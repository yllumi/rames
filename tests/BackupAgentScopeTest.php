<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Auth\AppAccessDenied;
use app\library\Backup\VolumeBackupService;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Cakupan server pada jalur **agent** `BackupController` (multi-server §9.3).
 *
 * Invarian yang dibuktikan di sini:
 *  1. server di luar jangkauan user **tidak pernah dihubungi** — bukan sekadar
 *     disembunyikan setelah `GET /backups/volumes` dikirim; member tanpa app
 *     terlihat ⇒ **nol** panggilan ke agent mana pun;
 *  2. non-admin hanya menerima target volume milik app yang boleh ia lihat
 *     (nama volume app lain tidak ikut berpindah ke respons/`findTarget`);
 *  3. admin tetap menghubungi setiap server agent dan melihat seluruh baris;
 *  4. 404 "bukan milik user" dan 404 "volume/server tidak dikenal" **tidak dapat
 *     dibedakan** pada konteks yang sama (status, tipe, dan isi respons sama);
 *  5. baris `volumes[]` `GET /api/backups/status` membawa `server_name` dan peta
 *     nama server dibaca **sekali** per respons (bukan kueri per baris).
 *
 * Seluruh uji tanpa jaringan/Docker: handler Guzzle tiruan, store di `/tmp`,
 * data runtime nyata tidak disentuh.
 */
class BackupAgentScopeTest extends TestCase
{
    private const APP_A = 'a1b2c3d4e5f60718';
    private const APP_B = 'b1c2d3e4f5061728';

