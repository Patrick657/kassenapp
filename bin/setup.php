<?php
declare(strict_types=1);

// CLI setup: applies migrations, sets the access code + delete code hashes.
// Usage: php bin/setup.php --access-code=1234 --delete-code=9999 [--demo]

require_once __DIR__ . '/../src/bootstrap.php';
$config = require __DIR__ . '/../config/config.php';

use Festkasse\Db;

$opts = getopt('', ['access-code:', 'delete-code:', 'demo']);

$db = Db::get();

echo "Wende Migrationen an…\n";
$migrationFiles = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($migrationFiles);
foreach ($migrationFiles as $file) {
    if (str_contains(basename($file), 'seed')) {
        continue; // seed files are opt-in via --demo below, not part of the schema
    }
    echo '  - ' . basename($file) . "\n";
    $db->exec(file_get_contents($file));
}

if (isset($opts['demo'])) {
    $existing = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($existing === 0) {
        echo "Spiele Demo-Artikel ein…\n";
        $db->exec(file_get_contents(__DIR__ . '/../migrations/002_seed_demo.sql'));
    } else {
        echo "Artikel bereits vorhanden — Demo-Daten übersprungen.\n";
    }
}

$accessCode = $opts['access-code'] ?? null;
$deleteCode = $opts['delete-code'] ?? null;

if ($accessCode !== null || $deleteCode !== null) {
    if ($accessCode !== null && $deleteCode !== null && $accessCode === $deleteCode) {
        fwrite(STDERR, "Fehler: Zugangscode und Löschkennwort müssen unterschiedlich sein.\n");
        exit(1);
    }
    $stmt = $db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
    if ($accessCode !== null) {
        $stmt->execute(['access_code_hash', password_hash((string) $accessCode, PASSWORD_DEFAULT)]);
        echo "Zugangscode gesetzt.\n";
    }
    if ($deleteCode !== null) {
        $stmt->execute(['delete_code_hash', password_hash((string) $deleteCode, PASSWORD_DEFAULT)]);
        echo "Löschkennwort gesetzt.\n";
    }
} else {
    echo "Hinweis: kein --access-code/--delete-code übergeben — bitte vor dem Produktivbetrieb setzen, sonst bleiben Änderungen ungeschützt (leerer Hash gilt als 'kein Code').\n";
}

echo "Fertig.\n";
