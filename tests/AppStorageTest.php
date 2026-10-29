<?php
declare(strict_types=1);

namespace Tests;

use app\library\Monitor\AppStorage;
use PHPUnit\Framework\TestCase;

/**
 * `Monitor\AppStorage` — ringkasan storage satu app dari respons `GET /system/df`.
 *
 * Yang dikunci: filter **label** compose project (bukan tebakan nama volume),
 * penjumlahan volume (`UsageData.Size`) + writable layer container (`SizeRw`),
 * dan perlakuan ukuran negatif (Engine tidak bisa menghitung) yang tetap
 * dilaporkan dengan `size_human` "N/A" tetapi **tidak** dijumlahkan.
 */
class AppStorageTest extends TestCase
{
    public function testSummarizeFiltersByComposeProjectLabelAndSumsVolumeAndContainer(): void
    {
        $df = [
            'Volumes' => [
                $this->volume('demo_data', 'demo', 1000),
                $this->volume('other_data', 'other', 9000),
                $this->volume('unlabelled', '', 7000),
            ],
            'Containers' => [
                $this->container('demo-web-1', 'demo', 2000),
                $this->container('other-web-1', 'other', 8000),
                $this->container('loose', '', 6000),
            ],
        ];

        $summary = AppStorage::summarize($df, 'demo');

        $this->assertTrue($summary['available']);
        $this->assertSame(1000, $summary['volumes_bytes']);
        $this->assertSame(2000, $summary['containers_bytes']);
        $this->assertSame(3000, $summary['total_bytes']);
        $this->assertSame('3.000kB', $summary['total_human']);
        $this->assertNull($summary['error']);

        $this->assertSame([
            ['name' => 'demo_data', 'size' => 1000, 'size_human' => '1.000kB'],
        ], $summary['volumes']);
        $this->assertSame([
            ['name' => 'demo-web-1', 'size' => 2000, 'size_human' => '2.000kB'],
        ], $summary['containers']);
    }

    public function testNegativeSizesAreReportedAsNaAndNotSummed(): void
    {
        $df = [
            'Volumes' => [
                $this->volume('demo_data', 'demo', 4096),
                $this->volume('demo_cache', 'demo', -1),
            ],
            'Containers' => [
                $this->container('demo-web-1', 'demo', 512),
                $this->container('demo-worker-1', 'demo', -1),
            ],
        ];

        $summary = AppStorage::summarize($df, 'demo');

        $this->assertSame(4096, $summary['volumes_bytes'], 'volume negatif tidak dijumlahkan');
        $this->assertSame(512, $summary['containers_bytes'], 'container negatif tidak dijumlahkan');
        $this->assertSame(4608, $summary['total_bytes']);

        $sizes = array_column($summary['volumes'], 'size_human', 'name');
        $this->assertSame('N/A', $sizes['demo_cache'], 'ukuran negatif tetap dilaporkan sebagai N/A');
        $this->assertSame(-1, array_column($summary['volumes'], 'size', 'name')['demo_cache']);

        $containerSizes = array_column($summary['containers'], 'size_human', 'name');
        $this->assertSame('N/A', $containerSizes['demo-worker-1']);
    }

    public function testWithoutMatchingProjectReturnsZeroAndAvailable(): void
    {
        $summary = AppStorage::summarize([
            'Volumes' => [$this->volume('other_data', 'other', 500)],
            'Containers' => [$this->container('other-web-1', 'other', 700)],
        ], 'demo');

        $this->assertTrue($summary['available']);
        $this->assertSame(0, $summary['volumes_bytes']);
        $this->assertSame(0, $summary['containers_bytes']);
        $this->assertSame(0, $summary['total_bytes']);
        $this->assertSame('0B', $summary['total_human']);
        $this->assertSame([], $summary['volumes']);
        $this->assertSame([], $summary['containers']);
        $this->assertNull($summary['error']);
    }

    public function testMissingSizesFallBackToZeroAndContainerNameFallsBackToId(): void
    {
        $summary = AppStorage::summarize([
            'Volumes' => [
                ['Name' => 'demo_data', 'Labels' => ['com.docker.compose.project' => 'demo']],
            ],
            'Containers' => [
                [
                    'Id' => 'abcdef0123456789',
                    'Names' => [],
                    'Labels' => ['com.docker.compose.project' => 'demo'],
                ],
            ],
        ], 'demo');

        $this->assertSame(0, $summary['volumes_bytes']);
        $this->assertSame(0, $summary['containers_bytes']);
        $this->assertSame('0B', $summary['volumes'][0]['size_human']);
        $this->assertSame('abcdef012345', $summary['containers'][0]['name'], 'cadangan: 12 karakter id');
        $this->assertSame(0, $summary['containers'][0]['size']);
    }

    public function testEmptyProjectDoesNotAccidentallyMatchUnlabelledRows(): void
    {
        $summary = AppStorage::summarize([
            'Volumes' => [$this->volume('loose', '', 123)],
            'Containers' => [$this->container('loose-1', '', 456)],
        ], '   ');

        $this->assertTrue($summary['available']);
        $this->assertSame(0, $summary['total_bytes']);
        $this->assertSame([], $summary['volumes']);
        $this->assertSame([], $summary['containers']);
    }

    public function testHumanUsesSiUnitsLikeDockerSystemDf(): void
    {
        $this->assertSame('0B', AppStorage::human(0));
        $this->assertSame('999B', AppStorage::human(999));
        $this->assertSame('1.000kB', AppStorage::human(1000));
        $this->assertSame('1.235MB', AppStorage::human(1234567));
        $this->assertSame('1.000GB', AppStorage::human(1000000000));
        $this->assertSame('N/A', AppStorage::human(-1));
    }

    public function testUnavailableBlockCarriesZerosAndErrorMessage(): void
    {
        $block = AppStorage::unavailable('Versi agent belum mendukung ukuran storage.');

        $this->assertFalse($block['available']);
        $this->assertSame(0, $block['total_bytes']);
        $this->assertSame('0B', $block['total_human']);
        $this->assertSame([], $block['volumes']);
        $this->assertSame([], $block['containers']);
        $this->assertSame('Versi agent belum mendukung ukuran storage.', $block['error']);
    }

    /** @return array<string,mixed> */
    private function volume(string $name, string $project, int $size): array
    {
        return [
            'Name' => $name,
            'Labels' => $project === '' ? [] : ['com.docker.compose.project' => $project],
            'UsageData' => ['Size' => $size, 'RefCount' => 1],
        ];
    }

    /** @return array<string,mixed> */
    private function container(string $name, string $project, int $size): array
    {
        return [
            'Id' => str_pad($name, 64, '0'),
            'Names' => ['/' . $name],
            'Labels' => $project === '' ? [] : ['com.docker.compose.project' => $project],
            'SizeRw' => $size,
        ];
    }
}
