<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\AppContainers;
use PHPUnit\Framework\TestCase;

/**
 * Test AppContainers — validasi kepemilikan container & pemilihan container default.
 *
 * Hanya jalur data-tersimpan yang diuji (jalur fallback memanggil Docker Engine).
 */
class AppContainersTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function app(): array
    {
        return [
            'id' => 'app1',
            'name' => 'myapp',
            'primary_service' => 'web',
            'containers' => [
                ['service_name' => 'db', 'container_name' => 'myapp-db-1'],
                ['service_name' => 'web', 'container_name' => 'myapp-web-1'],
            ],
        ];
    }

    public function testResolveAcceptsContainerOwnedByApp(): void
    {
        $this->assertSame('myapp-web-1', AppContainers::resolve(self::app(), 'myapp-web-1'));
    }

    public function testResolveRejectsEmptyOrSuspiciousNames(): void
    {
        foreach (['', ' lain', 'lain ', "lain\n", 'lain/x', 'lain\\x', 'lain x'] as $bad) {
            $this->assertNull(AppContainers::resolve(self::app(), $bad), 'harus ditolak: ' . var_export($bad, true));
        }
    }

    public function testDefaultContainerPrefersPrimaryService(): void
    {
        $app = self::app();
        $this->assertSame('myapp-web-1', AppContainers::defaultContainer($app));

        // primary service tidak ada di daftar container → container pertama
        $app['primary_service'] = 'worker';
        $this->assertSame('myapp-db-1', AppContainers::defaultContainer($app));

        // tanpa primary service → container pertama
        $app['primary_service'] = '';
        $this->assertSame('myapp-db-1', AppContainers::defaultContainer($app));
    }

    public function testDefaultContainerIsNullWhenNoContainers(): void
    {
        $this->assertNull(AppContainers::defaultContainer(['name' => 'myapp', 'containers' => []]));
        $this->assertNull(AppContainers::defaultContainer(['name' => 'myapp']));
        // entri tanpa nama container diabaikan
        $this->assertNull(AppContainers::defaultContainer([
            'name' => 'myapp',
            'containers' => [['service_name' => 'web', 'container_name' => '']],
        ]));
    }
}
