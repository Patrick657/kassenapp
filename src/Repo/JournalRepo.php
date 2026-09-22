<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\Support;
use PDO;

final class JournalRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn();
    }

    /** @return array{items: array<int, array>, nextBefore: ?int} */
    public function page(int $limit, ?int $beforeId): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT m.*, s.receipt_no FROM cash_movements m LEFT JOIN sales s ON s.id = m.sale_id';
        $params = [];
        if ($beforeId !== null) {
            $sql .= ' WHERE m.id < ?';
            $params[] = $beforeId;
        }
        $sql .= ' ORDER BY m.id DESC LIMIT ' . ($limit + 1);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map(static fn ($m) => [
            'id' => (int) $m['id'],
            'occurredAt' => Support::toIso($m['occurred_at']),
            'type' => $m['type'],
            'amountCents' => (int) $m['amount_cents'],
            'note' => $m['note'],
            'saleId' => $m['sale_id'] !== null ? (int) $m['sale_id'] : null,
            'receiptNo' => $m['receipt_no'],
        ], $rows);

        return [
            'items' => $items,
            'nextBefore' => $hasMore ? (int) end($rows)['id'] : null,
        ];
    }

    /**
     * Every journal entry in chronological (oldest-first) ledger order, for the "Journal per
     * E-Mail" PDF — page() is newest-first and capped at 200 for the on-screen infinite-scroll
     * list, neither of which fits a printed Kassenbuch. Capped defensively at $limit so a very
     * long-running install can't build an unbounded PDF in one request.
     */
    public function exportRows(int $limit = 5000): array
    {
        $sql = 'SELECT m.*, s.receipt_no FROM cash_movements m LEFT JOIN sales s ON s.id = m.sale_id
                ORDER BY m.id ASC LIMIT ' . max(1, $limit);
        $rows = $this->db->query($sql)->fetchAll();
        return array_map(static fn ($m) => [
            'occurredAt' => Support::toIso($m['occurred_at']),
            'type' => $m['type'],
            'amountCents' => (int) $m['amount_cents'],
            'note' => $m['note'],
            'receiptNo' => $m['receipt_no'],
        ], $rows);
    }
}
