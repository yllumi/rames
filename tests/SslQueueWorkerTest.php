<?php
declare(strict_types=1);

namespace Tests;

use app\library\SSL\SslQueue;
use app\library\SSL\SslQueueWorker;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\StoreDbFiles;
use Webman\Config;

// Helper webman (`base_path()`, `config()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test drainer antrean SSL ({@see SslQueueWorker}).
 *
 * Certbot **tidak pernah** dijalankan: seam `$executor` menangkap array command
 * dan mengembalikan hasil palsu (`code`, `stdout`, `stderr`, `timedOut`).
 * Seluruh store (antrean, app, domain parkir) berada di berkas .sqlite temp.
 *
 * Yang dikunci:
 *  - target app memilih `cli/ssl.php <appId> <domain>` dan target parkir memilih
 *    `cli/parked-ssl.php <id>` (array argumen, tanpa shell);
 *  - sukses ⇒ job `done`; gagal transien ⇒ `retry` + target `pending` dengan
 *    pesan percobaan ulang; gagal permanen ⇒ job `failed` + target `failed`;
 *  - target hilang ⇒ job gagal permanen tanpa eksekusi;
 *  - timeout dianggap transien.
 */
class SslQueueWorkerTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';
    private SslQueue $queue;

    /** @var array<int,array<int,string>> command yang diminta executor */
    private array $commands = [];

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        $this->tmp = sys_get_temp_dir() . '/rames-sslqueue-worker-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';

        SqliteDatabase::reset();
        $this->useConfig();
        $this->queue = new SslQueue(new SqliteDatabase($this->file));
        $this->commands = [];
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        StoreDbFiles::remove($this->file);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Sukses & pemilihan command
    // ==================================================================

    public function testIdleWhenTheQueueIsEmpty(): void
    {
        $this->assertSame('idle', $this->worker(['code' => 0])->tick());
        $this->assertSame([], $this->commands, 'executor tidak dipanggil tanpa job');
    }

    public function testAppJobRunsTheSslCliAndMarksDone(): void
    {
        $app = (new AppStore($this->file))->create(['name' => 'app-ssl', 'status' => 'running']);
        $this->queue->enqueueApp($app, 'app-ssl.example.com', 'ssl', 'u1');

        $this->assertSame('done', $this->worker(['code' => 0])->tick());

        $this->assertSame(
            [[PHP_BINARY, base_path('cli/ssl.php'), (string) $app['id'], 'app-ssl.example.com']],
            $this->commands,
            'target app memakai cli/ssl.php dengan appId + domain'
        );

        $saved = (new AppStore($this->file))->find((string) $app['id']);
        $this->assertSame('pending', $saved['ssl_status'], 'status target ditandai pending sebelum eksekusi');
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertSame(SslQueueWorker::MESSAGE_RUNNING, $saved['ssl_message']);
        $this->assertSame(0, $this->queue->queueSize(), 'job selesai keluar dari antrean');
    }

    public function testParkedJobRunsTheParkedCli(): void
    {
        $entry = (new ParkedDomainStore($this->file))->create(['domain' => 'parkir.example.com', 'mode' => 'html']);
        $this->queue->enqueueParked($entry, 'adm');

        $this->assertSame('done', $this->worker(['code' => 0])->tick());

        $this->assertSame(
            [[PHP_BINARY, base_path('cli/parked-ssl.php'), (string) $entry['id']]],
            $this->commands,
            'target parkir memakai cli/parked-ssl.php dengan id entri'
        );

        $saved = (new ParkedDomainStore($this->file))->find((string) $entry['id']);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertSame(SslQueueWorker::MESSAGE_RUNNING, $saved['ssl_message']);
    }

    // ==================================================================
    // Kegagalan
    // ==================================================================

    public function testTransientFailureRetriesAndKeepsTheTargetPending(): void
    {
        $app = (new AppStore($this->file))->create(['name' => 'app-retry', 'status' => 'running']);
        $this->queue->enqueueApp($app, 'retry.example.com', 'ssl');

        $status = $this->worker([
            'code' => 1,
            'stdout' => '',
            'stderr' => 'Another instance of certbot is already running.',
        ])->tick();

        $this->assertSame('retry', $status);

        $saved = (new AppStore($this->file))->find((string) $app['id']);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertSame('Percobaan ulang 1/3 ...', $saved['ssl_message']);
        $this->assertNull($saved['ssl_error']);

        $job = $this->queue->findActive('app:' . $app['id'] . ':retry.example.com:ssl');
        $this->assertIsArray($job);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(1, (int) $job['attempts']);
        $this->assertStringContainsString('Another instance of certbot', (string) $job['last_error']);
    }

    public function testPermanentFailureMarksTheTargetFailed(): void
    {
        $app = (new AppStore($this->file))->create(['name' => 'app-gagal', 'status' => 'running']);
        $this->queue->enqueueApp($app, 'gagal.example.com', 'ssl');

        $status = $this->worker([
            'code' => 1,
            'stdout' => '',
            'stderr' => 'too many certificates already issued for this exact set of domains',
        ])->tick();

        $this->assertSame('failed', $status);

        $saved = (new AppStore($this->file))->find((string) $app['id']);
        $this->assertSame('failed', $saved['ssl_status']);
        $this->assertNull($saved['ssl_stage']);
        $this->assertNull($saved['ssl_message']);
        $this->assertStringContainsString('too many certificates', (string) $saved['ssl_error']);

        $this->assertSame(0, $this->queue->queueSize());
        $this->assertNull($this->queue->findActive('app:' . $app['id'] . ':gagal.example.com:ssl'));
    }

    public function testPermanentFailureOnParkedEntry(): void
    {
        $entry = (new ParkedDomainStore($this->file))->create(['domain' => 'parkir-gagal.example.com', 'mode' => 'html']);
        $this->queue->enqueueParked($entry);

        $status = $this->worker([
            'code' => 1,
            'stdout' => '',
            'stderr' => 'Invalid response from http://parkir-gagal.example.com/.well-known/acme-challenge/x: 404',
        ])->tick();

        $this->assertSame('failed', $status);

        $saved = (new ParkedDomainStore($this->file))->find((string) $entry['id']);
        $this->assertSame('failed', $saved['ssl_status']);
        $this->assertNull($saved['ssl_stage']);
        $this->assertStringContainsString('404', (string) $saved['ssl_error']);
    }

    public function testMissingAppTargetFailsWithoutRunningTheWorker(): void
    {
        $this->queue->enqueueApp(['id' => 'app-hilang'], 'hilang.example.com', 'ssl');

        $this->assertSame('failed', $this->worker(['code' => 0])->tick());
        $this->assertSame([], $this->commands, 'target hilang tidak boleh menjalankan certbot');

        $job = $this->jobByDomain('hilang.example.com');
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('tidak ditemukan', (string) $job['last_error']);
    }

    public function testMissingParkedTargetFailsWithoutRunningTheWorker(): void
    {
        $this->queue->enqueueParked(['id' => 'pd_000000000000', 'domain' => 'parkir-hilang.example.com']);

        $this->assertSame('failed', $this->worker(['code' => 0])->tick());
        $this->assertSame([], $this->commands);

        $job = $this->jobByDomain('parkir-hilang.example.com');
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('tidak ditemukan', (string) $job['last_error']);
    }

    public function testTimeoutIsTreatedAsTransient(): void
    {
        $app = (new AppStore($this->file))->create(['name' => 'app-timeout', 'status' => 'running']);
        $this->queue->enqueueApp($app, 'timeout.example.com', 'ssl');

        $status = $this->worker([
            'code' => -1,
            'stdout' => '',
            'stderr' => '',
            'timedOut' => true,
        ])->tick();

        $this->assertSame('retry', $status);

        $job = $this->queue->findActive('app:' . $app['id'] . ':timeout.example.com:ssl');
        $this->assertIsArray($job);
        $this->assertStringContainsString('timeout', strtolower((string) $job['last_error']));
    }

    /**
     * Exception di dalam executor (mis. proses gagal di-spawn) dicatat sebagai
     * kegagalan job, bukan exception yang lolos keluar `tick()`.
     */
    public function testExecutorExceptionDoesNotEscapeTick(): void
    {
        $app = (new AppStore($this->file))->create(['name' => 'app-ex', 'status' => 'running']);
        $this->queue->enqueueApp($app, 'exception.example.com', 'ssl');

        $worker = new SslQueueWorker($this->queue, static function (array $command): array {
            throw new \RuntimeException('Gagal menjalankan proses: ' . implode(' ', $command));
        });

        $status = $worker->tick();

        $this->assertContains($status, ['retry', 'failed']);
        $job = $this->jobByDomain('exception.example.com');
        $this->assertStringContainsString('Gagal menjalankan worker SSL', (string) $job['last_error']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Worker dengan executor palsu.
     *
     * @param array<string,mixed> $result
     */
    private function worker(array $result): SslQueueWorker
    {
        return new SslQueueWorker($this->queue, function (array $command) use ($result): array {
            $this->commands[] = $command;

            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function jobByDomain(string $domain): array
    {
        $stmt = (new SqliteDatabase($this->file))->pdo()
            ->prepare('SELECT * FROM "ssl_jobs" WHERE "domain" = ? ORDER BY "created_at", "id" LIMIT 1');
        $stmt->execute([$domain]);
        $row = $stmt->fetch();
        $this->assertIsArray($row);

        return $row;
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->file,
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'ssl_queue_timeout' => 60,
            'ssl_queue_stale_seconds' => 900,
            'ssl_queue_max_attempts' => 3,
            'ssl_queue_backoff' => [30, 120],
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

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
