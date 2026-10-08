<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\FileError;
use app\library\Files\FilesInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test validasi `move` (§7.9) — logika murni `FilesInput::moveTarget()`,
 * tanpa Docker. Menjaga kasus tolak yang berbahaya (akar `/`, pindah ke dalam
 * diri sendiri/descendant, nama tak aman) tetap ditolak SEBELUM menyentuh
 * container (`ContainerFiles::move()` menambahkan cek keberadaan/bentrok nama).
 */
class FileMoveTest extends TestCase
{
    public function testMovesIntoDirectoryKeepingSourceNameByDefault(): void
    {
        $plan = FilesInput::moveTarget('/a/b.txt', '/c', null);

        $this->assertSame('/a/b.txt', $plan['from']);
        $this->assertSame('/c', $plan['dir']);
        $this->assertSame('/c/b.txt', $plan['target']);
    }

    public function testMovesDirectoryIntoRoot(): void
    {
        $plan = FilesInput::moveTarget('/a/dir', '/', null);

        $this->assertSame('/', $plan['dir']);
        $this->assertSame('/dir', $plan['target']);
    }

    public function testExplicitNameOverridesTargetEntryName(): void
    {
        $plan = FilesInput::moveTarget('/a/b.txt', '/c/dir', 'lain.txt');

        $this->assertSame('/c/dir/lain.txt', $plan['target']);
    }

    public function testNormalizesSourceAndDestination(): void
    {
        $plan = FilesInput::moveTarget('/a/./x/../b.txt', '/c//d/', '');

        $this->assertSame('/a/b.txt', $plan['from']);
        $this->assertSame('/c/d', $plan['dir']);
        $this->assertSame('/c/d/b.txt', $plan['target']);
    }

    public function testRejectsMovingRoot(): void
    {
        try {
            FilesInput::moveSource('/');
            $this->fail('akar "/" harus ditolak');
        } catch (FileError $e) {
            $this->assertSame(400, $e->status);
            $this->assertNotSame('', $e->getMessage());
        }

        $this->expectException(FileError::class);
        FilesInput::moveTarget('/', '/tmp', null);
    }

    public function testRejectsTargetInsideSource(): void
    {
        // Pindah ke dalam dirinya sendiri.
        try {
            FilesInput::moveTarget('/a', '/a', null);
            $this->fail('tujuan = sumber harus ditolak');
        } catch (FileError $e) {
            $this->assertSame(400, $e->status);
        }

        // Pindah ke descendant-nya sendiri.
        try {
            FilesInput::moveTarget('/a', '/a/b/c', null);
            $this->fail('tujuan descendant sumber harus ditolak');
        } catch (FileError $e) {
            $this->assertSame(400, $e->status);
        }
    }

    public function testAllowsSiblingAndAncestorDestination(): void
    {
        // Ke saudara (bukan descendant) dan ke atas (parent) tetap sah.
        $this->assertSame('/b/x/file.txt', FilesInput::moveTarget('/a/file.txt', '/b/x', null)['target']);
        $this->assertSame('/x/file.txt', FilesInput::moveTarget('/a/file.txt', '/x', null)['target']);
        $this->assertSame('/file.txt', FilesInput::moveTarget('/a/b/file.txt', '/', null)['target']);
    }

    public function testRejectsUnsafeNames(): void
    {
        foreach (['../x', 'a/b', '-rf', '.', '..'] as $bad) {
            try {
                FilesInput::moveTarget('/a/b.txt', '/c', $bad);
                $this->fail('nama harus ditolak: ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRejectsRelativeOrControlDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FilesInput::moveTarget('/a/b.txt', 'relative/dir', null);
    }
}
