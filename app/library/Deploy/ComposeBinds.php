<?php
declare(strict_types=1);

namespace app\library\Deploy;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Penyiapan **source bind mount** app sebelum `docker compose up`.
 *
 * Latar belakang: source bind mount wajib sudah ada di host. Untuk named volume
 * dengan `driver_opts: {type: none, device: <path>, o: bind}` (pola umum untuk
 * menyimpan data di dalam direktori app), daemon gagal dengan pesan:
 *
 *   failed to populate volume: ... mount <path>:/var/lib/docker/volumes/...:
 *   no such file or directory
 *
 * karena `mount --bind` menuntut direktori sumber sudah ada. Dashboard membuatkan
 * direktori tersebut (relatif terhadap direktori app) agar app compose bisa
 * di-deploy hanya dari file YAML tanpa langkah manual di host.
 *
 * Aturan (aman & dapat diprediksi — dashboard tidak menebak-nebak membuat FILE):
 *  - device volume bernama (driver `local` + `o: bind`) → selalu direktori data → dibuat;
 *  - source bind tanpa titik (mis. `./data`, `${PWD}/store`) → direktori → dibuat;
 *  - dotfile (`.env`, `.gitignore`) & nama ber-ekstensi (`nginx.conf`, `app.json`)
 *    → dianggap **file** → TIDAK dibuat, hanya dilaporkan (file harus diunggah
 *    lewat tab Compose atau dibuat manual di host);
 *  - path hasil resolve yang keluar dari direktori app → TIDAK dibuat, hanya dilaporkan.
 *
 * Substitusi variabel mengikuti docker compose: `${PWD}` = direktori app (nilai
 * yang dipakai local deployer saat menjalankan compose), `${VAR}`/`$VAR` dari
 * managed env app. Entry dengan variabel tak dikenal dilewati (tidak dikira-kira).
 *
 * Stateless (statik) — aman untuk worker Webman persistent.
 */
final class ComposeBinds
{
    /**
     * Kumpulkan seluruh source bind mount dari file compose app.
     *
     * @param string            $dir   direktori app (absolut)
     * @param array<int,string> $files nama file compose relatif terhadap $dir
     * @param array<string,string> $env nilai untuk substitusi variabel (termasuk `PWD`)
     * @return array<int,array{raw:string,path:string,dir:bool,file:string,type:string}>
     *         `type`: `volume` (device volume bernama) | `bind` (bind mount service)
     */
    public static function collect(string $dir, array $files, array $env = []): array
    {
        $dir = rtrim($dir, '/');
        $found = [];

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            try {
                $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
            } catch (ParseException $e) {
                continue; // compose invalid ditangani compose sendiri saat `up`
            }
            if (!is_array($data)) {
                continue;
            }

            foreach (self::volumeDevices($data, $dir, $env) as $entry) {
                $found[] = $entry + ['file' => (string) $file];
            }
            foreach (self::serviceBinds($data, $dir, $env) as $entry) {
                $found[] = $entry + ['file' => (string) $file];
            }
        }

