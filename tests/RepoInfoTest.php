<?php
declare(strict_types=1);

namespace Tests;

use app\library\Update\RepoInfo;
use PHPUnit\Framework\TestCase;

/**
 * Unit test RepoInfo — pembacaan keadaan repo git untuk fitur self-update
 * (SPECS.md §7.8). Semua parser diuji tanpa repo nyata; pembacaan SHA/branch
 * diuji dengan fixture `.git` di direktori temporer.
 *
 * Yang dijaga di sini: dashboard berjalan sebagai ROOT di dalam container
 * sedangkan repo dimiliki uid host, jadi jalur pembacaan `HEAD`/refs/config
 * TIDAK boleh memanggil git (kalau tidak, muncul `detected dubious ownership`)
 * dan tidak boleh menulis apa pun ke `.git`.
 */
class RepoInfoTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-repo-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================
    // Parser statik
    // ==================================================================

    public function testParseHeadRef(): void
    {
        $this->assertSame('refs/heads/main', RepoInfo::parseHeadRef("ref: refs/heads/main\n"));
        $this->assertSame('refs/heads/feature/x', RepoInfo::parseHeadRef('ref: refs/heads/feature/x'));
        $this->assertSame('a1b2c3d', RepoInfo::parseHeadRef('a1b2c3d'));
        $this->assertNull(RepoInfo::parseHeadRef(''));
        $this->assertNull(RepoInfo::parseHeadRef('ref: '));
        $this->assertNull(RepoInfo::parseHeadRef('bukan ref'));
    }

    public function testParsePackedRefs(): void
    {
        $packed = "# pack-refs with: peeled fully-peeled sorted\n"
            . "1111111111111111111111111111111111111111 refs/heads/main\n"
            . "2222222222222222222222222222222222222222 refs/heads/dev\n"
            . "3333333333333333333333333333333333333333 refs/tags/v1\n"
            . "^4444444444444444444444444444444444444444\n";

        $this->assertSame('1111111111111111111111111111111111111111', RepoInfo::parsePackedRefs($packed, 'refs/heads/main'));
        $this->assertSame('2222222222222222222222222222222222222222', RepoInfo::parsePackedRefs($packed, 'refs/heads/dev'));
        $this->assertNull(RepoInfo::parsePackedRefs($packed, 'refs/heads/hilang'));
    }

    public function testParseRemoteUrl(): void
    {
        $config = "[core]\n\trepositoryformatversion = 0\n"
            . "[remote \"origin\"]\n\turl = https://github.com/yllumi/rames.git\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n"
            . "[remote \"mirror\"]\n\turl = git@github.com:someone/mirror.git\n";

        $this->assertSame('https://github.com/yllumi/rames.git', RepoInfo::parseRemoteUrl($config, 'origin'));
        $this->assertSame('git@github.com:someone/mirror.git', RepoInfo::parseRemoteUrl($config, 'mirror'));
        $this->assertNull(RepoInfo::parseRemoteUrl($config, 'tidak-ada'));
        $this->assertNull(RepoInfo::parseRemoteUrl('[core]', 'origin'));

        // Nilai ber-quote (git menulis ini bila ada karakter khusus).
        $quoted = "[remote \"origin\"]\n\turl = \"https://example.com/a b.git\"\n";
        $this->assertSame('https://example.com/a b.git', RepoInfo::parseRemoteUrl($quoted, 'origin'));
    }

    public function testParsePorcelain(): void
    {
        $out = " M app/functions.php\n"
            . "M  config/route.php\n"
            . "A  app/library/Update/RepoInfo.php\n"
            . "?? templates/wabaileys/\n"
            . "R  old.php -> new.php\n"
            . "\n";

        $entries = RepoInfo::parsePorcelain($out);
        $this->assertCount(5, $entries);
        $this->assertSame(['code' => 'M', 'path' => 'app/functions.php'], $entries[0]);
        $this->assertSame('??', $entries[3]['code']);
        $this->assertSame('templates/wabaileys/', $entries[3]['path']);
    }

    public function testParseLsRemote(): void
    {
        $out = "5f2a1c9d3b4e5f60718293a4b5c6d7e8f9a0b1c2\trefs/heads/main\n"
            . "aaaabbbbccccddddeeeeffff0000111122223333\trefs/heads/dev\n";

        $this->assertSame('5f2a1c9d3b4e5f60718293a4b5c6d7e8f9a0b1c2', RepoInfo::parseLsRemote($out, 'refs/heads/main'));
        $this->assertNull(RepoInfo::parseLsRemote($out, 'refs/heads/hilang'));
        $this->assertNull(RepoInfo::parseLsRemote('', 'refs/heads/main'));
    }

    public function testParseLogLine(): void
    {
        $line = "c81b5e4a1b2c3d4e5f60718293a4b5c6d7e8f9a0\t2026-09-22T17:12:00+07:00\tAdd templates";
        $parsed = RepoInfo::parseLogLine($line);

        $this->assertNotNull($parsed);
        $this->assertSame('c81b5e4a1b2c3d4e5f60718293a4b5c6d7e8f9a0', $parsed['sha']);
        $this->assertSame('2026-09-22T17:12:00+07:00', $parsed['date']);
        $this->assertSame('Add templates', $parsed['subject']);
        $this->assertNull(RepoInfo::parseLogLine(''));
    }

    public function testCompareUrlPerHost(): void
    {
        $from = '1111111111111111111111111111111111111111';
        $to = '2222222222222222222222222222222222222222';

        $this->assertSame(
            'https://github.com/yllumi/rames/compare/' . $from . '...' . $to,
            RepoInfo::compareUrl('https://github.com/yllumi/rames.git', $from, $to)
        );
        $this->assertSame(
            'https://github.com/yllumi/rames/compare/' . $from . '...' . $to,
            RepoInfo::compareUrl('git@github.com:yllumi/rames.git', $from, $to)
        );
        $this->assertSame(
            'https://gitlab.com/group/proj/-/compare/' . $from . '...' . $to,
            RepoInfo::compareUrl('https://gitlab.com/group/proj.git', $from, $to)
        );
        // Host tak dikenal → null (UI hanya menyembunyikan tautannya).
        $this->assertNull(RepoInfo::compareUrl('https://git.internal.local/team/proj.git', $from, $to));
        $this->assertNull(RepoInfo::compareUrl(null, $from, $to));
        $this->assertNull(RepoInfo::compareUrl('https://github.com/a/b.git', '', $to));
    }

    // ==================================================================
    // Pembacaan dari `.git` nyata (fixture)
    // ==================================================================

    public function testReadsShaAndBranchFromLooseRef(): void
    {
        $repo = $this->makeGitDir();
        file_put_contents($repo . '/.git/refs/heads/main', "abcdef1234567890abcdef1234567890abcdef12\n");

        $info = new RepoInfo($repo);
        $this->assertTrue($info->isRepo());
        $this->assertSame('main', $info->branch());
        $this->assertSame('abcdef1234567890abcdef1234567890abcdef12', $info->sha());
    }

    public function testReadsShaFromPackedRefs(): void
    {
        $repo = $this->makeGitDir();
        file_put_contents(
            $repo . '/.git/packed-refs',
            "# pack-refs with: peeled\nfedcba9876543210fedcba9876543210fedcba98 refs/heads/main\n"
        );

        $info = new RepoInfo($repo);
        $this->assertSame('main', $info->branch());
        $this->assertSame('fedcba9876543210fedcba9876543210fedcba98', $info->sha());
    }

    public function testDetachedHeadHasNoBranchButHasSha(): void
    {
        $repo = $this->makeGitDir();
        file_put_contents($repo . '/.git/HEAD', "0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c\n");

        $info = new RepoInfo($repo);
        $this->assertNull($info->branch());
        $this->assertSame('0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c', $info->sha());
    }

    public function testMissingRepoIsReportedClearly(): void
    {
        $info = new RepoInfo($this->tmp . '/tidak-ada');

        $this->assertFalse($info->isRepo());
        $this->assertNull($info->sha());
        $this->assertNull($info->branch());
        $this->assertNull($info->remoteUrl());
    }

    public function testRemoteUrlFromFixtureConfig(): void
    {
        $repo = $this->makeGitDir();
        file_put_contents(
            $repo . '/.git/config',
            "[core]\n\trepositoryformatversion = 0\n[remote \"origin\"]\n\turl = https://github.com/yllumi/rames.git\n"
        );

        $this->assertSame('https://github.com/yllumi/rames.git', (new RepoInfo($repo))->remoteUrl());
    }

    /**
     * Direktori dengan `.git` minimal (HEAD + config) — tanpa menjalankan git.
     */
    private function makeGitDir(): string
    {
        $repo = $this->tmp . '/repo-' . bin2hex(random_bytes(3));
        mkdir($repo . '/.git/refs/heads', 0777, true);
        file_put_contents($repo . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($repo . '/.git/config', "[core]\n\trepositoryformatversion = 0\n");

        return $repo;
    }
}
