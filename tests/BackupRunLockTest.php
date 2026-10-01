<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupRunLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test BackupRunLock — flock lintas-instance pada run.lock (path temp; TIDAK
 * menyentuh runtime/backup/ nyata).
 */
class BackupRunLockTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/runlock_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function lockPath(): string
    {
        return $this->tmp . '/backup/run.lock';
    }

    public function testAcquireCreatesLockFileAndReleaseFreesIt(): void
    {
        $lock = new BackupRunLock($this->lockPath());
        $this->assertFalse($lock->isHeld());

        $lock->acquire();
        $this->assertTrue($lock->isHeld());
        $this->assertFileExists($this->lockPath());
        $this->assertStringContainsString((string) getmypid(), (string) file_get_contents($this->lockPath()));

        $lock->release();
        $this->assertFalse($lock->isHeld());

        // release() aman dipanggil berulang
        $lock->release();
        $this->assertFalse($lock->isHeld());
    }

    public function testSecondInstanceIsRejectedWithClearMessage(): void
    {
        $first = new BackupRunLock($this->lockPath());
        $first->acquire();

        $second = new BackupRunLock($this->lockPath());
        try {
            $second->acquire();
            $this->fail('acquire kedua seharusnya ditolak, bukan gagal senyap');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sedang berjalan', $e->getMessage());
        }
        $this->assertFalse($second->isHeld());

        $first->release();

        // setelah dilepas, instance lain boleh mengambil
        $second->acquire();
        $this->assertTrue($second->isHeld());
        $second->release();
    }

    public function testDoubleAcquireOnSameInstanceIsRejected(): void
    {
        $lock = new BackupRunLock($this->lockPath());
        $lock->acquire();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah dipegang');
        try {
            $lock->acquire();
        } finally {
            $lock->release();
        }
    }

    public function testWithLockReleasesEvenWhenWorkThrows(): void
    {
        $lock = new BackupRunLock($this->lockPath());

        $this->assertSame('ok', $lock->withLock(static fn (): string => 'ok'));
        $this->assertFalse($lock->isHeld());

        try {
            $lock->withLock(static function (): void {
                throw new RuntimeException('gagal di tengah run');
            });
            $this->fail('exception dari work harus diteruskan');
        } catch (RuntimeException $e) {
            $this->assertSame('gagal di tengah run', $e->getMessage());
        }
        $this->assertFalse($lock->isHeld(), 'lock wajib dilepas lewat finally');

        // lock bisa diambil lagi setelahnya
        $again = new BackupRunLock($this->lockPath());
        $again->acquire();
        $this->assertTrue($again->isHeld());
        $again->release();
    }
}
