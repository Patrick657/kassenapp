<?php
declare(strict_types=1);

namespace Festkasse;

use PDO;

/**
 * Applies pending schema migrations automatically on the next request. Exists because some
 * deployments have no SSH/CLI access at all (shared hosting with only a web file manager) — for
 * those, "upload the new files" has to be the entire deployment step, migrations included.
 * 001_schema.sql is the implicit baseline (version 1); every schema change after that gets its
 * own private method here, added to the dispatch in ensureUpToDate().
 *
 * Each step must stay idempotent using portable, widely-supported checks (information_schema,
 * CREATE TABLE IF NOT EXISTS, INSERT IGNORE) rather than newer syntax sugar like MySQL 8.0.29's
 * `ADD COLUMN IF NOT EXISTS` — shared hosts often run older MySQL where that's a syntax error,
 * and an uncaught error here must never be able to take the whole site down (see the try/catch
 * around the call site in public/index.php).
 */
final class Migrator
{
    private const LATEST_VERSION = 10;

    public function __construct(private readonly PDO $db)
    {
    }

    public function ensureUpToDate(): void
    {
        $current = $this->currentVersion();
        if ($current < 2) {
            $this->migrateToV2();
            $this->setVersion(2);
        }
        if ($current < 3) {
            $this->migrateToV3();
            $this->setVersion(3);
        }
        if ($current < 4) {
            $this->migrateToV4();
            $this->setVersion(4);
        }
        if ($current < 5) {
            $this->migrateToV5();
            $this->setVersion(5);
        }
        if ($current < 6) {
            $this->migrateToV6();
            $this->setVersion(6);
        }
        if ($current < 7) {
            $this->migrateToV7();
            $this->setVersion(7);
        }
        if ($current < 8) {
            $this->migrateToV8();
            $this->setVersion(8);
        }
        if ($current < 9) {
            $this->migrateToV9();
            $this->setVersion(9);
        }
        if ($current < 10) {
            $this->migrateToV10();
            $this->setVersion(10);
        }
    }

    /** Artikelgruppen as a managed table + per-article stock tracking. */
    private function migrateToV2(): void
    {
        $sql = file_get_contents(__DIR__ . '/../migrations/003_categories_and_track_stock.sql');
        if ($sql !== false) {
            $this->db->exec($sql);
        }
        $this->ensureColumn(
            'products',
            'track_stock',
            'ALTER TABLE products ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_min'
        );
    }

    /** Optional article number (SKU), free text, no uniqueness constraint. */
    private function migrateToV3(): void
    {
        $this->ensureColumn(
            'products',
            'sku',
            'ALTER TABLE products ADD COLUMN sku VARCHAR(60) NULL AFTER name'
        );
    }

