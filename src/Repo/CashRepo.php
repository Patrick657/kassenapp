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

    public function insert(string $type, int $amountCents, string $note, ?int $saleId = null, ?int $productId = null, ?int $qty = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO cash_movements (occurred_at, type, amount_cents, note, sale_id, product_id, qty)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $amountCents, $note, $saleId, $productId, $qty]);
        return (int) $this->db->lastInsertId();
    }

    public function lastCloseOccurredAt(): ?string
    {
        $ts = $this->db->query("SELECT occurred_at FROM cash_movements WHERE type = 'close' ORDER BY occurred_at DESC LIMIT 1")->fetchColumn();
        return $ts === false ? null : (string) $ts;
    }

    /** Cash-paid sales revenue since the given timestamp (or all-time if null). */
    public function cashRevenueSince(?string $sinceOccurredAt): int
    {
        if ($sinceOccurredAt === null) {
            return (int) $this->db->query("SELECT COALESCE(SUM(amount_cents), 0) FROM cash_movements WHERE type = 'sale' AND amount_cents > 0")->fetchColumn();
        }
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM cash_movements WHERE type = 'sale' AND amount_cents > 0 AND occurred_at > ?");
        $stmt->execute([$sinceOccurredAt]);
        return (int) $stmt->fetchColumn();
    }
}
