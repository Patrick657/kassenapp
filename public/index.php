<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
$config = require __DIR__ . '/../config/config.php';

use Festkasse\Api;
use Festkasse\Db;
use Festkasse\Migrator;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

if (str_starts_with($path, '/api/')) {
    $db = Db::get();
    try {
        (new Migrator($db))->ensureUpToDate();
    } catch (\Throwable $e) {
        // A migration failing must never take the whole site down — log it and let the request
        // proceed; endpoints that don't touch the not-yet-migrated schema keep working normally.
        error_log('[Festkasse Migrator] ' . $e->getMessage());
    }
    $api = new Api($db, (bool) $config['https']);
    $api->handle($_SERVER['REQUEST_METHOD'], $path);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Festkasse</title>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#14171a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Festkasse">
<link rel="apple-touch-icon" href="/icons/icon-180.png">
<link rel="icon" href="/icons/icon-192.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css">
</head>
<body>
<div id="app">Lädt…</div>
<script src="/app.js"></script>
</body>
</html>
