<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\ArchiveGuard;
use PHPUnit\Framework\TestCase;

/**
 * Test ArchiveGuard — validasi entri arsip (zip-slip) & pemindaian symlink.
 */
class FileArchiveGuardTest extends TestCase
{
    /** @var array<int,string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public function testKindDetection(): void
    {
        $this->assertSame('zip', ArchiveGuard::kind('archive.zip'));
        $this->assertSame('zip', ArchiveGuard::kind('ARCHIVE.ZIP'));
        $this->assertSame('tar', ArchiveGuard::kind('backup.tar.gz'));
        $this->assertSame('tar', ArchiveGuard::kind('backup.TGZ'));
        $this->assertNull(ArchiveGuard::kind('notes.txt'));
        $this->assertNull(ArchiveGuard::kind('archive.tar'));
        $this->assertNull(ArchiveGuard::kind('archive.gz'));
        $this->assertNull(ArchiveGuard::kind(''));
    }

    public function testEntryErrorAcceptsSafeNames(): void
    {
        $this->assertNull(ArchiveGuard::entryError('a.txt'));
        $this->assertNull(ArchiveGuard::entryError('dir/sub/file.txt'));
        $this->assertNull(ArchiveGuard::entryError('a/./b'));
    }

    public function testEntryErrorRejectsZipSlipAndAbsolute(): void
    {
        foreach ([
            '',
            '../evil',
            'a/../../evil',
            '/etc/passwd',
            "a\0b",
            'C:\\Windows\\x',
            '..\\evil',
        ] as $bad) {
            $this->assertNotNull(ArchiveGuard::entryError($bad), 'harus ditolak: ' . var_export($bad, true));
        }
    }

    public function testUnsafeEntryReturnsFirstBadName(): void
    {
        $this->assertNull(ArchiveGuard::unsafeEntry(['a.txt', 'dir/b.txt']));
        $this->assertSame('../evil', ArchiveGuard::unsafeEntry(['a.txt', '../evil', '/abs']));
    }

    public function testParseZipTotal(): void
    {
        $listing = <<<TXT
Archive:  demo.zip
  Length      Date    Time    Name
---------  ---------- -----   ----
      100  2020-01-01 00:00   a.txt
      200  2020-01-01 00:00   b.txt
---------                     -------
      300                     2 files
TXT;
        $this->assertSame(['bytes' => 300, 'entries' => 2], ArchiveGuard::parseZipTotal($listing));

        $single = <<<TXT
---------                     -------
       42                     1 file
TXT;
        $this->assertSame(['bytes' => 42, 'entries' => 1], ArchiveGuard::parseZipTotal($single));

        $this->assertNull(ArchiveGuard::parseZipTotal('bukan listing'));
    }

    public function testEscapingSymlinkDetectsEscape(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/sub', 0700);

        // symlink aman (target di dalam root)
        symlink('../file.txt', $root . '/sub/safe');

        $this->assertNull(ArchiveGuard::escapingSymlink($root));

        // symlink menembus keluar root
        symlink('../../outside', $root . '/sub/escape');
        $this->assertSame($root . '/sub/escape', ArchiveGuard::escapingSymlink($root));
    }

    public function testEscapingSymlinkDetectsAbsoluteTarget(): void
    {
        $root = $this->tempDir();
        symlink('/etc/passwd', $root . '/abs');

        $this->assertSame($root . '/abs', ArchiveGuard::escapingSymlink($root));
    }

    public function testMeasureCountsEntriesAndBytes(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/dir', 0700);
        file_put_contents($root . '/dir/a.txt', str_repeat('a', 100));
        file_put_contents($root . '/b.txt', str_repeat('b', 40));

        $this->assertSame(['entries' => 3, 'bytes' => 140], ArchiveGuard::measure($root));
        $this->assertSame(['entries' => 0, 'bytes' => 0], ArchiveGuard::measure($root . '/missing'));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/rames-archive-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path => $info) {
            $path = (string) $path;
            if ($info->isDir() && !is_link($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
