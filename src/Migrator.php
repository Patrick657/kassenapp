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
    private const LATEST_VERSION = 16;

    private const CASH_MOVEMENT_TYPE_ENUM = "ENUM('sale','in','out','close','delivery','deposit_return','comp','void')";

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

        // v6 collided with a since-discarded, differently-shaped implementation that had already
        // run its OWN "version 6" on some installs before this code replaced it (own deposits
        // table, products.deposit_name/deposit_cents — see migrateToV6()'s docblock). Wherever
        // that happened, schema_version already reads >= 6, so the `$current < 6` gate below would
        // silently skip this version forever and this app's own sale_items.deposit_cents /
        // sales.deposit_cents / the deposit_return enum value would never get added — exactly what
        // broke checkout. Run it unconditionally instead; every statement inside is independently
        // idempotent (ensureColumn checks first, the ENUM MODIFY is a harmless no-op if re-applied),
        // so this is safe and cheap to repeat on every request regardless of what the counter says.
        $this->migrateToV6();
        if ($current < 6) {
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
        // v9/v10 each wrapped individually: on an install already stuck here (v9 used to throw
        // before sales.deposit_cents existed, which aborted this whole method and meant v10 never
        // even got attempted), one step failing must never cost the next one its chance to run.
        if ($current < 9) {
            try {
                $this->migrateToV9();
                $this->setVersion(9);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v9 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 10) {
            try {
                $this->migrateToV10();
                $this->setVersion(10);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v10 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 11) {
            try {
                $this->migrateToV11();
                $this->setVersion(11);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v11 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 12) {
            try {
                $this->migrateToV12();
                $this->setVersion(12);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v12 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 13) {
            try {
                $this->migrateToV13();
                $this->setVersion(13);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v13 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 14) {
            try {
                $this->migrateToV14();
                $this->setVersion(14);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v14 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 15) {
            try {
                $this->migrateToV15();
                $this->setVersion(15);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v15 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
        }
        if ($current < 16) {
            try {
                $this->migrateToV16();
                $this->setVersion(16);
            } catch (\Throwable $e) {
                error_log('[Festkasse Migrator] v16 fehlgeschlagen, wird beim nächsten Request erneut versucht: ' . $e->getMessage());
            }
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
        // This step runs on every request (see ensureUpToDate()), so the enum declared here must
        // always be the full, current list — including values added by later steps ('comp'/'void' from
        // migrateToV11()/V12()). Declaring the shorter original list would strip them again each time.
        $this->db->exec(
            "ALTER TABLE cash_movements MODIFY COLUMN type " . self::CASH_MOVEMENT_TYPE_ENUM . " NOT NULL"
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
     * "Ohne Berechnung": goods handed out without payment (e.g. the band's drinks are on the
     * house) still get recorded — stock, quantities and their value — but must never count as
     * revenue or touch the drawer. Such a checkout is a normal sales row with payment = 'comp',
     * booked onto one of a few freely managed Bereiche (comp_accounts: "Band", "Helfer", ...).
     * sales.comp_name freezes the Bereich's name at booking time, same idea as sale_items.name —
     * renaming or deleting a Bereich later must not rewrite what past bookings say.
     */
    private function migrateToV11(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS comp_accounts (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(60) NOT NULL,
                sort_order INT         NOT NULL DEFAULT 0,
                created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->ensureColumn(
            'sales',
            'comp_account_id',
            'ALTER TABLE sales ADD COLUMN comp_account_id INT UNSIGNED NULL AFTER payment'
        );
        $this->ensureColumn(
            'sales',
            'comp_name',
            'ALTER TABLE sales ADD COLUMN comp_name VARCHAR(60) NULL AFTER comp_account_id'
        );
        $this->db->exec("ALTER TABLE sales MODIFY COLUMN payment ENUM('cash','card','comp') NOT NULL");
        $this->db->exec(
            "ALTER TABLE cash_movements MODIFY COLUMN type " . self::CASH_MOVEMENT_TYPE_ENUM . " NOT NULL"
        );
    }

    /**
     * Storno: an admin can void a Bon of the still-open day (Verwaltung → Journal → Bon). The
     * sales row stays (voided_at was in the baseline schema already, every report filters on it)
     * and now also keeps the reason. The drawer is corrected by a counter-booking of the new
     * cash_movements type 'void' — nothing that was booked gets deleted or rewritten.
     */
    private function migrateToV12(): void
    {
        $this->ensureColumn(
            'sales',
            'void_reason',
            'ALTER TABLE sales ADD COLUMN void_reason VARCHAR(255) NULL AFTER voided_at'
        );
        $this->db->exec(
            "ALTER TABLE cash_movements MODIFY COLUMN type " . self::CASH_MOVEMENT_TYPE_ENUM . " NOT NULL"
        );
    }

    /**
     * Journal shows who booked each entry: the POS user logged in on that device at the time.
     * user_name freezes the name (same idea as sale_items.name), so renaming or deleting a user
     * later never rewrites the journal. No FK on user_id on purpose — users can be deleted.
     */
    private function migrateToV13(): void
    {
        $this->ensureColumn(
            'cash_movements',
            'user_id',
            'ALTER TABLE cash_movements ADD COLUMN user_id INT UNSIGNED NULL AFTER deposit_type_id'
        );
        $this->ensureColumn(
            'cash_movements',
            'user_name',
            'ALTER TABLE cash_movements ADD COLUMN user_name VARCHAR(60) NULL AFTER user_id'
        );
    }

    /**
     * Mehrere Kassen (e.g. Kasse 1 = Getränke, Kasse 2 = Merch at a different stand): each has its
     * own drawer, journal and Z-Berichte (numbered per Kasse), optionally its own article groups.
     * Everything booked so far belongs to "Kasse 1", created here, so an existing install keeps
     * working unchanged. z_reports.no becomes unique per Kasse instead of globally.
     */
    private function migrateToV14(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS registers (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(60) NOT NULL,
                sort_order INT         NOT NULL DEFAULT 0,
                created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS register_categories (
                register_id INT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (register_id, category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach (['sales' => 'payment', 'cash_movements' => 'type', 'z_reports' => 'no'] as $table => $after) {
            $this->ensureColumn($table, 'register_id', "ALTER TABLE {$table} ADD COLUMN register_id INT UNSIGNED NULL AFTER {$after}");
        }
        $this->ensureIndex('sales', 'idx_sales_register', 'ALTER TABLE sales ADD INDEX idx_sales_register (register_id, sold_at)');
        $this->ensureIndex('cash_movements', 'idx_cash_movements_register', 'ALTER TABLE cash_movements ADD INDEX idx_cash_movements_register (register_id)');

        if ((int) $this->db->query('SELECT COUNT(*) FROM registers')->fetchColumn() === 0) {
            $this->db->exec("INSERT INTO registers (name, sort_order) VALUES ('Kasse 1', 1)");
        }
        $firstId = (int) $this->db->query('SELECT id FROM registers ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
        foreach (['sales', 'cash_movements', 'z_reports'] as $table) {
            $this->db->prepare("UPDATE {$table} SET register_id = ? WHERE register_id IS NULL")->execute([$firstId]);
        }

        // Z-numbers per Kasse: replace the old global UNIQUE(no) with UNIQUE(register_id, no).
        $stmt = $this->db->query(
            "SELECT DISTINCT s.index_name FROM information_schema.statistics s
             WHERE s.table_schema = DATABASE() AND s.table_name = 'z_reports' AND s.non_unique = 0
               AND s.index_name <> 'PRIMARY' AND s.column_name = 'no'
               AND (SELECT COUNT(*) FROM information_schema.statistics s2
                    WHERE s2.table_schema = s.table_schema AND s2.table_name = s.table_name AND s2.index_name = s.index_name) = 1"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $indexName) {
            $this->db->exec('ALTER TABLE z_reports DROP INDEX `' . str_replace('`', '', (string) $indexName) . '`');
        }
        $this->ensureIndex('z_reports', 'uq_z_reports_register_no', 'ALTER TABLE z_reports ADD UNIQUE INDEX uq_z_reports_register_no (register_id, no)');
    }

    /**
     * "Auf Rechnung": goods sold to a Firma that pays later by invoice. Unlike "ohne Berechnung"
     * this IS revenue (Umsatz, journal), but no money enters the drawer — like a card payment.
     * Firmen (invoice_accounts) have a short Kürzel shown as a button in the Kasse. Code and name
     * are frozen on the sale (same idea as comp_name). billed_at/billed_ref mark which Bons have
     * already gone onto a written invoice, so nothing is billed twice. archived_at hides a Firma
     * from the Kasse without losing its history.
     */
    private function migrateToV15(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS invoice_accounts (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code        VARCHAR(12)  NOT NULL,
                name        VARCHAR(120) NOT NULL,
                address     TEXT         NULL,
                note        VARCHAR(255) NULL,
                sort_order  INT          NOT NULL DEFAULT 0,
                archived_at DATETIME     NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $columns = [
            'invoice_account_id' => 'INT UNSIGNED NULL AFTER comp_name',
            'invoice_code' => 'VARCHAR(12) NULL AFTER invoice_account_id',
            'invoice_name' => 'VARCHAR(120) NULL AFTER invoice_code',
            'invoice_ref' => 'VARCHAR(120) NULL AFTER invoice_name',
            'billed_at' => 'DATETIME NULL AFTER invoice_ref',
            'billed_ref' => 'VARCHAR(60) NULL AFTER billed_at',
        ];
        foreach ($columns as $name => $def) {
            $this->ensureColumn('sales', $name, "ALTER TABLE sales ADD COLUMN {$name} {$def}");
        }
        $this->ensureIndex('sales', 'idx_sales_invoice', 'ALTER TABLE sales ADD INDEX idx_sales_invoice (invoice_account_id, billed_at)');
        $this->db->exec("ALTER TABLE sales MODIFY COLUMN payment ENUM('cash','card','comp','invoice') NOT NULL");
        $this->ensureColumn('z_reports', 'invoice_cents', 'ALTER TABLE z_reports ADD COLUMN invoice_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER card_cents');
    }

    /**
     * Pfand-Rückgabe "auf Rechnung": a Firma handing back Krüge gets the Pfand credited on its
     * invoice instead of cash from the drawer. The deposit_return row then has amount_cents 0
     * (drawer untouched) and credit_cents = the credited amount, frozen like everything else.
     * z_reports.invoice_cents becomes signed: a day with only Rückgaben is a credit (negative).
     */
    private function migrateToV16(): void
    {
        $this->ensureColumn('cash_movements', 'credit_cents', 'ALTER TABLE cash_movements ADD COLUMN credit_cents INT UNSIGNED NULL AFTER amount_cents');
        $this->db->exec('ALTER TABLE z_reports MODIFY COLUMN invoice_cents INT NOT NULL DEFAULT 0');
    }

    private function ensureIndex(string $table, string $indexName, string $alterSql): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $indexName]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec($alterSql);
        }
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

    /**
     * Best-effort only — a foreign key is a data-integrity nicety the app's own PHP code never
     * relies on (nothing checks that the constraint exists), unlike the columns ensureColumn()
     * adds, which the app genuinely can't run without. Some shared-hosting DB users lack the
     * privilege to add foreign keys at all (REFERENCES, or a restricted ALTER grant); if that
     * ALTER throws, swallow it and move on rather than letting it abort the whole migration step
     * — the columns before it in the same migrateToVN() must still land and schema_version must
     * still advance, or every later migration stays permanently stuck retrying (and failing) this
     * same statement on every request, which is exactly what breaks checkout: sales/cash_movements
     * columns added in a *later* step never get the chance to exist.
     */
    private function ensureForeignKey(string $table, string $constraintName, string $alterSql): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'"
        );
        $stmt->execute([$table, $constraintName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        try {
            $this->db->exec($alterSql);
        } catch (\Throwable $e) {
            error_log('[Festkasse Migrator] Foreign Key ' . $constraintName . ' konnte nicht angelegt werden (übersprungen): ' . $e->getMessage());
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
