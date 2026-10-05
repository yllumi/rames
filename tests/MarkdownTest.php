<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Unit test Markdown — render panduan template (SPECS.md §7.2b).
 *
 * Kontrak keamanan yang diuji: HTML mentah di-escape, tautan berbahaya
 * (`javascript:`) dinonaktifkan, dan tautan eksternal membuka tab baru dengan
 * `rel` memuat `noopener`.
 */
class MarkdownTest extends TestCase
{
    public function testRendersHeadingsListsAndInlineCode(): void
    {
        $html = Markdown::toHtml("# Judul\n\n- satu\n- dua\n\n`kode`");

        $this->assertStringContainsString('<h1>Judul</h1>', $html);
        $this->assertStringContainsString('<li>satu</li>', $html);
        $this->assertStringContainsString('<li>dua</li>', $html);
        $this->assertStringContainsString('<code>kode</code>', $html);
    }

    public function testEscapesRawHtmlScript(): void
    {
        $html = Markdown::toHtml('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRejectsUnsafeJavascriptLink(): void
    {
        $html = Markdown::toHtml('[x](javascript:alert(1))');

        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function testExternalLinkOpensInNewWindowWithNoopener(): void
    {
        $html = Markdown::toHtml('[situs](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $html);
    }

    public function testEmptyInputReturnsEmptyString(): void
    {
        $this->assertSame('', Markdown::toHtml(''));
        $this->assertSame('', Markdown::toHtml("   \n\t  "));
    }

    public function testRendersGfmPipeTable(): void
    {
        $html = Markdown::toHtml("| Key | Status |\n|-----|--------|\n| A   | Wajib  |");

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);
        $this->assertStringContainsString('<th', $html);
        $this->assertStringContainsString('<tbody', $html);
        $this->assertStringContainsString('<td', $html);
        $this->assertStringContainsString('Wajib', $html);
        $this->assertStringNotContainsString('| Key |', $html);
    }
}
