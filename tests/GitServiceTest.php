<?php
declare(strict_types=1);

namespace Tests;

use app\library\Git\GitService;
use PHPUnit\Framework\TestCase;

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
