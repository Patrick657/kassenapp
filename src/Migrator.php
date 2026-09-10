<?php
declare(strict_types=1);

namespace Festkasse;

use PDO;

/**
 * Applies pending schema migrations automatically on the next request. Exists because some
 * deployments have no SSH/CLI access at all (shared hosting with only a web file manager) — for
 * those, "upload the new files" has to be the entire deployment step, migrations included.
 * 001_schema.sql is the implicit baseline (version 1); every schema change after that gets an
 * entry here. Each migration file must stay safe to re-run (CREATE TABLE IF NOT EXISTS, INSERT
 * IGNORE, ADD COLUMN IF NOT EXISTS, ...) since two requests could race on a fresh deploy.
 */
final class Migrator
{
    private const MIGRATIONS = [
        2 => '003_categories_and_track_stock.sql',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function ensureUpToDate(): void
    {
        $pending = self::MIGRATIONS;
        if (empty($pending)) {
            return;
        }
        ksort($pending);
        $latest = array_key_last($pending);
        $current = $this->currentVersion();
        if ($current >= $latest) {
            return;
        }
        foreach ($pending as $version => $file) {
            if ($version <= $current) {
                continue;
            }
            $path = __DIR__ . '/../migrations/' . $file;
            $sql = file_get_contents($path);
            if ($sql !== false) {
                $this->db->exec($sql);
            }
            $this->setVersion($version);
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
        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        $stmt->execute(['schema_version', (string) $version]);
    }
}
