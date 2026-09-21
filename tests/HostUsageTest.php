<?php
declare(strict_types=1);

namespace Tests;

use app\library\System\HostUsage;
use PHPUnit\Framework\TestCase;

/**
 * Unit test HostUsage — pembacaan metrik host (CPU/memori/load/uptime) dari
 * pseudo-filesystem `/proc` untuk kartu "total VM" di halaman `/monitor`.
 *
 * Semua test memakai direktori fixture, bukan `/proc` sungguhan, supaya hasilnya
 * deterministik; perhitungan CPU diuji lewat `percentBetween()` (murni).
 */
class HostUsageTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/hostusage_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    // ==================================================================
    // percentBetween() — menghitung pemakaian dari dua sampel jiffies
    // ==================================================================

    public function testPercentBetweenComputesBusyShare(): void
    {
        // 100 jiffies berlalu, 25 di antaranya idle → 75% sibuk
        $this->assertSame(75.0, HostUsage::percentBetween(
            ['total' => 1000, 'idle' => 500],
            ['total' => 1100, 'idle' => 525]
        ));
    }

    public function testPercentBetweenReturnsNullOnEmptyWindowOrMissingSample(): void
    {
        $same = ['total' => 1000, 'idle' => 400];

        $this->assertNull(HostUsage::percentBetween($same, $same), 'jendela kosong tidak menghasilkan angka');
        $this->assertNull(HostUsage::percentBetween(null, $same));
        $this->assertNull(HostUsage::percentBetween($same, null));
    }

    public function testPercentBetweenNullOnCounterReset(): void
    {
        // sampel kedua lebih kecil (host reboot / counter reset) → tidak diketahui,
        // lebih jujur N/A daripada melaporkan 0%
        $this->assertNull(HostUsage::percentBetween(
            ['total' => 1100, 'idle' => 525],
            ['total' => 1000, 'idle' => 500]
        ));
    }

    // ==================================================================
    // cpuSample()
    // ==================================================================

    public function testCpuSampleCountsIdleAndIowaitAsIdleAndIgnoresGuestFields(): void
    {
        // user nice system idle iowait irq softirq steal guest guest_nice
        $this->write('stat', "cpu  100 0 50 200 10 0 0 0 999 999\ncpu0 1 2 3 4\n");

        $sample = HostUsage::cpuSample($this->tmp);

        // 100+0+50+200+10+0+0+0 = 360 (guest & guest_nice tidak ikut dijumlah)
        $this->assertSame(['total' => 360, 'idle' => 210], $sample);
    }

    public function testCpuSampleIgnoresPerCoreLinesAndMissingFile(): void
    {
        $this->write('stat', "cpu0 1 2 3 4 5 6 7 8\n");
        $this->assertNull(HostUsage::cpuSample($this->tmp), 'baris per-core saja bukan sampel total');

        $this->assertNull(HostUsage::cpuSample($this->tmp . '/tidak-ada'));
    }

    // ==================================================================
    // memory(), load(), uptimeSeconds(), cpuCount()
    // ==================================================================

    public function testMemoryUsesMemAvailableForUsed(): void
    {
        $this->write('meminfo', "MemTotal:       16000000 kB\nMemFree:         1000000 kB\nMemAvailable:    6000000 kB\n");

        $memory = HostUsage::memory($this->tmp);

        $this->assertNotNull($memory);
        $this->assertSame(16000000 * 1024, $memory['total']);
        $this->assertSame(6000000 * 1024, $memory['available']);
        $this->assertSame(10000000 * 1024, $memory['used']);
        $this->assertSame(62.5, $memory['percent']);
    }

    public function testMemoryFallsBackToMemFreeAndRejectsMissingTotal(): void
    {
        $this->write('meminfo', "MemTotal: 1000 kB\nMemFree: 250 kB\n");
        $this->assertSame(750 * 1024, HostUsage::memory($this->tmp)['used'] ?? null);

        $this->write('meminfo', "MemFree: 250 kB\n");
        $this->assertNull(HostUsage::memory($this->tmp));
    }

    public function testLoadParsesThreeAverages(): void
    {
        $this->write('loadavg', "2.55 2.82 2.64 1/2599 1865\n");

        $this->assertSame([2.55, 2.82, 2.64], HostUsage::load($this->tmp));
    }

    public function testUptimeUsesFirstFieldAsWholeSeconds(): void
    {
        $this->write('uptime', "22853.91 137620.17\n");

        $this->assertSame(22853, HostUsage::uptimeSeconds($this->tmp));
    }

    public function testCpuCountCountsProcessorEntries(): void
    {
        $this->write('cpuinfo', "processor\t: 0\nmodel name\t: x\nprocessor\t: 1\n");

        $this->assertSame(2, HostUsage::cpuCount($this->tmp));
    }

    // ==================================================================
    // snapshot()
    // ==================================================================

    public function testSnapshotCombinesAllFiles(): void
    {
        $this->write('stat', "cpu  100 0 50 200 10 0 0 0 0 0\n");
        $this->write('meminfo', "MemTotal: 1000 kB\nMemAvailable: 400 kB\n");
        $this->write('loadavg', "1.00 2.00 3.00 1/10 100\n");
        $this->write('uptime', "100.5 50.0\n");
        $this->write('cpuinfo', "processor\t: 0\nprocessor\t: 1\nprocessor\t: 3\n");

        $snapshot = HostUsage::snapshot($this->tmp, 0);

        $this->assertTrue($snapshot['available']);
        $this->assertSame($this->tmp, $snapshot['proc_path']);
        $this->assertSame(3, $snapshot['cpu_count']);
        $this->assertSame(1000 * 1024, $snapshot['mem_total']);
        $this->assertSame(600 * 1024, $snapshot['mem_used']);
        $this->assertSame(60.0, $snapshot['mem_percent'], 'terpakai 600 dari 1000 kB');
        $this->assertSame(1.0, $snapshot['load_1']);
        $this->assertSame(3.0, $snapshot['load_15']);
        $this->assertSame(100, $snapshot['uptime_seconds']);
        // sampling dilewati (0 mikrodetik) → tidak ada delta CPU
        $this->assertNull($snapshot['cpu_percent']);
    }

    public function testSnapshotWithoutProcYieldsNullsInsteadOfThrowing(): void
    {
        $snapshot = HostUsage::snapshot($this->tmp . '/tidak-ada', 0);

        $this->assertFalse($snapshot['available']);
        $this->assertNull($snapshot['cpu_percent']);
        $this->assertNull($snapshot['mem_total']);
        $this->assertNull($snapshot['mem_percent']);
        $this->assertNull($snapshot['load_1']);
        $this->assertNull($snapshot['uptime_seconds']);
        $this->assertNull($snapshot['cpu_count']);
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->tmp . '/' . $file, $content);
    }
}
