<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\BuildEntitlements;
use app\library\Deploy\EnvManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit test BuildEntitlements — override `build.entitlements: [network.host]`
 * yang ditulis tepat sebelum build. Tanpa override ini BuildKit menolak app
 * ber-`build.network: host` / `RUN --network=host` dengan
 * `additional privileges requested: pass "--allow=network.host"`, padahal
 * legacy builder dulu mengizinkannya (regresi setelah buildx dipasang).
 */
class BuildEntitlementsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/buildent_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testServiceDenganBuildNetworkHostMendapatEntitlement(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          webman:
            build:
              context: .
              network: host
          db:
            image: mariadb:11
        YAML);

        $this->assertTrue(BuildEntitlements::sync($this->tmp, ['docker-compose.yml']));

        $data = Yaml::parseFile($this->tmp . '/' . BuildEntitlements::OVERRIDE_FILE);
        $this->assertSame(
            ['webman' => ['build' => ['entitlements' => ['network.host']]]],
            $data['services']
        );
    }

    public function testServiceTanpaBuildTidakDiberiBlokBuild(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          db:
            image: mariadb:11
        YAML);

        $this->assertFalse(BuildEntitlements::sync($this->tmp, ['docker-compose.yml']));
        $this->assertFileDoesNotExist($this->tmp . '/' . BuildEntitlements::OVERRIDE_FILE);
    }

    public function testBuildBentukStringJugaDikenali(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          app:
            build: .
        YAML);

        $this->assertTrue(BuildEntitlements::sync($this->tmp, ['docker-compose.yml']));
        $this->assertSame(
            ['app'],
            BuildEntitlements::servicesMissingEntitlement($this->tmp, ['docker-compose.yml'])
        );
    }

    public function testEntitlementYangSudahDideklarasikanTidakDiduplikasi(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          app:
            build:
              context: .
              entitlements: [network.host]
        YAML);

        $this->assertFalse(BuildEntitlements::sync($this->tmp, ['docker-compose.yml']));
        $this->assertFileDoesNotExist($this->tmp . '/' . BuildEntitlements::OVERRIDE_FILE);
    }

    public function testOverrideLamaDihapusSaatTidakAdaServiceBuild(): void
    {
        file_put_contents($this->tmp . '/' . BuildEntitlements::OVERRIDE_FILE, "services: {}\n");
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          db:
            image: mariadb:11
        YAML);

        $this->assertFalse(BuildEntitlements::sync($this->tmp, ['docker-compose.yml']));
        $this->assertFileDoesNotExist($this->tmp . '/' . BuildEntitlements::OVERRIDE_FILE);
    }

    public function testBuildDiFileOverrideRepoIkutDikenali(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          app:
            image: example/app:latest
        YAML);
        file_put_contents($this->tmp . '/docker-compose.override.yml', <<<'YAML'
        services:
          app:
            build:
              context: .
              network: host
        YAML);

        $files = ['docker-compose.yml', 'docker-compose.override.yml'];
        $this->assertSame(['app'], BuildEntitlements::servicesMissingEntitlement($this->tmp, $files));
    }

    public function testFileOverrideSendiriTidakMemengaruhiKeputusan(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          db:
            image: mariadb:11
        YAML);
        // Override sisa dari build sebelumnya: tidak boleh membuat service "hantu".
        file_put_contents($this->tmp . '/' . BuildEntitlements::OVERRIDE_FILE, <<<'YAML'
        services:
          app:
            build:
              entitlements: [network.host]
        YAML);

        $files = ['docker-compose.yml', BuildEntitlements::OVERRIDE_FILE];
        $this->assertSame([], BuildEntitlements::servicesMissingEntitlement($this->tmp, $files));
    }

    public function testFileComposeYangTidakAdaDilewati(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          app:
            build: .
        YAML);

        $this->assertSame(
            ['app'],
            BuildEntitlements::servicesMissingEntitlement(
                $this->tmp,
                ['docker-compose.yml', 'docker-compose.override.hilang.yml']
            )
        );
    }

    public function testYamlRusakGagalCepatDenganNamaFile(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', "services: [oops\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('docker-compose.yml');
        BuildEntitlements::servicesMissingEntitlement($this->tmp, ['docker-compose.yml']);
    }

    public function testWriteOverrideTanpaServiceDitolak(): void
    {
        $this->expectException(RuntimeException::class);
        BuildEntitlements::writeOverride($this->tmp, []);
    }

    // ==================================================================
    // Urutan daftar compose_files
    // ==================================================================

    public function testOverrideDisisipkanSebelumOverrideEnv(): void
    {
        $files = ['docker-compose.yml', EnvManager::OVERRIDE_FILE];

        $this->assertSame(
            ['docker-compose.yml', BuildEntitlements::OVERRIDE_FILE, EnvManager::OVERRIDE_FILE],
            BuildEntitlements::withOverride($files)
        );
    }

    public function testOverrideDitambahkanDiAkhirBilaTanpaOverrideEnv(): void
    {
        $this->assertSame(
            ['docker-compose.yml', BuildEntitlements::OVERRIDE_FILE],
            BuildEntitlements::withOverride(['docker-compose.yml'])
        );
    }

    public function testOverrideTidakDiduplikasi(): void
    {
        $files = ['docker-compose.yml', BuildEntitlements::OVERRIDE_FILE, EnvManager::OVERRIDE_FILE];

        $this->assertSame(
            ['docker-compose.yml', BuildEntitlements::OVERRIDE_FILE, EnvManager::OVERRIDE_FILE],
            BuildEntitlements::withOverride($files)
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
