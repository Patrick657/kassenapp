<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use Festkasse\Support;
use PDO;

/**
 * Firmen "auf Rechnung" (Verwaltung → Auf Rechnung) and the Abrechnung per Firma: which Bons are
 * still open (not yet on a written invoice), what's in them, and marking them as abgerechnet.
 * The amount owed per Bon is goods − Rabatt + Pfand − Pfand-Rückgabe (sales.total_cents −
 * deposit_returned_cents): Krüge a Firma hands back are credited on its invoice, not paid out in
 * cash. A Bon with only Rückgaben is therefore a credit (negative amount).
 */
final class InvoiceAccountRepo
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** All Firmen incl. archived ones (flagged), with open / billed totals. */
    public function list(bool $withArchived = true): array
    {
        $sql = "SELECT ia.*,
                       COALESCE(sq.open_cnt, 0) AS open_cnt, COALESCE(sq.open_cents, 0) AS open_cents,
                       COALESCE(sq.billed_cnt, 0) AS billed_cnt, COALESCE(sq.billed_cents, 0) AS billed_cents,
                       sq.first_open_at, sq.last_open_at
                FROM invoice_accounts ia
                LEFT JOIN (
                    SELECT invoice_account_id,
                           SUM(billed_at IS NULL) AS open_cnt,
                           SUM(CASE WHEN billed_at IS NULL THEN CAST(total_cents AS SIGNED) - CAST(deposit_returned_cents AS SIGNED) ELSE 0 END) AS open_cents,
                           SUM(billed_at IS NOT NULL) AS billed_cnt,
                           SUM(CASE WHEN billed_at IS NOT NULL THEN CAST(total_cents AS SIGNED) - CAST(deposit_returned_cents AS SIGNED) ELSE 0 END) AS billed_cents,
                           MIN(CASE WHEN billed_at IS NULL THEN sold_at END) AS first_open_at,
                           MAX(CASE WHEN billed_at IS NULL THEN sold_at END) AS last_open_at
                    FROM sales WHERE voided_at IS NULL AND payment = 'invoice'
                    GROUP BY invoice_account_id
                ) sq ON sq.invoice_account_id = ia.id"
            . ($withArchived ? '' : ' WHERE ia.archived_at IS NULL')
            . ' ORDER BY ia.archived_at IS NOT NULL, ia.sort_order ASC, ia.code ASC';
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'address' => $r['address'] ?? '',
            'note' => $r['note'] ?? '',
            'archived' => $r['archived_at'] !== null,
            'openCount' => (int) $r['open_cnt'],
            'openCents' => (int) $r['open_cents'],
            'billedCount' => (int) $r['billed_cnt'],
            'billedCents' => (int) $r['billed_cents'],
            'firstOpenAt' => $r['first_open_at'] !== null ? Support::toIso($r['first_open_at']) : null,
            'lastOpenAt' => $r['last_open_at'] !== null ? Support::toIso($r['last_open_at']) : null,
        ], $this->db->query($sql)->fetchAll());
    }

    /** Active Firmen for the Kasse buttons — Kürzel and name only. */
    public function forPos(): array
    {
        $rows = $this->db->query('SELECT id, code, name FROM invoice_accounts WHERE archived_at IS NULL ORDER BY sort_order ASC, code ASC')->fetchAll();
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name']], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoice_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** For a booking: must exist and not be archived. */
    public function findActive(int $id): ?array
    {
        $row = $this->find($id);
        return $row !== null && $row['archived_at'] === null ? $row : null;
    }

    public function create(array $b): int
    {
        [$code, $name, $address, $note] = $this->valid($b, null);
        $next = (int) $this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM invoice_accounts')->fetchColumn();
        $this->db->prepare('INSERT INTO invoice_accounts (code, name, address, note, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$code, $name, $address, $note, $next]);
        return (int) $this->db->lastInsertId();
    }

    /** Only affects future bookings — past ones keep code and name frozen on the sale. */
    public function update(int $id, array $b): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Firma nicht gefunden');
        }
        [$code, $name, $address, $note] = $this->valid($b, $id);
        $this->db->prepare('UPDATE invoice_accounts SET code = ?, name = ?, address = ?, note = ? WHERE id = ?')
            ->execute([$code, $name, $address, $note, $id]);
    }

    /**
     * "Löschen" archives: the Firma leaves the Kasse, its Bons and Abrechnungen stay. Refused
     * while Bons are still open — those have to be billed first, or they'd be forgotten.
     */
    public function archive(int $id): void
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Firma nicht gefunden');
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM sales WHERE invoice_account_id = ? AND payment = 'invoice' AND voided_at IS NULL AND billed_at IS NULL");
        $stmt->execute([$id]);
        $open = (int) $stmt->fetchColumn();
        if ($open > 0) {
            throw new ApiException(409, "Die Firma hat noch {$open} offene Bon(s) – erst abrechnen, dann löschen");
        }
        $this->db->prepare('UPDATE invoice_accounts SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /**
     * Abrechnung of one Firma. $status: 'open' (not yet billed), 'billed' or 'all'; optional
     * date range (Y-m-d, inclusive) on the sale date. Returns the Bons, the articles summed by
     * name + unit price, and the totals — everything an invoice needs.
     */
    public function statement(int $id, string $status = 'open', ?string $from = null, ?string $to = null): array
    {
        $account = $this->find($id) ?? throw new ApiException(404, 'Firma nicht gefunden');
        $where = "s.voided_at IS NULL AND s.payment = 'invoice' AND s.invoice_account_id = ?";
        $params = [$id];
        if ($status === 'open') {
            $where .= ' AND s.billed_at IS NULL';
        } elseif ($status === 'billed') {
            $where .= ' AND s.billed_at IS NOT NULL';
        }
        foreach ([[$from, '>=', ' 00:00:00'], [$to, '<=', ' 23:59:59']] as [$d, $op, $time]) {
            if ($d !== null && $d !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    throw new ApiException(400, 'Ungültiges Datum');
                }
                $where .= " AND s.sold_at {$op} ?";
                $params[] = $d . $time;
            }
        }

        $stmt = $this->db->prepare(
            "SELECT s.id, s.receipt_no, s.sold_at, s.subtotal_cents, s.discount_cents, s.deposit_cents, s.deposit_returned_cents, s.total_cents,
                    s.invoice_ref, s.billed_at, s.billed_ref, r.name AS register_name
             FROM sales s LEFT JOIN registers r ON r.id = s.register_id
             WHERE {$where} ORDER BY s.sold_at ASC, s.id ASC"
        );
        $stmt->execute($params);
        $sales = $stmt->fetchAll();

        $items = [];
        $linesBySale = [];
        $returns = [];
        $returnsBySale = [];
        if ($sales) {
            $ids = array_map(static fn ($s) => (int) $s['id'], $sales);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $itemStmt = $this->db->prepare("SELECT sale_id, name, unit_cents, deposit_cents, qty FROM sale_items WHERE sale_id IN ({$in}) ORDER BY id");
            $itemStmt->execute($ids);
            foreach ($itemStmt->fetchAll() as $i) {
                $linesBySale[(int) $i['sale_id']][] = ['name' => $i['name'], 'qty' => (int) $i['qty'], 'unitCents' => (int) $i['unit_cents'], 'depositCents' => (int) $i['deposit_cents']];
                $key = $i['name'] . '|' . $i['unit_cents'];
                $items[$key] ??= ['name' => $i['name'], 'unitCents' => (int) $i['unit_cents'], 'qty' => 0, 'sumCents' => 0];
                $items[$key]['qty'] += (int) $i['qty'];
                $items[$key]['sumCents'] += (int) $i['qty'] * (int) $i['unit_cents'];
            }
            // Pfand-Rückgaben credited on the invoice (amount frozen in credit_cents).
            $retStmt = $this->db->prepare(
                "SELECT m.sale_id, m.qty, COALESCE(m.credit_cents, -m.amount_cents) AS amount_cents, COALESCE(dt.name, 'Pfand') AS name
                 FROM cash_movements m LEFT JOIN deposit_types dt ON dt.id = m.deposit_type_id
                 WHERE m.type = 'deposit_return' AND m.sale_id IN ({$in}) ORDER BY m.id"
            );
            $retStmt->execute($ids);
            foreach ($retStmt->fetchAll() as $r) {
                $qty = (int) $r['qty'];
                $amount = (int) $r['amount_cents'];
                $returnsBySale[(int) $r['sale_id']][] = ['name' => $r['name'], 'qty' => $qty, 'amountCents' => $amount];
                $unit = $qty > 0 ? intdiv($amount, $qty) : $amount;
                $key = $r['name'] . '|' . $unit;
                $returns[$key] ??= ['name' => $r['name'], 'unitCents' => $unit, 'qty' => 0, 'sumCents' => 0];
                $returns[$key]['qty'] += $qty;
                $returns[$key]['sumCents'] += $amount;
            }
        }
        $returns = array_values($returns);
        $items = array_values($items);
        usort($items, static fn ($a, $b) => [$a['name'], $a['unitCents']] <=> [$b['name'], $b['unitCents']]);

        $sum = static fn (string $col) => array_sum(array_map(static fn ($s) => (int) $s[$col], $sales));
        return [
            'account' => [
                'id' => (int) $account['id'], 'code' => $account['code'], 'name' => $account['name'],
                'address' => $account['address'] ?? '', 'note' => $account['note'] ?? '', 'archived' => $account['archived_at'] !== null,
            ],
            'status' => $status,
            'from' => $from ?: null,
            'to' => $to ?: null,
            'sales' => array_map(static fn ($s) => [
                'id' => (int) $s['id'],
                'receiptNo' => $s['receipt_no'],
                'soldAt' => Support::toIso($s['sold_at']),
                'registerName' => $s['register_name'],
                'ref' => $s['invoice_ref'] ?? '',
                'subtotalCents' => (int) $s['subtotal_cents'],
                'discountCents' => (int) $s['discount_cents'],
                'depositCents' => (int) $s['deposit_cents'],
                'depositReturnedCents' => (int) $s['deposit_returned_cents'],
                'totalCents' => (int) $s['total_cents'] - (int) $s['deposit_returned_cents'],
                'billedAt' => $s['billed_at'] !== null ? Support::toIso($s['billed_at']) : null,
                'billedRef' => $s['billed_ref'] ?? '',
                'lines' => $linesBySale[(int) $s['id']] ?? [],
                'returns' => $returnsBySale[(int) $s['id']] ?? [],
            ], $sales),
            'items' => $items,
            'returns' => $returns,
            'totals' => [
                'count' => count($sales),
                'goodsCents' => $sum('subtotal_cents'),
                'discountCents' => $sum('discount_cents'),
                'depositCents' => $sum('deposit_cents'),
                'depositReturnedCents' => $sum('deposit_returned_cents'),
                'totalCents' => $sum('total_cents') - $sum('deposit_returned_cents'),
            ],
        ];
    }

    /**
     * Marks exactly these Bons as abgerechnet (or takes that back). Only Bons of this Firma, not
     * voided, in the right state are touched; returns how many changed.
     * @param int[] $saleIds
     */
    public function setBilled(int $id, array $saleIds, bool $billed, string $ref = ''): int
    {
        if ($this->find($id) === null) {
            throw new ApiException(404, 'Firma nicht gefunden');
        }
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds), static fn ($v) => $v > 0)));
        if (!$saleIds) {
            throw new ApiException(400, 'Keine Bons ausgewählt');
        }
        $ref = trim($ref);
        if (mb_strlen($ref) > 60) {
            throw new ApiException(400, 'Rechnungsnummer ist zu lang (max. 60 Zeichen)');
        }
        $in = implode(',', array_fill(0, count($saleIds), '?'));
        $base = "WHERE invoice_account_id = ? AND payment = 'invoice' AND voided_at IS NULL AND id IN ({$in})";
        if ($billed) {
            $stmt = $this->db->prepare("UPDATE sales SET billed_at = NOW(), billed_ref = ? {$base} AND billed_at IS NULL");
            $stmt->execute(array_merge([$ref !== '' ? $ref : null, $id], $saleIds));
        } else {
            $stmt = $this->db->prepare("UPDATE sales SET billed_at = NULL, billed_ref = NULL {$base} AND billed_at IS NOT NULL");
            $stmt->execute(array_merge([$id], $saleIds));
        }
        return $stmt->rowCount();
    }

    /** @return array{0:string,1:string,2:?string,3:?string} */
    private function valid(array $b, ?int $exceptId): array
    {
        $code = trim((string) ($b['code'] ?? ''));
        $name = trim((string) ($b['name'] ?? ''));
        $address = trim(str_replace("\r\n", "\n", (string) ($b['address'] ?? '')));
        $note = trim((string) ($b['note'] ?? ''));
        if ($code === '') {
            throw new ApiException(400, 'Kürzel fehlt');
        }
        if (mb_strlen($code) > 12) {
            throw new ApiException(400, 'Kürzel ist zu lang (max. 12 Zeichen)');
        }
        if ($name === '') {
            throw new ApiException(400, 'Firmenname fehlt');
        }
        if (mb_strlen($name) > 120) {
            throw new ApiException(400, 'Firmenname ist zu lang (max. 120 Zeichen)');
        }
        if (mb_strlen($address) > 1000 || mb_strlen($note) > 255) {
            throw new ApiException(400, 'Anschrift oder Notiz ist zu lang');
        }
        // Kürzel must be unique among the Firmen shown in the Kasse (archived ones may reuse it).
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM invoice_accounts WHERE LOWER(code) = LOWER(?) AND archived_at IS NULL AND id <> ?');
        $stmt->execute([$code, $exceptId ?? 0]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new ApiException(400, "Kürzel „{$code}“ ist schon vergeben");
        }
        return [$code, $name, $address !== '' ? $address : null, $note !== '' ? $note : null];
    }
}
