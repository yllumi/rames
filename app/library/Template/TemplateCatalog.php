<?php
declare(strict_types=1);

namespace app\library\Template;

use app\library\Deploy\ComposeSource;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Katalog template app siap-pakai (SPECS.md §7.2b / ARCHITECTURE.md §5.1c).
 *
 * Satu template = satu direktori di `templates_path`:
 *   templates/<slug>/
 *     template.yml         — metadata (title, description, category, env[], primary, files[])
 *     docker-compose.yml   — isi compose app (image prebuilt, tanpa `build:`)
 *     files/               — file pendukung opsional yang disalin ke direktori app
 *
 * Kelas ini adalah satu-satunya tempat aturan template ditegakkan:
 *  - compose template wajib memakai `image:` (tanpa `build:` — aturan mode compose),
 *  - DILARANG menulis `container_name` / replica > 1: nama container dikelola
 *    dashboard (prefix otomatis = nama app, SPECS §7.6a) supaya template bisa
 *    dipakai lebih dari satu app,
 *  - setiap `${VAR}` tanpa nilai default wajib dideklarasikan di `env[]` agar
 *    deploy tidak jalan dengan variabel kosong,
 *  - nilai env ditentukan saat create (input user → default template → generate
 *    rahasia otomatis), lalu ditulis EnvManager ke database/env/{name}.env.
 *
 * Stateless (hanya path katalog) — aman untuk worker Webman persistent.
 */
final class TemplateCatalog
{
    /** File metadata template. */
    public const MANIFEST_FILE = 'template.yml';

    /** Nama file compose template (ditulis apa adanya ke direktori app). */
    public const COMPOSE_FILE = 'docker-compose.yml';

    /** Subdirektori berisi file pendukung template. */
    public const FILES_DIR = 'files';

    /** Panjang nilai rahasia hasil auto-generate (karakter heksadesimal). */
    public const SECRET_LENGTH = 48;

    /** Batas panjang satu nilai env dari input user. */
    public const MAX_ENV_VALUE_LENGTH = 4096;

    /**
     * Variabel compose yang diisi sistem (bukan dari deklarasi `env[]`):
     * `PWD` disetel DockerComposeRunner ke direktori app (SPECS §7.2a).
     */
    public const RESERVED_VARS = ['PWD', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'COMPOSE_FILE'];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim((string) ($root ?? config('deploy.templates_path')), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    // ==================================================================
    // Pembacaan & validasi katalog
    // ==================================================================

    /**
     * Seluruh template katalog (terurut judul).
     *
     * Template yang rusak TETAP dikembalikan dengan `valid=false` + pesan
     * error, supaya galeri bisa menampilkan masalahnya (bukan menghilangkannya
     * diam-diam) dan tombol deploy-nya dinonaktifkan.
     *
     * @return array<int,array>
     */
    public function all(): array
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return [];
        }

