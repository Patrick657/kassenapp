<?php
declare(strict_types=1);

namespace Festkasse;

use Festkasse\Repo\AuditRepo;
use Festkasse\Repo\CashRepo;
use Festkasse\Repo\JournalRepo;
use Festkasse\Repo\MaintenanceRepo;
use Festkasse\Repo\ProductRepo;
use Festkasse\Repo\ReportsRepo;
use Festkasse\Repo\SaleRepo;
use Festkasse\Repo\SettingsRepo;
use Festkasse\Repo\ZReportRepo;
use PDO;

final class Api
{
    private Auth $auth;
    private SettingsRepo $settings;
    private ProductRepo $products;
    private CashRepo $cash;
    private SaleRepo $sales;
    private ZReportRepo $zReports;
    private JournalRepo $journal;
    private ReportsRepo $reports;
    private AuditRepo $audit;
    private MaintenanceRepo $maintenance;

    public function __construct(private readonly PDO $db, bool $https)
    {
        $this->auth = new Auth($db, $https);
        $this->settings = new SettingsRepo($db);
        $this->products = new ProductRepo($db);
        $this->cash = new CashRepo($db);
        $this->sales = new SaleRepo($db, $this->products, $this->cash);
        $this->zReports = new ZReportRepo($db, $this->cash);
        $this->journal = new JournalRepo($db);
        $this->reports = new ReportsRepo($db);
        $this->audit = new AuditRepo($db);
        $this->maintenance = new MaintenanceRepo($db);
    }

