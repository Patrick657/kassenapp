<?php
declare(strict_types=1);

namespace Festkasse\Repo;

use Festkasse\ApiException;
use Festkasse\Support;
use PDO;

final class SaleRepo
{
    public function __construct(private readonly PDO $db, private readonly ProductRepo $products, private readonly CashRepo $cash)
    {
    }

    public function findByClientUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM sales WHERE client_uuid = ?');
        $stmt->execute([$uuid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->receipt((int) $id);
    }

    /**
     * One checkout = a normal cart (0+ sale items) plus 0+ Pfand-Rückgabe lines, booked together
     * as a single transaction with one receipt — a customer buying new drinks while handing back
     * a Krug sees one combined amount, not two separate bookings. A return line picks a Pfand-
     * Option directly (not a specific article) — the register doesn't need to know which drink a
     * returned Krug originally held, only how much deposit to pay back.
     *
     * @param array<int, array{productId:?int, name:string, unitCents:int, qty:int}> $items
     * @param array<int, array{depositType:array, qty:int}> $returnLines resolved Pfand-Option rows
     *        (id/name/amount_cents) + qty, already validated to exist by the caller
     */
    public function create(array $items, int $discountCents, string $payment, int $givenCents, bool $trackStock, ?string $clientUuid, array $returnLines = []): array
    {
        if (empty($items) && empty($returnLines)) {
            throw new ApiException(400, 'Warenkorb ist leer');
        }
        if (!in_array($payment, ['cash', 'card'], true)) {
            throw new ApiException(400, 'Ungültige Zahlart');
        }
        foreach ($returnLines as $r) {
            if ((int) $r['qty'] <= 0) {
                throw new ApiException(400, 'Ungültige Pfand-Rückgabe im Warenkorb');
            }
        }
        $returnedCents = array_sum(array_map(
            static fn ($r) => (int) $r['depositType']['amount_cents'] * (int) $r['qty'],
            $returnLines
        ));

        $subtotal = 0;
        $depositTotal = 0;
        $resolved = [];
        foreach ($items as $item) {
            $qty = (int) $item['qty'];
            $unitCents = (int) $item['unitCents'];
            if ($qty <= 0 || $unitCents < 0) {
                throw new ApiException(400, 'Ungültige Position im Warenkorb');
            }
            $costCents = 0;
            $depositCents = 0;
            $name = (string) $item['name'];
            $productId = $item['productId'] !== null ? (int) $item['productId'] : null;
            $productTracksStock = true;
            if ($productId !== null) {
                $product = $this->products->find($productId);
                if ($product !== null) {
                    $costCents = (int) $product['cost_cents'];
                    $depositCents = (int) $product['deposit_cents'];
                    $name = $name !== '' ? $name : (string) $product['name'];
                    $productTracksStock = (bool) $product['track_stock'];
                }
            }
            $subtotal += $unitCents * $qty;
            $depositTotal += $depositCents * $qty;
            $resolved[] = ['productId' => $productId, 'name' => $name, 'unitCents' => $unitCents, 'costCents' => $costCents, 'depositCents' => $depositCents, 'qty' => $qty, 'trackStock' => $productTracksStock];
        }

        // Pfand is never discountable — it's a refundable liability, not part of the goods price.
        $discount = max(0, min($discountCents, $subtotal));
        $grossTotal = $subtotal - $discount + $depositTotal;

        // A same-checkout Pfand-Rückgabe only offsets what the customer hands over when paying
        // cash — a card charge still needs the full gross amount, since a card payment and a cash
        // refund are two different tenders that can't net against each other. That's also the only
        // way "net" can go negative: a card sale's given/change stay exactly as before, unaffected.
        if ($payment === 'cash') {
            $net = $grossTotal - $returnedCents;
            if ($net > 0) {
                if ($givenCents < $net) {
                    throw new ApiException(400, 'Betrag noch nicht ausreichend');
                }
                $given = $givenCents;
                $change = $given - $net;
            } else {
                // Nothing to collect (net == 0), or the register owes the customer money
                // (net < 0) — either way there's no "given" amount to validate; the
                // deposit_return cash movements below book the full payout on their own.
                $given = 0;
                $change = 0;
            }
        } else {
            $given = $grossTotal;
            $change = 0;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO sales (client_uuid, receipt_no, sold_at, subtotal_cents, discount_cents, deposit_cents, deposit_returned_cents, total_cents, payment, given_cents, change_cents)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // receipt_no is finalized below once we have the id; store a temporary unique placeholder first.
            $placeholder = 'TMP-' . bin2hex(random_bytes(8));
            $stmt->execute([$clientUuid, $placeholder, $subtotal, $discount, $depositTotal, $returnedCents, $grossTotal, $payment, $given, $change]);
            $saleId = (int) $this->db->lastInsertId();
            $receiptNo = Support::receiptNo($saleId);
            $this->db->prepare('UPDATE sales SET receipt_no = ? WHERE id = ?')->execute([$receiptNo, $saleId]);

            if (!empty($resolved)) {
                $itemStmt = $this->db->prepare(
                    'INSERT INTO sale_items (sale_id, product_id, name, unit_cents, deposit_cents, cost_cents, qty) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($resolved as $r) {
                    $itemStmt->execute([$saleId, $r['productId'], $r['name'], $r['unitCents'], $r['depositCents'], $r['costCents'], $r['qty']]);
                    if ($trackStock && $r['trackStock'] && $r['productId'] !== null) {
                        $this->products->deductStock($r['productId'], $r['qty']);
                    }
                }
                $note = count($resolved) . ' Position(en) · ' . ($payment === 'cash' ? 'Bar' : 'Karte');
                $this->cash->insert('sale', $payment === 'cash' ? $grossTotal : 0, $note, $saleId);
            }

            foreach ($returnLines as $r) {
                $amount = (int) $r['depositType']['amount_cents'] * (int) $r['qty'];
                if ($amount > $this->cash->balanceCents()) {
                    throw new ApiException(400, 'Mehr als der Kassenbestand');
                }
                $note = 'Pfandrückgabe ' . $r['qty'] . '× ' . $r['depositType']['name'];
                $this->cash->insert('deposit_return', -$amount, $note, $saleId, null, (int) $r['qty'], (int) $r['depositType']['id']);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->receipt($saleId);
    }

    public function receipt(int $saleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new ApiException(404, 'Bon nicht gefunden');
        }
        $itemStmt = $this->db->prepare('SELECT product_id, name, unit_cents, deposit_cents, qty FROM sale_items WHERE sale_id = ?');
        $itemStmt->execute([$saleId]);
        $items = $itemStmt->fetchAll();

        $returnStmt = $this->db->prepare(
            "SELECT m.deposit_type_id, m.qty, -m.amount_cents AS amount_cents, dt.name
             FROM cash_movements m LEFT JOIN deposit_types dt ON dt.id = m.deposit_type_id
             WHERE m.sale_id = ? AND m.type = 'deposit_return'"
        );
        $returnStmt->execute([$saleId]);
        $returns = $returnStmt->fetchAll();

        return [
            'receiptNo' => $sale['receipt_no'],
            'soldAt' => Support::toIso($sale['sold_at']),
            'items' => array_map(static fn ($i) => [
                'productId' => $i['product_id'] !== null ? (int) $i['product_id'] : null,
                'name' => $i['name'],
                'unitCents' => (int) $i['unit_cents'],
                'depositCents' => (int) $i['deposit_cents'],
                'qty' => (int) $i['qty'],
            ], $items),
            'returns' => array_map(static fn ($r) => [
                'depositTypeId' => $r['deposit_type_id'] !== null ? (int) $r['deposit_type_id'] : null,
                'name' => $r['name'] ?? 'Pfand',
                'qty' => (int) $r['qty'],
                'amountCents' => (int) $r['amount_cents'],
            ], $returns),
            'subtotalCents' => (int) $sale['subtotal_cents'],
            'discountCents' => (int) $sale['discount_cents'],
            'depositCents' => (int) $sale['deposit_cents'],
            'depositReturnedCents' => (int) $sale['deposit_returned_cents'],
            'totalCents' => (int) $sale['total_cents'],
            'netCents' => (int) $sale['total_cents'] - (int) $sale['deposit_returned_cents'],
            'payment' => $sale['payment'],
            'givenCents' => (int) $sale['given_cents'],
            'changeCents' => (int) $sale['change_cents'],
        ];
    }

    public function findByReceiptNo(string $receiptNo): array
    {
        $stmt = $this->db->prepare('SELECT id FROM sales WHERE receipt_no = ?');
        $stmt->execute([$receiptNo]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new ApiException(404, 'Bon nicht gefunden');
        }
        return $this->receipt((int) $id);
    }
}
