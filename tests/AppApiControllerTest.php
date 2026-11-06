<?php
declare(strict_types=1);

namespace Tests;

use app\controller\api\AppApiController;
use app\library\Api\ApiTokenAuth;
use app\library\Auth\UserStore;
use app\library\Deploy\AppBuildService;
use app\library\Deploy\DeploySpawner;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Tests\Support\StoreDbFiles;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Endpoint API eksternal fase awal (docs/SPECS.md §7.19):
 * `POST /api/v1/apps/{slug}/build` dan `GET /api/v1/apps/{slug}`.
 *
 * Yang diuji adalah **kontrak respons** controller (middleware token diuji
 * terpisah): 202 + payload build, 404 tanpa oracle untuk app tak terlihat,
 * 409 untuk nama ambigu, 401 tanpa aktor, dan payload status tanpa field
 * rahasia. Seluruh store memakai berkas temp (tidak menyentuh data runtime).
 */
class AppApiControllerTest extends TestCase
{
    private const TOKEN = 'api_token_rahasia_0123456789';

    private string $db = '';

    private string $root = '';

    private UserStore $users;

    private AppStore $apps;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->db = sys_get_temp_dir() . '/rames-apicontroller-' . $suffix . '.sqlite';
        $this->root = sys_get_temp_dir() . '/rames-apicontroller-' . $suffix;
        if (!mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            throw new RuntimeException('gagal membuat direktori temp: ' . $this->root);
        }
        // Gerbang kredit base controller membaca config + store default: arahkan
        // ke berkas temp supaya tes tidak menyentuh data runtime nyata.
        $this->configState = $this->snapshotConfigState();
        $this->configureDeploy();
        $this->users = new UserStore($this->db);
        $this->users->create('admin', 'rahasia-admin-1');
        $this->users->create('member', 'rahasia-member-1');
        $this->users->create('stranger', 'rahasia-stranger-1');
        $this->apps = new AppStore($this->db);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        StoreDbFiles::remove($this->db);
        @unlink($this->root . '/config/app.php');
        @unlink($this->root . '/config/deploy.php');
        @rmdir($this->root . '/config');
        @rmdir($this->root . '/demo');
        @rmdir($this->root . '/dup');
        @rmdir($this->root);
    }

    public function testBuildAcceptsAndStartsWorker(): void
    {
        $app = $this->createApp('demo', 'owner-other');
        mkdir($this->root . '/demo');

        $response = $this->controller('admin')->build($this->request('POST', '/api/v1/apps/demo/build'), 'demo');
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(0, $payload['code']);
        $this->assertSame('demo', $payload['data']['slug']);
        $this->assertSame($app['id'], $payload['data']['id']);
        $this->assertSame('deploying', $payload['data']['status']);
        $this->assertSame('/api/v1/apps/demo', $payload['data']['poll_url']);
        $this->assertSame('deploying', $this->apps->find($app['id'])['status']);
    }

    public function testBuildOfVisibleAppWithDifferentCaseSucceeds(): void
    {
        $this->createApp('demo', 'owner-other');
        mkdir($this->root . '/demo');

        $response = $this->controller('admin')->build($this->request('POST', '/api/v1/apps/DEMO/build'), 'DEMO');

        $this->assertSame(202, $response->getStatusCode());
    }

    public function testUnknownAppReturns404(): void
    {
        $response = $this->controller('admin')->build($this->request('POST', '/api/v1/apps/hantu/build'), 'hantu');
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(404, $payload['code']);
        $this->assertSame('not_found', $payload['reason']);
    }

    public function testAppOfOtherUserIs404NotForbidden(): void
    {
        $this->createApp('demo', 'someone-else');

        $own = $this->controller('stranger')->show($this->request('GET', '/api/v1/apps/demo'), 'demo');
        $missing = $this->controller('stranger')->show($this->request('GET', '/api/v1/apps/hantu'), 'hantu');

        $this->assertSame(404, $own->getStatusCode(), 'app milik user lain tidak boleh terlihat');
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame(
            json_decode($missing->rawBody(), true),
            json_decode($own->rawBody(), true),
            'respons "tidak berhak" wajib identik dengan "tidak ada" (tanpa oracle)'
        );
    }

    public function testMissingTokenReturns401(): void
    {
        $this->createApp('demo', 'someone-else');

        $response = $this->controller('admin')->build(
            $this->request('POST', '/api/v1/apps/demo/build', null),
            'demo'
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('unauthorized', json_decode($response->rawBody(), true)['reason']);
    }

    public function testAmbiguousSlugReturns409(): void
    {
        $this->createApp('dup', 'someone-else');
        $this->apps->create([
            'name' => 'dup',
            'owner_id' => 'someone-else',
            'status' => 'running',
            'server_id' => 'server-agent-1',
        ]);

        $response = $this->controller('admin')->build($this->request('POST', '/api/v1/apps/dup/build'), 'dup');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('ambiguous_app', json_decode($response->rawBody(), true)['reason']);
    }

    public function testDisabledApiReturns404EvenWithoutMiddleware(): void
    {
        $this->createApp('demo', 'someone-else');
        mkdir($this->root . '/demo');

        // Token kosong ⇒ API mati. Middleware sudah menjawab 404; controller
        // wajib fail-closed juga (defense-in-depth).
        $controller = $this->controller('admin', '');
        $response = $controller->build($this->request('POST', '/api/v1/apps/demo/build'), 'demo');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', json_decode($response->rawBody(), true)['reason']);
        $this->assertSame('running', $this->apps->findByName('demo')['status']);
    }

    public function testViewerCannotBuild(): void
    {
        $member = $this->users->findByUsername('member');
        $this->apps->create([
            'name' => 'demo',
            'owner_id' => 'owner-other',
            'status' => 'running',
            'members' => [(string) $member['id'] => ['role' => 'viewer']],
        ]);
        mkdir($this->root . '/demo');

        $response = $this->controller('member')->build($this->request('POST', '/api/v1/apps/demo/build'), 'demo');

        $this->assertSame(404, $response->getStatusCode(), 'viewer tidak punya ability deploy ⇒ 404');
        $this->assertSame('running', $this->apps->findByName('demo')['status']);
    }

    public function testInsufficientCreditsReturns402(): void
    {
        $this->configureDeploy(true);
        $member = $this->users->findByUsername('member');
        $this->apps->create([
            'name' => 'demo',
            'owner_id' => 'owner-other',
            'status' => 'running',
            'limits' => ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]],
            'members' => [(string) $member['id'] => ['role' => 'operator']],
        ]);
        mkdir($this->root . '/demo');

        $response = $this->controller('member')->build($this->request('POST', '/api/v1/apps/demo/build'), 'demo');

        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame('insufficient_credits', json_decode($response->rawBody(), true)['reason']);
        $this->assertSame('running', $this->apps->findByName('demo')['status'], 'kredit kurang tidak boleh mengubah status');
    }

    public function testShowPayloadIsWhitelisted(): void
    {
        $app = $this->apps->create([
            'name' => 'demo',
            'owner_id' => 'someone-else',
            'status' => 'running',
            'env' => ['APP_KEY' => 'rahasia-sekali'],
            'ssh_key' => 'keys/demo',
            'containers' => [[
                'service_name' => 'web',
                'container_name' => 'demo',
                'status' => 'running',
                'ports' => [['host' => 30000, 'container' => 8787]],
            ]],
            'deploy_history' => [[
                'sha' => 'abcdef1234',
                'short' => 'abcdef1',
                'action' => 'rebuild',
                'status' => 'success',
                'message' => '',
                'created_at' => '2026-09-16T14:44:33+07:00',
            ]],
        ]);

        $response = $this->controller('admin')->show($this->request('GET', '/api/v1/apps/demo'), 'demo');
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($app['id'], $payload['data']['id']);
        $this->assertSame('running', $payload['data']['status']);
        $this->assertSame('web', $payload['data']['containers'][0]['service']);
        $this->assertSame(30000, $payload['data']['containers'][0]['ports'][0]['host']);
        $this->assertSame('rebuild', $payload['data']['last_deploy']['action']);
        $this->assertArrayNotHasKey('env', $payload['data']);
        $this->assertArrayNotHasKey('ssh_key', $payload['data']);
        $this->assertStringNotContainsString('rahasia-sekali', $response->rawBody());
    }

    private function controller(string $actorUsername, string $token = self::TOKEN): AppApiController
    {
        $launcher = new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []): bool {
                $pid = 4321;

                return true;
            },
            $this->root . '/cli/deploy.php',
            $this->root . '/logs'
        );
        $service = new AppBuildService(
            $this->apps,
            new DeploySpawner($launcher, $this->apps),
            $this->root,
            new ServerResolver(new ServerStore($this->db))
        );

        return new AppApiController(
            $this->apps,
            $service,
            new ApiTokenAuth($token, $actorUsername, $this->users)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function createApp(string $name, string $ownerId): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => $ownerId,
            'status' => 'running',
            'compose_files' => ['docker-compose.yml'],
        ]);
    }

    private function request(string $method, string $path, ?string $token = self::TOKEN): Request
    {
        $headers = "Host: localhost\r\nAccept: application/json\r\n";
        if ($token !== null) {
            $headers .= 'Authorization: Bearer ' . $token . "\r\n";
        }

        return new Request($method . ' ' . $path . " HTTP/1.1\r\n" . $headers . "\r\n");
    }

    /**
     * Config minimal untuk base controller: SQLite temp + billing off.
     * `billing_enabled=false` menjaga tes tidak membaca saldo store nyata
     * (aktor tes tetap admin, yang juga exempt).
     */
    private function configureDeploy(bool $billingEnabled = false): void
    {
        $dir = $this->root . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents(
            $dir . '/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->db,
                'apps_path' => $this->root,
                'billing_enabled' => $billingEnabled,
            ], true) . ';' . PHP_EOL
        );
        Config::clear();
        Config::load($dir);
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
}
