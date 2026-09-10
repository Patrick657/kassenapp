<?php
declare(strict_types=1);

// CLI setup: applies migrations, sets the access code + delete code hashes.
// Usage: php bin/setup.php --access-code=1234 --delete-code=9999 [--demo]

require_once __DIR__ . '/../src/bootstrap.php';
$config = require __DIR__ . '/../config/config.php';

use Festkasse\Db;
use Festkasse\Migrator;

$opts = getopt('', ['access-code:', 'delete-code:', 'demo']);

$db = Db::get();

echo "Wende Basis-Schema an…\n";
$db->exec(file_get_contents(__DIR__ . '/../migrations/001_schema.sql'));

// Demo articles must exist BEFORE later migrations run, not after: migration v2's categories
// backfill reads DISTINCT products.category — on a brand-new install with --demo, that has to
// see the seeded demo articles, or the categories table ends up empty despite matching products.
if (isset($opts['demo'])) {
    $existing = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($existing === 0) {
        echo "Spiele Demo-Artikel ein…\n";
        $db->exec(file_get_contents(__DIR__ . '/../migrations/002_seed_demo.sql'));
    } else {
        echo "Artikel bereits vorhanden — Demo-Daten übersprungen.\n";
    }
}

echo "Wende weitere Migrationen an…\n";
(new Migrator($db))->ensureUpToDate();

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