    public function handle(string $method, string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        try {
            $result = $this->route($method, $path);
            Support::sendJson($result ?? ['ok' => true]);
        } catch (ApiException $e) {
            Support::sendJson(['error' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            error_log('[Festkasse API] ' . $e->getMessage());
            Support::sendJson(['error' => 'Interner Fehler'], 500);
        }
    }

    private function route(string $method, string $path): mixed
    {
        if ($method === 'GET' && $path === '/api/bootstrap') {
            return $this->bootstrap();
        }

        if ($path === '/api/access') {
            if ($method === 'GET') {
                return $this->auth->status();
            }
            if ($method === 'POST') {
                $body = Support::jsonBody();
                return $this->auth->attempt((string) ($body['code'] ?? ''));
            }
            if ($method === 'DELETE') {
                $this->auth->revoke();
                return ['ok' => true];
            }
        }

        if ($method === 'POST' && $path === '/api/sales') {
            return $this->createSale();
        }

        if ($method === 'GET' && preg_match('#^/api/receipts/([A-Za-z0-9\-]+)$#', $path, $m)) {
            return $this->sales->findByReceiptNo($m[1]);
        }

        if ($method === 'GET' && $path === '/api/admin/products') {
            $this->auth->requireAccess();
            return $this->adminProducts();
        }

        if ($method === 'POST' && $path === '/api/products') {
            $this->auth->requireAccess();
            return $this->createProduct();
        }

        if (preg_match('#^/api/products/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'PATCH') {
                $this->auth->requireAccess();
                return $this->updateProduct($id);
            }
            if ($method === 'DELETE') {
                $this->auth->requireAccess();
                return $this->deleteProduct($id);
            }
        }

        if ($method === 'POST' && $path === '/api/deliveries') {
            $this->auth->requireAccess();
            return $this->createDelivery();
        }

        if ($method === 'POST' && $path === '/api/cash-movements') {
            $this->auth->requireAccess();
            return $this->createCashMovement();
        }

        if ($method === 'POST' && $path === '/api/z-reports') {
            $this->auth->requireAccess();
            return $this->zReports->close();
        }

        if ($method === 'GET' && $path === '/api/z-reports') {
            $this->auth->requireAccess();
            return $this->zReports->list();
        }

        if ($method === 'GET' && $path === '/api/cash/status') {
            $this->auth->requireAccess();
            $sinceAt = $this->cash->lastCloseOccurredAt();
            return [
                'balanceCents' => $this->cash->balanceCents(),
                'cashSinceCloseCents' => $this->cash->cashRevenueSince($sinceAt),
            ];
        }

        if ($method === 'GET' && $path === '/api/maintenance/status') {
            $this->auth->requireAccess();
            return $this->maintenance->counts();
        }

        if ($method === 'GET' && preg_match('#^/api/reports/(kpis|daily|hourly|products|payments|groups)$#', $path, $m)) {
            $this->auth->requireAccess();
            return $this->report($m[1]);
        }

        if ($method === 'GET' && $path === '/api/journal') {
            $this->auth->requireAccess();
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 80;
            $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
            return $this->journal->page($limit, $before);
        }

        if ($method === 'PATCH' && $path === '/api/settings') {
            $this->auth->requireAccess();
            $this->settings->update(Support::jsonBody());
            return $this->settings->forClient();
        }

        if ($method === 'PATCH' && $path === '/api/settings/codes') {
            $this->auth->requireAccess();
            return $this->updateCodes();
        }

        if ($method === 'POST' && $path === '/api/maintenance/purge') {
            $this->auth->requireAccess();
            return $this->purge();
        }

        if ($method === 'POST' && $path === '/api/maintenance/reset') {
            $this->auth->requireAccess();
            return $this->reset();
        }

        throw new ApiException(404, 'Unbekannter Endpunkt');
    }

    private function bootstrap(): array
    {
        $settings = $this->settings->forClient();
        $products = array_map(static fn ($p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'category' => $p['category'],
            'priceCents' => (int) $p['price_cents'],
            'stock' => (int) $p['stock'],
            'stockMin' => (int) $p['stock_min'],
        ], $this->products->listActive());

        $today = (int) $this->db->query(
            "SELECT COALESCE(SUM(total_cents), 0) FROM sales WHERE voided_at IS NULL AND DATE(sold_at) = CURDATE()"
        )->fetchColumn();

        return [
            'shopName' => $settings['shopName'],
            'settings' => $settings,
            'products' => $products,
            'cashBalanceCents' => $this->cash->balanceCents(),
            'todayRevenueCents' => $today,
            'access' => $this->auth->status(),
        ];
    }

    private function adminProducts(): array
    {
        return array_map(static fn ($p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'category' => $p['category'],
            'priceCents' => (int) $p['price_cents'],
            'costCents' => (int) $p['cost_cents'],
            'stock' => (int) $p['stock'],
            'stockMin' => (int) $p['stock_min'],
            'soldQty' => (int) $p['sold_qty'],
            'soldRevenueCents' => (int) $p['sold_revenue_cents'],
        ], $this->products->listActive());
    }

    private function createProduct(): array
    {
        $b = Support::jsonBody();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        $price = (int) ($b['priceCents'] ?? 0);
        if ($price <= 0) {
            throw new ApiException(400, 'Verkaufspreis fehlt');
        }
        $id = $this->products->create(
            $name,
            trim((string) ($b['category'] ?? '')) ?: 'Speisen',
            $price,
            (int) ($b['costCents'] ?? 0),
            (int) ($b['stock'] ?? 0),
            (int) ($b['stockMin'] ?? 10)
        );
        return ['id' => $id];
    }

    private function updateProduct(int $id): array
    {
        $b = Support::jsonBody();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new ApiException(400, 'Name fehlt');
        }
        $price = (int) ($b['priceCents'] ?? 0);
        if ($price <= 0) {
            throw new ApiException(400, 'Verkaufspreis fehlt');
        }
        $this->products->update(
            $id,
            $name,
            trim((string) ($b['category'] ?? '')) ?: 'Speisen',
            $price,
            (int) ($b['costCents'] ?? 0),
            array_key_exists('stock', $b) ? (int) $b['stock'] : null,
            array_key_exists('stockMin', $b) ? (int) $b['stockMin'] : null
        );
        return ['ok' => true];
    }

    private function deleteProduct(int $id): array
    {
        $b = Support::jsonBody();
        $this->auth->verifyDeleteCode($b['deleteCode'] ?? null);
        $product = $this->products->find($id);
        if ($product === null) {
            throw new ApiException(404, 'Artikel nicht gefunden');
        }
        $this->products->archive($id);
        $this->audit->log('product.delete', 'Artikel gelöscht: ' . $product['name']);
        return ['ok' => true];
    }

    private function createDelivery(): array
    {
        $b = Support::jsonBody();
        $productId = (int) ($b['productId'] ?? 0);
        $qty = (int) ($b['qty'] ?? 0);
        if ($qty <= 0) {
            throw new ApiException(400, 'Menge muss größer als 0 sein');
        }
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new ApiException(404, 'Artikel nicht gefunden');
        }
        $this->products->addStock($productId, $qty);
        $note = 'Warenzugang ' . $qty . '× ' . $product['name'] . (!empty($b['note']) ? ' · ' . $b['note'] : '');
        $this->cash->insert('delivery', 0, $note, null, $productId, $qty);
        return ['ok' => true];
    }

