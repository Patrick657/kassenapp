<?php
declare(strict_types=1);

namespace Festkasse;

use Festkasse\Repo\AuditRepo;
use Festkasse\Repo\CashRepo;
use Festkasse\Repo\CategoryRepo;
use Festkasse\Repo\JournalRepo;
use Festkasse\Repo\MaintenanceRepo;
use Festkasse\Repo\ProductRepo;
use Festkasse\Repo\ReportsRepo;
use Festkasse\Repo\SaleRepo;
use Festkasse\Repo\SettingsRepo;
use Festkasse\Repo\UserRepo;
use Festkasse\Repo\ZReportRepo;
use PDO;

final class Api
{
    private Auth $auth;
    private SettingsRepo $settings;
    private ProductRepo $products;
    private CategoryRepo $categories;
    private CashRepo $cash;
    private SaleRepo $sales;
    private ZReportRepo $zReports;
    private JournalRepo $journal;
    private ReportsRepo $reports;
    private AuditRepo $audit;
    private MaintenanceRepo $maintenance;
    private UserRepo $users;

    public function __construct(private readonly PDO $db, bool $https)
    {
        $this->auth = new Auth($db, $https);
        $this->settings = new SettingsRepo($db);
        $this->products = new ProductRepo($db);
        $this->categories = new CategoryRepo($db);
        $this->cash = new CashRepo($db);
        $this->sales = new SaleRepo($db, $this->products, $this->cash);
        $this->zReports = new ZReportRepo($db, $this->cash);
        $this->journal = new JournalRepo($db);
        $this->reports = new ReportsRepo($db);
        $this->audit = new AuditRepo($db);
        $this->maintenance = new MaintenanceRepo($db);
        $this->users = new UserRepo($db);
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

        if ($method === 'POST' && $path === '/api/access/forgot') {
            $this->auth->requestReset();
            return ['ok' => true];
        }

        if ($method === 'POST' && $path === '/api/access/reset') {
            $b = Support::jsonBody();
            $deleteCode = !empty($b['deleteCode']) ? (string) $b['deleteCode'] : null;
            return $this->auth->consumeReset((string) ($b['token'] ?? ''), (string) ($b['accessCode'] ?? ''), $deleteCode);
        }

        if ($method === 'POST' && $path === '/api/sales') {
            return $this->createSale();
        }

        if ($method === 'POST' && $path === '/api/pos/login') {
            $b = Support::jsonBody();
            return $this->auth->posLogin((int) ($b['userId'] ?? 0), (string) ($b['pin'] ?? ''));
        }

        if ($method === 'POST' && $path === '/api/pos/logout') {
            $this->auth->posLogout();
            return ['ok' => true];
        }

        if ($path === '/api/users') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return $this->users->listAll();
            }
            if ($method === 'POST') {
                $b = Support::jsonBody();
                $id = $this->users->create(
                    (string) ($b['name'] ?? ''),
                    (string) ($b['pin'] ?? ''),
                    (string) ($b['role'] ?? 'cashier'),
                    (array) ($b['categoryIds'] ?? [])
                );
                $this->audit->log('user.create', 'Benutzer angelegt: ' . (string) ($b['name'] ?? ''));
                return ['id' => $id];
            }
        }

        if (preg_match('#^/api/users/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            $this->auth->requireAccess();
            if ($method === 'PATCH') {
                return $this->updateUser($id);
            }
            if ($method === 'DELETE') {
                $b = Support::jsonBody();
                $this->auth->verifyDeleteCode($b['deleteCode'] ?? null);
                $user = $this->users->find($id);
                if ($user === null) {
                    throw new ApiException(404, 'Benutzer nicht gefunden');
                }
                $this->users->delete($id);
                $this->audit->log('user.delete', 'Benutzer gelöscht: ' . $user['name']);
                return ['ok' => true];
            }
        }

        if ($method === 'GET' && preg_match('#^/api/receipts/([A-Za-z0-9\-]+)$#', $path, $m)) {
            return $this->sales->findByReceiptNo($m[1]);
        }

        if ($method === 'GET' && $path === '/api/admin/products') {
            $this->auth->requireAccess();
            return $this->adminProducts();
        }

        if ($method === 'GET' && $path === '/api/products/export') {
            $this->auth->requireAccess();
            $this->exportProductsCsv();
        }

        if ($method === 'POST' && $path === '/api/products/import') {
            $this->auth->requireAccess();
            return $this->importProductsCsv();
        }

        if ($path === '/api/categories') {
            if ($method === 'GET') {
                $this->auth->requireAccess();
                return $this->categories->list();
            }
            if ($method === 'POST') {
                $this->auth->requireAccess();
                $b = Support::jsonBody();
                $id = $this->categories->create((string) ($b['name'] ?? ''), (bool) ($b['trackStockDefault'] ?? true));
                return ['id' => $id];
            }
        }

        if (preg_match('#^/api/categories/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'PATCH') {
                $this->auth->requireAccess();
                $b = Support::jsonBody();
                if (array_key_exists('name', $b)) {
                    $this->categories->rename($id, (string) $b['name']);
                }
                if (array_key_exists('trackStockDefault', $b)) {
                    $this->categories->setTrackStockDefault($id, (bool) $b['trackStockDefault']);
                }
                return ['ok' => true];
            }
            if ($method === 'DELETE') {
                $this->auth->requireAccess();
                $this->categories->delete($id);
                return ['ok' => true];
            }
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

        if ($path === '/api/settings/codes') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return ['recoveryEmail' => $this->settings->recoveryEmail()];
            }
            if ($method === 'PATCH') {
                return $this->updateCodes();
            }
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
        $allowedCategories = $this->auth->posAllowedCategories(); // null = unrestricted (admin or feature unused)
        $active = $this->products->listActive();
        if ($allowedCategories !== null) {
            $active = array_values(array_filter($active, static fn ($p) => in_array($p['category'], $allowedCategories, true)));
        }
        $products = array_map(static fn ($p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'category' => $p['category'],
            'priceCents' => (int) $p['price_cents'],
            'stock' => (int) $p['stock'],
            'stockMin' => (int) $p['stock_min'],
            'trackStock' => (bool) $p['track_stock'],
        ], $active);

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
            'posUsers' => $this->auth->posUsers(),
            'posUser' => $this->auth->posCurrentUser(),
        ];
    }

    private function updateUser(int $id): array
    {
        $b = Support::jsonBody();
        $user = $this->users->find($id);
        if ($user === null) {
            throw new ApiException(404, 'Benutzer nicht gefunden');
        }
        $this->users->update(
            $id,
            (string) ($b['name'] ?? $user['name']),
            (string) ($b['role'] ?? $user['role']),
            array_key_exists('active', $b) ? (bool) $b['active'] : (bool) $user['active'],
            !empty($b['pin']) ? (string) $b['pin'] : null,
            array_key_exists('categoryIds', $b) ? (array) $b['categoryIds'] : $this->users->categoryIdsFor($id)
        );
        $this->audit->log('user.update', 'Benutzer geändert: ' . (string) ($b['name'] ?? $user['name']));
        return ['ok' => true];
    }

    private function adminProducts(): array
    {
        return array_map(static fn ($p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'sku' => $p['sku'] ?? null,
            'category' => $p['category'],
            'priceCents' => (int) $p['price_cents'],
            'costCents' => (int) $p['cost_cents'],
            'stock' => (int) $p['stock'],
            'stockMin' => (int) $p['stock_min'],
            'trackStock' => (bool) $p['track_stock'],
            'soldQty' => (int) $p['sold_qty'],
            'soldRevenueCents' => (int) $p['sold_revenue_cents'],
        ], $this->products->listActive());
    }

    private const CSV_HEADER = ['Artikelnummer', 'Name', 'Gruppe', 'Verkaufspreis', 'Einkaufspreis', 'Lagerbestand_fuehren', 'Bestand', 'Meldebestand'];

    private function exportProductsCsv(): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="artikel-' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel picks the right encoding instead of guessing Latin-1
        $out = fopen('php://output', 'w');
        fputcsv($out, self::CSV_HEADER, ';');
        foreach ($this->products->listActive() as $p) {
            fputcsv($out, [
                $p['sku'] ?? '',
                $p['name'],
                $p['category'],
                number_format(((int) $p['price_cents']) / 100, 2, ',', ''),
                number_format(((int) $p['cost_cents']) / 100, 2, ',', ''),
                ((bool) $p['track_stock']) ? 'ja' : 'nein',
                (string) (int) $p['stock'],
                (string) (int) $p['stock_min'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    /**
     * Upserts articles from a semicolon- or comma-delimited CSV (same columns as the export).
     * Matches existing articles by Artikelnummer first, then falls back to an exact name match;
     * anything else becomes a new article. Unknown groups are created on the fly (using the row's
     * own Lagerbestand_fuehren value as that new group's default) rather than failing the row —
     * partial success beats an all-or-nothing import when someone's editing a spreadsheet by hand.
     */
    private function importProductsCsv(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($raw)), static fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw new ApiException(400, 'Keine Datenzeilen in der Datei gefunden');
        }
        $header = array_shift($lines);
        $delimiter = substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';

        $existing = $this->products->listActive();
        $bySku = [];
        $byName = [];
        foreach ($existing as $p) {
            if (!empty($p['sku'])) {
                $bySku[$p['sku']] = $p;
            }
            $byName[mb_strtolower($p['name'])] = $p;
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($lines as $i => $line) {
            $rowNum = $i + 2; // header is row 1
            $cols = array_pad(str_getcsv($line, $delimiter), 8, '');
            [$sku, $name, $groupName, $priceRaw, $costRaw, $trackRaw, $stockRaw, $stockMinRaw] = $cols;

            $sku = trim($sku);
            $name = trim($name);
            $groupName = trim($groupName);
            $priceCents = Support::parseAmountToCents($priceRaw);

            if ($name === '') {
                $errors[] = ['row' => $rowNum, 'message' => 'Name fehlt'];
                continue;
            }
            if ($priceCents <= 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'Verkaufspreis fehlt oder ungültig'];
                continue;
            }
            if ($groupName === '') {
                $errors[] = ['row' => $rowNum, 'message' => 'Artikelgruppe fehlt'];
                continue;
            }

            $trackRawTrim = mb_strtolower(trim($trackRaw));
            $trackStock = $trackRawTrim === '' || in_array($trackRawTrim, ['ja', 'yes', 'true', '1'], true);

            $category = $this->categories->findByName($groupName);
            if ($category === null) {
                try {
                    $this->categories->create($groupName, $trackStock);
                } catch (ApiException $e) {
                    // race with another row of the same import naming the same new group — fine, re-fetch
                }
                $category = $this->categories->findByName($groupName);
            }

            $costCents = Support::parseAmountToCents($costRaw);
            $stock = (int) round(Support::parseGermanDecimal($stockRaw));
            $stockMin = $stockRaw === '' ? 10 : (int) round(Support::parseGermanDecimal($stockMinRaw));

            $match = ($sku !== '' && isset($bySku[$sku])) ? $bySku[$sku] : ($byName[mb_strtolower($name)] ?? null);

            if ($match) {
                $this->products->update(
                    (int) $match['id'],
                    $name,
                    $category['name'],
                    $priceCents,
                    $costCents,
                    $stock,
                    $stockMin,
                    $trackStock,
                    $sku !== '' ? $sku : null
                );
                $updated++;
            } else {
                $this->products->create($name, $category['name'], $priceCents, $costCents, $stock, $stockMin, $trackStock, $sku !== '' ? $sku : null);
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /** Category must already exist as a managed group — no more free-text categories from the article form. */
    private function resolveCategory(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'Artikelgruppe fehlt');
        }
        $category = $this->categories->findByName($name);
        if ($category === null) {
            throw new ApiException(400, 'Unbekannte Artikelgruppe · bitte zuerst unter Artikelgruppen anlegen');
        }
        return $category;
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
        $category = $this->resolveCategory((string) ($b['category'] ?? ''));
        $trackStock = array_key_exists('trackStock', $b) ? (bool) $b['trackStock'] : (bool) $category['track_stock_default'];
        $id = $this->products->create(
            $name,
            $category['name'],
            $price,
            (int) ($b['costCents'] ?? 0),
            (int) ($b['stock'] ?? 0),
            (int) ($b['stockMin'] ?? 10),
            $trackStock,
            self::normalizeSku($b['sku'] ?? null)
        );
        return ['id' => $id];
    }

    private static function normalizeSku(mixed $sku): ?string
    {
        $sku = trim((string) $sku);
        return $sku === '' ? null : $sku;
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
        $category = $this->resolveCategory((string) ($b['category'] ?? ''));
        $this->products->update(
            $id,
            $name,
            $category['name'],
            $price,
            (int) ($b['costCents'] ?? 0),
            array_key_exists('stock', $b) ? (int) $b['stock'] : null,
            array_key_exists('stockMin', $b) ? (int) $b['stockMin'] : null,
            array_key_exists('trackStock', $b) ? (bool) $b['trackStock'] : null,
            self::normalizeSku($b['sku'] ?? null)
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
        $allowedCategories = $this->auth->posAllowedCategories();
        if ($allowedCategories !== null) {
            foreach ($items as $item) {
                if ($item['productId'] === null) {
                    continue;
                }
                $product = $this->products->find($item['productId']);
                if ($product === null || !in_array($product['category'], $allowedCategories, true)) {
                    throw new ApiException(403, 'Artikel außerhalb des Benutzerbereichs · bitte Benutzer wechseln');
                }
            }
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
        $hasRecoveryEmail = array_key_exists('recoveryEmail', $b);
        if ($accessCode === null && $deleteCode === null && !$hasRecoveryEmail) {
            throw new ApiException(400, 'Kein Code angegeben');
        }
        if ($hasRecoveryEmail) {
            $recoveryEmail = trim((string) $b['recoveryEmail']);
            if ($recoveryEmail !== '' && !filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException(400, 'Ungültige E-Mail-Adresse');
            }
            $this->settings->setRecoveryEmail($recoveryEmail === '' ? null : $recoveryEmail);
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
        if ($hasRecoveryEmail) {
            $changed[] = 'Wiederherstellungs-E-Mail';
        }
        if (empty($changed)) {
            return ['ok' => true];
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
