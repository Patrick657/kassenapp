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

    /** @return array{unlocked:bool, expiresAt:?string} */
    public function status(): array
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!$token) {
            return ['unlocked' => false, 'expiresAt' => null];
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT expires_at FROM access_sessions WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$hash]);
        $expiresAt = $stmt->fetchColumn();
        if ($expiresAt === false) {
            return ['unlocked' => false, 'expiresAt' => null];
        }
        return ['unlocked' => true, 'expiresAt' => Support::toIso((string) $expiresAt)];
    }

    /** @return array{expiresAt:string} */
    public function attempt(string $code): array
    {
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

    /** Throws 401 unless a valid, non-expired access session exists — skipped only if require_code is off. */
    public function requireAccess(): void
    {
        if (($this->setting('require_code') ?? '1') === '0') {
            return;
        }
        if (!$this->status()['unlocked']) {
            throw new ApiException(401, 'Verwaltung gesperrt · Code erforderlich');
        }
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
