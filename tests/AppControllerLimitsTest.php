<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\EnvManager;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\ResourceLimits;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit test helper validasi batas CPU/memori di AppController — tanpa HTTP server.
 *
 * Yang diuji adalah kontrak yang tidak bisa diuji dari ResourceLimits saja:
 * whitelist service (tolak service di luar base compose), pembuangan entri tanpa
 * nilai, dan konteks `resourceLimits` yang tahan saat base compose tak terbaca.
 */
class AppControllerLimitsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/appctrl_limits_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new AppController(), ...$args);
    }

    public function testResolveLimitsMenolakServiceTidakDikenal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hantu');

        $this->invoke('resolveLimits', [
            ['hantu' => ['cpus' => '1']],
            ['web', 'worker'],
        ]);
    }

    public function testResolveLimitsMembuangEntriTanpaNilai(): void
    {
        $result = $this->invoke('resolveLimits', [
            [
                'web' => ['cpus' => '1.5', 'memory_mb' => '512'],
                'worker' => ['cpus' => '', 'memory_mb' => ''],
            ],
            ['web', 'worker'],
        ]);

        $this->assertSame(['web' => ['cpus' => 1.5, 'memory_mb' => 512]], $result);
    }

    public function testResolveLimitsKosongBilaTidakAdaInput(): void
    {
        $this->assertSame([], $this->invoke('resolveLimits', [[], ['web']]));
    }

    public function testLimitsContextTahanSaatBaseComposeTidakAda(): void
    {
        $context = $this->invoke('limitsContext', [
            $this->tmp,
            ['docker-compose.yml'],
            true,
            ['web' => ['cpus' => 1.0, 'memory_mb' => null]],
            ['web', 'worker'],
        ]);

        $this->assertTrue($context['canManage']);
        $this->assertSame(['web', 'worker'], $context['services']);
        $this->assertSame([], $context['repo']);
        $this->assertTrue($context['hasSaved']);
        $this->assertSame(['web' => ['cpus' => 1.0, 'memory_mb' => null]], $context['saved']);
        $this->assertNotNull($context['error']);
    }

    public function testLimitsContextMengisiRepoDariBaseCompose(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
            cpus: 1.5
            mem_limit: 256m
          worker:
            image: busybox
        YAML);

        $context = $this->invoke('limitsContext', [
            $this->tmp,
            ['docker-compose.yml'],
            false,
            [],
            [],
        ]);

        $this->assertNull($context['error']);
        $this->assertSame(['web', 'worker'], $context['services']);
        $this->assertSame(['cpus' => 1.5, 'memory_mb' => 256], $context['repo']['web']);
        $this->assertSame(['cpus' => null, 'memory_mb' => null], $context['repo']['worker']);
        $this->assertFalse($context['hasSaved']);
    }

    /**
     * Invarian urutan `compose_files` (dipakai `saveLimits()` saat menyisipkan
     * file limits) — tanpa HTTP/config: file limits wajib berada setelah names
     * dan sebelum networks/env.
     */
    public function testOrderComposeFilesMenempatkanLimitsSetelahNamesDanSebelumNetworksEnv(): void
    {
        $ordered = $this->invoke('orderComposeFiles', [[
            'docker-compose.yml',
            EnvManager::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ComposeSource::RESET_OVERRIDE_FILE,
        ]]);

        $this->assertSame('docker-compose.yml', $ordered[0]);
        // Prioritas dijamin berurutan: reset → ports → names → limits.
        $this->assertSame([
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
        ], array_slice($ordered, 1, 4));
        // Override non-prioritas (networks/env) selalu setelah limits; urutan
        // antar-mereka mengikuti urutan masukan (di sini env lebih dulu).
        $this->assertSame(
            [EnvManager::OVERRIDE_FILE, NetworkManager::OVERRIDE_FILE],
            array_slice($ordered, 5)
        );
    }

    /**
     * Saat batas dikosongkan, `compose_files` tidak boleh lagi memuat file
     * limits (bagian yang bisa diuji tanpa `saveLimits()` penuh).
     */
    public function testOrderComposeFilesTanpaFileLimitsTetapStabil(): void
    {
        $ordered = $this->invoke('orderComposeFiles', [[
            'docker-compose.yml',
            ContainerNames::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
        ]]);

        $this->assertNotContains(ResourceLimits::OVERRIDE_FILE, $ordered);
        $this->assertSame([
            'docker-compose.yml',
            ContainerNames::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
        ], $ordered);
    }

    /**
     * Prefill render ulang (F4): nilai kiriman user dipertahankan, input yang
     * tak bisa tampil di input number dibuang, dan TIDAK melempar (render ulang
     * error tidak boleh gagal sendiri).
     */
    public function testPrefillLimitsMempertahankanNilaiUserTanpaMelempar(): void
    {
        $result = $this->invoke('prefillLimits', [[
            'web' => ['cpus' => '1.5', 'memory_mb' => '512'],
            'worker' => ['cpus' => 'abc', 'memory_mb' => ''],
            'rusak' => 'bukan array',
            '' => ['cpus' => '1'],
        ]]);

        $this->assertSame(['web' => ['cpus' => 1.5, 'memory_mb' => 512]], $result);
        $this->assertSame([], $this->invoke('prefillLimits', [[]]));
    }
}
