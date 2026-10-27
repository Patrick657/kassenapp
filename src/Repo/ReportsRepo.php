<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use PDO;

final class ReportsRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * What a checkout actually brought in: its total minus any Pfand paid back out in the same
     * checkout (a return-only checkout is a sales row with total 0, so it comes out negative).
     * Every Umsatz figure sums this, so returned Pfand leaves the Umsatz again instead of staying
     * in it forever. Both columns are UNSIGNED — without the casts MySQL errors on a negative result.
     */
    public const NET_CENTS = 'CAST(total_cents AS SIGNED) - CAST(deposit_returned_cents AS SIGNED)';

    /**
     * Every report takes an optional Kasse: null = Gesamt over all Kassen, an id = just that one.
     * Returns the SQL fragment (for a sales table aliased as $alias, or unaliased) and its params.
     * @return array{0:string, 1:array}
     */
    private static function registerFilter(?int $registerId, string $alias = ''): array
    {
        if ($registerId === null) {
            return ['', []];
        }
        return [' AND ' . ($alias !== '' ? $alias . '.' : '') . 'register_id = ?', [$registerId]];
    }

    private function fetchOne(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    private function fetchAllRows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function kpis(bool $trackStock, ?int $registerId = null): array
    {
        [$f, $p] = self::registerFilter($registerId);
        [$fs, $ps] = self::registerFilter($registerId, 's');
        $today = $this->fetchOne(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(" . self::NET_CENTS . "), 0) AS sum_cents
             FROM sales WHERE voided_at IS NULL AND payment <> 'comp' AND DATE(sold_at) = CURDATE()" . $f,
            $p
        );

        $total = $this->fetchOne(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(" . self::NET_CENTS . "), 0) AS sum_cents FROM sales WHERE voided_at IS NULL AND payment <> 'comp'" . $f,
            $p
        );

        $profitStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(si.qty * (si.unit_cents - si.cost_cents)), 0)
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL AND s.payment <> 'comp'" . $fs
        );
        $profitStmt->execute($ps);
        $profit = $profitStmt->fetchColumn();

        $lowStockCount = 0;
        $lowStockNames = [];
        if ($trackStock) {
            $rows = $this->db->query(
                'SELECT name FROM products WHERE archived_at IS NULL AND track_stock = 1 AND stock <= stock_min ORDER BY stock ASC LIMIT 2'
            )->fetchAll();
            $lowStockNames = array_map(static fn ($r) => $r['name'], $rows);
            $lowStockCount = (int) $this->db->query(
                'SELECT COUNT(*) FROM products WHERE archived_at IS NULL AND track_stock = 1 AND stock <= stock_min'
            )->fetchColumn();
        }

        $topProduct = $this->productsRanking(1, $registerId);

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

    public function daily(?int $registerId = null): array
    {
        [$f, $p] = self::registerFilter($registerId);
        $rows = $this->fetchAllRows(
            "SELECT DATE(sold_at) AS d, SUM(" . self::NET_CENTS . ") AS sum_cents
             FROM sales WHERE voided_at IS NULL AND payment <> 'comp' AND sold_at >= (CURDATE() - INTERVAL 6 DAY)" . $f . "
             GROUP BY DATE(sold_at)",
            $p
        );
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

    public function hourly(?int $registerId = null): array
    {
        [$f, $p] = self::registerFilter($registerId);
        $rows = $this->fetchAllRows(
            "SELECT HOUR(sold_at) AS h, SUM(" . self::NET_CENTS . ") AS sum_cents
             FROM sales WHERE voided_at IS NULL AND payment <> 'comp' AND HOUR(sold_at) BETWEEN 9 AND 23" . $f . "
             GROUP BY HOUR(sold_at)",
            $p
        );
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

    public function productsRanking(int $limit = 7, ?int $registerId = null): array
    {
        [$fs, $ps] = self::registerFilter($registerId, 's');
        $stmt = $this->db->prepare(
            "SELECT si.product_id, MAX(si.name) AS name, SUM(si.qty) AS qty, SUM(si.qty * si.unit_cents) AS revenue_cents
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL AND s.payment <> 'comp'" . $fs . "
             GROUP BY si.product_id
             ORDER BY revenue_cents DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute($ps);
        return array_map(static fn ($r) => [
            'productId' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
            'name' => $r['name'],
            'qty' => (int) $r['qty'],
            'revenueCents' => (int) $r['revenue_cents'],
        ], $stmt->fetchAll());
    }

    public function payments(?int $registerId = null): array
    {
        [$f, $p] = self::registerFilter($registerId);
        $rows = $this->fetchAllRows(
            "SELECT payment, COUNT(*) AS cnt, COALESCE(SUM(total_cents), 0) AS sum_cents,
                    COALESCE(SUM(deposit_returned_cents), 0) AS returned_cents
             FROM sales WHERE voided_at IS NULL AND payment <> 'comp'" . $f . " GROUP BY payment",
            $p
        );
        $out = ['cash' => ['sumCents' => 0, 'count' => 0], 'card' => ['sumCents' => 0, 'count' => 0], 'invoice' => ['sumCents' => 0, 'count' => 0]];
        $returned = 0;
        foreach ($rows as $r) {
            $out[$r['payment']] = ['sumCents' => (int) $r['sum_cents'], 'count' => (int) $r['cnt']];
            if ($r['payment'] === 'invoice') {
                // Credited on the Firma's invoice, not paid out.
                $out['invoice']['sumCents'] -= (int) $r['returned_cents'];
            } else {
                $returned += (int) $r['returned_cents'];
            }
        }
        // Returned Pfand is paid out in cash, even when the same checkout was paid by card.
        $out['cash']['sumCents'] -= $returned;
        $totalCount = $out['cash']['count'] + $out['card']['count'] + $out['invoice']['count'];
        $totalSum = $out['cash']['sumCents'] + $out['card']['sumCents'] + $out['invoice']['sumCents'];
        $out['avgTicketCents'] = $totalCount > 0 ? (int) round($totalSum / $totalCount) : 0;
        return $out;
    }

    /**
     * @param ?array $categoryNames when filtering on one Kasse that sells only certain article
     *        groups: just those groups (plus any other group it did sell something from)
     */
    public function groups(bool $trackStock, ?int $registerId = null, ?array $categoryNames = null): array
    {
        [$fs, $ps] = self::registerFilter($registerId, 's');
        $soldRows = $this->fetchAllRows(
            "SELECT si.product_id, SUM(si.qty) AS qty, SUM(si.qty * si.unit_cents) AS revenue_cents
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL AND s.payment <> 'comp'" . $fs . "
             GROUP BY si.product_id",
            $ps
        );
        $sold = [];
        foreach ($soldRows as $r) {
            if ($r['product_id'] !== null) {
                $sold[(int) $r['product_id']] = ['qty' => (int) $r['qty'], 'revenueCents' => (int) $r['revenue_cents']];
            }
        }

        $products = $this->db->query(
            'SELECT id, name, category, price_cents, cost_cents, stock, stock_min, track_stock FROM products WHERE archived_at IS NULL ORDER BY sort_order ASC'
        )->fetchAll();

        $byCategory = [];
        foreach ($products as $p) {
            $byCategory[$p['category']][] = $p;
        }
        if ($categoryNames !== null) {
            $byCategory = array_filter(
                $byCategory,
                static fn ($items, $category) => in_array($category, $categoryNames, true)
                    || array_sum(array_map(static fn ($p) => $sold[(int) $p['id']]['qty'] ?? 0, $items)) > 0,
                ARRAY_FILTER_USE_BOTH
            );
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
                if ((bool) $p['track_stock']) {
                    $stockUnits += (int) $p['stock'];
                    $stockValue += (int) $p['stock'] * (int) $p['cost_cents'];
                    if ((int) $p['stock'] <= (int) $p['stock_min']) {
                        $lowCount++;
                    }
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

    /**
     * "Ohne Berechnung" bookings per Bereich: how many, what the goods would have cost (selling
     * price) and what they actually cost (Einkauf), plus the articles behind it. Grouped by the
     * name frozen on the booking, so a Bereich deleted since still shows up with its history.
     * These rows are excluded from every revenue figure above — this is the one place they count.
     */
    public function comp(?int $registerId = null): array
    {
        [$fs, $ps] = self::registerFilter($registerId, 's');
        $totals = $this->fetchAllRows(
            "SELECT s.comp_name, COUNT(*) AS cnt, COALESCE(SUM(s.total_cents), 0) AS value_cents
             FROM sales s WHERE s.voided_at IS NULL AND s.payment = 'comp'" . $fs . "
             GROUP BY s.comp_name ORDER BY value_cents DESC",
            $ps
        );
        $itemRows = $this->fetchAllRows(
            "SELECT s.comp_name, si.name, SUM(si.qty) AS qty, SUM(si.qty * si.unit_cents) AS value_cents,
                    SUM(si.qty * si.cost_cents) AS cost_cents
             FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.voided_at IS NULL AND s.payment = 'comp'" . $fs . "
             GROUP BY s.comp_name, si.name ORDER BY value_cents DESC",
            $ps
        );
        $items = [];
        $cost = [];
        foreach ($itemRows as $r) {
            $key = (string) $r['comp_name'];
            $items[$key][] = ['name' => $r['name'], 'qty' => (int) $r['qty'], 'valueCents' => (int) $r['value_cents']];
            $cost[$key] = ($cost[$key] ?? 0) + (int) $r['cost_cents'];
        }
        return array_map(static function ($t) use ($items, $cost) {
            $key = (string) $t['comp_name'];
            return [
                'name' => $key !== '' ? $key : 'Ohne Bereich',
                'count' => (int) $t['cnt'],
                'valueCents' => (int) $t['value_cents'],
                'costCents' => $cost[$key] ?? 0,
                'qty' => array_sum(array_column($items[$key] ?? [], 'qty')),
                'items' => $items[$key] ?? [],
            ];
        }, $totals);
    }

    private static function weekdayShort(int $isoDayOfWeek): string
    {
        $names = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        return $names[$isoDayOfWeek] ?? '';
    }
}
