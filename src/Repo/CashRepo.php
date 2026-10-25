<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

final class CashRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function balanceCents(): int
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(amount_cents), 0) FROM cash_movements')->fetchColumn();
    }

    public function insert(string $type, int $amountCents, string $note, ?int $saleId = null, ?int $productId = null, ?int $qty = null, ?int $depositTypeId = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO cash_movements (occurred_at, type, amount_cents, note, sale_id, product_id, qty, deposit_type_id)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $amountCents, $note, $saleId, $productId, $qty, $depositTypeId]);
        return (int) $this->db->lastInsertId();
    }

    public function lastCloseOccurredAt(): ?string
    {
        $ts = $this->db->query("SELECT occurred_at FROM cash_movements WHERE type = 'close' ORDER BY occurred_at DESC LIMIT 1")->fetchColumn();
        return $ts === false ? null : (string) $ts;
    }

    /** Cash-paid sales revenue since the given timestamp (or all-time if null); stornierte Bons excluded. */
    public function cashRevenueSince(?string $sinceOccurredAt): int
    {
        $base = "SELECT COALESCE(SUM(m.amount_cents), 0) FROM cash_movements m
                 LEFT JOIN sales s ON s.id = m.sale_id
                 WHERE m.type = 'sale' AND m.amount_cents > 0 AND s.voided_at IS NULL";
        if ($sinceOccurredAt === null) {
            return (int) $this->db->query($base)->fetchColumn();
        }
        $stmt = $this->db->prepare($base . ' AND m.occurred_at > ?');
        $stmt->execute([$sinceOccurredAt]);
        return (int) $stmt->fetchColumn();
    }
}
