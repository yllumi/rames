<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use PHPUnit\Framework\TestCase;

/**
 * Test `BackupController::guideHtml()` — pembacaan + render aman Markdown panduan
 * setup Restic (`host/restic-setup.md`) untuk halaman `/backups/guide`.
 *
 * Fail-safe: berkas tak ada / kosong / whitespace-only → `''` (halaman tetap
 * dirender). Berkas valid → HTML ter-render via `Markdown::toHtml()`. Semua
 * berkas ditulis di direktori temp supaya data runtime nyata tidak tersentuh.
 */
class BackupControllerGuideTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/rames-guide-test-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    public function testValidMarkdownRendersHtml(): void
    {
        $path = $this->writeFile('guide.md', "# Judul\n\nIsi panduan.");

        $html = BackupController::guideHtml($path);

        $this->assertStringContainsString('<h1>Judul</h1>', $html);
        $this->assertStringContainsString('Isi panduan.', $html);
    }

    public function testRawHtmlIsEscaped(): void
    {
        $path = $this->writeFile('guide.md', "<script>alert(1)</script>\n\n# Aman");

        $html = BackupController::guideHtml($path);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testMissingFileReturnsEmptyString(): void
    {
        $this->assertSame('', BackupController::guideHtml($this->dir . '/tidak-ada.md'));
    }

    public function testEmptyFileReturnsEmptyString(): void
    {
        $path = $this->writeFile('empty.md', '');

        $this->assertSame('', BackupController::guideHtml($path));
    }

    public function testWhitespaceOnlyFileReturnsEmptyString(): void
    {
        $path = $this->writeFile('blank.md', "   \n\t  \n");

        $this->assertSame('', BackupController::guideHtml($path));
    }
}