        return $found;
    }

    /**
     * Pastikan sumber bind mount ada; buat direktori yang aman dibuat.
     *
     * @param string            $dir   direktori app (absolut)
     * @param array<int,string> $files nama file compose relatif terhadap $dir
     * @param array<string,string> $env nilai untuk substitusi variabel (termasuk `PWD`)
     * @return array{created:array<int,string>,missing:array<int,string>}
     *         `missing` sudah berisi path + alasannya (untuk pesan error)
     */
    public static function ensure(string $dir, array $files, array $env = []): array
    {
        $dir = rtrim($dir, '/');
        $created = [];
        $missing = [];

        foreach (self::collect($dir, $files, $env) as $bind) {
            $path = $bind['path'];
            if ($path === '' || file_exists($path)) {
                continue;
            }

            if ($bind['dir'] && self::insideDir($dir, $path)) {
                if (@mkdir($path, 0775, true) || is_dir($path)) {
                    $created[] = $path;
                    continue;
                }
            }

            $missing[] = $path . ' — ' . self::reason($bind, $dir);
        }

        return ['created' => $created, 'missing' => $missing];
    }

    /**
     * Pesan tambahan (siap tempel ke pesan error `docker compose up`) berisi
     * daftar source bind yang belum siap. String kosong bila tidak ada.
     *
     * @param array<int,string> $missing hasil `ensure()['missing']`
     */
    public static function missingHint(array $missing): string
    {
        if ($missing === []) {
            return '';
        }
        $hint = ' Bind mount di compose belum siap:';
        foreach ($missing as $item) {
            $hint .= "\n - " . $item;
        }
        return $hint . "\nPerbaiki dengan salah satu cara: buat path tersebut di host, "
            . 'unggah filenya lewat tab Compose, atau pakai path relatif di dalam direktori app (mis. ./data).';
    }

    // ==================================================================
    // Internal — pengumpulan bind
    // ==================================================================

    /**
     * Volume bernama dengan driver `local` + opsi `bind` → device adalah direktori data.
     *
     * @return array<int,array{raw:string,path:string,dir:bool,type:string}>
     */
    private static function volumeDevices(array $data, string $dir, array $env): array
    {
        $volumes = $data['volumes'] ?? null;
        if (!is_array($volumes)) {
            return [];
        }

        $result = [];
        foreach ($volumes as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $opts = $definition['driver_opts'] ?? null;
            if (!is_array($opts)) {
                continue;
            }
            $device = $opts['device'] ?? null;
            if (!is_string($device) || trim($device) === '') {
                continue;
            }
            // Hanya driver local dengan opsi bind yang memakai path lokal. Driver
            // lain (mis. nfs) memakai device bentuk "host:/export" — dilewati.
            if ((string) ($definition['driver'] ?? 'local') !== 'local') {
                continue;
            }
            if (!self::hasBindOption($opts['o'] ?? null)) {
                continue;
            }

            $resolved = self::resolve($device, $dir, $env);
            if ($resolved === null) {
                continue;
            }
            $result[] = ['raw' => $device, 'path' => $resolved, 'dir' => true, 'type' => 'volume'];
        }

        return $result;
    }

    /**
     * Bind mount pada service (short syntax `./data:/data` atau long syntax
     * `{type: bind, source: ...}`). Named/anonymous volume dilewati.
     *
     * @return array<int,array{raw:string,path:string,dir:bool,type:string}>
     */
    private static function serviceBinds(array $data, string $dir, array $env): array
    {
        $services = $data['services'] ?? null;
        if (!is_array($services)) {
            return [];
        }

        $result = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $volumes = $service['volumes'] ?? null;
            if (!is_array($volumes)) {
                continue;
            }

            foreach ($volumes as $entry) {
                $source = '';
                $target = '';
                if (is_string($entry)) {
                    [$source, $target] = self::splitShortSyntax($entry, $env);
                    if ($source === '') {
                        continue;
                    }
                } elseif (is_array($entry)) {
                    if ((string) ($entry['type'] ?? '') !== 'bind') {
                        continue;
                    }
                    $source = (string) ($entry['source'] ?? '');
                    $target = (string) ($entry['target'] ?? '');
                    if (trim($source) === '') {
                        continue;
                    }
                } else {
                    continue;
                }

                $resolved = self::resolve($source, $dir, $env);
                if ($resolved === null) {
                    continue;
                }
                $result[] = [
                    'raw' => $source,
                    'path' => $resolved,
                    'dir' => !self::looksLikeFile($source),
                    'type' => 'bind',
                ];
            }
        }

        return $result;
    }

    /**
     * Pecah short syntax `source:target[:mode]`. Mengembalikan source `''` bila
     * entry bukan bind path (named/anonymous volume, atau hanya target).
     *
     * Deteksi host path dilakukan pada nilai SETELAH substitusi variabel, seperti
     * docker compose: `${PWD}/store:/store` adalah host path, sedangkan
     * `named-vol:/opt/data` adalah named volume.
     *
     * @param array<string,string> $env
     * @return array{0:string,1:string}
     */
    private static function splitShortSyntax(string $entry, array $env): array
    {
        $entry = trim($entry);
        if ($entry === '' || !str_contains($entry, ':')) {
            return ['', '']; // hanya target → anonymous volume
        }
        $parts = explode(':', $entry);
        $source = (string) array_shift($parts);
        $target = (string) ($parts[0] ?? '');

        // Source sebelum substitusi (variabel tak dikenal → pakai bentuk mentah
        // sebagai tebakan) hanya untuk menentukan apakah ini host path.
        $forPathCheck = self::substitute($source, $env) ?? $source;
        $isPath = str_starts_with($forPathCheck, '/') || str_starts_with($forPathCheck, '.');

        return $isPath ? [$source, $target] : ['', $target]; // nama → named volume
    }

    /**
     * Apakah source kemungkinan besar sebuah FILE (bukan direktori)? Dipakai agar
     * dashboard tidak membuat direktori bernama `nginx.conf` atau `.env`.
     *
     * Konvensi: nama tanpa titik sama sekali (`data`, `conf`, `mysql`) → direktori;
     * nama ber-ekstensi (`nginx.conf`) atau dotfile (`.env`, `.gitignore`) → file.
     * Kasus ambigu (mis. dotdir `.hermes` sebagai bind service) diperlakukan
     * konservatif sebagai file — hanya dilaporkan, tidak dibuat otomatis.
     */
    private static function looksLikeFile(string $source): bool
    {
        $raw = trim($source);
        if (str_ends_with($raw, '/')) {
            return false; // berakhir '/' → pasti direktori
        }
        $base = basename(rtrim($raw, '/'));
        if ($base === '' || $base === '.' || $base === '..') {
            return false;
        }
        if (str_starts_with($base, '.')) {
            return true; // dotfile (.env, .gitignore, .htaccess)
        }
        return str_contains($base, '.'); // name.ext (nginx.conf, app.json)
    }

    private static function hasBindOption(mixed $options): bool
    {
        if (is_string($options)) {
            return str_contains($options, 'bind');
        }
        if (is_array($options)) {
            foreach ($options as $value) {
                if (is_string($value) && str_contains($value, 'bind')) {
                    return true;
                }
            }
        }
        return false;
    }

    // ==================================================================
    // Internal — path
    // ==================================================================

    /**
     * Substitusi `${VAR}` / `$VAR` seperti docker compose. Mengembalikan null
     * bila masih ada variabel tak dikenal atau path home-relative (`~`).
     *
     * @param array<string,string> $env
     */
    private static function substitute(string $value, array $env): ?string
    {
        $value = (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
            static function (array $m) use ($env): string {
                $name = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                return array_key_exists($name, $env) ? (string) $env[$name] : $m[0];
            },
            $value
        );
        if (str_contains($value, '$') || str_starts_with($value, '~')) {
            return null;
        }
        return $value;
    }

    /**
     * Resolusi path source: substitusi variabel, relative terhadap direktori app,
     * lalu normalisasi (`.`/`..`). Mengembalikan null bila tidak bisa dipastikan
     * (variabel tak dikenal, `~`, path kosong).
     */
    private static function resolve(string $raw, string $dir, array $env): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $value = self::substitute($value, $env);
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        if (!str_starts_with($value, '/')) {
            $value = $dir . '/' . $value;
        }

        return self::normalize($value);
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return '/' . implode('/', $parts);
    }

    private static function insideDir(string $dir, string $path): bool
    {
        $base = self::normalize($dir);
        return $path === $base || str_starts_with($path, $base . '/');
    }

    /**
     * @param array{raw:string,path:string,dir:bool,file:string,type:string} $bind
     */
    private static function reason(array $bind, string $dir): string
    {
        if (!self::insideDir($dir, $bind['path'])) {
            return 'di luar direktori app — buat dulu di host';
        }
        if (!$bind['dir']) {
            return 'kemungkinan file — unggah lewat tab Compose atau buat manual di host';
        }
        return 'gagal dibuat (periksa izin tulis direktori app)';
    }
}
