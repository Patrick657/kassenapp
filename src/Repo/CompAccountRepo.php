<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use PDO;

/** Bereiche "ohne Berechnung" (Verwaltung → Ohne Berechnung) — e.g. "Band", "Helfer". */
final class CompAccountRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** With lifetime booking count and goods value (at selling price) per Bereich. */
    public function list(): array
    {
        $sql = "SELECT ca.*, COALESCE(sq.cnt, 0) AS sale_count, COALESCE(sq.value_cents, 0) AS value_cents
                FROM comp_accounts ca
                LEFT JOIN (
                    SELECT comp_account_id, COUNT(*) AS cnt, SUM(total_cents) AS value_cents
                    FROM sales WHERE voided_at IS NULL AND payment = 'comp' AND comp_account_id IS NOT NULL
                    GROUP BY comp_account_id
                ) sq ON sq.comp_account_id = ca.id
                ORDER BY ca.sort_order ASC, ca.name ASC";
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'saleCount' => (int) $r['sale_count'],
            'valueCents' => (int) $r['value_cents'],
        ], $this->db->query($sql)->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM comp_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name): int
    {
        $name = $this->validName($name, null);
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM comp_accounts')->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO comp_accounts (name, sort_order) VALUES (?, ?)');
        $stmt->execute([$name, $next]);
        return (int) $this->db->lastInsertId();
    }

    /** Only affects future bookings — past ones keep the name frozen in sales.comp_name. */
    public function rename(int $id, string $name): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Bereich nicht gefunden');
        }
        $name = $this->validName($name, $id);
        $this->db->prepare('UPDATE comp_accounts SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /** Past bookings survive: they keep comp_name and still show up in the Auswertung. */
    public function delete(int $id): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Bereich nicht gefunden');
        }
        $this->db->prepare('UPDATE sales SET comp_account_id = NULL WHERE comp_account_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM comp_accounts WHERE id = ?')->execute([$id]);
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
        $stmt = $this->db->prepare('SELECT id FROM comp_accounts WHERE name = ?');
        $stmt->execute([$name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && (int) $existing !== $exceptId) {
            throw new ApiException(400, 'Bereich existiert bereits');
        }
        return $name;
    }
}
