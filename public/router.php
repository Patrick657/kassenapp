<?php
// Only used by `php -S` during local development — a real Apache/Nginx server
// serves static files itself and rewrites everything else to index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
