<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use PDO;

/**
 * Manages POS login accounts (name + 4-digit PIN + role) and which article groups a
 * 'cashier'-role account may sell. Purely an admin-management concern — verifying a PIN and
 * issuing/holding the POS session cookie lives in Auth, same split as Auth/SettingsRepo.
 */
final class UserRepo
{
    private const ROLES = ['admin', 'cashier'];
    private const PIN_PATTERN = '/^\d{4}$/';

    public function __construct(private readonly PDO $db)
    {
    }

    public function listAll(): array
    {
        $rows = $this->db->query('SELECT * FROM users ORDER BY sort_order ASC, id ASC')->fetchAll();
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $categoriesByUser = $this->categoryIdsForUsers($ids);
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'role' => $r['role'],
            'active' => (bool) $r['active'],
            'categoryIds' => $categoriesByUser[(int) $r['id']] ?? [],
        ], $rows);
    }

    /** @return array<int,int[]> */
    private function categoryIdsForUsers(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT user_id, category_id FROM user_categories WHERE user_id IN ($placeholders)");
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['user_id']][] = (int) $r['category_id'];
        }
        return $out;
    }

    public function categoryIdsFor(int $userId): array
    {
        return $this->categoryIdsForUsers([$userId])[$userId] ?? [];
    }

    public function hasActiveAdmin(): bool
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function validateRole(string $role): string
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new ApiException(400, 'Ungültige Rolle');
        }
        return $role;
    }

    private static function validatePin(string $pin): string
    {
        if (!preg_match(self::PIN_PATTERN, $pin)) {
            throw new ApiException(400, 'PIN muss 4-stellig sein');
        }
        return $pin;
    }

    public function create(string $name, string $pin, string $role, array $categoryIds): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        self::validateRole($role);
        self::validatePin($pin);
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM users')->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO users (name, pin_hash, role, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, password_hash($pin, PASSWORD_DEFAULT), $role, $next]);
        $id = (int) $this->db->lastInsertId();
        $this->setCategories($id, $role === 'admin' ? [] : $categoryIds);
        return $id;
    }

    public function update(int $id, string $name, string $role, bool $active, ?string $pin, array $categoryIds): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new ApiException(404, 'Benutzer nicht gefunden');
        }
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        self::validateRole($role);
        if ($pin !== null) {
            self::validatePin($pin);
        }
        $sql = 'UPDATE users SET name = ?, role = ?, active = ?' . ($pin !== null ? ', pin_hash = ?' : '') . ' WHERE id = ?';
        $params = [$name, $role, $active ? 1 : 0];
        if ($pin !== null) {
            $params[] = password_hash($pin, PASSWORD_DEFAULT);
        }
        $params[] = $id;
        $this->db->prepare($sql)->execute($params);
        $this->setCategories($id, $role === 'admin' ? [] : $categoryIds);
    }

    public function setCategories(int $userId, array $categoryIds): void
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM user_categories WHERE user_id = ?')->execute([$userId]);
            if ($categoryIds) {
                $stmt = $this->db->prepare('INSERT INTO user_categories (user_id, category_id) VALUES (?, ?)');
                foreach ($categoryIds as $cid) {
                    $stmt->execute([$userId, $cid]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new ApiException(404, 'Benutzer nicht gefunden');
        }
        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
