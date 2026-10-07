<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\ListingParser;
use PHPUnit\Framework\TestCase;

/**
 * Test ListingParser — parse keluaran `stat` dari container (logika murni).
 */
class FileListingParserTest extends TestCase
{
    private function record(string $type, int $size, int $mtime, string $mode, string $name, string $link = ''): string
    {
        return $type . ListingParser::US . $size . ListingParser::US . $mtime . ListingParser::US
            . $mode . ListingParser::US . $name . ListingParser::US . $link . ListingParser::RS;
    }

    public function testParseMapsFields(): void
    {
        $raw = $this->record('regular file', 123, 1700000000, '-rw-r--r--', '/etc/a.txt')
            . $this->record('directory', 4096, 1700000001, 'drwxr-xr-x', '/etc/conf.d')
            . $this->record('symbolic link', 7, 1700000002, 'lrwxrwxrwx', '/etc/link', '/etc/a.txt');

        $entries = ListingParser::parse($raw, '/etc');

        // direktori lebih dulu
        $this->assertSame('conf.d', $entries[0]['name']);
        $this->assertSame('dir', $entries[0]['type']);
        $this->assertSame(4096, $entries[0]['size']);
        $this->assertSame(1700000001, $entries[0]['mtime']);
        $this->assertSame('drwxr-xr-x', $entries[0]['mode']);

        $this->assertSame('a.txt', $entries[1]['name']);
        $this->assertSame('file', $entries[1]['type']);
        $this->assertSame(123, $entries[1]['size']);

        $this->assertSame('link', $entries[2]['type']);
        $this->assertSame('/etc/a.txt', $entries[2]['link']);
    }

    public function testParseSortsCaseInsensitivelyAndDirectoriesFirst(): void
    {
        $raw = $this->record('regular file', 1, 1, '-rw-', '/d/beta')
            . $this->record('regular file', 1, 1, '-rw-', '/d/Alpha')
            . $this->record('directory', 1, 1, 'drwx', '/d/zeta')
            . $this->record('directory', 1, 1, 'drwx', '/d/alpha');

        $names = array_map(
            static fn (array $e): string => $e['name'],
            ListingParser::parse($raw, '/d')
        );

        $this->assertSame(['alpha', 'zeta', 'Alpha', 'beta'], $names);
    }

    public function testParseHandlesRootAndEmpty(): void
    {
        $raw = $this->record('regular file', 5, 10, '-rw-r--r--', '//etc');
        $entries = ListingParser::parse($raw, '/');
        $this->assertCount(1, $entries);
        $this->assertSame('etc', $entries[0]['name']);

        $this->assertSame([], ListingParser::parse('', '/etc'));
    }

    public function testTypeOfAcrossImplementations(): void
    {
        $this->assertSame('dir', ListingParser::typeOf('directory'));
        $this->assertSame('dir', ListingParser::typeOf('sticky directory'));
        $this->assertSame('file', ListingParser::typeOf('regular file'));
        $this->assertSame('file', ListingParser::typeOf('regular empty file'));
        $this->assertSame('link', ListingParser::typeOf('symbolic link'));
        $this->assertSame('other', ListingParser::typeOf('character special file'));
        $this->assertSame('other', ListingParser::typeOf('fifo'));
        $this->assertSame('other', ListingParser::typeOf('socket'));
    }
}
