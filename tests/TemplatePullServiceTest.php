<?php
declare(strict_types=1);

namespace Tests;

use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullService;
use app\library\Template\TemplatePullState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `TemplatePullService` — preflight, spawn worker, dan panel (§7.13).
 *
 * Spawner disuntik (tanpa fork/exec nyata) sehingga test tidak pernah
 * menjalankan Docker maupun menulis di `runtime/` produksi.
 */
class TemplatePullServiceTest extends TestCase
{
    /** Pid yang dijamin tidak ada (di atas `pid_max` Linux). */
    private const DEAD_PID = 2147483646;

    private string $dir;
    private TemplatePullState $state;
    private string $worker;
    private TemplateCatalog $catalog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-pullsvc-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->state = new TemplatePullState($this->dir);
        $this->worker = dirname(__DIR__) . '/cli/pull.php';
        $this->catalog = new TemplateCatalog(dirname(__DIR__) . '/templates');
    }

    protected function tearDown(): void
    {
        $this->state->clear();
        @rmdir($this->dir);
    }

    public function testPreflightAcceptsShippedTemplateWithAllImages(): void
    {
        $preflight = $this->service($this->succeedingSpawn())->preflight('outline');

        $this->assertTrue($preflight['ok']);
        $this->assertNull($preflight['error']);
        $this->assertSame(
            ['docker.getoutline.com/outlinewiki/outline:latest', 'postgres:18', 'redis:8-alpine'],
            $preflight['images']
        );
    }

    public function testPreflightRejectsUnknownSlug(): void
    {
        $preflight = $this->service($this->succeedingSpawn())->preflight('tidak-ada');

        $this->assertFalse($preflight['ok']);
        $this->assertStringContainsString('tidak ditemukan', (string) $preflight['error']);
        $this->assertSame([], $preflight['images']);
    }

    public function testPreflightRejectsEmptySlug(): void
    {
        $preflight = $this->service($this->succeedingSpawn())->preflight('   ');

        $this->assertFalse($preflight['ok']);
        $this->assertStringContainsString('tidak boleh kosong', (string) $preflight['error']);
    }

    public function testPreflightRejectsInvalidTemplate(): void
    {
        $root = $this->brokenCatalog();

        $service = new TemplatePullService(
            new TemplateCatalog($root),
            $this->state,
            $this->succeedingSpawn(),
            $this->worker
        );

        $preflight = $service->preflight('rusak');

        $this->assertFalse($preflight['ok']);
        $this->assertStringContainsString('tidak valid', (string) $preflight['error']);
    }

    public function testPreflightRejectsWhileAnotherRunIsAlive(): void
    {
        $this->state->startRun(['id' => 'run-lama', 'slug' => 'mysql', 'result' => null, 'pid' => getmypid()]);

        $preflight = $this->service($this->succeedingSpawn())->preflight('outline');

        $this->assertFalse($preflight['ok']);
        $this->assertStringContainsString('masih ada proses', strtolower((string) $preflight['error']));
    }

    public function testStartSpawnsWorkerWithExpectedArgvAndWritesStatus(): void
    {
        $calls = [];
        $spawn = function (array $argv, ?int &$pid = null) use (&$calls): bool {
            $calls[] = $argv;
            $pid = getmypid();

            return true;
        };

        $result = $this->service($spawn)->start('outline', 'budi');

        $this->assertSame(
            [[PHP_BINARY, $this->worker, 'outline', 'budi']],
            $calls,
            'worker wajib dipanggil sebagai [PHP_BINARY, cli/pull.php, slug, actor]'
        );

        $run = $this->state->run();
        $this->assertSame($result['id'], $run['id']);
        $this->assertSame('outline', $run['slug']);
        $this->assertSame('Outline', $run['title']);
        $this->assertSame('starting', $run['stage']);
        $this->assertSame('budi', $run['actor']);
        $this->assertSame($result['id'] . '.log', $run['log']);
        $this->assertSame(getmypid(), $run['pid']);
        $this->assertTrue($run['result'] === null);
        $this->assertSame(
            ['docker.getoutline.com/outlinewiki/outline:latest', 'postgres:18', 'redis:8-alpine'],
            $run['images']
        );
        $this->assertSame($run['images'], $result['images']);
        $this->assertTrue($this->service($spawn)->isWorkerRunning((string) $run['id']));
    }

    public function testStartRejectsUnknownSlugWithoutSpawning(): void
    {
        $calls = [];
        $spawn = function (array $argv, ?int &$pid = null) use (&$calls): bool {
            $calls[] = $argv;

            return true;
        };

        try {
            $this->service($spawn)->start('tidak-ada', 'budi');
            self::fail('start() seharusnya melempar untuk slug tak dikenal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak ditemukan', $e->getMessage());
        }

        $this->assertSame([], $calls, 'spawn TIDAK boleh dipanggil bila preflight gagal');
        $this->assertNull($this->state->run()['id']);
    }

    public function testFailedSpawnFinalizesRunAsErrorAndThrows(): void
    {
        $spawn = static function (array $argv, ?int &$pid = null): bool {
            return false; // mis. pcntl tidak tersedia
        };

        try {
            $this->service($spawn)->start('outline', 'budi');
            self::fail('start() seharusnya melempar bila spawn gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('spawn gagal', $e->getMessage());
        }

        $run = $this->state->run();
        $this->assertSame('error', $run['result']);
        $this->assertSame('finished', $run['stage']);
        $this->assertNotNull($run['finished_at']);
        $this->assertStringContainsString('spawn gagal', (string) $run['error']);
    }

    public function testThrowingSpawnIsReportedAsError(): void
    {
        $spawn = static function (array $argv, ?int &$pid = null): bool {
            throw new RuntimeException('fork dilarang');
        };

        try {
            $this->service($spawn)->start('outline', 'budi');
            self::fail('start() seharusnya melempar bila spawner gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fork dilarang', $e->getMessage());
        }

        $run = $this->state->run();
        $this->assertSame('error', $run['result']);
        $this->assertStringContainsString('fork dilarang', (string) $run['error']);
    }

    public function testPanelClosesRunAbandonedByDeadWorker(): void
    {
        $this->state->startRun([
            'id' => 'run-1',
            'slug' => 'outline',
            'title' => 'Outline',
            'stage' => 'pulling',
            'message' => 'Menarik image 2/3: postgres:18',
            'result' => null,
            'error' => null,
            'started_at' => date('c'),
            'finished_at' => null,
            'actor' => 'budi',
            'images' => ['redis:8-alpine'],
            'results' => [],
            'log' => 'run-1.log',
            'pid' => self::DEAD_PID,
        ]);
        $this->state->appendLog('run-1.log', "docker pull redis:8-alpine\n");

        $panel = $this->service($this->succeedingSpawn())->panel();

        $this->assertFalse($panel['running']);
        $this->assertSame('error', $panel['run']['result']);
        $this->assertSame('finished', $panel['run']['stage']);
        $this->assertStringContainsString('tanpa laporan', (string) $panel['run']['error']);
        $this->assertSame("docker pull redis:8-alpine\n", $panel['log_tail']);
        $this->assertSame(['run-1.log'], array_column($panel['logs'], 'name'));
        $this->assertTrue($panel['enabled']);
    }

    public function testPanelKeepsLiveRunRunning(): void
    {
        $this->state->startRun(['id' => 'run-1', 'slug' => 'mysql', 'result' => null, 'pid' => getmypid()]);

        $panel = $this->service($this->succeedingSpawn())->panel();

        $this->assertTrue($panel['running']);
        $this->assertSame('run-1', $panel['run']['id']);
        $this->assertNull($panel['run']['result']);
    }

    public function testPanelDisabledWhenWorkerScriptIsMissing(): void
    {
        $service = new TemplatePullService($this->catalog, $this->state, $this->succeedingSpawn(), $this->dir . '/tidak-ada.php');

        $panel = $service->panel();

        $this->assertFalse($panel['enabled']);
        $this->assertNull($panel['run']['id']);
        $this->assertSame('', $panel['log_tail']);
    }

    public function testIsWorkerRunningIsFalseForOtherRunId(): void
    {
        $this->state->startRun(['id' => 'run-1', 'slug' => 'mysql', 'result' => null, 'pid' => getmypid()]);

        $service = $this->service($this->succeedingSpawn());

        $this->assertTrue($service->isWorkerRunning('run-1'));
        $this->assertFalse($service->isWorkerRunning('run-lain'));
        $this->assertFalse($service->isWorkerRunning(''));
    }

    /**
     * @param callable $spawn
     */
    private function service(callable $spawn): TemplatePullService
    {
        return new TemplatePullService($this->catalog, $this->state, $spawn, $this->worker);
    }

    /**
     * Spawner sukses yang mengisi pid dengan pid test ini (hidup) dan merekam argv.
     *
     * @return callable(array<int,string>, ?int&):bool
     */
    private function succeedingSpawn(): callable
    {
        return static function (array $argv, ?int &$pid = null): bool {
            $pid = getmypid();

            return true;
        };
    }

    /**
     * Katalog temp berisi satu template rusak (`build:` ditolak mode compose).
     */
    private function brokenCatalog(): string
    {
        $root = $this->dir . '/templates';
        mkdir($root . '/rusak', 0777, true);
        file_put_contents($root . '/rusak/template.yml', "title: Rusak\n");
        file_put_contents($root . '/rusak/docker-compose.yml', "services:\n  web:\n    build: .\n");

        return $root;
    }
}
