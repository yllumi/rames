<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentTerminalController;
use app\library\Docker\DockerExec;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use support\Request;
use Webman\Http\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;

// Helper webman (`base_path()`, `json()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `DockerExec` tiruan **sisi agent**: sesi hidup di memori uji (tanpa Docker,
 * FIFO, proses, atau `runtime/terminal` nyata) dan merekam setiap panggilan.
 *
 * Dideklarasikan di berkas uji ini supaya tetap dapat di-autoload saat dijalankan
 * dengan `--filter`.
 */
final class AgentTerminalFakeExec extends DockerExec
{
    /** @var array<int,array{app:string,container:string,opts:array<string,string>}> */
    public array $openCalls = [];

    /** @var array<int,array{token:string,data:string}> */
    public array $inputs = [];

    /** @var list<string> */
    public array $closed = [];

    /** @var list<string> */
    public array $marked = [];

    public string $output = '';

    public bool $running = true;

    public function __construct(private string $token, private string $appId)
    {
    }

    public function open(string $appId, string $container, array $opts = []): array
    {
        $this->openCalls[] = ['app' => $appId, 'container' => $container, 'opts' => $opts];

        return [
            'token' => $this->token,
            'pid' => 4242,
            'shell' => ($opts['shell'] ?? '') === '' ? 'bash' : (string) $opts['shell'],
            'user' => ($opts['user'] ?? '') === '' ? 'root' : (string) $opts['user'],
            'container' => $container,
        ];
    }

    public function sessionInfo(string $token, string $appId): ?array
    {
        if ($token !== $this->token || $appId !== $this->appId) {
            return null;
        }

        return ['token' => $token, 'app_id' => $appId, 'container' => 'demo-web-1', 'shell' => 'bash'];
    }

    public function writeInput(string $token, string $data): bool
    {
        $this->inputs[] = ['token' => $token, 'data' => $data];

        return true;
    }

    public function readOutput(string $token): string
    {
        $chunk = $this->output;
        $this->output = '';

        return $chunk;
    }

    public function markOutput(string $token): void
    {
        $this->marked[] = $token;
    }

    public function touchActivity(string $token): void
    {
    }

    public function hasOutput(string $token): bool
    {
        return true;
    }

    public function isRunning(string $token): bool
    {
        return $this->running;
    }

    public function closeSession(string $token): void
    {
        $this->closed[] = $token;
    }
}

/**
 * Koneksi TCP tiruan (tanpa socket nyata) — `instanceof TcpConnection` tetap
 * terpenuhi sehingga jalur SSE diuji apa adanya.
 */
final class AgentTerminalFakeConnection extends TcpConnection
{
    /** @var list<mixed> */
    public array $sent = [];

    public int $closeCalls = 0;

    private bool $established = true;

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

    /** Tirukan klien yang memutus koneksi SSE (onClose dipanggil Workerman). */
    public function dropClient(): void
    {
        $this->established = false;
        $callback = $this->onClose;
        if ($callback !== null) {
            $callback($this);
        }
    }

    /** Byte yang diterima klien (framing chunked dibuang). */
    public function payload(): string
    {
        $out = '';
        foreach ($this->sent as $item) {
            $raw = (string) $item;
            $pos = strpos($raw, "\r\n");
            if ($pos === false) {
                continue;
            }
            $out .= substr($raw, $pos + 2, -2);
        }

        return $out;
    }
}

/** `AgentTerminalController` dengan seam `dockerExec()` (tanpa Docker nyata). */
final class AgentTerminalTestController extends AgentTerminalController
{
    public function __construct(private AppStore $apps, private DockerExec $exec)
    {
        parent::__construct($apps);
    }

    protected function dockerExec(): DockerExec
    {
        return $this->exec;
    }
}

