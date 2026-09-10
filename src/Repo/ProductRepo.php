<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use PDO;

final class ProductRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Active (non-archived) products with lifetime sold quantity, for the POS and Artikel tab. */
    public function listActive(): array
    {
        $sql = "SELECT p.*, COALESCE(sq.qty, 0) AS sold_qty, COALESCE(sq.revenue_cents, 0) AS sold_revenue_cents
                FROM products p
                LEFT JOIN (
                    SELECT si.product_id, SUM(si.qty) AS qty, SUM(si.qty * si.unit_cents) AS revenue_cents
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL
                    GROUP BY si.product_id
                ) sq ON sq.product_id = p.id
                WHERE p.archived_at IS NULL
                ORDER BY p.sort_order ASC, p.id ASC";
        return $this->db->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? AND archived_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name, string $category, int $priceCents, int $costCents, int $stock, int $stockMin): int
    {
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn();
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, category, price_cents, cost_cents, stock, stock_min, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $category, $priceCents, $costCents, $stock, $stockMin, $next]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $category, int $priceCents, int $costCents, ?int $stock, ?int $stockMin): void
    {
        $product = $this->find($id);
        if ($product === null) {
            throw new ApiException(404, 'Artikel nicht gefunden');
        }
        $stmt = $this->db->prepare(
            'UPDATE products SET name = ?, category = ?, price_cents = ?, cost_cents = ?, stock = ?, stock_min = ? WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $category,
            $priceCents,
            $costCents,
            $stock ?? (int) $product['stock'],
            $stockMin ?? (int) $product['stock_min'],
            $id,
        ]);
    }

    public function archive(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE products SET archived_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function addStock(int $id, int $qty): void
    {
        $stmt = $this->db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        $stmt->execute([$qty, $id]);
    }

    /** Deducts stock for a completed sale; never goes below zero. Runs inside the caller's transaction. */
    public function deductStock(int $id, int $qty): void
    {
        $stmt = $this->db->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?');
        $stmt->execute([$qty, $id]);
    }

    /** Locks the row for update inside a transaction (parallel-register safety). */
    public function lockForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
