<?php
declare(strict_types=1);

namespace Festkasse;

use Festkasse\Repo\AuditRepo;
use Festkasse\Repo\CashRepo;
use Festkasse\Repo\CategoryRepo;
use Festkasse\Repo\CompAccountRepo;
use Festkasse\Repo\InvoiceAccountRepo;
use Festkasse\Repo\DepositTypeRepo;
use Festkasse\Repo\JournalRepo;
use Festkasse\Repo\MaintenanceRepo;
use Festkasse\Repo\ProductRepo;
use Festkasse\Repo\RegisterRepo;
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
    private DepositTypeRepo $depositTypes;
    private CompAccountRepo $compAccounts;
    private InvoiceAccountRepo $invoiceAccounts;
    private CashRepo $cash;
    private SaleRepo $sales;
    private ZReportRepo $zReports;
    private JournalRepo $journal;
    private ReportsRepo $reports;
    private AuditRepo $audit;
    private MaintenanceRepo $maintenance;
    private UserRepo $users;
    private RegisterRepo $registers;
    private Mailer $mailer;

    /** The Kasse this device is bound to (see RegisterRepo::forDevice()), null if unassigned. */
    private ?array $deviceRegister = null;

    private const REGISTER_COOKIE = 'festkasse_register';

    /** @param array{host:string,port:int,encryption:string,user:string,pass:string,fromEmail:string,fromName:string} $smtp */
    public function __construct(private readonly PDO $db, private readonly bool $https, array $smtp)
    {
        $this->auth = new Auth($db, $https);
        $this->settings = new SettingsRepo($db);
        $this->products = new ProductRepo($db);
        $this->categories = new CategoryRepo($db);
        $this->depositTypes = new DepositTypeRepo($db);
        $this->compAccounts = new CompAccountRepo($db);
        $this->invoiceAccounts = new InvoiceAccountRepo($db);
        $this->cash = new CashRepo($db);
        $this->sales = new SaleRepo($db, $this->products, $this->cash);
        $this->zReports = new ZReportRepo($db, $this->cash);
        $this->journal = new JournalRepo($db);
        $this->reports = new ReportsRepo($db);
        $this->audit = new AuditRepo($db);
        $this->maintenance = new MaintenanceRepo($db);
        $this->users = new UserRepo($db);
        $this->registers = new RegisterRepo($db);
        $this->mailer = Mailer::fromConfig($smtp);
    }

    public function handle(string $method, string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        try {
            // Who booked it, for the Journal. Best-effort: a lookup failure must never block a sale.
            try {
                $this->cash->setActor($this->auth->posCurrentUser());
            } catch (\Throwable $e) {
                error_log('[Festkasse API] Benutzer für Journal nicht ermittelbar: ' . $e->getMessage());
            }
            try {
                $cookie = $_COOKIE[self::REGISTER_COOKIE] ?? null;
                $this->deviceRegister = $this->registers->forDevice(ctype_digit((string) $cookie) ? (int) $cookie : null);
            } catch (\Throwable $e) {
                error_log('[Festkasse API] Kasse des Geräts nicht ermittelbar: ' . $e->getMessage());
            }
            $result = $this->route($method, $path);
            Support::sendJson($result ?? ['ok' => true]);
        } catch (ApiException $e) {
            Support::sendJson(['error' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            error_log('[Festkasse API] ' . $e->getMessage());
            Support::sendJson(['error' => 'Interner Fehler'], 500);
        }
    }

    /** The device's Kasse, required for anything booked at the POS itself. */
    private function requireDeviceRegister(): array
    {
        if ($this->deviceRegister === null) {
            throw new ApiException(409, 'Dieses Gerät ist keiner Kasse zugeordnet · Admin: Verwaltung → Kassen');
        }
        return $this->deviceRegister;
    }

    /**
     * Kasse picked in Verwaltung (Journal, Kasse, Abschlüsse): the given id, else this device's
     * Kasse, else the first one — Verwaltung must keep working on a not-yet-assigned device.
     */
    private function registerFrom(mixed $raw): array
    {
        if ($raw !== null && $raw !== '' && $raw !== 'all') {
            $register = $this->registers->find((int) $raw);
            if ($register === null) {
                throw new ApiException(404, 'Kasse nicht gefunden');
            }
            return $register;
        }
        $register = $this->deviceRegister ?? $this->registers->first();
        if ($register === null) {
            throw new ApiException(500, 'Keine Kasse angelegt');
        }
        return $register;
    }

    /** Report filter: null = Gesamt (all Kassen), otherwise one Kasse's id. */
    private function reportRegisterId(): ?int
    {
        $raw = $_GET['registerId'] ?? null;
        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }
        return $this->registerFrom($raw)['id'];
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

        if ($path === '/api/registers') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return ['registers' => $this->registers->list(), 'deviceRegisterId' => $this->deviceRegister['id'] ?? null];
            }
            if ($method === 'POST') {
                $b = Support::jsonBody();
                $id = $this->registers->create((string) ($b['name'] ?? ''), (array) ($b['categoryIds'] ?? []));
                $this->audit->log('register.create', 'Kasse angelegt: ' . (string) ($b['name'] ?? ''));
                return ['id' => $id];
            }
        }

        if (preg_match('#^/api/registers/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            $this->auth->requireAccess();
            if ($method === 'PATCH') {
                $b = Support::jsonBody();
                $this->registers->update($id, (string) ($b['name'] ?? ''), (array) ($b['categoryIds'] ?? []));
                return ['ok' => true];
            }
            if ($method === 'DELETE') {
                $register = $this->registers->find($id);
                $this->registers->delete($id);
                $this->audit->log('register.delete', 'Kasse gelöscht: ' . ($register['name'] ?? $id));
                return ['ok' => true];
            }
        }

        // Binds this device to a Kasse — only an admin may, so a cashier can't move their bookings
        // into another stand's drawer. httponly: page scripts can't change it either.
        if ($method === 'POST' && $path === '/api/device/register') {
            $this->auth->requireAccess();
            $b = Support::jsonBody();
            $register = $this->registers->find((int) ($b['registerId'] ?? 0));
            if ($register === null) {
                throw new ApiException(404, 'Kasse nicht gefunden');
            }
            setcookie(self::REGISTER_COOKIE, (string) $register['id'], [
                'expires' => time() + 60 * 60 * 24 * 365 * 5,
                'path' => '/',
                'httponly' => true,
                'secure' => $this->https,
                'samesite' => 'Strict',
            ]);
            $this->audit->log('device.register', 'Gerät zugeordnet: ' . $register['name']);
            return $register;
        }

        if ($path === '/api/users') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return $this->users->listAll();
            }
            if ($method === 'POST') {
                $b = Support::jsonBody();
                $role = (string) ($b['role'] ?? 'cashier');
                // Creating the very first admin account closes the bootstrap access-code door
                // behind it (see Auth::assertVerwaltungAllowed()) — auto-login this device as it
                // right away so the still-open Verwaltung session doesn't lock itself out on its
                // very next request.
                $autoLogin = $role === 'admin' && !$this->users->hasActiveAdmin() && $this->auth->posCurrentUser() === null;
                $id = $this->users->create(
                    (string) ($b['name'] ?? ''),
                    (string) ($b['pin'] ?? ''),
                    $role,
                    (array) ($b['categoryIds'] ?? [])
                );
                if ($autoLogin) {
                    $this->auth->posAutoLoginAsNewAdmin($id);
                }
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

        if ($method === 'POST' && preg_match('#^/api/receipts/([A-Za-z0-9\-]+)/void$#', $path, $m)) {
            $this->auth->requireAccess();
            $b = Support::jsonBody();
            $receipt = $this->sales->void($m[1], (string) ($b['reason'] ?? ''), $this->settings->bool('track_stock', true));
            $this->audit->log('sale.void', 'Bon ' . $m[1] . ' storniert · ' . $receipt['voidReason']);
            return $receipt;
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
                $id = $this->categories->create(
                    (string) ($b['name'] ?? ''),
                    (bool) ($b['trackStockDefault'] ?? true),
                    self::normalizeDepositTypeId($b['depositTypeId'] ?? null)
                );
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
                if (array_key_exists('depositTypeId', $b)) {
                    $this->categories->setDepositType($id, self::normalizeDepositTypeId($b['depositTypeId']));
                }
                return ['ok' => true];
            }
            if ($method === 'DELETE') {
                $this->auth->requireAccess();
                $this->categories->delete($id);
                return ['ok' => true];
            }
        }

        // Bulk-apply a Pfand-Option to every article currently in this group — distinct from the
        // group's own depositTypeId above, which only prefills *new* articles going forward.
        if ($method === 'POST' && preg_match('#^/api/categories/(\d+)/apply-deposit-type$#', $path, $m)) {
            $this->auth->requireAccess();
            $b = Support::jsonBody();
            $count = $this->categories->applyDepositTypeToProducts((int) $m[1], self::normalizeDepositTypeId($b['depositTypeId'] ?? null));
            return ['updated' => $count];
        }

        if ($path === '/api/deposit-types') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return $this->depositTypes->list();
            }
            if ($method === 'POST') {
                $b = Support::jsonBody();
                $id = $this->depositTypes->create((string) ($b['name'] ?? ''), Support::parseAmountToCents((string) ($b['amount'] ?? '0')));
                return ['id' => $id];
            }
        }

        if (preg_match('#^/api/deposit-types/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            $this->auth->requireAccess();
            if ($method === 'PATCH') {
                $b = Support::jsonBody();
                $this->depositTypes->update($id, (string) ($b['name'] ?? ''), Support::parseAmountToCents((string) ($b['amount'] ?? '0')));
                return ['ok' => true];
            }
            if ($method === 'DELETE') {
                $this->depositTypes->delete($id);
                return ['ok' => true];
            }
        }

        if ($path === '/api/comp-accounts') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return $this->compAccounts->list();
            }
            if ($method === 'POST') {
                $b = Support::jsonBody();
                $id = $this->compAccounts->create((string) ($b['name'] ?? ''));
                return ['id' => $id];
            }
        }

        if (preg_match('#^/api/comp-accounts/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            $this->auth->requireAccess();
            if ($method === 'PATCH') {
                $b = Support::jsonBody();
                $this->compAccounts->rename($id, (string) ($b['name'] ?? ''));
                return ['ok' => true];
            }
            if ($method === 'DELETE') {
                $this->compAccounts->delete($id);
                return ['ok' => true];
            }
        }

        // ---- Auf Rechnung: Firmen und Abrechnung (Verwaltung) ----
        if ($path === '/api/invoice-accounts') {
            $this->auth->requireAccess();
            if ($method === 'GET') {
                return $this->invoiceAccounts->list();
            }
            if ($method === 'POST') {
                $b = Support::jsonBody();
                $id = $this->invoiceAccounts->create($b);
                $this->audit->log('invoice_account.create', 'Firma auf Rechnung angelegt: ' . trim((string) ($b['code'] ?? '')) . ' · ' . trim((string) ($b['name'] ?? '')));
                return ['id' => $id];
            }
        }

        if (preg_match('#^/api/invoice-accounts/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            $this->auth->requireAccess();
            if ($method === 'PATCH') {
                $this->invoiceAccounts->update($id, Support::jsonBody());
                return ['ok' => true];
            }
            if ($method === 'DELETE') {
                $this->invoiceAccounts->archive($id);
                $this->audit->log('invoice_account.archive', 'Firma auf Rechnung gelöscht (archiviert): #' . $id);
                return ['ok' => true];
            }
        }

        if ($method === 'GET' && preg_match('#^/api/invoice-accounts/(\d+)/statement(?:\.(pdf|csv))?$#', $path, $m)) {
            $this->auth->requireAccess();
            $status = in_array($_GET['status'] ?? 'open', ['open', 'billed', 'all'], true) ? (string) ($_GET['status'] ?? 'open') : 'open';
            $st = $this->invoiceAccounts->statement((int) $m[1], $status, $_GET['from'] ?? null, $_GET['to'] ?? null);
            if (($m[2] ?? '') === 'pdf') {
                $this->outputStatementPdf($st);
            }
            if (($m[2] ?? '') === 'csv') {
                $this->outputStatementCsv($st);
            }
            return $st;
        }

        if ($method === 'POST' && preg_match('#^/api/invoice-accounts/(\d+)/(bill|unbill)$#', $path, $m)) {
            $this->auth->requireAccess();
            $b = Support::jsonBody();
            $billed = $m[2] === 'bill';
            $n = $this->invoiceAccounts->setBilled((int) $m[1], (array) ($b['saleIds'] ?? []), $billed, (string) ($b['billedRef'] ?? ''));
            $this->audit->log($billed ? 'invoice.bill' : 'invoice.unbill', ($billed ? 'Abgerechnet' : 'Abrechnung zurückgenommen') . ': ' . $n . ' Bon(s) · Firma #' . $m[1]
                . ($billed && trim((string) ($b['billedRef'] ?? '')) !== '' ? ' · Rechnung ' . trim((string) $b['billedRef']) : ''));
            return ['changed' => $n];
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
            $register = $this->registerFrom(Support::jsonBody()['registerId'] ?? null);
            $result = $this->zReports->close($register['id']);
            $this->audit->log('zreport.close', $register['name'] . ' · Z-' . $result['no']);
            return $result;
        }

        if ($method === 'GET' && $path === '/api/z-reports') {
            $this->auth->requireAccess();
            return $this->zReports->list($this->registerFrom($_GET['registerId'] ?? null)['id']);
        }

        if ($method === 'POST' && preg_match('#^/api/z-reports/(\d+)/email$#', $path, $m)) {
            $this->auth->requireAccess();
            return $this->emailZReport((int) $m[1]);
        }

        if ($method === 'GET' && $path === '/api/cash/status') {
            $this->auth->requireAccess();
            $registerId = $this->registerFrom($_GET['registerId'] ?? null)['id'];
            $sinceAt = $this->cash->lastCloseOccurredAt($registerId);
            return [
                'registerId' => $registerId,
                'balanceCents' => $this->cash->balanceCents($registerId),
                'cashSinceCloseCents' => $this->cash->cashRevenueSince($registerId, $sinceAt),
            ];
        }

        if ($method === 'GET' && $path === '/api/maintenance/status') {
            $this->auth->requireAccess();
            return $this->maintenance->counts();
        }

        if ($method === 'GET' && preg_match('#^/api/reports/(kpis|daily|hourly|products|payments|groups|comp)$#', $path, $m)) {
            $this->auth->requireAccess();
            return $this->report($m[1]);
        }

        if ($method === 'POST' && $path === '/api/reports/email') {
            $this->auth->requireAccess();
            return $this->emailReports();
        }

        if ($method === 'GET' && $path === '/api/journal') {
            $this->auth->requireAccess();
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 80;
            $before = isset($_GET['before']) ? (int) $_GET['before'] : null;
            return $this->journal->page($this->registerFrom($_GET['registerId'] ?? null)['id'], $limit, $before);
        }

        if ($method === 'POST' && $path === '/api/journal/email') {
            $this->auth->requireAccess();
            return $this->emailJournal();
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

        if ($method === 'POST' && $path === '/api/maintenance/demo') {
            $this->auth->requireAccess();
            return $this->seedDemo();
        }

        throw new ApiException(404, 'Unbekannter Endpunkt');
    }

    private function bootstrap(): array
    {
        $settings = $this->settings->forClient();
        $register = $this->deviceRegister;
        $allowedCategories = $this->allowedCategoriesHere();
        $active = $this->products->listActive();
        if ($allowedCategories !== null) {
            $active = array_values(array_filter($active, static fn ($p) => in_array($p['category'], $allowedCategories, true)));
        }
        $products = array_map(static fn ($p) => [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'category' => $p['category'],
            'priceCents' => (int) $p['price_cents'],
            'depositCents' => (int) $p['deposit_cents'],
            'stock' => (int) $p['stock'],
            'stockMin' => (int) $p['stock_min'],
            'trackStock' => (bool) $p['track_stock'],
        ], $active);

        $today = 0;
        if ($register !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(" . ReportsRepo::NET_CENTS . "), 0) FROM sales
                 WHERE voided_at IS NULL AND payment <> 'comp' AND DATE(sold_at) = CURDATE() AND register_id = ?"
            );
            $stmt->execute([$register['id']]);
            $today = (int) $stmt->fetchColumn();
        }

        return [
            'shopName' => $settings['shopName'],
            'settings' => $settings,
            'products' => $products,
            'depositTypes' => $this->depositTypes->list(),
            // Bereiche for "ohne Berechnung" bookings — names only, the POS needs nothing else.
            'compAccounts' => array_map(
                static fn ($c) => ['id' => $c['id'], 'name' => $c['name']],
                $this->compAccounts->list()
            ),
            // Firmen "auf Rechnung" — Kürzel for the buttons, name to confirm the choice.
            'invoiceAccounts' => $this->invoiceAccounts->forPos(),
            'cashBalanceCents' => $register !== null ? $this->cash->balanceCents($register['id']) : 0,
            'todayRevenueCents' => $today,
            // This device's Kasse (null = several exist and it isn't assigned yet: POS can't sell),
            // plus all Kassen by name for the Verwaltung pickers.
            'register' => $register,
            'registers' => array_map(static fn ($r) => ['id' => $r['id'], 'name' => $r['name']], $this->registers->list()),
            'access' => $this->auth->status(),
            'posUsers' => $this->auth->posUsers(),
            'posUser' => $this->auth->posCurrentUser(),
        ];
    }

    /**
     * Article groups sellable on this device right now: the logged-in cashier's groups,
     * intersected with this Kasse's groups. null = no restriction from either side.
     */
    private function allowedCategoriesHere(): ?array
    {
        $byUser = $this->auth->posAllowedCategories();
        $byRegister = $this->deviceRegister !== null ? $this->registers->categoryNames($this->deviceRegister['id']) : null;
        if ($byUser === null) {
            return $byRegister;
        }
        if ($byRegister === null) {
            return $byUser;
        }
        return array_values(array_intersect($byUser, $byRegister));
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
            'depositCents' => (int) $p['deposit_cents'],
            'depositTypeId' => $p['deposit_type_id'] !== null ? (int) $p['deposit_type_id'] : null,
            'costCents' => (int) $p['cost_cents'],
            'stock' => (int) $p['stock'],
            'stockMin' => (int) $p['stock_min'],
            'trackStock' => (bool) $p['track_stock'],
            'soldQty' => (int) $p['sold_qty'],
            'soldRevenueCents' => (int) $p['sold_revenue_cents'],
        ], $this->products->listActive());
    }

    // Pfand is appended at the end, not inserted among the existing columns, so a CSV exported
    // before this field existed still imports correctly (missing trailing column = 0 Pfand).
    private const CSV_HEADER = ['Artikelnummer', 'Name', 'Gruppe', 'Verkaufspreis', 'Einkaufspreis', 'Lagerbestand_fuehren', 'Bestand', 'Meldebestand', 'Pfand'];

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
                number_format(((int) $p['deposit_cents']) / 100, 2, ',', ''),
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
            $cols = array_pad(str_getcsv($line, $delimiter), 9, '');
            [$sku, $name, $groupName, $priceRaw, $costRaw, $trackRaw, $stockRaw, $stockMinRaw, $depositRaw] = $cols;

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
            // A raw amount in the sheet resolves to (or creates) a reusable Pfand-Option by that
            // exact amount, same convenience as an unknown Artikelgruppe getting created on the fly.
            $depositCents = Support::parseAmountToCents($depositRaw);
            $depositTypeId = $depositCents > 0 ? $this->depositTypes->findOrCreateByAmount($depositCents) : null;

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
                    $sku !== '' ? $sku : null,
                    $depositTypeId
                );
                $updated++;
            } else {
                $this->products->create($name, $category['name'], $priceCents, $costCents, $stock, $stockMin, $trackStock, $sku !== '' ? $sku : null, $depositTypeId);
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
            self::normalizeSku($b['sku'] ?? null),
            self::normalizeDepositTypeId($b['depositTypeId'] ?? null)
        );
        return ['id' => $id];
    }

    private static function normalizeSku(mixed $sku): ?string
    {
        $sku = trim((string) $sku);
        return $sku === '' ? null : $sku;
    }

    private static function normalizeDepositTypeId(mixed $depositTypeId): ?int
    {
        return empty($depositTypeId) ? null : (int) $depositTypeId;
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
            self::normalizeSku($b['sku'] ?? null),
            self::normalizeDepositTypeId($b['depositTypeId'] ?? null)
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
        // Stock is shared, but the journal entry goes to the Kasse that sells this article group
        // (if exactly one does) — a Merch delivery belongs in the Merch-Kasse's journal.
        $registerId = $this->registers->soleRegisterForCategory((string) $product['category']) ?? $this->registerFrom(null)['id'];
        $this->cash->insert($registerId, 'delivery', 0, $note, null, $productId, $qty);
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
        $registerId = $this->registerFrom($b['registerId'] ?? null)['id'];
        if ($type === 'out' && $amount > $this->cash->balanceCents($registerId)) {
            throw new ApiException(400, 'Mehr als der Kassenbestand');
        }
        $note = trim((string) ($b['note'] ?? '')) ?: ($type === 'in' ? 'Einlage' : 'Entnahme');
        $this->cash->insert($registerId, $type, $type === 'out' ? -$amount : $amount, $note);
        return ['ok' => true];
    }

    private function createSale(): array
    {
        $register = $this->requireDeviceRegister();
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
        $allowedCategories = $this->allowedCategoriesHere();
        if ($allowedCategories !== null) {
            foreach ($items as $item) {
                if ($item['productId'] === null) {
                    continue;
                }
                $product = $this->products->find($item['productId']);
                if ($product === null || !in_array($product['category'], $allowedCategories, true)) {
                    throw new ApiException(403, 'Artikel wird an dieser Kasse bzw. von diesem Benutzer nicht verkauft');
                }
            }
        }

        // Pfand-Rückgabe lines in the same cart (e.g. new drinks + a returned Krug, one combined
        // checkout) — picks a Pfand-Option directly, not a specific article; the register doesn't
        // need to know which drink a returned Krug held. Resolved server-side like everything
        // else here, never trusted from the client.
        $returnLines = [];
        foreach ((array) ($b['returns'] ?? []) as $r) {
            $depositType = $this->depositTypes->find((int) ($r['depositTypeId'] ?? 0));
            if ($depositType === null) {
                throw new ApiException(404, 'Pfand-Option nicht gefunden');
            }
            $returnLines[] = ['depositType' => $depositType, 'qty' => (int) ($r['qty'] ?? 0)];
        }

        // "Ohne Berechnung": the Bereich is resolved here, never trusted from the client beyond
        // its id — same as Pfand-Optionen above.
        $payment = (string) ($b['payment'] ?? 'cash');
        $compAccount = null;
        if ($payment === 'comp') {
            $compAccount = $this->compAccounts->find((int) ($b['compAccountId'] ?? 0));
            if ($compAccount === null) {
                throw new ApiException(400, 'Bitte einen Bereich für die Buchung ohne Berechnung wählen');
            }
        }

        // "Auf Rechnung": same — only the Firma's id is taken from the client.
        $invoiceAccount = null;
        if ($payment === 'invoice') {
            $invoiceAccount = $this->invoiceAccounts->findActive((int) ($b['invoiceAccountId'] ?? 0));
            if ($invoiceAccount === null) {
                throw new ApiException(400, 'Bitte eine Firma für die Buchung auf Rechnung wählen');
            }
        }

        $trackStock = $this->settings->bool('track_stock', true);
        return $this->sales->create(
            $register['id'],
            $items,
            (int) ($b['discountCents'] ?? 0),
            $payment,
            (int) ($b['givenCents'] ?? 0),
            $trackStock,
            $clientUuid,
            $returnLines,
            $compAccount,
            $invoiceAccount,
            (string) ($b['invoiceRef'] ?? '')
        );
    }

    private function report(string $kind): array
    {
        $registerId = $this->reportRegisterId();
        $trackStock = $this->settings->bool('track_stock', true);
        return match ($kind) {
            'kpis' => $this->reports->kpis($trackStock, $registerId),
            'daily' => $this->reports->daily($registerId),
            'hourly' => $this->reports->hourly($registerId),
            'products' => $this->reports->productsRanking(7, $registerId),
            'payments' => $this->reports->payments($registerId),
            'groups' => $this->reports->groups($trackStock, $registerId, $registerId !== null ? $this->registers->categoryNames($registerId) : null),
            'comp' => $this->reports->comp($registerId),
            default => throw new ApiException(404, 'Unbekannter Report'),
        };
    }

    /** @return string[] */
    private function reportRecipientsOrFail(): array
    {
        $to = $this->settings->reportEmails();
        if (empty($to)) {
            throw new ApiException(400, 'Keine Berichts-E-Mail hinterlegt · Verwaltung → Einstellungen');
        }
        return $to;
    }

    private const JOURNAL_TAG_TEXT = [
        'sale' => 'Verkauf', 'in' => 'Einlage', 'out' => 'Entnahme',
        'close' => 'Z-Abschluss', 'delivery' => 'Warenzugang', 'deposit_return' => 'Pfand zurück',
        'comp' => 'Ohne Berechnung', 'void' => 'Storno',
    ];

    private function emailJournal(): array
    {
        $to = $this->reportRecipientsOrFail();
        $register = $this->registerFrom(Support::jsonBody()['registerId'] ?? null);
        $rows = $this->journal->exportRows($register['id']);
        $shopName = $this->settings->forClient()['shopName'];
        $title = $shopName . ' · ' . $register['name'];

        $balance = $this->cash->balanceCents($register['id']);
        $pdf = new Pdf($title . ' · Journal');
        $pdf->addBanner(
            [
                ['text' => 'Kassenbestand aktuell: ' . Support::eur($balance), 'bold' => true, 'size' => 13.0],
                ['text' => 'Erstellt ' . date('d.m.Y H:i') . ' Uhr · ' . count($rows) . ' Eintrag(e)'],
            ],
            [0.10, 0.15, 0.35]
        );
        // One card per Vorgang, using the full line width: a bold, coloured header (Nr. · Typ ·
        // Datum/Zeit, amount right-aligned) followed by one line per purchased article or
        // returned Pfand-Option — never a single run-on summary that could overflow the margin.
        // A light hairline after each card turns the listing into a table without a heavy border.
        $pdf->addRule();
        foreach ($rows as $r) {
            $when = (new \DateTime($r['occurredAt']))->format('d.m.Y H:i');
            $type = self::JOURNAL_TAG_TEXT[$r['type']] ?? $r['type'];
            if ($r['type'] === 'comp' && !empty($r['compName'])) {
                $type .= ' (' . $r['compName'] . ')';
            }
            if (!empty($r['saleVoided']) && $r['type'] !== 'void') {
                $type .= ' (storniert)';
            }
            $amount = $r['type'] === 'delivery' ? '–' : Support::eur($r['amountCents']);
            $color = $r['type'] === 'delivery' ? null : ($r['amountCents'] > 0 ? Pdf::GREEN : ($r['amountCents'] < 0 ? Pdf::RED : null));

            $header = 'Nr. ' . ($r['receiptNo'] ?? '–') . '  ·  ' . $type . '  ·  ' . $when . ' Uhr'
                . (!empty($r['userName']) ? '  ·  ' . $r['userName'] : '');
            $pdf->addSplitLine($header, $amount, bold: true, rightColor: $color);

            if (!empty($r['lines'])) {
                foreach ($r['lines'] as $line) {
                    $pdf->addSplitLine('  ' . Support::padDisplay($line['label'], 85), Support::eur($line['amountCents']));
                }
            } elseif ($r['note'] !== '') {
                $pdf->addLine('  ' . $r['note']);
            }
            $pdf->addRule();
        }
        if (empty($rows)) {
            $pdf->addLine('Noch keine Kassenbewegungen.');
        }

        $filename = 'Journal-' . self::fileSafe($register['name']) . '-' . date('Y-m-d') . '.pdf';
        $this->mailer->send(
            $to,
            $title . ' · Journal (' . date('d.m.Y') . ')',
            'Anbei das Journal (Kassenbuch) von ' . $register['name'] . ' mit ' . count($rows) . " Eintrag(en).\n\n" . $shopName,
            [['filename' => $filename, 'mime' => 'application/pdf', 'content' => $pdf->output()]]
        );
        $this->audit->log('journal.email', 'Journal ' . $register['name'] . ' per E-Mail an ' . implode(', ', $to));
        return ['ok' => true];
    }

    private function emailReports(): array
    {
        $to = $this->reportRecipientsOrFail();
        $trackStock = $this->settings->bool('track_stock', true);
        $shopName = $this->settings->forClient()['shopName'];
        $rawRegister = Support::jsonBody()['registerId'] ?? null;
        $registerId = ($rawRegister === null || $rawRegister === '' || $rawRegister === 'all') ? null : $this->registerFrom($rawRegister)['id'];
        $scope = $registerId === null ? 'Alle Kassen' : $this->registers->find($registerId)['name'];
        $kpis = $this->reports->kpis($trackStock, $registerId);
        $daily = $this->reports->daily($registerId);
        $ranking = $this->reports->productsRanking(10, $registerId);
        $payments = $this->reports->payments($registerId);
        $groups = $this->reports->groups($trackStock, $registerId, $registerId !== null ? $this->registers->categoryNames($registerId) : null);

        $pdf = new Pdf($shopName . ' · Auswertungen · ' . $scope);
        $pdf->addBanner(
            [
                ['text' => 'Umsatz gesamt: ' . Support::eur($kpis['totalRevenueCents']), 'bold' => true, 'size' => 13.0],
                ['text' => 'Heute: ' . Support::eur($kpis['todayRevenueCents']) . ' (' . $kpis['todayCount'] . ' Bons) · Rohertrag: ' . Support::eur($kpis['grossProfitCents']) . ' · Ø Bon: ' . Support::eur($kpis['avgTicketCents'])],
                ['text' => 'Erstellt ' . date('d.m.Y H:i') . ' Uhr'],
            ],
            [0.10, 0.15, 0.35]
        );
        if ($trackStock && $kpis['lowStockCount'] > 0) {
            $pdf->addLine('Artikel unter Meldebestand: ' . $kpis['lowStockCount'] . ' (' . implode(', ', $kpis['lowStockNames']) . ')');
            $pdf->addSpacer();
        }

        $pdf->addLine('Umsatz pro Tag (letzte 7 Tage)', true);
        foreach ($daily as $d) {
            $pdf->addLine(Support::padDisplay($d['weekday'] . ' ' . $d['date'], 20) . Support::padDisplayRight(Support::eur($d['totalCents']), 12));
        }
        $pdf->addSpacer();

        $pdf->addLine('Top Artikel nach Umsatz', true);
        $pdf->addLine(Support::padDisplay('Artikel', 40) . Support::padDisplayRight('Menge', 10) . Support::padDisplayRight('Umsatz', 14));
        foreach ($ranking as $r) {
            $pdf->addLine(Support::padDisplay($r['name'] ?? '–', 40) . Support::padDisplayRight($r['qty'] . '×', 10) . Support::padDisplayRight(Support::eur($r['revenueCents']), 14));
        }
        if (empty($ranking)) {
            $pdf->addLine('Noch keine Verkäufe.');
        }
        $pdf->addSpacer();

        $pdf->addLine('Zahlungsarten', true);
        $pdf->addLine('Bar: ' . Support::eur($payments['cash']['sumCents']) . ' (' . $payments['cash']['count'] . ' Bons)');
        $pdf->addLine('Karte: ' . Support::eur($payments['card']['sumCents']) . ' (' . $payments['card']['count'] . ' Bons)');
        if ($payments['invoice']['count'] > 0) {
            $pdf->addLine('Auf Rechnung: ' . Support::eur($payments['invoice']['sumCents']) . ' (' . $payments['invoice']['count'] . ' Bons)');
        }
        $pdf->addSpacer();

        $pdf->addLine('Artikelgruppen', true);
        $pdf->addLine(Support::padDisplay('Gruppe', 30) . Support::padDisplayRight('Anteil', 9) . Support::padDisplayRight('Umsatz', 14) . Support::padDisplayRight('Rohertrag', 16));
        foreach ($groups as $g) {
            $pdf->addLine(
                Support::padDisplay($g['name'], 30) . Support::padDisplayRight($g['sharePct'] . '%', 9)
                . Support::padDisplayRight(Support::eur($g['revenueCents']), 14) . Support::padDisplayRight(Support::eur($g['profitCents']), 16)
            );
        }

        $comp = $this->reports->comp($registerId);
        if (!empty($comp)) {
            $pdf->addSpacer();
            $pdf->addLine('Ohne Berechnung (nicht im Umsatz enthalten)', true);
            $pdf->addLine(Support::padDisplay('Bereich', 30) . Support::padDisplayRight('Buchungen', 11) . Support::padDisplayRight('Warenwert VK', 16) . Support::padDisplayRight('Wareneinsatz EK', 18));
            foreach ($comp as $c) {
                $pdf->addLine(
                    Support::padDisplay($c['name'], 30) . Support::padDisplayRight((string) $c['count'], 11)
                    . Support::padDisplayRight(Support::eur($c['valueCents']), 16) . Support::padDisplayRight(Support::eur($c['costCents']), 18)
                );
                foreach ($c['items'] as $item) {
                    $pdf->addLine('  ' . Support::padDisplay($item['qty'] . '× ' . $item['name'], 39) . Support::padDisplayRight(Support::eur($item['valueCents']), 16));
                }
            }
        }

        $filename = 'Auswertungen-' . self::fileSafe($scope) . '-' . date('Y-m-d') . '.pdf';
        $this->mailer->send(
            $to,
            $shopName . ' · Auswertungen ' . $scope . ' (' . date('d.m.Y') . ')',
            'Anbei die aktuellen Auswertungen (' . $scope . ").\n\n" . $shopName,
            [['filename' => $filename, 'mime' => 'application/pdf', 'content' => $pdf->output()]]
        );
        $this->audit->log('reports.email', 'Auswertungen (' . $scope . ') per E-Mail an ' . implode(', ', $to));
        return ['ok' => true];
    }

    private function emailZReport(int $id): array
    {
        $to = $this->reportRecipientsOrFail();
        $z = $this->zReports->find($id);
        if ($z === null) {
            throw new ApiException(404, 'Abschluss nicht gefunden');
        }
        $shopName = $this->settings->forClient()['shopName'] . ' · ' . ($z['registerName'] ?? 'Kasse');

        $period = 'Abgeschlossen ' . (new \DateTime($z['closedAt']))->format('d.m.Y H:i') . ' Uhr';
        if ($z['fromSaleAt'] !== null) {
            $period .= ' · seit ' . (new \DateTime($z['fromSaleAt']))->format('d.m.Y H:i') . ' Uhr';
        }
        $pdf = new Pdf($shopName . ' · Tagesabschluss Z-' . $z['no']);
        $pdf->addBanner(
            [
                ['text' => 'Endstand: ' . Support::eur($z['totalCents']), 'bold' => true, 'size' => 13.0],
                ['text' => $period],
                ['text' => 'Bar ' . Support::eur($z['cashCents']) . ' · Karte ' . Support::eur($z['cardCents'])
                    . ($z['invoiceCents'] > 0 ? ' · Auf Rechnung ' . Support::eur($z['invoiceCents']) : '') . ' · ' . $z['salesCount'] . ' Bon(s)'],
            ],
            [0.10, 0.15, 0.35]
        );
        $pdf->addLine('Bar aus der Kasse entnommen: ' . Support::eur($z['drawerCents']));
        $pdf->addSpacer();

        $sales = $this->zReports->salesFor($z['id']);
        $pdf->addLine('Verkäufe', true);
        $pdf->addSplitLine(
            Support::padDisplay('Bon-Nr.', 15) . Support::padDisplay('Datum/Zeit', 17) . Support::padDisplay('Zahlung', 10),
            'Betrag',
            bold: true
        );
        $pdf->addRule();
        foreach ($sales as $s) {
            $pdf->addSplitLine(
                Support::padDisplay($s['receiptNo'], 15) . Support::padDisplay((new \DateTime($s['soldAt']))->format('d.m.Y H:i'), 17)
                . Support::padDisplay(match ($s['payment']) { 'card' => 'Karte', 'invoice' => 'Re. ' . ($s['invoiceCode'] ?? ''), default => 'Bar' }, 10),
                Support::eur($s['totalCents']),
                leftColor: Pdf::GREEN,
                rightColor: Pdf::GREEN
            );
            $pdf->addRule();
        }
        if (empty($sales)) {
            $pdf->addLine('Keine Verkäufe in diesem Zeitraum.');
        }

        $comp = $this->zReports->compFor($z['id']);
        if (!empty($comp)) {
            $pdf->addSpacer();
            $pdf->addLine('Ohne Berechnung (nicht im Umsatz enthalten)', true);
            foreach ($comp as $c) {
                $pdf->addSplitLine($c['name'] . ' · ' . $c['count'] . ' Buchung(en)', 'Warenwert ' . Support::eur($c['valueCents']));
            }
        }

        $filename = 'Z-Bericht-' . self::fileSafe((string) $z['registerName']) . '-' . $z['no'] . '.pdf';
        $this->mailer->send(
            $to,
            $shopName . ' · Tagesabschluss Z-' . $z['no'],
            'Anbei der Tagesabschluss Z-' . $z['no'] . ".\n\n" . $shopName,
            [['filename' => $filename, 'mime' => 'application/pdf', 'content' => $pdf->output()]]
        );
        $this->audit->log('zreport.email', $shopName . ' Z-' . $z['no'] . ' per E-Mail an ' . implode(', ', $to));
        return ['ok' => true];
    }

    /** Abrechnung of one Firma as PDF — the basis for writing the invoice. */
    private function outputStatementPdf(array $st): never
    {
        $acc = $st['account'];
        $shopName = $this->settings->forClient()['shopName'];
        $statusText = ['open' => 'offene Bons', 'billed' => 'abgerechnete Bons', 'all' => 'alle Bons'][$st['status']];
        $range = ($st['from'] || $st['to'])
            ? ' · ' . ($st['from'] ? date('d.m.Y', strtotime($st['from'])) : '…') . ' – ' . ($st['to'] ? date('d.m.Y', strtotime($st['to'])) : '…')
            : '';

        $pdf = new Pdf($shopName . ' · Abrechnung auf Rechnung');
        $pdf->addBanner(
            [
                ['text' => $acc['code'] . ' · ' . $acc['name'], 'bold' => true, 'size' => 13.0],
                ['text' => 'Summe: ' . Support::eur($st['totals']['totalCents']) . ' · ' . $st['totals']['count'] . ' Bon(s) · ' . $statusText . $range],
                ['text' => 'Erstellt ' . date('d.m.Y H:i') . ' Uhr'],
            ],
            [0.10, 0.15, 0.35]
        );
        if ($acc['address'] !== '') {
            foreach (explode("\n", $acc['address']) as $line) {
                $pdf->addLine($line);
            }
            $pdf->addSpacer();
        }

        $pdf->addLine('Artikel', true);
        $pdf->addSplitLine(Support::padDisplay('Menge', 8) . Support::padDisplay('Artikel', 44) . Support::padDisplayRight('Einzel', 12), 'Gesamt', bold: true);
        $pdf->addRule();
        foreach ($st['items'] as $i) {
            $pdf->addSplitLine(
                Support::padDisplay($i['qty'] . '×', 8) . Support::padDisplay($i['name'], 44) . Support::padDisplayRight(Support::eur($i['unitCents']), 12),
                Support::eur($i['sumCents'])
            );
        }
        foreach ($st['returns'] as $r) {
            $pdf->addSplitLine(
                Support::padDisplay($r['qty'] . '×', 8) . Support::padDisplay('Pfand-Rückgabe ' . $r['name'], 44) . Support::padDisplayRight('-' . Support::eur($r['unitCents']), 12),
                '-' . Support::eur($r['sumCents'])
            );
        }
        $pdf->addRule();
        $t = $st['totals'];
        $pdf->addSplitLine('Warenwert', Support::eur($t['goodsCents']));
        if ($t['discountCents']) {
            $pdf->addSplitLine('Rabatt', '-' . Support::eur($t['discountCents']));
        }
        if ($t['depositCents']) {
            $pdf->addSplitLine('Pfand', Support::eur($t['depositCents']));
        }
        if ($t['depositReturnedCents']) {
            $pdf->addSplitLine('Pfand-Rückgabe (Gutschrift)', '-' . Support::eur($t['depositReturnedCents']));
        }
        $pdf->addSplitLine('Summe (brutto)', Support::eur($t['totalCents']), bold: true);
        $pdf->addSpacer();

        $pdf->addLine('Bons', true);
        $pdf->addSplitLine(Support::padDisplay('Bon-Nr.', 15) . Support::padDisplay('Datum/Zeit', 17) . 'Vermerk', 'Betrag', bold: true);
        $pdf->addRule();
        foreach ($st['sales'] as $s) {
            $pdf->addSplitLine(
                Support::padDisplay($s['receiptNo'], 15) . Support::padDisplay((new \DateTime($s['soldAt']))->format('d.m.Y H:i'), 17)
                . Support::padDisplay($s['ref'] . ($s['billedRef'] !== '' ? ' (Re. ' . $s['billedRef'] . ')' : ''), 40),
                Support::eur($s['totalCents']),
                bold: true
            );
            foreach ($s['lines'] as $l) {
                $pdf->addSplitLine('  ' . Support::padDisplay($l['qty'] . '× ' . $l['name'], 60), Support::eur($l['qty'] * $l['unitCents']));
            }
            if ($s['discountCents']) {
                $pdf->addSplitLine('  Rabatt', '-' . Support::eur($s['discountCents']));
            }
            if ($s['depositCents']) {
                $pdf->addSplitLine('  Pfand', Support::eur($s['depositCents']));
            }
            foreach ($s['returns'] as $r) {
                $pdf->addSplitLine('  ' . Support::padDisplay($r['qty'] . '× Pfand-Rückgabe ' . $r['name'], 60), '-' . Support::eur($r['amountCents']));
            }
            $pdf->addRule();
        }
        if (empty($st['sales'])) {
            $pdf->addLine('Keine Bons im gewählten Zeitraum.');
        }

        $filename = 'Abrechnung-' . self::fileSafe($acc['code']) . '-' . date('Y-m-d') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo $pdf->output();
        exit;
    }

    /** Same Abrechnung as CSV (one row per article line of each Bon) for Excel or the invoicing program. */
    private function outputStatementCsv(array $st): never
    {
        $filename = 'Abrechnung-' . self::fileSafe($st['account']['code']) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        $money = static fn (int $c) => number_format($c / 100, 2, ',', '');
        fputcsv($out, ['Kuerzel', 'Firma', 'Bon', 'Datum', 'Kasse', 'Vermerk', 'Menge', 'Artikel', 'Einzelpreis', 'Pfand_je_Stueck', 'Gesamt', 'Abgerechnet_am', 'Rechnungsnummer'], ';');
        foreach ($st['sales'] as $s) {
            foreach ($s['lines'] as $l) {
                fputcsv($out, [
                    $st['account']['code'], $st['account']['name'], $s['receiptNo'], (new \DateTime($s['soldAt']))->format('d.m.Y H:i'),
                    $s['registerName'] ?? '', $s['ref'], (string) $l['qty'], $l['name'], $money($l['unitCents']), $money($l['depositCents']),
                    $money($l['qty'] * ($l['unitCents'] + $l['depositCents'])),
                    $s['billedAt'] ? (new \DateTime($s['billedAt']))->format('d.m.Y') : '', $s['billedRef'],
                ], ';');
            }
            foreach ($s['returns'] as $r) {
                fputcsv($out, [$st['account']['code'], $st['account']['name'], $s['receiptNo'], (new \DateTime($s['soldAt']))->format('d.m.Y H:i'),
                    $s['registerName'] ?? '', $s['ref'], (string) $r['qty'], 'Pfand-Rückgabe ' . $r['name'],
                    '-' . $money($r['qty'] > 0 ? intdiv($r['amountCents'], $r['qty']) : $r['amountCents']), '0,00', '-' . $money($r['amountCents']),
                    $s['billedAt'] ? (new \DateTime($s['billedAt']))->format('d.m.Y') : '', $s['billedRef']], ';');
            }
            if ($s['discountCents']) {
                fputcsv($out, [$st['account']['code'], $st['account']['name'], $s['receiptNo'], (new \DateTime($s['soldAt']))->format('d.m.Y H:i'),
                    $s['registerName'] ?? '', $s['ref'], '1', 'Rabatt', '-' . $money($s['discountCents']), '0,00', '-' . $money($s['discountCents']),
                    $s['billedAt'] ? (new \DateTime($s['billedAt']))->format('d.m.Y') : '', $s['billedRef']], ';');
            }
        }
        fclose($out);
        exit;
    }

    private static function fileSafe(string $name): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-') ?: 'Kasse';
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
        $this->maintenance->resetAll();
        $this->audit->log('data.reset', 'Alles zurückgesetzt');
        return ['ok' => true];
    }

    private function seedDemo(): array
    {
        if ($this->maintenance->counts()['products'] > 0) {
            throw new ApiException(409, 'Demo-Daten können nur eingespielt werden, wenn noch keine Artikel vorhanden sind.');
        }
        $this->maintenance->seedDemo();
        $this->audit->log('data.demo', 'Demo-Daten eingespielt');
        return ['ok' => true];
    }
}
