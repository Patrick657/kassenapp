<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

final class SettingsRepo
{
    private const BOOL_KEYS = ['track_stock', 'warn_low', 'card_enabled', 'require_code'];
    private const STRING_KEYS = ['shop_name'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,string> */
    public function raw(): array
    {
        $rows = $this->db->query('SELECT `key`, value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    /** @return array<string,mixed> public-facing settings (no hashes) */
    public function forClient(): array
    {
        $raw = $this->raw();
        return [
            'shopName' => $raw['shop_name'] ?? 'Festkasse',
            'trackStock' => ($raw['track_stock'] ?? '1') === '1',
            'warnLow' => ($raw['warn_low'] ?? '1') === '1',
            'cardEnabled' => ($raw['card_enabled'] ?? '1') === '1',
            'requireCode' => ($raw['require_code'] ?? '1') === '1',
        ];
    }

    public function bool(string $key, bool $default = false): bool
    {
        $raw = $this->raw();
        if (!isset($raw[$key])) {
            return $default;
        }
        return $raw[$key] === '1';
    }

    /** @param array<string,mixed> $patch */
    public function update(array $patch): void
    {
        $map = [
            'shopName' => 'shop_name',
            'trackStock' => 'track_stock',
            'warnLow' => 'warn_low',
            'cardEnabled' => 'card_enabled',
            'requireCode' => 'require_code',
        ];
        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        foreach ($map as $clientKey => $dbKey) {
            if (!array_key_exists($clientKey, $patch)) {
                continue;
            }
            $value = $patch[$clientKey];
            if (in_array($dbKey, self::BOOL_KEYS, true)) {
                $value = $value ? '1' : '0';
            } else {
                $value = (string) $value;
            }
            $stmt->execute([$dbKey, $value]);
        }
    }

    public function setCodeHash(string $key, string $hash): void
    {
        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        $stmt->execute([$key, $hash]);
    }

    public function accessCodeHash(): ?string
    {
        $raw = $this->raw();
        return $raw['access_code_hash'] ?? null;
    }
}
