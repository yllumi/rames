<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerExec;
use PHPUnit\Framework\TestCase;

/**
 * Test prune sesi terminal (`DockerExec::pruneStale()`).
 *
 * Regresi yang dijaga: prune TIDAK boleh menghapus direktori sesi yang baru dibuat.
 * `open()` membuat direktori lebih dulu, baru menulis `session.json`/`pid`, sehingga
 * ada jendela singkat di mana direktori sudah ada tapi metadata belum. Dulu metadata
 * yang absen dianggap `created_at = 0` → umur tak terhingga → direktori langsung
 * dihapus prune milik worker lain, dan semua request lanjutan (stream/input/close)
 * menjawab 404 "Sesi terminal tidak ditemukan atau sudah berakhir".
 *
 * Catatan: test yang MENGARAP prune tidak boleh menulis PID proses test ke berkas
 * `pid` (nanti `closeSession()` mengirim sinyal ke proses test itu sendiri).
 */
class TerminalSessionPruneTest extends TestCase
{
    /** `DockerExec::PRUNE_GRACE_SECONDS` (private) — jeda proteksi sesi baru. */
    private const GRACE = 30;

    /** Default `deploy.terminal_session_ttl` (config belum dimuat di bootstrap test). */
    private const SESSION_TTL = 3600;

    /** Default `deploy.terminal_idle_timeout`. */
    private const IDLE_TIMEOUT = 900;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-terminal-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sessionDir) {
            foreach (glob($sessionDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($sessionDir);
        }
        @rmdir($this->dir);
    }

    public function testFreshSessionWithoutMetadataSurvivesPrune(): void
    {
        // Jendela race: direktori sudah ada, metadata belum ditulis.
        $dir = $this->makeSession($this->token(), []);

        $this->assertSame([], $this->exec()->pruneStale());
        $this->assertDirectoryExists($dir);
    }

    public function testSessionOfDeadProcessIsPrunedAfterGrace(): void
    {
        $token = $this->token();
        $dir = $this->makeSession($token, [], self::GRACE + 60);

        $this->assertSame([$token], $this->exec()->pruneStale());
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testLiveSessionWithRecentActivitySurvivesPrune(): void
    {
        $token = $this->token();
        $dir = $this->makeSession($token, ['created_at' => time() - 60], self::GRACE + 60, 5);
        file_put_contents($dir . '/pid', (string) getmypid());

        $this->assertSame([], $this->exec()->pruneStale());
        $this->assertDirectoryExists($dir);
    }

    public function testMissingMetadataFallsBackToDirectoryAge(): void
    {
        // Tanpa session.json, umur diambil dari mtime direktori — dulu dianggap
        // epoch 1970 sehingga sesi yang masih hidup pun ikut dihapus.
        $token = $this->token();
        $dir = $this->makeSession($token, [], self::GRACE + 10);
        file_put_contents($dir . '/pid', (string) getmypid());

        $this->assertSame([], $this->exec()->pruneStale());
        $this->assertDirectoryExists($dir);
    }

    public function testIdleSessionIsPrunedEvenWhenProcessIsAlive(): void
    {
        // Sesi ditinggalkan (browser ditutup tanpa POST /close): proses masih hidup,
        // tapi penanda aktivitas sudah lama → dibuang.
        $token = $this->token();
        $dir = $this->makeSession($token, ['created_at' => time() - 60], self::GRACE + 60, self::IDLE_TIMEOUT + 60);

        $this->assertSame([$token], $this->exec()->pruneStale());
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testSessionBeyondMaxAgeIsPruned(): void
    {
        $token = $this->token();
        $dir = $this->makeSession(
            $token,
            ['created_at' => time() - self::SESSION_TTL - 60],
            self::GRACE + 60,
            5
        );

        $this->assertSame([$token], $this->exec()->pruneStale());
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testPruneWithoutRuntimeDirReturnsEmpty(): void
    {
        $exec = new DockerExec('docker', $this->dir . '/tidak-ada', 'script');

        $this->assertSame([], $exec->pruneStale());
    }

    private function exec(): DockerExec
    {
        return new DockerExec('docker', $this->dir, 'script');
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Buat direktori sesi palsu.
     *
     * @param array<string,mixed> $meta         isi session.json (kosong = tidak ditulis)
     * @param int                 $dirAge       umur mtime direktori (detik)
     * @param int                 $activityAge  umur penanda `activity` (detik; 0 = tidak ada)
     */
    private function makeSession(string $token, array $meta = [], int $dirAge = 0, int $activityAge = 0): string
    {
        $dir = $this->dir . '/' . $token;
        mkdir($dir, 0700, true);
        if ($meta !== []) {
            file_put_contents($dir . '/session.json', (string) json_encode($meta + ['token' => $token]));
        }
        if ($activityAge > 0) {
            file_put_contents($dir . '/activity', '');
            touch($dir . '/activity', time() - $activityAge);
        }
        if ($dirAge > 0) {
            touch($dir, time() - $dirAge);
        }

        return $dir;
    }
}
