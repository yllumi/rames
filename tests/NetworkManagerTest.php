<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\NetworkManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Test NetworkManager — penulis override external networks per site.
 * Verifikasi: deklarasi `external: true` + tiap service di-join ke network
 * eksternal bersama network default project.
 */
class NetworkManagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-net-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/docker-compose.yml', <<<YAML
services:
  web:
    image: nginx:alpine
  worker:
    image: myapp:latest
YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/' . NetworkManager::OVERRIDE_FILE);
        @unlink($this->dir . '/docker-compose.yml');
        @rmdir($this->dir);
    }

    public function testWriteOverrideDeclaresExternalAndJoinsAllServices(): void
    {
        (new NetworkManager())->writeOverride($this->dir, ['docker-compose.yml'], ['shared-app']);

        $path = $this->dir . '/' . NetworkManager::OVERRIDE_FILE;
        $this->assertFileExists($path);

        $data = Yaml::parseFile($path);
        $this->assertSame(['external' => true], $data['networks']['shared-app'] ?? null);
        $this->assertSame(['default', 'shared-app'], $data['services']['web']['networks'] ?? null);
        $this->assertSame(['default', 'shared-app'], $data['services']['worker']['networks'] ?? null);
    }

    public function testSyncWithEmptyNetworksRemovesOverride(): void
    {
        $manager = new NetworkManager();
        $manager->writeOverride($this->dir, ['docker-compose.yml'], ['shared-app']);
        $this->assertFileExists($this->dir . '/' . NetworkManager::OVERRIDE_FILE);

        $result = $manager->sync(
            ['name' => 'myapp', 'external_networks' => []],
            $this->dir,
            ['docker-compose.yml']
        );

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->dir . '/' . NetworkManager::OVERRIDE_FILE);
    }

    public function testSyncWritesOverrideWhenNetworksPresent(): void
    {
        $result = (new NetworkManager())->sync(
            ['name' => 'myapp', 'external_networks' => ['shared-a', 'shared-b']],
            $this->dir,
            ['docker-compose.yml']
        );

        $this->assertTrue($result);
        $data = Yaml::parseFile($this->dir . '/' . NetworkManager::OVERRIDE_FILE);
        $this->assertSame(['external' => true], $data['networks']['shared-a'] ?? null);
        $this->assertSame(['external' => true], $data['networks']['shared-b'] ?? null);
        $this->assertSame(['default', 'shared-a', 'shared-b'], $data['services']['web']['networks'] ?? null);
    }

    public function testNetworksOfFiltersInvalidNames(): void
    {
        $result = (new NetworkManager())->sync(
            ['name' => 'myapp', 'external_networks' => ['valid-name', 'bad name!', '', 'dup', 'dup']],
            $this->dir,
            ['docker-compose.yml']
        );

        $this->assertTrue($result);
        $data = Yaml::parseFile($this->dir . '/' . NetworkManager::OVERRIDE_FILE);
        $this->assertArrayHasKey('valid-name', $data['networks'] ?? []);
        $this->assertArrayHasKey('dup', $data['networks'] ?? []);
        $this->assertArrayNotHasKey('bad name!', $data['networks'] ?? []);
        $this->assertSame(['default', 'valid-name', 'dup'], $data['services']['web']['networks'] ?? null);
    }
}
