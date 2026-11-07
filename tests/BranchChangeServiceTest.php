<?php
declare(strict_types=1);

namespace Tests;

use app\library\Branch\BranchChangeBlocked;
use app\library\Branch\BranchChangeService;
use app\library\Deploy\AppBuildService;
use app\library\Deploy\DeploySpawner;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

class BranchChangeServiceTest extends TestCase
{
    private string $db = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rames-branch-change-' . bin2hex(random_bytes(5));
        $this->db = $this->root . '.sqlite';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
        $this->removeTree($this->root);
    }

    public function testValidBranchQueuesWorkerWithoutChangingBranchMetadata(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        mkdir($this->root . '/apps/demo', 0777, true);
        $spawned = [];
        $service = $this->service($store, $spawned);
        $guardCalls = 0;

        $result = $service->change($app, 'release/2026.11', function () use (&$guardCalls): void {
            $guardCalls++;
        }, 'actor-7');

        self::assertSame('deploying', $result['status']);
        self::assertSame('queued', $result['stage']);
        self::assertSame(1, $guardCalls);
        self::assertCount(1, $spawned);
        self::assertSame($app['id'], $spawned[0][2]);
        self::assertSame(BranchChangeService::MODE, $spawned[0][3]);
        self::assertSame('release/2026.11', $spawned[0][4]);
        self::assertSame('actor-7', $spawned[0][5]);

        $fresh = $store->find($app['id']);
        self::assertSame('main', $fresh['branch'], 'metadata target branch hanya boleh ditulis worker setelah sukses');
        self::assertSame('deploying', $fresh['status']);
        self::assertSame(4321, $fresh['worker_pid']);
        self::assertSame(BranchChangeService::MODE, $fresh['worker_mode']);
    }

    public function testAgentAppDoesNotRequireLocalArtifactDirectoryAndStillQueuesSwitchMode(): void
    {
        $servers = new ServerStore($this->db);
        $servers->create([
            'id' => 'agent-1',
            'name' => 'agent-1',
            'kind' => 'agent',
            'enabled' => true,
        ]);
        $store = new AppStore($this->db);
        $app = $this->app($store, ['server_id' => 'agent-1']);
        $spawned = [];

        $result = $this->service($store, $spawned, $servers)->change($app, 'feature/topic');

        self::assertSame('deploying', $result['status']);
        self::assertCount(1, $spawned);
        self::assertSame(BranchChangeService::MODE, $spawned[0][3]);
        self::assertSame('feature/topic', $spawned[0][4]);
        self::assertSame('main', $store->find($app['id'])['branch']);
    }

    public function testInvalidBranchInputsAreRejectedBeforeGuardOrSpawn(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        mkdir($this->root . '/apps/demo', 0777, true);
        $spawned = [];
        $service = $this->service($store, $spawned);

        foreach (['', '--help', 'feature;touch', 'feature name', ['main']] as $branch) {
            $guardCalls = 0;
            try {
                $service->change($app, $branch, function () use (&$guardCalls): void {
                    $guardCalls++;
                });
                self::fail('input branch tidak valid harus ditolak');
            } catch (BranchChangeBlocked $e) {
                self::assertFalse($e->busy());
                self::assertSame('Nama branch tidak valid.', $e->getMessage());
            }
            self::assertSame(0, $guardCalls);
        }

        self::assertSame([], $spawned);
        self::assertSame('running', $store->find($app['id'])['status']);
    }

    public function testNonGitAndImportedAppsAreRejected(): void
    {
        $store = new AppStore($this->db);
        $compose = $this->app($store, ['source' => 'compose']);
        $imported = $this->app($store, ['name' => 'imported', 'origin' => 'imported']);
        mkdir($this->root . '/apps/demo', 0777, true);
        mkdir($this->root . '/apps/imported', 0777, true);
        $spawned = [];
        $service = $this->service($store, $spawned);

        try {
            $service->change($compose, 'release');
            self::fail('app compose tidak boleh mengganti branch');
        } catch (BranchChangeBlocked $e) {
            self::assertStringContainsString('hanya tersedia', $e->getMessage());
        }

        try {
            $service->change($imported, 'release');
            self::fail('app impor tidak boleh mengganti branch');
        } catch (BranchChangeBlocked $e) {
            self::assertStringContainsString('diimpor', $e->getMessage());
        }

        self::assertSame([], $spawned);
    }

    public function testAtomicBusyCheckRejectsStaleRequestWithoutSpawning(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        mkdir($this->root . '/apps/demo', 0777, true);
        $store->update($app['id'], static function (array &$state): void {
            $state['status'] = 'deploying';
        });
        $spawned = [];
        $guardCalls = 0;

        try {
            // `$app` is intentionally stale; the atomic store claim must win.
            $this->service($store, $spawned)->change($app, 'release', function () use (&$guardCalls): void {
                $guardCalls++;
            });
            self::fail('request kedua harus dianggap busy');
        } catch (BranchChangeBlocked $e) {
            self::assertTrue($e->busy());
            self::assertSame(BranchChangeService::BUSY_MESSAGE, $e->getMessage());
        }

        self::assertSame(1, $guardCalls);
        self::assertSame([], $spawned);
        self::assertSame('main', $store->find($app['id'])['branch']);
    }

    public function testBillingFailureLeavesAppStateUntouched(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        mkdir($this->root . '/apps/demo', 0777, true);
        $spawned = [];

        try {
            $this->service($store, $spawned)->change($app, 'release', static function (): void {
                throw new RuntimeException('kredit habis');
            });
            self::fail('billing guard harus menghentikan operasi');
        } catch (RuntimeException $e) {
            self::assertSame('kredit habis', $e->getMessage());
        }

        self::assertSame([], $spawned);
        self::assertSame('running', $store->find($app['id'])['status']);
        self::assertSame('main', $store->find($app['id'])['branch']);
    }

    public function testSpawnFailureMarksErrorButNeverClaimsTargetBranch(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        mkdir($this->root . '/apps/demo', 0777, true);
        $launcher = new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []): bool {
                $pid = 0;

                return false;
            },
            $this->root . '/cli/deploy.php',
            $this->root . '/logs'
        );
        $spawned = [];
        $service = $this->service(
            $store,
            $spawned,
            new ServerStore($this->db),
            new DeploySpawner($launcher, $store)
        );

        try {
            $service->change($app, 'release');
            self::fail('spawn gagal harus menolak operasi');
        } catch (BranchChangeBlocked $e) {
            self::assertSame(BranchChangeService::SPAWN_FAILED_MESSAGE, $e->getMessage());
        }

        $fresh = $store->find($app['id']);
        self::assertSame('error', $fresh['status']);
        self::assertSame('main', $fresh['branch']);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function app(AppStore $store, array $extra = []): array
    {
        $name = (string) ($extra['name'] ?? 'demo');

        return $store->create($extra + [
            'name' => $name,
            'owner_id' => 'u1',
            'source' => 'git',
            'repo_url' => 'https://example.test/team/demo.git',
            'branch' => 'main',
            'status' => 'running',
            'compose_files' => ['docker-compose.yml'],
        ]);
    }

    /**
     * @param array<int,array<int,string>> $spawned
     */
    private function service(
        AppStore $store,
        array &$spawned,
        ?ServerStore $servers = null,
        ?DeploySpawner $spawner = null
    ): BranchChangeService {
        $launcher = new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []) use (&$spawned): bool {
                $spawned[] = $argv;
                $pid = 4321;

                return true;
            },
            $this->root . '/cli/deploy.php',
            $this->root . '/logs'
        );

        return new BranchChangeService(
            $store,
            $spawner ?? new DeploySpawner($launcher, $store),
            new AppBuildService(
                $store,
                $spawner ?? new DeploySpawner($launcher, $store),
                $this->root . '/apps',
                new ServerResolver($servers ?? new ServerStore($this->db))
            )
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
