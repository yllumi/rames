<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Agent\AgentArtifactPackager;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\EnvManager;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Git\GitService;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Webman\Config;

/**
 * Handler Guzzle tiruan untuk uji "agent palsu" (tanpa jaringan) — merekam
 * setiap permintaan (method, path, body) dan melayani respons dari antrean.
 *
 * Sengaja dideklarasikan di berkas ini (bukan dipakai ulang dari
 * `AgentDeployerTest`) supaya uji tetap jalan saat dijalankan dengan
 * `--filter` (kelas di berkas uji lain tidak dapat di-autoload PSR-4).
 */
final class AppControllerServerFakeAgent
{
    /** @var array<int,array{method:string,path:string,query:string,body:string}> */
    public array $calls = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    /** @param array<string,string> $headers */
    public function reply(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    /**
     * @return array{method:string,path:string,query:string,body:string}
     */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total " . count($this->calls) . ')');
        }

        return $this->calls[$index];
    }

    /** Body JSON panggilan ke-`$index` sebagai array. */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->call($index)['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AppControllerServerFakeAgent: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * Repo git tiruan untuk jalur analisis wizard create (`createPreview`): merekam
 * panggilan `clone()` dan menulis `docker-compose.yml` di tujuannya — tanpa
 * jaringan/git nyata.
 *
 * Dideklarasikan di berkas ini (bukan dipakai ulang dari berkas uji lain) supaya
 * kelas ini tetap dapat di-autoload saat dijalankan dengan `--filter`.
 */
final class AppControllerServerFakeGit extends GitService
{
    /** @var array<int,array{repo:string,branch:string,dest:string,sshKey:?string}> */
    public array $clones = [];

    public const COMPOSE = "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n";

    /** Sengaja tidak memanggil konstruktor induk: tidak ada proses yang dijalankan. */
    public function __construct()
    {
    }

    public function clone(string $repoUrl, string $branch, string $dest, ?string $sshKeyPath = null): void
    {
        $this->clones[] = [
            'repo' => $repoUrl,
            'branch' => $branch,
            'dest' => $dest,
            'sshKey' => $sshKeyPath,
        ];

        if (!is_dir($dest) && !mkdir($dest, 0777, true) && !is_dir($dest)) {
            throw new RuntimeException('fixture: gagal membuat direktori clone');
        }
        file_put_contents($dest . '/docker-compose.yml', self::COMPOSE);
    }
}

/**
 * Request dengan sesi nyata (in-process) untuk jalur wizard create:
 * `createPreview()` menyimpan `pending_app` di session, sedangkan
 * `Request::session()` workerman butuh koneksi nyata — di luar runtime ia gagal
 * ("Request->session() fail, header already send"). Berkas sesi diarahkan ke
 * direktori temp uji lewat `session.save_path` (bukan runtime aplikasi).
 */
final class AppControllerServerSessionRequest extends Request
{
    private ?\Workerman\Protocols\Http\Session $session = null;

    public function session(): \Workerman\Protocols\Http\Session
    {
        return $this->session ??= new \Workerman\Protocols\Http\Session('uji-' . bin2hex(random_bytes(6)));
    }
}

/**
 * Scope server pada wizard create & jalur app-scoped (`AppController`, misi
 * `ms-f1-create-server`; plan §4, §4.1, §4.3, §9 keputusan 3, §11 catatan 6).
 *
 * Yang dikunci:
 *  1. `POST /apps/create/*` menerima `server_id`: default server **lokal**
 *     (perilaku lama), admin boleh memilih server agent, non-admin **tidak**
 *     (ditolak sebelum lookup ⇒ keberadaan server lain tidak bocor), id tak
 *     dikenal **ditolak** (tidak pernah jatuh ke lokal), server `enabled=false` /
 *     `offline` / agent tanpa capability `deploy` ditolak dengan pesan jelas
 *     ("Test connection" untuk capability);
 *  2. validasi nama app, subdomain, dan port **per server** dipakai jalur create;
 *  3. jalur app-scoped yang masih menulis artefak lokal (env, import env, limits,
 *     jaringan eksternal, nama container) menolak app di server agent dengan
 *     alasan sesungguhnya ("belum aktif untuk server agent (fase F2/F3)") —
 *     bukan "Direktori app tidak ada"; server lokal tidak berubah;
 *  4. create-form mengirim kontrak view `servers`/`selectedServerId`/`canSelectServer`.
 *
 * Data & konfigurasi 100% temp (`sys_get_temp_dir()`): `database/rames.sqlite`,
 * `apps/`, `nginx-status/`, Docker Engine, dan worker deploy nyata **tidak
 * pernah** disentuh (spawn worker lewat seam `DeployWorkerLauncher`).
 */
class AppControllerServerScopeTest extends TestCase
{
    /** @var array<string,mixed> */
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];

    /** @var array<string,mixed> */
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];

    /** Server agent contoh — nama/domain/token-nya tidak boleh bocor ke non-admin. */
    private const AGENT_ID = 'vps-rahasia';
    private const AGENT_TOKEN = 'tok-uji-rahasia-9f3c';

    /**
     * Stub helper global (dipakai hanya di test ber-`RunInSeparateProcess`).
     * `flash_set()` merekam pesan ke `$GLOBALS['RAMES_FLASH']` supaya jalur
     * controller yang menolak eksplisit bisa diperiksa tanpa session Webman.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return $GLOBALS['RAMES_TEST_USER'] ?? null;
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                    $GLOBALS['RAMES_FLASH'][] = ['type' => $type, 'message' => $message];
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('csrf_token')) {
                function csrf_token(): string
                {
                    return 'uji-csrf-token';
                }
            }
            if (!function_exists('csrf_field')) {
                function csrf_field(): string
                {
                    return '<input type="hidden" name="_token" value="uji-csrf-token">';
                }
            }
            if (!function_exists('session')) {
                function session(): object
                {
                    return new class {
                        public function get(?string $key = null): mixed
                        {
                            return $key === 'user' ? ($GLOBALS['RAMES_TEST_USER'] ?? null) : null;
                        }

                        public function set(string $key, mixed $value): void
                        {
                        }

                        public function delete(string $key): void
                        {
                        }

                        public function pull(string $key): mixed
                        {
                            return null;
                        }
                    };
                }
            }
        }
        PHP;

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-appctrl-server-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/apps', 0777, true);
        mkdir($this->tmp . '/nginx/conf', 0777, true);
        mkdir($this->tmp . '/logs/deploy', 0777, true);

        $GLOBALS['RAMES_FLASH'] = [];
        $GLOBALS['RAMES_SPAWN'] = [];
        $this->configState = $this->snapshotConfigState();

        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
        unset($GLOBALS['RAMES_FLASH'], $GLOBALS['RAMES_SPAWN'], $GLOBALS['RAMES_TEST_USER']);
    }

    // ==================================================================
    // (1) Pemilihan server: lokal default, admin-only, fail-fast
    // ==================================================================

    public function testDefaultAndExplicitLocalServerAreAcceptedForEveryone(): void
    {
        foreach ([self::ADMIN, self::MEMBER, null] as $user) {
            $this->assertSame(
                ServerStore::LOCAL_ID,
                $this->invoke('resolveCreateServer', ['', $user, new ServerResolver()])['id'],
                'kosong ⇒ server lokal'
            );
            $this->assertSame(
                ServerStore::LOCAL_ID,
                $this->invoke('resolveCreateServer', [ServerStore::LOCAL_ID, $user, new ServerResolver()])['id'],
                '`local` eksplisit ⇒ server lokal (tidak butuh admin)'
            );
        }
    }

    public function testUnknownServerIdIsRejectedAndNothingIsStored(): void
    {
        $this->agent(self::AGENT_ID);

        try {
            $this->invoke('resolveCreateServer', ['ghost-server', self::ADMIN, new ServerResolver()]);
            $this->fail('server_id tak dikenal harus ditolak (fail-fast, bukan jatuh ke lokal)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Server tidak dikenal', $e->getMessage());
            $this->assertStringContainsString('ghost-server', $e->getMessage());
        }

        $this->assertSame([], (new AppStore())->all(), 'tidak boleh ada app yang tersimpan');
    }

    /**
     * Non-admin tidak boleh memilih server selain server lokal — dan pesannya
     * **tidak membocorkan** apakah server itu ada (id tak dikenal & id milik
     * agent nyata menghasilkan pesan yang identik).
     */
    public function testNonAdminCannotSelectNonLocalServerAndExistenceDoesNotLeak(): void
    {
        $this->agent(self::AGENT_ID);

        $messages = [];
        foreach ([self::AGENT_ID, 'ghost-server'] as $requested) {
            try {
                $this->invoke('resolveCreateServer', [$requested, self::MEMBER, new ServerResolver()]);
                $this->fail('non-admin tidak boleh memilih server selain server lokal');
            } catch (RuntimeException $e) {
                $messages[$requested] = $e->getMessage();
            }
        }

        foreach ($messages as $requested => $message) {
            $this->assertStringContainsString('tidak berhak', $message);
            $this->assertStringNotContainsString($requested, $message, 'id server tidak boleh digemakan');
            $this->assertStringNotContainsString('tidak dikenal', $message, 'jangan pakai pesan lookup (oracle)');
            $this->assertStringNotContainsString('rahasia.example.test', $message, 'domain server tidak boleh bocor');
        }

        $this->assertSame(
            $messages['ghost-server'],
            $messages[self::AGENT_ID],
            'jawaban untuk id nyata & id asing harus identik (tanpa oracle keberadaan server)'
        );

        $this->assertSame([], (new AppStore())->all());
    }

    public function testAgentWithoutCapabilitiesIsRejectedWithTestConnectionHint(): void
    {
        foreach ([[], ['stop', 'start']] as $index => $capabilities) {
            $id = 'vps-baru-' . $index;
            $this->agent($id, ['capabilities' => $capabilities, 'status' => 'unknown']);

            try {
                $this->invoke('resolveCreateServer', [$id, self::ADMIN, new ServerResolver()]);
                $this->fail('agent tanpa capability `deploy` harus ditolak');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Test connection', $e->getMessage());
                $this->assertStringContainsString('deploy', $e->getMessage());
            }
        }
    }

    public function testDisabledAndOfflineServersAreRejected(): void
    {
        $this->agent('vps-mati', ['enabled' => false]);

        try {
            $this->invoke('resolveCreateServer', ['vps-mati', self::ADMIN, new ServerResolver()]);
            $this->fail('server non-aktif harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dinonaktifkan', $e->getMessage());
        }

        $this->agent('vps-down', ['status' => 'offline']);

        try {
            $this->invoke('resolveCreateServer', ['vps-down', self::ADMIN, new ServerResolver()]);
            $this->fail('server offline harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('offline', $e->getMessage());
            $this->assertStringContainsString('Test connection', $e->getMessage());
        }
    }

    public function testReadyAgentIsAcceptedForAdmin(): void
    {
        $this->agent(self::AGENT_ID);

        $server = $this->invoke('resolveCreateServer', [self::AGENT_ID, self::ADMIN, new ServerResolver()]);

        $this->assertSame(self::AGENT_ID, $server['id']);
        $this->assertSame('agent', $server['kind']);
    }

    // ==================================================================
    // (4) Kontrak view create-form
    // ==================================================================

    public function testServerFormContextAdminGetsWholeRegistryWithoutSecrets(): void
    {
        $this->agent(self::AGENT_ID);

        $context = $this->invoke('serverFormContext', [self::ADMIN, '']);

        $this->assertTrue($context['canSelectServer']);
        $this->assertSame(ServerStore::LOCAL_ID, $context['selectedServerId']);
        $this->assertSame(
            [ServerStore::LOCAL_ID, self::AGENT_ID],
            array_column($context['servers'], 'id')
        );

        $agent = $this->serverOption($context, self::AGENT_ID);
        $this->assertSame('agent', $agent['kind']);
        $this->assertSame(self::AGENT_ID . '.example.test', $agent['domain']);
        $this->assertTrue($agent['enabled']);
        $this->assertSame('online', $agent['status']);
        $this->assertContains('deploy', $agent['capabilities']);

        // Rahasia & alamat internal tidak pernah masuk daftar pilihan (HTML).
        $json = (string) json_encode($context);
        $this->assertStringNotContainsString(self::AGENT_TOKEN, $json, 'token agent bocor ke form');
        $this->assertStringNotContainsString('https://', $json, 'base_url agent bocor ke form');
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $json, 'PEM agent bocor ke form');
    }

    public function testServerFormContextNonAdminGetsOnlyLocalAndCannotForceSelection(): void
    {
        $this->agent(self::AGENT_ID);

        $context = $this->invoke('serverFormContext', [self::MEMBER, self::AGENT_ID]);

        $this->assertFalse($context['canSelectServer']);
        $this->assertSame([ServerStore::LOCAL_ID], array_column($context['servers'], 'id'));
        $this->assertSame(
            ServerStore::LOCAL_ID,
            $context['selectedServerId'],
            'pilihan non-lokal dari non-admin diabaikan (fallback server lokal)'
        );

        $json = (string) json_encode($context);
        $this->assertStringNotContainsString(self::AGENT_ID, $json, 'nama server lain tidak boleh dikirim ke browser');
        $this->assertStringNotContainsString('rahasia.example.test', $json, 'domain server lain tidak boleh dikirim');
    }

    public function testAdminSelectionIsEchoedWhenServerIsSelectable(): void
    {
        $this->agent(self::AGENT_ID);

        $context = $this->invoke('serverFormContext', [self::ADMIN, self::AGENT_ID]);

        $this->assertSame(self::AGENT_ID, $context['selectedServerId']);
    }

    /**
     * `server_id` dari request dibaca aman: nilai skalar dipakai apa adanya,
     * nilai non-skalar (upaya menabrak tipe) dianggap kosong ⇒ server lokal.
     */
    public function testRequestedServerIdIsParsedWithoutTrustingShape(): void
    {
        $plain = new Request(
            "POST /apps/create HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n\r\nserver_id=" . self::AGENT_ID
        );
        $this->assertSame(self::AGENT_ID, $this->invoke('requestedServerId', [$plain]));

        $array = new Request(
            "POST /apps/create HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n\r\nserver_id[]=" . self::AGENT_ID
        );
        $this->assertSame('', $this->invoke('requestedServerId', [$array]), 'array ⇒ diperlakukan kosong (server lokal)');

        $empty = new Request("POST /apps/create HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->assertSame('', $this->invoke('requestedServerId', [$empty]));
    }

    /**
     * Setiap pintu masuk wizard create wajib melewati `resolveCreateServer()`
     * dan memakai pemeriksaan **per server** (nama app, subdomain, port) —
     * dicek pada sumber karena jalur ini menyentuh disk/git dan tak bisa
     * dijalankan utuh tanpa efek samping.
     */
    public function testCreateEntryPointsUseServerScopedChecks(): void
    {
        foreach (['createPreview', 'composePreview', 'templateDeploy'] as $method) {
            $body = $this->methodBody($method);

            $this->assertStringContainsString('resolveCreateServer(', $body, $method);
            $this->assertStringContainsString('requestedServerId(', $body, $method);
            $this->assertStringContainsString('nameExistsOnServer(', $body, "{$method}() wajib scoped per server");
            $this->assertStringContainsString('assertSubdomainAvailable(', $body, $method);
            $this->assertStringContainsString('$serverId)', $body, "{$method}() wajib meneruskan serverId");
            $this->assertStringNotContainsString('->nameExists(', $body, "{$method}() tidak boleh memakai cek nama global");
        }

        $confirm = $this->methodBody('confirmCreate');
        $this->assertStringContainsString('resolveCreateServer(', $confirm);
        $this->assertStringContainsString('validateAndApplyPorts($pending[\'services\'], $serviceInput, $serverId)', $confirm);
        $this->assertStringContainsString("assertSubdomainAvailable(\$subdomain, (string) \$pending['name'], '', \$serverId)", $confirm);

        $create = $this->methodBody('createAndDeploy');
        $this->assertStringContainsString('ServerResolver::isLocal($server)', $create, 'ensureWritable hanya untuk server lokal');
        $this->assertStringContainsString("'server_id' => AppStore::normalizeServerId(", $create);

        // Preflight nama app di server target wajib dijalankan SEBELUM record app
        // ditulis (satu tempat, semua jalur create lewat sini).
        $this->assertStringContainsString('DeployerFactory::assertAppTargetAvailable(', $create);
        $this->assertStringContainsString('(new AppStore())->create($appData)', $create);
        $this->assertLessThan(
            (int) strpos($create, '(new AppStore())->create($appData)'),
            (int) strpos($create, 'DeployerFactory::assertAppTargetAvailable('),
            'preflight ketersediaan nama app wajib berjalan sebelum record app dibuat'
        );
    }

    /**
     * Kontrak view wajib benar-benar dipakai form yang dirender (bukan hanya
     * helper yang tidak pernah dipanggil). Dicek pada sumber kontroler karena
     * merender view butuh konteks Webman penuh (pola `DeployWorkerLauncherTest`).
     */
    public function testCreateFormsMergeServerContextIntoView(): void
    {
        foreach (['createForm', 'templateForm', 'confirmForm'] as $method) {
            $this->assertStringContainsString(
                'serverFormContext(',
                $this->methodBody($method),
                "{$method}() wajib mengirim servers/selectedServerId/canSelectServer ke view"
            );
        }
    }

    /**
     * Jalur app-scoped yang masih menulis artefak lokal wajib lewat satu guard
     * (`appArtifactDirOrFail()`) — kalau tidak, pesan menyesatkan "Direktori app
     * tidak ada" bisa kembali untuk app di server agent.
     */
    public function testAppScopedPathsUseTheSingleGuard(): void
    {
        foreach (['saveEnv', 'importEnv', 'uploadEnv', 'saveNetworks', 'saveContainerNames', 'saveLimits'] as $method) {
            $body = $this->methodBody($method);

            $this->assertStringContainsString('appArtifactDirOrFail(', $body, $method);
            $this->assertStringNotContainsString(
                'Direktori app tidak ada',
                $body,
                "{$method}() tidak boleh lagi memakai pesan is_dir lokal langsung"
            );
        }
    }

    // ==================================================================
    // (3) Jalur app-scoped: agen ditolak eksplisit, lokal tidak berubah
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentAppScopedPathsRejectWithStageMessageAndTouchNothing(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID);
        $app = (new AppStore())->create([
            'name' => 'agen-1',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
        ]);

        foreach (['saveEnv', 'importEnv', 'uploadEnv', 'saveNetworks', 'saveContainerNames', 'saveLimits'] as $method) {
            $GLOBALS['RAMES_FLASH'] = [];
            $request = new Request("POST /apps/{$app['id']}/x HTTP/1.1\r\nHost: localhost\r\n\r\n");

            $response = (new AppController())->{$method}($request, $app['id']);

            $this->assertSame(302, $response->getStatusCode(), "{$method}() harus mengalihkan, bukan menjalankan operasi");
            $this->assertSame('/apps/' . $app['id'], $response->getHeader('Location'));

            $flash = $GLOBALS['RAMES_FLASH'];
            $this->assertCount(1, $flash, "{$method}() harus menulis satu pesan penolakan");
            $this->assertSame('error', $flash[0]['type']);
            $this->assertStringContainsString('belum aktif untuk server agent', $flash[0]['message'], $method);
            $this->assertStringContainsString('F2/F3', $flash[0]['message'], $method);
            $this->assertStringNotContainsString(
                'Direktori app tidak ada',
                $flash[0]['message'],
                "{$method}() tidak boleh memakai pesan menyesatkan"
            );
        }

        // Tidak ada artefak lokal yang tertulis / direktori app yang dibuat.
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/agen-1');
        $this->assertSame([], $this->appFiles('agen-1'));

        // `server_id` yang hilang dari registry juga ditolak jelas (bukan 500).
        $orphan = (new AppStore())->create([
            'name' => 'agen-hilang',
            'server_id' => 'server-yang-dihapus',
            'status' => 'running',
        ]);
        $GLOBALS['RAMES_FLASH'] = [];
        $request = new Request("POST /apps/{$orphan['id']}/env HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = (new AppController())->saveEnv($request, $orphan['id']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('Server tidak dikenal', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
        $this->assertStringNotContainsString('Direktori app tidak ada', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
    }

    /**
     * App yatim — `server_id` menunjuk server yang **sudah dihapus** dari halaman
     * Servers — wajib tetap bisa dihapus. Teardown remote dilewati (tidak ada
     * jalur jaringan lagi; DILARANG jatuh ke server lokal), record + artefak
     * lokal dibersihkan, dan admin diberi tahu bahwa container/volume di server
     * itu harus dibersihkan manual (ARCHITECTURE §8 GOTCHA no. 53).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteOfAppOnRemovedServerSkipsTeardownAndKeepsTheRecordRemovable(): void
    {
        $this->installStubs(self::ADMIN);
        $app = (new AppStore())->create([
            'name' => 'app-yatim',
            'server_id' => 'server-yang-dihapus',
            'status' => 'running',
            'auth_method' => 'none',
        ]);
        mkdir($this->tmp . '/apps/app-yatim', 0777, true);
        file_put_contents($this->tmp . '/apps/app-yatim/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        $GLOBALS['RAMES_FLASH'] = [];

        $request = new Request("POST /apps/{$app['id']}/delete HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = (new AppController())->delete($request, $app['id']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/apps', $response->getHeader('Location'));
        $this->assertNull((new AppStore())->find($app['id']), 'record app wajib terhapus');
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/app-yatim', 'artefak lokal ikut dibersihkan');

        $flash = $GLOBALS['RAMES_FLASH'];
        $this->assertCount(1, $flash, 'satu pesan (bukan "Gagal menghapus")');
        $this->assertSame('info', $flash[0]['type']);
        $this->assertStringContainsString('server-yang-dihapus', $flash[0]['message']);
        $this->assertStringContainsString('sudah tidak ada di registry', $flash[0]['message']);
        $this->assertStringContainsString('TIDAK dijalankan', $flash[0]['message']);
        $this->assertStringContainsString('down -v', $flash[0]['message'], 'arah pembersihan manual disebut');
    }

    /**
     * Halaman detail app yatim wajib tetap **terbuka**: tab "Hapus App" adalah
     * satu-satunya aksi yang masih bisa menuntaskan pekerjaan lokal. Sebelum
     * perbaikan, `DeployerFactory::createForApp()` melempar dan halaman 500 —
     * tombol hapus tak pernah bisa dijangkau.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDetailOfAppOnRemovedServerStillRendersSoItCanBeDeleted(): void
    {
        $this->installStubs(self::ADMIN);
        $app = (new AppStore())->create([
            'name' => 'app-yatim-view',
            'server_id' => 'server-yang-dihapus',
            'status' => 'running',
        ]);

        $controller = new class extends AppController {
            /** @var array<string,mixed> */
            public array $rendered = [];

            protected function renderView(string $template, array $vars): \Webman\Http\Response
            {
                $this->rendered = ['template' => $template, 'vars' => $vars];

                return new \Webman\Http\Response(200, ['Content-Type' => 'text/html'], 'ok');
            }
        };

        $request = new Request("GET /apps/{$app['id']} HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $controller->detail($request, $app['id']);

        $this->assertSame(200, $response->getStatusCode(), 'halaman detail tidak boleh 500');
        $this->assertSame('app/detail', $controller->rendered['template']);
        $this->assertSame([], $controller->rendered['vars']['live'], 'tanpa server: tidak ada container');
        $this->assertSame([], $controller->rendered['vars']['volumes'], 'tanpa server: tidak ada volume');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLocalAppScopedGuardKeepsLegacyBehaviour(): void
    {
        $this->installStubs(self::ADMIN);
        $local = (new AppStore())->create(['name' => 'lokal-1', 'status' => 'running']);

        // Direktori ada ⇒ guard mengembalikan path-nya, tanpa flash.
        mkdir($this->tmp . '/apps/lokal-1', 0777, true);
        $GLOBALS['RAMES_FLASH'] = [];
        $this->assertSame(
            $this->tmp . '/apps/lokal-1',
            $this->invoke('appArtifactDirOrFail', [$local, 'Environment variable'])
        );
        $this->assertSame([], $GLOBALS['RAMES_FLASH']);

        // Direktori hilang ⇒ pesan lama persis (perilaku sebelum multi-server).
        $this->removeTree($this->tmp . '/apps/lokal-1');
        $this->assertNull($this->invoke('appArtifactDirOrFail', [$local, 'Environment variable']));
        $this->assertSame(
            [['type' => 'error', 'message' => 'Direktori app tidak ada. App mungkin sudah dihapus.']],
            $GLOBALS['RAMES_FLASH']
        );

        // Jalur controller untuk app lokal dengan direktori hilang: pesan & tujuan sama.
        $GLOBALS['RAMES_FLASH'] = [];
        $request = new Request("POST /apps/{$local['id']}/env HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = (new AppController())->saveEnv($request, $local['id']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/apps/' . $local['id'], $response->getHeader('Location'));
        $this->assertSame(
            'Direktori app tidak ada. App mungkin sudah dihapus.',
            $GLOBALS['RAMES_FLASH'][0]['message'] ?? ''
        );
    }

    // ==================================================================
    // (1)+(2) Create & deploy: lokal lama, agent benar-benar ke agent
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateOnLocalServerKeepsLegacyBehaviour(): void
    {
        $this->installStubs(self::ADMIN);
        $name = 'app-lokal';
        $this->writeCompose($name, "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n");

        $result = $this->invoke('createAndDeploy', [
            $this->pending($name, ServerStore::LOCAL_ID),
            $this->services(30500),
            'web',
            30500,
            '',
            [],
            null,
            [],
            null,
            $this->fakeLauncher(),
        ]);

        $app = $result['app'];

        $this->assertTrue($result['spawned']);
        $this->assertSame(ServerStore::LOCAL_ID, $app['server_id']);
        $this->assertSame(ServerStore::LOCAL_ID, AppStore::serverIdOf((new AppStore())->find($app['id']) ?? []));
        $this->assertSame('u1', $app['owner_id'], 'owner_id dari user yang login');

        // Worker deploy lokal dipicu dengan mode `deploy` (perilaku lama).
        $this->assertCount(1, $GLOBALS['RAMES_SPAWN']);
        $this->assertSame($app['id'], $GLOBALS['RAMES_SPAWN'][0][2]);
        $this->assertSame('deploy', $GLOBALS['RAMES_SPAWN'][0][3]);

        // Override port/nama tetap ditulis ke direktori app lokal.
        foreach ([ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE] as $file) {
            $this->assertFileExists($this->tmp . '/apps/' . $name . '/' . $file);
        }

        // Jalur lokal tidak memakai mekanisme jalur agent: rencana port pilihan
        // user tetap hidup di berkas override di disk (bukan metadata `host_ports`).
        $this->assertArrayNotHasKey('host_ports', $app);

        // Prasyarat direktori Nginx host ini masih diperiksa (server lokal).
        $this->assertDirectoryExists($this->tmp . '/nginx/conf');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminCreatesAppOnAgentServerAndDeployGoesToThatAgent(): void
    {
        $this->installStubs(self::ADMIN);
        $server = $this->agent(self::AGENT_ID);
        $name = 'app-agent';
        $this->writeCompose($name, "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n");

        // Preflight ketersediaan nama app (`POST /apps/check`) dijalankan
        // `createAndDeploy()` SEBELUM record app ditulis — agen palsu menjawab
        // "bebas" supaya jalur sukses tetap bisa diperiksa di sini.
        $preflight = new AppControllerServerFakeAgent();
        $preflight->reply(200, (string) json_encode([
            'code' => 0,
            'op' => 'app_check',
            'available' => true,
            'state' => 'free',
            'message' => '',
        ]));
        $preflightClient = new AgentClient($server, $preflight, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));

        $result = $this->invoke('createAndDeploy', [
            $this->pending($name, self::AGENT_ID),
            $this->services(30500),
            'web',
            30500,
            '',
            [],
            null,
            [],
            null,
            $this->fakeLauncher(),
            $preflightClient,
        ]);

        $app = $result['app'];

        // Preflight benar-benar berjalan ke agent sebelum record ditulis, dan id
        // yang diperiksanya = id app yang lahir (id dibuat di control plane, bukan
        // oleh `AppStore::create()`), sehingga sama dengan id penanda di agent.
        $this->assertSame(1, count($preflight->calls), 'hanya preflight yang bicara ke agent pada tahap create');
        $this->assertSame('/apps/check', $preflight->call(0)['path']);
        $this->assertSame('POST', $preflight->call(0)['method']);
        $this->assertSame($app['id'], $preflight->jsonBody(0)['app']['id'] ?? null);
        $this->assertSame($name, $preflight->jsonBody(0)['app']['name'] ?? null);
        $this->assertArrayNotHasKey(
            'repo_url',
            (array) ($preflight->jsonBody(0)['app'] ?? []),
            'app compose tidak mengirim `repo_url` (payload preflight tetap sempit untuk app non-repo)'
        );

        $this->assertTrue($result['spawned']);
        $this->assertSame(self::AGENT_ID, $app['server_id'], 'app harus lahir di server yang dipilih admin');
        $this->assertSame(self::AGENT_ID, (new AppStore())->find($app['id'])['server_id']);
        $this->assertCount(1, $GLOBALS['RAMES_SPAWN'], 'worker kontrol tetap dijalankan (ia yang bicara ke agent)');

        // Deploy app itu BENAR-BENAR lewat agent: `createForApp()` ⇒ AgentDeployer
        // (bukan LocalDeployer) dan mengirim job `deploy` ke agent palsu.
        $handler = new AppControllerServerFakeAgent();
        $handler->reply(200, (string) json_encode(['code' => 0, 'job_id' => 'job-1', 'log_offset' => 0]));
        $handler->reply(200, (string) json_encode(['code' => 0, 'job' => ['status' => 'done']]));
        $handler->reply(200, "[2026-01-01T00:00:00+00:00] [deploy] container naik\n", ['X-Rames-Offset' => '47']);
        $handler->reply(200, '');
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'app' => ['id' => $app['id'], 'status' => 'running'],
        ]));

        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));
        $deployer = DeployerFactory::createForApp($app, new ServerResolver(), $client);

        $this->assertInstanceOf(AgentDeployer::class, $deployer);
        $this->assertNotInstanceOf(LocalDeployer::class, $deployer);

        $lines = [];
        $deployed = $deployer->deploy($app, static function (string $stage, string $message) use (&$lines): void {
            $lines[] = $stage . ': ' . $message;
        });

        $this->assertSame('POST', $handler->call(0)['method']);
        $this->assertSame('/jobs', $handler->call(0)['path']);
        $this->assertSame('deploy', $handler->jsonBody(0)['op']);
        $this->assertSame($app['id'], $handler->jsonBody(0)['app']['id']);
        $this->assertSame(self::AGENT_ID, $handler->jsonBody(0)['app']['server_id']);
        $this->assertSame('running', $deployed['status'], 'state eksekusi ditarik dari agent');
        $this->assertNotSame([], $lines, 'log agent diteruskan ke logger worker');
    }

    /**
     * Preflight nama app **menolak** ⇒ create dibatalkan **sebelum** record app
     * ditulis di store control plane: tidak ada baris `apps.json`, tidak ada worker
     * yang di-spawn, tidak ada artefak lokal — dan pesan galat sampai ke pemanggil
     * (wizard menampilkan pesan itu apa adanya). Inilah bukti "gagal cepat sebelum
     * build", bukan "deploy gagal" sesudah record app lahir.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateOnAgentServerIsRefusedBeforeAnyRecordWhenNameIsBlocked(): void
    {
        $this->installStubs(self::ADMIN);
        $server = $this->agent(self::AGENT_ID);
        $name = 'app-bentrok';

        // Direktori `apps/<name>` di agent berisi data tanpa penanda kepemilikan
        // Rames ⇒ agent menjawab `available:false` (state `blocked`).
        $handler = new AppControllerServerFakeAgent();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'op' => 'app_check',
            'available' => false,
            'state' => 'blocked',
            'message' => 'Direktori `apps/' . $name . '` di server ini sudah ada dan berisi data tanpa '
                . 'penanda kepemilikan Rames. Pindahkan atau hapus direktori itu manual, lalu deploy ulang.',
        ]));
        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));

        try {
            $this->invoke('createAndDeploy', [
                $this->pending($name, self::AGENT_ID),
                $this->services(30500),
                'web',
                30500,
                '',
                [],
                null,
                [],
                null,
                $this->fakeLauncher(),
                $client,
            ]);
            $this->fail('preflight yang menolak harus menggagalkan create');
        } catch (\Throwable $e) {
            // Kontrak: penolakan preflight = pesan ramah (RuntimeException), BUKAN
            // AgentError — AgentError hanya untuk masalah transport/agent.
            $this->assertNotInstanceOf(AgentError::class, $e);
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertStringContainsString($name, $e->getMessage(), 'nama app wajib disebut');
            $this->assertStringContainsString(self::AGENT_ID, $e->getMessage(), 'server target wajib disebut');
            $this->assertStringContainsString('pakai nama app lain', $e->getMessage());
            $this->assertStringContainsString(
                'apps/' . $name,
                $e->getMessage(),
                'catatan dari agent (tindakan yang bisa ditempuh) ikut disampaikan'
            );
        }

        // Tidak ada record app yang tertulis — id yang diperiksa preflight pun
        // tidak pernah masuk store.
        $this->assertSame([], (new AppStore())->all(), 'tidak boleh ada record app sesudah preflight menolak');
        $this->assertSame([], $GLOBALS['RAMES_SPAWN'], 'worker deploy tidak boleh di-spawn');
        $this->assertSame([], $this->appFiles($name), 'tidak ada artefak lokal yang ditulis');

        // Hanya preflight yang bicara ke agent (tanpa job deploy).
        $this->assertSame(1, count($handler->calls));
        $this->assertSame('/apps/check', $handler->call(0)['path']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{16}$/',
            (string) ($handler->jsonBody(0)['app']['id'] ?? ''),
            'id app sudah final (dibuat control plane) saat preflight dijalankan'
        );
    }

    /**
     * Agent **lama** (belum punya `POST /apps/check`) menjawab 404 ⇒ preflight
     * dilewati dan create berjalan seperti sebelumnya: record app tertulis, worker
     * di-spawn, tanpa galat ke user. Guard di jalur job tetap berlaku di sana.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateOnAgentServerContinuesWhenAgentHasNoCheckEndpoint(): void
    {
        $this->installStubs(self::ADMIN);
        $server = $this->agent(self::AGENT_ID);
        $name = 'app-agent-lama';

        $handler = new AppControllerServerFakeAgent();
        $handler->reply(404, (string) json_encode(['code' => 1, 'error' => 'Endpoint tidak ada.']));
        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));

        $result = $this->invoke('createAndDeploy', [
            $this->pending($name, self::AGENT_ID),
            $this->services(30500),
            'web',
            30500,
            '',
            [],
            null,
            [],
            null,
            $this->fakeLauncher(),
            $client,
        ]);

        $app = $result['app'];

        $this->assertTrue($result['spawned'], 'create tetap jalan untuk agent lama (degradasi yang disengaja)');
        $this->assertSame(self::AGENT_ID, (new AppStore())->find($app['id'])['server_id'] ?? null);
        $this->assertCount(1, $GLOBALS['RAMES_SPAWN']);
        $this->assertSame(1, count($handler->calls), 'hanya preflight yang dicoba (endpoint tidak ada)');
        $this->assertSame('/apps/check', $handler->call(0)['path']);
    }

    /**
     * Galat agent selain 404 (mis. 500) ⇒ preflight **tidak** dilewati: create
     * dibatalkan dengan `AgentError` (masalah transport/agent, bukan penolakan
     * data) dan **tidak ada** record app/worker.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateOnAgentServerIsAbortedWhenPreflightFails(): void
    {
        $this->installStubs(self::ADMIN);
        $server = $this->agent(self::AGENT_ID);
        $name = 'app-agent-error';

        $handler = new AppControllerServerFakeAgent();
        $handler->reply(500, (string) json_encode(['code' => 1, 'error' => 'gagal membaca direktori app']));
        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));

        try {
            $this->invoke('createAndDeploy', [
                $this->pending($name, self::AGENT_ID),
                $this->services(30500),
                'web',
                30500,
                '',
                [],
                null,
                [],
                null,
                $this->fakeLauncher(),
                $client,
            ]);
            $this->fail('galat agent selain 404 harus membatalkan create');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::SERVER_ERROR, $e->reason);
            $this->assertStringContainsString('tidak bisa memastikan', $e->getMessage());
            $this->assertStringContainsString(self::AGENT_ID, $e->getMessage());
        }

        $this->assertSame([], (new AppStore())->all(), 'tidak ada record app saat preflight gagal');
        $this->assertSame([], $GLOBALS['RAMES_SPAWN'], 'worker deploy tidak boleh di-spawn');
        $this->assertSame([], $this->appFiles($name));
    }

    // ==================================================================
    // (5) Clone repo: agent meng-clone sendiri, control plane tidak
    // ==================================================================

    /**
     * Analisis repo (`POST /apps/create`) untuk server **agent** TIDAK boleh
     * membuat checkout/direktori app di host control plane — clone untuk deploy
     * terjadi di agent (`AgentArtifactMaterializer`). Clone analisis sementara
     * (yang dipakai membaca file compose untuk halaman konfirmasi) wajib dibuang.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRepoAppAnalysisOnAgentServerCreatesNothingOnTheControlPlane(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID);
        $git = new AppControllerServerFakeGit();
        $name = 'app-repo-agent';

        $response = (new AppController())->createPreview($this->postForm('/apps/create', [
            'name' => $name,
            'repo_url' => 'https://example.test/app.git',
            'branch' => 'main',
            'auth_method' => 'none',
            'server_id' => self::AGENT_ID,
        ]), $git);

        // Analisis tetap sukses (nama file compose & service terbaca)…
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/apps/create/confirm', $response->getHeader('Location'));

        // …tetapi clone analisis TIDAK diarahkan ke `apps_path` dan dibersihkan lagi
        // (checkout repo app ada di agent, bukan di control plane).
        $this->assertCount(1, $git->clones);
        $this->assertStringStartsNotWith($this->tmp . '/apps/', $git->clones[0]['dest']);
        $this->assertDirectoryDoesNotExist($git->clones[0]['dest'], 'clone analisis wajib dibuang');
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/' . $name);
        $this->assertSame([], $this->appFiles($name));
        $this->assertSame([], $this->analysisLeftovers($name), 'clone analisis sementara wajib dibersihkan');
        $this->assertSame([], (new AppStore())->all(), 'langkah analisis belum membuat app');
    }

    /** Server lokal: perilaku lama **tidak berubah** — repo tetap di-clone ke `apps_path`. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRepoAppAnalysisOnLocalServerKeepsTheLegacyClone(): void
    {
        $this->installStubs(self::ADMIN);
        $git = new AppControllerServerFakeGit();
        $name = 'app-repo-lokal';

        $response = (new AppController())->createPreview($this->postForm('/apps/create', [
            'name' => $name,
            'repo_url' => 'https://example.test/app.git',
            'branch' => 'main',
            'auth_method' => 'none',
        ]), $git);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/apps/create/confirm', $response->getHeader('Location'));
        $this->assertSame($this->tmp . '/apps/app-repo-lokal', $git->clones[0]['dest'], 'clone server lokal ke aplikasi lokal (perilaku lama)');
        $this->assertFileExists($this->tmp . '/apps/' . $name . '/docker-compose.yml');
        $this->assertSame([], $this->analysisLeftovers($name), 'server lokal tidak memakai clone analisis sementara');
    }

    /** Alur create app repo di server agent tetap men-enqueue worker deploy & menyimpan `server_id`. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentRepoAppCreateSkipsLocalArtifactsAndStillEnqueuesDeploy(): void
    {
        $this->installStubs(self::ADMIN);
        $server = $this->agent(self::AGENT_ID);
        $name = 'app-repo-agent';

        // Preflight nama app di agent (agen palsu menjawab "bebas").
        $preflight = new AppControllerServerFakeAgent();
        $preflight->reply(200, (string) json_encode([
            'code' => 0,
            'op' => 'app_check',
            'available' => true,
            'state' => 'free',
            'message' => '',
        ]));

        $result = $this->invoke('createAndDeploy', [
            $this->repoPending($name, self::AGENT_ID),
            $this->services(31500),
            'web',
            31500,
            '',
            [],
            null,
            [],
            null,
            $this->fakeLauncher(),
            new AgentClient($server, $preflight, 25, new TlsPinMaterializer($this->tmp . '/agent-tls')),
        ]);

        $app = $result['app'];

        $this->assertTrue($result['spawned'], 'create tetap memicu worker (worker yang bicara ke agent)');
        $this->assertSame(self::AGENT_ID, $app['server_id']);
        $this->assertCount(1, $GLOBALS['RAMES_SPAWN']);

        // Tidak ada artefak lokal yang ditulis / dipaksakan (direktori app agent
        // tidak ada di host ini). Daftar compose **memuat override port** pilihan
        // user (misi F1 celah B): berkasnya tidak dibuat di host ini, melainkan
        // dikirim `AgentArtifactPackager` lewat `artifacts.files` dan ditulis
        // materializer di server agent — jadi bukan entri yatim.
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/' . $name);
        $this->assertSame([], $this->appFiles($name));
        $this->assertSame(
            ['docker-compose.yml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            $app['compose_files']
        );
        $this->assertSame([['service' => 'web', 'container' => 80, 'host' => 31500]], $app['host_ports']);

        // Preflight app repo membawa `repo_url` supaya agent bisa membandingkan
        // remote checkout yang sudah ada di sana — payload tetap sempit
        // (`id`,`name`,`repo_url`), tanpa `env`/kunci.
        $body = $preflight->jsonBody(0);
        $this->assertSame('https://example.test/app.git', $body['app']['repo_url'] ?? null);
        $this->assertSame($app['id'], $body['app']['id'] ?? null);
        $this->assertArrayNotHasKey('env', (array) $body['app']);
        $this->assertArrayNotHasKey('ssh_key', (array) $body['app']);
    }

    /**
     * Prefix nama container kustom untuk app **repo** di server agent ditolak
     * **eksplisit** (base compose-nya di agent ⇒ pemeriksaan replica tidak bisa
     * dilakukan di sini), bukan gagal dengan pesan menyesatkan.
     */
    public function testCustomContainerPrefixForAgentRepoAppIsRejectedExplicitly(): void
    {
        $this->agent(self::AGENT_ID);

        try {
            $this->invoke('resolveContainerPrefix', ['app-repo-agent', 'docker-compose.yml', 'pfx', self::AGENT_ID]);
            $this->fail('prefix container untuk app repo di server agent harus ditolak eksplisit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('belum aktif untuk app repo di server agent', $e->getMessage());
            $this->assertStringNotContainsString('Base compose tidak ditemukan', $e->getMessage());
        }
    }

    /** Server lokal: prefix nama container tetap divalidasi seperti sebelumnya. */
    public function testCustomContainerPrefixStillWorksForLocalApps(): void
    {
        $name = 'app-lokal-prefix';
        $this->writeCompose($name, "services:\n  web:\n    image: nginx:alpine\n");

        $this->assertSame('pfx', $this->invoke('resolveContainerPrefix', [$name, 'docker-compose.yml', 'pfx', '']));
    }

    // ==================================================================
    // (6) Penjaga pra-deploy (rebuild/rollback) server-aware
    // ==================================================================

    /**
     * App di server **agent** tanpa direktori lokal **bukan** kesalahan: aksi
     * pra-deploy (rebuild/rollback) boleh lanjut — checkout repo-nya ada di
     * server itu, worker yang mengerjakan aksinya di sana.
     */
    public function testAgentAppWithoutLocalDirIsNotBlockedByPreDeployGuard(): void
    {
        $this->agent(self::AGENT_ID);
        $agentApp = (new AppStore())->create(['name' => 'app-repo-agent', 'server_id' => self::AGENT_ID, 'source' => 'git']);
        $localApp = (new AppStore())->create(['name' => 'app-lokal', 'source' => 'git']);

        $this->assertNull($this->invoke('localArtifactsError', [$agentApp, 'Rebuild']));
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/app-repo-agent');

        // Server lokal tetap dengan pesan lamanya persis…
        $this->assertSame(
            'Direktori app tidak ada. App mungkin sudah dihapus.',
            $this->invoke('localArtifactsError', [$localApp, 'Rebuild'])
        );
        // …dan `null` (boleh lanjut) begitu direktorinya ada.
        mkdir($this->tmp . '/apps/app-lokal', 0777, true);
        $this->assertNull($this->invoke('localArtifactsError', [$localApp, 'Rebuild']));

        // `server_id` yang hilang ditolak eksplisit, bukan pesan "direktori hilang".
        $orphan = (new AppStore())->create(['name' => 'app-hilang', 'server_id' => 'server-yang-dihapus', 'source' => 'git']);
        $message = (string) $this->invoke('localArtifactsError', [$orphan, 'Rollback']);
        $this->assertStringContainsString('Rollback tidak bisa dijalankan', $message);
        $this->assertStringContainsString('Server tidak dikenal', $message);
        $this->assertStringNotContainsString('Direktori app tidak ada', $message);
    }

    /**
     * `rebuild()`/`rollback()` wajib memakai satu penjaga server-aware — kalau
     * tidak, app agent (yang tidak punya direktori lokal) kembali ditolak dengan
     * "Direktori app tidak ada" yang menyesatkan.
     */
    public function testPreDeployActionsUseTheServerAwareGuard(): void
    {
        foreach (['rebuild', 'rollback'] as $method) {
            $body = $this->methodBody($method);

            $this->assertStringContainsString('localArtifactsError(', $body, $method);
            $this->assertStringNotContainsString('is_dir($dir)', $body, "{$method}() tidak boleh memeriksa direktori lokal langsung");
        }
    }

    // ==================================================================
    // (7) Misi F1 celah A/B: editor Compose & override port app agent
    // ==================================================================

    /**
     * Editor Compose (tab Compose) untuk app di server **agent**: compose hasil
     * edit disimpan sebagai metadata (`compose_content`) + rencana host port
     * (`host_ports`), daftar `compose_files` diperbarui kanonik (base → override
     * port), dan worker `apply` dipicu — **tanpa** satu pun berkas/direktori yang
     * dibuat di host control plane.
     *
     * Lanjutan test ini membuktikan "artifacts.compose terkirim": job yang
     * benar-benar dikirim worker ke agent (agent palsu) memuat isi compose hasil
     * edit + berkas override port, dengan nama file utama sesuai `compose_files`.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentComposeSaveStoresMasterAndSendsItThroughAnApplyJob(): void
    {
        $this->installStubs(self::ADMIN);
        $server = $this->agent(self::AGENT_ID);
        $name = 'app-compose-agent';
        $app = (new AppStore())->create([
            'name' => $name,
            'server_id' => self::AGENT_ID,
            'source' => ComposeSource::SOURCE_COMPOSE,
            'compose_files' => ['compose.yaml'],
            'primary_service' => 'web',
            'primary_port' => 80,
            'status' => 'running',
        ]);

        $content = "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n";
        $response = (new AppController())->saveCompose(
            $this->postForm('/apps/' . $app['id'] . '/compose', [
                'compose' => $content,
            ]),
            (string) $app['id'],
            $this->fakeLauncher()
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/apps/' . $app['id'], $response->getHeader('Location'));
        $flash = $GLOBALS['RAMES_FLASH'];
        $this->assertSame('success', $flash[0]['type'] ?? '', (string) json_encode($flash));

        // Tidak ada berkas/direktori lokal untuk app agent (celah A: dulu gagal
        // karena `is_dir(apps_path/<name>)`, sekarang tidak menyentuh host ini).
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/' . $name);
        $this->assertSame([], $this->appFiles($name));

        $saved = (new AppStore())->find((string) $app['id']) ?? [];
        $this->assertSame($content, $saved['compose_content'] ?? null, 'master compose disimpan di metadata control plane');
        $this->assertSame(
            ['compose.yaml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            $saved['compose_files'] ?? null,
            'nama file utama mengikuti aturan repo (compose_files[0]) + dua lapis override port'
        );
        $this->assertSame(
            [['service' => 'web', 'container' => 80, 'host' => 31000]],
            $saved['host_ports'] ?? null,
            'host port dialokasikan dari rentang port server agent (31000-31999)'
        );
        $this->assertSame('deploying', $saved['status'] ?? '');

        // Worker control plane dipicu dengan op `apply` (tanpa build di agent).
        $this->assertCount(1, $GLOBALS['RAMES_SPAWN']);
        $this->assertSame($app['id'], $GLOBALS['RAMES_SPAWN'][0][2]);
        $this->assertSame('apply', $GLOBALS['RAMES_SPAWN'][0][3]);

        // "artifacts.compose terkirim": jalankan jalur worker yang sama (deployer
        // agent) dengan agent palsu dan periksa body job yang benar-benar dikirim.
        $handler = new AppControllerServerFakeAgent();
        $handler->reply(200, (string) json_encode(['code' => 0, 'job_id' => 'job-apply', 'log_offset' => 0]));
        $handler->reply(200, (string) json_encode(['code' => 0, 'job' => ['status' => 'done']]));
        $handler->reply(200, '');
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'app' => ['id' => $app['id'], 'status' => 'running'],
        ]));
        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));
        $deployer = DeployerFactory::createForApp($saved, new ServerResolver(), $client);
        $deployer->apply($saved, static function (string $stage, string $message): void {
        });

        $body = $handler->jsonBody(0);
        $this->assertSame('apply', $body['op']);
        $this->assertSame($content, $body['artifacts']['compose'] ?? null);
        $this->assertSame('compose.yaml', $body['artifacts']['compose_name'] ?? null);
        $this->assertSame(
            [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            array_keys((array) ($body['artifacts']['files'] ?? [])),
            'berkas override port ikut terkirim (agent tidak meregenerasi port dari metadata)'
        );
        $this->assertStringContainsString('!reset', (string) $body['artifacts']['files'][ComposeSource::RESET_OVERRIDE_FILE]);
        $this->assertStringContainsString('31000:80', (string) $body['artifacts']['files'][ComposeSource::PORTS_OVERRIDE_FILE]);
        $this->assertSame($saved['compose_files'], $body['app']['compose_files']);
        $this->assertArrayNotHasKey('compose_content', $body['app']['env'] ?? []);
    }

    /**
     * Compose tidak sah untuk app agent ⇒ ditolak **sebelum** efek samping:
     * tidak ada job yang di-spawn, `apps.json` tidak berubah, tidak ada berkas
     * lokal yang dibuat.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentComposeSaveRejectsInvalidComposeWithoutSideEffects(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID);
        $name = 'app-compose-agent';
        $app = (new AppStore())->create([
            'name' => $name,
            'server_id' => self::AGENT_ID,
            'source' => ComposeSource::SOURCE_COMPOSE,
            'compose_files' => ['docker-compose.yml'],
            'status' => 'running',
        ]);
        $before = (new AppStore())->find((string) $app['id']);

        foreach ([
            "services:\n  web:\n    build: .\n",     // `build:` dilarang di template/compose app
            "services: []\n",                          // tanpa service (bentuk tak sah)
            ": :\n",                                   // YAML rusak
        ] as $invalid) {
            $GLOBALS['RAMES_FLASH'] = [];
            $response = (new AppController())->saveCompose(
                $this->postForm('/apps/' . $app['id'] . '/compose', ['compose' => $invalid]),
                (string) $app['id'],
                $this->fakeLauncher()
            );

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('error', $GLOBALS['RAMES_FLASH'][0]['type'] ?? '');
            $this->assertSame([], $GLOBALS['RAMES_SPAWN'], 'job tidak boleh dipicu untuk compose tak sah');
            $this->assertSame($before, (new AppStore())->find((string) $app['id']), 'apps.json tidak berubah');
            $this->assertDirectoryDoesNotExist($this->tmp . '/apps/' . $name);
            $this->assertSame([], $this->appFiles($name));
        }
    }

    /**
     * Port pilihan user untuk app agent tetap sampai ke agent, dan urutan
     * `compose_files` mengikuti kontrak repo: base → override port → override
     * jaringan → **override env paling akhir** (override env tidak dikirim lewat
     * `files`: agent menulisnya sendiri dari metadata `env`).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentComposeSavePreparesPortOverridesAndKeepsEnvOverrideLast(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID);
        $name = 'app-compose-agent';
        $app = (new AppStore())->create([
            'name' => $name,
            'server_id' => self::AGENT_ID,
            'source' => ComposeSource::SOURCE_COMPOSE,
            'compose_files' => ['docker-compose.yml', 'docker-compose.override.networks.yml', 'docker-compose.override.env.yml'],
            'env' => ['APP_KEY' => 'rahasia'],
            'status' => 'running',
        ]);

        $content = "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n";
        (new AppController())->saveCompose(
            $this->postForm('/apps/' . $app['id'] . '/compose', ['compose' => $content]),
            (string) $app['id'],
            $this->fakeLauncher()
        );

        $saved = (new AppStore())->find((string) $app['id']) ?? [];
        $files = (array) ($saved['compose_files'] ?? []);

        $this->assertSame(ComposeSource::RESET_OVERRIDE_FILE, $files[1] ?? null);
        $this->assertSame(ComposeSource::PORTS_OVERRIDE_FILE, $files[2] ?? null);
        $this->assertContains('docker-compose.override.networks.yml', $files);
        $this->assertSame(EnvManager::OVERRIDE_FILE, end($files), 'override env wajib paling akhir');
        $this->assertSame([], $this->appFiles($name), 'tidak ada berkas override lokal untuk app agent');

        // Berkas yang dikirim ke agent = dua lapis override port saja.
        $files = (new AgentArtifactPackager(
            $this->tmp . '/apps',
            $this->tmp . '/database/keys',
            $this->tmp . '/database'
        ))->pack($saved)['files'];
        $this->assertSame(
            [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            array_keys($files)
        );
    }

    /**
     * Server **lokal**: perilaku save compose tidak berubah — berkas ditulis di
     * direktori app, rename file utama diikuti, dan metadata khusus jalur agent
     * (`compose_content`/`host_ports`) **tidak** ikut ditulis.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLocalComposeSaveKeepsLegacyBehaviour(): void
    {
        $this->installStubs(self::ADMIN);
        $name = 'app-lokal-compose';
        $this->writeCompose($name, "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n");
        $app = (new AppStore())->create([
            'name' => $name,
            'source' => ComposeSource::SOURCE_COMPOSE,
            'compose_files' => ['docker-compose.yml'],
            'primary_service' => 'web',
            'primary_port' => 80,
            'status' => 'running',
        ]);

        $content = "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n  api:\n    image: busybox\n    ports:\n      - \"8080\"\n";
        (new AppController())->saveCompose(
            $this->postForm('/apps/' . $app['id'] . '/compose', ['compose' => $content]),
            (string) $app['id'],
            $this->fakeLauncher()
        );

        $this->assertSame($content, (string) file_get_contents($this->tmp . '/apps/' . $name . '/docker-compose.yml'));
        foreach ([ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE] as $file) {
            $this->assertFileExists($this->tmp . '/apps/' . $name . '/' . $file);
        }

        $saved = (new AppStore())->find((string) $app['id']) ?? [];
        $this->assertArrayNotHasKey('compose_content', $saved, 'jalur lokal tidak menyimpan master di metadata');
        $this->assertArrayNotHasKey('host_ports', $saved, 'jalur lokal menyimpan rencana port di berkas override');
        $this->assertSame('apply', $GLOBALS['RAMES_SPAWN'][0][3] ?? null);

        // Master di disk yang dipakai packager untuk app lokal (bukan metadata).
        $this->assertSame(
            $content,
            (new AgentArtifactPackager(
                $this->tmp . '/apps',
                $this->tmp . '/database/keys',
                $this->tmp . '/database'
            ))->pack($saved)['compose']
        );
    }

    // ==================================================================
    // (5) detail app: nama server dikirim ke view, tanpa rahasia
    // ==================================================================

    /**
     * Badge server di detail app butuh **nama** server, bukan id-nya. Controller
     * hanya boleh mengirim `name` (fallback `id`) — token/PEM/base_url tidak
     * pernah masuk konteks view (rencana §4.3; penyembunyian di view bukan
     * pengaman, jadi rahasianya memang tidak dikirim).
     */
    public function testServerNameForAgentAppCarriesNoSecret(): void
    {
        $row = [
            'id' => 'vps-jakarta',
            'name' => 'vps-jakarta',
            'kind' => 'agent',
            'base_url' => 'https://10.0.0.12:8443',
            'token' => self::AGENT_TOKEN,
            'tls_cert_pem' => $this->selfSignedPem(),
            'tls_fingerprint' => str_repeat('ab', 32),
            'notes' => 'rahasia internal',
        ];

        $name = $this->invoke('serverNameForApp', [$row]);

        $this->assertSame('vps-jakarta', $name);
        $this->assertStringNotContainsString(self::AGENT_TOKEN, $name);
        $this->assertStringNotContainsString('10.0.0.12', $name);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $name);
        $this->assertStringNotContainsString('rahasia internal', $name);
    }

    public function testServerNameFallsBackToIdWhenNameIsEmpty(): void
    {
        $this->assertSame('vps-tanpa-nama', $this->invoke('serverNameForApp', [[
            'id' => 'vps-tanpa-nama',
            'name' => '   ',
            'kind' => 'agent',
            'token' => self::AGENT_TOKEN,
        ]]));
    }

    /** App lokal & server tak terbaca ⇒ `''` (view menampilkan "lokal" seperti dulu). */
    public function testLocalAndUnknownServerYieldEmptyName(): void
    {
        $this->assertSame('', $this->invoke('serverNameForApp', [null]));
        $this->assertSame('', $this->invoke('serverNameForApp', [[
            'id' => 'local',
            'name' => 'local',
            'kind' => 'local',
        ]]));
    }

    /**
     * Kontrak statik: `detail()` benar-benar mengoper nama server ke view dan
     * tidak menyelundupkan kolom rahasia ke konteks view (dicek pada sumber
     * karena `detail()` menyentuh Engine/view dan tak bisa dijalankan utuh di sini).
     */
    public function testDetailPassesServerNameToTheViewWithoutSecretFields(): void
    {
        $body = $this->methodBody('detail');

        $this->assertStringContainsString('$this->serverNameForApp($appServer)', $body);
        $this->assertStringContainsString("'serverName' => \$serverName", $body);
        $this->assertStringNotContainsString('tls_cert_pem', $body);
        $this->assertStringNotContainsString("'token'", $body);
        $this->assertStringNotContainsString('base_url', $body);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @return array<string,mixed> */
    private function pending(string $name, string $serverId): array
    {
        return [
            'name' => $name,
            'source' => 'compose',
            'repo_url' => null,
            'branch' => null,
            'local_path' => 'apps/' . $name,
            'compose_file' => 'docker-compose.yml',
            'services' => $this->services(30500),
            'primary_service' => 'web',
            'primary_port' => 80,
            'auth_method' => 'none',
            'ssh_key' => null,
            'server_id' => $serverId,
        ];
    }

    /**
     * @return array<string,array{ports:array<int,array{container:int,host:int}>,host_port:int}>
     */
    private function services(int $hostPort): array
    {
        return ['web' => ['ports' => [['container' => 80, 'host' => $hostPort]], 'host_port' => $hostPort]];
    }

    private function writeCompose(string $name, string $yaml): void
    {
        $dir = $this->tmp . '/apps/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/docker-compose.yml', $yaml);
    }

    /** @return array<string,mixed> */
    private function repoPending(string $name, string $serverId): array
    {
        return [
            'name' => $name,
            'source' => 'git',
            'repo_url' => 'https://example.test/app.git',
            'branch' => 'main',
            'local_path' => 'apps/' . $name,
            'compose_file' => 'docker-compose.yml',
            'services' => $this->services(31500),
            'primary_service' => 'web',
            'primary_port' => 80,
            'auth_method' => 'none',
            'ssh_key' => null,
            'server_id' => $serverId,
        ];
    }

    // ==================================================================
    // (5) Re-render kegagalan wizard — pilihan server tidak boleh hilang
    // ==================================================================

    /**
     * Verifier #3 (verifikasi fungsional, bukan sekadar sumber): jalur
     * re-render kegagalan wajib memakai id server yang **diminta** request,
     * bukan `$serverId` yang masih bernilai default lokal saat
     * `resolveCreateServer()` gagal.
     *
     * View benar-benar dirender lewat helper `view()` Webman (template repo),
     * lalu HTML hasilnya diperiksa: `<option value="<agent>" selected>`.
     * Fixture 100% temp (sqlite/apps/keys/template) + git tiruan.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailureRerenderKeepsRequestedServerInAllThreeModes(): void
    {
        $this->installStubs(self::ADMIN);
        $this->useViewConfig();
        $this->agent(self::AGENT_ID, ['status' => 'offline']);
        $this->tempTemplate('demo');
        // Cabang re-render mode git hanya hidup saat deploy key sudah ada.
        $this->writeDeployKey('app-git-ssh');
        // Git tiruan: jangan sampai jalur ini menyentuh `git`/jaringan nyata bila
        // urutan kegagalan berubah (fail-fast resolve tetap terjadi lebih dulu).
        $git = new AppControllerServerFakeGit();

        $html = $this->renderBody((new AppController())->createPreview($this->postForm('/apps/create', [
            'name' => 'app-git-ssh',
            'repo_url' => 'git@example.test:user/app.git',
            'branch' => 'main',
            'auth_method' => 'ssh',
            'server_id' => self::AGENT_ID,
        ]), $git));
        $this->assertFailureRerenderKeepsAgent($html, 'createPreview (git, cabang SSH)');

        $html = $this->renderBody((new AppController())->composePreview($this->postForm('/apps/create/compose', [
            'name' => 'app-compose',
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'server_id' => self::AGENT_ID,
        ])));
        $this->assertFailureRerenderKeepsAgent($html, 'composePreview');

        $html = $this->renderBody((new AppController())->templateDeploy(
            $this->postForm('/apps/create/template/demo', ['name' => 'app-tpl', 'server_id' => self::AGENT_ID]),
            'demo'
        ));
        $this->assertFailureRerenderKeepsAgent($html, 'templateDeploy (fase 1)');

        // Server **nonaktif** (`enabled=false`): id yang diminta juga tetap
        // dipertahankan — opsi dirender `selected disabled` + penanda `(nonaktif)`
        // — bukan diam-diam kembali ke server lokal. POST-nya tetap ditolak
        // `resolveCreateServer()` (fail-fast), jadi target deploy tidak berubah.
        $this->agent('srv-mati', ['enabled' => false, 'status' => 'offline']);
        $html = $this->renderBody((new AppController())->composePreview($this->postForm('/apps/create/compose', [
            'name' => 'app-compose-mati',
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'server_id' => 'srv-mati',
        ])));
        self::assertMatchesRegularExpression(
            '/<option value="srv-mati" selected disabled>.*\(nonaktif\)/',
            $html,
            'server nonaktif: id yang diminta tetap dipertahankan (ditandai nonaktif)'
        );
        self::assertStringNotContainsString('<option value="' . ServerStore::LOCAL_ID . '" selected>', $html);
        // `composePreview()` merender pesan kegagalan di form (`compose_error`),
        // bukan lewat flash — yang dibuktikan: pesannya menyebut server dinonaktifkan.
        self::assertStringContainsString('dinonaktifkan', $html, 'server nonaktif: pesan kegagalan menyebut server dinonaktifkan');
    }

    /**
     * Cabang non-SSH `createPreview()` (jalur `redirect()`): `Location` hanya
     * boleh membawa `?server_id=<id>` bila id non-kosong, bukan `local`, **dan**
     * user berhak memilih server. URL tanpa parameter tetap berarti lokal.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailureRedirectCarriesServerIdOnlyForEntitledRequest(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID, ['enabled' => false, 'status' => 'offline']);
        $git = new AppControllerServerFakeGit();

        $base = [
            'name' => 'app-redirect',
            'repo_url' => 'https://example.test/app.git',
            'branch' => 'main',
            'auth_method' => 'none',
        ];

        // admin + server tidak siap ⇒ pilihan tidak boleh hilang dari URL.
        self::assertSame(
            '/apps/create?server_id=' . self::AGENT_ID,
            $this->failureLocation($base + ['server_id' => self::AGENT_ID], $git)
        );

        // admin + id tak dikenal ⇒ parameter dibawa apa adanya; `serverFormContext()`
        // menormalkannya kembali ke lokal (tidak ada identitas server yang bocor).
        self::assertSame(
            '/apps/create?server_id=ghost-server',
            $this->failureLocation($base + ['server_id' => 'ghost-server'], $git)
        );

        // Nilai id di-`rawurlencode` sebelum ditempel ke query.
        self::assertSame(
            '/apps/create?server_id=srv%20%22x',
            $this->failureLocation($base + ['server_id' => 'srv "x'], $git)
        );

        // non-admin + id agent ⇒ tanpa parameter (keberadaan server lain tidak bocor).
        $GLOBALS['RAMES_TEST_USER'] = self::MEMBER;
        self::assertSame(
            '/apps/create',
            $this->failureLocation($base + ['server_id' => self::AGENT_ID], $git),
            'non-admin tidak boleh mendapat parameter server'
        );
        $GLOBALS['RAMES_TEST_USER'] = self::ADMIN;

        // id kosong / `local` / `LOCAL` ⇒ URL bersih (gagal validasi nama lebih dulu).
        foreach (['', 'local', 'LOCAL'] as $localId) {
            $fields = $base;
            $fields['name'] = 'app_bad'; // nama tidak valid (underscore) → jalur redirect
            if ($localId !== '') {
                $fields['server_id'] = $localId;
            }
            self::assertSame(
                '/apps/create',
                $this->failureLocation($fields, $git),
                'id lokal/kosong ⇒ tanpa parameter (id=' . $localId . ')'
            );
        }
    }

    /**
     * Uji mutasi assert backend di atas: (a) pilihan server dikembalikan ke
     * default lokal pada ketiga jalur re-render, (b) parameter `Location`
     * dibuang ⇒ assert wajib **gagal** (mutan tertangkap).
     *
     * Mutan = salinan berkas controller di temp dengan namespace berbeda; berkas
     * repo tidak pernah disentuh.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailureRerenderAndRedirectMutantsAreCaught(): void
    {
        $this->installStubs(self::ADMIN);
        $this->useViewConfig();
        $this->agent(self::AGENT_ID, ['enabled' => false, 'status' => 'offline']);
        $this->tempTemplate('demo');
        $this->writeDeployKey('app-git-ssh');

        $source = (string) file_get_contents(dirname(__DIR__) . '/app/controller/AppController.php');
        $git = new AppControllerServerFakeGit();
        $report = [];

        // M1: createPreview (cabang SSH) kembali memakai default server lokal.
        $report[] = $this->mutantCaught(
            'M1-createpreview-selected-server',
            $source,
            "                ] + \$this->serverFormContext(current_user(), \$this->requestedServerId(\$request)));\n            }\n            if (\$generatedKey) {",
            "                ] + \$this->serverFormContext(current_user()));\n            }\n            if (\$generatedKey) {",
            function (object $controller): void {
                $html = $this->renderBody($controller->createPreview($this->postForm('/apps/create', [
                    'name' => 'app-git-ssh',
                    'repo_url' => 'git@example.test:user/app.git',
                    'branch' => 'main',
                    'auth_method' => 'ssh',
                    'server_id' => self::AGENT_ID,
                ])));
                self::assertStringContainsString('<option value="' . self::AGENT_ID . '" selected>', $html, 'server yang diminta tetap terpilih');
            }
        );

        // M2: composePreview kembali memakai default server lokal.
        $report[] = $this->mutantCaught(
            'M2-composepreview-selected-server',
            $source,
            "            ] + \$this->serverFormContext(current_user(), \$this->requestedServerId(\$request)));\n        }\n    }\n\n    // ==================================================================\n    // Wizard create — mode Template",
            "            ] + \$this->serverFormContext(current_user()));\n        }\n    }\n\n    // ==================================================================\n    // Wizard create — mode Template",
            function (object $controller): void {
                $html = $this->renderBody($controller->composePreview($this->postForm('/apps/create/compose', [
                    'name' => 'app-compose',
                    'compose' => "services:\n  web:\n    image: nginx:alpine\n",
                    'server_id' => self::AGENT_ID,
                ])));
                self::assertStringContainsString('<option value="' . self::AGENT_ID . '" selected>', $html, 'server yang diminta tetap terpilih');
            }
        );

        // M3: templateDeploy fase-1 kembali memakai default server lokal.
        $report[] = $this->mutantCaught(
            'M3-templatedeploy-selected-server',
            $source,
            "            return view('app/template', \$this->serverFormContext(\n                current_user(),\n                \$this->requestedServerId(\$request)\n            ) + [",
            "            return view('app/template', \$this->serverFormContext(\n                current_user()\n            ) + [",
            function (object $controller): void {
                $html = $this->renderBody($controller->templateDeploy(
                    $this->postForm('/apps/create/template/demo', ['name' => 'app-tpl', 'server_id' => self::AGENT_ID]),
                    'demo'
                ));
                self::assertStringContainsString('<option value="' . self::AGENT_ID . '" selected>', $html, 'server yang diminta tetap terpilih');
            }
        );

        // M4: parameter `server_id` di Location dibuang.
        $report[] = $this->mutantCaught(
            'M4-location-server-query',
            $source,
            "            \$serverQuery = \$mayPickServer ? '?server_id=' . rawurlencode(\$requestedServerId) : '';",
            "            \$serverQuery = '';",
            function (object $controller) use ($git): void {
                $response = $controller->createPreview($this->postForm('/apps/create', [
                    'name' => 'app-redirect',
                    'repo_url' => 'https://example.test/app.git',
                    'branch' => 'main',
                    'auth_method' => 'none',
                    'server_id' => self::AGENT_ID,
                ]), $git);
                self::assertSame('/apps/create?server_id=' . self::AGENT_ID, $response->getHeader('Location'));
            }
        );

        echo "\n";
        foreach ($report as $line) {
            echo '  ' . $line . "\n";
        }
        self::assertCount(4, $report);
    }

    // ------------------------------------------------------------------
    // Helper blok (5)
    // ------------------------------------------------------------------

    /** Bukti view hasil re-render kegagalan mempertahankan server yang diminta. */
    private function assertFailureRerenderKeepsAgent(string $html, string $label, string $expectedError = 'berstatus offline'): void
    {
        self::assertNotSame('', $html, $label . ': view wajib dirender ulang');
        self::assertStringContainsString('Server tujuan', $html, $label . ': blok pemilih server dirender');
        // `selected` boleh diikuti atribut lain (`disabled` untuk server nonaktif);
        // yang dibuktikan: payload re-render tetap membawa id yang diminta.
        self::assertMatchesRegularExpression(
            '/<option value="' . preg_quote(self::AGENT_ID, '/') . '" selected/',
            $html,
            $label . ': server yang diminta request harus tetap terpilih'
        );
        self::assertStringNotContainsString(
            '<option value="' . ServerStore::LOCAL_ID . '" selected>',
            $html,
            $label . ': pilihan tidak boleh jatuh kembali ke server lokal'
        );
        self::assertStringNotContainsString('badge-running', $html, $label . ': server offline tidak boleh berbadge online');
        self::assertStringContainsString('badge-error', $html, $label . ': status offline memakai badge error');

        $flash = (array) ($GLOBALS['RAMES_FLASH'] ?? []);
        $surfaced = $flash !== []
            ? (string) ($flash[array_key_last($flash)]['message'] ?? '')
            // `composePreview()`/`templateDeploy()` fase-1 & cabang SSH mode git
            // merender pesan kegagalan di form (`compose_error`/`form_error`/
            // `preview_error`), bukan lewat flash.
            : $html;
        self::assertStringContainsString(
            $expectedError,
            $surfaced,
            $label . ': pesan kegagalan kesiapan server wajib tampil (flash atau form)'
        );
    }

    /**
     * POST wizard create yang **gagal** → header `Location` saja (kontrak:
     * `302` + flash error + tanpa rahasia server di URL).
     *
     * @param array<string,mixed> $fields
     */
    private function failureLocation(array $fields, GitService $git): string
    {
        $response = (new AppController())->createPreview($this->postForm('/apps/create', $fields), $git);

        self::assertSame(302, $response->getStatusCode(), 'kegagalan wizard git wajib redirect ke form create');

        $flash = (array) ($GLOBALS['RAMES_FLASH'] ?? []);
        self::assertNotSame([], $flash, 'kegagalan wajib menyetor flash error');

        $location = (string) $response->getHeader('Location');
        self::assertStringNotContainsString(self::AGENT_TOKEN, $location, 'token agent tidak boleh masuk URL');

        return $location;
    }

    /** Body respons `view()`/`redirect()` Webman sebagai HTML. */
    private function renderBody(object $response): string
    {
        self::assertTrue(method_exists($response, 'rawBody'), 'respons Webman wajib punya rawBody()');

        return (string) $response->rawBody();
    }

    /**
     * Muat salinan controller bermutasi (namespace unik, di temp) lalu buktikan
     * assert yang sama **gagal** untuk mutan tersebut.
     */
    private function mutantCaught(string $name, string $source, string $from, string $to, callable $assert): string
    {
        self::assertStringContainsString($from, $source, 'anchor mutasi tidak ditemukan: ' . $name);

        $namespace = 'RamesMutant' . str_replace('-', '', ucwords($name, '-'));
        $class = $namespace . '\\AppController';
        file_put_contents(
            $this->tmp . '/' . $namespace . '.php',
            str_replace('namespace app\controller;', 'namespace ' . $namespace . ';', str_replace($from, $to, $source))
        );
        require $this->tmp . '/' . $namespace . '.php';

        $caught = 'TIDAK tertangkap';
        try {
            $assert(new $class());
        } catch (AssertionFailedError $failure) {
            $caught = 'tertangkap (' . $this->shorten($failure->getMessage()) . ')';
        }

        self::assertStringStartsWith('tertangkap', $caught, 'mutan lolos: ' . $name);

        return $name . ' ⇒ ' . $caught;
    }

    private function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($text) > 100 ? substr($text, 0, 97) . '...' : $text;
    }

    /** Konfigurasi `view` temp (`Raw` handler) supaya `view()` benar-benar merender. */
    private function useViewConfig(): void
    {
        file_put_contents($this->tmp . '/config/view.php', '<?php return ' . var_export([
            'handler' => \support\view\Raw::class,
            'options' => ['view_suffix' => 'php'],
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    /**
     * Deploy key tiruan untuk app (tanpa `ssh-keygen`): cukup berkas privat +
     * publik, supaya `createPreview()` masuk cabang re-render SSH.
     */
    private function writeDeployKey(string $name): void
    {
        $dir = $this->tmp . '/keys';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/' . $name, "-----BEGIN OPENSSH PRIVATE KEY-----\nuji\n");
        file_put_contents($dir . '/' . $name . '.pub', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHUJI uji');
    }

    /** Template temp minimal (valid) untuk jalur `templateDeploy()`. */
    private function tempTemplate(string $slug): void
    {
        $dir = $this->tmp . '/templates/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/template.yml', "title: Template Uji\ndescription: fixture uji\n");
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n");

        $template = (new \app\library\Template\TemplateCatalog())->find($slug);
        self::assertNotNull($template, 'fixture template wajib terbaca');
        self::assertTrue($template['valid'], 'fixture template wajib valid: ' . (string) ($template['error'] ?? ''));
    }

    /** Request form-urlencoded (jalur wizard create) — dengan sesi uji. */
    private function postForm(string $uri, array $fields): Request
    {
        $body = http_build_query($fields);

        return new AppControllerServerSessionRequest(
            "POST {$uri} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * Sisa direktori clone **analisis sementara** milik app ini (nama sama dengan
     * konstanta `AppController::REPO_ANALYSIS_DIR`; dikutip literal karena
     * konstanta itu privat).
     *
     * @return array<int,string>
     */
    private function analysisLeftovers(string $name): array
    {
        return array_values(array_map(
            'strval',
            glob(sys_get_temp_dir() . '/rames-agent-repo-analysis/' . $name . '-*') ?: []
        ));
    }

    /**
     * Peluncur worker palsu: merekam argv dan **tidak** membuat proses apa pun.
     */
    private function fakeLauncher(): DeployWorkerLauncher
    {
        return new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []): bool {
                $GLOBALS['RAMES_SPAWN'][] = $argv;
                $pid = 4242;

                return true;
            },
            $this->tmp . '/cli/deploy.php',
            $this->tmp . '/logs/deploy'
        );
    }

    /**
     * Server agent siap pakai (capability = seluruh mode worker, status online).
     *
     * @param array<string,mixed> $extra override sebagian field (`capabilities`, `enabled`, …)
     * @return array<string,mixed>
     */
    private function agent(string $id, array $extra = []): array
    {
        $server = (new ServerStore())->create(array_merge([
            'id' => $id,
            'name' => $id,
            'kind' => 'agent',
            'enabled' => true,
            'domain' => $id . '.example.test',
            'base_url' => 'https://' . $id . '.internal.test:8443',
            'token' => $id === self::AGENT_ID ? self::AGENT_TOKEN : 'tok-uji-' . $id,
            'tls_cert_pem' => $this->selfSignedPem(),
            'status' => 'online',
            'capabilities' => DeployWorkerLauncher::MODES,
            'port_range' => ['start' => 31000, 'end' => 31999],
        ], $extra));

        ServerResolver::forgetMemo();

        return $server;
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
     * @param array{servers:array<int,array<string,mixed>>} $context
     * @return array<string,mixed>
     */
    private function serverOption(array $context, string $id): array
    {
        foreach ($context['servers'] as $server) {
            if (($server['id'] ?? '') === $id) {
                return $server;
            }
        }

        $this->fail("server {$id} tidak ada di daftar pilihan");
    }

    /**
     * Nama berkas apa pun di direktori app (bukti "tidak ada yang ditulis").
     *
     * @return array<int,string>
     */
    private function appFiles(string $name): array
    {
        $dir = $this->tmp . '/apps/' . $name;
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    /**
     * @param array<int,mixed> $args
     */
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new AppController(), ...$args);
    }

    /** Isi berkas satu method controller (untuk pemeriksaan kontrak statik). */
    private function methodBody(string $method): string
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $lines = (array) file((string) $reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    private function useConfig(): void
    {
        Config::clear();
        // `Config::load()` hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'nginx_conf_path' => $this->tmp . '/nginx/conf',
            'nginx_enabled_path' => '',
            'app_domain' => 'example.test',
            'port_range' => ['start' => 30000, 'end' => 30999],
            'templates_path' => $this->tmp . '/templates',
            // Deploy key SSH juga di temp: jalur create mode git ber-`auth_method=ssh`
            // tidak boleh menyentuh `database/keys/` runtime nyata.
            'ssh_keys_path' => $this->tmp . '/keys',
            // Uji tidak boleh menyentuh daemon Docker nyata host ini.
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
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

    /**
     * Pasang stub helper global + `app/functions.php` (hanya di proses anak).
     *
     * @param array<string,mixed>|null $user user login yang dikembalikan `current_user()`
     */
    private function installStubs(?array $user): void
    {
        $GLOBALS['RAMES_TEST_USER'] = $user;
        $GLOBALS['RAMES_FLASH'] = [];
        $GLOBALS['RAMES_SPAWN'] = [];

        if (!function_exists('flash_set')) {
            $file = sys_get_temp_dir() . '/rames-appctrl-stubs-' . getmypid() . '.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
            @unlink($file);
        }

        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }
        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        $this->useConfig();

        // Sesi uji (dipakai `createPreview` saat menyimpan `pending_app`) ditulis ke
        // direktori temp uji, bukan runtime aplikasi.
        $sessions = $this->tmp . '/sessions';
        if (!is_dir($sessions)) {
            mkdir($sessions, 0777, true);
        }
        ini_set('session.save_path', $sessions);
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
