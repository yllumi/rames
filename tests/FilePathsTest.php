<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\PathGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test PathGuard — normalisasi & validasi path/nama (logika murni, tanpa Docker).
 */
class FilePathsTest extends TestCase
{
    public function testNormalizeResolvesLexically(): void
    {
        $this->assertSame('/a/c', PathGuard::normalize('/a/b/../c'));
        $this->assertSame('/a/b', PathGuard::normalize('/a//b/.'));
        $this->assertSame('/a/b', PathGuard::normalize('/a/b'));
        $this->assertSame('/', PathGuard::normalize('/'));
        $this->assertSame('/etc/passwd', PathGuard::normalize('/etc/passwd'));
        $this->assertSame('/a/c', PathGuard::normalize('/a/b/..//./c'));
    }

    public function testNormalizeRejectsRelativeEmptyAndControlChars(): void
    {
        foreach (['relative/path', '', "/a\0b", "/a\nb", "/a\rb"] as $bad) {
            try {
                PathGuard::normalize($bad);
                $this->fail('harus ditolak: ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testNormalizeRejectsEscapingRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathGuard::normalize('/..');
    }

    public function testNormalizeRejectsEscapeBeyondRootInMiddle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathGuard::normalize('/a/../../b');
    }

    public function testAssertDeletableRejectsRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathGuard::assertDeletable('/');
    }

    public function testAssertDeletableNormalizes(): void
    {
        $this->assertSame('/tmp/x', PathGuard::assertDeletable('/tmp/./y/../x'));
    }

    public function testAssertNameAcceptsPlainNames(): void
    {
        $this->assertSame('file.txt', PathGuard::assertName('file.txt'));
        $this->assertSame('my dir', PathGuard::assertName('  my dir '));
    }

    public function testAssertNameRejectsUnsafeNames(): void
    {
        foreach (['', '.', '..', 'a/b', 'a\\b', '-rf', "a\0b", "a\nb"] as $bad) {
            try {
                PathGuard::assertName($bad);
                $this->fail('harus ditolak: ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAssertNameRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathGuard::assertName(str_repeat('a', PathGuard::MAX_NAME_BYTES + 1));
    }

    public function testResolveChild(): void
    {
        $this->assertSame('/x', PathGuard::resolveChild('/', 'x'));
        $this->assertSame('/a/b', PathGuard::resolveChild('/a', 'b'));
        $this->assertSame('/a/b', PathGuard::resolveChild('/a/', 'b'));
    }

    public function testParentOf(): void
    {
        $this->assertNull(PathGuard::parentOf('/'));
        $this->assertSame('/', PathGuard::parentOf('/a'));
        $this->assertSame('/a', PathGuard::parentOf('/a/b'));
    }

    public function testContains(): void
    {
        $this->assertTrue(PathGuard::contains('/', '/etc/passwd'));
        $this->assertTrue(PathGuard::contains('/a', '/a/b'));
        $this->assertTrue(PathGuard::contains('/a', '/a'));
        $this->assertFalse(PathGuard::contains('/a', '/ab'));
        $this->assertFalse(PathGuard::contains('/a/b', '/a'));
    }
}
