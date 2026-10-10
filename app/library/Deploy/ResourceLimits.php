<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Storage\AppStore;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Override **batas maksimum CPU & memori** per service per app — pasangan dari
 * override host port / nama container / env.
 *
 * Sumber kebenaran: field `limits` di apps.json —
 * `{ "<service>": { "cpus": ?float, "memory_mb": ?int } }`. Field kosong =
 * *tidak diatur dashboard* (key tidak ditulis), sehingga nilai CPU/memori milik
 * base compose repo tetap berlaku. `!reset` **tidak** dipakai (belum
 * terverifikasi pada versi compose yang didukung).
 *
 * Representasi di disk: `apps/{name}/docker-compose.override.limits.yml`.
 *
 * ## Keluarga field (WAJIB ditiru dari base compose, bukan pilihan bebas)
 *
 * Compose menolak mencampur gaya legacy dan modern dengan nilai berbeda
 * (`services.<x>: can't set distinct values on 'cpus' and
 * deploy.resources.limits.cpus'`); tag `!override` tidak menyelamatkan.
 *
 * Aturan **terverifikasi empiris** (Compose v5.5.1, non-swarm) — untuk tiap
 * service & tiap resource (CPU / memori):
 *
 * 1. Bila base compose menulis key legacy canonical (`cpus` / `mem_limit`) dan
 *    service itu punya blok `deploy.resources.limits` (key **apa pun** di
 *    dalamnya, termasuk untuk resource lain), maka pasangan modern-nya
 *    (`deploy.resources.limits.cpus` / `.memory`) **wajib** ada dengan nilai
 *    identik. Melanggar ⇒ compose menolak project (bahkan bila key legacy-nya
 *    baru muncul dari file override).
 * 2. Blok `deploy.resources.reservations` **tidak** memicu aturan di atas
 *    (reservation bukan limit), dan key legacy "saudara" (`cpu_shares`,
 *    `mem_reservation`, …) juga tidak.
 * 3. Karena itu rencana tulis ditentukan **per service**:
 *    - base punya blok `deploy.resources.limits` → tulis **modern**; bila base
 *      juga memakai key legacy canonical untuk resource tsb, tulis **keduanya**
 *      (nilai identik) agar pasangan legacy/modern tidak berbeda;
 *    - base tidak punya blok `deploy.resources.limits` → tulis **legacy**
 *      (`cpus`/`mem_limit`), termasuk saat base hanya memakai key saudara atau
 *      reservation — portabel v1 & v2 dan tanpa warning deprecation.
 *
 * Base yang **tidak konsisten** ditolak lebih dahulu (fail-fast, file tidak
 * ditulis): baik nilai berbeda antar keluarga, maupun key legacy canonical yang
 * tidak punya pasangan di blok `deploy.resources.limits` — keduanya membuat
 * `docker compose` menolak project.
 *
 * Deteksi dijalankan **setiap** `sync()`/`writeOverride()` (bukan hanya saat
 * save) karena base compose app git bisa berubah gaya setelah `git pull`.
 *
 * Stateless & murni statik — aman untuk worker Webman persistent.
 */
final class ResourceLimits
{
    public const OVERRIDE_FILE = 'docker-compose.override.limits.yml';

    /** Batas minimum memori (MB) — di bawah ini container tak bisa start. */
    public const MIN_MEMORY_MB = 6;

    /** Batas maksimum memori (MB) = 1 TiB. */
    public const MAX_MEMORY_MB = 1048576;

    /** Batas maksimum CPU (jumlah core). */
    public const MAX_CPUS = 1024.0;

    /** Titik skala pertama slider CPU (core); langkah berikutnya kelipatan SCALE_CPU_STEP. */
    public const SCALE_CPU_START = 0.5;
    /** Langkah skala slider CPU (core) setelah titik pertama. */
    public const SCALE_CPU_STEP = 1.0;
    /** Titik skala pertama slider memori (MB); langkah berikutnya kelipatan SCALE_MEMORY_STEP_MB. */
    public const SCALE_MEMORY_START_MB = 512;
    /** Langkah skala slider memori (MB) setelah titik pertama (1 GB). */
    public const SCALE_MEMORY_STEP_MB = 1024;

    public const FAMILY_LEGACY = 'legacy';
    public const FAMILY_MODERN = 'modern';

    private const BYTES_PER_MB = 1048576;