    private function createCashMovement(): array
    {
        $b = Support::jsonBody();
        $type = (string) ($b['type'] ?? '');
        if (!in_array($type, ['in', 'out'], true)) {
            throw new ApiException(400, 'Ungültiger Bewegungstyp');
        }
        $amount = (int) ($b['amountCents'] ?? 0);
        if ($amount <= 0) {
            throw new ApiException(400, 'Betrag muss größer als 0 sein');
        }
        if ($type === 'out' && $amount > $this->cash->balanceCents()) {
            throw new ApiException(400, 'Mehr als der Kassenbestand');
        }
        $note = trim((string) ($b['note'] ?? '')) ?: ($type === 'in' ? 'Einlage' : 'Entnahme');
        $this->cash->insert($type, $type === 'out' ? -$amount : $amount, $note);
        return ['ok' => true];
    }

    private function createSale(): array
    {
        $b = Support::jsonBody();
        $clientUuid = isset($b['clientUuid']) ? (string) $b['clientUuid'] : null;
        if ($clientUuid) {
            $existing = $this->sales->findByClientUuid($clientUuid);
            if ($existing !== null) {
                return $existing;
            }
        }
        $items = [];
        foreach ((array) ($b['items'] ?? []) as $item) {
            $items[] = [
                'productId' => isset($item['productId']) ? (int) $item['productId'] : null,
                'name' => (string) ($item['name'] ?? ''),
                'unitCents' => (int) ($item['unitCents'] ?? 0),
                'qty' => (int) ($item['qty'] ?? 0),
            ];
        }
        $trackStock = $this->settings->bool('track_stock', true);
        return $this->sales->create(
            $items,
            (int) ($b['discountCents'] ?? 0),
            (string) ($b['payment'] ?? 'cash'),
            (int) ($b['givenCents'] ?? 0),
            $trackStock,
            $clientUuid
        );
    }

    private function report(string $kind): array
    {
        return match ($kind) {
            'kpis' => $this->reports->kpis($this->settings->bool('track_stock', true)),
            'daily' => $this->reports->daily(),
            'hourly' => $this->reports->hourly(),
            'products' => $this->reports->productsRanking(7),
            'payments' => $this->reports->payments(),
            'groups' => $this->reports->groups($this->settings->bool('track_stock', true)),
            default => throw new ApiException(404, 'Unbekannter Report'),
        };
    }

    private function updateCodes(): array
    {
        $b = Support::jsonBody();
        $accessCode = !empty($b['accessCode']) ? (string) $b['accessCode'] : null;
        $deleteCode = !empty($b['deleteCode']) ? (string) $b['deleteCode'] : null;
        if ($accessCode === null && $deleteCode === null) {
            throw new ApiException(400, 'Kein Code angegeben');
        }
        if ($accessCode !== null && $deleteCode !== null && $accessCode === $deleteCode) {
            throw new ApiException(400, 'Zugangscode und Löschkennwort müssen unterschiedlich sein');
        }
        $raw = $this->settings->raw();
        $currentAccessHash = $raw['access_code_hash'] ?? '';
        $currentDeleteHash = $raw['delete_code_hash'] ?? '';
        if ($accessCode !== null && $currentDeleteHash !== '' && password_verify($accessCode, $currentDeleteHash)) {
            throw new ApiException(400, 'Zugangscode darf nicht dem Löschkennwort entsprechen');
        }
        if ($deleteCode !== null && $currentAccessHash !== '' && password_verify($deleteCode, $currentAccessHash)) {
            throw new ApiException(400, 'Löschkennwort darf nicht dem Zugangscode entsprechen');
        }

        $changed = [];
        if ($accessCode !== null) {
            $this->settings->setCodeHash('access_code_hash', password_hash($accessCode, PASSWORD_DEFAULT));
            $changed[] = 'Zugangscode';
        }
        if ($deleteCode !== null) {
            $this->settings->setCodeHash('delete_code_hash', password_hash($deleteCode, PASSWORD_DEFAULT));
            $changed[] = 'Löschkennwort';
        }
        $this->audit->log('settings.codes', implode(' + ', $changed) . ' geändert');
        return ['ok' => true];
    }

    private function purge(): array
    {
        $b = Support::jsonBody();
        $this->auth->verifyDeleteCode($b['deleteCode'] ?? null);
        $counts = $this->maintenance->counts();
        $this->maintenance->purgeSalesAndJournal();
        $this->audit->log('data.purge', $counts['sales'] . ' Bons, ' . $counts['movements'] . ' Bewegungen gelöscht');
        return ['ok' => true];
    }

    private function reset(): array
    {
        $b = Support::jsonBody();
        $this->auth->verifyDeleteCode($b['deleteCode'] ?? null);
        $this->maintenance->resetToDemo();
        $this->audit->log('data.reset', 'Alles zurückgesetzt (Demo-Daten)');
        return ['ok' => true];
    }
}
