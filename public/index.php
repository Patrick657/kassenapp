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
    (new Migrator($db))->ensureUpToDate();
    $api = new Api($db, (bool) $config['https']);
    $api->handle($_SERVER['REQUEST_METHOD'], $path);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Festkasse</title>
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
