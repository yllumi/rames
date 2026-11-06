<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\AppBuildBlocked;
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

/**
 * Pipeline build ulang app — satu sumber perilaku dashboard (`/apps/{id}/rebuild`)
 * dan API (`/api/v1/apps/{slug}/build`).
 *
 * Kontrak: preflight dulu (app impor → busy → artefak), gerbang kredit pemanggil
 * dijalankan **sebelum** efek samping, status berubah ke `deploying/queued`,
 * worker di-spawn dengan mode `rebuild`, PID tercatat, dan kegagalan spawn
 * mengembalikan status `error` + melempar {@see AppBuildBlocked}.
 */
class AppBuildServiceTest extends TestCase
{
    private string $db = '';

    private string $root = '';

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->db = sys_get_temp_dir() . '/rames-appbuild-' . $suffix . '.sqlite';
        $this->root = sys_get_temp_dir() . '/rames-appbuild-' . $suffix;
        if (!mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            throw new RuntimeException('gagal membuat direktori temp: ' . $this->root);
        }
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
        @rmdir($this->root . '/demo');
        @rmdir($this->root);
    }

    public function testRebuildStartsWorkerAndRecordsPid(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store, 'demo');
        mkdir($this->root . '/demo');
        $spawned = [];
        $service = $this->service($store, $spawned);
        $guardCalls = 0;

        $result = $service->rebuild($app, function () use (&$guardCalls): void {
            $guardCalls++;
        }, 'actor-1');

        $this->assertSame('deploying', $result['status']);
        $this->assertSame('queued', $result['stage']);
        $this->assertSame(1, $guardCalls, 'gerbang kredit wajib dipanggil sekali sebelum spawn');
        $this->assertCount(1, $spawned);
        $this->assertSame($app['id'], $spawned[0][2]);
        $this->assertSame('rebuild', $spawned[0][3]);

        $fresh = $store->find($app['id']);
        $this->assertSame('deploying', $fresh['status']);
        $this->assertSame('queued', $fresh['stage']);
        $this->assertSame(4321, $fresh['worker_pid']);
        $this->assertSame('rebuild', $fresh['worker_mode']);
    }

    public function testBusyAppIsRejectedBeforeAnySideEffect(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store, 'demo');
        mkdir($this->root . '/demo');
        $store->update($app['id'], static function (array &$s): void {
            $s['status'] = 'deploying';
        });
        $spawned = [];
        $guardCalls = 0;

        try {
            $this->service($store, $spawned)->rebuild($store->find($app['id']) ?? [], function () use (&$guardCalls): void {
                $guardCalls++;
            });
            $this->fail('build ganda harus ditolak');
        } catch (AppBuildBlocked $e) {
            $this->assertTrue($e->busy());
            $this->assertSame(AppBuildService::BUSY_MESSAGE, $e->getMessage());
        }

        $this->assertSame(0, $guardCalls);
        $this->assertSame([], $spawned);
    }

    public function testMissingArtifactDirectoryIsRejected(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store, 'demo');
        $spawned = [];

        try {
            $this->service($store, $spawned)->rebuild($app);
            $this->fail('direktori app hilang harus ditolak');
        } catch (AppBuildBlocked $e) {
            $this->assertFalse($e->busy());
            $this->assertSame(AppBuildService::MISSING_DIR_MESSAGE, $e->getMessage());
        }

        $this->assertSame([], $spawned);
        $this->assertSame('running', $store->find($app['id'])['status']);
    }

    public function testImportedAppIsRejected(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store, 'demo', ['origin' => 'imported']);
        mkdir($this->root . '/demo');
        $spawned = [];

        $this->expectException(AppBuildBlocked::class);
        try {
            $this->service($store, $spawned)->rebuild($app);
        } finally {
            $this->assertSame([], $spawned);
        }
    }

    public function testGuardExceptionPreventsStateMutation(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store, 'demo');
        mkdir($this->root . '/demo');
        $spawned = [];

        try {
            $this->service($store, $spawned)->rebuild($app, static function (): void {
                throw new RuntimeException('kredit habis');
            });
            $this->fail('exception gerbang harus menggagalkan build');
        } catch (RuntimeException $e) {
            $this->assertSame('kredit habis', $e->getMessage());
        }

        $this->assertSame([], $spawned);
        $this->assertSame('running', $store->find($app['id'])['status']);
    }

    public function testSpawnFailureMarksAppAsError(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store, 'demo');
        mkdir($this->root . '/demo');
        $launcher = new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []): bool {
                $pid = 0;

                return false;
            },
            $this->root . '/cli/deploy.php',
            $this->root . '/logs'
        );
        $service = new AppBuildService(
            $store,
            new DeploySpawner($launcher, $store),
            $this->root,
            new ServerResolver(new ServerStore($this->db))
        );

        try {
            $service->rebuild($app);
            $this->fail('spawn gagal harus melempar penolakan');
        } catch (AppBuildBlocked $e) {
            $this->assertSame(AppBuildService::SPAWN_FAILED_MESSAGE, $e->getMessage());
        }

        $fresh = $store->find($app['id']);
        $this->assertSame('error', $fresh['status']);
        $this->assertNull($fresh['stage']);
        $this->assertSame('Gagal spawn worker rebuild.', $fresh['error']);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function app(AppStore $store, string $name, array $extra = []): array
    {
        return $store->create($extra + [
            'name' => $name,
            'owner_id' => 'u1',
            'status' => 'running',
            'compose_files' => ['docker-compose.yml'],
        ]);
    }

    /**
     * @param array<int,array<int,string>> $spawned
     */
    private function service(AppStore $store, array &$spawned): AppBuildService
    {
        $launcher = new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []) use (&$spawned): bool {
                $spawned[] = $argv;
                $pid = 4321;

                return true;
            },
            $this->root . '/cli/deploy.php',
            $this->root . '/logs'
        );

        return new AppBuildService(
            $store,
            new DeploySpawner($launcher, $store),
            $this->root,
            new ServerResolver(new ServerStore($this->db))
        );
    }
}
