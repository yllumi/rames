<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * Test TextContent — deteksi biner & batas ukuran edit (logika murni).
 */
class FileTextContentTest extends TestCase
{
    public function testPlainTextIsNotBinary(): void
    {
        $this->assertFalse(TextContent::isBinary('hello world'));
        $this->assertFalse(TextContent::isBinary("line1\nline2\t!\r\n"));
        $this->assertFalse(TextContent::isBinary(''));
        $this->assertFalse(TextContent::isBinary("<?php echo 'x'; ?>"));
        $this->assertFalse(TextContent::isBinary("Halo — ünïcode ✅\n"));
    }

    public function testNullByteIsBinary(): void
    {
        $this->assertTrue(TextContent::isBinary("hello\0world"));
    }

    public function testInvalidUtf8IsBinary(): void
    {
        // 0xC3 0x28 bukan sekuens UTF-8 valid.
        $this->assertTrue(TextContent::isBinary("\xC3\x28"));
    }

    public function testControlCharHeavyContentIsBinary(): void
    {
        $this->assertTrue(TextContent::isBinary('a' . str_repeat("\x01", 20)));
    }

    public function testSizeLimit(): void
    {
        $this->assertFalse(TextContent::isTooLarge(TextContent::MAX_TEXT_BYTES));
        $this->assertFalse(TextContent::isTooLarge(0));
        $this->assertTrue(TextContent::isTooLarge(TextContent::MAX_TEXT_BYTES + 1));
    }
}
