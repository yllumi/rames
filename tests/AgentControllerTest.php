<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentController;
use app\controller\AgentJobController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Webman\Http\Response;

/**
 * Kontrak respons route diagnostik agent (mode `RAMES_MODE=agent`, plan §4.1).
 *
 * Yang dijaga di sini:
 *  - `GET /healthz` → 200 `{ok, service, mode, version}` — **tanpa** jejak
 *    token/path/host (route agent adalah satu-satunya permukaan server agent);
 *  - `GET /version` → `{agent_version, capabilities[], min_control_plane_version}`
 *    dengan `capabilities` = op yang benar-benar didukung agent (F1: mode
 *    `cli/deploy.php` lewat `AgentJobController::OPS` — bukan daftar terpisah);
 *  - versi hanya punya SATU sumber (`AgentController::VERSION`) sehingga
 *    `health.version` == `version.agent_version` — control plane membacanya
 *    sebagai `servers.agent_version`;
 *  - controller mediator tipis: tanpa konstruktor (tidak ada `session()`/
 *    `request()` di konstruktor) dan tanpa state properti lintas-request.
 *
 * Murni data — tanpa I/O, session, Engine API, atau berkas runtime.
 */
class AgentControllerTest extends TestCase
{
    private const AGENT_TOKEN_FIXTURE = 'agt_fixture_token_do_not_leak';

    public function testHealthContract(): void
    {
        [$status, $headers, $body, $raw] = $this->call('health');

        $this->assertSame(200, $status);
        $this->assertSame('application/json', $headers['Content-Type'] ?? null);
        $this->assertEqualsCanonicalizing(['ok', 'service', 'mode', 'version'], array_keys($body));
        $this->assertTrue($body['ok']);
        $this->assertSame('rames-agent', $body['service']);
        $this->assertSame('agent', $body['mode']);
        $this->assertSame(AgentController::VERSION, $body['version']);
        $this->assertNotSame('', $body['version']);
        $this->assertStringNotContainsString("\n", $raw, 'respons satu baris (tanpa data tambahan)');
    }

    /**
     * Respons kesehatan adalah satu-satunya endpoint yang (nanti) publik di
     * server agent — ia tidak boleh membocorkan apa pun selain identitas versi.
     */
    public function testHealthNeverLeaksTokenOrPaths(): void
    {
        $previous = getenv('AGENT_TOKEN');
        putenv('AGENT_TOKEN=' . self::AGENT_TOKEN_FIXTURE);
        try {
            [, , $body, $raw] = $this->call('health');
        } finally {
            $previous === false ? putenv('AGENT_TOKEN') : putenv('AGENT_TOKEN=' . $previous);
        }

        $this->assertStringNotContainsString(self::AGENT_TOKEN_FIXTURE, $raw, 'token bocor di body');
        $this->assertStringNotContainsString(dirname(__DIR__), $raw, 'path instalasi bocor di body');
        $this->assertStringNotContainsString('Bearer', $raw);

        foreach (['token', 'agent_token', 'path', 'paths', 'host', 'hostname', 'bind', 'env', 'apps', 'root'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }

        // Shape persis: tambahan field apa pun = kegagalan kontrak (dan berpotensi bocor).
        $this->assertEqualsCanonicalizing(['ok', 'service', 'mode', 'version'], array_keys($body));
    }

    public function testVersionContract(): void
    {
        [$status, $headers, $body, $raw] = $this->call('version');

        $this->assertSame(200, $status);
        $this->assertSame('application/json', $headers['Content-Type'] ?? null);
        $this->assertEqualsCanonicalizing(
            ['agent_version', 'capabilities', 'min_control_plane_version'],
            array_keys($body)
        );
        $this->assertSame(AgentController::VERSION, $body['agent_version']);
        $this->assertSame(AgentController::MIN_CONTROL_PLANE_VERSION, $body['min_control_plane_version']);
        // F1: capabilities = op yang benar-benar didukung agent — satu sumber
        // dengan validasi `POST /jobs` (jangan disalin ke sini).
        $this->assertSame(AgentJobController::OPS, $body['capabilities']);
        $this->assertNotSame([], $body['capabilities']);
        $this->assertSame(array_values($body['capabilities']), $body['capabilities'], 'capabilities harus array JSON, bukan objek');
        $this->assertStringNotContainsString('"capabilities":{', $raw);
    }

    /** Satu sumber versi: health() & version() tidak boleh menyimpang. */
    public function testHealthAndVersionReportSameVersion(): void
    {
        [, , $health] = $this->call('health');
        [, , $version] = $this->call('version');

        $this->assertSame($version['agent_version'], $health['version']);
    }

    /** Mediator tipis: tanpa state properti & tanpa konstruktor (larangan peran). */
    public function testControllerIsStatelessMediator(): void
    {
        $reflection = new ReflectionClass(AgentController::class);

        $this->assertSame([], $reflection->getProperties(), 'controller tidak boleh punya state properti');
        $this->assertNull($reflection->getConstructor(), 'tidak boleh ada konstruktor');
        $this->assertEqualsCanonicalizing(['health', 'version'], get_class_methods(AgentController::class));
    }

    /**
     * Panggil aksi controller dan bongkar responsnya.
     *
     * @return array{0:int,1:array<string,string>,2:array<string,mixed>,3:string}
     */
    private function call(string $action): array
    {
        $response = (new AgentController())->{$action}();
        $this->assertInstanceOf(Response::class, $response);

        $raw = $response->rawBody();
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return [$response->getStatusCode(), $response->getHeaders(), $body, $raw];
    }
}
