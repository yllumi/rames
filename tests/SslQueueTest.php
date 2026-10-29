<?php
declare(strict_types=1);

namespace Tests;

use app\library\SSL\SslQueue;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\StoreDbFiles;
use Webman\Config;

// Helper webman (`config()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test antrean aktivasi SSL ({@see SslQueue}, skema v5 `ssl_jobs`).
 *
 * Seluruh data di berkas .sqlite temp (`database/rames.sqlite` **tidak**
 * disentuh): instance `SqliteDatabase` disuntikkan ke antrean, dan config
 * (`deploy.ssl_queue_*`) diarahkan ke direktori temp yang dipulihkan di
 * `tearDown`.
 *
 * Yang dikunci:
 *  - enqueue memberi posisi/size; dedupe menjaga satu job aktif per domain;
 *  - `claimNext()` FIFO + menaikkan `attempts`, menandai `running` macet gagal;
 *  - `fail()` retry hanya untuk error transien, dengan backoff 30 lalu 120 dtk;
 *  - `complete()`, `prune()` retensi 7 hari, dan `decorate()` yang fail-safe;
 *  - `isRetryable()`: non-retryable menang atas retry, default false.
 */
class SslQueueTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';
    private SslQueue $queue;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        $this->tmp = sys_get_temp_dir() . '/rames-sslqueue-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';

        SqliteDatabase::reset();
        $this->useConfig();
        $this->queue = new SslQueue(new SqliteDatabase($this->file));
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        StoreDbFiles::remove($this->file);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Enqueue & dedupe
    // ==================================================================

    public function testEnqueueAppReturnsPositionAndSize(): void
    {
        $first = $this->queue->enqueueApp($this->app('app_a'), 'a.example.com', 'ssl', 'u1');
        $second = $this->queue->enqueueApp($this->app('app_b'), 'b.example.com', 'ssl', 'u2');

        $this->assertTrue($first['queued']);
        $this->assertSame(1, $first['position']);
        $this->assertSame(1, $first['size']);
        $this->assertSame('queued', $first['job']['status']);
        $this->assertSame('app', $first['job']['target_type']);
        $this->assertSame('app_a', $first['job']['app_id']);
        $this->assertSame('a.example.com', $first['job']['domain']);
        $this->assertSame('ssl', $first['job']['slot']);
        $this->assertSame('u1', $first['job']['actor_id']);
        $this->assertSame(0, (int) $first['job']['attempts']);
        $this->assertSame(3, (int) $first['job']['max_attempts']);

        $this->assertTrue($second['queued']);
        $this->assertSame(2, $second['size']);
        $this->assertSame(2, $this->queue->queueSize());
        // Urutan dalam detik yang sama ditentukan tie-break `id` (kontrak
        // `ORDER BY created_at, id`), jadi yang diuji: dua posisi berbeda 1..2.
        $this->assertEqualsCanonicalizing(
            [1, 2],
            [
                $this->queue->position((string) $first['job']['id']),
                $this->queue->position((string) $second['job']['id']),
            ]
        );
    }

    public function testEnqueueDeduplicatesTheSameDomain(): void
    {
        $app = $this->app('app_a');

        $first = $this->queue->enqueueApp($app, 'a.example.com', 'ssl');
        $second = $this->queue->enqueueApp($app, 'a.example.com', 'ssl');

        $this->assertTrue($first['queued']);
        $this->assertFalse($second['queued'], 'job aktif dengan dedupe sama dikembalikan apa adanya');
        $this->assertSame($first['job']['id'], $second['job']['id']);
        $this->assertSame(1, $second['position']);
        $this->assertSame(1, $second['size']);
        $this->assertSame(1, $this->queue->queueSize());

        // Slot berbeda = domain berbeda = job berbeda.
        $custom = $this->queue->enqueueApp($app, 'a.example.com', 'custom_ssl');
        $this->assertTrue($custom['queued']);
        $this->assertSame(2, $this->queue->queueSize());
    }

    public function testEnqueueParkedUsesItsOwnDedupeKey(): void
    {
        $result = $this->queue->enqueueParked(['id' => 'pd_aaaaaaaaaaaa', 'domain' => 'p.example.com']);

        $this->assertTrue($result['queued']);
        $this->assertSame('parked', $result['job']['target_type']);
        $this->assertSame('pd_aaaaaaaaaaaa', $result['job']['parked_id']);
        $this->assertNull($result['job']['slot']);
        $this->assertIsArray($this->queue->findActive('parked:pd_aaaaaaaaaaaa:p.example.com'));
        $this->assertNull($this->queue->findActive('app:pd_aaaaaaaaaaaa:p.example.com:ssl'));
    }

    // ==================================================================
    // Klaim & penyelesaian
    // ==================================================================

    public function testClaimNextIsFifoAndCountsAttempts(): void
    {
        $first = $this->queue->enqueueApp($this->app('app_a'), 'a.example.com', 'ssl');
        $this->queue->enqueueApp($this->app('app_b'), 'b.example.com', 'ssl');
        // Pastikan urutan waktu berbeda (detik) — kontrak FIFO `created_at, id`.
        $this->db()->pdo()->prepare('UPDATE "ssl_jobs" SET "created_at" = ? WHERE "id" = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 5), (string) $first['job']['id']]);

        $claimed = $this->queue->claimNext();
        $this->assertIsArray($claimed);
        $this->assertSame($first['job']['id'], $claimed['id'], 'job paling tua diklaim lebih dulu');
        $this->assertSame('running', $claimed['status']);
        $this->assertSame(1, (int) $claimed['attempts']);
        $this->assertNotNull($claimed['started_at']);
        $this->assertSame(1, $this->queue->position((string) $claimed['id']), 'job running = posisi 1');

        $second = $this->queue->claimNext();
        $this->assertIsArray($second);
        $this->assertNotSame($claimed['id'], $second['id']);

        $this->assertNull($this->queue->claimNext(), 'tidak ada job queued tersisa');
        $this->assertSame(2, $this->queue->queueSize(), 'dua job running tetap dihitung');
    }

    public function testCompleteMarksTheJobDone(): void
    {
        $job = $this->queue->enqueueApp($this->app('app_a'), 'a.example.com', 'ssl');
        $claimed = $this->queue->claimNext();
        $this->assertIsArray($claimed);
        $startedAt = $claimed['started_at'];

        $this->queue->complete((string) $job['job']['id']);

        $row = $this->row((string) $job['job']['id']);
        $this->assertSame('done', $row['status']);
        $this->assertNotNull($row['finished_at']);
        $this->assertSame($startedAt, $row['started_at'], 'started_at dipertahankan');
        $this->assertSame(0, $this->queue->queueSize());
        $this->assertNull($this->queue->position((string) $job['job']['id']), 'job selesai tanpa posisi');
    }

    public function testFailWithTransientErrorRetriesWithBackoff(): void
    {
        $job = $this->queue->enqueueApp($this->app('app_a'), 'a.example.com', 'ssl');
        $id = (string) $job['job']['id'];

        $claimed = $this->queue->claimNext();
        $this->assertIsArray($claimed);

        $outcome = $this->queue->fail($id, 'Another instance of certbot is already running', true);
        $this->assertSame('queued', $outcome['status']);
        $this->assertSame(1, $outcome['attempts']);
        $this->assertNotNull($outcome['available_at']);
        $this->assertSame(30, $this->secondsFromNow((string) $outcome['available_at']), 'backoff pertama 30 dtk');

        $row = $this->row($id);
        $this->assertNull($row['started_at'], 'started_at direset agar bisa diklaim lagi');
        $this->assertSame('Another instance of certbot is already running', $row['last_error']);

        // Job belum bisa diklaim sebelum backoff lewat.
        $this->assertNull($this->queue->claimNext());

        // Percobaan kedua → backoff 120 dtk.
        $this->makeAvailableNow($id);
        $second = $this->queue->claimNext();
        $this->assertIsArray($second);
        $this->assertSame(2, (int) $second['attempts']);

        $retry = $this->queue->fail($id, 'Timeout during connect', true);
        $this->assertSame('queued', $retry['status']);
        $this->assertSame(120, $this->secondsFromNow((string) $retry['available_at']), 'backoff kedua 120 dtk');

        // Percobaan ketiga = batas `max_attempts` → gagal permanen.
        $this->makeAvailableNow($id);
        $third = $this->queue->claimNext();
        $this->assertIsArray($third);
        $this->assertSame(3, (int) $third['attempts']);

        $final = $this->queue->fail($id, 'Timeout during connect', true);
        $this->assertSame('failed', $final['status'], 'percobaan habis ⇒ failed');
        $this->assertNull($final['available_at']);
        $this->assertNotNull($this->row($id)['finished_at']);
    }

    public function testFailWithPermanentErrorDoesNotRetry(): void
    {
        $job = $this->queue->enqueueApp($this->app('app_a'), 'a.example.com', 'ssl');
        $id = (string) $job['job']['id'];
        $this->queue->claimNext();

        $outcome = $this->queue->fail($id, 'too many certificates already issued for this exact set of domains', false);

        $this->assertSame('failed', $outcome['status']);
        $this->assertSame(0, $this->queue->queueSize());
        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('too many certificates', (string) $row['last_error']);
        $this->assertNotNull($row['started_at'], 'started_at tidak direset pada kegagalan final');
    }

    public function testStaleRunningJobIsMarkedFailed(): void
    {
        $this->queue->enqueueApp($this->app('app_a'), 'a.example.com', 'ssl');
        $this->queue->enqueueApp($this->app('app_b'), 'b.example.com', 'ssl');
        $stale = $this->queue->claimNext();
        $this->assertIsArray($stale);

        // Job `running` sejak lama (proses drainer mati di tengah jalan).
        $this->db()->pdo()->prepare('UPDATE "ssl_jobs" SET "started_at" = ? WHERE "id" = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 3600), (string) $stale['id']]);

        $claimed = $this->queue->claimNext(900);
        $this->assertIsArray($claimed);
        $this->assertNotSame($stale['id'], $claimed['id'], 'job macet tidak diklaim ulang');

        $row = $this->row((string) $stale['id']);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Job SSL macet > 15 menit.', $row['last_error']);
        $this->assertNotNull($row['finished_at']);
    }

    // ==================================================================
    // Dekorasi baris & retensi
    // ==================================================================

    public function testDecorateAddsQueuePositionOnlyToPendingRows(): void
    {
        $app = $this->app('app_pending');
        $pendingJob = $this->queue->enqueueApp($app, 'pending.example.com', 'ssl');
        $this->queue->enqueueApp($this->app('app_lain'), 'lain.example.com', 'ssl');
        $this->db()->pdo()->prepare('UPDATE "ssl_jobs" SET "created_at" = ? WHERE "id" = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 5), (string) $pendingJob['job']['id']]);

        $rows = $this->queue->decorate([
            ['kind' => 'app', 'app_id' => 'app_pending', 'domain' => 'pending.example.com', 'is_custom' => false, 'ssl_status' => 'pending'],
            ['kind' => 'app', 'app_id' => 'app_aktif', 'domain' => 'aktif.example.com', 'is_custom' => false, 'ssl_status' => 'active'],
            ['kind' => 'app', 'app_id' => 'app_tanpa_job', 'domain' => 'lain2.example.com', 'is_custom' => false, 'ssl_status' => 'pending'],
            ['kind' => 'parked', 'id' => 'pd_aaaaaaaaaaaa', 'domain' => 'p.example.com', 'ssl_status' => 'pending'],
        ]);

        $this->assertSame(1, $rows[0]['ssl_queue_position'], 'job paling tua = posisi 1');
        $this->assertSame(2, $rows[0]['ssl_queue_size']);
        $this->assertNull($rows[1]['ssl_queue_position'], 'baris non-pending tidak diberi posisi');
        $this->assertNull($rows[1]['ssl_queue_size']);
        $this->assertNull($rows[2]['ssl_queue_position'], 'pending tanpa job aktif = null');
        $this->assertNull($rows[3]['ssl_queue_position'], 'domain parkir tanpa job = null');
    }

    public function testDecorateIsFailSafeWhenTheStoreIsBroken(): void
    {
        $broken = new SslQueue(new SqliteDatabase('/dev/null/rames-ssl-uji/rames.sqlite'));

        $rows = $broken->decorate([
            ['kind' => 'app', 'app_id' => 'app_a', 'domain' => 'a.example.com', 'ssl_status' => 'pending'],
        ]);

        $this->assertNull($rows[0]['ssl_queue_position']);
        $this->assertNull($rows[0]['ssl_queue_size']);
    }

    public function testDecorateWithoutPendingRowsSkipsTheDatabase(): void
    {
        $broken = new SslQueue(new SqliteDatabase('/dev/null/rames-ssl-uji/rames.sqlite'));

        $rows = $broken->decorate([
            ['kind' => 'app', 'app_id' => 'app_a', 'domain' => 'a.example.com', 'ssl_status' => 'active'],
        ]);

        $this->assertNull($rows[0]['ssl_queue_position']);
    }

    public function testPruneRemovesOnlyOldFinishedJobs(): void
    {
        $old = $this->queue->enqueueApp($this->app('app_lama'), 'lama.example.com', 'ssl');
        $fresh = $this->queue->enqueueApp($this->app('app_baru'), 'baru.example.com', 'ssl');
        $this->queue->enqueueApp($this->app('app_antre'), 'antre.example.com', 'ssl');

        $this->db()->pdo()->prepare(
            'UPDATE "ssl_jobs" SET "status" = \'done\', "finished_at" = ? WHERE "id" = ?'
        )->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 8 * 86400), (string) $old['job']['id']]);
        $this->db()->pdo()->prepare(
            'UPDATE "ssl_jobs" SET "status" = \'failed\', "finished_at" = ? WHERE "id" = ?'
        )->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 60), (string) $fresh['job']['id']]);

        $this->assertSame(1, $this->queue->prune(7), 'hanya job 8 hari yang dibuang');

        $this->assertNull($this->row((string) $old['job']['id']));
        $this->assertIsArray($this->row((string) $fresh['job']['id']), 'job baru tetap ada');
        $this->assertSame(1, $this->queue->queueSize(), 'job queued tidak tersentuh retensi');
    }

    // ==================================================================
    // Klasifikasi error
    // ==================================================================

    public function testIsRetryableClassifiesErrors(): void
    {
        $this->assertTrue(SslQueue::isRetryable('Another instance of certbot is already running'));
        $this->assertTrue(SslQueue::isRetryable('certbot.lock sudah dipegang proses lain'));
        $this->assertTrue(SslQueue::isRetryable('Timeout during connect (likely firewall problem)'));
        $this->assertTrue(SslQueue::isRetryable('Could not resolve host: acme-v02.api.letsencrypt.org'));
        $this->assertTrue(SslQueue::isRetryable('curl error 28'));

        $this->assertFalse(SslQueue::isRetryable('too many certificates already issued'));
        $this->assertFalse(SslQueue::isRetryable('Rate limit exceeded'));
        $this->assertFalse(SslQueue::isRetryable('Invalid response from http://x/.well-known/acme-challenge/y: 404'));
        $this->assertFalse(SslQueue::isRetryable('Nxdomain lookup failed'));
        $this->assertFalse(SslQueue::isRetryable('ADMIN_EMAIL belum diisi'));
        $this->assertFalse(SslQueue::isRetryable('Pesan tanpa pola dikenal'));
        $this->assertFalse(SslQueue::isRetryable(''));

        // Non-retryable menang walau pesan memuat kata transien.
        $this->assertFalse(SslQueue::isRetryable('rate limit; timeout menunggu lock'));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @return array<string,mixed> */
    private function app(string $id): array
    {
        return ['id' => $id, 'server_id' => 'local'];
    }

    private function db(): SqliteDatabase
    {
        return new SqliteDatabase($this->file);
    }

    /** @return array<string,mixed>|null */
    private function row(string $id): ?array
    {
        $stmt = $this->db()->pdo()->prepare('SELECT * FROM "ssl_jobs" WHERE "id" = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function makeAvailableNow(string $id): void
    {
        $this->db()->pdo()
            ->prepare('UPDATE "ssl_jobs" SET "available_at" = ? WHERE "id" = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 1), $id]);
    }

    /** Selisih detik antara timestamp ISO dan sekarang (dibulatkan). */
    private function secondsFromNow(string $timestamp): int
    {
        return (int) round(strtotime($timestamp) - time());
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->file,
            'sqlite_dir' => $this->tmp,
            'ssl_queue_max_attempts' => 3,
            'ssl_queue_backoff' => [30, 120],
            'ssl_queue_prune_days' => 7,
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