    private const OWNER_A = ['id' => 'u1', 'username' => 'owner-a', 'role' => 'member'];
    private const VIEWER_A = ['id' => 'u3', 'username' => 'viewer-a', 'role' => 'member'];
    private const OUTSIDER = ['id' => 'u4', 'username' => 'tanpa-app', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_backupscopevis_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $this->tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        SqliteFixture::apps($this->tmp . '/rames.sqlite', [
            [
                'id' => self::APP_A,
                'name' => 'demo-a',
                'server_id' => 'srv-a',
                'status' => 'running',
                'owner_id' => 'u1',
                'members' => ['u3' => 'viewer'],
            ],
            [
                'id' => self::APP_B,
                'name' => 'demo-b',
                'server_id' => 'srv-b',
                'status' => 'running',
                'owner_id' => 'u2',
                'members' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) Member tanpa app terlihat ⇒ nol panggilan jaringan
    // ==================================================================

    public function testMemberWithoutAnyVisibleAppNeverContactsAnAgent(): void
    {
        $handlerA = new BackupAgentScopeFakeHandler();
        $handlerB = new BackupAgentScopeFakeHandler();
        $controller = $this->controller(self::OUTSIDER, ['srv-a' => $handlerA, 'srv-b' => $handlerB]);

        $this->assertSame([], $controller->targets(), 'tanpa app terlihat tidak ada target agent');
        $this->assertSame([], $controller->contacted, 'controller tidak boleh menyentuh agent mana pun');
        $this->assertSame(0, $handlerA->count());
        $this->assertSame(0, $handlerB->count());

        // Aksi apa pun (mis. tombol "Backup sekarang") juga tidak boleh membuka
        // sambungan ke server di luar jangkauan, dan tidak boleh menyebutnya.
        $response = $controller->run($this->post('/backups/run', ['volume' => 'demo-b_data'], true));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNoForeignServerLeak($response->rawBody());
        $this->assertSame([], $controller->contacted);
        $this->assertSame(0, $handlerB->count());
        $this->assertSame([], $controller->spawned);
    }

    // ==================================================================
    // (2) Non-admin: hanya server yang berhak, hanya volume app-nya
    // ==================================================================

    public function testMemberOnlyContactsTheServerThatHostsAVisibleApp(): void
    {
        $handlerA = new BackupAgentScopeFakeHandler();
        $handlerA->replyJson($this->volumesPayload([
            ['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A],
            ['name' => 'demo-b_data', 'project' => 'demo-b', 'app_id' => self::APP_B],
            ['name' => 'yatim_data', 'project' => 'hilang'],
        ]));
        $handlerB = new BackupAgentScopeFakeHandler();
        $handlerB->replyJson($this->volumesPayload([
            ['name' => 'demo-b_data', 'project' => 'demo-b', 'app_id' => self::APP_B],
        ]));

        $controller = $this->controller(self::OWNER_A, ['srv-a' => $handlerA, 'srv-b' => $handlerB]);
        $rows = $controller->targets();

        $this->assertSame(['srv-a'], $controller->contacted, 'hanya server app milik user yang dihubungi');
        $this->assertSame(1, $handlerA->count(), 'satu panggilan per server (GET /backups/volumes)');
        $this->assertSame(0, $handlerB->count(), 'server tanpa app user tidak dihubungi');
        $this->assertSame(['demo-a_data'], array_column($rows, 'name'), 'volume app lain/yatim bukan target user');
        $this->assertSame(['srv-a'], array_column($rows, 'server_id'));
    }

    public function testViewerIsScopeVisibleButStillGrantedNothingExtra(): void
    {
        $handlerA = $this->volumesHandlerWith([
            ['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A],
        ]);
        $handlerB = new BackupAgentScopeFakeHandler();

        $controller = $this->controller(self::VIEWER_A, ['srv-a' => $handlerA, 'srv-b' => $handlerB]);

        $this->assertSame(['demo-a_data'], array_column($controller->targets(), 'name'));
        $this->assertSame(['srv-a'], $controller->contacted);

        // Viewer boleh melihat target, tetapi aksi `backup` tetap ditolak 404
        // (satu pintu `BackupAccess` → `AppAccessDenied`), bukan 403.
        $request = $this->post('/backups/run', ['volume' => 'demo-a_data'], true);
        $response = $this->asFramework($request, fn () => $controller->run($request));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $controller->spawned, 'tidak ada efek samping sebelum otorisasi');
    }

    // ==================================================================
    // (3) Admin: semua server, semua baris
    // ==================================================================

    public function testAdminContactsEveryAgentAndSeesEveryRow(): void
    {
        $handlerA = new BackupAgentScopeFakeHandler();
        $handlerA->replyJson($this->volumesPayload([
            ['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A],
            ['name' => 'yatim_data', 'project' => 'hilang'],
        ]));
        $handlerB = new BackupAgentScopeFakeHandler();
        $handlerB->replyJson($this->volumesPayload([
            ['name' => 'demo-b_data', 'project' => 'demo-b', 'app_id' => self::APP_B],
        ]));

        $controller = $this->controller(self::ADMIN, ['srv-a' => $handlerA, 'srv-b' => $handlerB]);
        $rows = $controller->targets();

        $this->assertSame(['srv-a', 'srv-b'], $controller->contacted);
        $this->assertSame(1, $handlerA->count());
        $this->assertSame(1, $handlerB->count());
        $this->assertSame(
            ['demo-a_data', 'yatim_data', 'demo-b_data'],
            array_column($rows, 'name'),
            'admin melihat volume yatim (satu-satunya tempat yatim berada)'
        );
        $this->assertSame(['srv-a', 'srv-a', 'srv-b'], array_column($rows, 'server_id'));
    }

    // ==================================================================
    // (4) 404 tidak dapat dibedakan pada konteks yang sama
    // ==================================================================

    public function testDeniedAndUnknownAreIndistinguishableInBrowserContext(): void
    {
        $controller = $this->controller(self::VIEWER_A, [
            'srv-a' => $this->volumesHandlerWith([['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A]]),
        ]);
        $this->assertSame([], $controller->spawned);

        // (a) volume ada tetapi viewer tidak berhak `backup` ⇒ AppAccessDenied
        $reqDenied = $this->post('/backups/run', ['volume' => 'demo-a_data']);
        $denied = $this->asFramework($reqDenied, fn () => $controller->run($reqDenied));

        // (b) volume tidak dikenal ⇒ notFound()
        $reqUnknownVolume = $this->post('/backups/run', ['volume' => 'tidak-ada_data']);
        $unknownVolume = $this->asFramework($reqUnknownVolume, fn () => $controller->run($reqUnknownVolume));

        // (c) id server tidak dikenal ⇒ notFound()
        $reqUnknownServer = $this->post('/backups/run', ['volume' => 'demo-a_data', 'server_id' => 'srv-hilang']);
        $unknownServer = $this->asFramework($reqUnknownServer, fn () => $controller->run($reqUnknownServer));

        foreach ([$denied, $unknownVolume, $unknownServer] as $response) {
            $this->assertSame(404, $response->getStatusCode());
            $this->assertNotSame('', $response->rawBody(), '404 harus punya body (bukan respons kosong)');
        }

        $this->assertSame($denied->getHeader('Content-Type'), $unknownVolume->getHeader('Content-Type'));
        $this->assertSame($denied->getHeader('Content-Type'), $unknownServer->getHeader('Content-Type'));
        $this->assertSame($denied->rawBody(), $unknownVolume->rawBody(), 'bentuk 404 harus identik');
        $this->assertSame($denied->rawBody(), $unknownServer->rawBody(), 'bentuk 404 harus identik');
        $this->assertSame([], $controller->spawned, 'ditahan sebelum efek samping');
    }

    public function testDeniedAndUnknownAreIndistinguishableInJsonContext(): void
    {
        $controller = $this->controller(self::VIEWER_A, [
            'srv-a' => $this->volumesHandlerWith([['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A]]),
        ]);

        $reqDenied = $this->post('/backups/run', ['volume' => 'demo-a_data'], true);
        $denied = $this->asFramework($reqDenied, fn () => $controller->run($reqDenied));

        $reqUnknown = $this->post('/backups/run', ['volume' => 'tidak-ada_data'], true);
        $unknown = $this->asFramework($reqUnknown, fn () => $controller->run($reqUnknown));

        $this->assertSame(404, $denied->getStatusCode());
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame('{"code":404,"msg":"Not Found"}', $denied->rawBody());
        $this->assertSame($denied->rawBody(), $unknown->rawBody());
        $this->assertSame($denied->getHeader('Content-Type'), $unknown->getHeader('Content-Type'));
    }

    // ==================================================================
    // (5) `server_name` di `/api/backups/status`
    // ==================================================================

    public function testStatusRowsCarryServerNameAndTheMapIsReadOnce(): void
    {
        $service = new BackupAgentScopeFakeService([
            ['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A, 'orphaned' => false, 'server_id' => 'srv-a'],
            ['name' => 'demo-a_cache', 'project' => 'demo-a', 'app_id' => self::APP_A, 'orphaned' => false, 'server_id' => 'srv-a'],
            ['name' => 'demo-b_data', 'project' => 'demo-b', 'app_id' => self::APP_B, 'orphaned' => false, 'server_id' => 'srv-b'],
            ['name' => 'demo-a_data', 'project' => 'demo-a', 'app_id' => self::APP_A, 'orphaned' => false, 'server_id' => 'srv-c'],
        ]);

        $controller = $this->controller(self::ADMIN, [], $service);
        $response = $controller->status(new Request("GET /api/backups/status HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);

        $rows = $payload['data']['volumes'];
        $this->assertCount(4, $rows);
        $this->assertSame(
            ['srv-a', 'srv-a', 'srv-b', 'srv-c'],
            array_column($rows, 'server_id')
        );
        $this->assertSame(
            ['agent-a', 'agent-a', 'agent-b', 'srv-c'],
            array_column($rows, 'server_name'),
            'nama server dipetakan dari registry; id tak dikenal dipakai apa adanya'
        );
        $this->assertSame(1, $controller->serverNamesReads, 'peta nama dibaca sekali, bukan per baris');
    }

    public function testStatusLocalRowUsesLocalServerName(): void
    {
        $service = new BackupAgentScopeFakeService([
            ['name' => 'demo-b_data', 'project' => 'demo-b', 'app_id' => self::APP_B, 'orphaned' => false],
        ]);

        $controller = $this->controller(self::ADMIN, [], $service);
        $response = $controller->status(new Request("GET /api/backups/status HTTP/1.1\r\nHost: localhost\r\n\r\n"));
        $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('local', $payload['data']['volumes'][0]['server_name']);
        $this->assertSame(1, $controller->serverNamesReads);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,BackupAgentScopeFakeHandler> $handlers serverId ⇒ handler
     */
    private function controller(
        array $user,
        array $handlers,
        ?VolumeBackupService $service = null,
    ): BackupAgentScopeFakeController {
        return new BackupAgentScopeFakeController($user, $this->tmp, $handlers, $service);
    }

    /** @param array<int,array<string,mixed>> $volumes */
    private function volumesPayload(array $volumes): array
    {
        return ['code' => 0, 'data' => ['volumes' => $volumes, 'status' => []]];
    }

    /** @param array<int,array<string,mixed>> $volumes */
    private function volumesHandlerWith(array $volumes): BackupAgentScopeFakeHandler
    {
        return (new BackupAgentScopeFakeHandler())->replyJson($this->volumesPayload($volumes));
    }

    /**
     * Bukti tidak ada identitas (nama/host) server lain yang bocor ke respons.
     */
    private function assertNoForeignServerLeak(string $body): void
    {
        foreach (['srv-b', 'srv-b.internal', 'agent-b', 'demo-b_data'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, 'identitas server lain bocor ke respons');
        }
    }

    /** @param array<string,string> $body */
    private function post(string $path, array $body, bool $json = false): Request
    {
        $encoded = http_build_query($body);
        $accept = $json ? "Accept: application/json\r\n" : '';

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: rames.test\r\n" . $accept
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($encoded)
            . "\r\n\r\n" . $encoded
        );
    }

    /**
     * Jalankan aksi persis seperti framework: `AppAccessDenied` yang dilempar
     * mediator di-render lewat `render($request)` (`ExceptionHandler::render()`
     * melakukan hal yang sama), sehingga bentuk 404 dapat dibandingkan
     * apel-ke-apel.
     */
    private function asFramework(Request $request, callable $action): \Webman\Http\Response
    {
        try {
            $response = $action();
        } catch (AppAccessDenied $e) {
            $response = $e->render($request) ?? new \Webman\Http\Response(404);
        }

        $this->assertInstanceOf(\Webman\Http\Response::class, $response);

        return $response;
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

/**
 * Subclass seam: user login, registry server (2 agent), deployer agent per server
 * (klien HTTP tiruan), service backup, dan worker di-inject — mediator diuji tanpa
 * Docker/jaringan/proses nyata.
 */
final class BackupAgentScopeFakeController extends BackupController
{
    /** @var array<int,string> server id yang benar-benar dihubungi */
    public array $contacted = [];

    /** @var array<int,array<int,string>> */
    public array $spawned = [];

    public int $serverNamesReads = 0;

    private ?ServerResolver $resolver = null;

    /**
     * @param array<string,mixed>|null                  $user
     * @param array<string,BackupAgentScopeFakeHandler> $handlers
     */
    public function __construct(
        private ?array $user,
        private string $tmp,
        private array $handlers,
        private ?VolumeBackupService $service,
    ) {
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function isAdmin(): bool
    {
        return (string) ($this->user['role'] ?? '') === 'admin';
    }

    /** Jalur lokal (Engine host ini) bukan subjek uji — seluruh target dari agent. */
    protected function volumeTargets(): array
    {
        return $this->agentVolumeTargets((new AppStore($this->tmp . '/rames.sqlite'))->all());
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        $id = (string) ($server['id'] ?? '');
        $this->contacted[] = $id;

        $handler = $this->handlers[$id] ?? null;
        if ($handler === null) {
            return null;
        }

        return new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls')),
            30,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
            }
        );
    }

    protected function spawnWorker(array $args): bool
    {
        $this->spawned[] = $args;

        return true;
    }

    protected function backupService(): VolumeBackupService
    {
        return $this->service ?? new VolumeBackupService();
    }

    protected function serverResolver(): ServerResolver
    {
        if ($this->resolver === null) {
            $store = new ServerStore($this->tmp . '/servers-fake.sqlite');
            foreach ([['srv-a', 'agent-a', 'https://srv-a.internal:8443'], ['srv-b', 'agent-b', 'https://srv-b.internal:8443']] as [$id, $name, $base]) {
                $server = [
                    'id' => $id,
                    'name' => $name,
                    'kind' => 'agent',
                    'enabled' => true,
                    'domain' => $id . '.test',
                    'base_url' => $base,
                    'token' => 'tok-uji-' . $id,
                    'tls_cert_pem' => BackupAgentScopePem::selfSigned(),
                    'port_range' => ['start' => 30000, 'end' => 30999],
                ];
                try {
                    $store->create($server);
                } catch (\Throwable) {
                    $store->update($id, static function (array &$row) use ($server): void {
                        $row = array_merge($row, $server);
                    });
                }
            }
            ServerResolver::forgetMemo();
            $this->resolver = new ServerResolver($store);
        }

        return $this->resolver;
    }

    protected function serverNames(): array
    {
        $this->serverNamesReads++;

        return parent::serverNames();
    }

    /** @return array<int,array<string,mixed>> */
    public function targets(): array
    {
        return $this->volumeTargets();
    }
}

/** Handler Guzzle tiruan (tanpa jaringan) untuk `AgentClient`/`AgentDeployer`. */
final class BackupAgentScopeFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string}> */
    public array $calls = [];

    /** @var list<\GuzzleHttp\Psr7\Response|\Throwable> */
    private array $queue = [];

    /**
     * Respons **lengket**: respons terakhir diulang untuk panggilan berikutnya,
     * supaya jumlah panggilan tetap terekam (`count()`) tanpa antrean rapuh di
     * uji yang memanggil beberapa endpoint.
     */
    private ?\GuzzleHttp\Psr7\Response $last = null;

    public function __construct()
    {
        // Bawaan (tanpa antrean): daftar volume kosong — cukup untuk server yang
        // seharusnya **tidak** dihubungi; panggilan tak terduga tetap tercatat.
        $this->last = $this->response($this->volumesPayload([]));
    }

    /** @param array<string,mixed> $payload */
    public function replyJson(array $payload, int $status = 200): self
    {
        $response = $this->response($payload, $status);
        $this->queue[] = $response;
        $this->last = $response;

        return $this;
    }

    /** @param array<string,mixed> $payload */
    private function response(array $payload, int $status = 200): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload)
        );
    }

    /** @return array<string,mixed> */
    private function volumesPayload(array $volumes): array
    {
        return ['code' => 0, 'data' => ['volumes' => $volumes, 'status' => []]];
    }

    public function count(): int
    {
        return count($this->calls);
    }

    public function __invoke(object $request, array $options): mixed
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
        ];

        $next = $this->queue === [] ? $this->last : array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return \GuzzleHttp\Promise\Create::promiseFor($next);
    }
}

/**
 * Service backup tiruan: baris cache + `catalog()` dipasok langsung, overlay live
 * tidak menyentuh Engine, tanpa berkas cache/registry nyata.
 */
final class BackupAgentScopeFakeService extends VolumeBackupService
{
    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function isRunning(): bool
    {
        return false;
    }

    /** @return array{volumes:array<int,array<string,mixed>>,cached_at:?string} */
    public function catalog(): array
    {
        return ['volumes' => $this->rows, 'cached_at' => '2026-10-14T10:00:00+07:00'];
    }

    public function overlayLiveContainerStates(array $rows, int $timeoutSeconds = 5): array
    {
        return $rows;
    }

    public function archived(): array
    {
        return [];
    }
}

/** Sertifikat self-signed kecil untuk pinning `AgentClient` di uji. */
final class BackupAgentScopePem
{
    public static function selfSigned(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