        $result = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($this->root . '/' . $entry)) {
                continue;
            }
            if (!$this->isValidSlug($entry)) {
                continue; // nama direktori bukan slug template yang sah
            }
            $result[] = $this->read($entry);
        }

        usort($result, static fn (array $a, array $b): int => strcmp((string) $a['title'], (string) $b['title']));

        return $result;
    }

    /**
     * Template berdasarkan slug (null bila tidak ada).
     */
    public function find(string $slug): ?array
    {
        $slug = trim($slug);
        if (!$this->isValidSlug($slug) || !is_dir($this->root . '/' . $slug)) {
            return null;
        }

        return $this->read($slug);
    }

    /**
     * Template yang wajib ada & valid (melempar bila tidak).
     */
    public function require(string $slug): array
    {
        $template = $this->find($slug);
        if ($template === null) {
            throw new RuntimeException('Template "' . $slug . '" tidak ditemukan.');
        }
        if (!$template['valid']) {
            throw new RuntimeException('Template "' . $slug . '" tidak valid: ' . $template['error']);
        }

        return $template;
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug) && strlen($slug) <= 63;
    }

    /**
     * Baca + validasi satu template.
     *
     * @return array{slug:string,dir:string,title:string,description:string,category:string,icon:string,docs_url:string,image:string,env:array<int,array>,files:array<int,string>,primary:array{service:string,port:int},ports:array<int,int>,compose:string,services:array<string,array>,valid:bool,error:?string}
     */
    private function read(string $slug): array
    {
        $dir = $this->root . '/' . $slug;
        $template = [
            'slug' => $slug,
            'dir' => $dir,
            'title' => $slug,
            'description' => '',
            'category' => 'Lainnya',
            'icon' => '',
            'docs_url' => '',
            'image' => '',
            'env' => [],
            'files' => [],
            'primary' => ['service' => '', 'port' => 0],
            'ports' => [],
            'compose' => '',
            'services' => [],
            'valid' => false,
            'error' => null,
        ];

        try {
            $manifest = $this->readManifest($dir);

            $template['title'] = $this->text($manifest['title'] ?? '') ?: $slug;
            $template['description'] = $this->text($manifest['description'] ?? '');
            $template['category'] = $this->text($manifest['category'] ?? '') ?: 'Lainnya';
            $template['icon'] = $this->text($manifest['icon'] ?? '');
            $template['docs_url'] = $this->text($manifest['docs_url'] ?? '');

            $compose = $this->readCompose($dir);
            $template['compose'] = $compose;
            $template['image'] = $this->firstImage($compose);

            // Aturan mode compose: services + `image:` wajib, `build:` ditolak.
            ComposeSource::assertDeployable($compose);

            $data = $this->parseYaml($compose, self::COMPOSE_FILE);
            $services = is_array($data['services'] ?? null) ? $data['services'] : [];
            $this->assertNoManagedKeys($services, $data);
            $this->assertHasPorts($services);

            $template['services'] = ComposeSource::parseContent($compose);
            $template['primary'] = $this->resolvePrimaryFrom($template['services'], $manifest['primary'] ?? null);
            $template['env'] = $this->readEnv($manifest['env'] ?? null, $compose);
            $template['files'] = $this->readFiles($dir, $manifest['files'] ?? null);
            $template['ports'] = $this->summarizePorts($template['services']);
            $template['valid'] = true;
        } catch (\Throwable $e) {
            $template['error'] = $e->getMessage();
        }

        return $template;
    }

    /**
     * @return array<string,mixed>
     */
    private function readManifest(string $dir): array
    {
        $path = $dir . '/' . self::MANIFEST_FILE;
        if (!is_file($path)) {
            throw new RuntimeException('File ' . self::MANIFEST_FILE . ' tidak ada.');
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Gagal membaca ' . self::MANIFEST_FILE . '.');
        }

        $data = $this->parseYaml($content, self::MANIFEST_FILE);
        if (!is_array($data)) {
            throw new RuntimeException(self::MANIFEST_FILE . ' harus berupa map YAML.');
        }

        return $data;
    }

    private function readCompose(string $dir): string
    {
        $path = $dir . '/' . self::COMPOSE_FILE;
        if (!is_file($path)) {
            throw new RuntimeException('File ' . self::COMPOSE_FILE . ' tidak ada.');
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('File ' . self::COMPOSE_FILE . ' kosong atau tidak terbaca.');
        }

        return $content;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseYaml(string $content, string $label): array
    {
        try {
            $data = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new RuntimeException('YAML ' . $label . ' tidak valid: ' . $e->getMessage(), 0, $e);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Kunci yang dikelola dashboard TIDAK boleh ada di template:
     * `container_name` (nama container = prefix otomatis per app), replica > 1
     * (tidak kompatibel dengan `container_name`, SPECS §7.6a), dan `name:` di
     * level atas (nama project compose = nama app — dipakai untuk reuse volume
     * saat app dibuat ulang, SPECS §7.4).
     *
     * @param array<string,mixed> $services
     * @param array<string,mixed> $compose
     */
    private function assertNoManagedKeys(array $services, array $compose): void
    {
        if (isset($compose['name'])) {
            throw new RuntimeException(
                'Compose template menulis "name:" di level atas — nama project compose ditentukan dashboard '
                . 'dari nama app (dipakai untuk reuse volume saat app dibuat ulang). Hapus baris itu.'
            );
        }

        foreach ($services as $name => $config) {
            $service = (string) $name;
            if (!is_array($config)) {
                throw new RuntimeException('Service "' . $service . '" tidak valid.');
            }
            if (isset($config['container_name'])) {
                throw new RuntimeException(
                    'Service "' . $service . '" memakai "container_name" — nama container dikelola dashboard '
                    . '(prefix otomatis = nama app) supaya template bisa dipakai lebih dari satu app. Hapus baris itu.'
                );
            }

            $replicas = (int) ($config['deploy']['replicas'] ?? 0);
            $scale = (int) ($config['scale'] ?? 0);
            if ($replicas > 1 || $scale > 1) {
                throw new RuntimeException(
                    'Service "' . $service . '" memakai replica/scale > 1 — tidak kompatibel dengan prefix nama container otomatis.'
                );
            }
        }
    }

    /**
     * Template wajib mempublikasikan minimal satu port: tanpa port, app tidak
     * punya target `proxy_pass` Nginx (domestik ke app tak akan pernah jalan).
     *
     * @param array<string,mixed> $services
     */
    private function assertHasPorts(array $services): void
    {
        foreach ($services as $name => $config) {
            if (is_array($config) && !empty($config['ports'])) {
                return;
            }
        }

        throw new RuntimeException('Compose template tidak mempublikasikan port (`ports:`) pada service mana pun.');
    }

    /**
     * Service + port container yang di-proxy ke domain.
     *
     * `primary: {service, port}` dari manifest divalidasi ketat; bila tidak
     * diisi dipakai default = service pertama yang punya port, port pertama.
     *
     * @param array<string,array> $services hasil ComposeParser
     * @return array{service:string,port:int}
     */
    private function resolvePrimaryFrom(array $services, mixed $raw): array
    {
        $service = '';
        $port = 0;
        if (is_array($raw)) {
            $service = trim((string) ($raw['service'] ?? ''));
            $port = (int) ($raw['port'] ?? 0);
        }

        if ($service !== '') {
            if (!isset($services[$service])) {
                throw new RuntimeException('primary.service "' . $service . '" tidak ada di compose template.');
            }
            $available = $this->containerPorts($services[$service]);
            if ($available === []) {
                throw new RuntimeException('primary.service "' . $service . '" tidak mempublikasikan port.');
            }
            if ($port > 0 && !in_array($port, $available, true)) {
                throw new RuntimeException(
                    'primary.port ' . $port . ' tidak ada pada service "' . $service . '" (tersedia: ' . implode(', ', $available) . ').'
                );
            }

            return ['service' => $service, 'port' => $port > 0 ? $port : $available[0]];
        }

        foreach ($services as $name => $svc) {
            $available = $this->containerPorts($svc);
            if ($available !== []) {
                return ['service' => (string) $name, 'port' => $available[0]];
            }
        }

        throw new RuntimeException('Tidak ada service dengan port exposed — primary tidak bisa ditentukan.');
    }

    /**
     * @param array $service
     * @return array<int,int>
     */
    private function containerPorts(array $service): array
    {
        $ports = [];
        foreach ((array) ($service['ports'] ?? []) as $entry) {
            $container = (int) ($entry['container'] ?? 0);
            if ($container > 0) {
                $ports[] = $container;
            }
        }

        return array_values(array_unique($ports));
    }

    /**
     * Daftar port ringkas untuk kartu galeri.
     *
     * @param array<string,array> $services
     * @return array<int,int>
     */
    private function summarizePorts(array $services): array
    {
        $ports = [];
        foreach ($services as $svc) {
            foreach ($this->containerPorts($svc) as $port) {
                $ports[] = $port;
            }
        }

        $ports = array_values(array_unique($ports));
        sort($ports);

        return $ports;
    }

    /**
     * Deklarasi environment variable template.
     *
     * `default: false`/`0` harus tetap dianggap nilai (bukan "kosong"), karena
     * itu konversi skalar dilakukan eksplisit di sini.
     *
     * @return array<int,array{key:string,label:string,help:string,default:?string,secret:bool,generate:bool,required:bool}>
     */
    private function readEnv(mixed $raw, string $compose): array
    {
        $env = [];
        if ($raw !== null && !is_array($raw)) {
            throw new RuntimeException('Bagian "env" pada ' . self::MANIFEST_FILE . ' harus berupa daftar.');
        }

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Setiap entri "env" harus berupa map (key, label, default, ...).');
            }

            $key = strtoupper(trim((string) ($item['key'] ?? '')));
            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException('Kunci env tidak valid: "' . (string) ($item['key'] ?? '') . '" (pakai A-Z, 0-9, garis bawah).');
            }
            if (isset($env[$key])) {
                throw new RuntimeException('Kunci env duplikat: ' . $key);
            }

            $generate = ($item['generate'] ?? null) === 'secret';
            $env[$key] = [
                'key' => $key,
                'label' => $this->text($item['label'] ?? '') ?: $key,
                'help' => $this->text($item['help'] ?? ''),
                'default' => $this->scalarToText($item['default'] ?? null),
                'secret' => $generate || (bool) ($item['secret'] ?? false),
                'generate' => $generate,
                // Tanpa default & tanpa generate = wajib diisi user.
                'required' => (bool) ($item['required'] ?? (($item['default'] ?? null) === null && !$generate)),
            ];
        }

        $this->assertEnvReferenced($compose, array_keys($env));

        return array_values($env);
    }

    /**
     * Setiap `${VAR}`/`$VAR` tanpa default wajib dideklarasikan di `env[]`.
     * Tanpa ini compose tetap jalan dengan variabel kosong (docker compose hanya
     * memberi warning), sehingga app bisa diam-diam salah konfigurasi.
     *
     * @param array<int,string> $declared
     */
    private function assertEnvReferenced(string $compose, array $declared): void
    {
        $missing = [];
        foreach ($this->referencedVars($compose) as $var) {
            if (in_array($var, $declared, true) || in_array($var, self::RESERVED_VARS, true)) {
                continue;
            }
            $missing[] = $var;
        }

        if ($missing !== []) {
            $missing = array_values(array_unique($missing));
            throw new RuntimeException(
                'Variabel ' . implode(', ', array_map(static fn (string $v): string => '${' . $v . '}', $missing))
                . ' dipakai di ' . self::COMPOSE_FILE . ' tetapi tidak dideklarasikan pada bagian "env" ' . self::MANIFEST_FILE . '.'
            );
        }
    }

    /**
     * Variabel compose yang direferensikan TANPA nilai default (`:-`), sehingga
     * wajib punya sumber nilai eksplisit.
     *
     * @return array<int,string>
     */
    private function referencedVars(string $compose): array
    {
        $vars = [];

        // ${VAR}, ${VAR:-default}, ${VAR-default}, ${VAR:?err}
        if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)([^}]*)\}/', $compose, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $hit) {
                if (trim((string) $hit[2]) === '') {
                    $vars[] = (string) $hit[1];
                }
            }
        }

        // $VAR tanpa kurung kurawal (bukan bagian dari ${VAR} — setelah `$` ada huruf)
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $compose, $matches) !== false) {
            foreach ($matches[1] as $var) {
                $vars[] = (string) $var;
            }
        }

        return $vars;
    }

    /**
     * File pendukung template (dari subdirektori `files/`).
     *
     * @return array<int,string>
     */
    private function readFiles(string $dir, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new RuntimeException('Bagian "files" pada ' . self::MANIFEST_FILE . ' harus berupa daftar nama file.');
        }

        $names = [];
        foreach ($raw as $item) {
            $name = (string) $item;
            if (trim($name) === '') {
                continue;
            }
            // Nama relatif aman + bukan file yang dikelola dashboard.
            $name = ComposeSource::sanitizeName($name);
            if (str_starts_with($name, ComposeSource::GENERATED_PREFIX)) {
                throw new RuntimeException('File "' . $name . '" dikelola dashboard dan tidak boleh ikut template.');
            }
            if (!is_file($dir . '/' . self::FILES_DIR . '/' . $name)) {
                throw new RuntimeException('File template tidak ada: ' . self::FILES_DIR . '/' . $name);
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function firstImage(string $compose): string
    {
        if (preg_match('/^\s+image:\s*["\']?([^"\'\s]+)["\']?\s*$/m', $compose, $m) === 1) {
            return (string) $m[1];
        }

        return '';
    }

    // ==================================================================
    // Materialisasi & nilai env
    // ==================================================================

    /**
     * Tulis file template (compose utama + file pendukung) ke direktori app.
     *
     * Memakai jalur penyimpanan mode compose (`ComposeSource::store()`) sehingga
     * aturan nama file & validasi compose identik dengan mode paste/upload.
     * Direktori app TIDAK dibersihkan di sini — pemanggil yang menentukan.
     *
     * @return array<int,string> nama file yang ditulis
     */
    public function materialize(
        array $template,
        string $dest,
        int $maxFileBytes = ComposeSource::MAX_FILE_BYTES,
        int $maxTotalBytes = ComposeSource::MAX_TOTAL_BYTES
    ): array {
        if (!($template['valid'] ?? false)) {
            throw new RuntimeException('Template tidak valid: ' . (string) ($template['error'] ?? 'tidak diketahui'));
        }

        $uploads = [];
        foreach ((array) ($template['files'] ?? []) as $name) {
            $path = (string) $template['dir'] . '/' . self::FILES_DIR . '/' . $name;
            $size = @filesize($path);
            $uploads[] = [
                'name' => (string) $name,
                'tmp_name' => $path,
                'size' => $size === false ? 0 : (int) $size,
                'error' => UPLOAD_ERR_OK,
            ];
        }

        return ComposeSource::store(
            $dest,
            $uploads,
            (string) $template['compose'],
            $maxFileBytes,
            $maxTotalBytes,
            self::COMPOSE_FILE
        );
    }

    /**
     * Tentukan nilai env final dari input user.
     *
     * Urutan: nilai input → `default` template → auto-generate (bila
     * `generate: secret`) → tolak bila `required`.
     *
     * @param array<string,mixed> $input map KEY => value dari form
     * @return array<string,string>
     */
    public function resolveEnv(array $template, array $input): array
    {
        $values = [];
        foreach ((array) ($template['env'] ?? []) as $spec) {
            $key = (string) $spec['key'];
            $raw = $input[$key] ?? null;
            if (is_array($raw) || is_object($raw)) {
                throw new RuntimeException('Nilai env ' . $key . ' tidak valid.');
            }

            $value = is_scalar($raw) ? (string) $raw : '';
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new RuntimeException('Nilai env ' . $key . ' tidak boleh mengandung baris baru.');
            }
            if (strlen($value) > self::MAX_ENV_VALUE_LENGTH) {
                throw new RuntimeException('Nilai env ' . $key . ' terlalu panjang (maks ' . self::MAX_ENV_VALUE_LENGTH . ' karakter).');
            }

            if ($value !== '') {
                $values[$key] = $value;
                continue;
            }
            if ($spec['default'] !== null) {
                $values[$key] = (string) $spec['default'];
                continue;
            }
            if ($spec['generate']) {
                $values[$key] = self::randomSecret();
                continue;
            }
            if ($spec['required']) {
                throw new RuntimeException('Nilai wajib diisi: ' . $spec['label'] . ' (' . $key . ').');
            }
            // Opsional & kosong → tidak ditulis sama sekali.
        }

        return $values;
    }

    /**
     * Kunci env yang akan di-generate otomatis (untuk pesan ke user bahwa
     * nilainya bisa dilihat/diubah di tab Environment).
     *
     * @param array<string,mixed> $input
     * @return array<int,string>
     */
    public function generatedKeys(array $template, array $input): array
    {
        $keys = [];
        foreach ((array) ($template['env'] ?? []) as $spec) {
            if (!$spec['generate']) {
                continue;
            }
            $raw = $input[$spec['key']] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if ($value === '') {
                $keys[] = (string) $spec['key'];
            }
        }

        return $keys;
    }

    /**
     * Nilai rahasia acak (hex) untuk env yang di-generate.
     */
    public static function randomSecret(): string
    {
        return bin2hex(random_bytes((int) (self::SECRET_LENGTH / 2)));
    }

    // ==================================================================
    // Internal kecil
    // ==================================================================

    private function text(mixed $value): string
    {
        return trim((string) $this->scalarToText($value));
    }

    /**
     * Konversi skalar YAML ke string; `null` tetap `null` (berarti "tidak ada
     * default"), sedangkan `false`/`0` tetap punya nilai.
     */
    private function scalarToText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
