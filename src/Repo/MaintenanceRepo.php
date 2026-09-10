<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

final class MaintenanceRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function counts(): array
    {
        return [
            'sales' => (int) $this->db->query('SELECT COUNT(*) FROM sales')->fetchColumn(),
            'movements' => (int) $this->db->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn(),
            'products' => (int) $this->db->query('SELECT COUNT(*) FROM products WHERE archived_at IS NULL')->fetchColumn(),
        ];
    }

    /** Deletes all sales, journal entries and Z-reports. Products and settings survive. */
    public function purgeSalesAndJournal(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('TRUNCATE TABLE sale_items');
        $this->db->exec('TRUNCATE TABLE sales');
        $this->db->exec('TRUNCATE TABLE cash_movements');
        $this->db->exec('TRUNCATE TABLE z_reports');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Deletes everything, including products, and reseeds the demo article set from migrations/002_seed_demo.sql. */
    public function resetToDemo(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec('TRUNCATE TABLE sale_items');
        $this->db->exec('TRUNCATE TABLE sales');
        $this->db->exec('TRUNCATE TABLE cash_movements');
        $this->db->exec('TRUNCATE TABLE z_reports');
        $this->db->exec('TRUNCATE TABLE products');
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $seedFile = __DIR__ . '/../../migrations/002_seed_demo.sql';
        $sql = file_get_contents($seedFile);
        if ($sql !== false) {
            $this->db->exec($sql);
        }
    }
}
