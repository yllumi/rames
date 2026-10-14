<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `BackupController` multi-server F4 — mediator: target volume server agent ikut
 * terlihat (`volumeTargets()`/`agentVolumeRows()`) dan pekerjaannya diteruskan ke
 * worker dengan `server_id` yang benar (worker → `BackupDispatcher` → job agent),
 * sedangkan volume lokal memakai bentuk argv **lama** (regresi).
 *
 * Ditambah fail-fast: server agent yang belum melaporkan op (`capabilities`) ⇒
 * **422** tanpa menyentuh worker (tidak ada sukses palsu, tidak ada fallback
 * lokal).
 *
 * Seluruh uji memakai seam (tanpa Docker/jaringan/proses nyata).
 */
class BackupControllerAgentTargetsTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_backupagent_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $this->tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        SqliteFixture::apps($this->tmp . '/rames.sqlite', [[
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'srv-a',
            'status' => 'running',
            'owner_id' => 'u1',
            'members' => [],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    public function testAgentVolumeIsRoutedToTheWorkerWithTheServerId(): void
    {
        $controller = $this->controller($this->owner());

        $response = $controller->run($this->post(['volume' => 'demo_data']));
        $this->assertSame(200, $response->getStatusCode(), $response->rawBody());
        $this->assertSame(['run', 'demo_data', 'manual', 'srv-a'], $controller->spawned[0]);
    }

    public function testLocalVolumeKeepsTheLegacyWorkerArguments(): void
    {
        $controller = $this->controller($this->owner(), localOnly: true);

        $response = $controller->run($this->post(['volume' => 'demo_data']));
        $this->assertSame(200, $response->getStatusCode(), $response->rawBody());
        $this->assertSame(['run', 'demo_data', 'manual', 'local'], $controller->spawned[0]);
    }

    public function testRestoreOnAgentVolumeForwardsSnapshotAndServer(): void
    {
        $controller = $this->controller($this->owner());

        $response = $controller->restore($this->post([
            'volume' => 'demo_data',
            'snapshot' => 'abcdef12',
            'confirm' => 'demo_data',
        ]));

        $this->assertSame(200, $response->getStatusCode(), $response->rawBody());
        $this->assertSame(['restore', self::APP_ID, 'demo_data', 'abcdef12', 'srv-a'], $controller->spawned[0]);
    }

    public function testDbDumpOnAgentVolumeForwardsServerId(): void
    {
        $controller = $this->controller($this->owner());

        $response = $controller->dbDump($this->post(['volume' => 'demo_data']));
        $this->assertSame(200, $response->getStatusCode(), $response->rawBody());
        $this->assertSame(['db-dump', self::APP_ID, 'demo_data', 'srv-a'], $controller->spawned[0]);
    }

    public function testAmbiguousVolumeAcrossServersIsRejected(): void
    {
        $controller = $this->controller($this->owner(), ambiguous: true);

        $response = $controller->run($this->post(['volume' => 'demo_data']));
        $this->assertSame(422, $response->getStatusCode(), 'volume ganda antar-server wajib ditolak jelas');
        $this->assertSame([], $controller->spawned, 'tidak boleh menjalankan worker apa pun');
        $this->assertStringContainsString('srv-a', $response->rawBody());
    }

    public function testUnsupportedCapabilityIsRejectedWith422AndNoWorker(): void
    {
        $controller = $this->controller($this->owner(), capabilities: ['deploy']);

        $response = $controller->run($this->post(['volume' => 'demo_data']));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('backup_run', $response->rawBody());
        $this->assertSame([], $controller->spawned, 'op tak didukung = tidak ada sukses palsu');
    }

    public function testUnauthorizedUserIsDeniedWith404AndNoSideEffect(): void
    {
        $controller = $this->controller(['id' => 'u8', 'username' => 'stranger', 'role' => 'member']);

        try {
            $controller->run($this->post(['volume' => 'demo_data']));
            $this->fail('user tanpa hak harus ditolak (AppAccessDenied → 404)');
        } catch (\app\library\Auth\AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
        }

        $this->assertSame([], $controller->spawned, 'tanpa hak = tanpa efek samping');
    }

    public function testAgentVolumeRowsAreMergedForTheStatusPage(): void
    {
        $controller = $this->controller($this->owner());

        $targets = $controller->targets();
        $this->assertSame('srv-a', $targets[0]['server_id']);
        $this->assertSame('demo_data', $targets[0]['name']);

        $rows = $controller->agentRows();
        $this->assertSame('demo_data', $rows[0]['name']);
        $this->assertSame('srv-a', $rows[0]['server_id']);
        $this->assertSame('a1b2c3d4e5f60718', $rows[0]['app_id']);
        $this->assertSame('demo', $rows[0]['app_name']);
        $this->assertFalse($rows[0]['orphaned']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @return array<string,mixed> */
    private function owner(): array
    {
        return ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    }

    private function controller(
        array $user,
        bool $localOnly = false,
        bool $ambiguous = false,
        array $capabilities = ['deploy', 'backup_run', 'restore', 'db_dump'],
    ): BackupControllerAgentTargetsFake {
        return new BackupControllerAgentTargetsFake(
            $user,
            $localOnly,
            $ambiguous,
            $capabilities,
            new AppStore($this->tmp . '/rames.sqlite'),
            $this->tmp
        );
    }

    /** @param array<string,string> $body */
    private function post(array $body): Request
    {
        $encoded = http_build_query($body);

        return new Request(
            "POST /backups/run HTTP/1.1\r\nHost: rames.test\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($encoded)
            . "\r\n\r\n" . $encoded
        );
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

/**
 * Subclass seam: user login, target volume (lokal/agent), spawn worker, dan
 * resolver server disuntikkan sehingga mediator dapat diuji tanpa Docker,
 * jaringan, maupun proses nyata.
 */
final class BackupControllerAgentTargetsFake extends BackupController
{
    /** @var array<int,array<int,string>> */
    public array $spawned = [];

    private ?ServerResolver $resolver = null;

    public function __construct(
        private array $user,
        private bool $localOnly,
        private bool $ambiguous,
        private array $capabilities,
        private AppStore $apps,
        private string $tmp,
    ) {
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function spawnWorker(array $args): bool
    {
        $this->spawned[] = $args;

        return true;
    }

    /** Target volume (server lokal) — tanpa Engine nyata. */
    protected function volumeTargets(): array
    {
        $local = [[
            'name' => 'demo_data',
            'project' => 'demo',
            'app_id' => 'a1b2c3d4e5f60718',
            'app_name' => 'demo',
            'orphaned' => false,
            'server_id' => ServerStore::LOCAL_ID,
        ]];

        if ($this->localOnly) {
            return $local;
        }

        $agentRows = $this->agentVolumeTargets($this->apps->all());
        if ($this->ambiguous) {
            $other = $agentRows[0] ?? [];
            $other['server_id'] = 'srv-b';
            $other['app_id'] = 'other-app';

            return array_merge($local, $agentRows, [$other]);
        }

        return $agentRows;
    }

    /** Baris server agent tanpa jaringan (seam). */
    protected function agentVolumeTargets(array $apps = []): array
    {
        return [[
            'name' => 'demo_data',
            'project' => 'demo',
            'app_id' => 'a1b2c3d4e5f60718',
            'app_name' => 'demo',
            'orphaned' => false,
            'server_id' => 'srv-a',
        ]];
    }

    protected function agentVolumeRows(): array
    {
        $row = $this->agentVolumeTargets()[0];
        $row['strategy'] = 'snapshot';
        $row['container_state'] = 'running';
        $row['snapshots'] = 2;

        return [$row];
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return null;
    }

    /** Registry server tiruan (hanya untuk `assertAgentSupports`). */
    protected function serverResolver(): ServerResolver
    {
        if ($this->resolver === null) {
            $store = new ServerStore($this->tmp . '/servers-fake.sqlite');
            try {
                $store->create([
                    'id' => 'srv-a',
                    'name' => 'srv-a',
                    'kind' => 'agent',
                    'enabled' => true,
                    'domain' => 'agent-a.test',
                    'base_url' => 'https://agent-a.test',
                    'token' => 'tok-uji',
                    'tls_cert_pem' => BackupControllerAgentTargetsPem::selfSigned(),
                    'port_range' => ['start' => 30000, 'end' => 30999],
                ]);
            } catch (\Throwable) {
                // sudah ada (instance resolver kedua) — cukup.
            }
            $store->update('srv-a', function (array &$row): void {
                $row['capabilities'] = $this->capabilities;
            });
            ServerResolver::forgetMemo();
            $this->resolver = new ServerResolver($store);
        }

        return $this->resolver;
    }

    public function targets(): array
    {
        return $this->volumeTargets();
    }

    public function agentRows(): array
    {
        return $this->agentVolumeRows();
    }
}

/** Sertifikat self-signed kecil untuk baris server uji (validasi `ServerStore`). */
final class BackupControllerAgentTargetsPem
{
    public static function selfSigned(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            throw new RuntimeException('gagal membuat kunci uji');
        }
        $csr = openssl_csr_new(['commonName' => 'agent-a.test'], $resource, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $resource, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
