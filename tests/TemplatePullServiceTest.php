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

    /** @var int|string|false disposisi SIGCHLD sebelum uji (dipulihkan di tearDown) */
    private int|string|false $prevSigchld = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-pullsvc-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->state = new TemplatePullState($this->dir);
        $this->worker = dirname(__DIR__) . '/cli/pull.php';
        $this->catalog = new TemplateCatalog(dirname(__DIR__) . '/templates');

        // Uji spawn sungguhan menyetel SIGCHLD=SIG_IGN lewat DetachedWorker —
        // dipulihkan di tearDown supaya tidak bocor ke test lain.
        if (function_exists('pcntl_signal_get_handler')) {
            $this->prevSigchld = pcntl_signal_get_handler(SIGCHLD);
        }
    }

    protected function tearDown(): void
    {
        if ($this->prevSigchld !== false && function_exists('pcntl_signal')) {
            @pcntl_signal(SIGCHLD, $this->prevSigchld);
        }

        $this->state->clear();
        GitTestFixture::removeDir($this->dir);
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
     * Jalur spawn kedua yang terkena bug env: `TemplatePullService` memanggil
     * `DetachedWorker::spawn($argv, $pid)` **tanpa env tambahan**, jadi sebelum
     * perbaikan anak lahir tanpa satu pun variabel ⇒ `docker pull` di worker
     * kehilangan `PATH` (dan jalur agent kehilangan `RAMES_DB_DIR`).
     *
     * Spawn **sungguhan** (fork+exec lewat `DetachedWorker`, bukan seam) tapi
     * worker-nya skrip palsu di temp yang mencatat env yang dilihatnya — tanpa
     * Docker, tanpa registry, tanpa menyentuh `runtime/` produksi (katalog
     * template hanya dibaca; status pull ada di temp).
     */
    public function testSpawnedWorkerInheritsEnvironmentOfThisProcess(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            $this->markTestSkipped('pcntl_fork/pcntl_exec tidak tersedia — DetachedWorker::spawn() tidak aktif.');
        }

        $out = $this->dir . '/fake-worker-env.json';
        $worker = $this->dir . '/fake-pull-worker.php';
        $marker = 'RAMES_TEST_PULLENV_' . bin2hex(random_bytes(4));
        file_put_contents($worker, self::fakeWorkerSource($out, $marker));

        putenv($marker . '=pull-inherit');
        try {
            // `null` = `DetachedWorker` sungguhan (bukan seam) — justru itu yang diuji.
            $service = new TemplatePullService($this->catalog, $this->state, null, $worker);
            $service->start('outline', 'budi');
        } finally {
            putenv($marker);
        }

        $this->assertGreaterThan(
            0,
            (int) $this->state->run()['pid'],
            'start() harus benar-benar mem-fork worker (pid diisi DetachedWorker)'
        );

        $seen = $this->awaitJson($out);
        $this->assertSame(
            'pull-inherit',
            $seen['env'][$marker],
            'env proses pemanggil harus diwariskan ke worker pull (bukan envp kosong)'
        );
        $this->assertNotSame('', (string) $seen['env']['PATH'], 'PATH wajib ikut — docker pull bergantung padanya');
        $this->assertSame(['outline', 'budi'], $seen['argv'], 'argv worker harus tetap [slug, actor]');
    }

    /**
     * Skrip worker palsu: tuliskan env yang **dilihatnya** (hanya PATH/HOME +
     * penanda uji — JANGAN pernah membuang seluruh env, isinya memuat rahasia)
     * dan argv-nya ke berkas JSON.
     */
    private static function fakeWorkerSource(string $out, string $marker): string
    {
        return sprintf(
            <<<'PHP'
            <?php
            declare(strict_types=1);
            $read = static fn (string $name) => getenv($name) === false ? null : (string) getenv($name);
            file_put_contents(%s, json_encode([
                'argv' => array_slice($argv, 1),
                'env' => [
                    'PATH' => $read('PATH'),
                    'HOME' => $read('HOME'),
                    %s => $read(%s),
                ],
            ]));
            exit(0);
            PHP,
            var_export($out, true),
            var_export($marker, true),
            var_export($marker, true),
        );
    }

    /**
     * Tunggu berkas JSON dari proses anak (stdio-nya ke `/dev/null` + auto-reap,
     * jadi berkas penanda yang jadi kanal bukti).
     *
     * @return array<string,mixed>
     */
    private function awaitJson(string $path): array
    {
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $decoded = json_decode((string) @file_get_contents($path), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            usleep(20_000);
        }

        $this->fail("worker palsu tidak menulis {$path} dalam 15 detik");
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
