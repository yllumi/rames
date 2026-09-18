<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\AppPorts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test AppPorts — daftar port app (semua port publish) & penentuan port
 * yang di-proxy Nginx ke domain (menghormati `primary_port` yang dipilih user,
 * dengan fallback perilaku lama untuk app tanpa `primary_port`).
 */
class AppPortsTest extends TestCase
{
    /**
     * App dengan satu service mempublikasikan dua port (web 9119 + gateway 8642).
     */
    private function multiPortApp(array $overrides = []): array
    {
        return array_merge([
            'name' => 'hermesan',
            'primary_service' => 'hermes',
            'primary_port' => 9119,
            'containers' => [
                [
                    'service_name' => 'hermes',
                    'container_name' => 'hermesan',
                    'internal_port' => 8642,
                    'host_port' => 30000,
                    'ports' => [
                        ['host' => 30000, 'container' => 8642],
                        ['host' => 30001, 'container' => 9119],
                    ],
                    'status' => 'running',
                ],
            ],
        ], $overrides);
    }

    public function testAllFlattensPortsOfEveryContainer(): void
    {
        $app = [
            'containers' => [
                [
                    'service_name' => 'web',
                    'internal_port' => 8080,
                    'host_port' => 30010,
                    'ports' => [['host' => 30010, 'container' => 8080], ['host' => 30011, 'container' => 9119]],
                ],
                [
                    'service_name' => 'worker',
                    'internal_port' => null,
                    'host_port' => null,
                    'ports' => [],
                ],
            ],
        ];

        $this->assertSame([
            ['service' => 'web', 'container' => 8080, 'host' => 30010],
            ['service' => 'web', 'container' => 9119, 'host' => 30011],
        ], AppPorts::all($app));
    }

    public function testForContainerDedupesDualStackEntries(): void
    {
        // Bentuk nyata dari Docker Engine: satu entri per IP (IPv4 + IPv6)
        $container = [
            'service_name' => 'hermes',
            'internal_port' => 8642,
            'host_port' => 8642,
            'ports' => [
                ['host' => 8642, 'container' => 8642],
                ['host' => 8642, 'container' => 8642],
                ['host' => 9119, 'container' => 9119],
                ['host' => 9119, 'container' => 9119],
            ],
        ];

        $this->assertSame([
            ['host' => 8642, 'container' => 8642],
            ['host' => 9119, 'container' => 9119],
        ], AppPorts::forContainer($container));
    }

    public function testForContainerKeepsDistinctHostPortsForSameContainerPort(): void
    {
        $container = [
            'ports' => [
                ['host' => 30000, 'container' => 8642],
                ['host' => 30005, 'container' => 8642],
            ],
        ];

        $this->assertSame([
            ['host' => 30000, 'container' => 8642],
            ['host' => 30005, 'container' => 8642],
        ], AppPorts::forContainer($container));
    }

    public function testAllDedupesDuplicatedStoredPorts(): void
    {
        $app = $this->multiPortApp([
            'containers' => [[
                'service_name' => 'hermes',
                'internal_port' => 8642,
                'host_port' => 8642,
                'ports' => [
                    ['host' => 8642, 'container' => 8642],
                    ['host' => 8642, 'container' => 8642],
                    ['host' => 9119, 'container' => 9119],
                    ['host' => 9119, 'container' => 9119],
                ],
            ]],
        ]);

        $this->assertSame([
            ['service' => 'hermes', 'container' => 8642, 'host' => 8642],
            ['service' => 'hermes', 'container' => 9119, 'host' => 9119],
        ], AppPorts::all($app));
        // 9119 tetap dikenali sebagai port yang di-proxy
        $this->assertSame(9119, AppPorts::proxiedContainerPort($app));
        $this->assertSame(9119, AppPorts::primaryHostPort($app));
    }

