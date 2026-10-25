<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use PDO;

/**
 * Kassen (registers): physical points of sale, each with its own drawer, journal, Z-Berichte and
 * — optionally — its own article groups (Kasse 1 = Getränke, Kasse 2 = Merch). A device is bound
 * to one Kasse by the admin (Verwaltung → Kassen → "Dieses Gerät zuordnen", a long-lived cookie).
 * There is always at least one Kasse; migration v14 creates "Kasse 1" and moves existing data there.
 */
final class RegisterRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** With assigned article group ids and lifetime booking count. */
    public function list(): array
    {
        $rows = $this->db->query(
            'SELECT r.*, COALESCE(sq.cnt, 0) AS sale_count
             FROM registers r
             LEFT JOIN (SELECT register_id, COUNT(*) AS cnt FROM sales GROUP BY register_id) sq ON sq.register_id = r.id
             ORDER BY r.sort_order ASC, r.id ASC'
        )->fetchAll();
        $cats = [];
        foreach ($this->db->query('SELECT register_id, category_id FROM register_categories')->fetchAll() as $c) {
            $cats[(int) $c['register_id']][] = (int) $c['category_id'];
        }
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'categoryIds' => $cats[(int) $r['id']] ?? [],
            'saleCount' => (int) $r['sale_count'],
        ], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM registers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'name' => $row['name']] : null;
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM registers')->fetchColumn();
    }

    public function first(): ?array
    {
        $row = $this->db->query('SELECT id, name FROM registers ORDER BY sort_order ASC, id ASC LIMIT 1')->fetch();
        return $row ? ['id' => (int) $row['id'], 'name' => $row['name']] : null;
    }

    /**
     * The Kasse this device books into: the one stored on the device, or — while only one Kasse
     * exists — simply that one, so a single-register install never needs to assign anything.
     * null means "several Kassen exist and this device isn't assigned yet": the POS can't sell.
     */
    public function forDevice(?int $cookieRegisterId): ?array
    {
        if ($cookieRegisterId !== null) {
            $register = $this->find($cookieRegisterId);
            if ($register !== null) {
                return $register;
            }
        }
        return $this->count() === 1 ? $this->first() : null;
    }

    /** Article group names this Kasse sells, or null when it sells everything (none assigned). */
    public function categoryNames(int $registerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.name FROM register_categories rc JOIN categories c ON c.id = rc.category_id WHERE rc.register_id = ?'
        );
        $stmt->execute([$registerId]);
        $names = array_map(static fn ($r) => $r['name'], $stmt->fetchAll());
        return $names === [] ? null : $names;
    }

    /** The only Kasse that sells this article group, if exactly one does — used to file a Warenzugang. */
    public function soleRegisterForCategory(string $categoryName): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT rc.register_id FROM register_categories rc JOIN categories c ON c.id = rc.category_id WHERE c.name = ?'
        );
        $stmt->execute([$categoryName]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    public function create(string $name, array $categoryIds): int
    {
        $name = $this->validName($name, null);
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM registers')->fetchColumn();
        $this->db->prepare('INSERT INTO registers (name, sort_order) VALUES (?, ?)')->execute([$name, $next]);
        $id = (int) $this->db->lastInsertId();
        $this->setCategories($id, $categoryIds);
        return $id;
    }

    public function update(int $id, string $name, array $categoryIds): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Kasse nicht gefunden');
        }
        $name = $this->validName($name, $id);
        $this->db->prepare('UPDATE registers SET name = ? WHERE id = ?')->execute([$name, $id]);
        $this->setCategories($id, $categoryIds);
    }

    /** Only an unused Kasse can go — its bookings, journal and Z-Berichte must never be orphaned. */
    public function delete(int $id): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Kasse nicht gefunden');
        }
        if ($this->count() <= 1) {
            throw new ApiException(400, 'Mindestens eine Kasse muss bestehen bleiben');
        }
        $stmt = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM sales WHERE register_id = ?) + (SELECT COUNT(*) FROM cash_movements WHERE register_id = ?)'
        );
        $stmt->execute([$id, $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new ApiException(400, 'Kasse hat bereits Buchungen und kann nicht gelöscht werden');
        }
        $this->db->prepare('DELETE FROM register_categories WHERE register_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM registers WHERE id = ?')->execute([$id]);
    }

    private function setCategories(int $registerId, array $categoryIds): void
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $this->db->prepare('DELETE FROM register_categories WHERE register_id = ?')->execute([$registerId]);
        $stmt = $this->db->prepare(
            'INSERT INTO register_categories (register_id, category_id) SELECT ?, id FROM categories WHERE id = ?'
        );
        foreach ($categoryIds as $cid) {
            $stmt->execute([$registerId, $cid]);
        }
    }

    private function validName(string $name, ?int $exceptId): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        if (mb_strlen($name) > 60) {
            throw new ApiException(400, 'Name ist zu lang (max. 60 Zeichen)');
        }
        $stmt = $this->db->prepare('SELECT id FROM registers WHERE name = ?');
        $stmt->execute([$name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && (int) $existing !== $exceptId) {
            throw new ApiException(400, 'Kasse existiert bereits');
        }
        return $name;
    }
}
