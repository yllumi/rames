<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentAdminerController;
use app\library\Adminer\AdminerHelper;
use app\library\Adminer\AdminerProxy;
use app\library\Adminer\AdminerProxyLimitExceeded;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`json()`, `config()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Engine Docker server agent tiruan: container DB + container non-DB.
 */
final class AgentAdminerFakeDocker extends DockerClient
{
    /** @var list<string> */
    public array $inspected = [];

    public bool $notFound = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return [
            ['Id' => 'c-db', 'Names' => ['/demo-db-1'], 'Image' => 'mysql:8', 'State' => 'running', 'Status' => 'Up'],
            ['Id' => 'c-web', 'Names' => ['/demo-web-1'], 'Image' => 'nginx:alpine', 'State' => 'running', 'Status' => 'Up'],
        ];
    }

    public function inspectContainer(string $id): array
    {
        if ($this->notFound) {
            throw new RuntimeException('No such container');
        }
        $this->inspected[] = $id;

        return match ($id) {
            'c-web', 'demo-web-1' => ['Id' => $id, 'Name' => '/demo-web-1', 'Config' => ['Image' => 'nginx:alpine']],
            default => [
                'Id' => $id,
                'Name' => '/demo-db-1',
                'Config' => ['Image' => 'mysql:8', 'Env' => ['MYSQL_USER=app', 'MYSQL_PASSWORD=secret', 'MYSQL_DATABASE=appdb']],
                'State' => ['Status' => 'running'],
                'NetworkSettings' => ['Networks' => ['demo_default' => ['IPAddress' => '172.20.0.7']]],
            ],
        };
    }
}

/**
 * Helper Adminer tiruan (tanpa Docker/`docker run`): merekam network yang
 * diminta supaya jalur agent terbukti menyiapkan helper **di server ini**.
 */
final class AgentAdminerFakeHelper extends AdminerHelper
{
    /** @var list<string> */
    public array $ensured = [];

    public int $runs = 0;

    public bool $fail = false;

    public function __construct()
    {
        // tanpa induk: tidak ada DockerClient/ProcessRunner nyata
    }

    public function ensureRunning(): void
    {
        $this->runs++;
        if ($this->fail) {
            throw new RuntimeException('helper tidak bisa dijalankan');
        }
    }

    public function ensureOnNetwork(string $networkName): void
    {
        $this->ensured[] = $networkName;
    }

    public function baseUrl(): string
    {
        return 'http://rames-adminer:8080';
    }
}

/**
 * Proxy Adminer tiruan: merekam spec + argumen `forward()` dan mengembalikan
 * hasil yang ditentukan uji.
 */
final class AgentAdminerFakeProxy extends AdminerProxy
{
    /** @var array<string,mixed> */
    public array $specSeen = [];

    /** @var list<array{method:string,path:string,query:string,body:?string,headers:array<string,string>,jar:array<string,string>}> */
    public array $forwards = [];

    /** @var array<string,string> */
    public array $rotate = [];

    /** @var array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int,session_expired:bool} */
    public array $result = [
        'status' => 200,
        'headers' => ['Content-Type' => ['text/html; charset=utf-8']],
        'body_file' => '',
        'size' => 0,
        'session_expired' => false,
    ];

    public bool $tooLarge = false;

    /** @param array<string,mixed> $spec */
    public function __construct(array $spec)
    {
        parent::__construct($spec);
        $this->specSeen = $spec;
    }

    /**
     * Catat spec yang benar-benar dikirim controller (seam `adminerProxy()`
     * mengembalikan instance yang sudah dibangun lebih dulu).
     *
     * @param array<string,mixed> $spec
     */
    public function captureSpec(array $spec): void
    {
        $this->specSeen = $spec;
    }

    public function forward(string $method, string $path, string $query, ?string $body, array $headers, array &$jar): array
    {
        if ($this->tooLarge) {
            throw new AdminerProxyLimitExceeded(AdminerProxy::LIMIT_MESSAGE);
        }

        $this->forwards[] = compact('method', 'path', 'query', 'body', 'headers', 'jar');
        if ($this->rotate !== []) {
            $jar = $this->rotate;
        }

        return $this->result;
    }
}

/**
 * `AgentAdminerController` **sisi agent** (F3d multi-server).
 *
 * Yang dikunci:
 *  1. `GET /database/containers` mendeteksi container DB server ini (deteksi yang
 *     sama dengan halaman `/database` lokal) tanpa I/O ke host lain;
 *  2. `ANY /adminer/{container}` meneruskan body **mentah** (multipart utuh) &
 *     prefix dari `X-Forwarded-Prefix` ke `AdminerProxy` (HTML tidak disunting);
 *  3. jar cookie dititipkan control plane lewat header (agent tanpa state) dan
 *     dikembalikan setelah rotasi;
 *  4. container tidak ada/bukan DB ⇒ 404 JSON; batas ukuran ⇒ 413; galat helper
 *     ⇒ 502 — semua status HTTP nyata (kontrak `AgentClient`).
 */
final class AgentAdminerControllerTest extends TestCase
{
    private const APP_ID = 'app-demo';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agentadminer-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException('gagal membuat temp dir');
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // GET /database/containers
    // ==================================================================

    public function testContainersListsDbContainersOfThisServer(): void
    {
        $controller = $this->controller($this->docker(), $this->helper(), new AgentAdminerFakeProxy([]));

        $payload = $this->payload($controller->containers($this->get('/database/containers')));

        $this->assertSame(0, $payload['code']);
        $this->assertSame(['demo-db-1'], array_column($payload['data']['rows'], 'container_name'));
        // Field deteksi saja (tanpa env/inspect mentah).
        $this->assertSame(['container_name', 'container_id', 'image', 'state', 'status'], array_keys($payload['data']['rows'][0]));
    }

    // ==================================================================
    // ANY /adminer/{container}
    // ==================================================================

    public function testMultipartBodyAndPrefixAreForwardedRaw(): void
    {
        $docker = $this->docker();
        $helper = $this->helper();
        $proxy = new AgentAdminerFakeProxy([]);
        // Proxy merotasi jar lewat parameter by-reference (Set-Cookie helper).
        $proxy->rotate = ['adminer_sid' => 'sesi-2', 'adminer_key' => 'kunci-2'];

        $controller = $this->controller($docker, $helper, $proxy);
        $body = "--B\r\nContent-Disposition: form-data; name=\"file\"; filename=\"dump.sql\"\r\n\r\nSELECT 1;\r\n--B--\r\n";

        $response = $controller->proxy(
            $this->rawPost('/adminer/demo-db-1/import?username=app', 'multipart/form-data; boundary=B', $body),
            'demo-db-1',
            'import'
        );

        $this->assertSame(200, $response->getStatusCode());
        $forward = $proxy->forwards[0];
        $this->assertSame('POST', $forward['method']);
        $this->assertSame('import', $forward['path']);
        $this->assertSame('username=app', $forward['query'], 'query diteruskan apa adanya');
        $this->assertSame($body, $forward['body'], 'body multipart diteruskan byte demi byte');
        $this->assertSame('multipart/form-data; boundary=B', $forward['headers']['content-type']);
        $this->assertSame('text/html', $forward['headers']['accept'], 'header browser diteruskan apa adanya');
        // Prefix & konteks dari control plane (bukan dari browser).
        $this->assertSame('/database/demo-db-1/adminer', $proxy->specSeen['prefix']);
        $this->assertSame('https', $proxy->specSeen['forwarded_proto']);
        $this->assertSame('203.0.113.9', $proxy->specSeen['forwarded_for']);
        // Host = nama container DB (helper & DB berbagi network app → DNS bekerja).
        $this->assertSame('demo-db-1:3306', $proxy->specSeen['credentials']['server']);
        // Helper disiapkan di server ini pada network app.
        $this->assertSame(1, $helper->runs);
        $this->assertSame(['demo_default'], $helper->ensured);
        // Jar hasil rotasi dikembalikan ke control plane (bukan ke browser).
        $this->assertSame(
            ['adminer_sid' => 'sesi-2', 'adminer_key' => 'kunci-2'],
            $this->header($response, AgentAdminerController::HEADER_JAR)
        );
    }

    public function testJarFromControlPlaneHeaderIsLoadedAndRotated(): void
    {
        $proxy = new AgentAdminerFakeProxy([]);
        $proxy->rotate = ['adminer_sid' => 'sesi-baru'];
        $controller = $this->controller($this->docker(), $this->helper(), $proxy);

        $request = $this->get('/adminer/demo-db-1', [
            'X-Forwarded-Prefix' => '/database/demo-db-1/adminer',
            'X-Rames-Adminer-Jar' => AgentAdminerController::encodeJar(['adminer_sid' => 'sesi-lama']),
        ]);

        $response = $controller->proxy($request, 'demo-db-1');

        $this->assertSame(['adminer_sid' => 'sesi-lama'], $proxy->forwards[0]['jar']);
        $this->assertSame(
            ['adminer_sid' => 'sesi-baru'],
            AgentAdminerController::decodeJar((string) ($response->getHeader(AgentAdminerController::HEADER_JAR) ?? ''))
        );
    }

    public function testSessionExpiredIsFlaggedForControlPlane(): void
    {
        $proxy = new AgentAdminerFakeProxy([]);
        $proxy->result['status'] = 409;
        $proxy->result['headers'] = ['Content-Type' => ['text/html; charset=utf-8']];
        $proxy->result['session_expired'] = true;

        $controller = $this->controller($this->docker(), $this->helper(), $proxy);
        $response = $controller->proxy($this->rawPost('/adminer/demo-db-1/', 'application/x-www-form-urlencoded', 'q=1'), 'demo-db-1');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('1', (string) $response->getHeader(AgentAdminerController::HEADER_SESSION_EXPIRED));
    }

    public function testOversizedResponseMapsTo413(): void
    {
        $proxy = new AgentAdminerFakeProxy([]);
        $proxy->tooLarge = true;

        $controller = $this->controller($this->docker(), $this->helper(), $proxy);
        $response = $controller->proxy($this->get('/adminer/demo-db-1'), 'demo-db-1');

        $this->assertSame(413, $response->getStatusCode());
        $payload = $this->payload($response);
        $this->assertSame(1, $payload['code']);
        $this->assertStringContainsString('terlalu besar', (string) $payload['error']);
    }

    public function testUnknownContainerIsNotFound(): void
    {
        $docker = $this->docker();
        $docker->notFound = true;

        $controller = $this->controller($docker, $this->helper(), new AgentAdminerFakeProxy([]));
        $response = $controller->proxy($this->get('/adminer/hilang-db-1'), 'hilang-db-1');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $this->payload($response)['reason']);
    }

    public function testNonDbContainerIsNotFound(): void
    {
        $controller = $this->controller($this->docker(), $this->helper(), new AgentAdminerFakeProxy([]));
        $response = $controller->proxy($this->get('/adminer/demo-web-1'), 'demo-web-1');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_db_container', $this->payload($response)['reason']);
    }

    public function testInvalidContainerNameIsRejectedWithoutTouchingEngine(): void
    {
        $docker = $this->docker();
        $controller = $this->controller($docker, $this->helper(), new AgentAdminerFakeProxy([]));

        $response = $controller->proxy($this->get('/adminer/bad%2Fname'), 'bad/name');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $docker->inspected);
    }

    public function testHelperFailureIsBadGateway(): void
    {
        $helper = $this->helper();
        $helper->fail = true;

        $controller = $this->controller($this->docker(), $helper, new AgentAdminerFakeProxy([]));
        $response = $controller->proxy($this->get('/adminer/demo-db-1'), 'demo-db-1');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('helper_failed', $this->payload($response)['reason']);
    }

    // ==================================================================
    // Codec jar (header titipan)
    // ==================================================================

    public function testJarCodecRoundTripAndRejectsGarbage(): void
    {
        $jar = ['adminer_sid' => 'a b', 'adminer_key' => 'k', '__landing' => '/?server=db:3306'];
        $this->assertSame($jar, AgentAdminerController::decodeJar(AgentAdminerController::encodeJar($jar)));

        $this->assertSame([], AgentAdminerController::decodeJar(''));
        $this->assertSame([], AgentAdminerController::decodeJar('bukan-base64!!'));
        $this->assertSame([], AgentAdminerController::decodeJar(base64_encode('"bukan objek"')));
        $this->assertSame([], AgentAdminerController::decodeJar(base64_encode('{"a":{"nested":1}}')));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(DockerClient $docker, AdminerHelper $helper, AdminerProxy $proxy): AgentAdminerController
    {
        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $apps->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'local',
            'containers' => [['service_name' => 'db', 'container_name' => 'demo-db-1']],
        ]);

        return new AgentAdminerControllerTestable($apps, $docker, $helper, $proxy);
    }

    private function docker(): AgentAdminerFakeDocker
    {
        return new AgentAdminerFakeDocker();
    }

    private function helper(): AgentAdminerFakeHelper
    {
        return new AgentAdminerFakeHelper();
    }

    /**
     * @param array<string,string> $headers
     */
    private function get(string $path, array $headers = []): Request
    {
        return new Request($this->raw($path, 'GET', $headers, ''));
    }

    /**
     * @param array<string,string> $headers
     */
    private function rawPost(string $path, string $contentType, string $body, array $headers = []): Request
    {
        return new Request($this->raw($path, 'POST', ['Content-Type' => $contentType] + $headers, $body));
    }

    /**
     * Permintaan mentah dengan header kontrol dari control plane (prefix, proto,
     * for, jar) — `support\Request` tidak mengizinkan penulisan `$headers`.
     *
     * @param array<string,string> $headers
     */
    private function raw(string $path, string $method, array $headers, string $body): string
    {
        $lines = "{$method} {$path} HTTP/1.1\r\nHost: agent.test\r\nAccept: text/html\r\n"
            . "X-Forwarded-Prefix: /database/demo-db-1/adminer\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-For: 203.0.113.9\r\n";
        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }
        $lines .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;

        return $lines;
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string> */
    private function header(Response $response, string $name): array
    {
        return AgentAdminerController::decodeJar((string) ($response->getHeader($name) ?? ''));
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

/**
 * Controller agent dengan helper/proxy tiruan (seam uji) — produksi memakai
 * `AdminerHelper`/`AdminerProxy` nyata.
 */
final class AgentAdminerControllerTestable extends AgentAdminerController
{
    public function __construct(
        AppStore $apps,
        DockerClient $docker,
        private AdminerHelper $helperFake,
        private AdminerProxy $proxyFake,
    ) {
        parent::__construct($apps, $docker);
    }

    protected function helper(DockerClient $docker): AdminerHelper
    {
        return $this->helperFake;
    }

    /** @param array<string,mixed> $spec */
    protected function adminerProxy(array $spec): AdminerProxy
    {
        if ($this->proxyFake instanceof AgentAdminerFakeProxy) {
            $this->proxyFake->captureSpec($spec);
        }

        return $this->proxyFake;
    }
}
