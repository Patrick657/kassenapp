<?php
// Vorlage für die Server-Konfiguration.
//
// Auf dem Server diese Datei nach  config.local.php  kopieren (gleicher Ordner) und dort die
// echten Werte eintragen. config.local.php wird von keinem Update angefasst — config.php und
// diese Vorlage dagegen werden bei jedem Update ersetzt, dort also nichts eintragen.
//
// Nur die Zeilen behalten, die gebraucht werden; alles andere kann gelöscht werden.

return [
    // Datenbank
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'kassen_app',
    'DB_USER' => '',
    'DB_PASS' => '',

    // 1, sobald die Seite über HTTPS läuft (Zugangs-Cookie wird dann nur verschlüsselt gesendet)
    'APP_HTTPS' => '1',
    // Eigene Adresse der Kasse, für den Link in der "Code vergessen?"-Mail
    'APP_URL' => 'https://kasse.example.de',
    'APP_TIMEZONE' => 'Europe/Berlin',

    // Mailversand für "Per E-Mail senden" — SMTP_HOST leer lassen = kein Mailversand
    'SMTP_HOST' => '',
    'SMTP_PORT' => '587',
    'SMTP_ENCRYPTION' => 'tls', // tls (Port 587), ssl (Port 465) oder none
    'SMTP_USER' => '',
    'SMTP_PASS' => '',
    'SMTP_FROM_EMAIL' => '',
    'SMTP_FROM_NAME' => 'Festkasse',
];
