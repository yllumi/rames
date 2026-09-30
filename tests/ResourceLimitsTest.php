<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ResourceLimits;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit test ResourceLimits — override batas maksimum CPU/memori per service:
 * parsing nilai memori, normalisasi input user, deteksi gaya (legacy/modern/dual)
 * dari base compose, penulisan override sesuai keluarga field, serta sinkronisasi
 * idempoten (termasuk penghapusan override basi saat batas kosong — tanpa parse
 * base compose).
 */
class ResourceLimitsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/resourcelimits_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
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

    private function writeBase(string $yaml, string $name = 'docker-compose.yml'): void
    {
        file_put_contents($this->tmp . '/' . $name, $yaml);
    }

    /**
     * @return array<string,mixed>
     */
    private function overrideData(): array
    {
        $path = $this->tmp . '/' . ResourceLimits::OVERRIDE_FILE;
        $this->assertFileExists($path);

        return Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
    }

    // ==================================================================
    // Parsing nilai memori
    // ==================================================================

    /**
     * @dataProvider memoryValues
     */
    public function testMemoryMbFromMengubahSatuanKeMb(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, ResourceLimits::memoryMbFrom($raw));
    }

    /**
     * @return array<string,array{0:mixed,1:int}>
     */
    public static function memoryValues(): array
    {
        return [
            'byte polos (int)' => [1024, 1],
            'byte polos (besar)' => [10485760, 10],
            'b' => ['512b', 1],
            'k' => ['2048k', 2],
            'm' => ['512m', 512],
            'm huruf besar' => ['512M', 512],
            'mb' => ['128mb', 128],
            'g' => ['1g', 1024],
            'g desimal' => ['1.5g', 1536],
            'string byte tanpa satuan' => ['1048576', 1],
            'minimum 1 MB' => ['1', 1],
        ];
    }

    /**
     * @dataProvider memoryInvalid
     */
    public function testMemoryMbFromMenolakNilaiTidakValid(mixed $raw): void
    {
        $this->expectException(RuntimeException::class);
        ResourceLimits::memoryMbFrom($raw, 'web');
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function memoryInvalid(): array
    {
        return [
            'kosong' => [''],
            'bukan angka' => ['abc'],
            'satuan asing' => ['512x'],
            'negatif' => ['-512m'],
            'integer negatif' => [-1],
        ];
    }

    // ==================================================================
    // Normalisasi input user
    // ==================================================================

    public function testNormalizeMenerimaNilaiValid(): void
    {
        $result = ResourceLimits::normalize([
            'web' => ['cpus' => '1.5', 'memory_mb' => '512'],
            'worker' => ['cpus' => 0.5, 'memory_mb' => 128],
        ]);

        $this->assertSame([
            'web' => ['cpus' => 1.5, 'memory_mb' => 512],
            'worker' => ['cpus' => 0.5, 'memory_mb' => 128],
        ], $result);
    }

    public function testNormalizeMengosongkanFieldKosong(): void
    {
        $result = ResourceLimits::normalize([
            'web' => ['cpus' => '', 'memory_mb' => '  '],
            'worker' => [],
        ]);

        $this->assertSame([
            'web' => ['cpus' => null, 'memory_mb' => null],
            'worker' => ['cpus' => null, 'memory_mb' => null],
        ], $result);
    }

    /**
     * @dataProvider cpusInvalid
     */
    public function testNormalizeMenolakCpusTidakValid(mixed $raw): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CPU');
        ResourceLimits::normalize(['web' => ['cpus' => $raw]]);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function cpusInvalid(): array
    {
        return [
            'nol' => [0],
            'negatif' => [-1],
            'bukan angka' => ['abc'],
            'melebihi maksimum' => [ResourceLimits::MAX_CPUS + 1],
            'boolean' => [true],
        ];
    }

    /**
     * @dataProvider memoryInputInvalid
     */
    public function testNormalizeMenolakMemoriTidakValid(mixed $raw): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('memori');
        ResourceLimits::normalize(['web' => ['memory_mb' => $raw]]);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function memoryInputInvalid(): array
    {
        return [
            'bersatuan' => ['128MB'],
            'float string' => ['512.5'],
            'float' => [512.5],
            'di bawah minimum' => [ResourceLimits::MIN_MEMORY_MB - 1],
            'melebihi maksimum' => [ResourceLimits::MAX_MEMORY_MB + 1],
        ];
    }

    public function testNormalizeMenolakEntriBukanArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak valid');
        ResourceLimits::normalize(['web' => '512']);
    }

    public function testNormalizeMenolakFieldTidakDikenal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dikenal');
        ResourceLimits::normalize(['web' => ['cpu' => '1']]);
    }

    /**
     * Guard dinilai dari **nilai entri**, bukan bentuk list: `['salah']`
     * (key `0`) ditolak karena nilainya bukan map — pesannya format & menyebut
     * key sebagai "entri", bukan seolah-olah ada service bernama "0".
     */
    public function testNormalizeMenolakPayloadSkalarListDenganPesanFormat(): void
    {
        try {
            ResourceLimits::normalize(['salah']);
            $this->fail('Payload skalar berbentuk list wajib ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Format batas CPU/memori tidak valid', $e->getMessage());
            $this->assertStringContainsString('entri "0"', $e->getMessage());
            $this->assertStringNotContainsString('Nama service', $e->getMessage());
        }
    }

    /**
     * Nilai entri non-array (`['web' => 'x']`) ditolak sebagai masalah
     * **format**, menyebut key-nya.
     */
    public function testNormalizeMenolakNilaiEntriSkalarDenganPesanFormat(): void
    {
        try {
            ResourceLimits::normalize(['web' => 'x']);
            $this->fail('Nilai entri skalar wajib ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Format batas CPU/memori tidak valid', $e->getMessage());
            $this->assertStringContainsString('entri "web"', $e->getMessage());
        }
    }

    /**
     * Map dengan key numerik murni **diterima** apa adanya — compose
     * mengizinkan service bernama `0`, dan PHP meng-cast key `"0"` → `int 0`.
     */
    public function testNormalizeMenerimaServiceBernamaNumerik(): void
    {
        $result = ResourceLimits::normalize(['0' => ['cpus' => '1', 'memory_mb' => '128']]);

        $this->assertSame(['0' => ['cpus' => 1.0, 'memory_mb' => 128]], $result);
        $this->assertSame(['0'], array_map('strval', array_keys($result)));
    }

    /**
     * Map campuran (numerik + bernama) juga diterima; kedua key dibaca apa
     * adanya sebagai nama service.
     */
    public function testNormalizeMenerimaMapCampuranNumerikDanBernama(): void
    {
        $result = ResourceLimits::normalize([
            '0' => ['cpus' => 1],
            'web' => ['memory_mb' => '256'],
        ]);

        $this->assertSame(['0', 'web'], array_map('strval', array_keys($result)));
        $this->assertSame(1.0, $result['0']['cpus']);
        $this->assertSame(256, $result['web']['memory_mb']);
    }

    // ==================================================================
    // of() & isActive()
    // ==================================================================

    public function testOfMembacaFieldLimitsDanMembuangEntriKosong(): void
    {
        $app = ['limits' => [
            'web' => ['cpus' => 1.5, 'memory_mb' => 512],
            'worker' => ['cpus' => null, 'memory_mb' => null],
        ]];

        $this->assertSame(['web' => ['cpus' => 1.5, 'memory_mb' => 512]], ResourceLimits::of($app));
        $this->assertSame([], ResourceLimits::of([]));
        $this->assertSame([], ResourceLimits::of(['limits' => null]));
    }

    public function testIsActive(): void
    {
        $this->assertTrue(ResourceLimits::isActive(['web' => ['cpus' => 1.0, 'memory_mb' => null]]));
        $this->assertTrue(ResourceLimits::isActive(['web' => ['cpus' => null, 'memory_mb' => 512]]));
        $this->assertFalse(ResourceLimits::isActive(['web' => ['cpus' => null, 'memory_mb' => null]]));
        $this->assertFalse(ResourceLimits::isActive([]));
    }

    // ==================================================================
    // services() & detect()
    // ==================================================================

    public function testServicesMembacaServiceBaseCompose(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
          worker:
            image: busybox
        YAML);

        $this->assertSame(['web', 'worker'], ResourceLimits::services($this->tmp, ['docker-compose.yml']));
    }

    public function testServicesMelemparBilaBaseTidakAda(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Base compose tidak ditemukan');
        ResourceLimits::services($this->tmp, ['docker-compose.yml']);
    }

    public function testDetectMembacaBaseLegacy(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            cpus: 1.5
            mem_limit: 256m
          worker:
            image: busybox
        YAML);

        $detected = ResourceLimits::detect($this->tmp, ['docker-compose.yml']);

        $this->assertSame(1.5, $detected['web']['cpus']);
        $this->assertSame(256, $detected['web']['memory_mb']);
        $this->assertSame(['legacy'], $detected['web']['cpu_families']);
        $this->assertSame(['legacy'], $detected['web']['memory_families']);

        $this->assertNull($detected['worker']['cpus']);
        $this->assertNull($detected['worker']['memory_mb']);
        // Tanpa blok `deploy.resources.limits` → rencana tulis legacy.
        $this->assertSame(['legacy'], $detected['worker']['cpu_families']);
        $this->assertSame(['legacy'], $detected['worker']['memory_families']);
    }

    public function testDetectMembacaSaudaraKeyLegacySebagaiKeluarga(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            cpu_shares: 512
            mem_reservation: 64m
        YAML);

        $detected = ResourceLimits::detect($this->tmp, ['docker-compose.yml']);

        $this->assertNull($detected['web']['cpus']);
        $this->assertSame(['legacy'], $detected['web']['cpu_families']);
        $this->assertSame(['legacy'], $detected['web']['memory_families']);
    }

    public function testDetectMembacaBaseModern(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            deploy:
              resources:
                limits:
                  cpus: "0.5"
                  memory: 128M
        YAML);

        $detected = ResourceLimits::detect($this->tmp, ['docker-compose.yml']);

        $this->assertSame(0.5, $detected['web']['cpus']);
        $this->assertSame(128, $detected['web']['memory_mb']);
        $this->assertSame(['modern'], $detected['web']['cpu_families']);
        $this->assertSame(['modern'], $detected['web']['memory_families']);
    }

    public function testDetectMembacaBaseDualDanReservation(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            cpus: 2
            mem_limit: 512m
            deploy:
              resources:
                limits:
                  cpus: 2
                  memory: 512m
                reservations:
                  cpus: 0.25
                  memory: 128m
          worker:
            image: busybox
            deploy:
              resources:
                reservations:
                  cpus: 1
        YAML);

        $detected = ResourceLimits::detect($this->tmp, ['docker-compose.yml']);

        $this->assertSame(['legacy', 'modern'], $detected['web']['cpu_families']);
        $this->assertSame(['legacy', 'modern'], $detected['web']['memory_families']);
        $this->assertSame(2.0, $detected['web']['cpus']);
        $this->assertSame(512, $detected['web']['memory_mb']);

        // Reservation saja BUKAN blok `limits` → tetap legacy (reservation base
        // tidak boleh disentuh).
        $this->assertSame(['legacy'], $detected['worker']['cpu_families']);
        $this->assertNull($detected['worker']['cpus']);
    }

    /**
     * Blok `deploy.resources.limits` untuk resource LAIN pun memaksa gaya modern
     * — Compose menolak key legacy begitu blok `limits` ada.
     */
    public function testDetectBlokLimitsMemaksaKeluargaModern(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            deploy:
              resources:
                limits:
                  memory: 128m
        YAML);

        $detected = ResourceLimits::detect($this->tmp, ['docker-compose.yml']);

        $this->assertSame(['modern'], $detected['web']['cpu_families']);
        $this->assertSame(['modern'], $detected['web']['memory_families']);
        $this->assertNull($detected['web']['cpus']);
        $this->assertSame(128, $detected['web']['memory_mb']);
    }

    public function testDetectFailFastSaatLegacyTanpaPasanganModern(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            cpus: 1
            deploy:
              resources:
                limits:
                  memory: 128m
        YAML);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak konsisten/');
        ResourceLimits::detect($this->tmp, ['docker-compose.yml']);
    }

    public function testDetectMemperlakukanSiblingLegacyDenganBlokLimitsTetapInkonsisten(): void
    {
        // `mem_limit` (legacy canonical) + `deploy.resources.limits.cpus` → base
        // ditolak Compose karena `limits.memory` tidak ada.
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            mem_limit: 128m
            deploy:
              resources:
                limits:
                  cpus: 0.5
        YAML);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mem_limit/');
        ResourceLimits::detect($this->tmp, ['docker-compose.yml']);
    }

    public function testDetectFailFastSaatNilaiMemoriBerbedaAntarKeluarga(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            mem_limit: 256m
            deploy:
              resources:
                limits:
                  memory: 128m
        YAML);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mem_limit/');
        ResourceLimits::detect($this->tmp, ['docker-compose.yml']);
    }

    public function testDetectFailFastSaatNilaiCpuBerbedaAntarKeluarga(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            cpus: 2
            deploy:
              resources:
                limits:
                  cpus: 1
        YAML);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cpus/');
        ResourceLimits::detect($this->tmp, ['docker-compose.yml']);
    }

    // ==================================================================
    // writeOverride()
    // ==================================================================

    public function testWriteOverrideMenulisLegacySaatBaseLegacy(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            mem_limit: 128m
        YAML);

        $result = ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => 1.5, 'memory_mb' => 512]]
        );

        $this->assertTrue($result);
        $data = $this->overrideData();
        $this->assertSame(1.5, $data['services']['web']['cpus']);
        $this->assertSame('512m', $data['services']['web']['mem_limit']);
        $this->assertArrayNotHasKey('deploy', $data['services']['web']);
    }

    public function testWriteOverrideMenulisModernSaatBaseModern(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            deploy:
              resources:
                limits:
                  cpus: 1
                  memory: 128m
        YAML);

        $result = ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => 1.5, 'memory_mb' => 512]]
        );

        $this->assertTrue($result);
        $entry = $this->overrideData()['services']['web'];
        $this->assertSame('512m', $entry['deploy']['resources']['limits']['memory']);
        $this->assertSame(1.5, $entry['deploy']['resources']['limits']['cpus']);
        $this->assertArrayNotHasKey('cpus', $entry);
        $this->assertArrayNotHasKey('mem_limit', $entry);
    }

    public function testWriteOverrideMenulisDualSaatBaseDual(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            cpus: 1
            mem_limit: 256m
            deploy:
              resources:
                limits:
                  cpus: 1
                  memory: 256m
        YAML);

        ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => 2.0, 'memory_mb' => 1024]]
        );

        $entry = $this->overrideData()['services']['web'];
        $this->assertSame(2.0, $entry['cpus']);
        $this->assertSame(2.0, $entry['deploy']['resources']['limits']['cpus']);
        $this->assertSame('1024m', $entry['mem_limit']);
        $this->assertSame('1024m', $entry['deploy']['resources']['limits']['memory']);
    }

    public function testWriteOverrideFallbackKeLegacySaatBaseTanpaBatas(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);

        ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => 1.0, 'memory_mb' => 256]]
        );

        $entry = $this->overrideData()['services']['web'];
        $this->assertSame(1.0, $entry['cpus']);
        $this->assertSame('256m', $entry['mem_limit']);
        $this->assertArrayNotHasKey('deploy', $entry);
    }

    public function testWriteOverrideHanyaMenulisServiceYangDiatur(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
          worker:
            image: busybox
        YAML);

        ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            [
                'web' => ['cpus' => 1.0, 'memory_mb' => null],
                'worker' => ['cpus' => null, 'memory_mb' => null],
            ]
        );

        $services = $this->overrideData()['services'];
        $this->assertArrayHasKey('web', $services);
        $this->assertArrayNotHasKey('worker', $services);
        $this->assertSame(1.0, $services['web']['cpus']);
        $this->assertArrayNotHasKey('mem_limit', $services['web']);
    }

    public function testWriteOverrideMenulisModernUntukCpuSaatBaseHanyaModernMemori(): void
    {
        // Regression: menulis legacy `cpus` di sini membuat Compose menolak project
        // ("can't set distinct values on 'cpus' and 'deploy.resources.limits.cpus'").
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            deploy:
              resources:
                limits:
                  memory: 128m
        YAML);

        ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => 1.5, 'memory_mb' => null]]
        );

        $entry = $this->overrideData()['services']['web'];
        $this->assertSame(1.5, $entry['deploy']['resources']['limits']['cpus']);
        $this->assertArrayNotHasKey('cpus', $entry);
    }

    public function testWriteOverrideMenulisLegacySaatBaseHanyaReservation(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            deploy:
              resources:
                reservations:
                  memory: 64m
        YAML);

        ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => 1.5, 'memory_mb' => 512]]
        );

        $entry = $this->overrideData()['services']['web'];
        $this->assertSame(1.5, $entry['cpus']);
        $this->assertSame('512m', $entry['mem_limit']);
        $this->assertArrayNotHasKey('deploy', $entry);
    }

    public function testWriteOverrideTidakMenyentuhBaseSaatTidakAdaNilai(): void
    {
        // Direktori TANPA base compose → tidak boleh parse/error (jalur create app).
        $this->assertFalse(ResourceLimits::writeOverride($this->tmp, ['docker-compose.yml'], []));
        $this->assertFalse(ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['web' => ['cpus' => null, 'memory_mb' => null]]
        ));
    }

    public function testWriteOverrideMengabaikanServiceYangTidakAdaDiBase(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);

        $result = ResourceLimits::writeOverride(
            $this->tmp,
            ['docker-compose.yml'],
            ['hantu' => ['cpus' => 1.0, 'memory_mb' => null]]
        );

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    public function testWriteOverrideTidakMenulisFileSaatValidasiGagal(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);

        // Validasi (normalize) dijalankan SEBELUM menyentuh disk → gagal =
        // tidak ada file override yang tertulis.
        try {
            ResourceLimits::writeOverride($this->tmp, ['docker-compose.yml'], ['web' => ['cpus' => 0]]);
            $this->fail('writeOverride seharusnya melempar untuk cpus = 0.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CPU', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    /**
     * GOTCHA: `symfony/yaml` tidak bisa menulis key numerik sebagai map
     * (array ber-key numerik berurutan keluar sebagai *sequence*). Bila akan
     * menulis limit untuk service bernama numerik murni, `writeOverride()`
     * harus fail-fast **tanpa** menulis file — bukan menghasilkan override yang
     * membuat `docker compose` menolak project.
     */
    public function testWriteOverrideFailFastServiceBernamaNumerikTanpaMenulisFile(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          0:
            image: x
        YAML);

        // Prasyarat: base YAML valid & service "0" terbaca sebagai service.
        $this->assertSame(['0'], ResourceLimits::services($this->tmp, ['docker-compose.yml']));

        try {
            ResourceLimits::writeOverride(
                $this->tmp,
                ['docker-compose.yml'],
                ['0' => ['cpus' => 1.0, 'memory_mb' => null]]
            );
            $this->fail('writeOverride wajib fail-fast untuk service bernama numerik murni.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sepenuhnya numerik', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    public function testWriteOverrideMenghapusFileSaatTidakAdaNilai(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);

        ResourceLimits::writeOverride($this->tmp, ['docker-compose.yml'], ['web' => ['cpus' => 1.0, 'memory_mb' => null]]);
        $this->assertFileExists($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);

        $result = ResourceLimits::writeOverride($this->tmp, ['docker-compose.yml'], []);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    // ==================================================================
    // sync()
    // ==================================================================

    public function testSyncIdempoten(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);
        $app = ['limits' => ['web' => ['cpus' => 1.5, 'memory_mb' => 512]]];

        $this->assertTrue(ResourceLimits::sync($app, $this->tmp, ['docker-compose.yml']));
        $first = (string) file_get_contents($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
        $this->assertTrue(ResourceLimits::sync($app, $this->tmp, ['docker-compose.yml']));
        $second = (string) file_get_contents($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);

        $this->assertSame($first, $second);
    }

    public function testSyncMenghapusOverrideBasiSaatBatasDikosongkan(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);
        $active = ['limits' => ['web' => ['cpus' => 1.5, 'memory_mb' => 512]]];

        // Seed: limit lama sudah tertulis di disk (mis. hasil saveLimits).
        $this->assertTrue(ResourceLimits::sync($active, $this->tmp, ['docker-compose.yml']));
        $this->assertFileExists($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);

        // `limits` dikosongkan di apps.json → override basi WAJIB dihapus.
        // Bug lama: sync early-return tanpa removeOverride sehingga limit lama
        // tetap berlaku pada setiap `up` (config merged masih 1.5/512m).
        $this->assertFalse(ResourceLimits::sync(['limits' => []], $this->tmp, ['docker-compose.yml']));
        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);

        // Idempoten: panggilan berikutnya tanpa file tetap false & tanpa error.
        $this->assertFalse(ResourceLimits::sync(['limits' => []], $this->tmp, ['docker-compose.yml']));
        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    public function testSyncMenghapusOverrideBasiTanpaPerluParseBaseCompose(): void
    {
        // Seed manual (file override bisa ada walau base compose tak terbaca).
        file_put_contents(
            $this->tmp . '/' . ResourceLimits::OVERRIDE_FILE,
            "services:\n  web:\n    cpus: 1.5\n"
        );

        // Tanpa base compose di direktori: andai sync mem-parse base, ia akan
        // melempar "Base compose tidak ditemukan" — bukti penghapusan tidak
        // membutuhkan parse apa pun.
        $this->assertFalse(ResourceLimits::sync(['limits' => []], $this->tmp, ['docker-compose.yml']));
        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    public function testSyncTanpaBatasTetapFalseTanpaErrorDanTanpaParseBaseCompose(): void
    {
        // Direktori sengaja TANPA base compose — sync tidak boleh parse apa pun
        // (jalur create app). Tanpa file override pun harus aman (murni no-op).
        $this->assertFalse(ResourceLimits::sync([], $this->tmp, ['docker-compose.yml']));
        $this->assertFalse(ResourceLimits::sync(['limits' => null], $this->tmp, ['docker-compose.yml']));
        $this->assertFalse(ResourceLimits::sync(
            ['limits' => ['web' => ['cpus' => null, 'memory_mb' => null]]],
            $this->tmp,
            ['docker-compose.yml']
        ));
        $this->assertFileDoesNotExist($this->tmp . '/' . ResourceLimits::OVERRIDE_FILE);
    }

    public function testSyncFailFastBilaBatasAktifTanpaBaseCompose(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Base compose tidak ditemukan');
        ResourceLimits::sync(['limits' => ['web' => ['cpus' => 1.0]]], $this->tmp, ['docker-compose.yml']);
    }

    public function testSyncMenggunakanFileUtamaBukanOverride(): void
    {
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);

        $files = [
            'docker-compose.yml',
            'docker-compose.override.yml',
            'docker-compose.override.ports.yml',
            'docker-compose.override.names.yml',
        ];
        $app = ['limits' => ['web' => ['cpus' => null, 'memory_mb' => 256]]];

        $this->assertTrue(ResourceLimits::sync($app, $this->tmp, $files));
        $entry = $this->overrideData()['services']['web'];
        $this->assertSame('256m', $entry['mem_limit']);
    }

    public function testSyncDenganGayaModernYangBerubah(): void
    {
        // Base awalnya modern; setelah "git pull" berganti ke legacy — sync wajib
        // mengikuti gaya base saat itu, bukan gaya saat save.
        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
            deploy:
              resources:
                limits:
                  cpus: 1
        YAML);
        $app = ['limits' => ['web' => ['cpus' => 2.0, 'memory_mb' => null]]];

        ResourceLimits::sync($app, $this->tmp, ['docker-compose.yml']);
        $entry = $this->overrideData()['services']['web'];
        $this->assertSame(2.0, $entry['deploy']['resources']['limits']['cpus']);
        $this->assertArrayNotHasKey('cpus', $entry);

        $this->writeBase(<<<'YAML'
        services:
          web:
            image: nginx
        YAML);

        ResourceLimits::sync($app, $this->tmp, ['docker-compose.yml']);
        $entry = $this->overrideData()['services']['web'];
        $this->assertSame(2.0, $entry['cpus']);
        $this->assertArrayNotHasKey('deploy', $entry);
    }
}
