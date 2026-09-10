<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use PDO;

final class CategoryRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function list(): array
    {
        $rows = $this->db->query('SELECT * FROM categories ORDER BY sort_order ASC, name ASC')->fetchAll();
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'sortOrder' => (int) $r['sort_order'],
            'trackStockDefault' => (bool) $r['track_stock_default'],
        ], $rows);
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function nameExists(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    public function create(string $name, bool $trackStockDefault): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        if ($this->nameExists($name)) {
            throw new ApiException(400, 'Gruppe existiert bereits');
        }
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories')->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO categories (name, sort_order, track_stock_default) VALUES (?, ?, ?)');
        $stmt->execute([$name, $next, $trackStockDefault ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    /** Renaming cascades to every product currently in the group (products.category is denormalized text). */
    public function rename(int $id, string $newName): void
    {
        $newName = trim($newName);
        if ($newName === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if (!$current) {
            throw new ApiException(404, 'Gruppe nicht gefunden');
        }
        if ($newName === $current['name']) {
            return;
        }
        if ($this->nameExists($newName)) {
            throw new ApiException(400, 'Gruppe existiert bereits');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$newName, $id]);
            $this->db->prepare('UPDATE products SET category = ? WHERE category = ?')->execute([$newName, $current['name']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function setTrackStockDefault(int $id, bool $value): void
    {
        $stmt = $this->db->prepare('UPDATE categories SET track_stock_default = ? WHERE id = ?');
        $stmt->execute([$value ? 1 : 0, $id]);
    }

    /** Refuses to delete a group that still has products — caller must reassign or delete those first. */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('SELECT name FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new ApiException(404, 'Gruppe nicht gefunden');
        }
        $stmt2 = $this->db->prepare('SELECT COUNT(*) FROM products WHERE category = ? AND archived_at IS NULL');
        $stmt2->execute([$name]);
        $productCount = (int) $stmt2->fetchColumn();
        if ($productCount > 0) {
            throw new ApiException(400, 'Gruppe wird noch von ' . $productCount . ' Artikel(n) verwendet — erst verschieben oder löschen');
        }
        $this->db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }
}
