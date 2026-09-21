<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\ContainerStats;
use PHPUnit\Framework\TestCase;

/**
 * Unit test ContainerStats — penafsiran `GET /containers/{id}/stats` +
 * `GET /containers/{id}/json` menjadi baris monitoring (SPECS §8d).
 *
 * Rumus yang diuji sengaja yang rawan salah: persentase CPU ala `docker stats`
 * dan pemakaian memori yang harus dikurangi page cache cgroup.
 */
class ContainerStatsTest extends TestCase
{
    /**
     * Payload ala container Linux cgroup v2 (ada `inactive_file`).
     *
     * @return array<string,mixed>
     */
    private function statsPayload(): array
    {
        return [
            'cpu_stats' => [
                'cpu_usage' => ['total_usage' => 3_000_000_000, 'percpu_usage' => [1, 2, 3, 4]],
                'system_cpu_usage' => 103_000_000_000,
                'online_cpus' => 4,
            ],
            'precpu_stats' => [
                'cpu_usage' => ['total_usage' => 3_000_000_000 - 100_000_000],
                'system_cpu_usage' => 103_000_000_000 - 1_000_000_000,
            ],
            'memory_stats' => [
                'usage' => 200_000_000,
                'limit' => 536_870_912,
                'stats' => ['inactive_file' => 50_000_000],
            ],
            'pids_stats' => ['current' => 12],
        ];
    }

    public function testMapComputesCpuMemoryAndInspectFields(): void
    {
        $inspect = [
            'State' => [
                'Status' => 'running',
                'Running' => true,
                'StartedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
                'Health' => ['Status' => 'healthy'],
            ],
            'RestartCount' => 3,
        ];

        $row = ContainerStats::map($this->statsPayload(), $inspect);

        // 100ms CPU / 1000ms system × 4 CPU = 40%
        $this->assertSame(40.0, $row['cpu_percent']);
        $this->assertSame(150_000_000, $row['mem_used'], 'usage dikurangi page cache');
        $this->assertSame(536_870_912, $row['mem_limit']);
        $this->assertSame(27.9, $row['mem_percent']);
        $this->assertSame(12, $row['pids']);
        $this->assertSame('running', $row['status']);
        $this->assertSame(3, $row['restart_count']);
        $this->assertSame('healthy', $row['health']);
        // toleransi 1 detik (batas pembulatan waktu saat test dijalankan)
        $this->assertGreaterThanOrEqual(3599, $row['uptime_seconds']);
        $this->assertLessThanOrEqual(3601, $row['uptime_seconds']);
    }

    public function testCpuPercentFallsBackToPercpuCountWhenOnlineCpusMissing(): void
    {
        $stats = $this->statsPayload();
        unset($stats['cpu_stats']['online_cpus']);
        // percpu_usage berisi 4 entri → 4 CPU tetap dipakai

        $this->assertSame(40.0, ContainerStats::cpuPercent($stats));
    }

    public function testCpuPercentNullWhenContainerRestartedBetweenSamples(): void
    {
        $stats = $this->statsPayload();
        $stats['precpu_stats']['cpu_usage']['total_usage'] = 9_000_000_000;

        $this->assertNull(ContainerStats::cpuPercent($stats));
    }

    public function testCpuPercentZeroForIdleContainer(): void
    {
        // container idle: Engine mengembalikan total_usage sama pada kedua sampel
        // (jendela tetap ada) → 0%, bukan "tidak diketahui"
        $stats = $this->statsPayload();
        $stats['precpu_stats']['cpu_usage']['total_usage'] = $stats['cpu_stats']['cpu_usage']['total_usage'];

        $this->assertSame(0.0, ContainerStats::cpuPercent($stats));
    }

    public function testCpuPercentNullWithoutPreviousSample(): void
    {
        // container baru dibuat / berhenti: `precpu_stats` tidak ada → N/A
        $stats = $this->statsPayload();
        unset($stats['precpu_stats']);

        $this->assertNull(ContainerStats::cpuPercent($stats));
    }

    public function testMemoryFallsBackToCgroupV1CacheField(): void
    {
        $stats = [
            'memory_stats' => [
                'usage' => 100_000_000,
                'stats' => ['cache' => 30_000_000],
            ],
        ];

        $this->assertSame(70_000_000, ContainerStats::memUsed($stats));
    }

    public function testMemoryCacheLargerThanUsageIsIgnored(): void
    {
        $stats = [
            'memory_stats' => [
                'usage' => 10_000_000,
                'stats' => ['inactive_file' => 99_000_000],
            ],
        ];

        $this->assertSame(10_000_000, ContainerStats::memUsed($stats));
    }

    public function testUnlimitedMemoryReportsNullLimitAndPercent(): void
    {
        $stats = [
            'memory_stats' => [
                'usage' => 100_000_000,
                'limit' => 9_223_372_036_854_771_712, // ~2^63 = tanpa limit
                'stats' => [],
            ],
        ];

        $this->assertNull(ContainerStats::memLimit($stats));
        $this->assertNull(ContainerStats::memPercent($stats));
    }

    public function testMissingStatsYieldNullsInsteadOfZeroes(): void
    {
        $row = ContainerStats::map([], []);

        $this->assertNull($row['cpu_percent']);
        $this->assertNull($row['mem_used']);
        $this->assertNull($row['mem_limit']);
        $this->assertNull($row['mem_percent']);
        $this->assertNull($row['pids']);
        $this->assertNull($row['status']);
        $this->assertNull($row['uptime_seconds']);
        $this->assertNull($row['health']);
    }

    public function testUptimeNullWhenContainerNotRunning(): void
    {
        $this->assertNull(ContainerStats::uptimeSeconds([
            'State' => ['Status' => 'exited', 'Running' => false, 'StartedAt' => '2026-01-01T00:00:00Z'],
        ]));
    }

    public function testAggregateSumsRowsAndDropsLimitWhenNotAllLimited(): void
    {
        $totals = ContainerStats::aggregate([
            ['cpu_percent' => 10.0, 'mem_used' => 100, 'mem_limit' => 1000, 'status' => 'running'],
            ['cpu_percent' => 2.25, 'mem_used' => 50, 'mem_limit' => 1000, 'status' => 'exited'],
        ]);

        $this->assertSame(12.3, $totals['cpu_percent']);
        $this->assertSame(2, $totals['containers']);
        $this->assertSame(1, $totals['running'], 'hanya status running dihitung');
        $this->assertSame(150, $totals['mem_used']);
        $this->assertSame(2000, $totals['mem_limit']);
        $this->assertSame(7.5, $totals['mem_percent']);

        $partial = ContainerStats::aggregate([
            ['cpu_percent' => 0.0, 'mem_used' => 10, 'mem_limit' => 1000],
            ['cpu_percent' => 0.0, 'mem_used' => 10, 'mem_limit' => null],
        ]);
        $this->assertNull($partial['mem_limit'], 'satu container tanpa limit → total limit tidak bermakna');
        $this->assertNull($partial['mem_percent']);
        $this->assertSame(20, $partial['mem_used']);
    }

    public function testAggregateToleratesNullMetrics(): void
    {
        $totals = ContainerStats::aggregate([
            ['cpu_percent' => null, 'mem_used' => null, 'mem_limit' => null, 'state' => 'running'],
            ['cpu_percent' => 5.0, 'mem_used' => 10, 'mem_limit' => 100, 'state' => 'running'],
        ]);

        $this->assertSame(5.0, $totals['cpu_percent']);
        $this->assertSame(10, $totals['mem_used']);
        $this->assertSame(2, $totals['running'], 'state dari listContainers boleh dipakai');
    }
}
