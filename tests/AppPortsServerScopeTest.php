<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\AppPorts;
use PHPUnit\Framework\TestCase;

/**
 * Scope **server** pada {@see AppPorts} (multi-server F1): port host unik per
 * server. Tanpa scope (`null`) perilakunya harus identik dengan sebelum
 * multi-server — himpunan port seluruh app (fail-safe untuk pemanggil lama).
 */
class AppPortsServerScopeTest extends TestCase
{
    /**
     * Dua app dengan **host port sama** (30001) di server berbeda + satu app
     * lokal lain di server yang sama dengan app pertama.
     *
     * @return array<int,array>
     */
    private function apps(): array
    {
        return [
            [
                'id' => 'app-local-a',
                'name' => 'alpha',
                'server_id' => 'local',
                'containers' => [[
                    'service_name' => 'web',
                    'ports' => [
                        ['host' => 30001, 'container' => 8080],
                        ['host' => 30002, 'container' => 9119],
                    ],
                ]],
            ],
            [
                'id' => 'app-vps-1',
                'name' => 'alpha', // nama sama boleh: server berbeda
                'server_id' => 'vps-1',
                'containers' => [[
                    'service_name' => 'web',
                    'ports' => [['host' => 30001, 'container' => 8080]],
                ]],
            ],
            [
                'id' => 'app-local-b',
                'name' => 'beta', // tanpa server_id ⇒ server lokal
                'containers' => [[
                    'service_name' => 'web',
                    'internal_port' => 8080,
                    'host_port' => 30003,
                    'ports' => [],
                ]],
            ],
        ];
    }

    // ==================================================================
    // onServer() — filter
    // ==================================================================

    public function testOnServerMenyaringPerServer(): void
    {
        $apps = $this->apps();

        $this->assertSame(
            ['app-local-a', 'app-local-b'],
            array_column(AppPorts::onServer($apps, 'local'), 'id')
        );
        $this->assertSame(['app-vps-1'], array_column(AppPorts::onServer($apps, 'vps-1'), 'id'));
        $this->assertSame([], AppPorts::onServer($apps, 'vps-9'));
    }

    public function testOnServerTanpaScopeMengembalikanSemua(): void
    {
        $apps = $this->apps();

        $this->assertSame(
            ['app-local-a', 'app-vps-1', 'app-local-b'],
            array_column(AppPorts::onServer($apps), 'id'),
            'null = semua server (perilaku lama)'
        );
    }

    // ==================================================================
    // usedOn() / allOnServer() — port per server
    // ==================================================================

    public function testUsedOnPerServerHanyaPortServerItu(): void
    {
        $apps = $this->apps();

        $this->assertSame([30001, 30002, 30003], AppPorts::usedOn($apps, 'local'));
        $this->assertSame([30001], AppPorts::usedOn($apps, 'vps-1'));
        $this->assertSame([], AppPorts::usedOn($apps, 'vps-9'));
    }

    public function testUsedOnTanpaScopeSamaDenganPerilakuLama(): void
    {
        $apps = $this->apps();

        // 30001 muncul di dua server → terdedupe (himpunan, seperti perilaku lama).
        $this->assertSame([30001, 30002, 30003], AppPorts::usedOn($apps));
        $this->assertSame(AppPorts::usedOn($apps), AppPorts::usedOn($apps, null));
    }

    public function testUsedOnMendedupePortSamaDalamSatuApp(): void
    {
        // Docker Engine mengembalikan entri per alamat IP (IPv4 + IPv6): port
        // sama tidak boleh muncul dua kali.
        $apps = [[
            'id' => 'a',
            'name' => 'dual',
            'server_id' => 'local',
            'containers' => [[
                'service_name' => 'web',
                'ports' => [
                    ['host' => 30001, 'container' => 80],
                    ['host' => 30001, 'container' => 80],
                ],
            ]],
        ]];

        $this->assertSame([30001], AppPorts::usedOn($apps, 'local'));
    }

