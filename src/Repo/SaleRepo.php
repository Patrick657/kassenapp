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
     * @param array<int, array{productId:?int, name:string, unitCents:int, qty:int}> $items
     */
    public function create(array $items, int $discountCents, string $payment, int $givenCents, bool $trackStock, ?string $clientUuid): array
    {
        if (empty($items)) {
            throw new ApiException(400, 'Warenkorb ist leer');
        }
        if (!in_array($payment, ['cash', 'card'], true)) {
            throw new ApiException(400, 'Ungültige Zahlart');
        }

        $subtotal = 0;
        $resolved = [];
        foreach ($items as $item) {
            $qty = (int) $item['qty'];
            $unitCents = (int) $item['unitCents'];
            if ($qty <= 0 || $unitCents < 0) {
                throw new ApiException(400, 'Ungültige Position im Warenkorb');
            }
            $costCents = 0;
            $name = (string) $item['name'];
            $productId = $item['productId'] !== null ? (int) $item['productId'] : null;
            if ($productId !== null) {
                $product = $this->products->find($productId);
                if ($product !== null) {
                    $costCents = (int) $product['cost_cents'];
                    $name = $name !== '' ? $name : (string) $product['name'];
                }
            }
            $subtotal += $unitCents * $qty;
            $resolved[] = ['productId' => $productId, 'name' => $name, 'unitCents' => $unitCents, 'costCents' => $costCents, 'qty' => $qty];
        }

        $discount = max(0, min($discountCents, $subtotal));
        $total = $subtotal - $discount;
        if ($payment === 'cash' && $givenCents < $total) {
            throw new ApiException(400, 'Betrag noch nicht ausreichend');
        }
        $given = $payment === 'cash' ? $givenCents : $total;
        $change = $payment === 'cash' ? $given - $total : 0;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO sales (client_uuid, receipt_no, sold_at, subtotal_cents, discount_cents, total_cents, payment, given_cents, change_cents)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)'
            );
            // receipt_no is finalized below once we have the id; store a temporary unique placeholder first.
            $placeholder = 'TMP-' . bin2hex(random_bytes(8));
            $stmt->execute([$clientUuid, $placeholder, $subtotal, $discount, $total, $payment, $given, $change]);
            $saleId = (int) $this->db->lastInsertId();
            $receiptNo = Support::receiptNo($saleId);
            $this->db->prepare('UPDATE sales SET receipt_no = ? WHERE id = ?')->execute([$receiptNo, $saleId]);

            $itemStmt = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, name, unit_cents, cost_cents, qty) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($resolved as $r) {
                $itemStmt->execute([$saleId, $r['productId'], $r['name'], $r['unitCents'], $r['costCents'], $r['qty']]);
                if ($trackStock && $r['productId'] !== null) {
                    $this->products->deductStock($r['productId'], $r['qty']);
                }
            }

            $count = array_sum(array_map(static fn ($r) => $r['qty'], $resolved));
            $note = count($resolved) . ' Position(en) · ' . ($payment === 'cash' ? 'Bar' : 'Karte');
            $this->cash->insert('sale', $payment === 'cash' ? $total : 0, $note, $saleId);

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
        $itemStmt = $this->db->prepare('SELECT product_id, name, unit_cents, qty FROM sale_items WHERE sale_id = ?');
        $itemStmt->execute([$saleId]);
        $items = $itemStmt->fetchAll();

        return [
            'receiptNo' => $sale['receipt_no'],
            'soldAt' => Support::toIso($sale['sold_at']),
            'items' => array_map(static fn ($i) => [
                'productId' => $i['product_id'] !== null ? (int) $i['product_id'] : null,
                'name' => $i['name'],
                'unitCents' => (int) $i['unit_cents'],
                'qty' => (int) $i['qty'],
            ], $items),
            'subtotalCents' => (int) $sale['subtotal_cents'],
            'discountCents' => (int) $sale['discount_cents'],
            'totalCents' => (int) $sale['total_cents'],
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
