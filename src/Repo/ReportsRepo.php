<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

final class ReportsRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function kpis(bool $trackStock): array
    {
        $today = $this->db->query(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_cents), 0) AS sum_cents
             FROM sales WHERE voided_at IS NULL AND DATE(sold_at) = CURDATE()"
        )->fetch();

        $total = $this->db->query(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(total_cents), 0) AS sum_cents FROM sales WHERE voided_at IS NULL'
        )->fetch();

        $profit = $this->db->query(
            "SELECT COALESCE(SUM(si.qty * (si.unit_cents - si.cost_cents)), 0)
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL"
        )->fetchColumn();

        $lowStockCount = 0;
        $lowStockNames = [];
        if ($trackStock) {
            $rows = $this->db->query(
                'SELECT name FROM products WHERE archived_at IS NULL AND stock <= stock_min ORDER BY stock ASC LIMIT 2'
            )->fetchAll();
            $lowStockNames = array_map(static fn ($r) => $r['name'], $rows);
            $lowStockCount = (int) $this->db->query(
                'SELECT COUNT(*) FROM products WHERE archived_at IS NULL AND stock <= stock_min'
            )->fetchColumn();
        }

        $topProduct = $this->productsRanking(1);

        return [
            'todayRevenueCents' => (int) $today['sum_cents'],
            'todayCount' => (int) $today['cnt'],
            'totalRevenueCents' => (int) $total['sum_cents'],
            'totalCount' => (int) $total['cnt'],
            'grossProfitCents' => (int) $profit,
            'lowStockCount' => $lowStockCount,
            'lowStockNames' => $lowStockNames,
            'avgTicketCents' => (int) $total['cnt'] > 0 ? (int) round((int) $total['sum_cents'] / (int) $total['cnt']) : 0,
            'topProductName' => $topProduct[0]['name'] ?? null,
        ];
    }

    public function daily(): array
    {
        $rows = $this->db->query(
            "SELECT DATE(sold_at) AS d, SUM(total_cents) AS sum_cents
             FROM sales WHERE voided_at IS NULL AND sold_at >= (CURDATE() - INTERVAL 6 DAY)
             GROUP BY DATE(sold_at)"
        )->fetchAll();
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['d']] = (int) $r['sum_cents'];
        }
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = [
                'date' => $d,
                'weekday' => self::weekdayShort((int) date('N', strtotime($d))),
                'totalCents' => $byDate[$d] ?? 0,
            ];
        }
        return $out;
    }

    public function hourly(): array
    {
        $rows = $this->db->query(
            "SELECT HOUR(sold_at) AS h, SUM(total_cents) AS sum_cents
             FROM sales WHERE voided_at IS NULL AND HOUR(sold_at) BETWEEN 9 AND 23
             GROUP BY HOUR(sold_at)"
        )->fetchAll();
        $byHour = [];
        foreach ($rows as $r) {
            $byHour[(int) $r['h']] = (int) $r['sum_cents'];
        }
        $out = [];
        for ($h = 9; $h <= 23; $h++) {
            $out[] = ['hour' => $h, 'totalCents' => $byHour[$h] ?? 0];
        }
        return $out;
    }

    public function productsRanking(int $limit = 7): array
    {
        $stmt = $this->db->prepare(
            "SELECT si.product_id, MAX(si.name) AS name, SUM(si.qty) AS qty, SUM(si.qty * si.unit_cents) AS revenue_cents
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL
             GROUP BY si.product_id
             ORDER BY revenue_cents DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute();
        return array_map(static fn ($r) => [
            'productId' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
            'name' => $r['name'],
            'qty' => (int) $r['qty'],
            'revenueCents' => (int) $r['revenue_cents'],
        ], $stmt->fetchAll());
    }

    public function payments(): array
    {
        $rows = $this->db->query(
            "SELECT payment, COUNT(*) AS cnt, COALESCE(SUM(total_cents), 0) AS sum_cents
             FROM sales WHERE voided_at IS NULL GROUP BY payment"
        )->fetchAll();
        $out = ['cash' => ['sumCents' => 0, 'count' => 0], 'card' => ['sumCents' => 0, 'count' => 0]];
        foreach ($rows as $r) {
            $out[$r['payment']] = ['sumCents' => (int) $r['sum_cents'], 'count' => (int) $r['cnt']];
        }
        $totalCount = $out['cash']['count'] + $out['card']['count'];
        $totalSum = $out['cash']['sumCents'] + $out['card']['sumCents'];
        $out['avgTicketCents'] = $totalCount > 0 ? (int) round($totalSum / $totalCount) : 0;
        return $out;
    }

    public function groups(bool $trackStock): array
    {
        $soldRows = $this->db->query(
            "SELECT si.product_id, SUM(si.qty) AS qty, SUM(si.qty * si.unit_cents) AS revenue_cents
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL
             GROUP BY si.product_id"
        )->fetchAll();
        $sold = [];
        foreach ($soldRows as $r) {
            if ($r['product_id'] !== null) {
                $sold[(int) $r['product_id']] = ['qty' => (int) $r['qty'], 'revenueCents' => (int) $r['revenue_cents']];
            }
        }

        $products = $this->db->query(
            'SELECT id, name, category, price_cents, cost_cents, stock, stock_min FROM products WHERE archived_at IS NULL ORDER BY sort_order ASC'
        )->fetchAll();

        $byCategory = [];
        foreach ($products as $p) {
            $byCategory[$p['category']][] = $p;
        }

        $groups = [];
        foreach ($byCategory as $category => $items) {
            $revenue = 0;
            $qty = 0;
            $profit = 0;
            $stockUnits = 0;
            $stockValue = 0;
            $lowCount = 0;
            $rows = [];
            foreach ($items as $p) {
                $id = (int) $p['id'];
                $s = $sold[$id] ?? ['qty' => 0, 'revenueCents' => 0];
                $revenue += $s['revenueCents'];
                $qty += $s['qty'];
                $profit += $s['qty'] * ((int) $p['price_cents'] - (int) $p['cost_cents']);
                $stockUnits += (int) $p['stock'];
                $stockValue += (int) $p['stock'] * (int) $p['cost_cents'];
                if ((int) $p['stock'] <= (int) $p['stock_min']) {
                    $lowCount++;
                }
                $rows[] = ['productId' => $id, 'name' => $p['name'], 'qty' => $s['qty'], 'revenueCents' => $s['revenueCents']];
            }
            usort($rows, static fn ($a, $b) => $b['revenueCents'] <=> $a['revenueCents']);
            $groups[] = [
                'name' => $category,
                'count' => count($items),
                'revenueCents' => $revenue,
                'qty' => $qty,
                'profitCents' => $profit,
                'avgRevenueCents' => count($items) > 0 ? (int) round($revenue / count($items)) : 0,
                'stockUnits' => $trackStock ? $stockUnits : null,
                'stockValueCents' => $trackStock ? $stockValue : null,
                'lowCount' => $lowCount,
                'items' => $rows,
            ];
        }
        usort($groups, static fn ($a, $b) => $b['revenueCents'] <=> $a['revenueCents']);
        $sumAll = array_sum(array_column($groups, 'revenueCents'));
        foreach ($groups as &$g) {
            $g['sharePct'] = $sumAll > 0 ? round($g['revenueCents'] / $sumAll * 100) : 0;
        }
        unset($g);
        return $groups;
    }

    private static function weekdayShort(int $isoDayOfWeek): string
    {
        $names = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        return $names[$isoDayOfWeek] ?? '';
    }
}
