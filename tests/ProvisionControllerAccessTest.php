<?php
declare(strict_types=1);

namespace Tests;

use app\controller\server\ProvisionController;
use app\library\Provision\AnsibleRunner;
use app\library\Provision\ProvisionJobStore;
use app\library\Provision\ProvisionLog;
use app\library\Provision\ServerSshKeyStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/**
 * Otorisasi {@see ProvisionController} (provisioning Ansible, admin-only).
 *
 * Non-admin harus mendapat **404** (bukan 403) di SETIAP action — keberadaan
 * route provisioning tidak boleh bocor — dan penolakan terjadi **sebelum efek
 * samping apa pun**: tidak ada baris job dibuat (berkas SQLite temp tidak
 * pernah dibuat), tidak ada kunci ditulis, tidak ada worker di-spawn, tidak ada
 * view dirender.
 *
 * Tanpa HTTP nyata, tanpa Docker, tanpa jaringan: store/log/kunci di direktori
 * temp dan `spawnWorker()` diganti penghitung. `database/` & `runtime/` nyata
 * tidak disentuh.
 */
class ProvisionControllerAccessTest extends TestCase
{
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];

    private string $tmp = '';
    private string $dbFile = '';
    private CountingProvisionRunner $processRunner;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/rames-provision-ctl-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/playbooks', 0775, true);
        $this->dbFile = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        self::removeTree($this->tmp);
    }

    public function testMemberGets404OnEveryActionWithoutSideEffects(): void
    {
        $controller = $this->controller(self::MEMBER);

        $requests = [
            'GET /servers/provision' => fn (): Response => $controller->form($this->get('/servers/provision')),
            'POST /servers/provision' => fn (): Response => $controller->store(
                $this->post('/servers/provision', $this->payload(), true)
            ),
            'GET /servers/provision/status' => fn (): Response => $controller->status(
                $this->get('/servers/provision/status?job=abc123&offset=0', true)
            ),
            'POST /servers/provision/cancel' => fn (): Response => $controller->cancel(
                $this->post('/servers/provision/cancel', ['job' => 'abc123'], true)
            ),
        ];

        $allowed = [];
        foreach ($requests as $label => $call) {
            $status = $call()->getStatusCode();
            if ($status !== 404) {
                $allowed[] = $label . ' ⇒ ' . $status;
            }
        }

        $this->assertSame([], $allowed, 'Route berikut tidak menolak member dengan 404: ' . implode(', ', $allowed));
        $this->assertNull($controller->rendered, 'Member tidak boleh sampai merender view provisioning');
        $this->assertSame(0, $controller->spawnCalls, 'Member tidak boleh memicu spawn worker');
        $this->assertSame(0, $this->processRunner->calls, 'Member tidak boleh memanggil runner (cancel/docker)');
        $this->assertDirectoryDoesNotExist($this->tmp . '/keys/servers', 'Member tidak boleh menulis kunci SSH');

        // Berkas SQLite dibuat oleh konstruktor `ServerStore` di test ini, jadi
        // yang dibuktikan: TIDAK ADA baris job baru yang ditulis controller.
        $pdo = (new SqliteDatabase($this->dbFile))->pdo();
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM "provision_jobs"')->fetchColumn(),
            'Member tidak boleh membuat job provisioning');
    }

    public function testAdminFormReceivesDefaultsPayload(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $response = $controller->form($this->get('/servers/provision'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('server/provision', $controller->rendered['template'] ?? null);

        $defaults = $controller->rendered['vars']['defaults'] ?? [];
        $this->assertSame(8443, $defaults['agent_port'] ?? null);
        $this->assertSame('0.0.0.0:8443', $defaults['bind'] ?? null);
        $this->assertSame(22, $defaults['ssh_port'] ?? null);
        $this->assertSame(['provision-agent', 'verify-agent'], $defaults['playbooks'] ?? null);
        $this->assertArrayNotHasKey('private_key', $defaults, 'Defaults tidak boleh memuat rahasia');
        $this->assertSame([], $controller->rendered['vars']['errors'] ?? null);
    }

    public function testAdminStatusForUnknownJobIs404Json(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $response = $controller->status($this->get('/servers/provision/status?job=tidakada&offset=0', true));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(1, $this->json($response)['code'] ?? null);
        $this->assertArrayNotHasKey('log', $this->json($response), 'Respons 404 bukan payload status');
    }

    public function testAdminCancelWithInvalidIdIs422(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $response = $controller->cancel($this->post('/servers/provision/cancel', ['job' => '../etc/passwd'], true));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $this->json($response)['code'] ?? null);
        $this->assertSame(0, $this->processRunner->calls);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?array $user): FakeProvisionController
    {
        $this->processRunner = new CountingProvisionRunner();

        $controller = new FakeProvisionController(
            new ProvisionJobStore(new SqliteDatabase($this->dbFile)),
            new ServerStore($this->dbFile),
            new ServerSshKeyStore($this->tmp . '/keys'),
            new ProvisionLog($this->tmp . '/logs'),
            new AnsibleRunner(
                $this->processRunner,
                $this->tmp . '/playbooks',
                $this->tmp . '/work',
                $this->tmp . '/keys',
                $this->tmp . '/keys/known_hosts'
            ),
        );
        $controller->user = $user;

        return $controller;
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'vps-jakarta',
            'host' => '10.0.0.5',
            'ssh_user' => 'root',
            'ssh_port' => '22',
            'domain' => 'vps.contoh.id',
            'email' => 'ops@contoh.id',
            'port_range_start' => '31000',
            'port_range_end' => '31999',
            'agent_port' => '8443',
            'playbook' => 'provision-agent',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----",
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->rawBody(), true);
        $this->assertIsArray($decoded, 'Respons harus JSON: ' . $response->rawBody());

        return $decoded;
    }

    /** @param array<string,string> $fields */
    private function post(string $path, array $fields, bool $json = false): Request
    {
        $body = http_build_query($fields);
        $headers = "Host: localhost\r\n" . ($json ? "Accept: application/json\r\n" : '')
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";

        return new Request("POST {$path} HTTP/1.1\r\n{$headers}\r\n{$body}");
    }

    private function get(string $path, bool $json = false): Request
    {
        $headers = "Host: localhost\r\n" . ($json ? "Accept: application/json\r\n" : '');

        return new Request("GET {$path} HTTP/1.1\r\n{$headers}\r\n");
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

/**
 * Subclass seam: user login, render view, spawn worker, dan pembatalan worker
 * disuntik tanpa session/fork/docker.
 */
class FakeProvisionController extends ProvisionController
{
    public ?array $user = null;

    /** @var array{template:string,vars:array}|null */
    public ?array $rendered = null;

    public int $spawnCalls = 0;

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    /** @param array<string,mixed> $vars */
    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered');
    }

    /** @param array<int,string> $argv @param array<string,string> $env */
    protected function spawnWorker(array $argv, ?int &$pid = null, array $env = []): bool
    {
        $this->spawnCalls++;

        return true;
    }
}

/**
 * Runner penghitung: mencatat setiap pemanggilan proses (mis. `docker ps` dari
 * `AnsibleRunner::cancel()`) tanpa menjalankan apa pun.
 */
class CountingProvisionRunner extends ProcessRunner
{
    public int $calls = 0;

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->calls++;

        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}
