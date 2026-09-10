<?php
declare(strict_types=1);

namespace Festkasse;

use PDO;

final class Db
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'],
                $db['port'],
                $db['name']
            );
            self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Named MySQL timezones ("Europe/Berlin") need tzinfo tables that aren't always loaded,
            // so compute the current DST-aware offset in PHP instead of hardcoding +01:00 (which is
            // wrong for roughly half the year and would desync sold_at from PHP-side timestamps).
            $offset = (new \DateTime('now', new \DateTimeZone('Europe/Berlin')))->format('P');
            self::$instance->exec("SET time_zone = '{$offset}'");
        }
        return self::$instance;
    }
}
