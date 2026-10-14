<?php
declare(strict_types=1);

namespace Tests;

use app\controller\NetworkController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`config()`, `json()`, `view()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Handler Guzzle tiruan untuk panggilan control plane → agent (tanpa jaringan).
 */
final class NetworkScopeFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string}> */
    public array $calls = [];

    /** @var list<array{status:int,body:string}> */
    private array $queue = [];

    public function replyJson(array $payload, int $status = 200): self
    {
        $this->queue[] = ['status' => $status, 'body' => (string) json_encode($payload)];

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return array{method:string,path:string,query:string,body:string} */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total {$this->count()})");
        }

        return $this->calls[$index];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $c): string => $c['method'] . ' ' . $c['path'], $this->calls);
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('NetworkScopeFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->queue);

        return Create::promiseFor(new Psr7Response($next['status'], ['Content-Type' => 'application/json'], $next['body']));
    }
}

/** Engine Docker host control plane tiruan (rekam / meledak). */
final class NetworkScopeLocalDocker extends DockerClient
{
    /** @var list<string> */
    public array $listed = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var array<int,array<string,mixed>> */
    public array $networks = [];

    public bool $explode = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function listNetworks(array $filters = []): array
    {
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }
        $this->listed[] = 'list';

        return $this->networks;
    }

    public function removeNetwork(string $id): void
    {
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH (uji gagal).');
        }
        $this->removed[] = $id;
    }
}

/**
 * `NetworkController` dengan seam uji (tanpa `database/`, `apps/`, sesi, atau
 * view HTTP nyata): store app, registry server, engine lokal, deployer agent,
 * dan renderer view disuntikkan.
 */
final class NetworkScopeController extends NetworkController
{
    /** @var list<array{template:string,vars:array<string,mixed>}> */
    public array $rendered = [];

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    public function __construct(
        private AppStore $apps,
        private ServerResolver $resolver,
        private DockerClient $docker,
        private ?AgentDeployer $agent,
        /** User dashboard uji; `null` ⇒ admin (perilaku uji lama). */
        private ?array $user = null,
    ) {
    }

    protected function appStore(): AppStore
    {
        return $this->apps;
    }

    protected function serverResolver(): ServerResolver
    {
        return $this->resolver;
    }

    protected function docker(): DockerClient
    {
        return $this->docker;
    }

    protected function agentFor(array $server): ?AgentDeployer
    {
        return $this->agent;
    }

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered[] = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered:' . $template);
    }

    protected function currentUser(): ?array
    {
        return $this->user ?? ['id' => 'u-admin', 'username' => 'admin', 'role' => AppAccess::ROLE_ADMIN];
    }

    protected function isAdmin(): bool
    {
        return (string) ($this->currentUser()['role'] ?? '') === AppAccess::ROLE_ADMIN;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }

    /** @return array<string,bool> */
    public function projectsForServerTest(string $serverId): array
    {
        return $this->activeProjects($serverId);
    }

    public function assertCrossServerTest(string $container, string $serverId): void
    {
        $this->assertContainerServer($container, $serverId);
    }

    /**
     * `NetworkController::serverOptions()` privat — dibuka lewat refleksi supaya
     * permukaan produksi tidak berubah hanya demi uji.
     *
     * @return array<int,array{id:string,name:string}>
     */
    public function serverOptionsTest(): array
    {
        $method = new \ReflectionMethod(NetworkController::class, 'serverOptions');
        $method->setAccessible(true);

        return $method->invoke($this);
    }
}

/**
 * Cakupan **per server** untuk network (F3c multi-server, plan §5).
 *
 * Yang dikunci:
 *  1. halaman/aksi network server agent membaca & mengubah Engine **server itu**
 *     (daemon host control plane tidak pernah tersentuh untuk app remote);
 *  2. tanpa parameter `server`, perilaku lama (server lokal) tidak berubah;
 *  3. shared network hanya bermakna dalam satu server: app di server lain tidak
 *     dihitung sebagai pemilik/pemakai, dan connect container lintas server
 *     ditolak dengan pesan jelas.
 *
 * `flash_set()`/`current_user()` global di-stub (pola
 * `AppControllerAgentHostIsolationTest`) supaya controller bisa dipanggil tanpa
 * runtime webman; proses dipisah agar stub tidak bocor ke uji lain.
 */
final class NetworkServerScopeTest extends TestCase
{
    private const TOKEN = 'tok-agent-uji-f3c-9f8e7d';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-netscope-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException('gagal membuat temp dir');
        }

    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (i)/(ii) daftar network per server
    // ==================================================================

    public function testAgentServerIndexReadsNetworksFromAgentAndNeverTouchesLocalDaemon(): void
    {
        $handler = new NetworkScopeFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => ['networks' => [
            ['Id' => 'net-1', 'Name' => 'agent-shared', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []],
        ]]]);

        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);
        $response = $controller->index($this->get('/networks?server=srv-agent-2'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['GET /networks'], $handler->paths());
        $this->assertSame('network/index', $controller->rendered[0]['template']);
        $rows = $controller->rendered[0]['vars']['rows'];
        $this->assertSame(['agent-shared'], array_column($rows, 'name'));
        $this->assertSame('srv-agent-2', $controller->rendered[0]['vars']['serverId']);
        $this->assertSame([], $docker->listed, 'daemon host control plane tidak boleh dibaca untuk server agent');
    }

    public function testLocalScopeKeepsPreviousBehaviourAndNeverCallsAgent(): void
    {
        $handler = new NetworkScopeFakeHandler(); // tidak boleh dipakai
        $docker = new NetworkScopeLocalDocker();
        $docker->networks = [
            ['Id' => 'net-local', 'Name' => 'lokal-shared', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []],
        ];

        $controller = $this->controller($handler, $docker);
        $controller->index($this->get('/networks'));

        $rows = $controller->rendered[0]['vars']['rows'];
        $this->assertSame(['lokal-shared'], array_column($rows, 'name'));
        $this->assertSame('', $controller->rendered[0]['vars']['serverId']);
        $this->assertSame(0, $handler->count(), 'halaman lokal tidak boleh memanggil agent');
        $this->assertSame(['list'], $docker->listed);
    }

    public function testUnknownServerScopeFailsClosedWithoutListingLocalNetworks(): void
    {
        $handler = new NetworkScopeFakeHandler();

        $docker = new NetworkScopeLocalDocker();
        $docker->networks = [['Id' => 'n', 'Name' => 'lokal', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []]];
        $docker->explode = true; // daftar lokal TIDAK boleh ditampilkan sebagai pengganti

        $controller = $this->controller($handler, $docker);

        try {
            $controller->index($this->get('/networks?server=srv-hilang'));
            $this->fail('server tak dikenal wajib ditolak (404), bukan dirender 200 + pesan pembeda');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode(), 'tak dikenal ⇒ 404, bukan 200 dan bukan 403');
            $this->assertSame(ServerAccess::ABILITY_SELECT, $e->ability, 'alasan sama dengan "server bukan milik Anda"');
        }

        $this->assertSame([], $controller->rendered, 'tidak ada halaman (beserta pesan "Server tidak dikenal") yang dirender');
        $this->assertSame([], $controller->flashes, 'tidak ada pesan pembeda di flash');
        $this->assertSame([], $docker->listed, 'daemon lokal tidak dipakai sebagai pengganti');
        $this->assertSame(0, $handler->count());
    }

    /**
     * **Oracle keberadaan server (keputusan §12)**: id tak dikenal wajib
     * **identik** dengan id yang ada-tapi-bukan-milik-user — kelas pengecualian,
     * kode, ability, dan pesan. Selisih 200/404 di antara keduanya memberi tahu
     * penyerang id mana yang benar-benar ada di registry.
     *
     * @return array<string,array{0:?array}>
     */
    public static function adminAndMemberUsers(): array
    {
        return [
            'admin' => [null],
            'member' => [['id' => 'u-member', 'username' => 'budi', 'role' => 'member']],
        ];
    }

    /**
     * Higienitas pesan galat: galat transport `AgentClient` menyisipkan
     * `base_url` server, jadi controller wajib meredaksinya lewat satu pintu
     * (`AgentError::sanitizeForServer()` — bukan regex yang disalin per controller).
     */
    public function testAgentScopeErrorMessagesNeverLeakTokenOrBaseUrl(): void
    {
        $handler = new NetworkScopeFakeHandler(); // antrean kosong ⇒ galat transport
        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);
        $controller->index($this->get('/networks?server=srv-agent-2'));

        $engineError = (string) ($controller->rendered[0]['vars']['engineError'] ?? '');
        $this->assertStringContainsString('agent-2', $engineError, 'nama server tetap membantu diagnosis');
        foreach ([self::TOKEN, 'agent-2.test', 'https://'] as $secret) {
            $this->assertStringNotContainsString($secret, $engineError, 'rahasia/endpoint internal bocor: ' . $secret);
        }
    }

    /**
     * Daftar **dan** semua mutasi (`create`/`connect`/`disconnect`/`delete`)
     * menolak id tak dikenal dengan 404 sebelum efek samping apa pun — untuk
     * admin maupun non-admin.
     *
     * @dataProvider adminAndMemberUsers
     */
    public function testUnknownServerIdIsDeniedLikeAForbiddenServerOnListAndMutations(?array $user): void
    {
        $handler = new NetworkScopeFakeHandler();
        $docker = new NetworkScopeLocalDocker();
        $docker->networks = [['Id' => 'n', 'Name' => 'lokal', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []]];
        $docker->explode = true; // server lokal (= pengganti yang dihindari) tidak boleh dipakai

        $controller = $this->controller($handler, $docker, $user);

        $calls = [
            'index' => fn () => $controller->index($this->get('/networks?server=srv-hilang')),
            'detail' => fn () => $controller->detail($this->get('/networks/net-1?server=srv-hilang'), 'net-1'),
            'create' => fn () => $controller->create($this->post('/networks/create', 'server=srv-hilang&name=baru')),
            'connect' => fn () => $controller->connect($this->post('/networks/net-1/connect', 'server=srv-hilang&container=demo-db-1'), 'net-1'),
            'disconnect' => fn () => $controller->disconnect($this->post('/networks/net-1/disconnect', 'server=srv-hilang&container=demo-db-1'), 'net-1'),
            'delete' => fn () => $controller->delete($this->post('/networks/net-1/delete', 'server=srv-hilang'), 'net-1'),
        ];

        foreach ($calls as $action => $call) {
            try {
                $call();
                $this->fail("{$action}: id server tak dikenal wajib 404 (bukan 200 dengan pesan pembeda)");
            } catch (AppAccessDenied $e) {
                $this->assertSame(404, $e->getCode(), "{$action}: 404 — bukan 403, bukan 200");
                $this->assertSame(ServerAccess::ABILITY_SELECT, $e->ability, "{$action}: alasan sama seperti server milik orang lain");
            }
        }

        $this->assertSame([], $controller->rendered, 'tidak ada halaman untuk server tak dikenal');
        $this->assertSame([], $controller->flashes, 'tidak ada pesan yang membedakan tak dikenal dari tidak berhak');
        $this->assertSame(0, $handler->count(), 'tidak ada operasi agent/daemon di server itu');
        $this->assertSame([], $docker->listed, 'daemon lokal tidak dibaca');
        $this->assertSame([], $docker->removed, 'tidak ada penghapusan network');
    }

    /** Tak dikenal vs bukan milik Anda: penolakan yang tak bisa dibedakan non-admin. */
    public function testUnknownIdAndForeignIdAreIndistinguishableForNonAdmin(): void
    {
        $seen = [];

        foreach (['srv-agent-2', 'srv-hilang'] as $id) {
            $controller = $this->controller(
                new NetworkScopeFakeHandler(),
                new NetworkScopeLocalDocker(),
                $this->member(),
                [],
                'oracle-' . $id
            );

            try {
                $controller->index($this->get('/networks?server=' . $id));
                $this->fail("{$id}: wajib ditolak 404");
            } catch (AppAccessDenied $e) {
                $seen[$id] = [$e->getCode(), $e->ability, $e->getMessage()];
            }
        }

        $this->assertNotEmpty($seen);
        $this->assertSame(
            $seen['srv-agent-2'],
            $seen['srv-hilang'],
            'id tak dikenal ≡ id milik orang lain (tanpa selisih yang bisa jadi oracle)'
        );
    }

    // ==================================================================
    // (vi) Pemilihan server = admin-only (keputusan §9.3): non-admin hanya
    //      server yang menaungi app yang boleh ia lihat, selain itu 404.
    // ==================================================================

    public function testNonAdminRequestingForeignServerIsDeniedWith404AndNoSideEffects(): void
    {
        $handler = new NetworkScopeFakeHandler(); // tidak boleh dipakai
        $docker = new NetworkScopeLocalDocker();
        $docker->networks = [['Id' => 'n', 'Name' => 'lokal', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []]];
        $docker->explode = true; // server lokal TIDAK boleh jadi pengganti

        $controller = $this->controller($handler, $docker, $this->member());

        try {
            $controller->index($this->get('/networks?server=srv-agent-2'));
            $this->fail('server di luar jangkauan user harus ditolak (fail-closed)');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode(), 'penolakan 404 — bukan 403 (keberadaan server tidak bocor)');
            $this->assertSame(ServerAccess::ABILITY_SELECT, $e->ability);
        }

        $this->assertSame([], $controller->rendered, 'halaman tidak dirender dengan data server orang lain');
        $this->assertSame(0, $handler->count(), 'tidak ada operasi network di server itu');
        $this->assertSame([], $docker->listed, 'daemon lokal tidak dipakai sebagai pengganti');
    }

    public function testNonAdminMutationOnForeignServerIsDeniedWithoutExecutingAnything(): void
    {
        $handler = new NetworkScopeFakeHandler();
        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker, $this->member());

        foreach (['delete', 'disconnect', 'connect'] as $action) {
            try {
                $controller->{$action}(
                    $this->post('/networks/net-1/' . $action, 'server=srv-agent-2&container=demo-db-1'),
                    'net-1'
                );
                $this->fail("{$action} di server orang lain harus ditolak sebelum efek samping");
            } catch (AppAccessDenied $e) {
                $this->assertSame(404, $e->getCode());
            }
        }

        $this->assertSame(0, $handler->count(), 'tidak ada operasi network di server itu');
        $this->assertSame([], $docker->listed);
        $this->assertSame([], $controller->flashes, 'penolakan bukan flash pesan yang membocorkan keberadaan server');
    }

    public function testNonAdminKeepsAccessToServerHostingTheirVisibleApp(): void
    {
        $handler = new NetworkScopeFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => ['networks' => []]]);

        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller(
            $handler,
            $docker,
            $this->member(),
            [$this->memberAppOnAgent()]
        );

        $response = $controller->index($this->get('/networks?server=srv-agent-2'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('srv-agent-2', $controller->rendered[0]['vars']['serverId']);
        $this->assertSame(['GET /networks'], $handler->paths(), 'network dibaca di server itu (bukan daemon lokal)');
        $this->assertSame([], $docker->listed);
    }

    public function testServerOptionsAreFilteredPerUserOnTheServer(): void
    {
        // Admin: seluruh registry (tanpa token/PEM — hanya id + name).
        $admin = $this->controller(new NetworkScopeFakeHandler(), new NetworkScopeLocalDocker(), null, [], 'opt-admin');
        $adminOptions = $admin->serverOptionsTest();
        $this->assertSame(['local', 'srv-agent-2'], array_column($adminOptions, 'id'));
        $this->assertSame(['id', 'name'], array_keys($adminOptions[0]));

        // Member tanpa app terlihat: daftar kosong (fail-closed, bukan semua server).
        $none = $this->controller(
            new NetworkScopeFakeHandler(),
            new NetworkScopeLocalDocker(),
            $this->member(),
            [],
            'opt-none'
        );
        $this->assertSame([], $none->serverOptionsTest());

        // Member dengan app di server agent: hanya server itu yang terlihat.
        $member = $this->controller(
            new NetworkScopeFakeHandler(),
            new NetworkScopeLocalDocker(),
            $this->member(),
            [$this->memberAppOnAgent()],
            'opt-member'
        );
        $this->assertSame(['srv-agent-2'], array_column($member->serverOptionsTest(), 'id'));
    }

    // ==================================================================
    // (v) shared network hanya dalam satu server
    // ==================================================================

    public function testCrossServerConnectIsRejectedWithClearMessageAndNoSideEffects(): void
    {
        $handler = new NetworkScopeFakeHandler();
        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);

        // Container milik app di server agent, network di server lokal.
        try {
            $controller->assertCrossServerTest('demo-db-1', '');
            $this->fail('connect lintas server harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Shared network hanya berlaku dalam satu server', $e->getMessage());
            $this->assertStringContainsString('demo', $e->getMessage());
            $this->assertStringContainsString('server "vps-jakarta-2"', $e->getMessage());
        }

        // Arah sebaliknya: network server agent, container app lokal.
        try {
            $controller->assertCrossServerTest('lokal-web-1', 'srv-agent-2');
            $this->fail('connect lintas server harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Shared network hanya berlaku dalam satu server', $e->getMessage());
            $this->assertStringContainsString('server lokal', $e->getMessage());
        }

        // Container eksternal (tanpa app) tidak dibatasi guard ini.
        $controller->assertCrossServerTest('container-manual', 'srv-agent-2');
        $this->assertSame(0, $handler->count());
        $this->assertSame([], $docker->listed);

        // End-to-end aksi connect: pesan flash jelas & tidak ada efek samping.
        $controller->connect($this->post('/networks/net-1/connect', 'container=demo-db-1'), 'net-1');
        $this->assertSame('error', $controller->flashes[0]['type'] ?? '');
        $this->assertStringContainsString(
            'Shared network hanya berlaku dalam satu server',
            (string) ($controller->flashes[0]['message'] ?? '')
        );
        $this->assertSame(0, $handler->count(), 'tidak ada panggilan agent untuk koneksi lintas server');
        $this->assertSame([], $docker->listed);
    }

    public function testConnectOnAgentServerIsForwardedToTheAgent(): void
    {
        $handler = new NetworkScopeFakeHandler();
        $handler->replyJson(['code' => 0]);

        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);
        $controller->connect(
            $this->post('/networks/net-1/connect', 'server=srv-agent-2&container=demo-db-1&alias=db'),
            'net-1'
        );

        $this->assertSame(['POST /networks/net-1/connect'], $handler->paths());
        $body = json_decode($handler->call(0)['body'], true);
        $this->assertSame('demo-db-1', $body['container'] ?? null);
        $this->assertSame('db', $body['alias'] ?? null);
        $this->assertSame('success', $controller->flashes[0]['type'] ?? '');
        $this->assertSame([], $docker->listed);
    }

    public function testActiveProjectsAreScopedPerServer(): void
    {
        $controller = $this->controller(new NetworkScopeFakeHandler(), new NetworkScopeLocalDocker());

        $this->assertSame(['demo' => true], $controller->projectsForServerTest('srv-agent-2'));
        $this->assertSame(['lokal' => true], $controller->projectsForServerTest(''));
        $this->assertSame([], $controller->projectsForServerTest('srv-lain'));
    }

    // ==================================================================
    // Hapus network di server agent
    // ==================================================================

    public function testDeleteOnAgentServerIsExecutedAtTheAgent(): void
    {
        $handler = new NetworkScopeFakeHandler();
        // 1) daftar network server agent (tanpa container, tanpa label compose).
        $handler->replyJson(['code' => 0, 'data' => ['networks' => [
            ['Id' => 'net-1', 'Name' => 'agent-bebas', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []],
        ]]]);
        // 2) hasil penghapusan di agent.
        $handler->replyJson(['code' => 0]);

        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);
        $controller->delete($this->post('/networks/agent-bebas/delete', 'server=srv-agent-2'), 'agent-bebas');

        $this->assertSame(['GET /networks', 'POST /networks/agent-bebas/delete'], $handler->paths());
        $this->assertSame([], $docker->removed, 'penghapusan tidak boleh dijalankan di host control plane');
    }

    public function testDeleteKeepsProtectionsForNetworkManagedByAppOnSameServer(): void
    {
        $handler = new NetworkScopeFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => ['networks' => [
            [
                'Id' => 'net-2',
                'Name' => 'demo_default',
                'Driver' => 'bridge',
                'Scope' => 'local',
                'Containers' => [],
                'Labels' => ['com.docker.compose.project' => 'demo'],
            ],
        ]]]);

        $docker = new NetworkScopeLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);
        $controller->delete($this->post('/networks/demo_default/delete', 'server=srv-agent-2'), 'demo_default');

        // Hanya daftar yang dibaca — tidak ada penghapusan (dilindungi).
        $this->assertSame(['GET /networks'], $handler->paths());
        $this->assertSame([], $docker->removed);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(
        NetworkScopeFakeHandler $handler,
        DockerClient $docker,
        ?array $user = null,
        array $extraApps = [],
        string $storeKey = 'default'
    ): NetworkScopeController {
        $apps = new AppStore($this->tmp . '/' . $storeKey . '-apps.sqlite');
        $apps->create([
            'id' => 'app-demo',
            'name' => 'demo',
            'server_id' => 'srv-agent-2',
            'owner_id' => 'u-admin',
            'containers' => [['service_name' => 'db', 'container_name' => 'demo-db-1']],
        ]);
        $apps->create([
            'id' => 'app-lokal',
            'name' => 'lokal',
            'server_id' => ServerStore::LOCAL_ID,
            'owner_id' => 'u-admin',
            'containers' => [['service_name' => 'web', 'container_name' => 'lokal-web-1']],
        ]);

        foreach ($extraApps as $extraApp) {
            $apps->create($extraApp);
        }

        $store = new ServerStore($this->tmp . '/' . $storeKey . '-servers.sqlite');
        $store->create([
            'id' => 'srv-agent-2',
            'name' => 'vps-jakarta-2',
            'kind' => 'agent',
            'base_url' => 'https://agent-2.test:8443',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::selfSignedPem(),
            'enabled' => true,
        ]);

        $resolver = new ServerResolver($store);

        $server = $resolver->resolve('srv-agent-2');
        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'));
        $agent = new AgentDeployer($server, $client);

        return new NetworkScopeController($apps, $resolver, $docker, $agent, $user);
    }

    /** @return array<string,mixed> */
    private function member(): array
    {
        return ['id' => 'u-member', 'username' => 'budi', 'role' => 'member'];
    }

    /** App milik member di server agent — dasar izin "pilih server". */
    private function memberAppOnAgent(): array
    {
        return [
            'id' => 'app-anggota',
            'name' => 'anggota',
            'server_id' => 'srv-agent-2',
            'owner_id' => 'u-member',
        ];
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n");
    }

    private function post(string $path, string $body = ''): Request
    {
        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /** Sertifikat self-signed sekali pakai (pola `AgentClientTest`). */
    private static function selfSignedPem(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $out = '';
        openssl_x509_export($cert, $out);
        $pem = $out;

        return $pem;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path => $info) {
            $path = (string) $path;
            if ($info->isDir() && !is_link($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

}
