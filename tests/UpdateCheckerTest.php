<?php
declare(strict_types=1);

namespace Tests;

use app\library\Update\RepoInfo;
use app\library\Update\UpdateChecker;
use app\library\Update\UpdateState;
use PHPUnit\Framework\TestCase;

/**
 * Unit test UpdateChecker (SPECS.md §7.8): membandingkan SHA lokal (dibaca dari
 * `.git`) dengan SHA remote hasil `git ls-remote`, menyimpan hasil ke check.json,
 * dan menangani jalur gagal (remote tidak terbaca, HEAD detached) dengan pesan
 * yang bisa ditindaklanjuti.
 *
 * Yang dijaga: pengecekan TIDAK boleh memakai `git fetch` (fetch menulis objek
 * & ref ke `.git` sebagai root → file milik root di repo milik user host,
 * `git pull` dari SSH berikutnya bisa gagal). Test di bawah memastikan hanya
 * `ls-remote` yang dipanggil.
 */
class UpdateCheckerTest extends TestCase
{
    private const LOCAL_SHA = 'c81b5e4a1b2c3d4e5f60718293a4b5c6d7e8f9a0';
    private const REMOTE_SHA = '5f2a1c9d3b4e5f60718293a4b5c6d7e8f9a0b1c2';

    private string $tmp;
    private string $repo;
    private FakeCommandRunner $runner;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-update-' . bin2hex(random_bytes(4));
        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo . '/.git/refs/heads', 0777, true);
        file_put_contents($this->repo . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->repo . '/.git/refs/heads/main', self::LOCAL_SHA . "\n");
        file_put_contents(
            $this->repo . '/.git/config',
            "[remote \"origin\"]\n\turl = https://github.com/yllumi/rames.git\n"
        );

        $this->runner = new FakeCommandRunner();
        $this->runner
            ->reply('ls-remote', self::REMOTE_SHA . "\trefs/heads/main\n")
            ->reply('log -1', self::LOCAL_SHA . "\t2026-09-22T17:12:00+07:00\tAdd templates\n")
            // "--untracked-files=no" adalah PREFIKS dari "--untracked-files=normal",
            // jadi aturan untracked harus didaftarkan lebih dulu & dicocokkan utuh.
            ->reply('untracked-files=normal', "?? templates/wabaileys/\n")
            ->reply('untracked-files=no', " M app/functions.php\n")
            ->reply('--version', "git version 2.49.1\n");
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================

    public function testDetectsAvailableUpdateAndWritesCache(): void
    {
        $checker = $this->checker();
        $result = $checker->check();

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('main', $result['branch']);
        $this->assertSame(self::LOCAL_SHA, $result['local_sha']);
        $this->assertSame(self::REMOTE_SHA, $result['remote_sha']);
        $this->assertTrue($result['update_available']);
        $this->assertSame(
            'https://github.com/yllumi/rames/compare/' . self::LOCAL_SHA . '...' . self::REMOTE_SHA,
            $result['compare_url']
        );
        $this->assertSame('Add templates', $result['head']['subject'] ?? null);
        // Informasi repo kotor dipakai preflight untuk menolak update.
        $this->assertSame(['M app/functions.php'], $result['tracked_changes']);
        $this->assertSame(['templates/wabaileys/'], $result['untracked']);

        // Hasil disimpan → badge nav membacanya tanpa jaringan.
        $this->assertFileExists($this->tmp . '/check.json');
        $cached = $checker->cached();
        $this->assertSame(self::REMOTE_SHA, $cached['remote_sha']);
        $this->assertTrue($cached['update_available']);

        // Anti-regresi: cek pembaruan TIDAK boleh memakai `git fetch`.
        $this->assertFalse($this->runner->calledWith('git fetch'));
        $this->assertTrue($this->runner->calledWith('ls-remote'));
    }

    public function testUpToDateWhenRemoteShaEqualsLocal(): void
    {
        $this->runner->responses = [];
        $this->runner
            ->reply('ls-remote', self::LOCAL_SHA . "\trefs/heads/main\n")
            ->reply('log -1', self::LOCAL_SHA . "\t2026-09-22T17:12:00+07:00\tAdd templates\n")
            ->reply('status --porcelain', '');
        $result = $this->checker()->check();

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['update_available']);
        $this->assertNull($result['compare_url']);
    }

    public function testRemoteFailureIsReportedNotThrown(): void
    {
        $this->runner->responses = [];
        $this->runner
            ->reply('ls-remote', '', 128, 'fatal: unable to access remote')
            ->reply('--version', "git version 2.49.1\n")
            ->reply('status --porcelain', '');

        $result = $this->checker()->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Gagal membaca ref remote', (string) $result['error']);
        $this->assertStringContainsString('git version 2.49.1', (string) $result['error']);
        $this->assertFalse($result['update_available']);
    }

    public function testDetachedHeadWithoutBranchOverrideIsRejected(): void
    {
        file_put_contents($this->repo . '/.git/HEAD', self::LOCAL_SHA . "\n");

        $result = $this->checker()->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('detached', (string) $result['error']);
    }

    public function testBranchOverrideIsUsed(): void
    {
        file_put_contents($this->repo . '/.git/HEAD', self::LOCAL_SHA . "\n");

        // Aturan paling spesifik harus didaftarkan lebih dulu (dicocokkan berurutan).
        $this->runner->responses = [];
        $this->runner
            ->reply('refs/heads/release', self::REMOTE_SHA . "\trefs/heads/release\n")
            ->reply('log -1', self::LOCAL_SHA . "\t2026-09-22T17:12:00+07:00\tAdd templates\n")
            ->reply('status --porcelain', '');

        $result = $this->checker('release')->check();

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('release', $result['branch']);
        $this->assertTrue($result['update_available']);
    }

    public function testNonRepoIsReportedClearly(): void
    {
        $checker = new UpdateChecker(
            new RepoInfo($this->tmp . '/bukan-repo', $this->runner),
            $this->state(),
            ''
        );

        $result = $checker->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bukan repo Git', (string) $result['error']);
        // Tidak ada pemanggilan jaringan sama sekali.
        $this->assertFalse($this->runner->calledWith('ls-remote'));
    }

    public function testCheckWithoutWriteKeepsCache(): void
    {
        $checker = $this->checker();
        $checker->check(false);

        $this->assertFileDoesNotExist($this->tmp . '/check.json');
    }

    // ==================================================================

    private function checker(string $branchOverride = ''): UpdateChecker
    {
        return new UpdateChecker(new RepoInfo($this->repo, $this->runner), $this->state(), $branchOverride);
    }

    private function state(): UpdateState
    {
        return new UpdateState($this->tmp . '/check.json', $this->tmp . '/runs');
    }
}