/**
 * Endpoint terminal **sisi agent** (F3a, plan §4.1/§5) — sesi hidup di agent.
 *
 * Kontrak yang dikunci:
 *  1. app dibaca dari proyeksi agent; app tak dikenal ⇒ **404 JSON** eksplisit
 *     (bukan 403/500) tanpa menyentuh Docker;
 *  2. container divalidasi milik app (`AppContainers::resolve()` — Engine server
 *     ini) sebelum sesi dibuka; container asing ⇒ 400 tanpa efek samping;
 *  3. `open`/`input`/`close` memakai sesi agent (`DockerExec`), `app_id` wajib
 *     cocok dengan pemilik token (`sessionInfo($token, $appId)`) — token app lain
 *     tidak bisa dipakai;
 *  4. `stream` mengembalikan respons `Webman\Http\Response` ber-`Transfer-Encoding:
 *     chunked` + `Content-Type: text/event-stream` (kontrak SSE repo), dan
 *  5. koneksi SSE drop **tidak** menutup sesi (aturan repo) — hanya `close` yang
 *     memanggil `DockerExec::closeSession()`.
 *
 * Tanpa Docker, tanpa jaringan, tanpa `apps/`/`runtime/` nyata.
 */
class AgentTerminalControllerTest extends TestCase
{
    private const APP_ID = 'app-agent-1';
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private string $tmp;

    private AppStore $apps;

    private Select $loop;

