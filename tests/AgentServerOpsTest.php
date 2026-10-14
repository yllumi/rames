<?php
declare(strict_types=1);

namespace Tests;

use app\controller\NginxController;
use app\controller\ServerController;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullService;
use app\library\Template\TemplatePullState;
use app\library\Update\AgentVersionGate;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;

/**
 * Op **tingkat server** multi-server F4 lanjutan, sisi **control plane**:
 * tarik image template per server (`/nginx` tab Template) dan self-update agen
 * (`POST /servers/{id}/agent-update`).
 *
 * Kontrak yang dikunci:
 *  (i)  pull untuk server agent ⇒ **job agent** (`POST /jobs` op `template_pull`
 *       + target slug) dan **tidak** ada `docker pull` di host control plane;
 *  (ii) state/riwayat pull **terpisah per server** (berkas state berbeda, run
 *       server A tidak tersentuh saat server B menarik);
 * (iii) self-update agen dikirim sebagai job `self_update` ke agen (image/helper
 *       container berjalan di sana) — control plane tidak menyentuh update lokal;
 *  (iv) op yang tidak ada di `capabilities` ⇒ ditolak `unsupported` dengan pesan
 *       jelas, **tanpa** panggilan HTTP;
 *  (v)  versi agen di bawah minimum / control plane terlalu tua untuk agen ⇒
 *       ditolak dengan pesan jelas (dan `warnings` saat data belum diketahui);
 *  (vi) server lokal ⇒ perilaku lama (`cli/pull.php` di sini, tanpa HTTP);
 * (vii) polling: status job dicerminkan ke state run (offset log maju, run
 *       ditutup `done`/`error`), job hilang (404) ⇒ run ditutup `error`.
 *
 * Semua tanpa jaringan/Docker: handler Guzzle tiruan, spawner palsu, dan store
 * di `/tmp` (tidak menyentuh `database/`, `apps/`, atau `runtime/` nyata).
 */
class AgentServerOpsTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const TOKEN = 'agt_uji_ops_9c1f';
    private const SLUG = 'outline';

    private static ?string $pem = null;

    private string $tmp;
    private string $db;

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var array<int,array<int,string>> argv spawn worker tarik image lokal */
    public array $localSpawns = [];

    /** @var array<int,string> */
    public array $spawnedOps = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/rames-agentsrvops-' . bin2hex(random_bytes(5));
        $this->db = $this->tmp . '/rames.sqlite';
        mkdir($this->tmp . '/config', 0777, true);
        $this->localSpawns = [];

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
        // Log worker op tingkat server ditulis ke runtime/logs/deploy/{target}.log
        // (runtime_path tidak bisa di-override lewat env) — bersihkan.
        @unlink(dirname(__DIR__) . '/runtime/logs/deploy/agent.log');
        @unlink(dirname(__DIR__) . '/runtime/logs/deploy/' . self::SLUG . '.log');
    }

    // ==================================================================
    // (i) + (vi) tarik image: agent ⇒ job agent, lokal ⇒ perilaku lama
    // ==================================================================

    public function testAgentServerPullGoesToAgentAndNeverPullsLocally(): void
    {
        $agent = $this->agent(id: 'vps-1');
        $handler = new AgentServerOpsHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-tpl-1', 'op' => 'template_pull', 'log_offset' => 0]));

        $controller = $this->nginx($handler)[0];
        [$status, , $body] = $this->decode($controller->pullTemplates(
            $this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => (string) $agent['id']])
        ));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(0, $body['code']);
        $this->assertSame('job-tpl-1', $body['data']['job_id']);
        $this->assertSame((string) $agent['id'], $body['data']['server_id']);

        // Satu-satunya panggilan HTTP = job ke agen; op benar & target = slug.
        $this->assertSame(1, $handler->count());
        $post = $handler->jsonBody(0);
        $this->assertSame('/jobs', $handler->call(0)['path']);
        $this->assertSame(DeployWorkerLauncher::SERVER_OPS[0], $post['op']);
        $this->assertSame(self::SLUG, $post['target']);
        $this->assertArrayNotHasKey('app', $post, 'op tingkat server tidak membawa app');

        // Tidak ada `docker pull` di host control plane: spawner jalur lokal
        // TIDAK pernah dipanggil.
        $this->assertSame([], $this->localSpawns, 'pull server agent tidak boleh dijalankan di control plane');
    }

    public function testLocalServerPullKeepsLegacyBehaviour(): void
    {
        // Server lokal = baris `local` (kind=local) ⇒ jalur lama, tanpa HTTP.
        $handler = new AgentServerOpsHandler();
        $controller = $this->nginx($handler)[0];

        [$status, , $body] = $this->decode($controller->pullTemplates(
            $this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => ServerStore::LOCAL_ID])
        ));

        $this->assertSame(0, $body['code']);
        $this->assertSame(0, $handler->count(), 'server lokal tidak boleh memanggil HTTP ke mana pun');
        $this->assertCount(1, $this->localSpawns);
        $this->assertSame(
            [PHP_BINARY, dirname(__DIR__) . '/cli/pull.php', self::SLUG, 'admin'],
            $this->localSpawns[0],
            'jalur lama wajib tetap memakai cli/pull.php'
        );
        $this->assertSame(200, $status);
    }

    // ==================================================================
    // (ii) state per server
    // ==================================================================

    public function testPullStateIsSeparatedPerServer(): void
    {
        $first = $this->agent(id: 'vps-a');
        $second = $this->agent(id: 'vps-b');

        $handler = new AgentServerOpsHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-a']));
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-b']));

        $controller = $this->nginx($handler)[0];
        $controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => 'mysql', 'server' => 'vps-a']));
        $controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => 'redis', 'server' => 'vps-b']));

        $runA = $this->stateFor('vps-a')->run();
        $runB = $this->stateFor('vps-b')->run();

        $this->assertSame('mysql', $runA['slug']);
        $this->assertSame('job-a', $runA['job']);
        $this->assertSame('redis', $runB['slug']);
        $this->assertSame('job-b', $runB['job']);
        $this->assertNotSame($this->stateFor('vps-a')->runDir(), $this->stateFor('vps-b')->runDir());
        $this->assertFileExists($this->tmp . '/template-pull/servers/vps-a/run.json');
        $this->assertFileDoesNotExist($this->tmp . '/template-pull/servers/vps-a/servers/vps-b/run.json');

        // State server lokal (tanpa kunci) tetap di tempat lama & tidak terisi run agen.
        $local = new TemplatePullState($this->tmp . '/template-pull');
        $this->assertNull($local->run()['id']);
        $this->assertSame('', $local->serverKey());

        unset($first, $second);
    }

    public function testSecondPullIsRefusedWhileTheFirstOneStillRuns(): void
    {
        $this->agent(id: 'vps-1');

        $handler = new AgentServerOpsHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-1']));
        // Percobaan kedua: run pertama belum ditutup ⇒ dicek ke agen (masih running).
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-1', 'status' => 'running', 'stage' => 'pulling', 'message' => 'Menarik image 2/3']]));
        $handler->reply(200, '');
        // Setelah job pertama selesai: run ditutup lalu pull baru boleh dimulai.
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-1', 'status' => 'done', 'message' => 'Selesai']]));
        $handler->reply(200, "[t] done\n");
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-2']));

        $controller = $this->nginx($handler)[0];
        [, , $first] = $this->decode($controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => 'vps-1'])));
        $this->assertSame(0, $first['code']);

        [, , $blocked] = $this->decode($controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => 'mysql', 'server' => 'vps-1'])));
        $this->assertSame(400, $blocked['code']);
        $this->assertStringContainsString('Masih ada proses', (string) $blocked['msg']);

        [, , $next] = $this->decode($controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => 'mysql', 'server' => 'vps-1'])));
        $this->assertSame(0, $next['code']);
        $this->assertSame('job-2', $next['data']['job_id']);

        $run = $this->stateFor('vps-1')->run();
        $this->assertSame('mysql', $run['slug']);
        $this->assertSame('job-2', $run['job']);
    }

    // ==================================================================
    // (vii) polling status job ⇒ state run
    // ==================================================================

    public function testPollingMirrorsJobStatusAndAdvancesLogOffset(): void
    {
        $agent = $this->agent(id: 'vps-1');
        $handler = new AgentServerOpsHandler();
        // start
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-p', 'log_offset' => 0]));
        // panel #1: running + potongan log pertama
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-p', 'status' => 'running', 'stage' => 'pulling', 'message' => 'Menarik image 1/3: postgres:18']]));
        $handler->reply(200, "[t] [template] docker pull postgres:18\n", ['X-Rames-Offset' => '39']);
        // panel #2: done + potongan log kedua
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-p', 'status' => 'done', 'message' => 'Selesai']]));
        $handler->reply(200, "[t] done\n");

        $controller = $this->nginx($handler)[0];
        $controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => 'vps-1']));

        $running = $this->decode($controller->pullStatus($this->getRequest('/nginx/templates/pull/status?server=vps-1')))[2];
        $this->assertTrue($running['data']['running']);
        $this->assertSame('pulling', $running['data']['run']['stage']);
        $this->assertStringContainsString('postgres:18', $running['data']['log_tail']);

        $done = $this->decode($controller->pullStatus($this->getRequest('/nginx/templates/pull/status?server=vps-1')))[2];
        $this->assertFalse($done['data']['running']);
        $this->assertSame('success', $done['data']['run']['result']);
        $this->assertSame('finished', $done['data']['run']['stage']);
        // Log run server itu memuat kedua potongan, offset maju (tidak mengulang).
        $this->assertStringContainsString('docker pull postgres:18', $done['data']['log_tail']);
        $this->assertStringContainsString('done', $done['data']['log_tail']);

        // Offset tersimpan = jumlah byte kedua potongan (tidak ada pembacaan ulang).
        $state = $this->stateFor('vps-1')->run();
        $this->assertSame(strlen("[t] [template] docker pull postgres:18\n[t] done\n"), $state['log_offset']);

        unset($agent);
    }

    public function testMissingJobClosesRunWithClearError(): void
    {
        $this->agent(id: 'vps-1');

        $handler = new AgentServerOpsHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-gone']));
        // Job hilang di agen (mis. agen baru di-restart) ⇒ 404 saat polling.
        $handler->reply(404, json_encode(['code' => 1, 'error' => 'Job tidak ditemukan.', 'reason' => 'not_found']));

        $controller = $this->nginx($handler)[0];
        $controller->pullTemplates($this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => 'vps-1']));

        $panel = $this->decode($controller->pullStatus($this->getRequest('/nginx/templates/pull/status?server=vps-1')))[2];

        $this->assertFalse($panel['data']['running']);
        $this->assertSame('error', $panel['data']['run']['result']);
        $this->assertStringContainsString('tidak ditemukan', strtolower((string) $panel['data']['run']['error']));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($panel));
    }

    // ==================================================================
    // (iv) + (v) gerbang capabilities & versi
    // ==================================================================

    public function testPullOnAgentWithoutTemplatePullCapabilityIsRejectedWithoutHttp(): void
    {
        $agent = $this->agent(id: 'vps-lama', capabilities: ['deploy', 'stop', 'start']);

        $handler = new AgentServerOpsHandler();
        $controller = $this->nginx($handler)[0];
        [, , $body] = $this->decode($controller->pullTemplates(
            $this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => (string) $agent['id']])
        ));

        // Bentuk respons mengikuti jalur lokal: HTTP 200 + `code:400` (kontrak
        // `NginxController::pullTemplates`), bukan status HTTP galat.
        $this->assertSame(400, $body['code']);
        $this->assertStringContainsString('template_pull', $body['msg']);
        $this->assertStringContainsString('capabilities', $body['msg']);
        $this->assertSame(0, $handler->count(), 'op yang tidak didukung tidak boleh dipanggil sama sekali');
        $this->assertSame([], $this->localSpawns, 'jangan pernah fallback ke pull lokal');
    }

    public function testPullIsRejectedWhenAgentNeedsNewerControlPlane(): void
    {
        $agent = $this->agent(id: 'vps-baru', minControlPlane: '99.0.0');

        $handler = new AgentServerOpsHandler();
        $controller = $this->nginx($handler)[0];
        [, , $body] = $this->decode($controller->pullTemplates(
            $this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => (string) $agent['id']])
        ));

        $this->assertSame(400, $body['code']);
        $this->assertStringContainsString('control plane', $body['msg']);
        $this->assertStringContainsString('99.0.0', $body['msg']);
        $this->assertSame(0, $handler->count());
    }

    public function testAgentBelowMinimumVersionIsRejected(): void
    {
        $agent = $this->agent(id: 'vps-tua', agentVersion: '0.0.1');

        $handler = new AgentServerOpsHandler();
        $controller = $this->nginx($handler)[0];
        [, , $body] = $this->decode($controller->pullTemplates(
            $this->formPost('/nginx/templates/pull', ['slug' => self::SLUG, 'server' => (string) $agent['id']])
        ));

        $this->assertSame(400, $body['code']);
        $this->assertStringContainsString(AgentVersionGate::MIN_AGENT_VERSION, $body['msg']);
        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // (iii) self-update agen dijalankan DI agen
    // ==================================================================

    public function testAgentUpdateIsSentToAgentAndNeverRunsLocally(): void
    {
        $agent = $this->agent(id: 'vps-1');
        $handler = new AgentServerOpsHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-upd']));

        $controller = $this->serverController($handler)[0];
        [$status, , $body] = $this->decode($controller->agentUpdate(
            $this->formPost('/servers/vps-1/agent-update', ['mode' => 'update']),
            'vps-1'
        ));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(0, $body['code']);
        $this->assertSame('job-upd', $body['data']['job_id']);
        $this->assertStringContainsString('Test connection', (string) $body['msg']);

        // Update dijalankan DI agen: satu panggilan, op self_update, target `agent`,
        // mode + versi control plane pemanggil ikut (gerbang sisi agen).
        $this->assertSame(1, $handler->count());
        $post = $handler->jsonBody(0);
        $this->assertSame('POST', $handler->call(0)['method']);
        $this->assertSame('/jobs', $handler->call(0)['path']);
        $this->assertSame(DeployWorkerLauncher::AGENT_UPDATE_OP, $post['op']);
        $this->assertSame(DeployWorkerLauncher::AGENT_UPDATE_TARGET, $post['target']);
        $this->assertSame('update', $post['update_mode']);
        $this->assertSame(AgentVersionGate::CODEBASE_VERSION, $post['control_plane_version']);
        $this->assertArrayNotHasKey('token', $post);

        unset($agent);
    }

    public function testAgentUpdateOnLocalServerIsRejectedWithoutHttp(): void
    {
        $handler = new AgentServerOpsHandler();
        $controller = $this->serverController($handler)[0];

        [, , $body] = $this->decode($controller->agentUpdate(
            $this->formPost('/servers/local/agent-update', ['mode' => 'update']),
            ServerStore::LOCAL_ID
        ));

        $this->assertSame(400, $body['code']);
        $this->assertStringContainsString('Config', (string) $body['msg']);
        $this->assertSame(0, $handler->count());
    }

    public function testAgentUpdateIsRejectedWhenCapabilityMissing(): void
    {
        $this->agent(id: 'vps-lama', capabilities: ['deploy']);

        $handler = new AgentServerOpsHandler();
        $controller = $this->serverController($handler)[0];
        [, , $body] = $this->decode($controller->agentUpdate(
            $this->formPost('/servers/vps-lama/agent-update', ['mode' => 'update']),
            'vps-lama'
        ));

        $this->assertSame(400, $body['code']);
        $this->assertStringContainsString(DeployWorkerLauncher::AGENT_UPDATE_OP, (string) $body['msg']);
        $this->assertSame(0, $handler->count(), 'agen yang belum mendukung op tidak boleh menerima job');
    }

    public function testAgentUpdateUnknownModeIsRejected(): void
    {
        $this->agent(id: 'vps-1');

        $handler = new AgentServerOpsHandler();
        $controller = $this->serverController($handler)[0];
        [, , $body] = $this->decode($controller->agentUpdate(
            $this->formPost('/servers/vps-1/agent-update', ['mode' => 'pwn']),
            'vps-1'
        ));

        $this->assertSame(400, $body['code']);
        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // Status versi per server (Test connection) + kompatibilitas
    // ==================================================================

    public function testConnectionStoresMinimumControlPlaneVersionAndExposesCompat(): void
    {
        $this->agent(id: 'vps-1');

        $handler = new AgentServerOpsHandler();
        $handler->reply(200, json_encode([
            'ok' => true,
            'service' => 'rames-agent',
            'mode' => 'agent',
            'version' => AgentVersionGate::CODEBASE_VERSION,
        ]));
        $handler->reply(200, json_encode([
            'agent_version' => '0.2.0',
            'capabilities' => DeployWorkerLauncher::MODES,
            'min_control_plane_version' => '0.1.5',
        ]));

        $controller = $this->serverController($handler)[0];
        [$status, , $body] = $this->decode($controller->testConnection(
            $this->formPost('/servers/vps-1/test', []),
            'vps-1'
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);
        $this->assertSame('online', $body['server']['status']);
        $this->assertSame('0.2.0', $body['server']['agent_version']);
        $this->assertSame('0.1.5', $body['server']['min_control_plane_version']);

        // Versi itu dipakai gerbang: dashboard (0.1.0) terlalu tua untuk agen ini.
        $stored = (new ServerStore($this->db))->find('vps-1');
        $this->assertNotNull($stored);
        $this->assertFalse(AgentVersionGate::check($stored, 'template_pull')['ok']);

        // Ringkasan kompatibilitas tampil di daftar (tanpa rahasia apa pun).
        [, , $api, $rawApi] = $this->decode($controller->apiIndex($this->getRequest('/api/servers')));
        $agentRow = null;
        foreach ($api['data'] as $row) {
            if (($row['id'] ?? '') === 'vps-1') {
                $agentRow = $row;
            }
        }
        $this->assertIsArray($agentRow, 'baris server agent wajib ada di daftar');
        $this->assertFalse($agentRow['agent_compat']['ok']);
        $this->assertSame('0.1.5', $agentRow['agent_compat']['min_control_plane_version']);
        $this->assertStringNotContainsString(self::TOKEN, $rawApi);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $rawApi);
    }

    // ==================================================================
    // Gating op app (AgentDeployer) — fail-fast sebelum HTTP
    // ==================================================================

    public function testAppOpIsRejectedBeforeHttpWhenCapabilityMissing(): void
    {
        $server = $this->agent(id: 'vps-1', capabilities: ['stop', 'start']);
        $handler = new AgentServerOpsHandler();
        $deployer = new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'))
        );

        try {
            $deployer->deploy(['id' => 'app-1', 'name' => 'demo'], static function (): void {
            });
            $this->fail('op app di luar capabilities wajib ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNSUPPORTED, $e->reason);
            $this->assertStringContainsString('deploy', $e->getMessage());
        }

        $this->assertSame(0, $handler->count(), 'op yang tidak didukung tidak boleh sampai ke agen');
    }

    public function testAppOpIsRejectedWhenAgentIsTooOld(): void
    {
        $server = $this->agent(id: 'vps-1', agentVersion: '0.0.1');
        $handler = new AgentServerOpsHandler();
        $deployer = new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'))
        );

        try {
            $deployer->deploy(['id' => 'app-1', 'name' => 'demo'], static function (): void {
            });
            $this->fail('agen di bawah versi minimum wajib ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNSUPPORTED, $e->reason);
            $this->assertStringContainsString(AgentVersionGate::MIN_AGENT_VERSION, $e->getMessage());
        }

        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @return array{0:AgentServerOpsNginxController,1:AgentServerOpsHandler,2:ServerStore}
     */
    private function nginx(AgentServerOpsHandler $handler): array
    {
        $store = new ServerStore($this->db);
        $controller = new AgentServerOpsNginxController($store, $handler, $this->tmp);
        $controller->admin = true;
        $controller->localSpawns = &$this->localSpawns;

        return [$controller, $handler, $store];
    }

    /**
     * @return array{0:AgentServerOpsServerController,1:AgentServerOpsHandler}
     */
    private function serverController(AgentServerOpsHandler $handler): array
    {
        $store = new ServerStore($this->db);
        $controller = new AgentServerOpsServerController($store, $handler, $this->tmp);
        $controller->user = self::ADMIN;

        return [$controller, $handler];
    }

    private function stateFor(string $serverId): TemplatePullState
    {
        return new TemplatePullState($this->tmp . '/template-pull', $serverId);
    }

    /**
     * @return array<string,mixed>
     */
    private function agent(
        string $id,
        array $capabilities = DeployWorkerLauncher::MODES,
        string $minControlPlane = AgentVersionGate::MIN_CONTROL_PLANE_VERSION,
        string $agentVersion = AgentVersionGate::CODEBASE_VERSION,
    ): array {
        $server = (new ServerStore($this->db))->create([
            'id' => $id,
            'name' => $id,
            'kind' => 'agent',
            'enabled' => true,
            'domain' => $id . '.example.test',
            'base_url' => 'https://' . $id . '.internal.test:8443',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::pem(),
            'status' => 'online',
            'capabilities' => $capabilities,
            'agent_version' => $agentVersion,
            'min_control_plane_version' => $minControlPlane,
        ]);

        ServerResolver::forgetMemo();

        return $server;
    }

    private function formPost(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request("POST {$path} HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }

    private function getRequest(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /**
     * @return array{0:int,1:array<string,string>,2:array<string,mixed>,3:string}
     */
    private function decode(\Webman\Http\Response $response): array
    {
        $raw = $response->rawBody();
        $body = $raw === '' ? [] : json_decode($raw, true);

        return [$response->getStatusCode(), $response->getHeaders(), is_array($body) ? $body : [], $raw];
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->db,
                'database_path' => $this->tmp,
                'app_domain' => 'localhost',
                'templates_path' => dirname(__DIR__) . '/templates',
                'template_pull_run_dir' => $this->tmp . '/template-pull',
                'template_pull_timeout' => 5,
                'docker_binary' => 'docker-test',
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function pem(): string
    {
        if (self::$pem !== null) {
            return self::$pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $exported);

        return self::$pem = (string) $exported;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Handler Guzzle tiruan (tanpa jaringan): merekam method/path/body dan melayani
 * respons dari antrean. Dideklarasikan di berkas ini supaya tetap dapat
 * di-autoload saat dijalankan dengan `--filter`.
 */
final class AgentServerOpsHandler
{
    /** @var array<int,array{method:string,path:string,body:string}> */
    public array $calls = [];

    /** @var list<GuzzleResponse|\Throwable> */
    private array $queue = [];

    /** @param array<string,string> $headers */
    public function reply(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new GuzzleResponse($status, $headers + ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /**
     * @return array{method:string,path:string,body:string}
     */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total {$this->count()})");
        }

        return $this->calls[$index];
    }

    /** @return array<string,mixed> */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->call($index)['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __invoke(object $request, array $options): mixed
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'body' => (string) $request->getBody(),
        ];

        if ($this->queue === []) {
            throw new \RuntimeException('AgentServerOpsHandler: antrean respons habis');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * `NginxController` dengan seam: resolver ber-store temp, deployer agent
 * ber-handler tiruan, dan service pull lokal ber-spawner palsu.
 */
final class AgentServerOpsNginxController extends NginxController
{
    public bool $admin = true;

    /** @var array<int,array<int,string>> */
    public array $localSpawns = [];

    /** @param array<int,string> */
    private array $spawnArgv = [];

    public function __construct(
        private readonly ServerStore $store,
        private readonly AgentServerOpsHandler $handler,
        private readonly string $tmp,
    ) {
    }

    protected function isAdmin(): bool
    {
        return $this->admin;
    }

    protected function actorName(): string
    {
        return 'admin';
    }

    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver($this->store);
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        if (ServerResolver::isLocal($server)) {
            return null;
        }

        $client = new AgentClient($server, $this->handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));

        return AgentDeployer::forServer($server, $client);
    }

    protected function pullService(): TemplatePullService
    {
        return new TemplatePullService(
            new TemplateCatalog(dirname(__DIR__) . '/templates'),
            new TemplatePullState($this->tmp . '/template-pull'),
            function (array $argv, ?int &$pid = null): bool {
                $this->localSpawns[] = $argv;
                $pid = 4242;

                return true;
            },
            dirname(__DIR__) . '/cli/pull.php'
        );
    }
}

/**
 * `ServerController` dengan seam: store temp + user tersuntik + deployer agent
 * ber-handler tiruan (tanpa jaringan).
 */
final class AgentServerOpsServerController extends ServerController
{
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    public function __construct(
        private readonly ServerStore $store,
        private readonly AgentServerOpsHandler $handler,
        private readonly string $tmp,
    ) {
        parent::__construct($store, null, new TlsPinMaterializer($this->tmp . '/agent-tls'));
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function agentClient(array $server): AgentClient
    {
        return new AgentClient($server, $this->handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));
    }

    protected function flash(string $type, string $message): void
    {
        // tanpa session di uji
    }

    protected function renderView(string $template, array $vars): \Webman\Http\Response
    {
        return new \Webman\Http\Response(200, [], '');
    }
}
