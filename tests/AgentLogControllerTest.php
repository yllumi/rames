<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentLogController;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`base_path()`, `json()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `DockerClient` tiruan untuk sisi agent: merekam panggilan & mengembalikan body
 * mentah tanpa daemon Docker (tanpa Guzzle/socket).
 *
 * Dideklarasikan di berkas uji ini supaya tetap dapat di-autoload saat dijalankan
 * dengan `--filter`.
 */
final class AgentLogFakeDocker extends DockerClient
{
    /** @var array<int,array{container:string,tail:int,timestamps:bool}> */
    public array $calls = [];

    public string $raw = '';

    public ?string $error = null;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada Engine nyata. */
    public function __construct()
    {
    }

    public function containerLogs(string $id, int $tail = 200, bool $timestamps = true): string
    {
        $this->calls[] = ['container' => $id, 'tail' => $tail, 'timestamps' => $timestamps];

        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }

        return $this->raw;
    }
}

/**
 * Endpoint log container **sisi agent** (`GET /apps/{id}/logs`, F3a).
 *
 * Kontrak yang dikunci:
 *  1. app dibaca dari proyeksi agent (`AppStore` agent) — app yang tidak ada ⇒
 *     404 JSON (API mesin), bukan 500/halaman HTML;
 *  2. nama container dari request divalidasi milik app ini sebelum menyentuh
 *     Docker Engine **server ini** (container tak dikenal ⇒ 404, Engine tidak
 *     dipanggil sama sekali);
 *  3. teks log dibersihkan dari framing multiplexed Engine (`ContainerLogs`) dan
 *     dipotong ke `AgentLogController::MAX_LOG_BYTES` (satu sumber dengan
 *     `AgentDeployer::MAX_LOG_BYTES` — plafon kontrak control plane ↔ agent);
 *  4. galat Engine ⇒ HTTP 502 JSON (diterjemahkan control plane menjadi pesan
 *     yang jelas), bukan 200 dengan teks kosong.
 *
 * Tanpa daemon Docker, tanpa jaringan, tanpa `apps/`/`runtime/` nyata (store
 * test memakai berkas temp).
 */
class AgentLogControllerTest extends TestCase
{
    private const APP_ID = 'app-agent-1';

    private string $tmp;

    private AppStore $apps;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agent-log-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        // Proyeksi agent: satu app dengan dua container (state eksekusi).
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
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1)/(3) log normal
    // ==================================================================

    public function testOwnedContainerLogsAreDemultiplexedAndReturned(): void
    {
        $docker = new AgentLogFakeDocker();
        $docker->raw = self::multiplexed("halo dari agent\n", "baris kedua\n");

        $response = $this->controller($docker)->index($this->get('?container=demo-web-1&tail=10'), self::APP_ID);
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame("halo dari agent\nbaris kedua\n", $body['data']['text']);
        $this->assertSame('demo-web-1', $body['data']['container']);
        $this->assertSame(10, $body['data']['tail']);
        $this->assertFalse($body['data']['truncated']);
        $this->assertSame([['container' => 'demo-web-1', 'tail' => 10, 'timestamps' => true]], $docker->calls);
    }

    public function testContainerDefaultsToPrimaryServiceAndTailIsClamped(): void
    {
        $docker = new AgentLogFakeDocker();

        $this->payload($this->controller($docker)->index($this->get('?tail=99999'), self::APP_ID));

        $this->assertSame('demo-web-1', $docker->calls[0]['container'], 'default = primary_service');
        $this->assertSame(2000, $docker->calls[0]['tail'], 'tail absurd dibatasi plafon aman');
    }

    public function testTimestampsCanBeDisabled(): void
    {
        $docker = new AgentLogFakeDocker();

        $this->payload($this->controller($docker)->index($this->get('?container=demo-db-1&timestamps=0'), self::APP_ID));

        $this->assertFalse($docker->calls[0]['timestamps']);
    }

    // ==================================================================
    // (2) validasi container/app
    // ==================================================================

    public function testUnknownContainerIsRejectedWithoutTouchingTheEngine(): void
    {
        $docker = new AgentLogFakeDocker();
        $response = $this->controller($docker)->index($this->get('?container=container-milik-app-lain'), self::APP_ID);
        $body = $this->payload($response);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('unknown_container', $body['reason']);
        $this->assertSame([], $docker->calls, 'Engine tidak boleh disentuh untuk container tak dikenal');
    }

    public function testMalformedContainerNameIsRejected(): void
    {
        $docker = new AgentLogFakeDocker();
        $response = $this->controller($docker)->index($this->get('?container=' . rawurlencode('../../etc/passwd')), self::APP_ID);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $docker->calls);
    }

    public function testUnknownAppIsNotFound(): void
    {
        $docker = new AgentLogFakeDocker();
        $response = $this->controller($docker)->index($this->get(''), 'app-tidak-ada');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $this->payload($response)['reason']);
    }

    // ==================================================================
    // (3)/(4) batas ukuran & galat Engine
    // ==================================================================

    public function testOversizedLogIsTruncatedKeepingTheNewestPart(): void
    {
        $docker = new AgentLogFakeDocker();
        $docker->raw = 'AWAL' . str_repeat('z', AgentLogController::MAX_LOG_BYTES + 100);

        $body = $this->payload($this->controller($docker)->index($this->get('?container=demo-web-1'), self::APP_ID));

        $this->assertTrue($body['data']['truncated']);
        $this->assertSame(AgentLogController::MAX_LOG_BYTES, strlen((string) $body['data']['text']));
        $this->assertStringNotContainsString('AWAL', (string) $body['data']['text'], 'yang dipotong adalah awal (ekor terbaru dipertahankan)');
    }

    public function testEngineFailureBecomesBadGatewayJson(): void
    {
        $docker = new AgentLogFakeDocker();
        $docker->error = 'Gagal terhubung ke Docker Engine: connection refused';

        $response = $this->controller($docker)->index($this->get('?container=demo-web-1'), self::APP_ID);
        $body = $this->payload($response);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('docker_failed', $body['reason']);
    }

    public function testMaxLogBytesIsSingleSourcedFromTheAgentContract(): void
    {
        $this->assertSame(
            AgentDeployer::MAX_LOG_BYTES,
            AgentLogController::MAX_LOG_BYTES,
            'plafon log = satu kontrak (bukan dua angka yang bisa menyimpang)'
        );
    }

    /** Mediator tipis: hanya seam uji, tanpa state lintas-request. */
    public function testControllerIsAStatelessMediator(): void
    {
        $reflection = new ReflectionClass(AgentLogController::class);

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

        $this->assertEqualsCanonicalizing(['__construct', 'index'], get_class_methods(AgentLogController::class));

        // Kontrak route sisi agent (didokumentasikan di kelas & config/route.php).
        $routes = (string) file_get_contents(dirname(__DIR__) . '/config/route.php');
        $this->assertStringContainsString("Route::get('/apps/{id}/logs'", $routes);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(DockerClient $docker): AgentLogController
    {
        return new AgentLogController($this->apps, $docker);
    }

    private function get(string $query): Request
    {
        return new Request(
            'GET /apps/' . self::APP_ID . '/logs' . $query . " HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n"
        );
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
            $out .= "\x01\x00\x00\x00" . pack('N', strlen($line)) . $line;
        }

        return $out;
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
