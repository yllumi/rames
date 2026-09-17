<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\ContainerLogs;
use PHPUnit\Framework\TestCase;

/**
 * Test ContainerLogs — pembersihan stream multiplexed Docker & normalisasi tail.
 */
class ContainerLogsTest extends TestCase
{
    /**
     * Bikin frame multiplexed ala Docker: header 8 byte + payload.
     */
    private static function frame(int $stream, string $payload): string
    {
        return pack('C4N', $stream, 0, 0, 0, strlen($payload)) . $payload;
    }

    public function testDemultiplexStripsFrameHeadersAndKeepsStdoutThenStderr(): void
    {
        $raw = self::frame(1, "baris stdout\n") . self::frame(2, "baris stderr\n");

        $this->assertSame("baris stdout\nbaris stderr\n", ContainerLogs::demultiplex($raw));
    }

    public function testDemultiplexHandlesEmptyStreamAndMultipleFrames(): void
    {
        $raw = self::frame(1, "a\n") . self::frame(1, "b\n") . self::frame(2, "c\n");

        $this->assertSame("a\nb\nc\n", ContainerLogs::demultiplex($raw));
        $this->assertSame('', ContainerLogs::demultiplex(self::frame(1, '')));
    }

    public function testPlainTextIsReturnedAsIs(): void
    {
        // container TTY → Engine mengirim teks polos (bukan frame)
        $plain = "2026-09-17T10:00:00Z halo dunia\nbaris kedua\n";

        $this->assertSame($plain, ContainerLogs::demultiplex($plain));
        $this->assertSame('pendek', ContainerLogs::demultiplex('pendek'));
        $this->assertSame('', ContainerLogs::demultiplex(''));
    }

    public function testTruncatedFrameFallsBackToRawOutput(): void
    {
        $raw = self::frame(1, "lengkap\n") . substr(self::frame(1, "terpotong\n"), 0, 10);

        $this->assertSame($raw, ContainerLogs::demultiplex($raw));
    }

    public function testNormalizeTailClampsToSafeRange(): void
    {
        $this->assertSame(ContainerLogs::DEFAULT_TAIL, ContainerLogs::normalizeTail(''));
        $this->assertSame(ContainerLogs::DEFAULT_TAIL, ContainerLogs::normalizeTail(0));
        $this->assertSame(ContainerLogs::DEFAULT_TAIL, ContainerLogs::normalizeTail(-5));
        $this->assertSame(500, ContainerLogs::normalizeTail('500'));
        $this->assertSame(ContainerLogs::MAX_TAIL, ContainerLogs::normalizeTail(999999));
    }

    public function testTailOptionsContainDefault(): void
    {
        $options = ContainerLogs::tailOptions();

        $this->assertArrayHasKey(ContainerLogs::DEFAULT_TAIL, $options);
        $this->assertSame('200 baris terakhir', $options[ContainerLogs::DEFAULT_TAIL]);
    }
}
