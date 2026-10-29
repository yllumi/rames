<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ErrorController;
use app\library\Errors\ErrorQuery;
use app\library\Errors\ErrorReporter;
use app\library\Errors\ErrorStore;
use app\library\Storage\AppStore;
use app\library\Storage\SqliteDatabase;
use app\middleware\CsrfMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test mediator {@see ErrorController}: otorisasi (non-admin ⇒ 404), filter
 * view, aksi status/hapus, endpoint laporan klien, dan CSRF pada semua mutasi.
 *
 * Tanpa HTTP/sesi: subclass mengganti seam `currentUser()`/`flash()`/
 * `renderView()`. Store & config 100% temp.
 */
class ErrorControllerTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];

    private string $tmp;

    private string $file;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/errctrl_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';

        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // otorisasi
    // ------------------------------------------------------------------

    public function testNonAdminGets404OnPagesAndApi(): void
    {
        $group = $this->seedGroup('Gagal <b>satu</b>');
        $controller = $this->controller(self::MEMBER);

        $index = $controller->index($this->get('/errors'));
        self::assertSame(404, $index->getStatusCode());
        self::assertNull($controller->rendered, 'halaman tidak boleh dirender untuk non-admin');

        $api = $controller->apiIndex($this->get('/api/errors'));
        self::assertSame(404, $api->getStatusCode());
        $body = json_decode($api->rawBody(), true);
        self::assertSame(1, $body['code'] ?? null);
        self::assertSame('Tidak ditemukan.', $body['error'] ?? null);

        self::assertSame(404, $controller->show($this->get('/errors/' . $group['id']), (string) $group['id'])->getStatusCode());
        self::assertSame(404, $controller->status($this->post('/errors/' . $group['id'] . '/status', ['status' => 'resolved']), (string) $group['id'])->getStatusCode());
        self::assertSame(404, $controller->delete($this->post('/errors/' . $group['id'] . '/delete', []), (string) $group['id'])->getStatusCode());
        self::assertSame([], $controller->flashes, 'tidak ada efek samping untuk non-admin');

        self::assertNotNull($this->store()->findGroup((string) $group['id']), 'grup tidak berubah');
    }

    // ------------------------------------------------------------------
    // index & apiIndex
    // ------------------------------------------------------------------

    public function testAdminIndexPassesViewContractAndFilters(): void
    {
        $app = (new AppStore($this->file))->create([
            'id' => 'app-1',
            'name' => 'Zeta App',
            'owner_id' => self::ADMIN['id'],
        ]);
        (new AppStore($this->file))->create([
            'id' => 'app-2',
            'name' => 'Alpha App',
            'owner_id' => self::ADMIN['id'],
        ]);

        $this->seedGroup('Disk penuh', ['app_id' => $app['id']]);
        $this->seedGroup('Masalah lain', ['kind' => 'server']);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->index($this->get('/errors?status=all&kind=client&q=Disk&app_id=app-1&page=1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($controller->rendered);
        self::assertSame('errors/index', $controller->rendered['template']);

        $vars = $controller->rendered['vars'];
        self::assertSame('errors', $vars['active']);
        self::assertSame(['status' => 'all', 'kind' => 'client', 'app_id' => 'app-1', 'q' => 'Disk'], $vars['filters']);
        self::assertSame(1, $vars['total']);
        self::assertSame(1, $vars['page']);
        self::assertSame(1, $vars['pages']);
        self::assertSame(['open' => 2, 'resolved' => 0, 'ignored' => 0], $vars['counts']);
        self::assertSame('Disk penuh', $vars['groups'][0]['message']);
        self::assertSame('Zeta App', $vars['groups'][0]['app_name']);

        // Opsi filter app urut nama.
        self::assertSame(['Alpha App', 'Zeta App'], array_column($vars['apps'], 'name'));
    }

    public function testApiIndexReturnsJsonEnvelope(): void
    {
        $this->seedGroup('Boom');

        $controller = $this->controller(self::ADMIN);
        $response = $controller->apiIndex($this->get('/api/errors?status=open'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        self::assertSame(0, $body['code'] ?? null);
        self::assertSame(1, $body['data']['total'] ?? null);
        self::assertSame(1, $body['data']['page'] ?? null);
        self::assertSame(1, $body['data']['pages'] ?? null);
        self::assertSame('Boom', $body['data']['groups'][0]['message'] ?? null);
        self::assertSame(['open' => 1, 'resolved' => 0, 'ignored' => 0], $body['data']['counts'] ?? null);
    }

    // ------------------------------------------------------------------
    // show
    // ------------------------------------------------------------------

    public function testAdminShowRendersDetailWithEvents(): void
    {
        $group = $this->seedGroup('Detail boom', [
            'stack' => "Error\n    at run (/app/public/js/app.js:1:1)",
            'context' => ['fitur' => 'monitor'],
        ]);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->show($this->get('/errors/' . $group['id']), (string) $group['id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('errors/detail', $controller->rendered['template']);
        self::assertSame('Detail boom', $controller->rendered['vars']['group']['message']);
        self::assertCount(1, $controller->rendered['vars']['events']);
        self::assertSame(['fitur' => 'monitor'], $controller->rendered['vars']['events'][0]['context']);
    }

    public function testShowReturns404ForInvalidOrMissingId(): void
    {
        $controller = $this->controller(self::ADMIN);

        self::assertSame(404, $controller->show($this->get('/errors/bad'), 'bad id!')->getStatusCode());
        self::assertSame(404, $controller->show($this->get('/errors/abc'), 'abcdef0123456789')->getStatusCode());
        self::assertNull($controller->rendered);
    }

    // ------------------------------------------------------------------
    // status & delete
    // ------------------------------------------------------------------

    public function testStatusUpdatesGroupAndFlashes(): void
    {
        $group = $this->seedGroup('Akan diselesaikan');
        $controller = $this->controller(self::ADMIN);

        $response = $controller->status(
            $this->post('/errors/' . $group['id'] . '/status', ['status' => 'resolved']),
            (string) $group['id']
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/errors/' . $group['id'], $response->getHeader('Location'));
        self::assertSame('success', $controller->flashes[0]['type']);

        $row = $this->store()->findGroup((string) $group['id']);
        self::assertSame('resolved', $row['status']);
        self::assertSame('admin', $row['resolved_by']);
    }

    public function testStatusRejectsUnknownStatusWithoutMutation(): void
    {
        $group = $this->seedGroup('Tetap open');
        $controller = $this->controller(self::ADMIN);

        $response = $controller->status(
            $this->post('/errors/' . $group['id'] . '/status', ['status' => 'deleted']),
            (string) $group['id']
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('error', $controller->flashes[0]['type']);
        self::assertSame('open', $this->store()->findGroup((string) $group['id'])['status']);
    }

    public function testStatusMissingGroupFlashesAndRedirectsToDetail(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->status(
            $this->post('/errors/abcdef0123456789/status', ['status' => 'open']),
            'abcdef0123456789'
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/errors/abcdef0123456789', $response->getHeader('Location'));
        self::assertSame('error', $controller->flashes[0]['type']);
    }

    public function testDeleteRemovesGroup(): void
    {
        $group = $this->seedGroup('Hapus saya');
        $controller = $this->controller(self::ADMIN);

        $response = $controller->delete(
            $this->post('/errors/' . $group['id'] . '/delete', []),
            (string) $group['id']
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/errors', $response->getHeader('Location'));
        self::assertSame('success', $controller->flashes[0]['type']);
        self::assertNull($this->store()->findGroup((string) $group['id']));
    }

    public function testDeleteMissingGroupFlashesError(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->delete($this->post('/errors/abcdef0123456789/delete', []), 'abcdef0123456789');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('error', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // report
    // ------------------------------------------------------------------

    public function testReportRequiresLogin(): void
    {
        $controller = $this->controller(null);

        $response = $controller->report($this->post('/api/errors', ['kind' => 'client', 'message' => 'Boom']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['code' => 401, 'msg' => 'Unauthorized'], json_decode($response->rawBody(), true));
    }

    public function testReportAcceptsValidPayload(): void
    {
        $controller = $this->controller(self::MEMBER);

        $response = $controller->report($this->post('/api/errors', [
            'kind' => 'client',
            'message' => 'Gagal memuat monitor',
            'route' => '/monitor',
            'occurred_at' => date('c'),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 0], json_decode($response->rawBody(), true));
        self::assertSame(1, $this->eventCount());
    }

    public function testReportStoresPayloadRouteAndDerivedAppId(): void
    {
        (new AppStore($this->file))->create([
            'id' => 'app-1',
            'name' => 'demo',
            'owner_id' => self::MEMBER['id'],
        ]);

        $controller = $this->controller(self::MEMBER);

        $response = $controller->report($this->post('/api/errors', [
            'kind' => 'client',
            'message' => 'Gagal di halaman app',
            'route' => '/apps/app-1',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $groups = $this->store()->listGroups(['status' => 'all'], 1, 50)['groups'];
        self::assertCount(1, $groups);
        self::assertSame('/apps/app-1', $groups[0]['route'], 'bukan /api/errors');
        self::assertSame('app-1', $groups[0]['app_id']);
    }

    public function testReportStoreFailureReturns500Not422(): void
    {
        // Path DB berupa direktori ⇒ PDO gagal membuka saat store dipakai.
        mkdir($this->tmp . '/bukan-db', 0777, true);
        $brokenStore = new ErrorStore(new SqliteDatabase($this->tmp . '/bukan-db'));

        $controller = new FakeErrorController(
            new ErrorQuery($brokenStore),
            new ErrorReporter($brokenStore),
            new AppStore($this->file)
        );
        $controller->user = self::MEMBER;

        $response = $controller->report($this->post('/api/errors', [
            'kind' => 'client',
            'message' => 'Payload sah tetapi store gagal',
        ]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            ['code' => 500, 'msg' => 'Gagal menyimpan laporan error.'],
            json_decode($response->rawBody(), true)
        );
    }

    public function testReportRejectsInvalidPayloadWith422(): void
    {
        $controller = $this->controller(self::MEMBER);

        $response = $controller->report($this->post('/api/errors', ['kind' => 'client', 'message' => '']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, json_decode($response->rawBody(), true)['code'] ?? null);
        self::assertSame(0, $this->eventCount());
    }

    public function testReportRateLimitedWith429(): void
    {
        $this->useConfig(['rate_per_minute' => 1]);
        $controller = $this->controller(self::MEMBER);

        $first = $controller->report($this->post('/api/errors', ['kind' => 'client', 'message' => 'Satu']));
        $second = $controller->report($this->post('/api/errors', ['kind' => 'client', 'message' => 'Dua']));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame(429, json_decode($second->rawBody(), true)['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // CSRF wajib untuk semua mutasi
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string}>
     */
    public static function mutationPaths(): array
    {
        return [
            'report' => ['/api/errors'],
            'status' => ['/errors/abcdef0123456789/status'],
            'delete' => ['/errors/abcdef0123456789/delete'],
        ];
    }

    #[DataProvider('mutationPaths')]
    public function testMutationsWithoutCsrfTokenAreRejected(string $path): void
    {
        $handled = false;

        $response = (new CsrfMiddleware())->process(
            $this->post($path, []),
            static function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        self::assertFalse($handled, 'POST tanpa token tidak boleh sampai ke controller');
        self::assertSame(419, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // smoke render nyata (escaping)
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexRenderNyataMengEscapeNilaiBerbahaya(): void
    {
        (new AppStore($this->file))->create([
            'id' => 'app-1',
            'name' => '<b>Demo</b>',
            'owner_id' => self::ADMIN['id'],
        ]);
        $this->seedGroup("<script>alert('xss')</script>", ['app_id' => 'app-1']);

        $controller = $this->controller(self::ADMIN);
        $controller->renderReal = true;

        $response = $controller->index($this->get('/errors'));

        self::assertSame(200, $response->getStatusCode());
        $html = $response->rawBody();
        self::assertStringContainsString('Error Log', $html);
        self::assertStringNotContainsString("<script>alert('xss')</script>", $html);
        self::assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<b>Demo</b>', $html);
        self::assertStringContainsString('&lt;b&gt;Demo&lt;/b&gt;', $html);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?array $user): FakeErrorController
    {
        $db = new SqliteDatabase($this->file);
        $store = new ErrorStore($db);

        $controller = new FakeErrorController(
            new ErrorQuery($store),
            new ErrorReporter($store),
            new AppStore($this->file)
        );
        $controller->user = $user;

        return $controller;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function seedGroup(string $message, array $overrides = []): array
    {
        $event = $overrides + [
            'kind' => 'client',
            'message' => $message,
            'route' => '/apps/demo',
            'occurred_at' => date('c'),
        ];

        return $this->store()->record($event, hash('sha256', 'fp-' . $message));
    }

    private function store(): ErrorStore
    {
        return new ErrorStore(new SqliteDatabase($this->file));
    }

    private function eventCount(): int
    {
        return (int) (new SqliteDatabase($this->file))->pdo()
            ->query('SELECT COUNT(*) FROM "error_events"')
            ->fetchColumn();
    }

    /**
     * @param array<string,mixed>  $fields
     * @param array<string,string> $headers
     */
    private function post(string $path, array $fields, array $headers = []): Request
    {
        $body = http_build_query($fields);
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Accept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . $headerLines . "\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /**
     * @param array<string,mixed> $errorsOverride
     */
    private function useConfig(array $errorsOverride = []): void
    {
        $errors = $errorsOverride + [
            'retention_days' => 30,
            'max_events' => 5000,
            'max_samples_per_group' => 20,
            'max_payload_bytes' => 16384,
            'rate_per_minute' => 20,
            'max_message_chars' => 2000,
            'max_stack_bytes' => 8192,
            'per_page' => 50,
        ];

        $configDir = $this->tmp . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }

        file_put_contents($configDir . '/app.php', "<?php return [];\n");
        file_put_contents(
            $configDir . '/deploy.php',
            '<?php return ' . var_export([
                'mode' => 'dashboard',
                'sqlite_file' => $this->file,
                'sqlite_dir' => $this->tmp,
                'apps_path' => $this->tmp . '/apps',
                'app_domain' => 'example.org',
            ], true) . ';' . PHP_EOL
        );
        file_put_contents(
            $configDir . '/errors.php',
            '<?php return ' . var_export($errors, true) . ';' . PHP_EOL
        );

        Config::clear();
        Config::load($configDir);
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

/**
 * Subclass seam: user/flash/render tanpa sesi HTTP.
 */
class FakeErrorController extends ErrorController
{
    public ?array $user = null;

    /** @var list<array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array<string,mixed>}|null */
    public ?array $rendered = null;

    /** Render template asli (smoke escaping) alih-alih mengembalikan placeholder. */
    public bool $renderReal = false;

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }

    /**
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered = ['template' => $template, 'vars' => $vars];

        if (!$this->renderReal) {
            return new Response(200, [], 'rendered');
        }

        return new Response(200, [], $this->renderTemplate($template, $vars));
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderTemplate(string $template, array $vars): string
    {
        self::installStubs();

        $file = dirname(__DIR__) . '/app/view/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View tidak ditemukan: ' . $file);
        }

        extract($vars, EXTR_SKIP);

        set_error_handler(static function (int $no, string $message, string $source, int $line): bool {
            throw new \ErrorException($message, 0, $no, $source, $line);
        });

        ob_start();
        try {
            include $file;
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        } finally {
            restore_error_handler();
        }

        return (string) ob_get_clean();
    }

    private static function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return;
        }

        $stubs = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('e')) {
                function e(mixed $value): string
                {
                    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            if (!function_exists('csrf_token')) {
                function csrf_token(): string
                {
                    return 'smoke-csrf-token';
                }
            }
            if (!function_exists('csrf_field')) {
                function csrf_field(): string
                {
                    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
                }
            }
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return true;
                }
            }
            if (!function_exists('error_badge_count')) {
                function error_badge_count(): int
                {
                    return 1;
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                }
            }
            if (!function_exists('brand_name')) {
                function brand_name(): string
                {
                    return 'Rames';
                }
            }
            if (!function_exists('brand_logo_url')) {
                function brand_logo_url(): string
                {
                    return '/logo-small.png';
                }
            }
            if (!function_exists('update_badge')) {
                function update_badge(): array
                {
                    return ['available' => false];
                }
            }
            if (!function_exists('current_credit_balance')) {
                function current_credit_balance(): ?float
                {
                    return null;
                }
            }
            if (!function_exists('format_credits')) {
                function format_credits(float $credits, int $decimals = 2): string
                {
                    return number_format($credits, $decimals, ',', '.');
                }
            }
        }
        PHP;

        $file = sys_get_temp_dir() . '/rames-error-controller-stubs-' . getmypid() . '.php';
        file_put_contents($file, $stubs);
        require $file;
        @unlink($file);
    }
}
