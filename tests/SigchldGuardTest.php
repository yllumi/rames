<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use app\library\Support\SigchldGuard;
use PHPUnit\Framework\TestCase;

/**
 * Regresi bug "Gagal clone repo: ... waitpid for git-remote-https failed: No child
 * process ... fatal: index-pack failed".
 *
 * Penyebabnya bukan jaringan: worker Webman persistent bisa sudah meng-ignore
 * SIGCHLD (spawn worker deploy / sesi terminal), dan disposisi SIG_IGN diwariskan
 * melewati fork+exec — sehingga git/*docker compose* kehilangan waitpid() atas
 * anaknya sendiri dan proc_close() di ProcessRunner kehilangan exit code.
 */
final class SigchldGuardTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_signal_get_handler') || !defined('SIGCHLD')) {
            $this->markTestSkipped('Ekstensi pcntl tidak tersedia.');
        }
    }

    protected function tearDown(): void
    {
        @pcntl_signal(SIGCHLD, SIG_DFL);
    }

    public function testDisableIgnoreHanyaBertindakSaatSigchldDiIgnore(): void
    {
        @pcntl_signal(SIGCHLD, SIG_DFL);
        $this->assertFalse(SigchldGuard::disableIgnore(), 'disposisi normal tidak boleh diubah');
        $this->assertFalse(SigchldGuard::isIgnored());

        @pcntl_signal(SIGCHLD, SIG_IGN);
        $this->assertTrue(SigchldGuard::disableIgnore());
        $this->assertFalse(SigchldGuard::isIgnored(), 'SIGCHLD harus kembali SIG_DFL');
    }

    public function testWithDefaultMemulihkanPolaIgnoreSetelahSelesai(): void
    {
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $result = SigchldGuard::withDefault(static fn (): string => 'selesai');

        $this->assertSame('selesai', $result);
        $this->assertTrue(SigchldGuard::isIgnored(), 'SIG_IGN harus dipasang kembali (worker butuh auto-reap)');
    }

    public function testWithDefaultMemulihkanDisposisiSaatSpawnMelemparException(): void
    {
        @pcntl_signal(SIGCHLD, SIG_IGN);

        try {
            SigchldGuard::withDefault(static function (): void {
                throw new \RuntimeException('gagal spawn');
            });
            $this->fail('exception harus diteruskan');
        } catch (\RuntimeException $e) {
            $this->assertSame('gagal spawn', $e->getMessage());
        }

        $this->assertTrue(SigchldGuard::isIgnored());
    }

    public function testRunnerMembacaExitCodeMeskiSigchldDiIgnore(): void
    {
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $result = (new ProcessRunner())->run(['/bin/sh', '-c', 'exit 3'], null, 30);

        // Tanpa perbaikan: anak auto-reap kernel → proc_close() mengembalikan -1.
        $this->assertSame(3, $result['code'], 'exit code hilang: ' . $result['stderr']);
    }

    public function testProsesAnakTidakMewarisiSigchldIgnore(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Pemeriksaan disposisi sinyal butuh /proc (Linux).');
        }

        @pcntl_signal(SIGCHLD, SIG_IGN);

        $result = (new ProcessRunner())->run(['/bin/sh', '-c', "grep '^SigIgn:' /proc/self/status"], null, 30);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        preg_match('/SigIgn:\s+([0-9a-f]+)/', $result['stdout'], $m);
        // Bit sinyal N = bit ke-(N-1) di mask; SIGCHLD = 17.
        $ignored = hexdec($m[1] ?? '0');
        $this->assertSame(
            0,
            $ignored & (1 << (SIGCHLD - 1)),
            'proses anak mewarisi SIGCHLD=SIG_IGN → waitpid() git/docker akan gagal: ' . $result['stdout']
        );
    }
}
