<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

/**
 * The drawer of each Kasse: every query and every booking names its Kasse explicitly — two
 * stands at the same Fest never share a Kassenbestand, journal or Tagesabschluss.
 */
final class CashRepo
{
    /** POS user logged in on the requesting device, stamped onto every movement booked. */
    private ?array $actor = null;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param ?array{id:int,name:string} $user */
    public function setActor(?array $user): void
    {
        $this->actor = $user;
    }

    public function balanceCents(int $registerId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(amount_cents), 0) FROM cash_movements WHERE register_id = ?');
        $stmt->execute([$registerId]);
        return (int) $stmt->fetchColumn();
    }

    /** $creditCents: Pfand credited on an invoice instead of paid out (then $amountCents is 0). */
    public function insert(int $registerId, string $type, int $amountCents, string $note, ?int $saleId = null, ?int $productId = null, ?int $qty = null, ?int $depositTypeId = null, ?int $creditCents = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO cash_movements (occurred_at, type, register_id, amount_cents, credit_cents, note, sale_id, product_id, qty, deposit_type_id, user_id, user_name)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $registerId, $amountCents, $creditCents, $note, $saleId, $productId, $qty, $depositTypeId, $this->actor['id'] ?? null, $this->actor['name'] ?? null]);
        return (int) $this->db->lastInsertId();
    }

    public function lastCloseOccurredAt(int $registerId): ?string
    {
        $stmt = $this->db->prepare("SELECT occurred_at FROM cash_movements WHERE type = 'close' AND register_id = ? ORDER BY occurred_at DESC LIMIT 1");
        $stmt->execute([$registerId]);
        $ts = $stmt->fetchColumn();
        return $ts === false ? null : (string) $ts;
    }

    /** Cash-paid sales revenue of one Kasse since the given timestamp (or all-time if null); stornierte Bons excluded. */
    public function cashRevenueSince(int $registerId, ?string $sinceOccurredAt): int
    {
        $sql = "SELECT COALESCE(SUM(m.amount_cents), 0) FROM cash_movements m
                LEFT JOIN sales s ON s.id = m.sale_id
                WHERE m.type = 'sale' AND m.amount_cents > 0 AND s.voided_at IS NULL AND m.register_id = ?";
        $params = [$registerId];
        if ($sinceOccurredAt !== null) {
            $sql .= ' AND m.occurred_at > ?';
            $params[] = $sinceOccurredAt;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
