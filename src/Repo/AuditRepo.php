<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\Support;
use PDO;

final class AuditRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function log(string $action, string $detail): void
    {
        $stmt = $this->db->prepare('INSERT INTO audit_log (happened_at, action, detail, ip) VALUES (NOW(), ?, ?, ?)');
        $stmt->execute([$action, $detail, Support::clientIp()]);
    }
}
