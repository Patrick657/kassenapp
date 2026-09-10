<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use Festkasse\Support;
use PDO;

final class ZReportRepo
{
    public function __construct(private readonly PDO $db, private readonly CashRepo $cash)
    {
    }

    public function list(): array
    {
        $rows = $this->db->query('SELECT * FROM z_reports ORDER BY no DESC')->fetchAll();
        return array_map(static fn ($r) => [
            'no' => (int) $r['no'],
            'closedAt' => Support::toIso($r['closed_at']),
            'salesCount' => (int) $r['sales_count'],
            'cashCents' => (int) $r['cash_cents'],
            'cardCents' => (int) $r['card_cents'],
            'totalCents' => (int) $r['total_cents'],
        ], $rows);
    }

    public function close(): array
    {
        $lastCloseAt = $this->cash->lastCloseOccurredAt();
        $sql = "SELECT COUNT(*) AS cnt,
                       COALESCE(SUM(CASE WHEN payment = 'cash' THEN total_cents ELSE 0 END), 0) AS cash_cents,
                       COALESCE(SUM(CASE WHEN payment = 'card' THEN total_cents ELSE 0 END), 0) AS card_cents
                FROM sales
                WHERE voided_at IS NULL" . ($lastCloseAt !== null ? ' AND sold_at > ?' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($lastCloseAt !== null ? [$lastCloseAt] : []);
        $row = $stmt->fetch();

        if ((int) $row['cnt'] === 0) {
            throw new ApiException(400, 'Seit dem letzten Abschluss keine Verkäufe');
        }

        $balance = $this->cash->balanceCents();
        $no = (int) ($this->db->query('SELECT COALESCE(MAX(no), 0) FROM z_reports')->fetchColumn()) + 1;
        $total = (int) $row['cash_cents'] + (int) $row['card_cents'];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO z_reports (no, closed_at, from_sale_at, sales_count, cash_cents, card_cents, total_cents, drawer_cents)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$no, $lastCloseAt, (int) $row['cnt'], (int) $row['cash_cents'], (int) $row['card_cents'], $total, $balance]);

            $note = 'Tagesabschluss Z-' . $no . ' · Bar entnommen ' . Support::eur($balance);
            $this->cash->insert('close', -$balance, $note);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'no' => $no,
            'salesCount' => (int) $row['cnt'],
            'cashCents' => (int) $row['cash_cents'],
            'cardCents' => (int) $row['card_cents'],
            'totalCents' => $total,
        ];
    }
}
