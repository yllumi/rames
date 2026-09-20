<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ContainerNames;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit test ContainerNames — override nama container (`container_name`) per app,
 * pasangan dari override host port: skema nama `{prefix}-{service}`, validasi
 * prefix, deteksi bentrok (nama container unik se-host) & penolakan service
 * ber-replica, serta penulisan/penghapusan file override.
 */
class ContainerNamesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/containernames_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    // ==================================================================
    // Skema nama
    // ==================================================================

    public function testNameForMenggabungkanPrefixDanService(): void
    {
        $this->assertSame('hermes-web', ContainerNames::nameFor('hermes', 'web'));
    }

    public function testMapForMembangunNamaSemuaService(): void
    {
        $map = ContainerNames::mapFor(['web', 'worker'], 'hermes');

        $this->assertSame(['web' => 'hermes-web', 'worker' => 'hermes-worker'], $map);
    }

    public function testNormalizePrefixMenurunkanHurufDanTrim(): void
    {
        $this->assertSame('hermes', ContainerNames::normalizePrefix('  HeRMeS '));
        $this->assertSame('', ContainerNames::normalizePrefix(null));
        $this->assertSame('', ContainerNames::normalizePrefix('   '));
    }

    public function testPrefixKosongDianggapValid(): void
    {
        ContainerNames::assertValidPrefix('');
        $this->addToAssertionCount(1); // prefix kosong = pakai nama default compose
    }

    public function testPrefixValidDiterima(): void
    {
        foreach (['hermes', 'my-app', 'a1', 'app2-web'] as $prefix) {
            ContainerNames::assertValidPrefix($prefix);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider prefixTidakValid
     */
    public function testPrefixTidakValidDitolak(string $prefix): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prefix nama container');
        ContainerNames::assertValidPrefix($prefix);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function prefixTidakValid(): array
    {
        return [
            'huruf besar' => ['Hermes'],
            'underscore' => ['my_app'],
            'strip di awal' => ['-web'],
            'strip di akhir' => ['web-'],
            'spasi' => ['my app'],
            'titik' => ['my.app'],
            'terlalu panjang' => [str_repeat('a', ContainerNames::MAX_PREFIX_LENGTH + 1)],
        ];
    }

    // ==================================================================
    // Service & replica
    // ==================================================================

    public function testServicesMembacaReplicaDariDeployReplicas(): void
    {
        $this->writeCompose([
            'web' => ['image' => 'nginx'],
            'worker' => ['image' => 'alpine', 'deploy' => ['replicas' => 3]],
        ]);

        $services = ContainerNames::services($this->tmp, ['docker-compose.yml']);

        $this->assertSame(1, $services['web']['replicas']);
        $this->assertSame(3, $services['worker']['replicas']);
    }

    public function testServicesMembacaReplicaDariScale(): void
    {
        $this->writeCompose(['web' => ['image' => 'nginx', 'scale' => 2]]);

        $this->assertSame(2, ContainerNames::services($this->tmp, ['docker-compose.yml'])['web']['replicas']);
    }

    public function testServicesMengabaikanOverrideGenerated(): void
    {
        $this->writeCompose(['web' => ['image' => 'nginx']]);
        file_put_contents($this->tmp . '/docker-compose.override.ports.yml', "services: {}\n");

        $services = ContainerNames::services($this->tmp, [
            'docker-compose.yml',
            'docker-compose.override.ports.yml',
        ]);

        $this->assertSame(['web'], array_keys($services));
    }

    public function testAssertNotReplicatedMenolakServiceBerReplica(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('replicas=3');

        ContainerNames::assertNotReplicated([
            'web' => ['replicas' => 1],
            'worker' => ['replicas' => 3],
        ]);
    }

    public function testAssertNotReplicatedLolosBilaSemuaReplicaSatu(): void
    {
        ContainerNames::assertNotReplicated(['web' => ['replicas' => 1], 'db' => ['replicas' => 1]]);
        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // Deteksi bentrok
    // ==================================================================

    public function testUsedFromEngineMelewatiContainerMilikProjectSendiri(): void
    {
        $used = ContainerNames::usedFromEngine([
            [
                'Names' => ['/myapp-web'],
                'Labels' => ['com.docker.compose.project' => 'myapp'],
            ],
            [
                'Names' => ['/other-web'],
                'Labels' => ['com.docker.compose.project' => 'other'],
            ],
            [
                'Names' => ['/rames-webman'],
                'Labels' => [],
            ],
        ], 'myapp');

        $this->assertSame('app "other"', $used['other-web']);
        $this->assertSame('container di luar dashboard (docker ps)', $used['rames-webman']);
        $this->assertArrayNotHasKey('myapp-web', $used, 'container app sendiri sedang digantikan');
    }

    public function testUsedFromAppsMelewatiAppSendiri(): void
    {
        $used = ContainerNames::usedFromApps([
            ['name' => 'myapp', 'containers' => [['container_name' => 'myapp-web']]],
            ['name' => 'other', 'containers' => [['container_name' => 'other-web']]],
        ], 'myapp');

        $this->assertSame(['other-web' => 'app "other"'], $used);
    }

    public function testAssertAvailableMenolakNamaYangSudahDipakai(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama container sudah terpakai: "rames-webman" (dipakai container di luar dashboard (docker ps))');

        ContainerNames::assertAvailable(
            ['webman' => 'rames-webman'],
            ['rames-webman' => 'container di luar dashboard (docker ps)']
        );
    }

    public function testAssertAvailableLolosBilaNamaBebas(): void
    {
        ContainerNames::assertAvailable(['web' => 'hermes-web'], ['other-web' => 'app "other"']);
        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // File override
    // ==================================================================

    public function testWriteOverrideMenulisContainerNamePerService(): void
    {
        ContainerNames::writeOverride($this->tmp, ['web', 'worker'], 'hermes');

        $path = $this->tmp . '/' . ContainerNames::OVERRIDE_FILE;
        $this->assertFileExists($path);

        $data = Yaml::parseFile($path);
        $this->assertSame('hermes-web', $data['services']['web']['container_name']);
        $this->assertSame('hermes-worker', $data['services']['worker']['container_name']);
    }

    public function testWriteOverrideMenolakPrefixTidakValid(): void
    {
        $this->expectException(RuntimeException::class);

        ContainerNames::writeOverride($this->tmp, ['web'], 'Web');
    }

    public function testWriteOverrideMenolakDaftarServiceKosong(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('daftar service');

        ContainerNames::writeOverride($this->tmp, [], 'hermes');
    }

    public function testSyncMenulisFileBilaPrefixDiisi(): void
    {
        $this->writeCompose(['web' => ['image' => 'nginx']]);

        $active = ContainerNames::sync(['container_prefix' => 'Hermes'], $this->tmp, ['docker-compose.yml']);

        $this->assertTrue($active);
        $this->assertFileExists($this->tmp . '/' . ContainerNames::OVERRIDE_FILE);
        $this->assertSame(
            'hermes-web',
            Yaml::parseFile($this->tmp . '/' . ContainerNames::OVERRIDE_FILE)['services']['web']['container_name']
        );
    }

    public function testSyncMenghapusFileBilaPrefixKosong(): void
    {
        $path = $this->tmp . '/' . ContainerNames::OVERRIDE_FILE;
        file_put_contents($path, "services:\n  web:\n    container_name: hermes-web\n");

        foreach ([['container_prefix' => ''], ['container_prefix' => null], [] /* app lama */] as $app) {
            file_put_contents($path, "services: {}\n");
            $active = ContainerNames::sync($app, $this->tmp, ['docker-compose.yml']);
            $this->assertFalse($active);
            $this->assertFileDoesNotExist($path);
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,array> $services
     */
    private function writeCompose(array $services): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', Yaml::dump(['services' => $services], 4, 2));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
