<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\EnvManager;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\ResourceLimits;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Test jalur **simpan batas CPU/memori** lewat `ResourceLimits::persist()` —
 * menutup celah F3 (`saveLimits()` tak bisa diuji tanpa HTTP/Engine):
 *
 *  - sukses ⇒ file override tertulis + `limits`/`compose_files` tersimpan;
 *  - penolakan (service tak dikenal / nilai invalid) ⇒ **tanpa** penulisan
 *    parsial, file tidak dibuat, entri app di store `apps` tidak berubah;
 *  - mengosongkan ⇒ file terhapus, `limits` = null, entri limits dibuang —
 *    termasuk saat base compose sedang tidak terbaca (V3: jalur kosong tidak
 *    mem-parse base compose, tetapi validasi nilai tetap dijalankan lebih dulu);
 *  - idempoten (dua kali simpan input sama ⇒ urutan & isi file tetap);
 *  - `ComposeSource::orderFiles()` (urutan kanonik) diuji langsung.
 *
 * Semua I/O memakai direktori temp — tidak menyentuh data runtime
 * (`database/`, `apps/`).
 */
class ResourceLimitsPersistTest extends TestCase
{
    private const APP_ID = 'app1';

    /** Daftar compose_files awal (memuat generated non-prioritas: networks & env). */
    private const INITIAL_FILES = [
        'docker-compose.yml',
        ContainerNames::OVERRIDE_FILE,
        NetworkManager::OVERRIDE_FILE,
        EnvManager::OVERRIDE_FILE,
    ];

    private string $tmp;
    private string $dir;

    /** Direktori app yang **tidak** punya base compose (repo rusak / `git pull` gagal). */
    private string $noBaseDir;

    private AppStore $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/resourcelimits_persist_' . bin2hex(random_bytes(4));
        $this->dir = $this->tmp . '/demo';
        mkdir($this->dir, 0777, true);
        $this->noBaseDir = $this->tmp . '/broken';
        mkdir($this->noBaseDir, 0777, true);

        file_put_contents($this->dir . '/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
          db:
            image: mysql:8
        YAML);

