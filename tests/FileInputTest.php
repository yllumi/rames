<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\FileError;
use app\library\Files\FilesInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test FilesInput — kontrak nama field dengan UI (senyap-senyap berisiko
 * kehilangan data bila salah, jadi dikunci di sini).
 */
class FileInputTest extends TestCase
{
    public function testTextPrefersContractField(): void
    {
        $this->assertSame('isi', FilesInput::text('isi', null));
        $this->assertSame('isi', FilesInput::text('isi', 'lain'));
        // string kosong tetap valid (simpan berkas menjadi kosong disengaja)
        $this->assertSame('', FilesInput::text('', null));
    }

    public function testTextFallsBackToLegacyContent(): void
    {
        $this->assertSame('legacy', FilesInput::text(null, 'legacy'));
    }

    public function testTextRejectsMissingBothFields(): void
    {
        try {
            FilesInput::text(null, null);
            $this->fail('harus melempar FileError 400');
        } catch (FileError $e) {
            $this->assertSame(400, $e->status);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testMkdirTargetJoinsParentAndName(): void
    {
        $this->assertSame('/var/www/app', FilesInput::mkdirTarget('/var/www', 'app'));
        $this->assertSame('/app', FilesInput::mkdirTarget('/', 'app'));
        $this->assertSame('/var/www/app', FilesInput::mkdirTarget('/var/www/', 'app'));
    }

    public function testMkdirTargetFallsBackToAbsolutePathWhenNameEmpty(): void
    {
        $this->assertSame('/var/www/app', FilesInput::mkdirTarget('/var/www/app', ''));
        $this->assertSame('/var/www/app', FilesInput::mkdirTarget('/var/www/app', null));
        $this->assertSame('/var/www/app', FilesInput::mkdirTarget('/var/./www/../www/app', ''));
    }

    public function testMkdirTargetRejectsBadName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FilesInput::mkdirTarget('/var/www', '../etc');
    }

    public function testRenameTargetPrefersExplicitTo(): void
    {
        $this->assertSame('/b/c.txt', FilesInput::renameTarget('/a/b.txt', '/b/c.txt', null));
    }

    public function testRenameTargetUsesNameInSourceDir(): void
    {
        $this->assertSame('/a/c.txt', FilesInput::renameTarget('/a/b.txt', null, 'c.txt'));
        $this->assertSame('/c.txt', FilesInput::renameTarget('/b.txt', '', 'c.txt'));
    }

    public function testRenameTargetRejectsBadOrMissingName(): void
    {
        try {
            FilesInput::renameTarget('/a/b.txt', null, '');
            $this->fail('nama kosong harus ditolak');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        FilesInput::renameTarget('/a/b.txt', null, '../c');
    }
}
