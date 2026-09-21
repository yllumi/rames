<?php
declare(strict_types=1);

namespace Tests;

use app\library\Monitor\ResourceCollector;
use PHPUnit\Framework\TestCase;

/**
 * Unit test ResourceCollector — penyaringan container & perakitan baris
 * monitoring (SPECS §8d).
 *
 * Fokus: aturan visibilitas. Container milik app yang tidak boleh diakses user
 * TIDAK boleh ikut terkirim ke UI, dan container "eksternal" (di luar compose
 * project dashboard) hanya untuk admin.
 */
class ResourceCollectorTest extends TestCase
{
    /**
     * @param array<string,string> $labels
     * @return array<string,mixed>
     */
    private function container(string $id, string $name, array $labels, string $image = 'nginx:alpine'): array
    {
        return [
            'Id' => $id,
            'Names' => ['/' . $name],
            'Image' => $image,
            'State' => 'running',
            'Labels' => $labels,
        ];
    }

    public function testVisibleContainersKeepsOnlyAccessibleProjectsForNonAdmin(): void
    {
        $raw = [
            $this->container('a', 'mine-web-1', ['com.docker.compose.project' => 'mine', 'com.docker.compose.service' => 'web']),
            $this->container('b', 'other-web-1', ['com.docker.compose.project' => 'other', 'com.docker.compose.service' => 'web']),
            $this->container('c', 'lonely', []),
        ];

        $visible = ResourceCollector::visibleContainers($raw, ['mine' => ['id' => '1', 'name' => 'mine']], false);

        $this->assertSame(['a'], array_column($visible, 'Id'));
    }

    public function testVisibleContainersIncludesExternalOnlyForAdmin(): void
    {
        $raw = [
            $this->container('a', 'mine-web-1', ['com.docker.compose.project' => 'mine']),
            $this->container('b', 'other-web-1', ['com.docker.compose.project' => 'other']),
            $this->container('c', 'lonely', []),
        ];

        $visible = ResourceCollector::visibleContainers($raw, ['mine' => ['id' => '1', 'name' => 'mine']], true);

        // admin: hanya container eksternal (tanpa project) yang ikut; "other" tetap
        // tidak terkirim karena bukan app dashboard (tidak ada di apps.json)
        $this->assertSame(['a', 'c'], array_column($visible, 'Id'));
    }

    public function testProjectIndexSkipsAppsWithoutName(): void
    {
        $index = ResourceCollector::projectIndex([
            ['id' => '1', 'name' => 'mine'],
            ['id' => '2'],
        ]);

        $this->assertSame(['mine' => ['id' => '1', 'name' => 'mine']], $index);
    }

    public function testRowsMergeEngineDataAndMarkManagedAppsFirst(): void
    {
        $raw = [
            $this->container('external-id-123456', 'redis', [], 'redis:7-alpine'),
            $this->container('managed-id-12345', 'mine-web-1', [
                'com.docker.compose.project' => 'mine',
                'com.docker.compose.service' => 'web',
            ]),
        ];
        $projects = ['mine' => ['id' => 'app-1', 'name' => 'mine']];
        $overview = [
            'managed-id-12345' => [
                'stats' => [
                    'cpu_stats' => [
                        'cpu_usage' => ['total_usage' => 500_000_000],
                        'system_cpu_usage' => 1_000_000_000,
                        'online_cpus' => 1,
                    ],
                    'precpu_stats' => [
                        'cpu_usage' => ['total_usage' => 0],
                        'system_cpu_usage' => 0,
                    ],
                    'memory_stats' => ['usage' => 25_000_000, 'limit' => 100_000_000, 'stats' => []],
                ],
                'inspect' => ['State' => ['Status' => 'running', 'Running' => false], 'RestartCount' => 1],
                'error' => null,
            ],
            // container eksternal: Engine menolak (mis. 409) → stats/inspect kosong
            'external-id-123456' => ['stats' => null, 'inspect' => null, 'error' => 'HTTP 409 Conflict'],
        ];

        $rows = ResourceCollector::rows($raw, $overview, $projects);

        $this->assertCount(2, $rows);
        // container app dashboard tampil lebih dulu
        $this->assertSame('mine-web-1', $rows[0]['name']);
        $this->assertSame('mine', $rows[0]['project']);
        $this->assertSame('web', $rows[0]['service']);
        $this->assertSame('app-1', $rows[0]['app_id']);
        $this->assertTrue($rows[0]['managed']);
        $this->assertSame(50.0, $rows[0]['cpu_percent'], 'CPU delta 0,5× dari jendela 1 CPU');
        $this->assertSame(25_000_000, $rows[0]['mem_used']);
        $this->assertSame(25.0, $rows[0]['mem_percent']);
        $this->assertSame('running', $rows[0]['status']);
        $this->assertSame(1, $rows[0]['restart_count']);
        $this->assertSame('managed-id-1', $rows[0]['id'], 'ID dipendekkan untuk tampilan');

        $external = $rows[1];
        $this->assertFalse($external['managed']);
        $this->assertNull($external['app_id']);
        $this->assertSame('', $external['project']);
        $this->assertNull($external['cpu_percent']);
        $this->assertSame('HTTP 409 Conflict', $external['error']);
        $this->assertSame('redis', $external['name']);
    }

    public function testRowsFallBackToShortIdWhenContainerHasNoName(): void
    {
        $rows = ResourceCollector::rows([
            ['Id' => 'abcdef0123456789', 'Image' => 'x', 'State' => 'created', 'Labels' => []],
        ], [], []);

        $this->assertSame('abcdef012345', $rows[0]['name']);
        $this->assertFalse($rows[0]['managed']);
        $this->assertSame('created', $rows[0]['state']);
    }

    /**
     * Snapshot host untuk polling `/api/monitor/host` — dipanggil berkala tanpa
     * menyentuh Docker Engine, jadi harus tahan baca `/proc` yang tidak lengkap.
     */
    public function testHostSnapshotReadsProcWithoutEngine(): void
    {
        $tmp = sys_get_temp_dir() . '/rcproc_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/meminfo', "MemTotal: 2000 kB\nMemAvailable: 500 kB\n");
        file_put_contents($tmp . '/loadavg', "0.50 0.40 0.30 1/100 999\n");
        file_put_contents($tmp . '/uptime', "42.5 10.0\n");
        file_put_contents($tmp . '/stat', "cpu  10 0 10 80 0 0 0 0 0 0\n");

        try {
            $host = ResourceCollector::hostSnapshot($tmp);

            $this->assertTrue($host['available']);
            $this->assertSame(2000 * 1024, $host['mem_total']);
            $this->assertSame(1500 * 1024, $host['mem_used']);
            $this->assertSame(75.0, $host['mem_percent']);
            $this->assertSame(0.5, $host['load_1']);
            $this->assertSame(42, $host['uptime_seconds']);
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmp);
        }
    }
}
