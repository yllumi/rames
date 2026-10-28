<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentAdminerController;
use app\controller\AgentController;
use app\controller\AgentDockerController;
use app\controller\AgentFilesController;
use app\controller\AgentJobController;
use app\controller\AgentLogController;
use app\controller\AgentNetworkController;
use app\controller\AgentTerminalController;
use app\controller\HealthController;
use app\controller\NetworkController;
use app\controller\VolumeController;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;
use Webman\Middleware;
use Webman\Route;

// Helper webman (`base_path()`, `config()`) membaca konstanta BASE_PATH —
// definisikan seperti test lain agar config/route.php & config/process.php bisa
// dimuat di luar runtime webman.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Gerbang mode runtime multi-server F0 (plan §4.1/§10.1) pada `config/route.php`
 * dan `config/process.php`.
 *
 * Kontrak yang dikunci:
 *  1. mode `agent` TANPA `AGENT_TOKEN` → **tidak ada** route yang terdaftar
 *     (fail-closed; semua 404);
 *  2. mode `agent` DENGAN token → hanya route agent (`GET /healthz`,
 *     `GET /version`, `POST /jobs`, `GET /jobs/{id}`, `GET /jobs/{id}/log`,
 *     `GET /apps/{id}`), dan route dashboard benar-benar **tidak terdaftar**
 *     (bukan disembunyikan);
 *  3. mode `dashboard` (default) → tabel route identik dengan sebelum gerbang
 *     mode ditambahkan (jumlah route + route penting tetap ada, `/healthz`
 *     masih milik HealthController);
 *  4. mode `agent` → timer khusus dashboard (update-check/billing/db-backup)
 *     tidak dipasang; timer lokal (webman/monitor) tetap;
 *  5. route agent tidak memasang middleware sesi/CSRF apa pun;
 *  6. middleware GLOBAL (`config/middleware.php`): `dashboard` tetap
 *     CSRF → Auth → StaticFile, `agent` hanya `AgentAuthMiddleware` (penegak
 *     bearer token + timestamp + nonce; tanpa sesi/CSRF/aset statis).
 *
 * Berkas diuji apa adanya (di-`require` ulang per mode setelah `Route::load([])`
 * membersihkan tabel), jadi yang diverifikasi adalah perilaku nyata berkasnya,
 * bukan salinan logikanya. Tidak menyentuh data runtime: konfigurasi mode/token
 * ditulis ke direktori temp dan registry Config dipulihkan di tearDown.
 */