        $this->store = new AppStore($this->dbPath());
        $this->store->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'owner_id' => 'u1',
            'members' => [],
            'compose_files' => self::INITIAL_FILES,
            'status' => 'running',
        ]);
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

    /**
     * Jalankan persist() dengan app tersimpan & direktori temp.
     *
     * @param array<string,mixed> $posted
     * @return array{limits:array,app:array}
     */
    private function persist(array $posted): array
    {
        return $this->persistIn($this->dir, $posted);
    }

    /**
     * Sama seperti persist(), tetapi menyasar direktori app tertentu.
     *
     * @param array<string,mixed> $posted
     * @return array{limits:array,app:array}
     */
    private function persistIn(string $dir, array $posted): array
    {
        $app = $this->store->find(self::APP_ID);
        $this->assertNotNull($app, 'App seed wajib ada.');

        return ResourceLimits::persist($this->store, $app, $dir, $posted);
    }

    /**
     * Berkas basis data SQLite temp untuk store `apps`.
     */
    private function dbPath(): string
    {
        return $this->tmp . '/rames.sqlite';
    }

    /**
     * Potret entri app di store (untuk membuktikan tidak ada mutasi). SQLite
     * bermode WAL membuat perbandingan berkas (md5/mtime) tidak lagi sahih,
     * jadi perbandingan dilakukan pada isi store — sumber kebenarannya.
     */
    private function appSnapshot(): string
    {
        return (string) json_encode($this->store->find(self::APP_ID));
    }

    private function overridePath(): string
    {
        return $this->dir . '/' . ResourceLimits::OVERRIDE_FILE;
    }

    private function noBaseOverridePath(): string
    {
        return $this->noBaseDir . '/' . ResourceLimits::OVERRIDE_FILE;
    }

    /**
     * Seed state "sudah punya batas" pada direktori tanpa base compose: file
     * override + field `limits` + entri limits di `compose_files`.
     */
    private function seedLimitsWithoutBaseCompose(): void
    {
        file_put_contents(
            $this->noBaseOverridePath(),
            "services:\n  web:\n    cpus: 1.5\n    mem_limit: 512m\n"
        );
        $this->assertFileExists($this->noBaseOverridePath());

        $this->store->update(self::APP_ID, function (array &$s): void {
            $s['limits'] = ['web' => ['cpus' => 1.5, 'memory_mb' => 512]];
            $files = (array) $s['compose_files'];
            $files[] = ResourceLimits::OVERRIDE_FILE;
            $s['compose_files'] = ComposeSource::orderFiles($files);
        });

        // Prasyarat: seed benar-benar terbentuk & base compose memang tidak ada.
        $this->assertSame(['web' => ['cpus' => 1.5, 'memory_mb' => 512]], $this->storedApp()['limits']);
        $this->assertContains(ResourceLimits::OVERRIDE_FILE, $this->storedApp()['compose_files']);
        $this->assertFileDoesNotExist($this->noBaseDir . '/docker-compose.yml');
    }

    /**
     * @return array<string,mixed>
     */
    private function storedApp(): array
    {
        $app = $this->store->find(self::APP_ID);
        $this->assertNotNull($app, 'App wajib masih ada di store.');

        return $app;
    }

    // ==================================================================
    // 1. Sukses
    // ==================================================================

    public function testPersistSuksesMenulisFileDanMerapikanComposeFiles(): void
    {
        $result = $this->persist(['web' => ['cpus' => '1.5', 'memory_mb' => '512']]);

        $this->assertSame(['web' => ['cpus' => 1.5, 'memory_mb' => 512]], $result['limits']);

        // File override tertulis dengan nilai sesuai (base tanpa gaya legacy →
        // keluarga legacy).
        $this->assertFileExists($this->overridePath());
        $this->assertSame(
            ['services' => ['web' => ['cpus' => 1.5, 'mem_limit' => '512m']]],
            Yaml::parseFile($this->overridePath())
        );

        // State tersimpan & urutan kanonik (limits setelah names, sebelum networks/env).
        $stored = $this->storedApp();
        $this->assertSame(['web' => ['cpus' => 1.5, 'memory_mb' => 512]], $stored['limits']);
        $this->assertSame([
            'docker-compose.yml',
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
            EnvManager::OVERRIDE_FILE,
        ], $stored['compose_files']);

        // Field lain tidak disentuh.
        $this->assertSame('running', $stored['status']);

        // Nilai balikan app = state terbaru.
        $this->assertSame($stored, $result['app']);
    }

    // ==================================================================
    // 2. Service tak dikenal → tolak tanpa menyentuh apa pun
    // ==================================================================

    public function testPersistMenolakServiceTidakDikenalTanpaMengubahState(): void
    {
        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persist(['hantu' => ['cpus' => '1']]);
            $this->fail('Seharusnya melempar RuntimeException untuk service tak dikenal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hantu', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->overridePath());
        $this->assertSame($before, $this->appSnapshot());
    }

    // ==================================================================
    // 3. Nilai invalid → tolak tanpa menyentuh apa pun
    // ==================================================================

    /**
     * @dataProvider nilaiTidakValid
     * @param array<string,mixed> $posted
     */
    public function testPersistMenolakNilaiTidakValid(array $posted): void
    {
        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persist($posted);
            $this->fail('Seharusnya melempar RuntimeException untuk nilai invalid.');
        } catch (RuntimeException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertFileDoesNotExist($this->overridePath());
        $this->assertSame($before, $this->appSnapshot());
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function nilaiTidakValid(): array
    {
        return [
            'cpus nol' => [['web' => ['cpus' => 0]]],
            'memori di bawah minimum' => [['web' => ['memory_mb' => 5]]],
            'memori bersatuan' => [['web' => ['memory_mb' => '128MB']]],
        ];
    }

    // ==================================================================
    // 4. Kombinasi valid + tak dikenal → tolak seluruh input
    // ==================================================================

    public function testPersistMenolakSeluruhInputSaatAdaServiceTidakDikenal(): void
    {
        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persist([
                'web' => ['cpus' => '2'],
                'hantu' => ['memory_mb' => '256'],
            ]);
            $this->fail('Input campuran wajib ditolak seluruhnya.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hantu', $e->getMessage());
        }

        // Tanpa penulisan parsial: file tidak dibuat & state tidak berubah.
        $this->assertFileDoesNotExist($this->overridePath());
        $this->assertSame($before, $this->appSnapshot());
        $this->assertArrayNotHasKey('limits', $this->storedApp());
    }

    // ==================================================================
    // 5. Mengosongkan → hapus file & entri limits
    // ==================================================================

    public function testPersistMengosongkanMenghapusFileDanEntriLimits(): void
    {
        $this->persist(['web' => ['cpus' => '1.5']]);
        $this->assertFileExists($this->overridePath());

        $result = $this->persist([]);

        $this->assertSame([], $result['limits']);
        $this->assertFileDoesNotExist($this->overridePath());

        $stored = $this->storedApp();
        $this->assertNull($stored['limits']);
        $this->assertNotContains(ResourceLimits::OVERRIDE_FILE, $stored['compose_files']);
        $this->assertSame(self::INITIAL_FILES, $stored['compose_files']);
    }

    // ==================================================================
    // 5b. Mengosongkan saat base compose tidak terbaca (V3)
    //
    // Jalur "kosongkan" tidak boleh mem-parse base compose: bila base hilang
    // (mis. `git pull` gagal / repo rusak) penghapusan batas wajib tetap jalan,
    // kalau tidak limit basi tetap berlaku pada `up` berikutnya.
    // ==================================================================

    public function testPersistMengosongkanTanpaBaseComposeTetapMenghapusFileDanMembersihkanState(): void
    {
        $this->seedLimitsWithoutBaseCompose();

        // Sebelum perbaikan: `RuntimeException: Base compose tidak ditemukan`.
        $result = $this->persistIn($this->noBaseDir, []);

        $this->assertSame([], $result['limits']);
        $this->assertFileDoesNotExist($this->noBaseOverridePath());

        $stored = $this->storedApp();
        $this->assertNull($stored['limits']);
        $this->assertNotContains(ResourceLimits::OVERRIDE_FILE, $stored['compose_files']);
        $this->assertSame(self::INITIAL_FILES, $stored['compose_files']);
        // Field lain tidak disentuh.
        $this->assertSame('running', $stored['status']);
    }

    public function testPersistMengosongkanSaatBaseComposeHilangSetelahSeedTetapBersih(): void
    {
        $this->persist(['web' => ['cpus' => '1.5', 'memory_mb' => '512']]);
        $this->assertFileExists($this->overridePath());

        // Simulasi `git pull` gagal / repo rusak: base compose lenyap dari disk.
        unlink($this->dir . '/docker-compose.yml');
        $this->assertFileDoesNotExist($this->dir . '/docker-compose.yml');

        $this->assertSame([], $this->persist([])['limits']);
        $this->assertFileDoesNotExist($this->overridePath());

        $stored = $this->storedApp();
        $this->assertNull($stored['limits']);
        $this->assertSame(self::INITIAL_FILES, $stored['compose_files']);
    }

    /**
     * Tanpa batas aktif, base compose juga tidak boleh diparse saat state sudah
     * kosong (idempoten) — termasuk entri yang ada tetapi kedua nilainya kosong.
     */
    public function testPersistTanpaBatasAktifTanpaBaseComposeTidakMemparseBase(): void
    {
        $this->assertSame([], $this->persistIn($this->noBaseDir, [])['limits']);
        $this->assertSame(
            [],
            $this->persistIn($this->noBaseDir, ['web' => ['cpus' => '', 'memory_mb' => '']])['limits']
        );

        $this->assertFileDoesNotExist($this->noBaseOverridePath());
        $stored = $this->storedApp();
        $this->assertNull($stored['limits']);
        $this->assertSame(self::INITIAL_FILES, $stored['compose_files']);
    }

    /**
     * Jalur batas aktif **tetap** fail-fast bila base compose tidak terbaca —
     * whitelist service butuh daftar service dari base.
     */
    public function testPersistBatasAktifTanpaBaseComposeTetapFailFast(): void
    {
        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persistIn($this->noBaseDir, ['web' => ['cpus' => '1.5']]);
            $this->fail('Batas aktif tanpa base compose wajib fail-fast.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Base compose tidak ditemukan', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->noBaseOverridePath());
        $this->assertSame($before, $this->appSnapshot());
    }

    /**
     * Validasi **nilai** tetap dijalankan sebelum base compose diparse: pesan
     * error wajib menyebut nilai/field, bukan "Base compose tidak ditemukan".
     *
     * @dataProvider nilaiTidakValidTanpaBaseCompose
     * @param array<string,mixed> $posted
     */
    public function testPersistValidasiNilaiTetapLebihDuluDaripadaParseBaseCompose(
        array $posted,
        string $expectedMessage
    ): void {
        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persistIn($this->noBaseDir, $posted);
            $this->fail('Nilai invalid wajib ditolak walau base compose tidak ada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            $this->assertStringNotContainsString('Base compose', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->noBaseOverridePath());
        $this->assertSame($before, $this->appSnapshot());
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function nilaiTidakValidTanpaBaseCompose(): array
    {
        return [
            'cpus nol' => [['web' => ['cpus' => 0]], 'CPU'],
            'memori di bawah minimum' => [['web' => ['memory_mb' => '5']], 'memori'],
            'memori bersatuan' => [['web' => ['memory_mb' => '128MB']], 'memori'],
        ];
    }

    // ==================================================================
    // 5b. Guard format (nilai entri) & service bernama numerik
    // ==================================================================

    /**
     * Payload skalar (`['salah']`, key `0`) ditolak sebagai masalah **format**
     * dengan pesan yang menyebut key-nya sebagai "entri" — bukan seolah-olah ada
     * service bernama "0"; tanpa file/state yang berubah.
     */
    public function testPersistMenolakPayloadSkalarTanpaMengubahState(): void
    {
        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persist(['salah']);
            $this->fail('Payload skalar wajib ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Format batas CPU/memori tidak valid', $e->getMessage());
            $this->assertStringContainsString('entri "0"', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->overridePath());
        $this->assertSame($before, $this->appSnapshot());
        $this->assertArrayNotHasKey('limits', $this->storedApp());
    }

    /**
     * Service bernama numerik murni (compose mengizinkan nama seperti `0`):
     * `normalize()` menerimanya, tetapi `symfony/yaml` tidak bisa menulis key
     * numerik sebagai map service → file override TIDAK ditulis & state tidak
     * berubah (fail-fast sebelum efek samping, bukan override rusak).
     */
    public function testPersistServiceBernamaNumerikFailFastTanpaMengubahState(): void
    {
        file_put_contents($this->dir . '/docker-compose.yml', <<<'YAML'
        services:
          0:
            image: x
          web:
            image: nginx
        YAML);

        // Prasyarat: base YAML valid & service "0" dikenal sebagai service.
        $this->assertSame(['0', 'web'], ResourceLimits::services($this->dir, ['docker-compose.yml']));

        $before = $this->appSnapshot();
        $this->assertIsString($before);

        try {
            $this->persist(['0' => ['cpus' => '1']]);
            $this->fail('Service bernama numerik wajib fail-fast saat menulis override.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sepenuhnya numerik', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->overridePath());
        $this->assertSame($before, $this->appSnapshot());
        $this->assertArrayNotHasKey('limits', $this->storedApp());
    }

    // ==================================================================
    // 6. Idempoten
    // ==================================================================

    public function testPersistIdempotenTidakMengubahUrutanAtauIsiFile(): void
    {
        $posted = [
            'web' => ['cpus' => '1.5', 'memory_mb' => '512'],
            'db' => ['memory_mb' => '256'],
        ];

        $this->persist($posted);
        $firstOrder = $this->storedApp()['compose_files'];
        $firstFile = (string) file_get_contents($this->overridePath());

        $this->persist($posted);
        $stored = $this->storedApp();

        $this->assertSame($firstOrder, $stored['compose_files']);
        $this->assertSame($firstFile, (string) file_get_contents($this->overridePath()));
        $this->assertIsArray($this->store->find(self::APP_ID));
        $this->assertSame([
            'web' => ['cpus' => 1.5, 'memory_mb' => 512],
            'db' => ['cpus' => null, 'memory_mb' => 256],
        ], $stored['limits']);
    }

    // ==================================================================
    // 7. orderFiles() — urutan kanonik
    // ==================================================================

    public function testOrderFilesMenyusunUrutanKanonik(): void
    {
        // Invarian kanonik: reset < ports < names < limits < networks < env.
        $ordered = ComposeSource::orderFiles([
            EnvManager::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ComposeSource::RESET_OVERRIDE_FILE,
            'docker-compose.yml',
        ]);

        $this->assertSame([
            'docker-compose.yml',
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            // Non-prioritas mengikuti urutan MASUKAN (di sini env mendahului networks).
            EnvManager::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
        ], $ordered);
    }

    /**
     * Non-prioritas (networks/env) mengikuti urutan masukan; karena itu daftar
     * yang sudah tersimpan dalam urutan kanonik tetap kanonik setelahnya.
     */
    public function testOrderFilesMempertahankanUrutanNetworksSebelumEnv(): void
    {
        $this->assertSame([
            'docker-compose.yml',
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
            EnvManager::OVERRIDE_FILE,
        ], ComposeSource::orderFiles([
            'docker-compose.yml',
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            NetworkManager::OVERRIDE_FILE,
            EnvManager::OVERRIDE_FILE,
        ]));
    }

    public function testOrderFilesMempertahankanUrutanBaseDanMembuangDuplikatNamaKosong(): void
    {
        // Beberapa base → urutan asli dipertahankan; duplikat & '' dibuang.
        $this->assertSame([
            'compose.yaml',
            'docker-compose.yml',
            ComposeSource::PORTS_OVERRIDE_FILE,
        ], ComposeSource::orderFiles([
            'compose.yaml',
            '',
            'docker-compose.yml',
            'compose.yaml',
            ComposeSource::PORTS_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
        ]));
    }

    public function testOrderFilesTanpaFileLimitsTidakMenyisipkanApaPun(): void
    {
        $ordered = ComposeSource::orderFiles([
            EnvManager::OVERRIDE_FILE,
            'docker-compose.yml',
            ContainerNames::OVERRIDE_FILE,
        ]);

        $this->assertSame([
            'docker-compose.yml',
            ContainerNames::OVERRIDE_FILE,
            EnvManager::OVERRIDE_FILE,
        ], $ordered);
        $this->assertNotContains(ResourceLimits::OVERRIDE_FILE, $ordered);
    }

    public function testOrderFilesDaftarKosong(): void
    {
        $this->assertSame([], ComposeSource::orderFiles([]));
        $this->assertSame([], ComposeSource::orderFiles(['', '']));
    }
}
