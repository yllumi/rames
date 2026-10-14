<?php
declare(strict_types=1);

namespace Tests;

use app\controller\VolumeController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Auth\ServerAccess;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`config()`, `json()`, `redirect()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Handler Guzzle tiruan untuk panggilan control plane → agent (tanpa jaringan).
 *
 * Mencatat setiap panggilan supaya tes bisa membuktikan agent yang **tidak
 * berhak** memang tidak pernah dihubungi.
 */
final class VolumeScopeAgentHandler
{
    /** @var array<int,array{method:string,path:string,query:string}> */
    public array $calls = [];

    /** @var array<string,array<int,string>> nama volume per project (`?project=`) */
    public array $volumesByProject = [];

    /** true ⇒ semua panggilan gagal (server agent tak terjangkau). */
    public bool $offline = false;

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->offline) {
            throw new RuntimeException('Connection refused (server agent offline)');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $query = $request->getUri()->getQuery();
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $query,
        ];

        parse_str($query, $params);
        $project = (string) ($params['project'] ?? '');

        $payload = json_encode([
            'code' => 0,
            'data' => ['volumes' => $this->volumesByProject[$project] ?? []],
        ]);

        return Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'application/json'], (string) $payload));
    }

    /** @return list<string> daftar project yang diminta (`?project=`) */
    public function requestedProjects(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            parse_str($call['query'], $params);
            if (isset($params['project'])) {
                $out[] = (string) $params['project'];
            }
        }

        return $out;
    }
}

/** Engine Docker host control plane tiruan (tanpa socket nyata). */
final class VolumeScopeDocker extends DockerClient
{
    /** @var array<int,array<string,mixed>> */
    public array $volumes = [];

    /** @var array<string,mixed> */
    public array $diskUsage = ['Volumes' => []];

    public bool $explode = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function listVolumes(array $filters = []): array
    {
        if ($this->explode) {
            throw new RuntimeException('Engine lokal tak tersedia');
        }

        return $this->volumes;
    }

    public function getDiskUsage(array $types = ['volume']): array
    {
        if ($this->explode) {
            throw new RuntimeException('Engine lokal tak tersedia');
        }

        return $this->diskUsage;
    }
}

/** Orkestrasi CLI compose tiruan — mencatat volume yang diminta dihapus. */
final class VolumeScopeComposeRunner extends DockerComposeRunner
{
    /** @var list<string> */
    public array $removed = [];

    public bool $explode = false;

    public function __construct()
    {
    }

    public function removeVolumes(array $names): void
    {
        if ($this->explode) {
            throw new RuntimeException('docker compose gagal');
        }

        foreach ($names as $name) {
            $this->removed[] = (string) $name;
        }
    }
}

/**
 * `VolumeController` dengan seam uji: store app, registry server, engine lokal,
 * deployer agent, runner compose, dan renderer view disuntikkan (tanpa
 * `database/` nyata, Docker, jaringan, atau HTTP).
 */
final class VolumeScopeController extends VolumeController
{
    /** @var list<array{template:string,vars:array<string,mixed>}> */
    public array $rendered = [];

    /** @var array<string,?AgentDeployer> */
    public array $agents = [];

    /** @var list<string> id server yang dimintai deployer (bukti agent tak dihubungi) */
    public array $agentRequests = [];

    public VolumeScopeComposeRunner $compose;

    public function __construct(
        private AppStore $apps,
        private ServerResolver $resolver,
        private DockerClient $docker,
        /** User dashboard uji; `null` ⇒ admin. */
        private ?array $user = null,
    ) {
        $this->compose = new VolumeScopeComposeRunner();
    }

    protected function appStore(): AppStore
    {
        return $this->apps;
    }

    protected function serverResolver(): ServerResolver
    {
        return $this->resolver;
    }

    protected function docker(int $timeout = 10): DockerClient
    {
        return $this->docker;
    }

    protected function compose(): DockerComposeRunner
    {
        return $this->compose;
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        $id = (string) ($server['id'] ?? '');
        $this->agentRequests[] = $id;

        return $this->agents[$id] ?? null;
    }

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered[] = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered:' . $template);
    }

    protected function currentUser(): ?array
    {
        return $this->user ?? ['id' => 'u-admin', 'username' => 'admin', 'role' => 'admin'];
    }

    protected function isAdmin(): bool
    {
        return (string) ($this->currentUser()['role'] ?? '') === 'admin';
    }

    protected function flash(string $type, string $message): void
    {
        $GLOBALS['RAMES_FLASH'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Volume controller yang mengambil keputusan visibilitas dari
     * `ServerAccess` — dipakai untuk membuktikan satu pintu dipatuhi.
     *
     * @param array<int,array> $servers
     */
    public function visibleServersForAssert(array $servers, ?array $user, array $apps): array
    {
        return ServerAccess::visibleServers($servers, $user, $apps);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function visibleAgentServersForAssert(): array
    {
        return $this->visibleAgentServers();
    }
}

/**
 * Cakupan server halaman `/volumes` (multi-server, plan §9.3/§10.4/§12):
 *  - admin melihat volume server lokal **dan** setiap server agent;
 *  - non-admin hanya volume server yang menaungi app yang boleh ia lihat, dan
 *    agent server lain **tidak pernah dihubungi**;
 *  - server agent tak terjangkau ⇒ peringatan, halaman tetap tampil;
 *  - purge/pembersihan tidak pernah menyentuh volume server agent maupun
 *    mengubah perilaku lama untuk volume lokal.
 *
 * Tidak menyentuh `database/`, `apps/`, `runtime/`, Docker, atau jaringan nyata.
 */
class VolumeServerScopeTest extends TestCase
{
    /** @var array<string,mixed> */
    private const ADMIN = ['id' => 'u-admin', 'username' => 'admin', 'role' => 'admin'];

    /** @var array<string,mixed> */
    private const MEMBER = ['id' => 'u-member', 'username' => 'budi', 'role' => 'member'];

    private string $tmp;

    private VolumeScopeDocker $docker;

    /** @var array<string,VolumeScopeAgentHandler> */
    private array $handlers = [];

    /** @var array<string,?AgentDeployer> */
    private array $agents = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-volume-scope-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/tls', 0777, true);

        $GLOBALS['RAMES_FLASH'] = [];
        $this->docker = new VolumeScopeDocker();
        $this->handlers = [];
        $this->agents = [];

        $this->useConfig();
    }

    protected function tearDown(): void
    {
        $GLOBALS['RAMES_FLASH'] = [];
        ServerResolver::forgetMemo();
        $this->removeTree($this->tmp);
    }

    // ==================================================================
    // (iii) Admin melihat semua server (lokal + agent)
    // ==================================================================

    public function testAdminSeesLocalAndEveryAgentVolumeWithServerNames(): void
    {
        $this->localVolumes([
            $this->volume('lokal_app_data', 'app-lokal'),
            $this->volume('lokal_yatim_data', 'app-tak-ada', ['rames.role' => 'x']),
        ]);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'server_id' => ServerStore::LOCAL_ID]);
        $this->agentServer('srv-jakarta', 'vps-jakarta', ['app-jakarta' => ['jakarta_data']]);
        $this->agentServer('srv-bandung', 'vps-bandung', ['app-bandung' => ['bandung_db']]);
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'server_id' => 'srv-jakarta']);
        $this->app(['id' => 'a3', 'name' => 'app-bandung', 'server_id' => 'srv-bandung']);

        $controller = $this->controller(self::ADMIN);
        $controller->index($this->get('/volumes'));

        $rows = $controller->rendered[0]['vars']['rows'] ?? [];

        $this->assertSame(
            [
                'lokal_yatim_data' => 'local',
                'lokal_app_data' => 'local',
                'bandung_db' => 'vps-bandung',
                'jakarta_data' => 'vps-jakarta',
            ],
            $this->nameToServer($rows)
        );

        // Penanda kind: volume lokal vs volume server agent.
        foreach ($rows as $row) {
            $expectedStart = $row['server_name'] === 'local' ? 'local' : 'agent';
            $this->assertSame($expectedStart, $row['server_kind'], (string) $row['name']);
        }

        // Volume lokal tetap membawa data Engine-nya; baris agent tidak.
        $lokal = $this->rowNamed($rows, 'lokal_app_data');
        $this->assertSame('local', $lokal['driver']);
        $this->assertSame('/var/lib/docker/volumes/lokal_app_data', $lokal['mountpoint']);
        $this->assertTrue($lokal['can_purge'] === false, 'volume lokal yang dipakai app aktif tidak bisa dipurge');

        $agent = $this->rowNamed($rows, 'jakarta_data');
        $this->assertSame('', $agent['driver']);
        $this->assertFalse($agent['can_purge'], 'volume server agent tidak boleh menampilkan aksi purge');

        // Volume yatim lokal boleh dipurge; yatim server agent tidak.
        $this->assertTrue($this->rowNamed($rows, 'lokal_yatim_data')['can_purge']);
        $this->assertSame([], $controller->rendered[0]['vars']['serverErrors']);
    }

    // ==================================================================
    // (i) Non-admin: hanya server yang berhak, agent lain tidak dihubungi
    // ==================================================================

    public function testNonAdminSeesOnlyOwnServerAndOtherAgentsAreNeverCalled(): void
    {
        $this->localVolumes([
            $this->volume('lokal_app_data', 'app-lokal'),
            $this->volume('lokal_oranglain_data', 'app-oranglain'),
            $this->volume('lokal_yatim_data', 'app-tak-ada'),
        ]);
        $visible = $this->app(['id' => 'a1', 'name' => 'app-lokal', 'owner_id' => 'u-member', 'server_id' => ServerStore::LOCAL_ID]);
        $this->app(['id' => 'a9', 'name' => 'app-oranglain', 'owner_id' => 'u-lain', 'server_id' => ServerStore::LOCAL_ID]);

        $this->agentServer('srv-jakarta', 'vps-jakarta', ['app-jakarta' => ['jakarta_data']]);
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'owner_id' => 'u-member', 'server_id' => 'srv-jakarta']);
        // App user lain di server agent lain: non-admin tidak boleh melihatnya —
        // dan agent itu tidak boleh dihubungi sama sekali.
        $this->agentServer('srv-rahasia', 'vps-rahasia', ['app-rahasia' => ['rahasia_data']]);
        $this->app(['id' => 'a3', 'name' => 'app-rahasia', 'owner_id' => 'u-lain', 'server_id' => 'srv-rahasia']);

        $controller = $this->controller(self::MEMBER);
        $controller->index($this->get('/volumes'));

        $rows = $controller->rendered[0]['vars']['rows'] ?? [];

        // Non-admin: hanya volume app miliknya di server lokal + volume server
        // yang menaungi app miliknya. Volume yatim & app user lain disembunyikan.
        $this->assertSame(['lokal_app_data', 'jakarta_data'], array_column($rows, 'name'));
        $this->assertSame('vps-jakarta', $this->rowNamed($rows, 'jakarta_data')['server_name']);

        // Server yang tidak berhak benar-benar tidak dihubungi.
        $this->assertSame(
            ['srv-jakarta'],
            array_column($controller->visibleAgentServersForAssert(), 'id'),
            'server lokal & server milik user lain tidak pernah masuk jalur agent'
        );
        $this->assertSame(
            ['srv-jakarta'],
            $controller->agentRequests,
            'hanya server yang menaungi app milik user yang dihubungi/dibangun'
        );
        $this->assertSame([], $this->handlers['srv-rahasia']->calls, 'agent server lain tidak pernah dipanggil');
        $this->assertSame(
            ['app-jakarta'],
            $this->handlers['srv-jakarta']->requestedProjects(),
            'hanya project app yang boleh dilihat user yang diminta ke agent'
        );
        $this->assertNotContains('vps-rahasia', array_column($rows, 'server_name'));
        $this->assertNotContains('rahasia_data', array_column($rows, 'name'));

        // Nama server agent memang diterima non-admin (server yang menaungi app-nya),
        // tetapi daftar server lengkapnya tidak pernah dikirim controller ini.
        $visibleServers = $controller->visibleServersForAssert(
            (new ServerResolver())->all(),
            $visible['owner_id'] === 'u-member' ? self::MEMBER : null,
            (new AppStore())->all()
        );
        $this->assertSame(
            ['local', 'srv-jakarta'],
            array_column($visibleServers, 'id'),
            'pintu yang sama: server lokal (app member) + server agent app member, tanpa server lain'
        );
    }

    // ==================================================================
    // (ii) Server agent offline tidak menggagalkan halaman
    // ==================================================================

    public function testOfflineAgentDoesNotBreakPageAndOthersStillRender(): void
    {
        $this->localVolumes([$this->volume('lokal_data', 'app-lokal')]);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'server_id' => ServerStore::LOCAL_ID]);

        $this->agentServer('srv-mati', 'vps-mati', ['app-mati' => ['mati_data']], offline: true);
        $this->app(['id' => 'a2', 'name' => 'app-mati', 'server_id' => 'srv-mati']);
        $this->agentServer('srv-hidup', 'vps-hidup', ['app-hidup' => ['hidup_data']]);
        $this->app(['id' => 'a3', 'name' => 'app-hidup', 'server_id' => 'srv-hidup']);

        $controller = $this->controller(self::ADMIN);
        $controller->index($this->get('/volumes'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame(['lokal_data', 'hidup_data'], array_column($vars['rows'], 'name'));
        $this->assertNull($vars['engineError'], 'Engine lokal sehat → tanpa galat');

        $this->assertCount(1, $vars['serverErrors'], 'satu pesan per server tak terjangkau');
        $this->assertStringContainsString('vps-mati', $vars['serverErrors'][0]);
        $this->assertStringNotContainsString('internal.test', $vars['serverErrors'][0], 'base_url internal tidak bocor');
        $this->assertStringNotContainsString('tok-uji-', $vars['serverErrors'][0], 'token tidak bocor');
        $this->assertSame('volume/index', $controller->rendered[0]['template']);
    }

    public function testUnreachableLocalEngineKeepsAgentRowsAndReportsEngineError(): void
    {
        $this->docker->explode = true;
        $this->agentServer('srv-jakarta', 'vps-jakarta', ['app-jakarta' => ['jakarta_data']]);
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'server_id' => 'srv-jakarta']);

        $controller = $this->controller(self::ADMIN);
        $controller->index($this->get('/volumes'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertStringContainsString('Tidak dapat mengakses Docker Engine', (string) $vars['engineError']);
        $this->assertSame(['jakarta_data'], array_column($vars['rows'], 'name'), 'baris server agent tetap tampil');
    }

    /**
     * Non-admin yang hanya punya app lokal lama (`server_id` kosong) tetap
     * melihat volume lokalnya — perilaku sebelum multi-server tidak berubah.
     */
    public function testLegacyLocalOnlyMemberStillSeesLocalVolumes(): void
    {
        $this->localVolumes([$this->volume('lokal_data', 'app-lokal')]);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'owner_id' => 'u-member']);

        $controller = $this->controller(self::MEMBER);
        $controller->index($this->get('/volumes'));

        $rows = $controller->rendered[0]['vars']['rows'];
        $this->assertSame(['lokal_data'], array_column($rows, 'name'));
        $this->assertSame('local', $rows[0]['server_kind']);
        $this->assertSame([], $controller->rendered[0]['vars']['serverErrors']);
    }

    // ==================================================================
    // (v) Perilaku lama volume lokal: urutan, filter db dashboard, purge
    // ==================================================================

    public function testLocalRowsKeepLegacyOrderAndDashboardVolumeIsHidden(): void
    {
        $this->localVolumes([
            $this->volume('rames', 'rames', ['rames.role' => 'dashboard-db']),
            $this->volume('b_aktif', 'app-b'),
            $this->volume('a_yatim', 'app-tak-ada'),
            $this->volume('a_aktif', 'app-a'),
        ]);
        $this->app(['id' => 'a1', 'name' => 'app-a', 'server_id' => ServerStore::LOCAL_ID]);
        $this->app(['id' => 'a2', 'name' => 'app-b', 'server_id' => ServerStore::LOCAL_ID]);

        $controller = $this->controller(self::ADMIN);
        $controller->index($this->get('/volumes'));

        // Volume DB dashboard tidak pernah tampil; yatim lebih dulu (urutan lama).
        $this->assertSame(['a_yatim', 'a_aktif', 'b_aktif'], array_column($controller->rendered[0]['vars']['rows'], 'name'));
    }

    public function testPurgeLocalOrphanUsesLocalComposeRunnerOnly(): void
    {
        $this->localVolumes([$this->volume('a_yatim', 'app-tak-ada')]);
        $this->app(['id' => 'a1', 'name' => 'app-a', 'server_id' => ServerStore::LOCAL_ID]);
        $this->agentServer('srv-jakarta', 'vps-jakarta', ['app-jakarta' => ['jakarta_data']]);
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'server_id' => 'srv-jakarta']);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->purge($this->post('/volumes/purge', 'volumes[]=a_yatim'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['a_yatim'], $controller->compose->removed);
        $this->assertSame(
            [['type' => 'success', 'message' => '1 volume yatim dihapus.']],
            $GLOBALS['RAMES_FLASH']
        );
    }

    public function testPurgeRejectsAgentVolumeWithoutTouchingLocalVolumes(): void
    {
        $this->localVolumes([$this->volume('a_yatim', 'app-tak-ada')]);
        $this->app(['id' => 'a1', 'name' => 'app-a', 'server_id' => ServerStore::LOCAL_ID]);
        $this->agentServer('srv-jakarta', 'vps-jakarta', ['app-jakarta' => ['jakarta_data']]);
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'server_id' => 'srv-jakarta']);

        $controller = $this->controller(self::ADMIN);
        // Satu volume agent + satu volume yatim lokal: fail-fast, tidak ada yang dihapus.
        $response = $controller->purge($this->post('/volumes/purge', 'volumes[]=jakarta_data&volumes[]=a_yatim'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $controller->compose->removed, 'tidak ada volume yang dihapus');
        $this->assertSame('error', $GLOBALS['RAMES_FLASH'][0]['type'] ?? '');
        $this->assertStringContainsString('vps-jakarta', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
        $this->assertStringContainsString('belum didukung', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
    }

    public function testPurgeKeepsLegacyRulesForNonAdminAndActiveVolumes(): void
    {
        $this->localVolumes([
            $this->volume('a_aktif', 'app-a'),
            $this->volume('a_yatim', 'app-tak-ada'),
        ]);
        $this->app(['id' => 'a1', 'name' => 'app-a', 'server_id' => ServerStore::LOCAL_ID]);

        $member = $this->controller(self::MEMBER);
        $member->purge($this->post('/volumes/purge', 'volumes[]=a_yatim'));
        $this->assertSame([], $member->compose->removed);
        $this->assertStringContainsString('hanya admin', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');

        $GLOBALS['RAMES_FLASH'] = [];
        $admin = $this->controller(self::ADMIN);
        $admin->purge($this->post('/volumes/purge', 'volumes[]=a_aktif'));
        $this->assertSame([], $admin->compose->removed, 'volume yang masih dipakai app aktif ditolak');
        $this->assertStringContainsString('tidak yatim atau masih dipakai', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');

        // Nama volume tidak sah tetap ditolak dengan pesan lama.
        $GLOBALS['RAMES_FLASH'] = [];
        $admin->purge($this->post('/volumes/purge', 'volumes[]=../etc/passwd'));
        $this->assertSame([], $admin->compose->removed);
        $this->assertStringContainsString('Nama volume tidak valid', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
    }

    // ==================================================================
    // /api/volumes/usage tetap lokal-only (Engine host ini tidak mengenal agent)
    // ==================================================================

    public function testUsageCountsOnlyLocalVolumes(): void
    {
        $this->localVolumes([$this->volume('lokal_data', 'app-a')]);
        $this->docker->diskUsage = ['Volumes' => [
            ['Name' => 'lokal_data', 'UsageData' => ['Size' => 2_000_000, 'RefCount' => 1]],
            ['Name' => 'jakarta_data', 'UsageData' => ['Size' => 5_000_000, 'RefCount' => 1]],
        ]];
        $this->app(['id' => 'a1', 'name' => 'app-a', 'owner_id' => 'u-member', 'server_id' => ServerStore::LOCAL_ID]);
        $this->agentServer('srv-jakarta', 'vps-jakarta', ['app-jakarta' => ['jakarta_data']]);
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'owner_id' => 'u-member', 'server_id' => 'srv-jakarta']);

        $controller = $this->controller(self::MEMBER);
        $response = $controller->usage($this->get('/api/volumes/usage'));
        $payload = json_decode((string) $response->rawBody(), true);

        $this->assertSame(0, $payload['code'] ?? null);
        $this->assertSame(['lokal_data'], array_keys($payload['data']['usage'] ?? []));
        $this->assertSame(2_000_000, $payload['data']['total'] ?? null);
        // Agent tidak dihubungi untuk menghitung ukuran.
        $this->assertSame([], $this->handlers['srv-jakarta']->calls ?? []);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    public function testServerAccessIsTheSingleDoorForVolumeVisibility(): void
    {
        $servers = [
            ['id' => 'local', 'name' => 'local', 'kind' => 'local', 'enabled' => true],
            ['id' => 'srv-jakarta', 'name' => 'vps-jakarta', 'kind' => 'agent', 'enabled' => true],
            ['id' => 'srv-rahasia', 'name' => 'vps-rahasia', 'kind' => 'agent', 'enabled' => true],
        ];
        $apps = [
            ['id' => 'a1', 'name' => 'app-jakarta', 'owner_id' => 'u-member', 'server_id' => 'srv-jakarta'],
            ['id' => 'a2', 'name' => 'app-rahasia', 'owner_id' => 'u-lain', 'server_id' => 'srv-rahasia'],
        ];

        $this->assertSame(
            ['srv-jakarta'],
            array_column(ServerAccess::visibleServers($servers, self::MEMBER, $apps), 'id'),
            'controller memakai pintu yang sama dengan hak akses app'
        );
        $this->assertSame(
            ['local', 'srv-jakarta', 'srv-rahasia'],
            array_column(ServerAccess::visibleServers($servers, self::ADMIN, $apps), 'id')
        );
    }

    private function controller(?array $user): VolumeScopeController
    {
        $controller = new VolumeScopeController(new AppStore(), new ServerResolver(), $this->docker, $user);
        $controller->agents = $this->agents;

        return $controller;
    }

    /** @param array<string,mixed> $extra */
    private function app(array $extra): array
    {
        return (new AppStore())->create(array_merge(['status' => 'running'], $extra));
    }

    /**
     * @param array<string,array<int,string>> $volumesByProject
     */
    private function agentServer(string $id, string $name, array $volumesByProject, bool $offline = false): void
    {
        (new ServerStore())->create([
            'id' => $id,
            'name' => $name,
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://' . $id . '.internal.test:8443',
            'token' => 'tok-uji-' . $id,
            'tls_cert_pem' => $this->selfSignedPem(),
            'status' => 'online',
        ]);
        ServerResolver::forgetMemo();

        $handler = new VolumeScopeAgentHandler();
        $handler->volumesByProject = $volumesByProject;
        $handler->offline = $offline;
        $this->handlers[$id] = $handler;

        $server = (new ServerResolver())->resolve($id);
        $client = new AgentClient($server, $handler, 5, new TlsPinMaterializer($this->tmp . '/tls'));
        $this->agents[$id] = new AgentDeployer($server, $client);
    }

    /**
     * @param array<string,mixed> $labels
     * @return array<string,mixed>
     */
    private function volume(string $name, string $project, array $labels = []): array
    {
        return [
            'Name' => $name,
            'Driver' => 'local',
            'Mountpoint' => '/var/lib/docker/volumes/' . $name,
            'CreatedAt' => '2026-10-14T10:00:00Z',
            'Labels' => array_merge(['com.docker.compose.project' => $project], $labels),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $volumes
     */
    private function localVolumes(array $volumes): void
    {
        $this->docker->volumes = $volumes;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,string>
     */
    private function nameToServer(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (string) $row['server_name'];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function rowNamed(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ((string) $row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("baris volume {$name} tidak ada");
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
    private function selfSignedPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return (string) $pem;
    }

    /**
     * Config uji: seluruh path menunjuk direktori temp (tidak menyentuh
     * `database/`, `apps/`, atau socket Docker nyata).
     */
    private function useConfig(): void
    {
        \Webman\Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        \Webman\Config::load($this->tmp . '/config');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
