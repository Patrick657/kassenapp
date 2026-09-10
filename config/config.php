<?php
declare(strict_types=1);

namespace Festkasse;

require_once __DIR__ . '/../src/Env.php';

Env::load(__DIR__ . '/../.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Europe/Berlin'));

return [
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'kassen_app'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
    ],
    'https' => Env::get('APP_HTTPS', '0') === '1',
];