    public function testAllSkipsEmptyPortEntries(): void
    {
        $app = [
            'containers' => [
                ['service_name' => 'web', 'ports' => [['host' => 0, 'container' => 0], ['host' => 30001, 'container' => 80]]],
            ],
        ];

        $this->assertSame([['service' => 'web', 'container' => 80, 'host' => 30001]], AppPorts::all($app));
    }

    public function testAllFallsBackToLegacyPortFields(): void
    {
        // Data app lama: tanpa field `ports`
        $app = [
            'containers' => [
                ['service_name' => 'web', 'internal_port' => 8080, 'host_port' => 30001, 'status' => 'running'],
            ],
        ];

        $this->assertSame(
            [['service' => 'web', 'container' => 8080, 'host' => 30001]],
            AppPorts::all($app)
        );
    }

    public function testAllRecoversHostPortFromLegacyFieldsWhenPortHasNoHost(): void
    {
        $app = [
            'containers' => [
                [
                    'service_name' => 'web',
                    'internal_port' => 9119,
                    'host_port' => 30001,
                    'ports' => [['host' => 0, 'container' => 9119]],
                ],
            ],
        ];

        $this->assertSame([['service' => 'web', 'container' => 9119, 'host' => 30001]], AppPorts::all($app));
    }

    public function testProxiedContainerPortUsesSelectedPort(): void
    {
        $this->assertSame(9119, AppPorts::proxiedContainerPort($this->multiPortApp()));
    }

    public function testProxiedContainerPortFallsBackToFirstPortWithoutSelection(): void
    {
        $app = $this->multiPortApp(['primary_port' => null]);
        $this->assertSame(8642, AppPorts::proxiedContainerPort($app));
    }

    public function testProxiedContainerPortFallsBackWhenSelectedPortIsGone(): void
    {
        // port pilihan tidak lagi dipublikasikan (compose berubah) → port pertama
        $this->assertSame(8642, AppPorts::proxiedContainerPort($this->multiPortApp(['primary_port' => 1234])));
    }

    public function testProxiedContainerPortIgnoresPortOwnedByOtherService(): void
    {
        $app = [
            'primary_service' => 'web',
            'primary_port' => 9119,
            'containers' => [
                ['service_name' => 'web', 'internal_port' => 80, 'host_port' => 30001, 'ports' => [['host' => 30001, 'container' => 80]]],
                ['service_name' => 'api', 'internal_port' => null, 'host_port' => null, 'ports' => [['host' => 30002, 'container' => 9119]]],
            ],
        ];

        // 9119 milik service lain → bukan kandidat; jatuh ke port service primary
        $this->assertSame(80, AppPorts::proxiedContainerPort($app));
        $this->assertSame(30001, AppPorts::primaryHostPort($app));
    }

    public function testPrimaryHostPortReturnsHostOfSelectedPort(): void
    {
        $this->assertSame(30001, AppPorts::primaryHostPort($this->multiPortApp()));
        $this->assertSame(30000, AppPorts::primaryHostPort($this->multiPortApp(['primary_port' => null])));
    }

    public function testPrimaryHostPortFallsBackWhenSelectedPortHasNoHost(): void
    {
        $app = $this->multiPortApp([
            'containers' => [
                [
                    'service_name' => 'hermes',
                    'internal_port' => 8642,
                    'host_port' => 30000,
                    'ports' => [
                        ['host' => 30000, 'container' => 8642],
                        ['host' => 0, 'container' => 9119], // tidak di-publish ke host
                    ],
                ],
            ],
        ]);

        $this->assertSame(30000, AppPorts::primaryHostPort($app));
    }

    public function testPrimaryHostPortThrowsWhenNoContainers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/host port/');
        AppPorts::primaryHostPort(['name' => 'kosong', 'containers' => []]);
    }

    public function testHostPortFor(): void
    {
        $app = $this->multiPortApp();
        $this->assertSame(30001, AppPorts::hostPortFor($app, 9119));
        $this->assertSame(0, AppPorts::hostPortFor($app, 1234));
    }
}
