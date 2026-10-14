<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppAccess;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\controller\AgentAdminerController;
use app\controller\DatabaseController;
use app\library\Docker\DockerClient;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`json()`, `view()`, `config()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Handler Guzzle tiruan untuk jalur **control plane → agent** (proxy Adminer per
 * server, F3d): merekam method/path/query/body/header setiap panggilan dan
 * menulis body respons ke `sink` seperti handler nyata — sehingga
 * `AgentClient::forwardToFile()` (berkas sementara + batas ukuran) benar-benar
 * teruji tanpa jaringan.
 */
final class DatabaseProxyFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string,headers:array<string,string>}> */
    public array $calls = [];

    /** @var list<array{status:int,headers:array<string,string>,body:string}> */
    private array $queue = [];

    /** @param array<string,string> $headers */
    public function reply(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => $body];

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return array{method:string,path:string,query:string,body:string,headers:array<string,string>} */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total {$this->count()})");
        }

        return $this->calls[$index];
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('DatabaseProxyFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', array_map('strval', (array) $values));
        }
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
            'headers' => $headers,
        ];

        $next = array_shift($this->queue);

        $sink = $options['sink'] ?? null;
        if ($next['body'] !== '' && $sink instanceof StreamInterface) {
            $sink->write($next['body']);
        }

        $responseHeaders = $next['headers'];
        if ($next['body'] !== '' && !isset($responseHeaders['Content-Length'])) {
            $responseHeaders['Content-Length'] = (string) strlen($next['body']);
        }

        $response = new Psr7Response($next['status'], $responseHeaders);
        // Handler Guzzle nyata (cURL/stream/mock) memanggil `on_headers` sebelum
        // body dialirkan — guard batas ukuran produksi bergantung pada ini.
        $onHeaders = $options['on_headers'] ?? null;
        if (is_callable($onHeaders)) {
            $onHeaders($response, $request);
        }

        return Create::promiseFor($response);
    }
}

/**
 * Engine Docker **host control plane** tiruan: merekam sentuhan, dan bisa
 * "meledak" bila tersentuh (bukti app server agent tidak pernah dibaca dari
 * daemon lokal).
 */
final class DatabaseProxyLocalDocker extends DockerClient
{
    /** @var list<string> */
    public array $inspected = [];

    public int $pingCalls = 0;

    /** true ⇒ sentuhan apa pun = kegagalan uji (jalur lokal tidak boleh aktif). */
    public bool $explode = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function ping(): bool
    {
        $this->pingCalls++;
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH (uji gagal).');
        }

        return false; // helper Adminer lokal tidak bisa siap → 502 (jalur lokal)
    }

    public function inspectContainer(string $id): array
    {
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH (uji gagal).');
        }
        $this->inspected[] = $id;

        return [
            'Id' => $id,
            'Name' => '/' . $id,
            'Config' => ['Image' => 'mysql:8'],
            'State' => ['Status' => 'running'],
            'NetworkSettings' => ['Networks' => ['demo_default' => ['IPAddress' => '172.20.0.5']]],
        ];
    }
}

/**
 * `DatabaseController` dengan seam uji (tanpa `apps/`, `AppAccess`, sesi Webman,
 * audit ke `runtime/`): app pemilik, deployer agent, engine lokal, jar in-memory,
 * dan renderer view in-memory.
 */
final class DatabaseProxyController extends DatabaseController
{
    /** @var array<string,array<string,string>> jar in-memory (per container) */
    public array $jar = [];

    /** @var list<array{template:string,vars:array<string,mixed>}> */
    public array $rendered = [];

    /**
     * @param array<string,mixed>|null $app
     */
    public function __construct(
        private ?array $app,
        private ?AgentDeployer $agent,
        private DockerClient $docker,
    ) {
    }

    protected function findOwningApp(string $container): ?array
    {
        return $this->app;
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->agent;
    }

    protected function dockerClient(): DockerClient
    {
        return $this->docker;
    }

    protected function adminerHelper(): \app\library\Adminer\AdminerHelper
    {
        // Helper lokal memakai daemon tiruan (uji tidak menjalankan docker nyata).
        return new \app\library\Adminer\AdminerHelper($this->docker);
    }

    protected function sessionJar(Request $request, string $container): array
    {
        return $this->jar[$container] ?? [];
    }

    protected function rememberJar(Request $request, string $container, array $jar): void
    {
        $this->jar[$container] = $jar;
    }

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered[] = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered:' . $template);
    }

    protected function audit(?array $app, string $container, string $action): void
    {
        // audit nyata menyentuh runtime/logs/db — dilewati di uji
    }
}

/**
 * Proxy Adminer untuk app di **server agent** (F3d multi-server).
 *
 * Yang dikunci:
 *  1. app server agent ⇒ seluruh permintaan diteruskan ke agent (helper Adminer
 *     hidup di sana); daemon Docker host control plane **tidak pernah** tersentuh;
 *  2. app server lokal ⇒ jalur lama tetap dipakai (engine lokal dibaca, tidak ada
 *     panggilan HTTP ke agent);
 *  3. body multipart POST diteruskan **utuh** (byte demi byte, `Content-Type`
 *     tidak ditimpa `application/json`);
 *  4. `X-Forwarded-Prefix` & konteks `X-Forwarded-*` dilaporkan ke agent, jar
 *     cookie Adminer dititipkan lewat header `X-Rames-Adminer-Jar` (state tetap
 *     di sesi dashboard);
 *  5. batas ukuran respons 64 MiB (tidak dinaikkan) & status domain agent
 *     (413/409/404) dipetakan ke bentuk yang sama dengan jalur lokal.
 */
final class DatabaseAdminerServerProxyTest extends TestCase
{
    private const CONTAINER = 'demo-db-1';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-dbproxy-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp . '/adminer-proxy', 0777, true) && !is_dir($this->tmp . '/adminer-proxy')) {
            throw new RuntimeException('gagal membuat temp dir');
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (i)/(iii) app server agent — diteruskan ke agent, body utuh
    // ==================================================================

    public function testAgentAppMultipartPostIsForwardedIntactAndLocalDaemonUntouched(): void
    {
        $handler = new DatabaseProxyFakeHandler();
        $handler->reply(200, '<html>adminer</html>', ['Content-Type' => 'text/html; charset=utf-8']);

        $docker = new DatabaseProxyLocalDocker();
        $docker->explode = true; // sentuhan apa pun = kegagalan

        $controller = $this->agentController($handler, $docker);
        $request = $this->multipartRequest('/database/' . self::CONTAINER . '/adminer/import', 'file', 'dump.sql', 'INSERT INTO t VALUES (1);');

        $response = $controller->adminer($request, self::CONTAINER, 'import');

        $this->assertSame(200, $response->getStatusCode());
        $call = $handler->call(0);
        $this->assertSame('POST', $call['method']);
        $this->assertSame('/adminer/' . self::CONTAINER . '/import', $call['path']);
        // Body diteruskan apa adanya (multipart utuh, termasuk boundary & isi).
        $this->assertSame($this->rawBody(), $call['body']);
        $this->assertStringContainsString('INSERT INTO t VALUES (1);', $call['body']);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $call['headers']['content-type']);
        $this->assertSame(1, $handler->count());
        $this->assertSame([], $docker->inspected, 'jalur lokal tidak boleh menyentuh daemon host control plane');
        $this->assertSame(0, $docker->pingCalls);
        // Prefix + konteks proxy dilaporkan ke agent (HTML Adminer tidak disunting).
        $this->assertSame('/database/' . self::CONTAINER . '/adminer', $call['headers']['x-forwarded-prefix']);
        $this->assertSame('http', $call['headers']['x-forwarded-proto']);
        $this->assertArrayHasKey('x-rames-adminer-jar', $call['headers']);
        $this->assertStringContainsString('<html>adminer</html>', $this->bodyOf($response));
    }

    public function testAgentAppSendsSessionJarAndStoresRotatedJar(): void
    {
        $handler = new DatabaseProxyFakeHandler();
        $handler->reply(302, '', [
            'Location' => '/database/' . self::CONTAINER . '/adminer/select',
            'X-Rames-Adminer-Jar' => AgentAdminerController::encodeJar(['adminer_sid' => 'sesi-baru', 'adminer_key' => 'kunci']),
        ]);

        $controller = $this->agentController($handler, new DatabaseProxyLocalDocker());
        // Jar lama ada di sesi dashboard (kunci per container, sama dengan jalur lokal).
        $controller->jar[self::CONTAINER] = ['adminer_sid' => 'sesi-lama'];

        $controller->adminer($this->getRequest('/database/' . self::CONTAINER . '/adminer'), self::CONTAINER);

        $sent = AgentAdminerController::decodeJar($handler->call(0)['headers']['x-rames-adminer-jar'] ?? '');
        $this->assertSame('sesi-lama', $sent['adminer_sid']);
        // Jar yang dirotasi agent disimpan kembali di sesi (bukan di browser).
        $this->assertSame('sesi-baru', $controller->jar[self::CONTAINER]['adminer_sid']);
        $this->assertSame('kunci', $controller->jar[self::CONTAINER]['adminer_key']);
    }

    public function testAgentSessionExpiredMapsToRamesNoticePage(): void
    {
        $handler = new DatabaseProxyFakeHandler();
        $handler->reply(409, '', [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Rames-Adminer-Session-Expired' => '1',
        ]);

        $controller = $this->agentController($handler, new DatabaseProxyLocalDocker());
        $request = $this->postRequest('/database/' . self::CONTAINER . '/adminer/', 'query=SELECT+1');

        $response = $controller->adminer($request, self::CONTAINER, '');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('db/session-expired', $controller->rendered[0]['template'] ?? '');
    }

    public function testAgentFailureIsReportedAsBadGatewayWithoutLocalFallback(): void
    {
        $handler = new DatabaseProxyFakeHandler();
        // Agent nyata menjawab galat helper dengan 502 JSON (`AgentAdminerController`).
        $handler->reply(502, json_encode(['code' => 1, 'error' => 'helper mati', 'reason' => 'helper_failed']));

        $docker = new DatabaseProxyLocalDocker();
        $docker->explode = true;

        $controller = $this->agentController($handler, $docker);

        $response = $controller->adminer($this->getRequest('/database/' . self::CONTAINER . '/adminer'), self::CONTAINER);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringStartsWith('Adminer: ', $this->bodyOf($response));
        $this->assertStringContainsString('helper mati', $this->bodyOf($response));
        // Body JSON mesin tidak pernah diteruskan ke browser.
        $this->assertStringNotContainsString('"reason"', $this->bodyOf($response));
        $this->assertSame([], $docker->inspected);
    }

    // ==================================================================
    // (ii) app server lokal — perilaku lama
    // ==================================================================

    public function testLocalAppKeepsLocalPathAndNeverCallsAgent(): void
    {
        $handler = new DatabaseProxyFakeHandler(); // tidak boleh dipakai
        $docker = new DatabaseProxyLocalDocker();

        // `agent = null` = app dilayani server lokal.
        $controller = new DatabaseProxyController($this->app(), null, $docker);

        $response = $controller->adminer($this->getRequest('/database/' . self::CONTAINER . '/adminer'), self::CONTAINER);

        // Jalur lokal: container di-inspect di daemon host ini, lalu helper lokal
        // gagal disiapkan (Engine tidak tersedia) → 502 polos.
        $this->assertSame([self::CONTAINER], $docker->inspected);
        $this->assertSame(1, $docker->pingCalls, 'helper Adminer lokal memakai Engine host ini');
        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringStartsWith('Adminer: ', $this->bodyOf($response));
        $this->assertSame(0, $handler->count(), 'app lokal tidak boleh memanggil agent');
    }

    public function testExternalContainerWithoutAppStaysOnLocalPath(): void
    {
        $handler = new DatabaseProxyFakeHandler();
        $docker = new DatabaseProxyLocalDocker();

        // Container tanpa app (mis. dibuat manual) ⇒ tanpa deployer agent.
        $controller = new DatabaseProxyController(null, null, $docker);

        $response = $controller->adminer($this->getRequest('/database/external-db/adminer'), 'external-db');

        $this->assertSame(['external-db'], $docker->inspected);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // (iii) batas ukuran respons 64 MiB (tidak dinaikkan)
    // ==================================================================

    public function testOversizedAgentResponseIsRejectedWithLimitMessage(): void
    {
        $handler = new DatabaseProxyFakeHandler();
        // Content-Length di atas plafon → ditolak **sebelum** byte mengalir.
        $handler->reply(200, 'x', ['Content-Length' => (string) (67108864 + 1)]);

        $controller = $this->agentController($handler, new DatabaseProxyLocalDocker());
        $response = $controller->adminer($this->getRequest('/database/' . self::CONTAINER . '/adminer'), self::CONTAINER);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('Berkas terlalu besar untuk proxy Adminer', $this->bodyOf($response));
    }

    public function testAgentMaxBytesIsConfigDrivenAndNeverAboveDefault(): void
    {
        $deployer = $this->deployer(new DatabaseProxyFakeHandler());

        $this->assertSame(67108864, $deployer->adminerMaxBytes(), 'plafon default 64 MiB');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function agentController(DatabaseProxyFakeHandler $handler, DockerClient $docker): DatabaseProxyController
    {
        return new DatabaseProxyController($this->app(), $this->deployer($handler), $docker);
    }

    private function deployer(DatabaseProxyFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => 'srv-agent',
            'name' => 'vps-jakarta-2',
            'kind' => 'agent',
            'base_url' => 'https://agent.test:8443',
            'token' => 'tok-agent-uji-f3d-1a2b3c',
            'tls_cert_pem' => self::selfSignedPem(),
        ];

        return new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls')),
            null,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
            },
            null,
            null,
            // Berkas sementara respons proxy di temp uji (bukan runtime/ nyata).
            $this->tmp . '/adminer-proxy'
        );
    }

    /** @return array<string,mixed> */
    private function app(): array
    {
        return [
            'id' => 'app-demo',
            'name' => 'demo',
            'server_id' => 'srv-agent',
            'primary_service' => 'db',
            'containers' => [
                ['service_name' => 'db', 'container_name' => self::CONTAINER],
            ],
        ];
    }

    private string $body = '';

    private function multipartRequest(string $path, string $field, string $filename, string $content): Request
    {
        $boundary = '----RamesBoundary' . bin2hex(random_bytes(4));
        $this->body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$content}\r\n--{$boundary}--\r\n";

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($this->body) . "\r\n\r\n" . $this->body
        );
    }

    private function rawBody(): string
    {
        return $this->body;
    }

    /**
     * Body respons: jalur berkas (`withFile`) dibaca dari disk — Workerman yang
     * mengirimnya setelah handler kembali, jadi isinya TIDAK ada di `rawBody()`.
     */
    private function bodyOf(Response $response): string
    {
        $file = $response->file['file'] ?? null;
        if (is_string($file) && $file !== '' && is_file($file)) {
            $data = file_get_contents($file);

            return $data === false ? '' : $data;
        }

        return (string) $response->rawBody();
    }

    private function getRequest(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n");
    }

    private function postRequest(string $path, string $body): Request
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
