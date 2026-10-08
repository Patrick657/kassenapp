<?php
declare(strict_types=1);

namespace Festkasse;

require_once __DIR__ . '/../src/Env.php';

Env::load(__DIR__ . '/../.env');

// Server-specific settings live in config.local.php (or .env) — never in this file. This file is
// part of the app and gets replaced by every deploy; config.local.php is neither in git nor
// touched by the deploy, so whatever is entered there survives every update.
// Template: config.local.example.php.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $local = require $localConfig;
    if (is_array($local)) {
        Env::override($local);
    }
}

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
    'smtp' => [
        'host' => Env::get('SMTP_HOST', ''),
        'port' => (int) Env::get('SMTP_PORT', '587'),
        'encryption' => Env::get('SMTP_ENCRYPTION', 'tls'),
        'user' => Env::get('SMTP_USER', ''),
        'pass' => Env::get('SMTP_PASS', ''),
        'fromEmail' => Env::get('SMTP_FROM_EMAIL', ''),
        'fromName' => Env::get('SMTP_FROM_NAME', 'Festkasse'),
    ],
];
