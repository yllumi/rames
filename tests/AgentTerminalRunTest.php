<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentTerminalController;
use app\library\Agent\AgentAppProjection;
use app\library\Docker\DockerExec;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

// Helper webman (`json()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/** `AgentTerminalController` dengan seam `dockerExec()` (tanpa Docker nyata). */
final class AgentTerminalRunTestController extends AgentTerminalController
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
 * `run` (perintah satu-kali non-interaktif) untuk app **server agent** (rencana
 * multi-server F4 sisa #4) — kontrak yang dikunci:
 *  1. jalur agent memakai mekanisme yang **sama** dengan jalur lokal
 *     (`DockerExec::runCommand()` di server itu: non-interaktif, timeout,
 *     stdout/stderr/exit code dikembalikan) — bukan sesi terminal interaktif;
 *  2. app dibaca dari proyeksi server itu (app tak dikenal ⇒ 404), container
 *     divalidasi milik app sebelum eksekusi (asing ⇒ 400 tanpa efek samping);
 *  3. jalur lokal (control plane) tetap seperti semula: `DockerExec` lokal
 *     dipakai, tidak ada HTTP ke agent (regresi eksplisit).
 *
 * Semua uji tanpa Docker/jaringan; `DockerExec` diganti fake scripted.
 */
class AgentTerminalRunTest extends TestCase
{
    private const APP_ID = 'app-agent-run';

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-terminal-run-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) sisi agent: jalankan DI server itu, hasil dikembalikan
    // ==================================================================

    public function testAgentRunsTheOneShotCommandInItsOwnContainer(): void
    {
        $exec = new FakeScriptedDockerExec(static fn (string $command): array => [
            'code' => 3,
            'stdout' => "hasil: {$command}\n",
            'stderr' => 'ada peringatan',
        ]);
        $controller = $this->controller($exec);

        [$status, $body] = $this->decode($controller->runSession($this->request('POST', '/terminal/run', [
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'command' => 'uptime',
            'timeout' => 120,
            'user' => 'www-data',
        ])));

        $this->assertSame(200, $status);
        $this->assertSame(0, (int) $body['code']);
        $this->assertSame(3, (int) $body['data']['code']);
        $this->assertSame("hasil: uptime\n", (string) $body['data']['stdout']);
        $this->assertSame('ada peringatan', (string) $body['data']['stderr']);
        $this->assertFalse((bool) $body['data']['timedOut']);
        $this->assertSame(
            [['container' => 'demo-web-1', 'command' => 'uptime', 'timeout' => 120, 'user' => 'www-data']],
            $exec->calls,
            'perintah dijalankan lewat `DockerExec::runCommand()` di server ini (non-interaktif)'
        );
    }

    public function testAgentAcceptsOperateDefaultsUserToRoot(): void
    {
        $exec = new FakeScriptedDockerExec(static fn (): array => ['stdout' => 'ok']);
        $controller = $this->controller($exec);

        $this->decode($controller->runSession($this->request('POST', '/terminal/run', [
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'command' => 'whoami',
        ])));

        $this->assertSame('root', $exec->calls[0]['user'], 'kebijakan user sama dengan jalur lokal');
    }

    public function testAgentRejectsUnknownAppForeignContainerAndEmptyCommand(): void
    {
        $exec = new FakeScriptedDockerExec(static fn (): array => ['stdout' => 'ok']);
        $controller = $this->controller($exec);

        // App tidak ada di proyeksi agent ⇒ 404 (bukan 403/500).
        [$status] = $this->decode($controller->runSession($this->request('POST', '/terminal/run', [
            'app_id' => 'app-tidak-ada',
            'container' => 'demo-web-1',
            'command' => 'ls',
        ])));
        $this->assertSame(404, $status);

        // Container asing ⇒ `{code:400}` (konvensi endpoint terminal: status
        // domain di body, sama seperti jalur lokal `open()`) tanpa efek samping.
        [, $foreign] = $this->decode($controller->runSession($this->request('POST', '/terminal/run', [
            'app_id' => self::APP_ID,
            'container' => 'container-lain',
            'command' => 'ls',
        ])));
        $this->assertSame(400, (int) $foreign['code']);

        // Perintah kosong ⇒ `{code:400}`.
        [, $empty] = $this->decode($controller->runSession($this->request('POST', '/terminal/run', [
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'command' => '   ',
        ])));
        $this->assertSame(400, (int) $empty['code']);

        $this->assertSame([], $exec->calls, 'tidak boleh ada eksekusi untuk permintaan tak sah');
    }

    // ==================================================================
    // (2) regresi: jalur lokal tidak berubah (tanpa HTTP ke agent)
    // ==================================================================

    public function testLocalPathStillUsesTheLocalDockerExec(): void
    {
        $exec = new FakeScriptedDockerExec(static fn (): array => ['code' => 0, 'stdout' => "lokal\n"]);
        $controller = new FakeTerminalController($exec);

        [$status, $body] = $this->decode($controller->run(
            $this->request('POST', '/api/apps/x/terminal/run', [
                'container' => 'demo-web-1',
                'command' => 'uptime',
            ]),
            'app-1'
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, (int) $body['code']);
        $this->assertSame("lokal\n", (string) $body['data']['stdout']);
        $this->assertCount(1, $exec->calls, 'perintah lokal tetap dijalankan DockerExec host ini');
        $this->assertCount(1, $controller->audits, 'audit jalur lokal tetap ditulis');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(DockerExec $exec): AgentTerminalRunTestController
    {
        $projection = new AgentAppProjection($this->tmp . '/frames.sqlite');
        $projection->upsert([
            'id' => self::APP_ID,
            'name' => 'demo',
            'status' => 'running',
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'demo-web-1'],
                ['service_name' => 'db', 'container_name' => 'demo-db-1'],
            ],
        ]);

        return new AgentTerminalRunTestController(new AppStore($projection->path()), $exec);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function request(string $method, string $path, array $body): Request
    {
        $raw = (string) json_encode($body);

        return new Request(
            "{$method} {$path} HTTP/1.1\r\nHost: agent.test\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($raw) . "\r\n\r\n" . $raw
        );
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function decode(Response $response): array
    {
        $raw = $response->rawBody();
        $body = $raw === '' ? [] : (json_decode($raw, true) ?? []);

        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}
