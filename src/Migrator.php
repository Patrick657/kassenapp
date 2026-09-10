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
    private const LATEST_VERSION = 3;

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

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
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