    /** Recovery email + reset tokens, for resetting a forgotten access code without SSH/phpMyAdmin. */
    private function migrateToV4(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS access_resets (
                token_hash  CHAR(64)  PRIMARY KEY,
                created_at  DATETIME  NOT NULL,
                expires_at  DATETIME  NOT NULL,
                used_at     DATETIME  NULL,
                ip          VARBINARY(16) NULL,
                INDEX (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $stmt = $this->db->prepare('INSERT IGNORE INTO settings (`key`, value) VALUES (?, ?)');
        $stmt->execute(['recovery_email', '']);
    }

    /** Multi-user POS logins + per-user article-group access (Merchandising vs. Speisen/Getränke stations). */
    private function migrateToV5(): void
    {
        $sql = file_get_contents(__DIR__ . '/../migrations/004_users.sql');
        if ($sql !== false) {
            $this->db->exec($sql);
        }
    }

    /**
     * Pfand (deposit) on articles like reusable Krüge: a per-unit deposit charged on top of the
     * price at sale time (never discounted, frozen into sale_items/sales like cost_cents already
     * is) and paid back out in cash via a new 'deposit_return' cash-movement type when the mug
     * comes back — see Api::createDepositReturn().
     */
    private function migrateToV6(): void
    {
        $this->ensureColumn(
            'products',
            'deposit_cents',
            'ALTER TABLE products ADD COLUMN deposit_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_cents'
        );
        $this->ensureColumn(
            'sale_items',
            'deposit_cents',
            'ALTER TABLE sale_items ADD COLUMN deposit_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_cents'
        );
        $this->ensureColumn(
            'sales',
            'deposit_cents',
            'ALTER TABLE sales ADD COLUMN deposit_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_cents'
        );
        // Re-declaring the same enum is a harmless no-op if this ever ran already; the version
        // gate in ensureUpToDate() means it normally only executes once anyway.
        $this->db->exec(
            "ALTER TABLE cash_movements MODIFY COLUMN type ENUM('sale','in','out','close','delivery','deposit_return') NOT NULL"
        );
    }

    /**
     * Per-Artikelgruppe Pfand-Standardbetrag (mirrors track_stock_default): prefills a new
     * article's Pfand field when a group like "Getränke" always uses the same deposit amount.
     * Superseded by the deposit_types entity in migrateToV8() — the column itself is dropped
     * there once its values are migrated, so this step only still matters for upgrades that
     * pass through v7 on the way to v8.
     */
    private function migrateToV7(): void
    {
        $this->ensureColumn(
            'categories',
            'deposit_default_cents',
            'ALTER TABLE categories ADD COLUMN deposit_default_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER track_stock_default'
        );
    }

    /**
     * Pfand-Optionen ("Krug 3,00 €", "Pfandflasche 0,15 €", ...) become their own managed entity
     * (Verwaltung → Pfand) instead of a raw amount typed separately onto every article/group —
     * one place to rename or reprice a deposit, which then applies everywhere it's referenced.
     * Replaces products.deposit_cents / categories.deposit_default_cents with a deposit_type_id
     * FK on each; existing raw amounts are folded into one deposit_type per distinct value so
     * nothing configured so far is lost. sale_items.deposit_cents / sales.deposit_cents are left
     * alone — those stay frozen historical cents, same as cost_cents always has been.
     */
    private function migrateToV8(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS deposit_types (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name         VARCHAR(60)  NOT NULL,
                amount_cents INT UNSIGNED NOT NULL,
                sort_order   INT          NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->ensureColumn(
            'products',
            'deposit_type_id',
            'ALTER TABLE products ADD COLUMN deposit_type_id INT UNSIGNED NULL AFTER price_cents'
        );
        $this->ensureColumn(
            'categories',
            'deposit_type_id',
            'ALTER TABLE categories ADD COLUMN deposit_type_id INT UNSIGNED NULL AFTER track_stock_default'
        );

        if ($this->columnExists('products', 'deposit_cents')) {
            $this->foldAmountsIntoDepositTypes('products', 'deposit_cents');
            $this->db->exec('ALTER TABLE products DROP COLUMN deposit_cents');
        }
        if ($this->columnExists('categories', 'deposit_default_cents')) {
            $this->foldAmountsIntoDepositTypes('categories', 'deposit_default_cents');
            $this->db->exec('ALTER TABLE categories DROP COLUMN deposit_default_cents');
        }

        $this->ensureForeignKey(
            'products',
            'fk_products_deposit_type',
            'ALTER TABLE products ADD CONSTRAINT fk_products_deposit_type FOREIGN KEY (deposit_type_id) REFERENCES deposit_types(id) ON DELETE SET NULL'
        );
        $this->ensureForeignKey(
            'categories',
            'fk_categories_deposit_type',
            'ALTER TABLE categories ADD CONSTRAINT fk_categories_deposit_type FOREIGN KEY (deposit_type_id) REFERENCES deposit_types(id) ON DELETE SET NULL'
        );
    }

    /**
     * A Pfand-Rückgabe can now happen inside the same checkout as a sale (Warenkorb: new items +
     * returned Krüge, booked together) — deposit_returned_cents records how much of that checkout
     * was paid back out, so total_cents - deposit_returned_cents gives the real net amount handed
     * over or paid out, and receipts/journal can show the return distinctly from the goods total.
     */
    private function migrateToV9(): void
    {
        $this->ensureColumn(
            'sales',
            'deposit_returned_cents',
            'ALTER TABLE sales ADD COLUMN deposit_returned_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER deposit_cents'
        );
    }

    /**
     * Pfand-Rückgabe is no longer tied to a specific article's stock (a returned Krug isn't "the
     * Bier coming back into stock", and the operator doesn't need that granularity at the
     * register) — a return picks a Pfand-Option directly. Its cash_movements row now records
     * which option via deposit_type_id instead of product_id, so the Journal/receipt can still
     * show the option's name without joining through an unrelated article.
     */
    private function migrateToV10(): void
    {
        $this->ensureColumn(
            'cash_movements',
            'deposit_type_id',
            'ALTER TABLE cash_movements ADD COLUMN deposit_type_id INT UNSIGNED NULL AFTER product_id'
        );
        $this->ensureForeignKey(
            'cash_movements',
            'fk_cash_movements_deposit_type',
            'ALTER TABLE cash_movements ADD CONSTRAINT fk_cash_movements_deposit_type FOREIGN KEY (deposit_type_id) REFERENCES deposit_types(id) ON DELETE SET NULL'
        );
    }

    /**
     * For every distinct positive value in $table.$amountColumn, finds or creates a matching
     * deposit_types row (reusing one across both tables when the amount matches) and points
     * deposit_type_id at it. Run once per table, before that table's raw amount column is dropped.
     */
    private function foldAmountsIntoDepositTypes(string $table, string $amountColumn): void
    {
        $amounts = $this->db->query("SELECT DISTINCT `{$amountColumn}` FROM `{$table}` WHERE `{$amountColumn}` > 0")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($amounts as $amount) {
            $amount = (int) $amount;
            $find = $this->db->prepare('SELECT id FROM deposit_types WHERE amount_cents = ? LIMIT 1');
            $find->execute([$amount]);
            $typeId = (int) $find->fetchColumn();
            if ($typeId === 0) {
                $insert = $this->db->prepare('INSERT INTO deposit_types (name, amount_cents) VALUES (?, ?)');
                $insert->execute(['Pfand ' . number_format($amount / 100, 2, ',', '.') . ' €', $amount]);
                $typeId = (int) $this->db->lastInsertId();
            }
            $update = $this->db->prepare("UPDATE `{$table}` SET deposit_type_id = ? WHERE `{$amountColumn}` = ?");
            $update->execute([$typeId, $amount]);
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        if (!$this->columnExists($table, $column)) {
            $this->db->exec($alterSql);
        }
    }

    private function ensureForeignKey(string $table, string $constraintName, string $alterSql): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'"
        );
        $stmt->execute([$table, $constraintName]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec($alterSql);
        }
    }

    private function currentVersion(): int
    {
        try {
            $stmt = $this->db->prepare('SELECT value FROM settings WHERE `key` = ?');
            $stmt->execute(['schema_version']);
            $value = $stmt->fetchColumn();
            return $value === false ? 1 : (int) $value;
        } catch (\Throwable $e) {
            // settings table missing entirely (never even ran 001_schema.sql) — nothing this
            // class can safely fix; let the caller's normal error handling surface it.
            return 1;
        }
    }

    private function setVersion(int $version): void
    {
        if ($version > self::LATEST_VERSION) {
            return;
        }
        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        $stmt->execute(['schema_version', (string) $version]);
    }
}
