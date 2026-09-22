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
        $summaries = $this->itemSummariesFor($rows);

        $items = array_map(static fn ($m) => [
            'id' => (int) $m['id'],
            'occurredAt' => Support::toIso($m['occurred_at']),
            'type' => $m['type'],
            'amountCents' => (int) $m['amount_cents'],
            'note' => $m['note'],
            'saleId' => $m['sale_id'] !== null ? (int) $m['sale_id'] : null,
            'receiptNo' => $m['receipt_no'],
            'itemsSummary' => $m['sale_id'] !== null ? ($summaries[(int) $m['sale_id']] ?? null) : null,
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
     * long-running install can't build an unbounded PDF in one request. Unlike page(), this
     * returns per-item 'lines' (not a single joined string) so the PDF can print one purchased
     * article — or one returned Pfand-Option — per line instead of cramming them together.
     */
    public function exportRows(int $limit = 5000): array
    {
        $sql = 'SELECT m.*, s.receipt_no, dt.name AS deposit_type_name
                FROM cash_movements m
                LEFT JOIN sales s ON s.id = m.sale_id
                LEFT JOIN deposit_types dt ON dt.id = m.deposit_type_id
                ORDER BY m.id ASC LIMIT ' . max(1, $limit);
        $rows = $this->db->query($sql)->fetchAll();

        $saleIds = array_values(array_unique(array_filter(
            array_map(static fn ($r) => $r['type'] === 'sale' && $r['sale_id'] !== null ? (int) $r['sale_id'] : null, $rows)
        )));
        $itemLines = $this->itemLinesForSales($saleIds);

        return array_map(static function ($m) use ($itemLines) {
            $lines = match ($m['type']) {
                'sale' => $itemLines[(int) $m['sale_id']] ?? [],
                'deposit_return' => [[
                    'label' => $m['qty'] . '× ' . ($m['deposit_type_name'] ?? 'Pfand'),
                    'amountCents' => (int) $m['amount_cents'],
                ]],
                default => [],
            };
            return [
                'occurredAt' => Support::toIso($m['occurred_at']),
                'type' => $m['type'],
                'amountCents' => (int) $m['amount_cents'],
                'note' => $m['note'],
                'receiptNo' => $m['receipt_no'],
                'lines' => $lines,
            ];
        }, $rows);
    }

    /**
     * One bulk query for every given sale_id (never one query per row).
     * @param int[] $saleIds
     * @return array<int, array<int, array{label: string, amountCents: int}>>
     */
    private function itemLinesForSales(array $saleIds): array
    {
        if (empty($saleIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT sale_id, name, unit_cents, qty FROM sale_items WHERE sale_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute($saleIds);
        $bySale = [];
        foreach ($stmt->fetchAll() as $item) {
            $bySale[(int) $item['sale_id']][] = [
                'label' => $item['qty'] . '× ' . $item['name'],
                'amountCents' => (int) $item['unit_cents'] * (int) $item['qty'],
            ];
        }
        return $bySale;
    }

    /**
     * A "Vorgang" (a sale and, if the customer also returned a Krug in the same checkout, its
     * linked deposit_return row too) shares one sale_id, so both get the same itemized purchase
     * list — the joined-string form page()'s on-screen infinite-scroll list displays inline.
     * @param array<int, array<string, mixed>> $rows raw cash_movements rows (with sale_id)
     * @return array<int, string> sale_id => "2× Bier 0,5 l (8,00 €), 1× Bratwurst ... (3,50 €)"
     */
    private function itemSummariesFor(array $rows): array
    {
        $saleIds = array_values(array_unique(array_filter(
            array_map(static fn ($r) => $r['sale_id'] !== null ? (int) $r['sale_id'] : null, $rows)
        )));
        $bySale = $this->itemLinesForSales($saleIds);
        return array_map(
            static fn (array $lines): string => implode(', ', array_map(
                static fn (array $l) => $l['label'] . ' (' . Support::eur($l['amountCents']) . ')',
                $lines
            )),
            $bySale
        );
    }
}
