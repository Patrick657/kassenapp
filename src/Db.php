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
            self::$instance = self::connect($config['db']);
        }
        return self::$instance;
    }

    /**
     * Opens a connection with the app's standard options. Also used by the web installer to test
     * credentials before they are saved. Leave 'name' empty to connect without selecting a database.
     * @param array{host:string, port:int, name:string, user:string, pass:string} $db
     */
    public static function connect(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']);
        if ($db['name'] !== '') {
            $dsn .= ';dbname=' . $db['name'];
        }
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        // Named MySQL timezones ("Europe/Berlin") need tzinfo tables that aren't always loaded,
        // so compute the current DST-aware offset in PHP instead of hardcoding +01:00 (which is
        // wrong for roughly half the year and would desync sold_at from PHP-side timestamps).
        $offset = (new \DateTime('now', new \DateTimeZone('Europe/Berlin')))->format('P');
        $pdo->exec("SET time_zone = '{$offset}'");
        return $pdo;
    }
}
