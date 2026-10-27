<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

final class SettingsRepo
{
    private const BOOL_KEYS = ['track_stock', 'warn_low', 'card_enabled', 'require_code', 'pos_pin_required'];
    private const STRING_KEYS = ['shop_name', 'report_email', 'pdf_color', 'pdf_font'];

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
            'posPinRequired' => ($raw['pos_pin_required'] ?? '1') === '1',
            'reportEmail' => $raw['report_email'] ?? '',
            'pdfColor' => $raw['pdf_color'] ?? \Festkasse\Pdf::DEFAULT_ACCENT,
            'pdfFont' => $raw['pdf_font'] ?? '',
            'pdfFonts' => \Festkasse\TtfFont::AVAILABLE,
            'pdfHasLogo' => ($raw['pdf_logo'] ?? '') !== '',
        ];
    }

    /** @return array{font:string, color:string, logo:?string} for new \Festkasse\Pdf() */
    public function pdfTheme(): array
    {
        $raw = $this->raw();
        return [
            'font' => $raw['pdf_font'] ?? '',
            'color' => $raw['pdf_color'] ?? \Festkasse\Pdf::DEFAULT_ACCENT,
            'logo' => $this->logo(),
        ];
    }

    /** The PDF logo as JPEG bytes, or null. */
    public function logo(): ?string
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'pdf_logo'");
        $stmt->execute();
        $b64 = (string) $stmt->fetchColumn();
        $bin = $b64 !== '' ? base64_decode($b64, true) : false;
        return $bin === false ? null : $bin;
    }

    public function setLogo(?string $jpeg): void
    {
        if ($jpeg === null) {
            $this->db->prepare("DELETE FROM settings WHERE `key` = 'pdf_logo'")->execute();
            return;
        }
        $this->db->prepare("REPLACE INTO settings (`key`, value) VALUES ('pdf_logo', ?)")->execute([base64_encode($jpeg)]);
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
            'posPinRequired' => 'pos_pin_required',
            'reportEmail' => 'report_email',
            'pdfColor' => 'pdf_color',
            'pdfFont' => 'pdf_font',
        ];
        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        foreach ($map as $clientKey => $dbKey) {
            if (!array_key_exists($clientKey, $patch)) {
                continue;
            }
            $value = $patch[$clientKey];
            if ($dbKey === 'pdf_color') {
                if (\Festkasse\Pdf::hexToRgb((string) $value) === null) {
                    throw new \Festkasse\ApiException(400, 'Farbe bitte als #RRGGBB angeben');
                }
                $value = '#' . strtoupper(ltrim(trim((string) $value), '#'));
            } elseif ($dbKey === 'pdf_font') {
                if (!array_key_exists((string) $value, \Festkasse\TtfFont::AVAILABLE)) {
                    throw new \Festkasse\ApiException(400, 'Unbekannte Schriftart');
                }
                $value = (string) $value;
            } elseif (in_array($dbKey, self::BOOL_KEYS, true)) {
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

    public function recoveryEmail(): ?string
    {
        $email = $this->raw()['recovery_email'] ?? '';
        return $email === '' ? null : $email;
    }

    public function setRecoveryEmail(?string $email): void
    {
        $stmt = $this->db->prepare('REPLACE INTO settings (`key`, value) VALUES (?, ?)');
        $stmt->execute(['recovery_email', $email ?? '']);
    }

    /** @return string[] one or more comma-separated report-email addresses, trimmed, never empty strings */
    public function reportEmails(): array
    {
        $raw = $this->raw()['report_email'] ?? '';
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $e): bool => $e !== ''));
    }
}