    /** Toleransi perbandingan titik skala CPU (float) agar `1` (int) = `1.0`. */
    private const SCALE_EPSILON = 1e-9;

    // ==================================================================
    // Normalisasi & validasi input user
    // ==================================================================

    /**
     * Normalisasi & validasi nilai batas dari input user:
     * map `service => ['cpus' => ?float, 'memory_mb' => ?int]`.
     *
     * Aturan: entri wajib array; sub-field selain `cpus`/`memory_mb` ditolak;
     * `cpus` numerik `> 0` dan `<= MAX_CPUS`; `memory_mb` **digit murni**
     * (float / `"128MB"` ditolak) `>= MIN_MEMORY_MB` dan `<= MAX_MEMORY_MB`;
     * kosong/absen → `null` (= tidak diatur). Pesan error menyebut service +
     * field. Tidak menyentuh disk.
     *
     * Bentuk input dinilai dari **nilai entri**, bukan dari bentuk list: map
     * yang key-nya numerik murni (compose mengizinkan service bernama `0`; PHP
     * meng-cast key `"0"` → `int 0`) tetap sah. Yang ditolak adalah entri yang
     * **nilainya** bukan array (mis. `['salah']` atau `['web' => 'x']`) — pesan
     * menyebut key-nya sebagai "entri", bukan seolah-olah ada service bernama
     * "0".
     *
     * @param array<int|string,mixed> $posted
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    public static function normalize(array $posted): array
    {
        $result = [];
        foreach ($posted as $rawService => $fields) {
            $key = (string) $rawService;
            if (!is_array($fields)) {
                throw new RuntimeException(
                    'Format batas CPU/memori tidak valid pada entri "' . $key
                    . '": setiap service wajib berupa map {cpus, memory_mb}, bukan nilai skalar.'
                );
            }
            $service = trim($key);
            if ($service === '') {
                throw new RuntimeException('Nama service pada batas CPU/memori tidak boleh kosong.');
            }
            foreach (array_keys($fields) as $fieldKey) {
                if (!in_array((string) $fieldKey, ['cpus', 'memory_mb'], true)) {
                    throw new RuntimeException(
                        'Field "' . (string) $fieldKey . '" tidak dikenal pada batas CPU/memori service "' . $service . '".'
                    );
                }
            }

            $result[$service] = [
                'cpus' => self::normalizeCpus($fields['cpus'] ?? null, $service),
                'memory_mb' => self::normalizeMemoryMb($fields['memory_mb'] ?? null, $service),
            ];
        }
        return $result;
    }

    /**
     * Validasi input form batas CPU/memori terhadap daftar service yang dikenal:
     * tolak service di luar base compose (fail-fast, pesan menyebut namanya),
     * normalkan nilai, lalu buang entri yang kedua nilainya kosong (tidak aktif).
     *
     * Murni statik — tidak menyentuh disk.
     *
     * @param array<string,mixed> $posted   input mentah dari form
     * @param array<int,string>   $services nama service yang dikenal (base compose)
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    public static function fromInput(array $posted, array $services): array
    {
        if ($posted === []) {
            return [];
        }

        $known = array_map('strval', $services);
        $unknown = [];
        foreach (array_keys($posted) as $service) {
            if (!in_array((string) $service, $known, true)) {
                $unknown[] = (string) $service;
            }
        }
        if ($unknown !== []) {
            throw new RuntimeException(
                'Service tidak dikenal pada compose app: ' . implode(', ', $unknown)
                . '. Muat ulang halaman lalu coba lagi.'
            );
        }

        $active = [];
        foreach (self::normalize($posted) as $service => $limit) {
            if ($limit['cpus'] !== null || $limit['memory_mb'] !== null) {
                $active[$service] = $limit;
            }
        }
        return $active;
    }

    /**
     * Batas tersimpan app (field `limits`) dalam bentuk ternormalisasi, tanpa
     * entri yang kedua nilainya kosong (tidak aktif).
     *
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    public static function of(array $app): array
    {
        $raw = $app['limits'] ?? [];
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $limits = [];
        foreach (self::normalize($raw) as $service => $limit) {
            if ($limit['cpus'] !== null || $limit['memory_mb'] !== null) {
                $limits[$service] = $limit;
            }
        }
        return $limits;
    }

    /**
     * Apakah ada minimal satu service dengan batas CPU/memori aktif.
     *
     * @param array<string,array{cpus:?float,memory_mb:?int}> $limits
     */
    public static function isActive(array $limits): bool
    {
        foreach ($limits as $limit) {
            if (!is_array($limit)) {
                continue;
            }
            if (($limit['cpus'] ?? null) !== null || ($limit['memory_mb'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    // ==================================================================
    // Skala slider form create (CPU & memori)
    // ==================================================================

    /**
     * Skala slider CPU untuk form create: `[null, 0.5, 1, 2, 3, …]`.
     *
     * `null` di indeks 0 = posisi "tanpa batas / default akun" (sama artinya dengan
     * input kosong yang sudah didukung `normalize()`). Titik pertama selalu
     * `SCALE_CPU_START`, sisanya `SCALE_CPU_STEP` core kelipatan selama `<= $maxCpus`.
     * Nilai `$current` yang **tidak persis** di titik skala (mis. 1.5 dari compose
     * repo) disisipkan supaya tidak berubah diam-diam saat form disimpan.
     *
     * Nilai di luar jangkauan skala (`<= 0` atau `> $maxCpus`) **tidak** disisipkan
     * dan dikembalikan sebagai `value = null`: skala berhenti di `$maxCpus` sehingga
     * plafon tetap terjaga di sisi klien, dan form jatuh ke posisi "tanpa batas /
     * default" (server tetap penegak terakhir).
     *
     * @return array{stops:list<float|null>,index:int,value:?float} `index`/`value` =
     *         posisi & nilai kirim untuk `$current` (`0`/`null` bila kosong/tidak sah)
     */
    public static function cpuScale(float $maxCpus, float|int|null $current = null): array
    {
        $stops = [null, self::SCALE_CPU_START];
        for ($cpus = self::SCALE_CPU_STEP; $cpus <= $maxCpus; $cpus += self::SCALE_CPU_STEP) {
            $stops[] = (float) $cpus;
        }

        $current = self::validScaleValue($current);
        if ($current === null || $current > $maxCpus) {
            return ['stops' => $stops, 'index' => 0, 'value' => null];
        }

        $index = self::insertFloatStop($stops, $current);

        return ['stops' => $stops, 'index' => $index, 'value' => $stops[$index]];
    }

    /**
     * Skala slider memori (MB) untuk form create: `[null, 512, 1024, 2048, …]`.
     * Aturan sama dengan `cpuScale()`: `null` = tanpa batas/default, titik pertama
     * `SCALE_MEMORY_START_MB`, sisanya `SCALE_MEMORY_STEP_MB` (1 GB) kelipatan
     * selama `<= $maxMemoryMb`, nilai `$current` di luar titik skala disisipkan.
     *
     * Sama seperti `cpuScale()`: nilai di luar jangkauan (`< MIN_MEMORY_MB` atau
     * `> $maxMemoryMb`) tidak disisipkan & `value = null` (posisi "tanpa batas").
     *
     * @return array{stops:list<int|null>,index:int,value:?int}
     */
    public static function memoryScale(int $maxMemoryMb, int|float|null $current = null): array
    {
        $stops = [null, self::SCALE_MEMORY_START_MB];
        for ($mb = self::SCALE_MEMORY_STEP_MB; $mb <= $maxMemoryMb; $mb += self::SCALE_MEMORY_STEP_MB) {
            $stops[] = $mb;
        }

        $raw = self::validScaleValue($current);
        $mb = $raw === null ? null : (int) $raw;
        if ($mb === null || $mb < self::MIN_MEMORY_MB || $mb > $maxMemoryMb) {
            return ['stops' => $stops, 'index' => 0, 'value' => null];
        }

        $index = self::insertIntStop($stops, $mb);

        return ['stops' => $stops, 'index' => $index, 'value' => $stops[$index]];
    }

    /**
     * Nilai `$current` yang boleh disisipkan ke skala: numerik & **> 0**.
     * `null`/non-numerik/`bool`/`NaN`/`INF`/`<= 0` → `null` (tidak disisipkan).
     */
    private static function validScaleValue(int|float|null $current): ?float
    {
        if ($current === null) {
            return null;
        }
        $value = (float) $current;

        return ($value > 0.0 && is_finite($value)) ? $value : null;
    }

    /**
     * Sisipkan stop CPU (float) pada posisi menaik, `null` tetap di depan;
     * kembalikan indeksnya. Nilai yang sudah ada (longgar-setara, mis. `1` = `1.0`)
     * tidak diduplikasi.
     *
     * @param list<float|null> $stops
     */
    private static function insertFloatStop(array &$stops, float $value): int
    {
        foreach ($stops as $i => $stop) {
            if ($stop !== null && abs($stop - $value) < self::SCALE_EPSILON) {
                return $i;
            }
        }

        $stops[] = $value;
        self::sortStops($stops);

        foreach ($stops as $i => $stop) {
            if ($stop !== null && abs($stop - $value) < self::SCALE_EPSILON) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * Sisipkan stop memori (int) pada posisi menaik, `null` tetap di depan;
     * kembalikan indeksnya. Nilai yang sudah ada tidak diduplikasi.
     *
     * @param list<int|null> $stops
     */
    private static function insertIntStop(array &$stops, int $value): int
    {
        foreach ($stops as $i => $stop) {
            if ($stop === $value) {
                return $i;
            }
        }

        $stops[] = $value;
        self::sortStops($stops);

        foreach ($stops as $i => $stop) {
            if ($stop === $value) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * Urutkan stop menaik dengan `null` selalu di depan (posisi "tanpa batas").
     *
     * @param list<float|int|null> $stops
     */
    private static function sortStops(array &$stops): void
    {
        usort($stops, static function ($a, $b): int {
            if ($a === null) {
                return $b === null ? 0 : -1;
            }
            if ($b === null) {
                return 1;
            }
            return $a <=> $b;
        });
    }

    /**
     * Simpan batas CPU/memori dari input form dalam satu langkah transparan:
     * validasi → tulis/hapus `docker-compose.override.limits.yml` → persist state.
     *
     * Urutan penting: file ditulis **sebelum** `apps.json` di-update, sehingga
     * kegagalan validasi/penulisan membuat state app tidak berubah sama sekali
     * (tanpa penulisan parsial). Field yang diubah hanya `limits` dan
     * `compose_files`; `status`/`message`/`containers`/field lain tidak disentuh.
     *
     * Validasi **nilai** (`normalize()`) selalu dijalankan lebih dulu — termasuk
     * pada jalur mengosongkan — agar input berisi nilai invalid tidak diam-diam
     * diabaikan. Base compose hanya diparse bila memang ada batas aktif
     * (`services()` + whitelist service); akibatnya **mengosongkan batas tetap
     * bisa dilakukan walau base compose sementara tidak ada di disk** (mis.
     * `git pull` gagal / repo rusak) — `writeOverride([], …)` hanya menghapus file
     * lama tanpa mem-parse base, sehingga limit basi tidak tertinggal.
     *
     * Sengaja **tanpa** Engine/Deployer/flash/redirect agar bisa diuji murni;
     * pemanggil (controller) yang bertanggung jawab recreate container.
     *
     * @param array<string,mixed> $app    entri app dari store (butuh `id` & `compose_files`)
     * @param array<string,mixed> $posted input mentah form `limits`
     * @return array{limits:array<string,array{cpus:?float,memory_mb:?int}>,app:array}
     */
    public static function persist(AppStore $store, array $app, string $dir, array $posted): array
    {
        $id = (string) ($app['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('App tanpa id tidak bisa menyimpan batas CPU/memori.');
        }

        $composeFiles = (array) ($app['compose_files'] ?? ['docker-compose.yml']);

        // Validasi nilai SELALU lebih dulu (juga saat mengosongkan): input berisi
        // nilai invalid tidak boleh diam-diam diabaikan.
        $normalized = self::normalize($posted);

        // Tanpa batas aktif, base compose **tidak** diparse — mengosongkan batas
        // tetap bisa walau base compose sementara tidak ada di disk (mis. `git
        // pull` gagal); `writeOverride([])` hanya menghapus file override lama.
        $limits = [];
        if (self::isActive($normalized)) {
            $services = self::services($dir, $composeFiles);
            $limits = self::fromInput($posted, $services);
        }

        // Tulis/hapus file lebih dulu — gagal ⇒ apps.json tidak berubah.
        self::writeOverride($dir, $composeFiles, $limits);

        $store->update($id, function (array &$s) use ($limits, $composeFiles): void {
            $s['limits'] = $limits !== [] ? $limits : null;
            $files = array_values(array_filter(
                $composeFiles,
                static fn (string $f): bool => $f !== self::OVERRIDE_FILE
            ));
            if ($limits !== []) {
                $files[] = self::OVERRIDE_FILE;
            }
            $s['compose_files'] = ComposeSource::orderFiles($files);
        });

        return ['limits' => $limits, 'app' => $store->find($id) ?? $app];
    }

    // ==================================================================
    // Base compose: service & deteksi gaya/nilai
    // ==================================================================

    /**
     * Nama-nama service dari **base compose** (file utama app), urut seperti
     * ditulis di file.
     *
     * @param array<int,string> $composeFiles
     * @return array<int,string>
     */
    public static function services(string $dir, array $composeFiles): array
    {
        $declared = self::declaredServices(self::parseBase($dir, $composeFiles));
        if ($declared === []) {
            throw new RuntimeException('Tidak ada service pada base compose app.');
        }

        return array_values(array_map('strval', array_keys($declared)));
    }

    /**
     * Deteksi batas CPU/memori yang sudah tertulis di base compose, per service —
     * dipakai untuk **prefill** form & menentukan keluarga field yang harus
     * ditulis saat menulis override.
     *
     * `cpu_families`/`memory_families` = **rencana tulis** (bukan sekadar gaya
     * base): `['legacy']`, `['modern']`, atau `['legacy','modern']` (dual).
     *
     * Melempar RuntimeException bila base compose sendiri tidak konsisten
     * (Compose akan menolak project).
     *
     * @param array<int,string> $composeFiles
     * @return array<string,array{cpus:?float,memory_mb:?int,cpu_families:array<int,string>,memory_families:array<int,string>}>
     */
    public static function detect(string $dir, array $composeFiles): array
    {
        $declared = self::declaredServices(self::parseBase($dir, $composeFiles));
        $result = [];
        foreach ($declared as $name => $config) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $result[$name] = self::detectService($name, is_array($config) ? $config : []);
        }
        return $result;
    }

    // ==================================================================
    // Representasi di disk
    // ==================================================================

    /**
     * Tulis `docker-compose.override.limits.yml` sesuai keluarga field yang
     * dipakai base compose. Service tanpa nilai (atau tidak ada di base) tidak
     * ditulis; bila tidak ada yang perlu ditulis, file dihapus & return `false`.
     *
     * Fail-fast (tanpa menulis file) bila service yang akan ditulis seluruhnya
     * bernama numerik — `symfony/yaml` menuliskannya sebagai sequence, bukan map
     * (lihat komentar di badan method).
     *
     * @param array<int,string>                                $composeFiles
     * @param array<string,array{cpus:?float,memory_mb:?int}>  $limits
     * @return bool true bila file override ditulis
     */
    public static function writeOverride(string $dir, array $composeFiles, array $limits): bool
    {
        $normalized = self::normalize($limits);

        // Tidak ada yang perlu ditulis → hapus file & keluar lebih awal.
        // Base compose tidak di-parse: jalur create app dengan `limits` kosong
        // tidak boleh terpengaruh bentuk base compose.
        $active = [];
        foreach ($normalized as $service => $limit) {
            if ($limit['cpus'] !== null || $limit['memory_mb'] !== null) {
                $active[$service] = $limit;
            }
        }
        if ($active === []) {
            self::removeOverride($dir);
            return false;
        }

        $detected = self::detect($dir, $composeFiles);

        $data = ['services' => []];
        foreach ($active as $service => $limit) {
            $cpus = $limit['cpus'];
            $memoryMb = $limit['memory_mb'];
            if (!isset($detected[$service])) {
                continue; // service sudah tidak ada di base compose (mis. setelah git pull)
            }

            // Rencana tulis dari detect(): ['legacy'], ['modern'], atau keduanya.
            $cpuFamilies = $detected[$service]['cpu_families'];
            $memoryFamilies = $detected[$service]['memory_families'];

            $entry = [];
            if ($cpus !== null) {
                foreach ($cpuFamilies as $family) {
                    if ($family === self::FAMILY_MODERN) {
                        $entry['deploy']['resources']['limits']['cpus'] = $cpus;
                    } else {
                        $entry['cpus'] = $cpus;
                    }
                }
            }
            if ($memoryMb !== null) {
                $value = $memoryMb . 'm';
                foreach ($memoryFamilies as $family) {
                    if ($family === self::FAMILY_MODERN) {
                        $entry['deploy']['resources']['limits']['memory'] = $value;
                    } else {
                        $entry['mem_limit'] = $value;
                    }
                }
            }

            $data['services'][$service] = $entry;
        }

        if ($data['services'] === []) {
            self::removeOverride($dir);
            return false;
        }

        // GOTCHA: `symfony/yaml` tidak bisa menulis **key numerik** sebagai map —
        // array ber-key numerik berurutan (`['0' => …]` tetap `array_is_list` di
        // PHP) keluar sebagai *sequence* (`services:\n  - …`), dan key numerik
        // lain keluar tanpa kutip (`0:`) sehingga terbaca sebagai int, bukan
        // string. Keduanya membuat `services` bukan map service berbasis string
        // seperti yang diharapkan `docker compose`. Fail-fast di sini (sebelum
        // file ditulis) daripada menghasilkan override rusak; service sepenuhnya
        // numerik harus dinamai ulang di base compose.
        foreach (array_keys($data['services']) as $name) {
            if (preg_match('/^[0-9]+$/', (string) $name) === 1) {
                throw new RuntimeException(
                    'Nama service "' . (string) $name . '" sepenuhnya numerik sehingga tidak bisa '
                    . 'ditulis sebagai map service pada ' . self::OVERRIDE_FILE
                    . ' (symfony/yaml menulis key numerik sebagai sequence/unquoted int). '
                    . 'Ganti nama service di base compose.'
                );
            }
        }

        $yaml = Yaml::dump($data, 4, 2);
        if (@file_put_contents($dir . '/' . self::OVERRIDE_FILE, $yaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . self::OVERRIDE_FILE . '.');
        }
        return true;
    }

    /**
     * Hapus override batas CPU/memori (saat batas dikosongkan / app dihapus).
     */
    public static function removeOverride(string $dir): void
    {
        $path = $dir . '/' . self::OVERRIDE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Sinkronkan file override dengan state app. Idempoten — dipanggil dari
     * controller (saveLimits) dan `LocalDeployer` (deploy/rebuild/rollback/
     * apply/applyEnv) agar file di disk selalu konsisten dengan apps.json.
     *
     * **Penting**: bila tidak ada batas aktif, override basi **dihapus** lalu
     * return `false` — tanpa mem-parse base compose (jalur create app & test
     * yang tidak punya base compose tidak boleh terpengaruh) dan tanpa error
     * bila file memang belum ada (murni no-op). Tanpa penghapusan ini, limit
     * lama tetap berlaku pada setiap `up` setelah `limits` dikosongkan di
     * apps.json.
     *
     * @param array<int,string> $composeFiles
     * @return bool true bila override aktif (file ditulis)
     */
    public static function sync(array $app, string $dir, array $composeFiles): bool
    {
        $limits = self::of($app);
        if (!self::isActive($limits)) {
            // File di disk wajib selalu cerminan apps.json → buang override basi
            // (idempoten: tanpa file = no-op, tanpa error).
            self::removeOverride($dir);
            return false;
        }

        return self::writeOverride($dir, $composeFiles, $limits);
    }

    // ==================================================================
    // Parsing nilai memori
    // ==================================================================

    /**
     * Konversi nilai memori compose ke MB (pembulatan ke atas, minimum 1).
     *
     * Didukung: integer/float byte polos, serta akhiran `b`/`k`/`m`/`g`/`t`
     * (case-insensitive, boleh `kb`/`mb`/… dan desimal seperti `1.5g`).
     *
     * @param mixed  $raw     nilai dari compose (int|float|string)
     * @param string $service nama service (untuk pesan error)
     */
    public static function memoryMbFrom(mixed $raw, string $service = ''): int
    {
        $label = $service !== '' ? ' pada service "' . $service . '"' : '';

        if (is_int($raw) || is_float($raw)) {
            if (!is_finite((float) $raw) || $raw < 0) {
                throw new RuntimeException('Nilai memori tidak valid' . $label . ': "' . (string) $raw . '".');
            }
            return max(1, (int) ceil((float) $raw / self::BYTES_PER_MB));
        }

        $value = trim((string) $raw);
        if ($value === '' || preg_match('/^([0-9]*\.?[0-9]+)\s*([a-zA-Z]{0,2})$/', $value, $matches) !== 1) {
            throw new RuntimeException('Nilai memori tidak valid' . $label . ': "' . $value . '".');
        }

        $number = (float) $matches[1];
        $factor = match (strtolower($matches[2])) {
            '', 'b' => 1,
            'k', 'kb' => 1024,
            'm', 'mb' => self::BYTES_PER_MB,
            'g', 'gb' => 1073741824,
            't', 'tb' => 1099511627776,
            default => throw new RuntimeException(
                'Satuan memori tidak dikenal' . $label . ': "' . $matches[2] . '".'
            ),
        };

        return max(1, (int) ceil($number * $factor / self::BYTES_PER_MB));
    }

    // ==================================================================
    // Helper privat
    // ==================================================================

    /**
     * @param array<string,mixed> $fields
     */
    private static function normalizeCpus(mixed $raw, string $service): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && trim($raw) === '') {
            return null;
        }
        if (!is_numeric($raw) || is_bool($raw)) {
            throw new RuntimeException('Batas CPU service "' . $service . '" harus berupa angka (contoh: 1.5).');
        }

        $cpus = (float) $raw;
        if ($cpus <= 0.0) {
            throw new RuntimeException('Batas CPU service "' . $service . '" harus lebih besar dari 0.');
        }
        if ($cpus > self::MAX_CPUS) {
            throw new RuntimeException(
                'Batas CPU service "' . $service . '" maksimal ' . self::MAX_CPUS . ' core.'
            );
        }
        return $cpus;
    }

    private static function normalizeMemoryMb(mixed $raw, string $service): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && trim($raw) === '') {
            return null;
        }

        $value = is_int($raw) || is_float($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
        if ($value === '' || !ctype_digit($value)) {
            throw new RuntimeException(
                'Batas memori service "' . $service . '" harus berupa angka MB tanpa satuan (contoh: 512).'
            );
        }

        $mb = (int) $value;
        if ($mb < self::MIN_MEMORY_MB) {
            throw new RuntimeException(
                'Batas memori service "' . $service . '" minimal ' . self::MIN_MEMORY_MB . ' MB.'
            );
        }
        if ($mb > self::MAX_MEMORY_MB) {
            throw new RuntimeException(
                'Batas memori service "' . $service . '" maksimal ' . self::MAX_MEMORY_MB . ' MB.'
            );
        }
        return $mb;
    }

    /**
     * @return array<string,mixed> data base compose
     */
    private static function parseBase(string $dir, array $composeFiles): array
    {
        $base = ComposeSource::mainFileFrom($composeFiles);
        if ($base === '') {
            $base = ComposeSource::detectMainFile($dir);
        }
        $base = $base !== '' ? $base : ComposeSource::MAIN_FILES[0];

        $path = $dir . '/' . $base;
        if (!is_file($path)) {
            throw new RuntimeException('Base compose tidak ditemukan: ' . $path);
        }
        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal parse ' . $base . ': ' . $e->getMessage());
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> services (nama => config)
     */
    private static function declaredServices(array $data): array
    {
        $services = $data['services'] ?? [];
        return is_array($services) ? $services : [];
    }

    /**
     * Deteksi nilai + rencana keluarga field untuk satu service base compose.
     *
     * @param array<string,mixed> $config
     * @return array{cpus:?float,memory_mb:?int,cpu_families:array<int,string>,memory_families:array<int,string>}
     */
    private static function detectService(string $service, array $config): array
    {
        $deploy = is_array($config['deploy'] ?? null) ? $config['deploy'] : [];
        $resources = is_array($deploy['resources'] ?? null) ? $deploy['resources'] : [];
        $limits = is_array($resources['limits'] ?? null) ? $resources['limits'] : [];

        // Blok `deploy.resources.limits` (key APA PUN, termasuk resource lain)
        // memicu aturan Compose: key legacy canonical wajib punya pasangan modern
        // bernilai sama. `reservations` & key legacy "saudara" tidak memicunya.
        $hasLimitsBlock = $limits !== [];

        $rawLegacyCpus = $config['cpus'] ?? null;
        $rawLegacyMemory = $config['mem_limit'] ?? null;
        $hasLegacyCpus = array_key_exists('cpus', $config) && $rawLegacyCpus !== null;
        $hasLegacyMemory = array_key_exists('mem_limit', $config) && $rawLegacyMemory !== null;

        $legacyCpus = $hasLegacyCpus ? self::requireNumber($rawLegacyCpus, $service, 'cpus') : null;
        $modernCpus = array_key_exists('cpus', $limits)
            ? self::requireNumber($limits['cpus'], $service, 'deploy.resources.limits.cpus')
            : null;
        $legacyMemoryMb = $hasLegacyMemory ? self::memoryMbFrom($rawLegacyMemory, $service) : null;
        $modernMemoryMb = array_key_exists('memory', $limits)
            ? self::memoryMbFrom($limits['memory'], $service)
            : null;

        // Fail-fast #1: nilai berbeda antar keluarga untuk key yang sama.
        if ($legacyCpus !== null && $modernCpus !== null && abs($legacyCpus - $modernCpus) > 1e-9) {
            throw new RuntimeException(self::conflictMessage(
                $service,
                'CPU',
                'cpus',
                (string) $rawLegacyCpus,
                'deploy.resources.limits.cpus',
                (string) $limits['cpus']
            ));
        }
        if ($legacyMemoryMb !== null && $modernMemoryMb !== null && $legacyMemoryMb !== $modernMemoryMb) {
            throw new RuntimeException(self::conflictMessage(
                $service,
                'memori',
                'mem_limit',
                (string) $rawLegacyMemory,
                'deploy.resources.limits.memory',
                (string) $limits['memory']
            ));
        }

        // Fail-fast #2: base memakai key legacy canonical tanpa pasangan modern,
        // padahal blok `deploy.resources.limits` ada → Compose menolak project.
        if ($hasLimitsBlock) {
            if ($hasLegacyCpus && $modernCpus === null) {
                throw new RuntimeException(self::inconsistentMessage($service, 'cpus', 'deploy.resources.limits.cpus'));
            }
            if ($hasLegacyMemory && $modernMemoryMb === null) {
                throw new RuntimeException(self::inconsistentMessage($service, 'mem_limit', 'deploy.resources.limits.memory'));
            }
        }

        return [
            'cpus' => $modernCpus ?? $legacyCpus,
            'memory_mb' => $modernMemoryMb ?? $legacyMemoryMb,
            'cpu_families' => self::familiesFor($hasLimitsBlock, $hasLegacyCpus),
            'memory_families' => self::familiesFor($hasLimitsBlock, $hasLegacyMemory),
        ];
    }

    /**
     * Rencana penulisan keluarga field untuk satu resource.
     *
     * @return array<int,string>
     */
    private static function familiesFor(bool $hasLimitsBlock, bool $hasCanonicalLegacy): array
    {
        if (!$hasLimitsBlock) {
            return [self::FAMILY_LEGACY];
        }
        if (!$hasCanonicalLegacy) {
            return [self::FAMILY_MODERN];
        }
        // Base dual → tulis keduanya dengan nilai identik (wajib, kalau tidak
        // pasangan legacy/modern hasil merge akan berbeda).
        return [self::FAMILY_LEGACY, self::FAMILY_MODERN];
    }

    private static function requireNumber(mixed $raw, string $service, string $key): float
    {
        if (!is_numeric($raw) || is_bool($raw)) {
            throw new RuntimeException(
                'Nilai `' . $key . '` pada service "' . $service . '" bukan angka: "'
                . (is_scalar($raw) ? (string) $raw : gettype($raw)) . '".'
            );
        }
        return (float) $raw;
    }

    private static function inconsistentMessage(string $service, string $legacyKey, string $modernKey): string
    {
        return 'Base compose tidak konsisten pada service "' . $service . '": `' . $legacyKey
            . '` ditulis tanpa `' . $modernKey . '` padahal service memakai blok '
            . '`deploy.resources.limits` — `docker compose` menolak project seperti ini. '
            . 'Hapus salah satu gaya atau tulis keduanya dengan nilai sama.';
    }

    private static function conflictMessage(
        string $service,
        string $resource,
        string $legacyKey,
        string $legacyValue,
        string $modernKey,
        string $modernValue
    ): string {
        return 'Base compose memasang batas ' . $resource . ' yang berbeda antar gaya pada service "'
            . $service . '": ' . $legacyKey . ' = "' . $legacyValue . '" sedangkan '
            . $modernKey . ' = "' . $modernValue . '". Samakan nilainya di base compose terlebih dahulu.';
    }
}
