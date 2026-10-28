<?php
declare(strict_types=1);

namespace Festkasse;

use PDO;

/**
 * First-time setup, shared by the CLI (bin/setup.php) and the web installer (/install).
 */
final class Installer
{
    public const ROOT = __DIR__ . '/..';
    public const LOCAL_CONFIG = self::ROOT . '/config/config.local.php';

    /**
     * The app counts as set up as soon as server settings exist in any of the supported places.
     * The web installer only runs while this is false, so it can never overwrite a live setup.
     */
    public static function isConfigured(): bool
    {
        return is_file(self::LOCAL_CONFIG)
            || is_file(self::ROOT . '/.env')
            || (getenv('DB_HOST') ?: '') !== '';
    }

    /**
     * Connects with the given credentials. If the database itself is missing, tries to create it
     * (works when the DB user has CREATE rights, e.g. locally — on shared hosting it usually has
     * to be created in the hosting panel first).
     * @param array{host:string, port:int, name:string, user:string, pass:string} $db
     */
    public static function connect(array $db): PDO
    {
        try {
            return Db::connect($db);
        } catch (\PDOException $e) {
            // 1049 = unknown database
            if (!str_contains($e->getMessage(), '[1049]')) {
                throw $e;
            }
        }
        $server = Db::connect(['name' => ''] + $db);
        $name = str_replace('`', '``', $db['name']);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        return Db::connect($db);
    }

    /**
     * Applies the baseline schema, optionally the demo articles, then every later migration.
     * Safe to run repeatedly. Returns short progress lines for the caller to show.
     * @return string[]
     */
    public static function initSchema(PDO $db, bool $demo): array
    {
        $log = ['Basis-Schema angewendet'];
        $db->exec((string) file_get_contents(self::ROOT . '/migrations/001_schema.sql'));

        // Demo articles must exist BEFORE later migrations run, not after: migration v2's categories
        // backfill reads DISTINCT products.category — on a brand-new install with demo data, that has
        // to see the seeded demo articles, or the categories table ends up empty.
        if ($demo) {
            $existing = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
            if ($existing === 0) {
                $db->exec((string) file_get_contents(self::ROOT . '/migrations/002_seed_demo.sql'));
                $log[] = 'Demo-Artikel eingespielt';
            } else {
                $log[] = 'Artikel bereits vorhanden — Demo-Daten übersprungen';
            }
        }

        (new Migrator($db))->ensureUpToDate();
        $log[] = 'Weitere Migrationen angewendet';
        return $log;
    }

    public static function setSetting(PDO $db, string $key, string $value): void
    {
        $db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)')->execute([$key, $value]);
    }

    public static function setCodeHash(PDO $db, string $key, string $code): void
    {
        self::setSetting($db, $key, password_hash($code, PASSWORD_DEFAULT));
    }

    /**
     * Builds the content of config/config.local.php. Keys with empty values are left out, so
     * config.php's defaults apply to them.
     * @param array<string, string> $values
     */
    public static function renderLocalConfig(array $values): string
    {
        $lines = [
            '<?php',
            '// Server-Konfiguration der Festkasse — erstellt vom Web-Installer am ' . date('d.m.Y H:i') . '.',
            '// Diese Datei wird von keinem Update angefasst. Änderungen hier direkt eintragen.',
            '',
            'return [',
        ];
        foreach ($values as $key => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = '    ' . var_export($key, true) . ' => ' . var_export($value, true) . ',';
        }
        $lines[] = '];';
        return implode("\n", $lines) . "\n";
    }

    /** Writes config.local.php atomically. False when the config folder isn't writable. */
    public static function writeLocalConfig(string $content): bool
    {
        $dir = dirname(self::LOCAL_CONFIG);
        if (!is_writable($dir)) {
            return false;
        }
        $tmp = $dir . '/.config.local.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!@rename($tmp, self::LOCAL_CONFIG)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::LOCAL_CONFIG, true);
        }
        return true;
    }
}
