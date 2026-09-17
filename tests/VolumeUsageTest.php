<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\VolumeUsage;
use PHPUnit\Framework\TestCase;

/**
 * Test VolumeUsage — pemetaan & format ukuran volume dari `GET /system/df`.
 */
class VolumeUsageTest extends TestCase
{
    public function testMapExtractsSizeAndRefCount(): void
    {
        $usage = VolumeUsage::map([
            [
                'Name' => 'myapp_data',
                'UsageData' => ['Size' => 1536000, 'RefCount' => 2],
            ],
        ]);

        $this->assertSame(
            ['size' => 1536000, 'ref_count' => 2, 'size_human' => '1.536MB'],
            $usage['myapp_data'] ?? null
        );
    }

    public function testMapSkipsNamelessEntriesAndDefaultsMissingUsageData(): void
    {
        $usage = VolumeUsage::map([
            ['Name' => '', 'UsageData' => ['Size' => 10]],
            ['Name' => 'no_usage_data'],
        ]);

        $this->assertSame(['no_usage_data'], array_keys($usage));
        $this->assertSame(['size' => 0, 'ref_count' => 0, 'size_human' => '0B'], $usage['no_usage_data']);
    }

    public function testNegativeSizeMeansUnavailable(): void
    {
        // Engine melaporkan -1 bila ukuran tidak bisa dihitung.
        $usage = VolumeUsage::map([['Name' => 'busy', 'UsageData' => ['Size' => -1, 'RefCount' => 1]]]);

        $this->assertSame('N/A', $usage['busy']['size_human']);
        $this->assertSame(-1, $usage['busy']['size']);
    }

    public function testSummarizeKeepsAllowedVolumesAndTotalsOnlyKnownSizes(): void
    {
        $summary = VolumeUsage::summarize([
            ['Name' => 'mine', 'UsageData' => ['Size' => 1_500_000, 'RefCount' => 1]],
            ['Name' => 'someone_else', 'UsageData' => ['Size' => 9_000_000, 'RefCount' => 1]],
            ['Name' => 'unknown_size', 'UsageData' => ['Size' => -1, 'RefCount' => 1]],
        ], ['mine', 'unknown_size']);

        // volume milik user lain tidak ikut (aturan visibilitas di controller)
        $this->assertSame(['mine', 'unknown_size'], array_keys($summary['usage']));
        // -1 (tidak diketahui) tidak dijumlahkan
        $this->assertSame(1_500_000, $summary['total']);
        $this->assertSame('1.500MB', $summary['total_human']);
    }

    /**
     * @dataProvider humanProvider
     */
    public function testHumanFormatsLikeDockerCli(int $bytes, string $expected): void
    {
        $this->assertSame($expected, VolumeUsage::human($bytes));
    }

    /**
     * @return array<string,array{0:int,1:string}>
     */
    public static function humanProvider(): array
    {
        return [
            'nol' => [0, '0B'],
            'byte' => [999, '999B'],
            'satu kB' => [1000, '1.000kB'],
            'bulat MB' => [5_000_000, '5.000MB'],
            'pecahan MB' => [1_234_567, '1.235MB'],
            'ratusan MB' => [123_456_789, '123.5MB'],
            'GB' => [5_000_000_000, '5.000GB'],
            'TB' => [2_500_000_000_000, '2.500TB'],
        ];
    }
}
