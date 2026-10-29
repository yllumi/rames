<?php
declare(strict_types=1);

namespace Tests;

use app\library\Errors\ErrorRateLimiter;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see ErrorRateLimiter} — bucket `kv` per user per menit (UTC).
 *
 * Seluruh uji memakai berkas SQLite di direktori temp; `database/rames.sqlite`
 * tidak pernah disentuh. Waktu "menit" diuji lewat kunci `kv` yang disemai
 * manual (tidak ada seam jam di kontrak kelas), bukan dengan `sleep()`.
 */
class ErrorRateLimiterTest extends TestCase
{
    private string $tmp;

    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/errrate_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        foreach (glob($this->tmp . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->tmp);
    }

    private function limiter(?SqliteDatabase $db = null): ErrorRateLimiter
    {
        return new ErrorRateLimiter($db ?? new SqliteDatabase($this->file));
    }

    /** Kunci bucket `errors:rate:{user}:{YmdHi}` menit berjalan. */
    private static function bucketKey(string $userId, ?int $at = null): string
    {
        return ErrorRateLimiter::KEY_PREFIX . $userId . ':' . gmdate('YmdHi', $at ?? time());
    }

    private function kvValue(string $key): ?string
    {
        $stmt = (new SqliteDatabase($this->file))->pdo()
            ->prepare('SELECT "value" FROM "kv" WHERE "key" = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testAllowsFirstTwentyThenDeniesTwentyFirst(): void
    {
        $limiter = $this->limiter();

        for ($i = 1; $i <= 20; $i++) {
            $this->assertTrue($limiter->allow('user-1'), 'laporan ke-' . $i . ' masih boleh');
        }

        $this->assertFalse($limiter->allow('user-1'), 'laporan ke-21 ditolak');
        $this->assertSame('21', $this->kvValue(self::bucketKey('user-1')), 'counter tersimpan di kv');
    }

    public function testEachUserHasOwnBucket(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 20; $i++) {
            $limiter->allow('user-a');
        }

        $this->assertFalse($limiter->allow('user-a'));
        $this->assertTrue($limiter->allow('user-b'), 'bucket user lain tidak terpengaruh');
        $this->assertSame('1', $this->kvValue(self::bucketKey('user-b')));
    }

    public function testCounterIsReadFromCurrentMinuteBucketOnly(): void
    {
        // Bucket menit sebelumnya sudah penuh → tidak memblokir menit berjalan.
        $previous = time() - 60;
        (new SqliteDatabase($this->file))->pdo()
            ->prepare('INSERT INTO "kv" ("key", "value") VALUES (:key, :value)')
            ->execute([':key' => self::bucketKey('user-c', $previous), ':value' => '20']);

        $this->assertTrue($this->limiter()->allow('user-c', 20), 'bucket menit lalu diabaikan');
        $this->assertSame('1', $this->kvValue(self::bucketKey('user-c')));

        // Bucket menit berjalan penuh (disemai manual) → ditolak.
        (new SqliteDatabase($this->file))->pdo()
            ->prepare('INSERT INTO "kv" ("key", "value") VALUES (:key, :value)'
                . ' ON CONFLICT("key") DO UPDATE SET "value" = :value2')
            ->execute([':key' => self::bucketKey('user-d'), ':value' => '20', ':value2' => '20']);

        $this->assertFalse($this->limiter()->allow('user-d', 20));
    }

    public function testInvalidUserIdIsFailClosed(): void
    {
        $limiter = $this->limiter();

        foreach (['', 'user dengan spasi', 'drop;table', str_repeat('a', 129), "user\n"] as $invalid) {
            $this->assertFalse($limiter->allow($invalid), 'userId tidak aman harus ditolak');
        }

        $this->assertTrue($limiter->allow(str_repeat('a', 128)), 'batas 128 karakter masih sah');
        $this->assertTrue($limiter->allow('user.name_1:@-x'));
    }

    public function testDatabaseErrorIsFailOpen(): void
    {
        // Path berupa direktori: PDO tidak bisa membuka berkas → exception.
        $dir = $this->tmp . '/bukan-db';
        mkdir($dir);

        $this->assertTrue(
            $this->limiter(new SqliteDatabase($dir))->allow('user-1'),
            'galat DB tidak boleh menolak laporan error'
        );
    }

    public function testNonPositiveLimitDeniesEverything(): void
    {
        $this->assertFalse($this->limiter()->allow('user-1', 0));
        $this->assertFalse($this->limiter()->allow('user-1', -5));
    }

    public function testCleanupPurgesOldBucketsOncePerWindow(): void
    {
        $pdo = (new SqliteDatabase($this->file))->pdo();
        $stale = ErrorRateLimiter::KEY_PREFIX . 'user-lama:202001010000';
        $pdo->prepare('INSERT INTO "kv" ("key", "value") VALUES (:key, \'5\')')
            ->execute([':key' => $stale]);

        $limiter = $this->limiter();
        $this->assertTrue($limiter->allow('user-baru'));
        $this->assertNull($this->kvValue($stale), 'bucket > 2 menit dibersihkan');
        $this->assertNotNull($this->kvValue(ErrorRateLimiter::CLEANUP_KEY), 'penanda cleanup tersimpan');

        // Di dalam jendela 60 s, pembersihan tidak diulang (klaim atomic gagal).
        $pdo->prepare('INSERT INTO "kv" ("key", "value") VALUES (:key, \'5\')')
            ->execute([':key' => $stale]);
        $this->assertTrue($limiter->allow('user-baru'));
        $this->assertSame('5', $this->kvValue($stale), 'cleanup dibatasi 1× per jendela');
    }

    public function testKeyIsNotWrittenWhenUserIdInvalid(): void
    {
        $this->assertFalse($this->limiter()->allow('bad id'));
        $this->assertNull($this->kvValue('bad id'));
        $this->assertNull($this->kvValue(self::bucketKey('bad id')));
    }
}
