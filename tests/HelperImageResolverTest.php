<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\HelperImageResolver;
use app\library\Backup\ResticRunner;
use app\library\Docker\DockerClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fake DockerClient — mengembalikan hasil inspect yang diberikan, atau melempar
 * bila Engine "tak dapat diakses".
 */
class ImageProbeFakeDockerClient extends DockerClient
{
    /** @param array<string,mixed> $inspect */
    public function __construct(private array $inspect = [], private string $error = '')
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function inspectContainer(string $id): array
    {
        if ($this->error !== '') {
            throw new RuntimeException($this->error);
        }
        return $this->inspect;
    }
}

/**
 * Test `HelperImageResolver` — kontrak §4.3/§5.2:
 * "`VOLUME_BACKUP_IMAGE` kosong ⇒ image container dashboard".
 *
 * Membuktikan spec image **ter-resolve** saat `volume_backup_image` kosong,
 * tanpa Engine nyata (seam `DockerClient` di-fake).
 */
class HelperImageResolverTest extends TestCase
{
    // ==================================================================
    // Keputusan murni (choose)
    // ==================================================================

    public function testExplicitOverrideWinsOverDashboardImage(): void
    {
        $this->assertSame(
            'registry.example.com/rames/restic:1.2.3',
            HelperImageResolver::choose('registry.example.com/rames/restic:1.2.3', 'rames:dashboard')
        );
    }

    public function testDashboardImageUsedWhenOverrideEmpty(): void
    {
        $this->assertSame('rames:dashboard', HelperImageResolver::choose('', 'rames:dashboard'));
        $this->assertSame('rames:dashboard', HelperImageResolver::choose('  ', "  rames:dashboard\n"));
    }

    public function testFailsFastWhenNeitherImageIsAvailable(): void
    {
        try {
            HelperImageResolver::choose('', '', 'rames-webman', 'socket tidak tersedia');
            $this->fail('choose() seharusnya melempar saat kedua sumber kosong');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('VOLUME_BACKUP_IMAGE', $e->getMessage());
            $this->assertStringContainsString('rames-webman', $e->getMessage());
            $this->assertStringContainsString('socket tidak tersedia', $e->getMessage());
            $this->assertStringContainsString('tidak terbaca', $e->getMessage());
        }
    }

    // ==================================================================
    // Resolusi lewat Engine (instance, DockerClient di-fake)
    // ==================================================================

    public function testApplyResolvesDashboardImageWhenOverrideEmpty(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient(['Config' => ['Image' => 'rames:dashboard-1.0']]),
            '',            // VOLUME_BACKUP_IMAGE kosong
            'rames-webman'
        );

        $spec = $resolver->apply(['image' => '']);

        $this->assertSame('rames:dashboard-1.0', $spec['image'], 'image dashboard harus dipakai saat override kosong');
    }

    public function testApplyResolvesDashboardImageIntoResticHelperArgv(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient(['Config' => ['Image' => 'rames:dashboard-1.0']]),
            '',
            'rames-webman'
        );

        $spec = ResticRunner::normalizeSpec([
            'image' => '',
            'repository' => 's3:https://s3.example.com/bucket/rames',
            'password_file' => '/tmp/restic-password',
        ]);
        $spec = $resolver->apply($spec);

        $argv = ResticRunner::buildBackupArgv($spec, ['/data'], ['volume:tonidata_data'], '');

        $this->assertContains('rames:dashboard-1.0', $argv, 'argv helper harus memuat image teresolusi');
        $this->assertSame('rames:dashboard-1.0', $spec['image']);
    }

    public function testApplyKeepsExplicitSpecImageWithoutTouchingEngine(): void
    {
        // Engine sengaja dibuat gagal: bila spec sudah punya image, Engine tidak
        // boleh disentuh sama sekali.
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient([], 'Engine mati'),
            '',
            'rames-webman'
        );

        $spec = $resolver->apply(['image' => 'fake:test']);

        $this->assertSame('fake:test', $spec['image']);
    }

    public function testConfiguredOverrideNeverTouchesEngine(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient([], 'Engine mati'),
            'override:1',
            'rames-webman'
        );

        $this->assertSame('override:1', $resolver->resolve());
    }

    public function testResolveFailsFastWhenEngineUnreachable(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient([], 'unix socket tidak dapat diakses'),
            '',
            'rames-webman'
        );

        try {
            $resolver->resolve();
            $this->fail('resolve() seharusnya melempar saat Engine tak dapat diakses');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('VOLUME_BACKUP_IMAGE', $e->getMessage());
            $this->assertStringContainsString('unix socket tidak dapat diakses', $e->getMessage());
        }
    }

    public function testResolveFailsFastWhenInspectReturnsNoImage(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient(['Config' => []]),
            '',
            'rames-webman'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image helper backup tidak bisa ditentukan');
        $resolver->resolve();
    }
}
