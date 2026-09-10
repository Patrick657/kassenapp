<?php
declare(strict_types=1);

namespace Festkasse;

final class Support
{
    /** Denominations in cents, largest first — notes and coins accepted at the register. */
    public const DENOMS_CENTS = [10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5];

    public static function eur(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign . number_format($abs / 100, 2, ',', '.') . ' €';
    }

    public static function short(int $cents): string
    {
        $abs = abs($cents);
        if ($abs >= 100000) {
            return ($cents < 0 ? '-' : '') . number_format($abs / 100000, 1, ',', '.') . 'k €';
        }
        return ($cents < 0 ? '-' : '') . (string) round($abs / 100) . ' €';
    }

    /** Parses German or English decimal input ("12,50" / "12.50") into cents. */
    public static function parseAmountToCents(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return 0;
        }
        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Greedy largest-denomination-first breakdown of a change amount, capped at 4 denominations.
     * @return string e.g. "2×2 € · 1×50 ct"
     */
    public static function breakdown(int $changeCents): string
    {
        $rest = $changeCents;
        $parts = [];
        foreach (self::DENOMS_CENTS as $d) {
            $count = intdiv($rest, $d);
            if ($count > 0) {
                $label = $d >= 100 ? ($d / 100) . ' €' : $d . ' ct';
                $parts[] = $count . '×' . $label;
                $rest -= $count * $d;
            }
        }
        return implode(' · ', array_slice($parts, 0, 4));
    }

    /** Converts a naive "Y-m-d H:i:s" MySQL datetime (stored in Europe/Berlin wall time) to unambiguous ISO-8601. */
    public static function toIso(string $mysqlDatetime): string
    {
        $dt = new \DateTime($mysqlDatetime, new \DateTimeZone('Europe/Berlin'));
        return $dt->format('c');
    }

    public static function receiptNo(int $sequentialId): string
    {
        return 'B-' . date('Y') . '-' . str_pad((string) $sequentialId, 6, '0', STR_PAD_LEFT);
    }

    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip === null) {
            return null;
        }
        return @inet_pton($ip) ?: null;
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException(400, 'Ungültiges JSON');
        }
        return $data;
    }

    public static function sendJson(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
