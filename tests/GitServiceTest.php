<?php
declare(strict_types=1);

namespace Tests;

use app\library\Git\GitService;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Pelari proses palsu: merekam command tanpa menjalankannya (uji argumen git).
 */
final class RecordingProcessRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $commands = [];

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = $command;

        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Test GitService terhadap repo git lokal — fondasi fitur rollback:
 * clone shallow, revParse, fetch SHA lama, checkout, re-attach branch, pull.
 */
class GitServiceTest extends TestCase
{
    private GitTestFixture $fx;

    protected function setUp(): void
    {
        $this->fx = GitTestFixture::create();
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    public function testShallowCloneThenRollbackToOldSha(): void
    {
        $dest = $this->fx->workDir . '/app';
        $git = new GitService();
        $git->clone($this->fx->origin, 'main', $dest);

        // clone shallow hanya berisi HEAD (v2)
        $this->assertSame($this->fx->v2, $git->revParse($dest));

        // fetch + checkout ke v1 (simulasi rollback)
        $git->fetchSha($dest, $this->fx->v1);
        $git->checkout($dest, $this->fx->v1);
        $this->assertSame($this->fx->v1, $git->revParse($dest));
        $this->assertFileExists($dest . '/v1.txt');
        $this->assertFileDoesNotExist($dest . '/v2.txt');

        // re-attach branch (persiapan rebuild setelah rollback)
        $git->ensureBranch($dest, 'main');
        $this->assertSame($this->fx->v2, $git->revParse($dest));
        $this->assertFileExists($dest . '/v2.txt');
    }

    public function testCloneUsesShallowSingleBranchArguments(): void
    {
        $runner = new RecordingProcessRunner();
        (new GitService($runner))->clone('https://example.test/app.git', 'produksi', '/tmp/rames-clone-target');

        $this->assertCount(1, $runner->commands);
        $command = $runner->commands[0];

        $this->assertSame(['git', 'clone'], array_slice($command, 0, 2));
        $this->assertContains('--depth=1', $command, 'clone WAJIB shallow (--depth=1) — jangan tarik seluruh riwayat');
        $this->assertContains('--single-branch', $command, 'hanya branch terpilih yang di-clone');
        $this->assertSame(['--branch', 'produksi'], array_slice($command, 2, 2), 'branch pilihan user tetap dipakai');
        $this->assertSame('https://example.test/app.git', $command[count($command) - 2]);
        $this->assertSame('/tmp/rames-clone-target', $command[count($command) - 1]);
    }

    public function testCloneProducesShallowRepositoryWithSingleCommit(): void
    {
        $dest = $this->fx->workDir . '/app-shallow';
        // `file://` (bukan path polos) supaya git benar-benar memakai protokol
        // transport: clone dari path lokal memakai hardlink dan **mengabaikan**
        // `--depth` ("--depth is ignored in local clones").
        (new GitService())->clone('file://' . $this->fx->origin, 'main', $dest);

        $this->assertFileExists($dest . '/.git/shallow', 'repo hasil clone harus shallow');

        $count = (new ProcessRunner())->run(['git', '-C', $dest, 'rev-list', '--count', 'HEAD'], $dest, 60);
        $this->assertSame(0, $count['code'], $count['stderr']);
        $this->assertSame('1', trim($count['stdout']), 'depth 1 ⇒ hanya satu commit di riwayat lokal');
    }

    public function testRevParseReturnsHead(): void
    {
        $dest = $this->fx->workDir . '/site2';
        $this->fx->cloneShallow($dest);
        $this->assertSame($this->fx->v2, (new GitService())->revParse($dest));
    }

    public function testPullFastForwardIsNoOpWhenUpToDate(): void
    {
        $dest = $this->fx->workDir . '/site3';
        $this->fx->cloneShallow($dest);
        $git = new GitService();
        $git->ensureBranch($dest, 'main');
        $git->pull($dest, 'main');
        $this->assertSame($this->fx->v2, $git->revParse($dest));
    }
}
