<?php
declare(strict_types=1);

namespace Tests;

use app\library\Domain\ParkedDomainContent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test penyimpanan berkas konten domain parkir: path aman, tulis atomik,
 * baca/hapus, dan prasyarat direktori.
 *
 * Semua berkas di direktori temp — `database/parked` nyata tidak disentuh.
 */
class ParkedDomainContentTest extends TestCase
{
    private const ID = 'pd_0123456789ab';

    private string $base;
    private string $root;
    private ParkedDomainContent $content;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rames-parked-content-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/parked';
        $this->content = new ParkedDomainContent($this->root);
    }

    protected function tearDown(): void
    {
        self::rmTree($this->base);
    }

    public function testDirAndPathAreInsideRoot(): void
    {
        $this->assertSame($this->root . '/' . self::ID, $this->content->dirFor(self::ID));
        $this->assertSame($this->root . '/' . self::ID . '/index.html', $this->content->pathFor(self::ID));
    }

    public function testWriteReadAndRemove(): void
    {
        $path = $this->content->write(self::ID, '<h1>Halo parkir</h1>');

        $this->assertSame($this->content->pathFor(self::ID), $path);
        $this->assertFileExists($path);
        $this->assertSame('<h1>Halo parkir</h1>', $this->content->read(self::ID));
        $this->assertSame(0644, fileperms($path) & 0777, 'berkas konten harus 0644 agar terbaca Nginx host');
        $this->assertDirectoryExists($this->root . '/' . self::ID);

        $this->content->remove(self::ID);

        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryDoesNotExist($this->root . '/' . self::ID);
        $this->assertNull($this->content->read(self::ID));
    }

    public function testWriteIsAtomicAndLeavesNoTempFile(): void
    {
        $path = $this->content->write(self::ID, '<html></html>');

        $this->assertFileDoesNotExist($path . '.tmp');
        $this->assertSame('<html></html>', (string) file_get_contents($path));
    }

    public function testEnsuresRootExistsAndIsWritable(): void
    {
        $this->assertDirectoryDoesNotExist($this->root);

        $this->content->ensureWritable();

        $this->assertDirectoryExists($this->root);
        $this->assertTrue(is_writable($this->root));
    }

    public function testEnsureWritableFailsClearlyWhenRootIsAFile(): void
    {
        mkdir($this->base, 0775, true);
        file_put_contents($this->root, 'bukan direktori');

        try {
            $this->content->ensureWritable();
            $this->fail('ensureWritable harus melempar bila akar bukan direktori');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->root, $e->getMessage());
            $this->assertStringContainsString('mkdir', $e->getMessage());
        }
    }

    public function testRejectsRelativeRootBeforeTouchingFilesystem(): void
    {
        $relative = 'rames-parked-rel-' . bin2hex(random_bytes(4));
        $content = new ParkedDomainContent($relative);

        foreach ([
            function () use ($content): void {
                $content->ensureWritable();
            },
            static fn () => $content->write(self::ID, '<html></html>'),
            static fn () => $content->dirFor(self::ID),
        ] as $operation) {
            try {
                $operation();
                $this->fail('akar parkir relatif harus ditolak: ' . $relative);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('PARKED_ROOT', $e->getMessage());
                $this->assertStringContainsString('absolut', $e->getMessage());
            }
        }

        // Tidak ada direktori relatif yang tercipta di cwd proses.
        $this->assertDirectoryDoesNotExist((string) getcwd() . '/' . $relative);
    }

    public function testRejectsParentTraversalInRootBeforeCreatingDirectory(): void
    {
        $traversal = $this->base . '/parked/../parked';
        $content = new ParkedDomainContent($traversal);

        foreach ([
            function () use ($content): void {
                $content->ensureWritable();
            },
            static fn () => $content->write(self::ID, '<html></html>'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('akar parkir ber-segmen ".." harus ditolak: ' . $traversal);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('..', $e->getMessage());
            }
        }

        $this->assertDirectoryDoesNotExist($this->base . '/parked');
    }

    public function testRejectsEmptyOrNulRoot(): void
    {
        foreach (['', '   ', "/tmp/parked\0evil"] as $root) {
            $content = new ParkedDomainContent($root);

            try {
                $content->ensureWritable();
                $this->fail('akar parkir kosong/NUL harus ditolak');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('PARKED_ROOT', $e->getMessage());
            }
        }
    }

    public function testRejectsUnsafeIdsBeforeTouchingFilesystem(): void
    {
        foreach ([
            '../etc',
            '../../passwd',
            'pd_0123456789AB',
            'pd_0123',
            "pd_0123456789ab\n",
            '',
        ] as $bad) {
            foreach ([
                fn (): string => $this->content->dirFor($bad),
                fn (): string => $this->content->pathFor($bad),
                fn (): string => $this->content->write($bad, 'x'),
                fn (): ?string => $this->content->read($bad),
                function () use ($bad): void {
                    $this->content->remove($bad);
                },
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('id tidak aman harus ditolak: ' . $bad);
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('tidak valid', $e->getMessage());
                }
            }
        }

        // Tidak ada direktori yang tercipta di luar akar.
        $this->assertDirectoryDoesNotExist($this->root);
    }

    private static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rmTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
