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
    /** Berapa kali `inspectContainer()` dipanggil (membuktikan "satu inspect"). */
    public int $inspectCalls = 0;

    /** @param array<string,mixed> $inspect */
    public function __construct(private array $inspect = [], private string $error = '')
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function inspectContainer(string $id): array
    {
        $this->inspectCalls++;
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

    // ==================================================================
    // DNS helper (HostConfig.Dns) — satu inspect, best-effort, override dihormati
    // ==================================================================

    public function testChooseDnsSanitizesRawHostConfigDns(): void
    {
        $this->assertSame(
            ['8.8.8.8', '1.1.1.1'],
            HelperImageResolver::chooseDns(['8.8.8.8', '', '1.1.1.1', null])
        );
        $this->assertSame([], HelperImageResolver::chooseDns([]));
    }

    public function testDnsFromInspectIsAppliedToSpecAndHelperArgv(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient([
                'Config' => ['Image' => 'rames:dashboard-1.0'],
                'HostConfig' => ['Dns' => ['8.8.8.8', '1.1.1.1']],
            ]),
            '',
            'rames-webman'
        );

        $spec = $resolver->apply(['image' => '']);
        $this->assertSame(['8.8.8.8', '1.1.1.1'], $spec['dns'], 'DNS dashboard harus diterapkan ke spec');
        $this->assertSame('rames:dashboard-1.0', $spec['image']);

        $argv = ResticRunner::buildBackupArgv($spec, ['/data'], ['volume:tonidata_data'], '');

        // Pasangan `--dns 8.8.8.8 --dns 1.1.1.1` berurutan.
        $pairs = [];
        foreach ($argv as $index => $arg) {
            if ($arg === '--dns' && isset($argv[$index + 1])) {
                $pairs[] = $argv[$index + 1];
            }
        }
        $this->assertSame(['8.8.8.8', '1.1.1.1'], $pairs, 'argv helper wajib memuat --dns untuk tiap DNS dashboard');
    }

    public function testApplyIsDnsBestEffortWhenInspectFails(): void
    {
        // Image override diisi agar resolusi image tidak melempar; DNS tetap best-effort.
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient([], 'Engine mati'),
            'rames:override',
            'rames-webman'
        );

        $spec = $resolver->apply(['image' => '', 'dns' => []]);

        $this->assertSame('rames:override', $spec['image']);
        $this->assertSame([], $spec['dns'], 'inspect gagal ⇒ dns [] tanpa menggagalkan operasi');
    }

    public function testApplyOmittedDnsWhenInspectHasNoDns(): void
    {
        $resolver = new HelperImageResolver(
            new ImageProbeFakeDockerClient(['Config' => ['Image' => 'rames:dashboard'], 'HostConfig' => ['Dns' => []]]),
            '',
            'rames-webman'
        );

        $spec = $resolver->apply(['image' => '']);

        $this->assertSame([], $spec['dns']);
    }

    public function testExplicitDnsOverrideIsNotReplaced(): void
    {
        $docker = new ImageProbeFakeDockerClient([
            'Config' => ['Image' => 'rames:dashboard'],
            'HostConfig' => ['Dns' => ['8.8.8.8', '1.1.1.1']],
        ]);
        $resolver = new HelperImageResolver($docker, '', 'rames-webman');

        $spec = $resolver->apply(['image' => 'rames:override', 'dns' => ['9.9.9.9']]);

        $this->assertSame(['9.9.9.9'], $spec['dns'], 'override dns eksplisit tidak boleh ditimpa');
        $this->assertSame('rames:override', $spec['image']);
        $this->assertSame(0, $docker->inspectCalls, 'override penuh ⇒ Engine tidak perlu disentuh');
    }

    public function testImageAndDnsShareSingleInspect(): void
    {
        $docker = new ImageProbeFakeDockerClient([
            'Config' => ['Image' => 'rames:dashboard'],
            'HostConfig' => ['Dns' => ['8.8.8.8']],
        ]);
        $resolver = new HelperImageResolver($docker, '', 'rames-webman');

        $this->assertSame('rames:dashboard', $resolver->resolve());
        $this->assertSame(['8.8.8.8'], $resolver->dns());
        $resolver->apply(['image' => '']);
        $resolver->apply(['image' => '']);

        $this->assertSame(1, $docker->inspectCalls, 'image + DNS harus memakai SATU inspect (memoize per instance)');
    }
}
