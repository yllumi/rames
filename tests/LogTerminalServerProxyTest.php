<?php
declare(strict_types=1);

namespace Tests;

use app\controller\LogController;
use app\controller\TerminalController;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerExec;
use app\library\Storage\ServerStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

// Helper webman (`base_path()`, `json()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Handler Guzzle tiruan (tanpa jaringan): merekam method/path/header/body setiap
 * panggilan lalu melayani respons dari antrean.
 *
 * Dideklarasikan di berkas uji ini (bukan dipakai ulang dari berkas uji lain)
 * supaya kelas ini tetap dapat di-autoload saat dijalankan dengan `--filter`.
 */
final class LogTerminalProxyFakeHandler
{
    /** @var array<int,array{method:string,path:string,body:string,authorization:string}> */
    public array $calls = [];

    /** @var list<Psr7Response|\Throwable> */
    private array $queue = [];

    public function replyJson(array $payload, int $status = 200): self
    {
        return $this->reply($status, (string) json_encode($payload));
    }

    public function reply(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new Psr7Response($status, $headers + ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], $this->calls);
    }

    /** @return array{method:string,path:string,body:string,authorization:string} */
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
        if ($this->queue === []) {
            throw new RuntimeException('LogTerminalProxyFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'body' => (string) $request->getBody(),
            'authorization' => $request->getHeaderLine('authorization'),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * Koneksi TCP tiruan (tanpa socket/event loop): merekam potongan `Chunk` yang
 * dikirim controller sehingga jalur streaming bisa diuji apa adanya
 * (`instanceof TcpConnection` tetap terpenuhi).
 */
final class LogTerminalProxyFakeConnection extends TcpConnection
{
    /** @var list<mixed> */
    public array $sent = [];

    public int $closeCalls = 0;

    private bool $established = true;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function getStatus(bool $rawOutput = true): int|string
    {
        return $this->established ? self::STATUS_ESTABLISHED : 4;
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        $this->sent[] = $sendBuffer;

        return true;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closeCalls++;
        $this->established = false;
    }

    /** Tirukan klien yang memutus koneksi (onClose dipanggil Workerman). */
    public function dropClient(): void
    {
        $this->established = false;
        $callback = $this->onClose;
        if ($callback !== null) {
            $callback($this);
        }
    }

    /** Isi byte yang diterima klien (potongan chunked digabung). */
    public function payload(): string
    {
        $out = '';
        foreach ($this->sent as $item) {
            $raw = (string) $item;
            // Buang framing chunked: "{hex}\r\n{data}\r\n".
            $pos = strpos($raw, "\r\n");
            if ($pos === false) {
                continue;
            }
            $out .= substr($raw, $pos + 2, -2);
        }

        return $out;
    }
}

/**
 * `DockerClient` tiruan: **gagal keras** bila tersentuh (membuktikan app server
 * agent tidak pernah dibaca dari daemon lokal) atau merekam panggilan (regresi
 * jalur lokal).
 */
final class LogTerminalProxyDocker extends DockerClient
{
    /** @var array<int,array{container:string,tail:int,timestamps:bool}> */
    public array $calls = [];

    public string $raw = '';

    /** true ⇒ sentuhan apa pun ke daemon lokal adalah kegagalan uji. */
    public bool $explode = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada Guzzle/socket. */
    public function __construct()
    {
    }

    public function containerLogs(string $id, int $tail = 200, bool $timestamps = true): string
    {
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }

        $this->calls[] = ['container' => $id, 'tail' => $tail, 'timestamps' => $timestamps];

        return $this->raw;
    }
}

/**
 * `DockerExec` tiruan (jalur lokal): merekam panggilan tanpa Docker/sesi nyata.
 */
final class LogTerminalProxyExec extends DockerExec
{
    /** @var array<int,array{app:string,container:string,opts:array<string,string>}> */
    public array $openCalls = [];

    /** @var array<int,array{token:string,data:string}> */
    public array $inputs = [];

    /** @var list<string> */
    public array $closed = [];

    /** true ⇒ sentuhan ke Docker lokal untuk app server agent = kegagalan uji. */
    public bool $explode = false;

    public function __construct(private string $token = '')
    {
    }

    public function open(string $appId, string $container, array $opts = []): array
    {
        if ($this->explode) {
            throw new RuntimeException('DOCKER EXEC LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }

        $this->openCalls[] = ['app' => $appId, 'container' => $container, 'opts' => $opts];

        return ['token' => $this->token, 'pid' => 4242, 'shell' => 'bash', 'user' => 'root', 'container' => $container];
    }

    public function sessionInfo(string $token, string $appId): ?array
    {
        return ['token' => $token, 'app_id' => $appId, 'container' => 'demo-web-1'];
    }

    public function writeInput(string $token, string $data): bool
    {
        if ($this->explode) {
            throw new RuntimeException('DOCKER EXEC LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }

        $this->inputs[] = ['token' => $token, 'data' => $data];

        return true;
    }

    public function closeSession(string $token): void
    {
        $this->closed[] = $token;
    }
}

/** `LogController` dengan seam uji (tanpa `apps/`, tanpa daemon lokal). */
final class LogTerminalProxyLogController extends LogController
{
    public function __construct(private ?AgentDeployer $deployer, private DockerClient $docker)
    {
    }

    protected function requireApp(string $id): array
    {
        return self::app();
    }

    protected function dockerClient(): DockerClient
    {
        return $this->docker;
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->deployer;
    }

    /** @return array<string,mixed> */
    public static function app(): array
    {
        return [
            'id' => 'app-a1',
            'name' => 'demo',
            'server_id' => 'srv-agent',
            'primary_service' => 'web',
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'demo-web-1'],
                ['service_name' => 'db', 'container_name' => 'demo-db-1'],
            ],
        ];
    }
}

/** `TerminalController` dengan seam uji (tanpa `apps/`, Docker, sesi, audit nyata). */
final class LogTerminalProxyTerminalController extends TerminalController
{
    /** @var list<array{container:string,action:string}> */
    public array $audits = [];

    public function __construct(private ?AgentDeployer $deployer, private DockerExec $exec)
    {
    }

    protected function requireApp(string $id): array
    {
        return LogTerminalProxyLogController::app();
    }

    protected function dockerExec(): DockerExec
    {
        return $this->exec;
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->deployer;
    }

    protected function audit(array $app, string $container, string $action): void
    {
        $this->audits[] = ['container' => $container, 'action' => $action];
    }
}

/**
 * Operasional per server (F3a, plan §5): log container & terminal app di server
 * `kind=agent` diambil **dari agent**, bukan dari Docker/Nginx host control plane.
 *
 * Kontrak yang dikunci:
 *  1. app server agent ⇒ log dibaca dari agent (`GET /apps/{id}/logs`), respons
 *     UI bentuknya sama (`{code:0,data:{container,tail,text,at}}`), dan daemon
 *     lokal **tidak** tersentuh (fake docker gagal keras bila dipanggil);
 *  2. app server lokal ⇒ perilaku lama (regresi eksplisit: daemon lokal dipakai,
 *     tidak ada panggilan agent);
 *  3. terminal: `open`/`input`/`close` diteruskan ke agent (sesi hidup di sana);
 *     `stream` mengembalikan `Webman\Http\Response` **chunked** dan byte SSE agent
 *     diteruskan apa adanya lewat `Timer` (non-blocking, tanpa buffer penuh);
 *  4. nama container tak valid ditolak **sebelum** panggilan apa pun (control
 *     plane), dan tetap divalidasi lagi di agent (`AppContainers::resolve`);
 *  5. kegagalan agent (401/404/timeout) ⇒ respons galat yang jelas **tanpa**
 *     membocorkan token server;
 *  6. batas ukuran teks log ditegakkan di control plane (jaring pengaman kedua);
 *  7. koneksi SSE drop **tidak** menutup sesi di agent (aturan repo) — tidak ada
 *     `POST /terminal/{token}/close` yang dikirim karena klien pergi;
 *  8. `run` (one-shot) untuk app server agent **dijalankan di agent** dengan
 *     mekanisme yang sama seperti jalur lokal (`POST /terminal/run` ⇒
 *     `DockerExec::runCommand()` di sana), bukan di server lokal dan bukan sesi
 *     interaktif.
 *
 * Semua uji tanpa jaringan, tanpa Docker, tanpa `apps/`/`runtime/` nyata.
 */
class LogTerminalServerProxyTest extends TestCase
{
    private const APP_ID = 'app-a1';
    private const TOKEN = 'tok-uji-proxy-1a2b3c';
    private const SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private string $tmp;

    /** Loop event workerman untuk uji (dibuat sekali, dipakai `pumpLoop()`). */
    private Select $loop;

    /** @var list<LogTerminalProxyFakeConnection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agent-proxy-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/tls', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);

        // `Timer` hanya hidup di lingkungan workerman; uji menyediakan loop-nya
        // sendiri (tanpa worker/HTTP server) supaya tick pemompa bisa dijalankan.
        // Loop-nya SATU instance: timer didaftarkan ke loop ini oleh controller.
        $this->loop = new Select();
        Timer::init($this->loop);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->dropClient();
        }
        $this->connections = [];
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Resolusi server (satu pintu AgentDeployer::forApp)
    // ==================================================================

    public function testForAppPicksTheAgentDeployerOnlyForAgentServers(): void
    {
        $store = new ServerStore($this->tmp . '/servers.sqlite');
        $store->create([
            'id' => 'srv-agent',
            'name' => 'agent-1',
            'kind' => 'agent',
            'base_url' => 'https://agent.test',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::selfSignedPem(),
        ]);
        $resolver = new ServerResolver($store);

        $this->assertNull(
            AgentDeployer::forApp(['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'local'], $resolver),
            'server lokal ⇒ null (jalur lama)'
        );
        $this->assertInstanceOf(
            AgentDeployer::class,
            AgentDeployer::forApp(['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'srv-agent'], $resolver)
        );

        // `server_id` tak dikenal ⇒ fail-fast, TIDAK jatuh ke server lokal.
        try {
            AgentDeployer::forApp(['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'srv-hilang'], $resolver);
            $this->fail('server_id tak dikenal harus melempar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak dikenal', $e->getMessage());
        }
    }

    // ==================================================================
    // (1)/(2)/(4)/(5)/(6) Log container
    // ==================================================================

    public function testAgentAppLogsComeFromTheAgentAndNeverFromTheLocalDaemon(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => [
            'container' => 'demo-web-1',
            'tail' => 500,
            'text' => "baris dari AGENT\n",
            'at' => '10:00:00',
        ]]);

        $docker = new LogTerminalProxyDocker();
        $docker->explode = true;
        $controller = new LogTerminalProxyLogController($this->deployer($handler), $docker);

        $response = $controller->index($this->get('/api/apps/' . self::APP_ID . '/logs?container=demo-web-1&tail=500'), self::APP_ID);
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('baris dari AGENT', trim((string) $body['data']['text']));
        $this->assertSame('demo-web-1', $body['data']['container']);
        $this->assertSame(500, $body['data']['tail']);
        $this->assertArrayHasKey('at', $body['data']);

        $this->assertSame('GET /apps/' . self::APP_ID . '/logs', $handler->call(0)['method'] . ' ' . $handler->call(0)['path']);
        $this->assertStringStartsWith('Bearer ', $handler->call(0)['authorization']);
    }

    public function testInvalidContainerNameIsRejectedOnTheControlPlaneBeforeAnyCall(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $docker = new LogTerminalProxyDocker();
        $docker->explode = true;
        $controller = new LogTerminalProxyLogController($this->deployer($handler), $docker);

        $response = $controller->index(
            $this->get('/api/apps/' . self::APP_ID . '/logs?container=' . rawurlencode('../../etc/passwd')),
            self::APP_ID
        );

        $this->assertSame(404, $this->payload($response)['code']);
        $this->assertSame(0, $handler->count(), 'container tak valid tidak boleh sampai ke agent');
    }

    public function testUnknownContainerOnTheAgentIsReportedAsNotFound(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        // Container di luar daftar proyeksi ⇒ ditolak control plane (tanpa HTTP).
        $controller = new LogTerminalProxyLogController($this->deployer($handler), new LogTerminalProxyDocker());

        $response = $controller->index($this->get('/api/apps/' . self::APP_ID . '/logs?container=container-lain'), self::APP_ID);

        $this->assertSame(404, $this->payload($response)['code']);
        $this->assertSame(0, $handler->count());
    }

    public function testAgentFailureIsReportedWithoutLeakingTheToken(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        // Agent yang "bocor" (memantulkan token di body galat) tetap tidak boleh
        // membuat token tampil di respons UI.
        $handler->replyJson(['error' => 'token ditolak: ' . self::TOKEN], 401);

        $controller = new LogTerminalProxyLogController($this->deployer($handler), new LogTerminalProxyDocker());
        $body = $this->payload($controller->index($this->get('/api/apps/' . self::APP_ID . '/logs'), self::APP_ID));

        $this->assertSame(502, $body['code']);
        $this->assertStringNotContainsString(self::TOKEN, (string) $body['msg']);
        $this->assertStringContainsString('401', (string) $body['msg']);
    }

    public function testLogResponseIsCappedAtTheContractLimit(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => [
            'container' => 'demo-web-1',
            'tail' => 200,
            'text' => str_repeat('x', AgentDeployer::MAX_LOG_BYTES + 4096),
        ]]);

        $controller = new LogTerminalProxyLogController($this->deployer($handler), new LogTerminalProxyDocker());
        $body = $this->payload($controller->index($this->get('/api/apps/' . self::APP_ID . '/logs'), self::APP_ID));

        $this->assertSame(0, $body['code']);
        $this->assertLessThanOrEqual(AgentDeployer::MAX_LOG_BYTES, strlen((string) $body['data']['text']));
    }

    public function testLocalAppLogsKeepTheOldBehaviour(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $docker = new LogTerminalProxyDocker();
        $docker->raw = self::multiplexed("log lokal\n");

        $controller = new LogTerminalProxyLogController(null, $docker);
        $response = $controller->index($this->get('/api/apps/' . self::APP_ID . '/logs?container=demo-db-1&tail=100'), self::APP_ID);
        $body = $this->payload($response);

        $this->assertSame(0, $body['code']);
        $this->assertSame("log lokal\n", $body['data']['text'], 'stream multiplexed dibersihkan seperti sebelumnya');
        $this->assertSame(['container' => 'demo-db-1', 'tail' => 100, 'timestamps' => true], $docker->calls[0]);
        $this->assertSame(0, $handler->count(), 'app server lokal tidak boleh memanggil agent');
    }

    // ==================================================================
    // (3)/(7)/(8) Terminal
    // ==================================================================

    public function testTerminalOpenInputCloseAreForwardedToTheAgent(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $handler
            ->replyJson(['code' => 0, 'data' => [
                'token' => self::SESSION,
                'pid' => 99,
                'shell' => 'bash',
                'user' => 'root',
                'container' => 'demo-web-1',
            ]])
            ->replyJson(['code' => 0])
            ->replyJson(['code' => 0]);

        $exec = new LogTerminalProxyExec();
        $exec->explode = true;
        $controller = new LogTerminalProxyTerminalController($this->deployer($handler), $exec);

        $open = $this->payload($controller->open(
            $this->post(['container' => 'demo-web-1', 'shell' => 'bash'], '/api/apps/' . self::APP_ID . '/terminal/open'),
            self::APP_ID
        ));
        $this->assertSame(0, $open['code']);
        $this->assertSame(self::SESSION, $open['data']['token']);

        $this->assertSame('POST /terminal/open', $handler->call(0)['method'] . ' ' . $handler->call(0)['path']);
        $sent = $handler->jsonBody(0);
        $this->assertSame(self::APP_ID, $sent['app_id']);
        $this->assertSame('demo-web-1', $sent['container']);
        $this->assertSame('bash', $sent['shell']);
        $this->assertSame('root', $sent['user'], 'kebijakan user default root tetap berlaku');

        $input = $this->payload($controller->input(
            $this->post(['data' => "whoami\n"], '/api/apps/' . self::APP_ID . '/terminal/' . self::SESSION . '/input'),
            self::APP_ID,
            self::SESSION
        ));
        $this->assertSame(0, $input['code']);
        $this->assertSame('POST /terminal/' . self::SESSION . '/input', $handler->call(1)['method'] . ' ' . $handler->call(1)['path']);
        $this->assertSame(["whoami\n"], [$handler->jsonBody(1)['data']]);

        $close = $this->payload($controller->close(
            $this->post([], '/api/apps/' . self::APP_ID . '/terminal/' . self::SESSION . '/close'),
            self::APP_ID,
            self::SESSION
        ));
        $this->assertSame(0, $close['code']);
        $this->assertSame('POST /terminal/' . self::SESSION . '/close', $handler->call(2)['method'] . ' ' . $handler->call(2)['path']);
        $this->assertSame(self::APP_ID, $handler->jsonBody(2)['app_id']);

        // Audit tetap ditulis di control plane (identitas pelaku ada di sini).
        $this->assertCount(2, $controller->audits);
        $this->assertSame('demo-web-1', $controller->audits[0]['container']);
    }

    public function testLocalTerminalKeepsUsingTheLocalDockerExec(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $exec = new LogTerminalProxyExec('tok-lokal');
        $controller = new LogTerminalProxyTerminalController(null, $exec);

        $body = $this->payload($controller->open(
            $this->post(['container' => 'demo-web-1'], '/api/apps/' . self::APP_ID . '/terminal/open'),
            self::APP_ID
        ));

        $this->assertSame(0, $body['code']);
        $this->assertSame('tok-lokal', $body['data']['token']);
        $this->assertSame(self::APP_ID, $exec->openCalls[0]['app']);
        $this->assertSame(0, $handler->count(), 'app server lokal tidak boleh memanggil agent');
    }

    public function testInvalidContainerIsRejectedBeforeAnyAgentCall(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $exec = new LogTerminalProxyExec();
        $exec->explode = true;
        $controller = new LogTerminalProxyTerminalController($this->deployer($handler), $exec);

        $body = $this->payload($controller->open(
            $this->post(['container' => 'nama container dengan spasi'], '/api/apps/' . self::APP_ID . '/terminal/open'),
            self::APP_ID
        ));

        $this->assertSame(400, $body['code']);
        $this->assertSame(0, $handler->count());
    }

    public function testTerminalStreamIsChunkedAndDoesNotCloseTheAgentSessionOnClientDrop(): void
    {
        $sse = "event: output\ndata: aGVsbG8=\n\n";
        $handler = new LogTerminalProxyFakeHandler();
        $handler->reply(200, $sse, ['Content-Type' => 'text/event-stream']);

        $controller = new LogTerminalProxyTerminalController($this->deployer($handler), new LogTerminalProxyExec());
        $request = $this->get('/api/apps/' . self::APP_ID . '/terminal/' . self::SESSION . '/stream');
        $connection = $this->connection($request);

        $response = $controller->stream($request, self::APP_ID, self::SESSION);

        // Kontrak streaming: kelas respons Webman + chunked (jalur streaming Webman).
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeader('Content-Type'));
        $this->assertSame('chunked', $response->getHeader('Transfer-Encoding'));
        $this->assertSame([], $connection->sent, 'byte tidak boleh dikirim sebelum respons kembali');

        $this->pumpLoop();
        $this->assertSame($sse, $connection->payload(), 'byte SSE agent diteruskan apa adanya');
        $this->assertSame(1, $connection->closeCalls, 'aliran chunked ditutup dengan benar saat agent selesai');
        $this->assertSame(
            ['GET /terminal/' . self::SESSION . '/stream'],
            $handler->paths(),
            'upstream hanya GET stream (tanpa close sesi)'
        );

        // Klien pergi (proxy memutus koneksi / laptop sleep): sesi di agent tetap
        // hidup — TIDAK ada `close` yang dikirim karena koneksi drop.
        $connection->dropClient();
        $this->assertSame(['GET /terminal/' . self::SESSION . '/stream'], $handler->paths());
    }

    public function testTerminalStreamAgentFailureBecomesSseGone(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $handler->replyJson(['error' => 'Sesi terminal tidak ditemukan.', 'reason' => 'not_found'], 404);

        $controller = new LogTerminalProxyTerminalController($this->deployer($handler), new LogTerminalProxyExec());
        $request = $this->get('/api/apps/' . self::APP_ID . '/terminal/' . self::SESSION . '/stream');
        $connection = $this->connection($request);

        $result = $controller->stream($request, self::APP_ID, self::SESSION);

        $this->assertFalse($result, 'sseGone menutup koneksi sendiri (tanpa respons kedua)');
        $this->assertStringContainsString('event: gone', $connection->payload());
        $this->assertStringNotContainsString(self::TOKEN, $connection->payload(), 'token server tidak boleh bocor ke klien');
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testRunIsDelegatedToTheAgentInsteadOfRunningLocally(): void
    {
        $handler = new LogTerminalProxyFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => [
            'code' => 0,
            'stdout' => "ok dari agent\n",
            'stderr' => '',
            'timedOut' => false,
        ]]);

        $exec = new LogTerminalProxyExec();
        $exec->explode = true;
        $controller = new LogTerminalProxyTerminalController($this->deployer($handler), $exec);

        $body = $this->payload($controller->run(
            $this->post(['container' => 'demo-web-1', 'command' => 'uptime'], '/api/apps/' . self::APP_ID . '/terminal/run'),
            self::APP_ID
        ));

        $this->assertSame(0, $body['code']);
        $this->assertSame('ok dari agent', trim((string) $body['data']['stdout']));
        $this->assertSame(
            ['POST /terminal/run'],
            $handler->paths(),
            'perintah satu-kali wajib dijalankan DI server agent'
        );
        $sent = $handler->jsonBody(0);
        $this->assertSame(self::APP_ID, $sent['app_id']);
        $this->assertSame('demo-web-1', $sent['container']);
        $this->assertSame('uptime', $sent['command']);
        // Timeout dipangkas ke anggaran HTTP klien agent (≤30 s, aturan repo).
        $this->assertLessThanOrEqual(20, (int) $sent['timeout']);
        $this->assertGreaterThan(0, (int) $sent['timeout']);
        $this->assertSame([], $exec->openCalls, 'dan tidak boleh dijalankan di server lokal');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function deployer(LogTerminalProxyFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => 'srv-agent',
            'name' => 'agent-1',
            'kind' => 'agent',
            'base_url' => 'https://agent.test',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::selfSignedPem(),
        ];
        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'));

        return new AgentDeployer($server, $client);
    }

    private function connection(Request $request): LogTerminalProxyFakeConnection
    {
        $connection = new LogTerminalProxyFakeConnection();
        $request->connection = $connection;
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * Jalankan event loop sebentar supaya tick `Timer` (pemompa aliran agent)
     * benar-benar dieksekusi — tanpa server/klien nyata.
     */
    private function pumpLoop(float $seconds = 0.4): void
    {
        $stop = Timer::add($seconds, function (): void {
            $this->loop->stop();
        }, [], false);
        $this->loop->run();
        Timer::del($stop);
    }

    /**
     * @param array<string,string> $fields
     */
    private function post(array $fields, string $path): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . "Accept: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n");
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Stream multiplexed Docker (header 8 byte + payload) seperti Engine API. */
    private static function multiplexed(string ...$lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            $payload = $line;
            $out .= "\x01\x00\x00\x00" . pack('N', strlen($payload)) . $payload;
        }

        return $out;
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
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $exported);

        return $pem = (string) $exported;
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
