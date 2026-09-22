<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use PDO;

/** Pfand-Optionen (Verwaltung → Pfand) — e.g. "Krug 3,00 €" — referenced by categories/products. */
final class DepositTypeRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function list(): array
    {
        $sql = "SELECT dt.*, COALESCE(pc.cnt, 0) AS product_count
                FROM deposit_types dt
                LEFT JOIN (
                    SELECT deposit_type_id, COUNT(*) AS cnt FROM products
                    WHERE archived_at IS NULL AND deposit_type_id IS NOT NULL
                    GROUP BY deposit_type_id
                ) pc ON pc.deposit_type_id = dt.id
                ORDER BY dt.sort_order ASC, dt.name ASC";
        $rows = $this->db->query($sql)->fetchAll();
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'amountCents' => (int) $r['amount_cents'],
            'productCount' => (int) $r['product_count'],
        ], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM deposit_types WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Finds an existing option with this exact amount, or creates a generically-named one. Used by CSV import. */
    public function findOrCreateByAmount(int $amountCents): int
    {
        $stmt = $this->db->prepare('SELECT id FROM deposit_types WHERE amount_cents = ? LIMIT 1');
        $stmt->execute([$amountCents]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        return $this->create('Pfand ' . number_format($amountCents / 100, 2, ',', '.') . ' €', $amountCents);
    }

    public function create(string $name, int $amountCents): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        if ($amountCents <= 0) {
            throw new ApiException(400, 'Betrag muss größer als 0 sein');
        }
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM deposit_types')->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO deposit_types (name, amount_cents, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$name, $amountCents, $next]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, int $amountCents): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Pfand-Option nicht gefunden');
        }
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        if ($amountCents <= 0) {
            throw new ApiException(400, 'Betrag muss größer als 0 sein');
        }
        $stmt = $this->db->prepare('UPDATE deposit_types SET name = ?, amount_cents = ? WHERE id = ?');
        $stmt->execute([$name, $amountCents, $id]);
    }

    /** Refuses to delete an option still assigned to a category or article — caller must reassign those first. */
    public function delete(int $id): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Pfand-Option nicht gefunden');
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE deposit_type_id = ? AND archived_at IS NULL');
        $stmt->execute([$id]);
        $productCount = (int) $stmt->fetchColumn();
        $stmt2 = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE deposit_type_id = ?');
        $stmt2->execute([$id]);
        $categoryCount = (int) $stmt2->fetchColumn();
        if ($productCount > 0 || $categoryCount > 0) {
            throw new ApiException(
                400,
                'Pfand-Option wird noch von ' . $productCount . ' Artikel(n) und ' . $categoryCount . ' Gruppe(n) verwendet — erst umstellen'
            );
        }
        $this->db->prepare('DELETE FROM deposit_types WHERE id = ?')->execute([$id]);
    }
}