class AgentModeTest extends TestCase
{
    /**
     * Snapshot jumlah route dashboard sebelum gerbang mode (F0). Bila misi lain
     * menambah/mengubah route dashboard, perbarui angka ini **beserta** alasannya.
     *
     * +8 (misi F0 registry server / multi-server): `GET /servers`,
     * `GET /servers/create`, `POST /servers`, `GET /servers/{id}`,
     * `POST /servers/{id}`, `POST /servers/{id}/delete`, `POST /servers/{id}/test`,
     * `GET /api/servers` — semuanya admin-only (non-admin ⇒ 404) dan **tidak**
     * boleh terdaftar di mode agent.
     *
     * +1 (F4 lanjutan: update agen dari control plane): `POST /servers/{id}/agent-update`
     * — admin-only (non-admin ⇒ 404) dan sama-sama **tidak** boleh ada di mode agent.
     *
     * +1 (F4 lanjutan: unduh hasil `db_dump`): `GET /backups/dump/sql` — ability
     * `backup` (operator+); volume server agent dibaca dari server itu.
     *
     * +2 (SSL mode AJAX, SPECS §8a): `GET /api/ssl/status` &
     * `POST /api/ssl/{id}/enable` — ability `ssl` (operator+); keduanya
     * dashboard-only (halaman/JSON tabel SSL tidak ada di agent) dan tetap wajib
     * CSRF (`CsrfMiddleware` tanpa pengecualian baru).
     *
     * +1 (log deploy on-demand, modal detail app): `GET /api/apps/{id}/deploy-log`
     * — ability `logs` (Viewer+), dashboard-only (membaca log worker di host ini;
     * log app server agent sudah diteruskan worker ke berkas yang sama).
     * +1 (batalkan build): `POST /apps/{id}/deploy/cancel` — ability `deploy`
     * (Operator+), dashboard-only (menghentikan worker + grup proses build).
     * +1 (upload file .env): `POST /apps/{id}/env/upload` — ability `env`
     * (Operator+), dashboard-only. Server lokal menulis artefak env app dan
     * melewati guard `appArtifactDirOrFail()`; app server agent **tidak**
     * menulis artefak lokal — hanya metadata via `persistEnvForReview()` dengan
     * `$dir = null` — jadi guard tidak dipanggil di cabang itu (guard tetap
     * dipakai cabang lokal/import).
     * +6 (halaman domain parking): `GET /domains`, `GET /domains/create`,
     * `POST /domains`, `GET /domains/{id}/edit`, `POST /domains/{id}`,
     * `POST /domains/{id}/delete` — pengelolaan admin-only (non-admin ⇒ 404),
     * fase 1 hanya server lokal, dan **tidak** boleh terdaftar di mode agent.
     * `GET /domains` sendiri terbuka untuk semua user login (tabel app+parkir).
     *
     * +3 (penyatuan SSL ke `/domains`, SPECS §8a/§8j): `GET /api/domains/status`,
     * `POST /domains/parked/{id}/ssl`, `POST /api/domains/parked/{id}/enable` —
     * status memakai otorisasi baris yang sama (`AppAccess::visible()`), aksi
     * aktifkan SSL parkir admin-only; semuanya dashboard-only (agent tidak
     * mengenal store domain parkir/SSL control plane) dan tetap wajib CSRF.
     * `GET /ssl` sekarang handler `DomainController::legacySsl` (redirect
     * `/domains`) tetapi route-nya **tetap terdaftar** satu definisi.
     *
     * +1 (ubah password self-service di halaman Profil): `POST /profile/password`
     * — semua user login (anti-IDOR: id dari sesi), dashboard-only (agent tidak
     * punya UI/sesi), tetap wajib CSRF.
     *
     * +3 (rebranding dashboard, tab Branding di Config): `POST /nginx/branding`,
     * `POST /nginx/branding/reset` (admin-only ⇒ 404, wajib CSRF), dan
     * `GET /branding/logo` (publik **path eksak** — halaman login butuh logo
     * sebelum sesi ada). Agent tidak punya halaman Config/login, jadi ketiganya
     * dashboard-only.
     *
     * +1 (grant pemilihan server per user): `POST /users/{id}/servers` —
     * admin-only (404 untuk non-admin), dashboard-only.
     */
    private const DASHBOARD_ROUTE_DEFINITIONS = 135;

    /** Definisi di atas menghasilkan 141 pasangan method+path (1 route `any` = 7). */
    private const DASHBOARD_ROUTE_PAIRS = 141;

