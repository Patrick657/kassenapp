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
     * @param ?array $compAccount resolved comp_accounts row — required for (and only used with)
     *        payment 'comp', an "ohne Berechnung" booking: the goods are recorded with their
     *        normal value and leave stock, but nothing is collected, no Pfand is charged and the
     *        drawer isn't touched. Every revenue figure excludes these rows (payment <> 'comp').
     * @param int $registerId the Kasse this checkout belongs to — its drawer, journal and Z-Bericht
     * @param ?array $invoiceAccount resolved invoice_accounts row — required for (and only used with)
     *        payment 'invoice', "auf Rechnung": counts as Umsatz like a card payment (nothing enters
     *        the drawer), but is collected later by an invoice to that Firma. $invoiceRef is an
     *        optional Vermerk for that invoice (e.g. who ordered).
     */
    public function create(int $registerId, array $items, int $discountCents, string $payment, int $givenCents, bool $trackStock, ?string $clientUuid, array $returnLines = [], ?array $compAccount = null, ?array $invoiceAccount = null, string $invoiceRef = ''): array
    {
        if (empty($items) && empty($returnLines)) {
            throw new ApiException(400, 'Warenkorb ist leer');
        }
        if (!in_array($payment, ['cash', 'card', 'comp', 'invoice'], true)) {
            throw new ApiException(400, 'Ungültige Zahlart');
        }
        $isInvoice = $payment === 'invoice';
        // "Auf Rechnung" may also carry Pfand-Rückgaben (or consist only of them): those are
        // credited on the Firma's invoice, not paid out from the drawer.
        if ($isInvoice && $invoiceAccount === null) {
            throw new ApiException(400, 'Bitte eine Firma für die Buchung auf Rechnung wählen');
        }
        $invoiceRef = trim($invoiceRef);
        if (mb_strlen($invoiceRef) > 120) {
            throw new ApiException(400, 'Vermerk ist zu lang (max. 120 Zeichen)');
        }
        $isComp = $payment === 'comp';
        if ($isComp) {
            if ($compAccount === null) {
                throw new ApiException(400, 'Bitte einen Bereich für die Buchung ohne Berechnung wählen');
            }
            if (empty($items)) {
                throw new ApiException(400, 'Warenkorb ist leer');
            }
            // A Pfand payout is real cash leaving the drawer — it can't ride along on a booking
            // whose whole point is that no money moves.
            if (!empty($returnLines)) {
                throw new ApiException(400, 'Pfand-Rückgabe bitte separat buchen, nicht zusammen mit „Ohne Berechnung“');
            }
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
                    $depositCents = $isComp ? 0 : (int) $product['deposit_cents'];
                    $name = $name !== '' ? $name : (string) $product['name'];
                    $productTracksStock = (bool) $product['track_stock'];
                }
            }
            $subtotal += $unitCents * $qty;
            $depositTotal += $depositCents * $qty;
            $resolved[] = ['productId' => $productId, 'name' => $name, 'unitCents' => $unitCents, 'costCents' => $costCents, 'depositCents' => $depositCents, 'qty' => $qty, 'trackStock' => $productTracksStock];
        }

        // Pfand is never discountable — it's a refundable liability, not part of the goods price.
        $discount = $isComp ? 0 : max(0, min($discountCents, $subtotal));
        $grossTotal = $subtotal - $discount + $depositTotal;

        // A same-checkout Pfand-Rückgabe only offsets what the customer hands over when paying
        // cash — a card charge still needs the full gross amount, since a card payment and a cash
        // refund are two different tenders that can't net against each other. That's also the only
        // way "net" can go negative: a card sale's given/change stay exactly as before, unaffected.
        if ($isComp) {
            // total_cents keeps the goods value (what it would have cost) for the Auswertung.
            $given = 0;
            $change = 0;
        } elseif ($payment === 'cash') {
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
        } elseif ($isInvoice) {
            // What the Firma owes for this Bon, Rückgaben already deducted (may be a credit).
            $given = max(0, $grossTotal - $returnedCents);
            $change = 0;
        } else {
            $given = $grossTotal;
            $change = 0;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO sales (client_uuid, receipt_no, sold_at, subtotal_cents, discount_cents, deposit_cents, deposit_returned_cents, total_cents, payment, register_id, comp_account_id, comp_name, invoice_account_id, invoice_code, invoice_name, invoice_ref, given_cents, change_cents)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // receipt_no is finalized below once we have the id; store a temporary unique placeholder first.
            $placeholder = 'TMP-' . bin2hex(random_bytes(8));
            $stmt->execute([$clientUuid, $placeholder, $subtotal, $discount, $depositTotal, $returnedCents, $grossTotal, $payment, $registerId, $isComp ? (int) $compAccount['id'] : null, $isComp ? (string) $compAccount['name'] : null,
                $isInvoice ? (int) $invoiceAccount['id'] : null, $isInvoice ? (string) $invoiceAccount['code'] : null, $isInvoice ? (string) $invoiceAccount['name'] : null,
                $isInvoice && $invoiceRef !== '' ? $invoiceRef : null, $given, $change]);
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
                if ($isComp) {
                    $note = count($resolved) . ' Position(en) · Ohne Berechnung · ' . $compAccount['name'];
                    $this->cash->insert($registerId, 'comp', 0, $note, $saleId);
                } else {
                    $note = count($resolved) . ' Position(en) · ' . match ($payment) {
                        'cash' => 'Bar',
                        'invoice' => 'Auf Rechnung · ' . $invoiceAccount['code'] . ($invoiceRef !== '' ? ' · ' . $invoiceRef : ''),
                        default => 'Karte',
                    };
                    $this->cash->insert($registerId, 'sale', $payment === 'cash' ? $grossTotal : 0, $note, $saleId);
                }
            }

            foreach ($returnLines as $r) {
                $amount = (int) $r['depositType']['amount_cents'] * (int) $r['qty'];
                $note = 'Pfandrückgabe ' . $r['qty'] . '× ' . $r['depositType']['name'];
                if ($isInvoice) {
                    $note .= ' · Gutschrift auf Rechnung · ' . $invoiceAccount['code'];
                    $this->cash->insert($registerId, 'deposit_return', 0, $note, $saleId, null, (int) $r['qty'], (int) $r['depositType']['id'], $amount);
                    continue;
                }
                if ($amount > $this->cash->balanceCents($registerId)) {
                    throw new ApiException(400, 'Mehr als der Kassenbestand');
                }
                $this->cash->insert($registerId, 'deposit_return', -$amount, $note, $saleId, null, (int) $r['qty'], (int) $r['depositType']['id']);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->receipt($saleId);
    }

    /**
     * Storno of a whole Bon, booked back into the Kasse the Bon belongs to (not the device's).
     * Only Bons of that Kasse's still-open day can be voided: a Bon that's already
     * in a Z-Bericht is part of a closed, emailed report and its cash has left the drawer, so
     * voiding it would silently change that report after the fact.
     *
     * Nothing is deleted: the sales row gets voided_at + reason (every report already filters on
     * voided_at), stock comes back, and one cash_movements row of type 'void' reverses exactly
     * what this Bon moved in the drawer (cash sale in, Pfand payout out; 0 for card/comp).
     */
    public function void(string $receiptNo, string $reason, bool $trackStock): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException(400, 'Bitte einen Grund für das Storno angeben');
        }
        if (mb_strlen($reason) > 255) {
            throw new ApiException(400, 'Grund ist zu lang (max. 255 Zeichen)');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM sales WHERE receipt_no = ? FOR UPDATE');
            $stmt->execute([$receiptNo]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new ApiException(404, 'Bon nicht gefunden');
            }
            if ($sale['voided_at'] !== null) {
                throw new ApiException(409, 'Bon ist bereits storniert');
            }
            if (($sale['billed_at'] ?? null) !== null) {
                throw new ApiException(409, 'Bon ist bereits auf einer Rechnung abgerechnet – erst unter „Auf Rechnung“ die Abrechnung zurücknehmen');
            }
            $registerId = (int) $sale['register_id'];
            $lastCloseAt = $this->cash->lastCloseOccurredAt($registerId);
            if ($lastCloseAt !== null && $sale['sold_at'] <= $lastCloseAt) {
                throw new ApiException(409, 'Bon ist bereits im Tagesabschluss enthalten und kann nicht mehr storniert werden');
            }

            $saleId = (int) $sale['id'];
            $movedStmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount_cents), 0) FROM cash_movements WHERE sale_id = ? AND type IN ('sale', 'deposit_return')"
            );
            $movedStmt->execute([$saleId]);
            $reversal = -(int) $movedStmt->fetchColumn();
            if ($reversal < 0 && -$reversal > $this->cash->balanceCents($registerId)) {
                throw new ApiException(400, 'Nicht genug Bargeld in der Kasse für dieses Storno');
            }

            $this->db->prepare('UPDATE sales SET voided_at = NOW(), void_reason = ? WHERE id = ?')->execute([$reason, $saleId]);

            if ($trackStock) {
                $itemStmt = $this->db->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ? AND product_id IS NOT NULL');
                $itemStmt->execute([$saleId]);
                foreach ($itemStmt->fetchAll() as $item) {
                    $product = $this->products->find((int) $item['product_id']);
                    if ($product !== null && (bool) $product['track_stock']) {
                        $this->products->addStock((int) $item['product_id'], (int) $item['qty']);
                    }
                }
            }

            $this->cash->insert($registerId, 'void', $reversal, 'Storno · ' . $reason, $saleId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->receipt($saleId);
    }

    public function receipt(int $saleId): array
    {
        $stmt = $this->db->prepare('SELECT s.*, r.name AS register_name FROM sales s LEFT JOIN registers r ON r.id = s.register_id WHERE s.id = ?');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new ApiException(404, 'Bon nicht gefunden');
        }
        $lastCloseAt = $this->cash->lastCloseOccurredAt((int) $sale['register_id']);
        $voidable = $sale['voided_at'] === null && ($lastCloseAt === null || $sale['sold_at'] > $lastCloseAt);
        $itemStmt = $this->db->prepare('SELECT product_id, name, unit_cents, deposit_cents, qty FROM sale_items WHERE sale_id = ?');
        $itemStmt->execute([$saleId]);
        $items = $itemStmt->fetchAll();

        $returnStmt = $this->db->prepare(
            "SELECT m.deposit_type_id, m.qty, COALESCE(m.credit_cents, -m.amount_cents) AS amount_cents, m.credit_cents IS NOT NULL AS credited, dt.name
             FROM cash_movements m LEFT JOIN deposit_types dt ON dt.id = m.deposit_type_id
             WHERE m.sale_id = ? AND m.type = 'deposit_return'"
        );
        $returnStmt->execute([$saleId]);
        $returns = $returnStmt->fetchAll();

        return [
            'receiptNo' => $sale['receipt_no'],
            'registerId' => (int) $sale['register_id'],
            'registerName' => $sale['register_name'],
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
                'credited' => (bool) $r['credited'],
            ], $returns),
            'subtotalCents' => (int) $sale['subtotal_cents'],
            'discountCents' => (int) $sale['discount_cents'],
            'depositCents' => (int) $sale['deposit_cents'],
            'depositReturnedCents' => (int) $sale['deposit_returned_cents'],
            'totalCents' => (int) $sale['total_cents'],
            'netCents' => (int) $sale['total_cents'] - (int) $sale['deposit_returned_cents'],
            'payment' => $sale['payment'],
            'compName' => $sale['comp_name'] ?? null,
            'invoiceCode' => $sale['invoice_code'] ?? null,
            'invoiceName' => $sale['invoice_name'] ?? null,
            'invoiceRef' => $sale['invoice_ref'] ?? null,
            'givenCents' => (int) $sale['given_cents'],
            'changeCents' => (int) $sale['change_cents'],
            'voidedAt' => $sale['voided_at'] !== null ? Support::toIso($sale['voided_at']) : null,
            'voidReason' => $sale['void_reason'] ?? null,
            'voidable' => $voidable,
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