    /** @var list<AgentTerminalFakeConnection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agent-term-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        $this->apps = new AppStore($this->tmp . '/rames.sqlite');
        $this->apps->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'local',
            'primary_service' => 'web',
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'demo-web-1'],
                ['service_name' => 'db', 'container_name' => 'demo-db-1'],
            ],
        ]);

        // `Timer` hanya hidup di lingkungan workerman: uji menyediakan loop-nya.
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
    // open
    // ==================================================================

    public function testOpenSessionCreatesTheSessionAtTheAgent(): void
    {
        $exec = $this->exec();
        $response = $this->controller($exec)->openSession($this->jsonPost('/terminal/open', [
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'shell' => 'bash',
        ]));
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame(self::TOKEN, $body['data']['token']);
        $this->assertSame(self::APP_ID, $exec->openCalls[0]['app']);
        $this->assertSame('demo-web-1', $exec->openCalls[0]['container']);
        $this->assertSame('bash', $exec->openCalls[0]['opts']['shell']);
        $this->assertSame('root', $exec->openCalls[0]['opts']['user'], 'default user = root (kebijakan controller)');
    }

    public function testOpenRejectsForeignContainerWithoutSideEffects(): void
    {
        $exec = $this->exec();
        $body = $this->payload($this->controller($exec)->openSession($this->jsonPost('/terminal/open', [
            'app_id' => self::APP_ID,
            'container' => 'container-app-lain',
        ])));

        $this->assertSame(400, $body['code']);
        $this->assertSame([], $exec->openCalls, 'sesi tidak boleh dibuka untuk container asing');
    }

    public function testOpenForUnknownOrMissingAppIsJsonNotFound(): void
    {
        foreach ([['app_id' => 'app-tidak-ada'], []] as $fields) {
            $exec = $this->exec();
            $response = $this->controller($exec)->openSession($this->jsonPost('/terminal/open', $fields));
            $body = $this->payload($response);

            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame('not_found', $body['reason']);
            $this->assertSame([], $exec->openCalls);
        }
    }

    // ==================================================================
    // input / close
    // ==================================================================

    public function testInputIsForwardedToTheAgentSession(): void
    {
        $exec = $this->exec();
        $body = $this->payload($this->controller($exec)->inputSession(
            $this->jsonPost('/terminal/' . self::TOKEN . '/input', ['app_id' => self::APP_ID, 'data' => "whoami\n"]),
            self::TOKEN
        ));

        $this->assertSame(0, $body['code']);
        $this->assertSame([['token' => self::TOKEN, 'data' => "whoami\n"]], $exec->inputs);
    }

    public function testInputIsRejectedWhenTheTokenBelongsToAnotherApp(): void
    {
        // Token sah, tetapi `app_id` bukan pemiliknya ⇒ app tidak ada di proyeksi
        // app itu ⇒ 404 (HTTP + JSON mesin), tanpa menyentuh sesi.
        $exec = $this->exec();
        $response = $this->controller($exec)->inputSession(
            $this->jsonPost('/terminal/' . self::TOKEN . '/input', ['app_id' => 'app-lain', 'data' => "whoami\n"]),
            self::TOKEN
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $this->payload($response)['reason']);
        $this->assertSame([], $exec->inputs);
    }

    public function testCloseEndsOnlyThatSession(): void
    {
        $exec = $this->exec();
        $body = $this->payload($this->controller($exec)->closeSession(
            $this->jsonPost('/terminal/' . self::TOKEN . '/close', ['app_id' => self::APP_ID]),
            self::TOKEN
        ));

        $this->assertSame(0, $body['code']);
        $this->assertSame([self::TOKEN], $exec->closed);
    }

    // ==================================================================
    // stream (SSE) — kontrak repo
    // ==================================================================

    public function testStreamIsChunkedSseAndSessionSurvivesClientDrop(): void
    {
        $exec = $this->exec();
        $exec->output = 'halo';

        $request = $this->getStreamRequest();
        $connection = $this->connection($request);

        $response = $this->controller($exec)->streamSession($request, self::TOKEN);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeader('Content-Type'));
        $this->assertSame('chunked', $response->getHeader('Transfer-Encoding'));
        $this->assertSame([], $connection->sent, 'frame tidak boleh mendahului header SSE');

        $this->pumpLoop();

        $payload = $connection->payload();
        $this->assertStringContainsString('event: output', $payload);
        $this->assertStringContainsString(base64_encode('halo'), $payload);
        $this->assertSame([self::TOKEN], $exec->marked);

        // Proxy/klien memutus koneksi SSE: sesi TETAP hidup di agent.
        $connection->dropClient();
        $this->assertSame([], $exec->closed, 'koneksi drop tidak boleh menutup sesi (aturan repo)');

        // Sesi benar-benar ditutup hanya lewat `close`.
        $this->payload($this->controller($exec)->closeSession(
            $this->jsonPost('/terminal/' . self::TOKEN . '/close', ['app_id' => self::APP_ID]),
            self::TOKEN
        ));
        $this->assertSame([self::TOKEN], $exec->closed);
    }

    public function testStreamWithoutSessionAnswersGone(): void
    {
        $exec = $this->exec();
        $request = $this->getStreamRequest();
        $connection = $this->connection($request);

        $result = $this->controller($exec)->streamSession($request, 'ffffffffffffffffffffffffffffffff');

        $this->assertFalse($result, 'sseGone menutup koneksi sendiri');
        $this->assertStringContainsString('event: gone', $connection->payload());
        $this->assertSame([], $exec->closed);
    }

    // ==================================================================
    // Higienitas kelas & kontrak route
    // ==================================================================

    /** Kelas hanya mediator: seam uji nullable, tanpa state lintas-request. */
    public function testControllerIsAStatelessMediator(): void
    {
        $reflection = new ReflectionClass(AgentTerminalController::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), "properti {$property->getName()} harus privat");
            $this->assertTrue(
                $property->getType()?->allowsNull() ?? false,
                "properti {$property->getName()} hanya boleh berisi seam uji (nullable)"
            );
        }
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = (string) $parameter->getType();
            $this->assertStringNotContainsString('Request', $type);
            $this->assertStringNotContainsString('Session', $type);
        }
        foreach (['openSession', 'streamSession', 'inputSession', 'closeSession'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), "endpoint agent {$method}() hilang");
        }

        $routes = (string) file_get_contents(dirname(__DIR__) . '/config/route.php');
        foreach ([
            "Route::post('/terminal/open'",
            "Route::get('/terminal/{token}/stream'",
            "Route::post('/terminal/{token}/input'",
            "Route::post('/terminal/{token}/close'",
        ] as $definition) {
            $this->assertStringContainsString($definition, $routes);
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(DockerExec $exec): AgentTerminalTestController
    {
        return new AgentTerminalTestController($this->apps, $exec);
    }

    private function exec(): AgentTerminalFakeExec
    {
        return new AgentTerminalFakeExec(self::TOKEN, self::APP_ID);
    }

    private function getStreamRequest(): Request
    {
        return new Request(
            'GET /terminal/' . self::TOKEN . '/stream?app_id=' . self::APP_ID
            . " HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n"
        );
    }

    private function connection(Request $request): AgentTerminalFakeConnection
    {
        $connection = new AgentTerminalFakeConnection();
        $request->connection = $connection;
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function jsonPost(string $path, array $fields): Request
    {
        $body = (string) json_encode($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Jalankan loop sebentar supaya tick SSE (`Timer`) benar-benar dieksekusi. */
    private function pumpLoop(float $seconds = 0.4): void
    {
        $stop = Timer::add($seconds, function (): void {
            $this->loop->stop();
        }, [], false);
        $this->loop->run();
        Timer::del($stop);
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
