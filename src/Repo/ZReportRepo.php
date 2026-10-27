<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use Festkasse\Support;
use PDO;

/**
 * Tagesabschlüsse per Kasse: each Kasse closes its own drawer and numbers its own Z-Berichte
 * (Kasse 1: Z-1, Z-2 … / Kasse 2: Z-1, Z-2 …). A report is addressed by its row id, since the
 * number alone is no longer unique.
 */
final class ZReportRepo
{
    public function __construct(private readonly PDO $db, private readonly CashRepo $cash)
    {
    }

    public function list(int $registerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT z.*, r.name AS register_name FROM z_reports z LEFT JOIN registers r ON r.id = z.register_id
             WHERE z.register_id = ? ORDER BY z.no DESC'
        );
        $stmt->execute([$registerId]);
        return array_map(static fn ($r) => self::toClient($r), $stmt->fetchAll());
    }

    /** One specific closed Z-Bericht, for "per E-Mail senden" on that report. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT z.*, r.name AS register_name FROM z_reports z LEFT JOIN registers r ON r.id = z.register_id WHERE z.id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ? self::toClient($r) : null;
    }

    private static function toClient(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'no' => (int) $r['no'],
            'registerId' => (int) $r['register_id'],
            'registerName' => $r['register_name'],
            'closedAt' => Support::toIso($r['closed_at']),
            'fromSaleAt' => $r['from_sale_at'] !== null ? Support::toIso($r['from_sale_at']) : null,
            'salesCount' => (int) $r['sales_count'],
            'cashCents' => (int) $r['cash_cents'],
            'cardCents' => (int) $r['card_cents'],
            'invoiceCents' => (int) ($r['invoice_cents'] ?? 0),
            'totalCents' => (int) $r['total_cents'],
            'drawerCents' => (int) $r['drawer_cents'],
        ];
    }

    /** Period and Kasse of one Z-Bericht, as the WHERE fragment + params its listings filter on. */
    private function periodFilter(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT register_id, from_sale_at, closed_at FROM z_reports WHERE id = ?');
        $stmt->execute([$id]);
        $z = $stmt->fetch();
        if (!$z) {
            return null;
        }
        $sql = ' AND register_id = ? AND sold_at <= ?';
        $params = [(int) $z['register_id'], $z['closed_at']];
        if ($z['from_sale_at'] !== null) {
            $sql .= ' AND sold_at > ?';
            $params[] = $z['from_sale_at'];
        }
        return [$sql, $params];
    }

    /** Individual sales that make up one closed Z-Bericht, oldest first — for its PDF listing. */
    public function salesFor(int $id): array
    {
        $filter = $this->periodFilter($id);
        if ($filter === null) {
            return [];
        }
        [$where, $params] = $filter;
        $stmt = $this->db->prepare(
            "SELECT receipt_no, sold_at, " . ReportsRepo::NET_CENTS . " AS total_cents, payment, invoice_code FROM sales
             WHERE voided_at IS NULL AND payment <> 'comp'" . $where . ' ORDER BY sold_at ASC'
        );
        $stmt->execute($params);
        return array_map(static fn ($r) => [
            'receiptNo' => $r['receipt_no'],
            'soldAt' => Support::toIso($r['sold_at']),
            'totalCents' => (int) $r['total_cents'],
            'payment' => $r['payment'],
            'invoiceCode' => $r['invoice_code'],
        ], $stmt->fetchAll());
    }

    /**
     * "Ohne Berechnung" bookings inside one closed Z-Bericht's period, summed per Bereich — shown
     * on its PDF for information only; they are in none of the report's Bar/Karte/Umsatz totals.
     */
    public function compFor(int $id): array
    {
        $filter = $this->periodFilter($id);
        if ($filter === null) {
            return [];
        }
        [$where, $params] = $filter;
        $stmt = $this->db->prepare(
            "SELECT comp_name, COUNT(*) AS cnt, COALESCE(SUM(total_cents), 0) AS value_cents FROM sales
             WHERE voided_at IS NULL AND payment = 'comp'" . $where . ' GROUP BY comp_name ORDER BY value_cents DESC'
        );
        $stmt->execute($params);
        return array_map(static fn ($r) => [
            'name' => (string) ($r['comp_name'] ?? '') !== '' ? $r['comp_name'] : 'Ohne Bereich',
            'count' => (int) $r['cnt'],
            'valueCents' => (int) $r['value_cents'],
        ], $stmt->fetchAll());
    }

    public function close(int $registerId): array
    {
        $lastCloseAt = $this->cash->lastCloseOccurredAt($registerId);
        $sql = "SELECT COUNT(*) AS cnt,
                       COALESCE(SUM(CASE WHEN payment = 'cash' THEN total_cents ELSE 0 END), 0) AS cash_cents,
                       COALESCE(SUM(CASE WHEN payment = 'card' THEN total_cents ELSE 0 END), 0) AS card_cents,
                       COALESCE(SUM(CASE WHEN payment = 'invoice' THEN total_cents ELSE 0 END), 0) AS invoice_cents,
                       COALESCE(SUM(CASE WHEN payment <> 'invoice' THEN deposit_returned_cents ELSE 0 END), 0) AS returned_cents,
                       COALESCE(SUM(CASE WHEN payment = 'invoice' THEN deposit_returned_cents ELSE 0 END), 0) AS invoice_returned_cents
                FROM sales
                WHERE voided_at IS NULL AND payment <> 'comp' AND register_id = ?" . ($lastCloseAt !== null ? ' AND sold_at > ?' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($lastCloseAt !== null ? [$registerId, $lastCloseAt] : [$registerId]);
        $row = $stmt->fetch();

        if ((int) $row['cnt'] === 0) {
            throw new ApiException(400, 'Seit dem letzten Abschluss keine Verkäufe');
        }

        // Returned Pfand leaves the Umsatz again — always out of the cash side, since a Rückgabe is
        // paid out in cash even when the same checkout was paid by card. Clamped at 0: the
        // z_reports columns are UNSIGNED.
        $row['cash_cents'] = max(0, (int) $row['cash_cents'] - (int) $row['returned_cents']);
        // Rückgaben "auf Rechnung" are credited on the invoice instead — may make this negative.
        $row['invoice_cents'] = (int) $row['invoice_cents'] - (int) $row['invoice_returned_cents'];
        $total = max(0, (int) $row['cash_cents'] + (int) $row['card_cents'] + (int) $row['invoice_cents']);

        $this->db->beginTransaction();
        try {
            $balance = $this->cash->balanceCents($registerId);
            $noStmt = $this->db->prepare('SELECT COALESCE(MAX(no), 0) + 1 FROM z_reports WHERE register_id = ? FOR UPDATE');
            $noStmt->execute([$registerId]);
            $no = (int) $noStmt->fetchColumn();

            $stmt = $this->db->prepare(
                'INSERT INTO z_reports (no, register_id, closed_at, from_sale_at, sales_count, cash_cents, card_cents, invoice_cents, total_cents, drawer_cents)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$no, $registerId, $lastCloseAt, (int) $row['cnt'], (int) $row['cash_cents'], (int) $row['card_cents'], (int) $row['invoice_cents'], $total, $balance]);
            $id = (int) $this->db->lastInsertId();

            $note = 'Tagesabschluss Z-' . $no . ' · Bar entnommen ' . Support::eur($balance);
            $this->cash->insert($registerId, 'close', -$balance, $note);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'id' => $id,
            'no' => $no,
            'salesCount' => (int) $row['cnt'],
            'cashCents' => (int) $row['cash_cents'],
            'cardCents' => (int) $row['card_cents'],
            'invoiceCents' => (int) $row['invoice_cents'],
            'totalCents' => $total,
        ];
    }
}
