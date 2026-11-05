<?php
declare(strict_types=1);

namespace Tests;

use app\controller\server\ProvisionController;
use app\library\Provision\AnsibleRunner;
use app\library\Provision\ProvisionJobStore;
use app\library\Provision\ProvisionLog;
use app\library\Provision\ProvisionAgentKey;
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
            'POST /servers/provision/keypair' => fn (): Response => $controller->keypair(
                $this->post('/servers/provision/keypair', [], true)
            ),
            'GET /servers/provision/jobs' => fn (): Response => $controller->jobList(
                $this->get('/servers/provision/jobs', true)
            ),
            'POST /servers/provision/jobs/delete' => fn (): Response => $controller->deleteJobs(
                $this->post('/servers/provision/jobs/delete', ['job' => 'abc123'], true)
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
        // Status job ikut dirender supaya tidak hilang setelah refresh.
        $this->assertSame([], $controller->rendered['vars']['jobs'] ?? null);
        $this->assertArrayHasKey('active_job', $controller->rendered['vars']);
        // Kunci privat tidak pernah sampai ke view.
        $this->assertArrayNotHasKey('private_key', $controller->rendered['vars']['agent_key'] ?? []);
    }

    public function testAdminCanForgetOldHostKeyPinOnSubmit(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $controller->hostKeys = new ServerSshKeyStore($this->tmp . '/keys');

        mkdir($this->tmp . '/keys', 0700, true);
        file_put_contents(
            $this->tmp . '/keys/known_hosts',
            "10.0.0.5 ssh-ed25519 AAAA-lama\n10.0.0.9 ssh-ed25519 AAAA-lain\n"
        );

        // Kunci bersama harus ada agar validasi lolos.
        (new ProvisionAgentKey(null, $this->tmp . '/keys'))->ensure();

        $response = $controller->store($this->post('/servers/provision', array_merge(
            $this->payload(),
            ['reset_host_key' => '1'],
        ), true));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $controller->spawnCalls, 'job tetap dijalankan');
        $this->assertSame(
            ['10.0.0.9 ssh-ed25519 AAAA-lain'],
            array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($this->tmp . '/keys/known_hosts'))))),
            'pin host target dihapus, entri host lain dipertahankan'
        );
    }

    public function testAdminDeletesTerminalJobAndItsLog(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $store = new ProvisionJobStore(new SqliteDatabase($this->dbFile));

        $job = $store->create($this->payload());
        $store->markError((string) $job['id'], 'gagal di run');
        $log = new ProvisionLog($this->tmp . '/logs');
        $log->append((string) $job['id'], 'baris log');

        $response = $controller->deleteJobs($this->post(
            '/servers/provision/jobs/delete',
            ['job' => (string) $job['id']],
            true
        ));

        $this->assertSame(200, $response->getStatusCode());
        $decoded = $this->json($response);
        $this->assertSame(0, $decoded['code'] ?? null);
        $this->assertSame(1, $decoded['count'] ?? null);
        $this->assertNull($store->find((string) $job['id']), 'baris job terhapus');
        $this->assertFileDoesNotExist($log->path((string) $job['id']), 'log ikut terhapus');
    }

    public function testAdminCannotDeleteRunningJob(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $store = new ProvisionJobStore(new SqliteDatabase($this->dbFile));
        $job = $store->create($this->payload());

        $response = $controller->deleteJobs($this->post(
            '/servers/provision/jobs/delete',
            ['job' => (string) $job['id']],
            true
        ));

        $this->assertSame(409, $response->getStatusCode(), 'job aktif = konflik, bukan 500');
        $this->assertIsArray($store->find((string) $job['id']));
    }

    public function testAdminBulkPurgeScopesOnlyTouchTerminalJobs(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);
        $store = new ProvisionJobStore(new SqliteDatabase($this->dbFile));

        $done = $store->create($this->payload(['name' => 'agent-done']));
        $store->markDone((string) $done['id'], 'selesai');
        $failed = $store->create($this->payload(['name' => 'agent-failed']));
        $store->markError((string) $failed['id'], 'gagal');
        $running = $store->create($this->payload(['name' => 'agent-running']));

        $response = $controller->deleteJobs($this->post(
            '/servers/provision/jobs/delete',
            ['scope' => 'failed'],
            true
        ));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->json($response)['count'] ?? null);
        $this->assertIsArray($store->find((string) $done['id']), 'riwayat sukses tidak ikut');
        $this->assertIsArray($store->find((string) $running['id']), 'job aktif tidak ikut');

        $response = $controller->deleteJobs($this->post(
            '/servers/provision/jobs/delete',
            ['scope' => 'done'],
            true
        ));
        $this->assertSame(1, $this->json($response)['count'] ?? null, 'scope done menghapus riwayat sukses');
        $this->assertIsArray($store->find((string) $running['id']));

        // Scope asing ditolak 422.
        $response = $controller->deleteJobs($this->post(
            '/servers/provision/jobs/delete',
            ['scope' => 'semua'],
            true
        ));
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAdminJobListReturnsJobsWithoutSecrets(): void
    {
        $controller = $this->controller(['id' => 'u1', 'username' => 'admin', 'role' => 'admin']);

        // Kosong: tetap respons JSON sah (panel di `/servers` menyembunyikan diri).
        $response = $controller->jobList($this->get('/servers/provision/jobs', true));
        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->rawBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(0, $decoded['code'] ?? null);
        $this->assertSame([], $decoded['jobs'] ?? null);
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
            new ProvisionAgentKey($this->processRunner, $this->tmp . '/keys'),
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
            // Wajib selama firewall dikelola (bawaan true).
            'control_plane_ips' => '203.0.113.7',
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

    /** Seam: pin host key disimpan di direktori temp tes. */
    public ?ServerSshKeyStore $hostKeys = null;

    protected function hostKeyStore(): ServerSshKeyStore
    {
        return $this->hostKeys ?? parent::hostKeyStore();
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
