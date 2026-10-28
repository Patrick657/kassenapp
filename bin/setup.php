<?php
declare(strict_types=1);

// CLI setup: applies migrations, sets the access code + delete code hashes.
// Usage: php bin/setup.php --access-code=1234 --delete-code=9999 [--demo]

require_once __DIR__ . '/../src/bootstrap.php';
$config = require __DIR__ . '/../config/config.php';

use Festkasse\Db;
use Festkasse\Installer;

$opts = getopt('', ['access-code:', 'delete-code:', 'demo']);

$db = Db::get();

echo "Wende Schema und Migrationen an…\n";
foreach (Installer::initSchema($db, isset($opts['demo'])) as $line) {
    echo $line . ".\n";
}

$accessCode = $opts['access-code'] ?? null;
$deleteCode = $opts['delete-code'] ?? null;

if ($accessCode !== null || $deleteCode !== null) {
    if ($accessCode !== null && $deleteCode !== null && $accessCode === $deleteCode) {
        fwrite(STDERR, "Fehler: Zugangscode und Löschkennwort müssen unterschiedlich sein.\n");
        exit(1);
    }
    if ($accessCode !== null) {
        Installer::setCodeHash($db, 'access_code_hash', (string) $accessCode);
        echo "Zugangscode gesetzt.\n";
    }
    if ($deleteCode !== null) {
        Installer::setCodeHash($db, 'delete_code_hash', (string) $deleteCode);
        echo "Löschkennwort gesetzt.\n";
    }
} else {
    echo "Hinweis: kein --access-code/--delete-code übergeben — bitte vor dem Produktivbetrieb setzen, sonst bleiben Änderungen ungeschützt (leerer Hash gilt als 'kein Code').\n";
}

echo "Fertig.\n";
