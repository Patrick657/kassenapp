<?php
declare(strict_types=1);

namespace Festkasse;

use PDO;

final class Auth
{
    private const COOKIE = 'festkasse_token';
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $db, private readonly bool $https)
    {
    }

    private function setting(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function checkRateLimit(?string $ip): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM access_attempts WHERE ok = 0 AND tried_at > (NOW() - INTERVAL :m MINUTE)' .
            ($ip !== null ? ' AND ip = :ip' : ' AND ip IS NULL')
        );
        $params = ['m' => self::WINDOW_MINUTES];
        if ($ip !== null) {
            $params['ip'] = $ip;
        }
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS) {
            throw new ApiException(429, 'Zu viele Fehlversuche · bitte später erneut versuchen');
        }
    }

    private function recordAttempt(?string $ip, bool $ok): void
    {
        $stmt = $this->db->prepare('INSERT INTO access_attempts (tried_at, ip, ok) VALUES (NOW(), ?, ?)');
        $stmt->execute([$ip, $ok ? 1 : 0]);
    }

    /** No access code has ever been set (fresh install) — there is nothing to unlock yet. */
    public function hasAccessCode(): bool
    {
        $hash = $this->setting('access_code_hash');
        return $hash !== null && $hash !== '';
    }

    /** @return array{unlocked:bool, expiresAt:?string, codeConfigured:bool} */
    public function status(): array
    {
        $codeConfigured = $this->hasAccessCode();
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!$token) {
            return ['unlocked' => false, 'expiresAt' => null, 'codeConfigured' => $codeConfigured];
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT expires_at FROM access_sessions WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$hash]);
        $expiresAt = $stmt->fetchColumn();
        if ($expiresAt === false) {
            return ['unlocked' => false, 'expiresAt' => null, 'codeConfigured' => $codeConfigured];
        }
        return ['unlocked' => true, 'expiresAt' => Support::toIso((string) $expiresAt), 'codeConfigured' => $codeConfigured];
    }

    /**
     * Verwaltung is for the 'admin' case, full stop — a 'cashier' login must never reach it, and
     * neither may a device with no POS login at all, even knowing the access code, once at least
     * one admin-role account exists. Checked before the code is even accepted, not just before
     * protected actions, so a device can't end up "unlocked" yet 403ing on every subsequent call.
     *
     * Exception: while zero admin-role accounts exist yet, the access code alone still opens
     * Verwaltung (same bootstrap-open pattern as the code itself) — otherwise nobody could ever
     * create that first admin account once the multi-user feature is turned on.
     */
    private function assertVerwaltungAllowed(): void
    {
        $hasAdminUser = (int) $this->db->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1"
        )->fetchColumn() > 0;
        if (!$hasAdminUser) {
            return;
        }
        $posUser = $this->posCurrentUser();
        if ($posUser === null || $posUser['role'] !== 'admin') {
            throw new ApiException(403, 'Verwaltung ist gesperrt · bitte als Admin an der Kasse anmelden');
        }
    }

    /** @return array{expiresAt:string} */
    public function attempt(string $code): array
    {
        $this->assertVerwaltungAllowed();
        $ip = Support::clientIp();
        $this->checkRateLimit($ip);
        $hash = $this->setting('access_code_hash');
        $ok = $hash !== null && $hash !== '' && password_verify($code, $hash);
        $this->recordAttempt($ip, $ok);
        if (!$ok) {
            throw new ApiException(401, 'Falscher Code');
        }
        $ttl = (int) ($this->setting('access_ttl_sec') ?? '7200');
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'INSERT INTO access_sessions (token_hash, created_at, expires_at, ip) VALUES (?, NOW(), NOW() + INTERVAL ? SECOND, ?)'
        );
        $stmt->execute([$tokenHash, $ttl, $ip]);
        setcookie(self::COOKIE, $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'httponly' => true,
            'secure' => $this->https,
            'samesite' => 'Strict',
        ]);
        $stmt = $this->db->prepare('SELECT NOW() + INTERVAL ? SECOND');
        $stmt->execute([$ttl]);
        $expiresAt = (string) $stmt->fetchColumn();
        return ['expiresAt' => Support::toIso($expiresAt)];
    }

    public function revoke(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if ($token) {
            $stmt = $this->db->prepare('UPDATE access_sessions SET revoked_at = NOW() WHERE token_hash = ?');
            $stmt->execute([hash('sha256', $token)]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    }

    /**
     * Throws 401 unless a valid, non-expired access session exists. Skipped if require_code is off,
     * and also skipped on a fresh install with no access code configured yet — otherwise nobody could
     * ever reach Verwaltung to set the first code (there's no CLI/SSH on some shared hosts). Once a
     * code is set, this bootstrap bypass closes automatically. The Verwaltung/admin-login check
     * runs first and is never skipped by require_code=0 — it's a separate, stronger boundary.
     */
    public function requireAccess(): void
    {
        $this->assertVerwaltungAllowed();
        if (($this->setting('require_code') ?? '1') === '0') {
            return;
        }
        if (!$this->hasAccessCode()) {
            return;
        }
        if (!$this->status()['unlocked']) {
            throw new ApiException(401, 'Verwaltung gesperrt · Code erforderlich');
        }
    }

    private const RESET_TTL_MINUTES = 30;

    /**
     * Emails a one-time reset link to the configured recovery address, if any. Always subject to
     * the same rate limit as login/delete-code attempts — an unauthenticated endpoint that
     * triggers an email send and a token insert needs throttling regardless of whether an email
     * is actually configured, or it becomes a spam/DoS lever.
     */
    public function requestReset(): void
    {
        $ip = Support::clientIp();
        $this->checkRateLimit($ip);

        $email = $this->setting('recovery_email');
        if ($email === null || $email === '') {
            $this->recordAttempt($ip, false);
            throw new ApiException(400, 'Keine Wiederherstellungs-E-Mail hinterlegt · bitte vor Ort Zugriff verschaffen (siehe README)');
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'INSERT INTO access_resets (token_hash, created_at, expires_at, ip) VALUES (?, NOW(), NOW() + INTERVAL ? MINUTE, ?)'
        );
        $stmt->execute([$tokenHash, self::RESET_TTL_MINUTES, $ip]);
        $this->recordAttempt($ip, true);

        $this->sendResetEmail($email, $token);
    }

    private function sendResetEmail(string $email, string $token): void
    {
        $baseUrl = Env::get('APP_URL');
        if ($baseUrl === null || $baseUrl === '') {
            // Falls back to the request's own Host header if APP_URL isn't configured — fine on a
            // normal vhost setup (the webserver already rejects unrecognized Host values before
            // PHP ever sees them), but setting APP_URL in .env is the safer, recommended option.
            $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $baseUrl = ($this->https ? 'https://' : 'http://') . $host;
        }
        $link = rtrim($baseUrl, '/') . '/?reset=' . $token;
        $shopName = $this->setting('shop_name') ?: 'Festkasse';

        $subject = $shopName . ' - Zugangscode zuruecksetzen';
        $body = "Jemand hat fuer \"{$shopName}\" einen neuen Zugangscode angefordert.\n\n"
            . 'Link zum Zuruecksetzen (gueltig ' . self::RESET_TTL_MINUTES . " Minuten):\n{$link}\n\n"
            . "Falls das nicht du warst, einfach ignorieren - es aendert sich nichts.";
        $fromHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $headers = "From: {$shopName} <no-reply@{$fromHost}>";

        if (!@mail($email, $subject, $body, $headers)) {
            error_log('[Festkasse Auth] Reset-Mail an ' . $email . ' konnte nicht gesendet werden');
        }
    }

    /** @return array{expiresAt:string} logs the admin in immediately, same as first-time setup. */
    public function consumeReset(string $token, string $newAccessCode, ?string $newDeleteCode): array
    {
        if ($token === '') {
            throw new ApiException(400, 'Ungültiger Link');
        }
        if ($newDeleteCode !== null && $newAccessCode === $newDeleteCode) {
            throw new ApiException(400, 'Zugangscode und Löschkennwort müssen unterschiedlich sein');
        }
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT token_hash FROM access_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$tokenHash]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiException(400, 'Link ungültig oder abgelaufen · bitte neu anfordern');
        }

        $this->db->prepare('UPDATE access_resets SET used_at = NOW() WHERE token_hash = ?')->execute([$tokenHash]);

        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        $stmt->execute(['access_code_hash', password_hash($newAccessCode, PASSWORD_DEFAULT)]);
        if ($newDeleteCode !== null) {
            $stmt->execute(['delete_code_hash', password_hash($newDeleteCode, PASSWORD_DEFAULT)]);
        }

        return $this->attempt($newAccessCode);
    }

    private const POS_COOKIE = 'festkasse_pos_user';
    private const POS_SESSION_DAYS = 365;

    /** Active POS login accounts for the "Wer arbeitet an dieser Kasse?" picker — names only, no PIN hashes. */
    public function posUsers(): array
    {
        $rows = $this->db->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role']], $rows);
    }

    /** @return array{id:int,name:string,role:string}|null */
    public function posCurrentUser(): ?array
    {
        $token = $_COOKIE[self::POS_COOKIE] ?? null;
        if (!$token) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.role FROM pos_sessions s
             JOIN users u ON u.id = s.user_id AND u.active = 1
             WHERE s.token_hash = ? AND s.revoked_at IS NULL'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'name' => $row['name'], 'role' => $row['role']] : null;
    }

    /**
     * Logs a device in as a POS user for the whole shift ("bis manuell gewechselt" — no 2h
     * timeout like the admin access code). Shares the same IP rate-limit bucket as the access
     * and delete codes: it is another PIN-guessing surface and needs the same throttle.
     * @return array{user:array{id:int,name:string,role:string}}
     */
    public function posLogin(int $userId, string $pin): array
    {
        $ip = Support::clientIp();
        $this->checkRateLimit($ip);
        $stmt = $this->db->prepare('SELECT id, name, role, pin_hash FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $ok = $user && password_verify($pin, $user['pin_hash']);
        $this->recordAttempt($ip, (bool) $ok);
        if (!$ok) {
            throw new ApiException(401, 'Falscher PIN');
        }
        $this->issuePosSession((int) $user['id']);
        return ['user' => ['id' => (int) $user['id'], 'name' => $user['name'], 'role' => $user['role']]];
    }

    private function issuePosSession(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT INTO pos_sessions (token_hash, user_id, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([hash('sha256', $token), $userId]);
        setcookie(self::POS_COOKIE, $token, [
            'expires' => time() + 60 * 60 * 24 * self::POS_SESSION_DAYS,
            'path' => '/',
            'httponly' => true,
            'secure' => $this->https,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Auto-logs this device in as a newly-created 'admin' POS account, skipping the PIN check —
     * used only right after Verwaltung (already legitimately open, via the bootstrap access-code
     * door) creates the very first admin account. Without this, that same Verwaltung session would
     * immediately 403 on its own next request, since creating that account closes the bootstrap
     * door behind it (see assertVerwaltungAllowed()).
     */
    public function posAutoLoginAsNewAdmin(int $userId): void
    {
        $this->issuePosSession($userId);
    }

    /** "Benutzer wechseln" — no PIN needed, anyone at the register may hand it to the next person. */
    public function posLogout(): void
    {
        $token = $_COOKIE[self::POS_COOKIE] ?? null;
        if ($token) {
            $this->db->prepare('UPDATE pos_sessions SET revoked_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
        setcookie(self::POS_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    }

    /**
     * Article group names the current device may sell, or null when unrestricted — either no
     * users have been configured yet (feature unused, POS stays fully open, same bootstrap-open
     * pattern as the access code) or the logged-in user has the 'admin' role. An empty array
     * means "cashier logged in but assigned no groups yet" — deliberately locks the register to
     * nothing rather than defaulting to open.
     */
    public function posAllowedCategories(): ?array
    {
        if ((int) $this->db->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn() === 0) {
            return null;
        }
        $user = $this->posCurrentUser();
        if ($user === null) {
            return [];
        }
        if ($user['role'] === 'admin') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT c.name FROM user_categories uc JOIN categories c ON c.id = uc.category_id WHERE uc.user_id = ?'
        );
        $stmt->execute([$user['id']]);
        return array_map(static fn ($r) => $r['name'], $stmt->fetchAll());
    }

    /** Second factor for destructive actions — never cached, checked on every call. */
    public function verifyDeleteCode(?string $code): void
    {
        if ($code === null || $code === '') {
            throw new ApiException(400, 'Löschkennwort fehlt');
        }
        $ip = Support::clientIp();
        $this->checkRateLimit($ip);
        $hash = $this->setting('delete_code_hash');
        $ok = $hash !== null && $hash !== '' && password_verify($code, $hash);
        $this->recordAttempt($ip, $ok);
        if (!$ok) {
            // 403, not 401: this is a wrong second-factor credential, not an expired/missing access session —
            // the frontend must not treat it as "session expired, lock and redirect to POS".
            throw new ApiException(403, 'Falsches Löschkennwort');
        }
    }
}
