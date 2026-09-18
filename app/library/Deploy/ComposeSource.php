<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\ComposeParser;
use app\library\Docker\PortManager;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Sumber app mode "compose" — app yang dibuat dari file docker-compose.yml yang
 * di-paste/di-upload (tanpa repo Git). Ditujukan untuk mendeploy aplikasi yang
 * memakai image prebuilt (tanpa build context), sehingga tidak butuh source.
 *
 * Tanggung jawab (semua logika bisnis mode compose ada di sini, bukan di controller):
 *  - deteksi mode app (`source` di apps.json)
 *  - validasi & penyimpanan file unggahan (nama relatif aman, batas ukuran)
 *  - validasi compose: service wajib `image:` dan DILARANG `build:` (tanpa build context)
 *  - baca/tulis file compose utama + daftar file sumber (untuk tab editor Compose)
 *
 * Stateless (hanya konstanta & static) — aman untuk worker Webman persistent.
 */
final class ComposeSource
{
    /** App dibuat dari repo Git (default — field absen = git). */
    public const SOURCE_GIT = 'git';

    /** App dibuat dari file compose yang di-paste/di-upload. */
    public const SOURCE_COMPOSE = 'compose';

    /** Nama file compose utama yang dikenali (urutan prioritas). */
    public const MAIN_FILES = ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'];

    /**
     * Prefix file yang di-generate dashboard (override port/env/network).
     * Tidak boleh di-upload user — akan ditimpa/di-manage sistem.
     */
    public const GENERATED_PREFIX = 'docker-compose.override';

    /** Override lapis 1: reset daftar ports bawaan base compose (`!reset`). */
    public const RESET_OVERRIDE_FILE = 'docker-compose.override.yml';

    /** Override lapis 2: host port final hasil edit user. */
    public const PORTS_OVERRIDE_FILE = 'docker-compose.override.ports.yml';

    /** Batas ukuran satu file unggahan (byte). */
    public const MAX_FILE_BYTES = 1048576;      // 1 MB

    /** Batas total ukuran seluruh file unggahan per request (byte). */
    public const MAX_TOTAL_BYTES = 4194304;     // 4 MB

    // ==================================================================
    // Deteksi mode
    // ==================================================================

    /**
     * Mode sumber app: ComposeSource::SOURCE_GIT | SOURCE_COMPOSE.
     * Field `source` absen (data lama) dibaca sebagai git.
     */
    public static function source(array $app): string
    {
        return ((string) ($app['source'] ?? self::SOURCE_GIT)) === self::SOURCE_COMPOSE
            ? self::SOURCE_COMPOSE
            : self::SOURCE_GIT;
    }

    public static function isCompose(array $app): bool
    {
        return self::source($app) === self::SOURCE_COMPOSE;
    }

    /**
     * Apakah app bisa di-rebuild lewat git (hanya app mode git).
     */
    public static function isGit(array $app): bool
    {
        return self::source($app) === self::SOURCE_GIT;
    }

    // ==================================================================
    // Nama file compose
    // ==================================================================

    /**
     * Nama file compose utama dari daftar compose_files app (entri pertama yang
     * bukan file override), atau string kosong bila tidak ada.
     *
     * @param array<int,string> $files
     */
    public static function mainFileFrom(array $files): string
    {
        foreach ($files as $file) {
            $file = (string) $file;
            if ($file === '' || str_starts_with($file, self::GENERATED_PREFIX)) {
                continue;
            }
            return $file;
        }
        return '';
    }

    /**
     * File compose utama app (nama relatif terhadap direktori app).
     */
    public static function mainFile(array $app): string
    {
        return self::mainFileFrom((array) ($app['compose_files'] ?? []));
    }

    /**
     * Apakah nama file termasuk file compose yang dikenali.
     */
    public static function isMainFileName(string $name): bool
    {
        return in_array(self::basename($name), self::MAIN_FILES, true);
    }