    public function testAllOnServerMembawaIdentitasAppDanServer(): void
    {
        $rows = AppPorts::allOnServer($this->apps(), 'vps-1');

        $this->assertSame([[
            'id' => 'app-vps-1',
            'name' => 'alpha',
            'server_id' => 'vps-1',
            'service' => 'web',
            'container' => 8080,
            'host' => 30001,
        ]], $rows);
    }

    public function testAllOnServerMenghormatiFieldLamaHostPort(): void
    {
        // App lokal kedua memakai field lama (`host_port`/`internal_port`).
        $rows = AppPorts::allOnServer($this->apps(), 'local');

        $this->assertContains(
            ['id' => 'app-local-b', 'name' => 'beta', 'server_id' => 'local',
                'service' => 'web', 'container' => 8080, 'host' => 30003],
            $rows
        );
    }

    public function testAllOnServerMenampilkanSemuaPortBukanHanyaPrimary(): void
    {
        $apps = $this->apps();
        $apps[0]['primary_service'] = 'web';
        $apps[0]['primary_port'] = 9119;

        $hosts = array_column(AppPorts::allOnServer($apps, 'local'), 'host');
        $this->assertSame([30001, 30002, 30003], $hosts);
        // primary tetap dihormati untuk pemilihan target proxy:
        $this->assertSame(9119, AppPorts::proxiedContainerPort($apps[0]));
        $this->assertSame(30002, AppPorts::primaryHostPort($apps[0]));
    }

    // ==================================================================
    // conflictsOn() — port sama: bentrok hanya bila server sama
    // ==================================================================

    public function testPortSamaDiServerBerbedaTidakKonflik(): void
    {
        $apps = $this->apps();

        $this->assertNull(AppPorts::conflictsOn($apps, 30001, 'vps-1', 'app-vps-1'));
        $this->assertNull(AppPorts::conflictsOn($apps, 30001, 'local', 'app-local-a'));
    }

    public function testPortSamaDiServerSamaTetapKonflik(): void
    {
        $apps = $this->apps();
        $apps[] = [
            'id' => 'app-local-c',
            'name' => 'gamma',
            'server_id' => 'local',
            'containers' => [[
                'service_name' => 'web',
                'ports' => [['host' => 30001, 'container' => 5000]],
            ]],
        ];

        $conflict = AppPorts::conflictsOn($apps, 30001, 'local', 'app-local-a');
        $this->assertNotNull($conflict);
        $this->assertSame('app-local-c', $conflict['id']);

        // Tanpa scope: perilaku lama (mencari lintas server, yang pertama menang).
        $this->assertSame('app-vps-1', AppPorts::conflictsOn($apps, 30001, null, 'app-local-a')['id']);
    }

    public function testConflictsOnMengabaikanPortNolAtauNegatif(): void
    {
        $apps = [[
            'id' => 'a',
            'name' => 'noport',
            'server_id' => 'local',
            'containers' => [['service_name' => 'db', 'ports' => [['host' => 0, 'container' => 3306]]]],
        ]];

        $this->assertNull(AppPorts::conflictsOn($apps, 0, 'local'));
        $this->assertNull(AppPorts::conflictsOn($apps, -1, 'local'));
    }

    // ==================================================================
    // Regresi: API lama tidak berubah
    // ==================================================================

    public function testApiLamaTidakBerubah(): void
    {
        $app = $this->apps()[0];

        $this->assertSame([
            ['service' => 'web', 'container' => 8080, 'host' => 30001],
            ['service' => 'web', 'container' => 9119, 'host' => 30002],
        ], AppPorts::all($app));
        $this->assertTrue(AppPorts::hasHostPort($app));
        $this->assertSame(30001, AppPorts::hostPortFor($app, 8080));
        $this->assertSame(8080, AppPorts::proxiedContainerPort($app));
        $this->assertSame(30001, AppPorts::primaryHostPort($app));
    }
}
