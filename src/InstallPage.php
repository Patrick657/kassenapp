<?php
declare(strict_types=1);

namespace Festkasse;

/**
 * Web installer at /install: asks for DB credentials and the first settings, sets up the database
 * and writes config/config.local.php. public/index.php only routes here while
 * Installer::isConfigured() is false, so this page disappears for good once a config exists.
 */
final class InstallPage
{
    private const FIELDS = [
        'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'kassen_app', 'db_user' => '', 'db_pass' => '',
        'shop_name' => 'Festkasse', 'recovery_email' => '',
        'app_url' => '', 'app_https' => '',
        'smtp_host' => '', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'smtp_user' => '', 'smtp_pass' => '',
        'smtp_from_email' => '', 'smtp_from_name' => 'Festkasse',
        'demo' => '',
    ];

    /** @var array<string, string> */
    private array $v;
    /** @var string[] */
    private array $errors = [];

    public function handle(): void
    {
        session_name('festkasse_install');
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
        if (empty($_SESSION['install_csrf'])) {
            $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        $this->v = self::FIELDS;
        $this->v['app_url'] = self::detectedUrl();
        $this->v['app_https'] = self::detectedHttps() ? '1' : '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->submit();
            return;
        }
        $this->renderForm();
    }

    private function submit(): void
    {
        if (!hash_equals((string) $_SESSION['install_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            $this->errors[] = 'Die Sitzung ist abgelaufen. Bitte das Formular erneut absenden.';
            $this->renderForm();
            return;
        }
        foreach (array_keys(self::FIELDS) as $key) {
            $this->v[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        // Passwords may legitimately start or end with spaces.
        $this->v['db_pass'] = (string) ($_POST['db_pass'] ?? '');
        $this->v['smtp_pass'] = (string) ($_POST['smtp_pass'] ?? '');
        $access = trim((string) ($_POST['access_code'] ?? ''));
        $access2 = trim((string) ($_POST['access_code2'] ?? ''));
        $delete = trim((string) ($_POST['delete_code'] ?? ''));
        $delete2 = trim((string) ($_POST['delete_code2'] ?? ''));

        $v = $this->v;
        if ($v['db_host'] === '' || $v['db_name'] === '' || $v['db_user'] === '') {
            $this->errors[] = 'Datenbank: Server, Name und Benutzer sind Pflicht.';
        }
        if (!ctype_digit($v['db_port']) || (int) $v['db_port'] < 1 || (int) $v['db_port'] > 65535) {
            $this->errors[] = 'Datenbank: Der Port muss eine Zahl sein (meist 3306).';
        }
        // The PIN pad in the app takes exactly four digits — any other code could never be typed in.
        if (!preg_match('/^\d{4}$/', $access)) {
            $this->errors[] = 'Der Zugangscode muss aus genau 4 Ziffern bestehen (so wird er an der Kasse eingetippt).';
        } elseif ($access !== $access2) {
            $this->errors[] = 'Die beiden Eingaben des Zugangscodes stimmen nicht überein.';
        }
        if (mb_strlen($delete) < 4) {
            $this->errors[] = 'Das Löschkennwort muss mindestens 4 Zeichen lang sein.';
        } elseif ($delete !== $delete2) {
            $this->errors[] = 'Die beiden Eingaben des Löschkennworts stimmen nicht überein.';
        } elseif ($delete === $access) {
            $this->errors[] = 'Zugangscode und Löschkennwort müssen unterschiedlich sein.';
        }
        if ($v['recovery_email'] !== '' && !filter_var($v['recovery_email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'Die Wiederherstellungs-E-Mail ist keine gültige Adresse.';
        }
        if ($v['app_url'] !== '' && !preg_match('#^https?://[^/\s]+#i', $v['app_url'])) {
            $this->errors[] = 'Die Adresse der Kasse muss mit http:// oder https:// beginnen.';
        }
        if ($v['smtp_host'] !== '') {
            if (!ctype_digit($v['smtp_port'])) {
                $this->errors[] = 'Mailversand: Der Port muss eine Zahl sein.';
            }
            if (!in_array($v['smtp_encryption'], ['tls', 'ssl', 'none'], true)) {
                $this->errors[] = 'Mailversand: Unbekannte Verschlüsselung.';
            }
            if (!filter_var($v['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = 'Mailversand: Bitte eine gültige Absender-Adresse angeben.';
            }
        }
        if ($this->errors) {
            $this->renderForm();
            return;
        }

        $dbConfig = [
            'host' => $v['db_host'], 'port' => (int) $v['db_port'], 'name' => $v['db_name'],
            'user' => $v['db_user'], 'pass' => $v['db_pass'],
        ];
        try {
            $pdo = Installer::connect($dbConfig);
        } catch (\Throwable $e) {
            $this->errors[] = 'Keine Verbindung zur Datenbank: ' . self::dbErrorHint($e->getMessage());
            $this->renderForm();
            return;
        }

        try {
            $log = Installer::initSchema($pdo, $v['demo'] === '1');
            Installer::setCodeHash($pdo, 'access_code_hash', $access);
            Installer::setCodeHash($pdo, 'delete_code_hash', $delete);
            Installer::setSetting($pdo, 'shop_name', $v['shop_name'] !== '' ? mb_substr($v['shop_name'], 0, 80) : 'Festkasse');
            Installer::setSetting($pdo, 'recovery_email', $v['recovery_email']);
            $log[] = 'Zugangscode und Löschkennwort gesetzt';
        } catch (\Throwable $e) {
            error_log('[Festkasse Installer] ' . $e->getMessage());
            $this->errors[] = 'Die Verbindung klappt, aber die Tabellen konnten nicht angelegt werden: '
                . $e->getMessage() . ' — hat der Benutzer volle Rechte auf diese Datenbank?';
            $this->renderForm();
            return;
        }

        $config = Installer::renderLocalConfig([
            'DB_HOST' => $v['db_host'], 'DB_PORT' => $v['db_port'], 'DB_NAME' => $v['db_name'],
            'DB_USER' => $v['db_user'], 'DB_PASS' => $v['db_pass'],
            'APP_HTTPS' => $v['app_https'] === '1' ? '1' : '0',
            'APP_URL' => rtrim($v['app_url'], '/'),
            'APP_TIMEZONE' => 'Europe/Berlin',
            'SMTP_HOST' => $v['smtp_host'],
            'SMTP_PORT' => $v['smtp_host'] !== '' ? $v['smtp_port'] : '',
            'SMTP_ENCRYPTION' => $v['smtp_host'] !== '' ? $v['smtp_encryption'] : '',
            'SMTP_USER' => $v['smtp_host'] !== '' ? $v['smtp_user'] : '',
            'SMTP_PASS' => $v['smtp_host'] !== '' ? $v['smtp_pass'] : '',
            'SMTP_FROM_EMAIL' => $v['smtp_host'] !== '' ? $v['smtp_from_email'] : '',
            'SMTP_FROM_NAME' => $v['smtp_host'] !== '' ? $v['smtp_from_name'] : '',
        ]);

        $written = Installer::writeLocalConfig($config);
        unset($_SESSION['install_csrf']);
        $this->renderDone($log, $written ? null : $config);
    }

    private static function dbErrorHint(string $message): string
    {
        $hint = match (true) {
            str_contains($message, '[1045]') => 'Benutzername oder Passwort stimmt nicht.',
            str_contains($message, '[1044]'), str_contains($message, '[1049]')
                => 'Die Datenbank gibt es nicht, und der Benutzer darf sie nicht anlegen. Bitte zuerst im Hosting-Panel anlegen.',
            str_contains($message, '[2002]'), str_contains($message, '[2005]')
                => 'Der Datenbank-Server ist nicht erreichbar. Server und Port prüfen (oft „localhost“, Port 3306).',
            default => '',
        };
        $detail = preg_replace('/^SQLSTATE\[[^\]]*\]\s*/', '', $message);
        return trim($hint . ' (' . $detail . ')');
    }

    private static function detectedHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function detectedUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $host)) {
            return '';
        }
        return (self::detectedHttps() ? 'https://' : 'http://') . $host;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function input(string $name, string $label, string $type = 'text', string $hint = '', string $extra = ''): string
    {
        $value = str_starts_with($name, 'access_') || str_starts_with($name, 'delete_') ? '' : ($this->v[$name] ?? '');
        $hintHtml = $hint !== '' ? '<small>' . $hint . '</small>' : '';
        return '<label class="field"><span>' . self::e($label) . '</span>'
            . '<input type="' . $type . '" name="' . $name . '" value="' . self::e($value) . '" ' . $extra . '>'
            . $hintHtml . '</label>';
    }

    private function renderForm(): void
    {
        $v = $this->v;
        $errors = '';
        if ($this->errors) {
            $errors = '<div class="alert err"><strong>Bitte korrigieren:</strong><ul>';
            foreach ($this->errors as $err) {
                $errors .= '<li>' . self::e($err) . '</li>';
            }
            $errors .= '</ul></div>';
        }
        $enc = static fn (string $val, string $label): string =>
            '<option value="' . $val . '"' . ($v['smtp_encryption'] === $val ? ' selected' : '') . '>' . $label . '</option>';
        $smtpOpen = $v['smtp_host'] !== '' ? ' open' : '';
        $writable = is_writable(dirname(Installer::LOCAL_CONFIG));

        $body = $errors
            . ($writable ? '' : '<div class="alert warn">Der Ordner <code>config/</code> ist nicht beschreibbar. Das ist kein Problem: '
                . 'Am Ende bekommst du den Inhalt der Datei <code>config.local.php</code> angezeigt und lädst sie selbst hoch.</div>')
            . '<form method="post" action="/install" autocomplete="off">'
            . '<input type="hidden" name="csrf" value="' . self::e((string) $_SESSION['install_csrf']) . '">'

            . '<section><h2>1 · Datenbank</h2>'
            . '<p class="muted">Die Datenbank und den Benutzer legst du vorher im Hosting-Panel an (bei netcup: „Datenbanken“). Lokal legt der Installer die Datenbank selbst an, wenn der Benutzer das darf.</p>'
            . '<div class="row">' . $this->input('db_host', 'Server', 'text', '', 'required') . $this->input('db_port', 'Port', 'text', '', 'inputmode="numeric" class="short" required') . '</div>'
            . $this->input('db_name', 'Name der Datenbank', 'text', '', 'required')
            . '<div class="row">' . $this->input('db_user', 'Benutzer', 'text', '', 'required') . $this->input('db_pass', 'Passwort', 'password') . '</div>'
            . '</section>'

            . '<section><h2>2 · Kasse &amp; Zugang</h2>'
            . $this->input('shop_name', 'Name der Kasse', 'text', 'Steht oben in der App und auf Berichten. Später unter Einstellungen änderbar.', 'maxlength="80"')
            . '<div class="row">'
            . $this->input('access_code', 'Zugangscode', 'password', 'Genau 4 Ziffern, entsperrt die Verwaltung.', 'inputmode="numeric" pattern="\d{4}" maxlength="4" required')
            . $this->input('access_code2', 'Zugangscode wiederholen', 'password', '', 'inputmode="numeric" pattern="\d{4}" maxlength="4" required')
            . '</div><div class="row">'
            . $this->input('delete_code', 'Löschkennwort', 'password', 'Wird bei jedem Löschen/Stornieren abgefragt. Anders als der Zugangscode.', 'minlength="4" required')
            . $this->input('delete_code2', 'Löschkennwort wiederholen', 'password', '', 'minlength="4" required')
            . '</div>'
            . $this->input('recovery_email', 'Wiederherstellungs-E-Mail (empfohlen)', 'email', 'Hierhin geht der Link bei „Code vergessen?“.')
            . '<label class="check"><input type="checkbox" name="demo" value="1"' . ($v['demo'] === '1' ? ' checked' : '') . '> 15 Demo-Artikel einspielen (zum Ausprobieren)</label>'
            . '</section>'

            . '<section><h2>3 · Adresse</h2>'
            . $this->input('app_url', 'Adresse der Kasse', 'url', 'Für den Link in der „Code vergessen?“-Mail.')
            . '<label class="check"><input type="checkbox" name="app_https" value="1"' . ($v['app_https'] === '1' ? ' checked' : '') . '> Die Seite läuft über HTTPS</label>'
            . '</section>'

            . '<details' . $smtpOpen . '><summary><h2>4 · Mailversand (optional)</h2><span class="muted">Für „Per E-Mail senden“ bei Journal, Auswertungen und Z-Bon. Kann leer bleiben.</span></summary>'
            . '<div class="row">' . $this->input('smtp_host', 'SMTP-Server', 'text', '', 'placeholder="z. B. smtp.example.de"') . $this->input('smtp_port', 'Port', 'text', '', 'inputmode="numeric" class="short"') . '</div>'
            . '<label class="field"><span>Verschlüsselung</span><select name="smtp_encryption">'
            . $enc('tls', 'STARTTLS (meist Port 587)') . $enc('ssl', 'SSL/TLS (meist Port 465)') . $enc('none', 'keine (nur lokaler Mailserver)')
            . '</select></label>'
            . '<div class="row">' . $this->input('smtp_user', 'SMTP-Benutzer') . $this->input('smtp_pass', 'SMTP-Passwort', 'password') . '</div>'
            . '<div class="row">' . $this->input('smtp_from_email', 'Absender-Adresse', 'email') . $this->input('smtp_from_name', 'Absender-Name') . '</div>'
            . '</details>'

            . '<button type="submit" class="primary">Kasse einrichten</button>'
            . '</form>';

        $this->page('Festkasse einrichten', 'Einmalige Einrichtung. Danach ist diese Seite gesperrt.', $body);
    }

    /** @param string[] $log */
    private function renderDone(array $log, ?string $manualConfig): void
    {
        $steps = '<ul class="done">';
        foreach ($log as $line) {
            $steps .= '<li>' . self::e($line) . '</li>';
        }
        if ($manualConfig === null) {
            $steps .= '<li>Einstellungen in <code>config/config.local.php</code> gespeichert</li></ul>';
            $body = $steps
                . '<div class="alert ok">Die Kasse ist eingerichtet. Diese Installationsseite ist ab jetzt gesperrt.</div>'
                . '<p>Zum Entsperren der Verwaltung brauchst du den eben gewählten Zugangscode.</p>'
                . '<a class="primary" href="/">Zur Kasse</a>';
        } else {
            $steps .= '</ul>';
            $body = $steps
                . '<div class="alert warn"><strong>Noch ein Schritt:</strong> Der Server durfte die Einstellungsdatei nicht selbst speichern. '
                . 'Lege eine Datei <code>config.local.php</code> mit dem folgenden Inhalt im Ordner <code>config/</code> an '
                . '(z. B. per FTP oder Dateimanager). Danach ist die Kasse fertig.</div>'
                . '<textarea readonly rows="16" onclick="this.select()">' . self::e($manualConfig) . '</textarea>'
                . '<p class="muted">Die Datei enthält Passwörter. Diese Seite nicht offen lassen und den Inhalt nirgends sonst speichern.</p>'
                . '<a class="primary" href="/">Fertig, zur Kasse</a>';
        }
        $this->page('Fertig', '', $body);
    }

    private function page(string $title, string $sub, string $body): void
    {
        $subHtml = $sub !== '' ? '<p class="sub">' . self::e($sub) . '</p>' : '';
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . self::e($title) . ' · Festkasse</title>'
            . '<link rel="icon" href="/icons/icon-192.png" type="image/png">'
            . '<style>' . self::CSS . '</style></head><body><main>'
            . '<h1>' . self::e($title) . '</h1>' . $subHtml . $body
            . '</main></body></html>';
    }

    private const CSS = <<<'CSS'
:root { --ink:#14171a; --bg:#eceeea; --surface:#fff; --accent:#0f7a5f; --text-2:rgba(0,0,0,.55);
  --border:rgba(0,0,0,.08); --field-border:rgba(0,0,0,.14); --err-text:#b91c1c; --err-fill:#fee2e2;
  --warn-fill:#fff7ed; --warn-border:#fdba74; --warn-text:#9a3412; --ok-fill:#e8f5f0; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.45 Figtree, system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width:640px; margin:0 auto; padding:32px 16px 64px; }
h1 { font-size:26px; margin:0 0 4px; }
.sub, .muted { color:var(--text-2); }
.muted { font-size:13px; margin:0 0 12px; }
section, details { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:18px; margin:16px 0; }
h2 { font-size:16px; margin:0 0 10px; display:inline-block; }
summary { cursor:pointer; list-style:none; } summary::-webkit-details-marker { display:none; }
summary .muted { display:block; margin:0; } details[open] summary { margin-bottom:12px; }
summary h2::after { content:" ▸"; color:var(--text-2); } details[open] summary h2::after { content:" ▾"; }
.row { display:flex; gap:12px; } .row > .field { flex:1; min-width:0; } .row > .field:has(.short) { flex:0 0 96px; }
@media (max-width:520px) { .row { flex-direction:column; gap:0; } .row > .field:has(.short) { flex:1; } }
.field { display:block; margin:0 0 12px; } .field span { display:block; font-weight:600; font-size:13px; margin-bottom:4px; }
.field small { display:block; color:var(--text-2); font-size:12px; margin-top:4px; }
input[type=text], input[type=password], input[type=email], input[type=url], select, textarea {
  width:100%; font:inherit; padding:10px 12px; border:1px solid var(--field-border); border-radius:10px; background:#fff; color:inherit; }
input:focus, select:focus, textarea:focus { outline:2px solid var(--accent); outline-offset:-1px; border-color:transparent; }
textarea { font-family:"IBM Plex Mono", ui-monospace, monospace; font-size:12.5px; }
.check { display:flex; gap:8px; align-items:center; margin:6px 0; }
.primary { display:inline-block; background:var(--ink); color:#fff; border:0; border-radius:12px; padding:13px 22px;
  font:inherit; font-weight:700; cursor:pointer; text-decoration:none; margin-top:8px; }
.primary:hover { background:#000; }
.alert { border-radius:12px; padding:12px 14px; margin:16px 0; } .alert ul { margin:6px 0 0; padding-left:18px; }
.alert.err { background:var(--err-fill); color:var(--err-text); }
.alert.warn { background:var(--warn-fill); border:1px solid var(--warn-border); color:var(--warn-text); }
.alert.ok { background:var(--ok-fill); color:var(--accent); font-weight:600; }
ul.done { padding-left:20px; } ul.done li::marker { content:"✓  "; color:var(--accent); }
code { font-family:"IBM Plex Mono", ui-monospace, monospace; font-size:.92em; background:rgba(0,0,0,.05); padding:1px 5px; border-radius:5px; }
CSS;
}