    /**
     * Deteksi nama file compose utama yang ada di disk ('' bila tidak ada).
     */
    public static function detectMainFile(string $dir): string
    {
        foreach (self::MAIN_FILES as $candidate) {
            if (is_file($dir . '/' . $candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Nama file compose utama di direktori app: dari compose_files app, atau
     * deteksi dari file yang ada di disk, atau nama default.
     */
    public static function resolveMainFile(string $dir, array $app): string
    {
        $fromApp = self::mainFile($app);
        if ($fromApp !== '') {
            return $fromApp;
        }
        $detected = self::detectMainFile($dir);

        return $detected !== '' ? $detected : self::MAIN_FILES[0];
    }

    /**
     * Parse isi compose (string) menjadi daftar service + port — tanpa perlu
     * menulis file permanen (dipakai tab editor Compose untuk validasi awal).
     *
     * @return array<string,array{internal_port:?int,host_port:?int,ports:array<int,array{host:?int,container:int,protocol:string}>}>
     */
    public static function parseContent(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rames-compose-');
        if ($tmp === false) {
            throw new RuntimeException('Gagal membuat file sementara untuk validasi compose.');
        }
        try {
            if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
                throw new RuntimeException('Gagal menulis file sementara untuk validasi compose.');
            }
            return (new ComposeParser())->parse($tmp)['services'];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Peta host port yang sedang dipakai app per nama service
     * (dari field `containers` apps.json) — dipakai untuk mempertahankan port
     * saat compose app di-edit.
     *
     * @return array<string,int>
     */
    public static function existingHostPorts(array $app): array
    {
        $map = [];
        foreach ((array) ($app['containers'] ?? []) as $container) {
            $service = (string) ($container['service_name'] ?? '');
            $port = (int) ($container['host_port'] ?? 0);
            if ($service !== '' && $port > 0) {
                $map[$service] = $port;
            }
        }
        return $map;
    }

    /**
     * Rencanakan host port untuk service hasil parse compose:
     *  - host port lama (per nama service) dipertahankan bila service-nya masih ada;
     *  - sisa yang belum punya / berkonflik diisi otomatis dari range oleh PortManager.
     *
     * @param array<string,array> $services           hasil ComposeParser::parse()['services']
     * @param array<string,int>   $existingByService  service_name => host_port (port lama app ini)
     * @param array<int,int>      $usedPorts          host port terpakai app LAIN
     * @return array<string,array>
     */
    public static function planHostPorts(
        array $services,
        array $existingByService,
        array $usedPorts,
        int $rangeStart,
        int $rangeEnd
    ): array {
        foreach ($services as $name => $svc) {
            $old = $existingByService[(string) $name] ?? null;
            if ($old === null || $old <= 0 || empty($svc['ports'])) {
                continue;
            }
            $services[$name]['host_port'] = (int) $old;
            if (isset($svc['ports'][0])) {
                $services[$name]['ports'][0]['host'] = (int) $old;
            }
        }

        return (new PortManager($rangeStart, $rangeEnd))->resolve($services, $usedPorts);
    }

    /**
     * Hapus file sumber app (tab editor Compose). File compose utama & file
     * override generated tidak boleh dihapus.
     *
     * @param array<int,string> $names
     * @return array<int,string> nama file yang benar-benar terhapus
     */
    public static function removeFiles(string $dir, array $names, string $keepMain): array
    {
        $removed = [];
        foreach ($names as $raw) {
            $name = self::sanitizeName((string) $raw);
            self::assertNotGenerated($name);
            if ($name === $keepMain) {
                throw new RuntimeException('File compose utama (' . $name . ') tidak bisa dihapus.');
            }
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                if (!@unlink($path)) {
                    throw new RuntimeException('Gagal menghapus file ' . $name . '.');
                }
                $removed[] = $name;
            }
        }
        return $removed;
    }
    /**
     * Path absolut file compose utama app; melempar bila tidak ada di disk.
     */
    public static function requireMainFile(string $dir, array $app): string
    {
        $file = self::resolveMainFile($dir, $app);
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException('File ' . $file . ' tidak ada di direktori app.');
        }
        return $path;
    }

    /**
     * Isi file compose utama app (string kosong bila belum ada).
     */
    public static function readMain(string $dir, array $app): string
    {
        $path = $dir . '/' . self::resolveMainFile($dir, $app);
        $content = is_file($path) ? @file_get_contents($path) : false;
        return $content === false ? '' : $content;
    }

    /**
     * Tulis file compose utama app setelah divalidasi (YAML valid, ada service,
     * tanpa `build:`). Mengembalikan nama file yang ditulis.
     */
    public static function writeMain(string $dir, array $app, string $content): string
    {
        if (trim($content) === '') {
            throw new RuntimeException('Isi docker-compose.yml tidak boleh kosong.');
        }
        self::assertDeployable($content);

        $file = self::resolveMainFile($dir, $app);
        self::writeFile($dir . '/' . $file, $content);

        return $file;
    }

    // ==================================================================
    // Validasi & penyimpanan file unggahan
    // ==================================================================

    /**
     * Normalisasi struktur $_FILES['<field>'] menjadi daftar entri seragam:
     * [['name' => string, 'tmp_name' => string, 'size' => int, 'error' => int], ...]
     *
     * Mendukung input `multiple` (name berupa array) maupun file tunggal.
     *
     * @return array<int,array{name:string,tmp_name:string,size:int,error:int}>
     */
    public static function normalizeUploads(array $files): array
    {
        $names = $files['name'] ?? null;
        if (is_array($names)) {
            $result = [];
            foreach (array_keys($names) as $i) {
                $result[] = [
                    'name' => (string) $names[$i],
                    'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                    'size' => (int) ($files['size'][$i] ?? 0),
                    'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
            return $result;
        }

        return [[
            'name' => (string) ($names ?? ''),
            'tmp_name' => (string) ($files['tmp_name'] ?? ''),
            'size' => (int) ($files['size'] ?? 0),
            'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        ]];
    }

    /**
     * Simpan file compose (paste dan/atau upload) ke direktori app.
     *
     * Aturan:
     *  - `docker-compose.yml` wajib: dari textarea (paste) ATAU file unggahan —
     *    tidak boleh keduanya (ambigu).
     *  - file lain dianggap file pendukung (mis. config yang di-bind mount).
     *  - nama file wajib relatif & aman; file override generated ditolak.
     *
     * Direktori app TIDAK dibersihkan di sini (pemanggil yang menentukan).
     *
     * @param array<int,array{name:string,tmp_name:string,size:int,error:int}> $uploads
     * @param string $preferredMain nama file compose utama yang dipakai bila isi
     *                              ditempel (mis. app yang dibuat dari `compose.yaml`)
     * @return array<int,string> daftar nama file yang ditulis
     */
    public static function store(
        string $dir,
        array $uploads,
        string $pastedCompose,
        int $maxFileBytes = self::MAX_FILE_BYTES,
        int $maxTotalBytes = self::MAX_TOTAL_BYTES,
        string $preferredMain = ''
    ): array {
        $pastedCompose = trim($pastedCompose) !== '' ? $pastedCompose : '';

        // Kumpulkan konten file yang akan ditulis (validasi dulu, tulis di akhir).
        /** @var array<string,string> $contents */
        $contents = [];
        $total = 0;

        foreach ($uploads as $upload) {
            $name = trim((string) ($upload['name'] ?? ''));
            // Input file yang tidak diisi tetap dikirim browser sebagai satu
            // entri dengan nama kosong (Workerman menandainya error=0, bukan
            // UPLOAD_ERR_NO_FILE) → abaikan, bukan error.
            if ($name === '') {
                continue;
            }
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Gagal menerima file "' . $name . '" (kode ' . (int) ($upload['error'] ?? 0) . ').');
            }

            $safe = self::sanitizeName($name);
            self::assertNotGenerated($safe);

            if ((int) $upload['size'] > $maxFileBytes) {
                throw new RuntimeException('File "' . $safe . '" terlalu besar (maks ' . self::humanBytes($maxFileBytes) . ').');
            }
            $total += (int) $upload['size'];
            if ($total > $maxTotalBytes) {
                throw new RuntimeException('Total ukuran file unggahan terlalu besar (maks ' . self::humanBytes($maxTotalBytes) . ').');
            }

            $content = @file_get_contents((string) $upload['tmp_name']);
            if ($content === false) {
                throw new RuntimeException('Gagal membaca file unggahan "' . $safe . '" (file sementara tidak ditemukan — coba unggah ulang).');
            }
            $contents[$safe] = $content;
        }

        $uploadedMain = [];
        foreach (array_keys($contents) as $name) {
            if (self::isMainFileName($name)) {
                $uploadedMain[] = $name;
            }
        }

        if ($pastedCompose !== '' && $uploadedMain !== []) {
            throw new RuntimeException('Pilih salah satu: tempel isi docker-compose.yml ATAU unggah file ' . implode('/', $uploadedMain) . '.');
        }

        if ($pastedCompose !== '') {
            $mainTarget = $preferredMain !== '' ? $preferredMain : self::MAIN_FILES[0];
            $mainTarget = self::sanitizeName($mainTarget);
            self::assertNotGenerated($mainTarget);
            $contents[$mainTarget] = $pastedCompose;
        }

        // Validasi compose utama (YAML + services + tanpa build) sebelum menulis.
        $mainName = self::pickMainFrom(array_keys($contents));
        if ($mainName === '') {
            $mainName = $preferredMain !== '' && isset($contents[$preferredMain]) ? $preferredMain : '';
        }
        if ($mainName === '') {
            throw new RuntimeException('File docker-compose.yml wajib: tempel isinya di textarea atau unggah filenya.');
        }
        self::assertDeployable((string) $contents[$mainName]);

        // Baru tulis semua file.
        $written = [];
        foreach ($contents as $name => $content) {
            self::writeFile($dir . '/' . $name, $content);
            $written[] = $name;
        }
        sort($written);

        return $written;
    }

    /**
     * Validasi isi compose: YAML valid, ada `services`, setiap service punya
     * `image:` dan TIDAK memakai `build:` (mode compose tanpa build context).
     */
    public static function assertDeployable(string $content): void
    {
        try {
            $data = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new RuntimeException('YAML tidak valid: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || !isset($data['services']) || !is_array($data['services']) || $data['services'] === []) {
            throw new RuntimeException('Compose harus memiliki bagian "services" yang tidak kosong.');
        }

        foreach ($data['services'] as $service => $config) {
            $service = (string) $service;
            if (!is_array($config)) {
                throw new RuntimeException('Service "' . $service . '" tidak valid.');
            }
            if (isset($config['build']) && $config['build'] !== null && $config['build'] !== [] && $config['build'] !== '') {
                throw new RuntimeException(
                    'Service "' . $service . '" memakai "build:" — mode Compose hanya mendukung image prebuilt '
                    . '(tanpa build context). Hapus bagian build dan pakai "image:", atau buat app lewat mode Clone repo Git.'
                );
            }
            if (!isset($config['image']) || trim((string) $config['image']) === '') {
                throw new RuntimeException(
                    'Service "' . $service . '" tidak punya "image:" — mode Compose memerlukan image prebuilt '
                    . '(mis. "image: nginx:alpine"). Build dari source tidak didukung.'
                );
            }
        }
    }

    /**
     * Daftar file sumber app (relatif, terurut) — tanpa file override generated,
     * agar tidak dianggap file milik user.
     *
     * @return array<int,string>
     */
    public static function listSourceFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $result = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1));
            if ($relative === '' || str_starts_with(self::basename($relative), self::GENERATED_PREFIX)) {
                continue;
            }
            $result[] = $relative;
        }
        sort($result);
        return $result;
    }

    // ==================================================================
    // Internal
    // ==================================================================

    private static function pickMainFrom(array $names): string
    {
        foreach (self::MAIN_FILES as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Validasi nama file relatif: tanpa path absolut, tanpa `..`, segmen aman.
     */
    public static function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0")) {
            throw new RuntimeException('Nama file tidak valid: ' . $name);
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Nama file tidak valid: ' . $name);
            }
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                throw new RuntimeException('Nama file hanya boleh huruf, angka, titik, strip, dan garis bawah: ' . $name);
            }
        }
        // Normalisasi berlebih (mis. "a/./b" sudah ditolak di atas) tidak perlu.
        return $name;
    }

    private static function assertNotGenerated(string $name): void
    {
        if (str_starts_with(self::basename($name), self::GENERATED_PREFIX)) {
            throw new RuntimeException(
                'File "' . $name . '" dikelola dashboard (override port/env/network) dan tidak boleh diunggah.'
            );
        }
    }

    private static function writeFile(string $path, string $content): void
    {
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Gagal membuat direktori ' . $parent . '.');
        }
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis file ' . basename($path) . '.');
        }
    }

    private static function basename(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $pos = strrpos($name, '/');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024) . ' KB';
    }
}