    /** Route dashboard yang wajib ADA di mode dashboard & TIDAK ADA di mode agent. */
    private const DASHBOARD_SAMPLES = [
        'GET /login',
        'POST /login',
        'GET /apps',
        'POST /nginx/reload',
        'GET /monitor',
        'GET /database',
        'GET /ssl',
        'GET /backups',
        'GET /credits',
        'GET /users',
        'GET /api/monitor/overview',
        'GET /api/apps/{id}/status',
        // SSL mode AJAX (SPECS §8a): endpoint JSON status & enable tidak boleh
        // bocor ke mode agent (agent tidak melayani tabel SSL control plane).
        'GET /api/ssl/status',
        'POST /api/ssl/{id}/enable',
        // Penyatuan SSL ke /domains (§8a/§8j): status tabel gabungan + aksi SSL
        // domain parkir juga dashboard-only.
        'GET /api/domains/status',
        'POST /domains/parked/{id}/ssl',
        'POST /api/domains/parked/{id}/enable',
        // Registry server (F0 multi-server): halaman & daftar JSON-nya tidak boleh
        // bocor ke mode agent (agent tidak mengenal server lain).
        'GET /servers',
        'GET /api/servers',
        // Rebranding (tab Branding di Config + logo publik untuk halaman login):
        // tidak ada padanannya di agent.
        'GET /branding/logo',
        'POST /nginx/branding',
        'POST /nginx/branding/reset',
    ];

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_agent_mode_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        // `global $argv` di config/process.php harus punya nilai array.
        if (!isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) {
            $GLOBALS['argv'] = [];
        }
        $this->configState = $this->snapshotConfigState();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        Route::load([]); // buang tabel route milik test ini
        foreach (glob($this->tmp . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp . '/config');
        @rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // (i) agent tanpa token — fail-closed
    // ------------------------------------------------------------------

    public function testAgentWithoutTokenRegistersNoRouteAtAll(): void
    {
        $this->configureDeploy('agent', '');
        $routes = $this->loadRouteTable();

        $this->assertSame([], $routes['definitions'], 'mode agent tanpa token harus mendaftarkan 0 route');
        $this->assertSame([], $routes['pairs'], 'tabel route agent tanpa token harus kosong (semua 404)');
    }

    public function testAgentWithWhitespaceOnlyTokenIsTreatedAsEmpty(): void
    {
        $this->configureDeploy('agent', "   \n");
        $routes = $this->loadRouteTable();

        $this->assertSame([], $routes['definitions'], 'token berisi spasi = token kosong (fail-closed)');
    }

    // ------------------------------------------------------------------
    // (ii) agent dengan token — hanya route diagnostik
    // ------------------------------------------------------------------

    public function testAgentWithTokenRegistersOnlyAgentRoutes(): void
    {
        $this->configureDeploy('agent', 'agt_test_token');
        $routes = $this->loadRouteTable();

        $pairs = array_keys($routes['pairs']);
        sort($pairs);
        $this->assertSame(
            [
                'DELETE /adminer/{container}[/{path:.*}]',
                'GET /adminer/{container}[/{path:.*}]',
                'GET /apps/{id}',
                'GET /apps/{id}/logs',
                'GET /apps/{id}/nginx',
                'GET /backups/dump',
                'GET /backups/snapshots',
                'GET /backups/volumes',
                'GET /database/containers',
                'GET /files/download',
                'GET /files/list',
                'GET /files/read',
                'GET /healthz',
                'GET /jobs/{id}',
                'GET /jobs/{id}/log',
                'GET /monitor/containers',
                'GET /networks',
                'GET /networks/{id}',
                'GET /system/host-usage',
                'GET /terminal/{token}/stream',
                'GET /version',
                'GET /volumes',
                'HEAD /adminer/{container}[/{path:.*}]',
                'OPTIONS /adminer/{container}[/{path:.*}]',
                'PATCH /adminer/{container}[/{path:.*}]',
                'POST /adminer/{container}[/{path:.*}]',
                'POST /apps/check',
                'POST /files/delete',
                'POST /files/extract',
                'POST /files/mkdir',
                'POST /files/move',
                'POST /files/rename',
                'POST /files/upload',
                'POST /files/write',
                'POST /jobs',
                'POST /jobs/{id}/cancel',
                'POST /networks/create',
                'POST /networks/{id}/connect',
                'POST /networks/{id}/delete',
                'POST /networks/{id}/disconnect',
                'POST /nginx/check',
                'POST /terminal/open',
                'POST /terminal/run',
                'POST /terminal/{token}/close',
                'POST /terminal/{token}/input',
                'PUT /adminer/{container}[/{path:.*}]',
            ],
            $pairs
        );

        $this->assertSame(
            [AgentController::class, 'health'],
            $routes['pairs']['GET /healthz']->getCallback()
        );
        $this->assertSame(
            [AgentController::class, 'version'],
            $routes['pairs']['GET /version']->getCallback()
        );
        // Job async (F1): agent menerima job deploy dari control plane.
        $this->assertSame(
            [AgentJobController::class, 'create'],
            $routes['pairs']['POST /jobs']->getCallback()
        );
        $this->assertSame(
            [AgentJobController::class, 'show'],
            $routes['pairs']['GET /jobs/{id}']->getCallback()
        );
        $this->assertSame(
            [AgentJobController::class, 'log'],
            $routes['pairs']['GET /jobs/{id}/log']->getCallback()
        );
        // Proyeksi app (F1): control plane menarik state eksekusi sesudah job.
        $this->assertSame(
            [AgentJobController::class, 'showApp'],
            $routes['pairs']['GET /apps/{id}']->getCallback()
        );
        // Nginx (F2): perakitan config dibaca dari server agent + preflight
        // direktori Nginx server itu (bukan host control plane).
        $this->assertSame(
            [AgentJobController::class, 'renderNginx'],
            $routes['pairs']['GET /apps/{id}/nginx']->getCallback()
        );
        $this->assertSame(
            [AgentJobController::class, 'checkNginx'],
            $routes['pairs']['POST /nginx/check']->getCallback()
        );
        // Preflight create app (F1 lanjutan): ketersediaan direktori `apps/<name>`
        // di server ini sebelum control plane menulis record app.
        $this->assertSame(
            [AgentJobController::class, 'checkApp'],
            $routes['pairs']['POST /apps/check']->getCallback()
        );
        // Operasional per server (F3a): log container + terminal yang berjalan di
        // server ini (sesi hidup di agent, control plane mem-proxy aliran SSE-nya).
        $this->assertSame(
            [AgentLogController::class, 'index'],
            $routes['pairs']['GET /apps/{id}/logs']->getCallback()
        );
        $this->assertSame(
            [AgentTerminalController::class, 'openSession'],
            $routes['pairs']['POST /terminal/open']->getCallback()
        );
        $this->assertSame(
            [AgentTerminalController::class, 'streamSession'],
            $routes['pairs']['GET /terminal/{token}/stream']->getCallback()
        );
        $this->assertSame(
            [AgentTerminalController::class, 'inputSession'],
            $routes['pairs']['POST /terminal/{token}/input']->getCallback()
        );
        $this->assertSame(
            [AgentTerminalController::class, 'closeSession'],
            $routes['pairs']['POST /terminal/{token}/close']->getCallback()
        );
        // Perintah satu-kali (tombol "Run") dijalankan di server ini lewat
        // mekanisme yang sama dengan jalur lokal (non-interaktif, timeout).
        $this->assertSame(
            [AgentTerminalController::class, 'runSession'],
            $routes['pairs']['POST /terminal/run']->getCallback()
        );
        // Operasional per server (F3b/F3c): file manager container & monitoring
        // dijalankan DI server agent (control plane mem-proxy hasilnya).
        $this->assertSame(
            [AgentFilesController::class, 'list'],
            $routes['pairs']['GET /files/list']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'read'],
            $routes['pairs']['GET /files/read']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'download'],
            $routes['pairs']['GET /files/download']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'write'],
            $routes['pairs']['POST /files/write']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'mkdir'],
            $routes['pairs']['POST /files/mkdir']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'rename'],
            $routes['pairs']['POST /files/rename']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'delete'],
            $routes['pairs']['POST /files/delete']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'move'],
            $routes['pairs']['POST /files/move']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'upload'],
            $routes['pairs']['POST /files/upload']->getCallback()
        );
        $this->assertSame(
            [AgentFilesController::class, 'extract'],
            $routes['pairs']['POST /files/extract']->getCallback()
        );
        $this->assertSame(
            [AgentDockerController::class, 'hostUsage'],
            $routes['pairs']['GET /system/host-usage']->getCallback()
        );
        $this->assertSame(
            [AgentDockerController::class, 'containers'],
            $routes['pairs']['GET /monitor/containers']->getCallback()
        );
        // Operasional per server (F3d): database/Adminer & network/volume app
        // yang berjalan di server ini (helper Adminer hidup di sini; control plane
        // mem-forward permintaannya).
        $this->assertSame(
            [AgentAdminerController::class, 'containers'],
            $routes['pairs']['GET /database/containers']->getCallback()
        );
        $this->assertSame(
            [AgentAdminerController::class, 'proxy'],
            $routes['pairs']['POST /adminer/{container}[/{path:.*}]']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'index'],
            $routes['pairs']['GET /networks']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'detail'],
            $routes['pairs']['GET /networks/{id}']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'volumes'],
            $routes['pairs']['GET /volumes']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'create'],
            $routes['pairs']['POST /networks/create']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'connect'],
            $routes['pairs']['POST /networks/{id}/connect']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'disconnect'],
            $routes['pairs']['POST /networks/{id}/disconnect']->getCallback()
        );
        $this->assertSame(
            [AgentNetworkController::class, 'delete'],
            $routes['pairs']['POST /networks/{id}/delete']->getCallback()
        );
    }

    public function testAgentModeDropsEveryDashboardRoute(): void
    {
        $this->configureDeploy('agent', 'agt_test_token');
        $routes = $this->loadRouteTable();

        foreach (self::DASHBOARD_SAMPLES as $sample) {
            $this->assertArrayNotHasKey($sample, $routes['pairs'], "route dashboard {$sample} bocor ke mode agent");
        }
        // `/healthz` sebelumnya milik HealthController dashboard; di agent harus
        // digantikan controller agent, bukan didaftarkan dua kali.
        $this->assertNotSame(
            [HealthController::class, 'index'],
            $routes['pairs']['GET /healthz']->getCallback()
        );
        $this->assertCount(
            40,
            $routes['definitions'],
            'route agent F0 (diagnostik) + F1 (3 job + proyeksi app + preflight nama app + cancel job) + F2 (nginx)'
            . ' + F3a (log + 5 terminal)'
            . ' + F3b/F3c (10 file manager + 2 monitoring) + F3d (1 database + 1 adminer + 7 network/volume)'
            . ' + F4 (3 route data: daftar volume backup + daftar snapshot + unduh dump bertahap)'
        );
    }

    public function testAgentRoutesCarryNoSessionOrCsrfMiddleware(): void
    {
        $this->configureDeploy('agent', 'agt_test_token');
        $routes = $this->loadRouteTable();

        foreach ($routes['pairs'] as $pair => $route) {
            $this->assertSame(
                [],
                $route->getMiddleware(),
                "route agent {$pair} tidak boleh memasang middleware sesi/CSRF"
            );
        }
        // Middleware **global** (yang benar-benar membungkus request worker)
        // diuji terpisah di bawah — di sana gerbangnya memang berada,
        // config/middleware.php.
    }

    // ------------------------------------------------------------------
    // (iii) dashboard — perilaku lama tidak berubah
    // ------------------------------------------------------------------

    public function testDashboardModeKeepsEveryRoute(): void
    {
        $this->configureDeploy('dashboard', '');
        $routes = $this->loadRouteTable();

        $this->assertCount(
            self::DASHBOARD_ROUTE_DEFINITIONS,
            $routes['definitions'],
            'jumlah definisi route dashboard berubah dari baseline F0'
        );
        $this->assertCount(self::DASHBOARD_ROUTE_PAIRS, $routes['pairs']);

        foreach (self::DASHBOARD_SAMPLES as $sample) {
            $this->assertArrayHasKey($sample, $routes['pairs'], "route dashboard {$sample} hilang");
        }
        $this->assertSame(
            [HealthController::class, 'index'],
            $routes['pairs']['GET /healthz']->getCallback(),
            'mode dashboard tetap memakai HealthController untuk /healthz'
        );
        $this->assertArrayNotHasKey('GET /version', $routes['pairs'], 'route agent tidak boleh ada di dashboard');
        // Route agent F3a (operasional per server) juga tidak boleh terdaftar di sini.
        foreach ([
            'GET /apps/{id}/logs',
            'POST /terminal/open',
            'GET /terminal/{token}/stream',
            'POST /terminal/{token}/input',
            'POST /terminal/{token}/close',
        ] as $agentPair) {
            $this->assertArrayNotHasKey($agentPair, $routes['pairs'], "route agent {$agentPair} bocor ke mode dashboard");
        }
        // Route agent F3b/F3c (file manager per server & monitoring per server):
        // dashboard memakai endpoint UI-nya sendiri (`/api/apps/{id}/files*`,
        // `/apps/{id}/files/*`, `/api/monitor/*`) — bukan endpoint mesin ini.
        foreach ([
            'GET /files/list',
            'GET /files/read',
            'GET /files/download',
            'POST /files/write',
            'POST /files/mkdir',
            'POST /files/rename',
            'POST /files/delete',
            'POST /files/move',
            'POST /files/upload',
            'POST /files/extract',
            'GET /system/host-usage',
            'GET /monitor/containers',
        ] as $agentPair) {
            $this->assertArrayNotHasKey($agentPair, $routes['pairs'], "route agent {$agentPair} bocor ke mode dashboard");
        }

        // F3d: route mesin DB/Adminer & network/volume tidak boleh terdaftar di
        // dashboard. Path yang memang dipakai UI dashboard (`GET /networks`,
        // `/volumes`, `POST /networks/*`) tetap ada — tetapi handler-nya harus
        // handler dashboard, BUKAN controller agent.
        foreach ([
            'GET /database/containers',
            'GET /adminer/{container}[/{path:.*}]',
            'POST /adminer/{container}[/{path:.*}]',
        ] as $agentPair) {
            $this->assertArrayNotHasKey($agentPair, $routes['pairs'], "route agent {$agentPair} bocor ke mode dashboard");
        }
        $this->assertSame([NetworkController::class, 'index'], $routes['pairs']['GET /networks']->getCallback());
        $this->assertSame([VolumeController::class, 'index'], $routes['pairs']['GET /volumes']->getCallback());
        $this->assertSame([NetworkController::class, 'connect'], $routes['pairs']['POST /networks/{id}/connect']->getCallback());
        $this->assertSame([NetworkController::class, 'delete'], $routes['pairs']['POST /networks/{id}/delete']->getCallback());
    }

    public function testDashboardModeIgnoresAgentToken(): void
    {
        $this->configureDeploy('dashboard', 'agt_test_token');
        $routes = $this->loadRouteTable();

        $this->assertCount(self::DASHBOARD_ROUTE_DEFINITIONS, $routes['definitions']);
        $this->assertArrayNotHasKey('GET /version', $routes['pairs'], 'token saja tidak memindahkan peran ke agent');
    }

    public function testUnknownModeFallsBackToDashboard(): void
    {
        // Normalisasi nilai tak sah ada di config/deploy.php; berkas route juga
        // harus fail-safe (bukan menganggap mode asing sebagai agent).
        $this->configureDeploy('agnt', 'agt_test_token');
        $routes = $this->loadRouteTable();

        $this->assertCount(self::DASHBOARD_ROUTE_DEFINITIONS, $routes['definitions']);
        $this->assertArrayNotHasKey('GET /version', $routes['pairs']);
    }

    // ------------------------------------------------------------------
    // (4) timer dashboard mati di mode agent
    // ------------------------------------------------------------------

    public function testDashboardTimersAreNotStartedInAgentMode(): void
    {
        $processes = $this->loadProcesses('agent');

        $this->assertArrayNotHasKey('update-check', $processes, 'cek update dashboard tidak boleh start di agent');
        $this->assertArrayNotHasKey('billing', $processes, 'meteran/penagihan tidak boleh start di agent');
        $this->assertArrayNotHasKey('db-backup', $processes, 'backup DB dashboard tidak boleh start di agent');
        // Timer lokal (worker + pemantau berkas Workerman) tetap ada.
        $this->assertArrayHasKey('webman', $processes);
        $this->assertArrayHasKey('monitor', $processes);
    }

    public function testDashboardModeKeepsEveryTimer(): void
    {
        $processes = $this->loadProcesses('dashboard');

        $this->assertSame(
            ['webman', 'monitor', 'update-check', 'billing', 'db-backup'],
            array_keys($processes)
        );
    }

    public function testUnknownModeKeepsDashboardTimers(): void
    {
        $processes = $this->loadProcesses('agnt');

        $this->assertArrayHasKey('billing', $processes);
        $this->assertArrayHasKey('db-backup', $processes);
        $this->assertArrayHasKey('update-check', $processes);
    }

    // ------------------------------------------------------------------
    // (6) middleware global per mode
    // ------------------------------------------------------------------

    public function testDashboardGlobalMiddlewareIsUnchanged(): void
    {
        $config = $this->loadMiddleware('dashboard');

        $this->assertSame(
            ['app\middleware\CsrfMiddleware', 'app\middleware\AuthMiddleware', 'app\middleware\StaticFile'],
            $config[''] ?? null,
            'mode dashboard wajib tetap CSRF → Auth → StaticFile (urutan sama)'
        );
        $this->assertSame([''], array_keys($config), 'hanya key default app yang dipakai');
    }

    public function testAgentGlobalMiddlewareIsOnlyAgentAuth(): void
    {
        $config = $this->loadMiddleware('agent');

        $this->assertSame(
            ['app\middleware\AgentAuthMiddleware'],
            $config[''] ?? null,
            'mode agent: satu-satunya middleware global adalah penegak token agent'
        );
        $this->assertNotContains('app\middleware\AuthMiddleware', $config[''] ?? []);
        $this->assertNotContains('app\middleware\CsrfMiddleware', $config[''] ?? []);
        $this->assertNotContains('app\middleware\StaticFile', $config[''] ?? []);
    }

    /**
     * Rantai yang benar-benar dipasang webman untuk request default app
     * (`Middleware::getMiddleware`) — bukan hanya isi berkas konfigurasi.
     */
    public function testResolvedGlobalMiddlewareChainPerMode(): void
    {
        $agent = $this->resolvedGlobalMiddleware($this->loadMiddleware('agent'));
        $this->assertSame(
            ['app\middleware\AgentAuthMiddleware'],
            array_map(static fn (array $item): string => (string) $item[0], $agent),
            'request worker agent hanya boleh melewati penegak token agent (tanpa sesi/CSRF/aset statis)'
        );

        $dashboard = $this->resolvedGlobalMiddleware($this->loadMiddleware('dashboard'));
        // Webman membalik urutan daftar saat menyusun rantai, sehingga CSRF
        // menjadi lapisan terluar — efeknya tetap CSRF → Auth → StaticFile.
        $this->assertSame(
            ['app\middleware\StaticFile', 'app\middleware\AuthMiddleware', 'app\middleware\CsrfMiddleware'],
            array_map(static fn (array $item): string => (string) $item[0], $dashboard)
        );
    }

    public function testUnknownModeKeepsDashboardMiddleware(): void
    {
        $this->assertSame(
            $this->loadMiddleware('dashboard')[''],
            $this->loadMiddleware('agnt')[''],
            'mode tak sah tidak boleh mematikan middleware sesi/CSRF'
        );
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Muat mode/token tiruan ke registry Config webman — sumber yang benar-benar
     * dibaca config/route.php (`config('deploy.mode')` / `config('deploy.agent_token')`).
     */
    private function configureDeploy(string $mode, string $token): void
    {
        $dir = $this->tmp . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // Config::loadFromDir() hanya memuat berkas di direktori yang punya app.php.
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents(
            $dir . '/deploy.php',
            '<?php return ' . var_export(['mode' => $mode, 'agent_token' => $token], true) . ';' . PHP_EOL
        );
        Config::clear();
        Config::load($dir);
    }

    /**
     * Jalankan config/route.php dan kembalikan tabel route-nya.
     *
     * @return array{definitions: array<int,\Webman\Route\Route>, pairs: array<string,\Webman\Route\Route>}
     */
    private function loadRouteTable(): array
    {
        Route::load([]); // tabel bersih + collector siap (webman melakukan ini saat bootstrap)
        require BASE_PATH . '/config/route.php';

        $definitions = [];
        $pairs = [];
        foreach (Route::getRoutes() as $route) {
            $definitions[] = $route;
            foreach ($route->getMethods() as $method) {
                $pairs[strtoupper((string) $method) . ' ' . $route->getPath()] = $route;
            }
        }

        return ['definitions' => $definitions, 'pairs' => $pairs];
    }

    /**
     * Jalankan config/process.php dengan mode dari env `RAMES_MODE`.
     *
     * Mode `agent` menuntut TLS (fail-fast di `config/process.php`, misi
     * Deploy & Docker): test ini memberi pasangan cert/key temp agar yang diuji
     * adalah daftar timer, bukan gerbang TLS. Kontrak TLS sendiri diuji di
     * tests/AgentProcessConfigTest.php.
     *
     * @return array<string,mixed>
     */
    private function loadProcesses(string $mode): array
    {
        $dir = $this->tmp . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        // config/process.php memanggil Log::channel('default') saat dievaluasi.
        file_put_contents($dir . '/log.php', "<?php return " . var_export(['default' => ['handlers' => [[]]]], true) . ";\n");
        Config::clear();
        Config::load($dir);

        putenv('RAMES_MODE=' . $mode);
        if ($mode === 'agent') {
            // Berkas di dalam $dir agar ikut dibersihkan tearDown.
            file_put_contents($dir . '/agent-tls.crt', "-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----\n");
            file_put_contents($dir . '/agent-tls.key', "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----\n");
            putenv('AGENT_TLS_CERT=' . $dir . '/agent-tls.crt');
            putenv('AGENT_TLS_KEY=' . $dir . '/agent-tls.key');
        }
        try {
            return require BASE_PATH . '/config/process.php';
        } finally {
            putenv('RAMES_MODE');
            putenv('AGENT_TLS_CERT');
            putenv('AGENT_TLS_KEY');
        }
    }

    /**
     * Jalankan config/middleware.php dengan mode dari env `RAMES_MODE`.
     *
     * @return array<string,array<int,string>>
     */
    private function loadMiddleware(string $mode): array
    {
        putenv('RAMES_MODE=' . $mode);
        try {
            return require BASE_PATH . '/config/middleware.php';
        } finally {
            putenv('RAMES_MODE');
        }
    }

    /**
     * Rantai middleware global hasil resolusi webman untuk default app.
     *
     * Menyentuh state statis `Webman\Middleware` — dipulihkan lagi di sini
     * (tidak menunggu tearDown) supaya test berikutnya tidak melihat campuran.
     *
     * @param array<string,array<int,string>> $config
     * @return array<int,array{0:string,1:string}>
     */
    private function resolvedGlobalMiddleware(array $config): array
    {
        $saved = $this->readStatic(Middleware::class, 'instances');
        try {
            $this->writeStatic(Middleware::class, 'instances', []);
            Middleware::load($config);

            return Middleware::getMiddleware(
                '',
                '',
                [AgentController::class, 'health'],
                null
            );
        } finally {
            $this->writeStatic(Middleware::class, 'instances', $saved);
        }
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
            $this->writeStatic(Config::class, $name, $value);
        }
    }

    private function readStatic(string $class, string $property): mixed
    {
        $prop = new ReflectionProperty($class, $property);
        $prop->setAccessible(true);

        return $prop->getValue();
    }

    private function writeStatic(string $class, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($class, $property);
        $prop->setAccessible(true);
        $prop->setValue(null, $value);
    }
}
