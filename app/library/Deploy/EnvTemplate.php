<?php
declare(strict_types=1);

namespace app\library\Deploy;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Deteksi variabel environment yang dibutuhkan app dari isi repo/app, dipakai
 * wizard create (halaman konfirmasi) supaya user bisa langsung mengisi nilainya
 * sebelum deploy — bukan menemukan error koneksi DB setelah app jalan.
 *
 * Urutan sumber:
 *   1. `.env.example` di root repo/app — semua key + nilai contohnya dipakai
 *      sebagai prefill form; variabel yang dipakai compose tanpa default tetap
 *      ditandai wajib (dan kunci wajib yang lupa dicantumkan ikut ditambahkan).
 *   2. Bila tidak ada: variabel yang direferensikan docker compose
 *      (`${VAR}`, `${VAR:-default}`, `${VAR-default}`, `${VAR:?err}`, `$VAR`),
 *      dengan nilai default compose sebagai prefill.
 *
 * Bentuk keluaran {@see detect()}:
 *   [
 *     'source' => 'env-example' | 'compose' | 'none',
 *     'vars'   => [
 *       ['key' => string, 'value' => string, 'default' => ?string, 'required' => bool],
 *       ...
 *     ],
 *   ]
 *
 * {@see resolveValues()} memvalidasi isian form create dan mengembalikan map
 * KEY => value yang siap dipakai {@see EnvManager}
 * (lihat `AppController::confirmCreate()`).
 */
class EnvTemplate
{
    public const SOURCE_ENV_EXAMPLE = 'env-example';
    public const SOURCE_COMPOSE     = 'compose';
    public const SOURCE_NONE        = 'none';

    /** Batas jumlah variabel yang dirender di form create. */
    public const MAX_VARS = 100;

    /** Batas panjang nilai (selaras dengan TemplateCatalog::MAX_ENV_VALUE_LENGTH). */
    public const MAX_VALUE_LENGTH = 4096;

    /** Pola nama variabel dotenv/compose. */
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Variabel yang **disediakan dashboard/compose sendiri** — tidak boleh
     * ditanyakan atau diisi user. `PWD` di-set `DockerComposeRunner` ke
     * direktori app (dipakai `${PWD}/data` sebagai source bind mount); menulis
     * `PWD` ke env file app akan menimpa nilai itu dan merusak bind mount.
     */
    public const RESERVED_KEYS = [
        'PWD',
        'COMPOSE_PROJECT_NAME',
        'COMPOSE_FILE',
        'COMPOSE_PROFILES',
        'COMPOSE_PATH_SEPARATOR',
    ];

    public static function isReservedKey(string $key): bool
    {
        return in_array($key, self::RESERVED_KEYS, true);
    }

    /**
     * Deteksi template env dari direktori app.
     *
     * @param array<int,string> $composeFiles compose file relatif terhadap $dir
     * @return array{source:string,vars:array<int,array{key:string,value:string,default:?string,required:bool}>}
     */
    public static function detect(string $dir, array $composeFiles = ['docker-compose.yml']): array
    {
        $vars = self::fromEnvExample($dir);
        if ($vars !== []) {
            // `.env.example` menentukan daftar & prefill, tetapi compose tetap
            // sumber kebenaran untuk "wajib": kunci yang dipakai `${VAR}` tanpa
            // default dan masih kosong di contoh ditandai wajib, dan kunci wajib
            // yang lupa dicantumkan di `.env.example` ikut ditambahkan.
            return [
                'source' => self::SOURCE_ENV_EXAMPLE,
                'vars'   => self::withComposeRequirements($vars, self::fromComposeFiles($dir, $composeFiles)),
            ];
        }

        $vars = self::fromComposeFiles($dir, $composeFiles);

        return [
            'source' => $vars === [] ? self::SOURCE_NONE : self::SOURCE_COMPOSE,
            'vars'   => $vars,
        ];
    }

    /**
     * Lengkapi entri `.env.example` dengan syarat dari compose: variabel wajib
     * yang masih kosong ditandai `required`, dan variabel wajib yang tidak
     * tercantum di contoh ikut ditambahkan (urutan contoh dipertahankan).
     *
     * @param array<int,array{key:string,value:string,default:?string,required:bool}> $vars
     * @param array<int,array{key:string,value:string,default:?string,required:bool}> $composeVars
     * @return array<int,array{key:string,value:string,default:?string,required:bool}>
     */
    private static function withComposeRequirements(array $vars, array $composeVars): array
    {
        if ($composeVars === []) {
            return $vars;
        }

        $byKey = [];
        foreach ($composeVars as $spec) {
            $byKey[$spec['key']] = $spec;
        }

        $seen = [];
        foreach ($vars as $index => $spec) {
            $seen[$spec['key']] = true;
            $composeSpec = $byKey[$spec['key']] ?? null;
            if ($composeSpec === null || empty($composeSpec['required']) || trim($spec['value']) !== '') {
                continue;
            }
            $vars[$index]['required'] = true;
        }

        foreach ($composeVars as $spec) {
            if (empty($spec['required']) || isset($seen[$spec['key']]) || count($vars) >= self::MAX_VARS) {
                continue;
            }
            $vars[] = $spec;
        }

        return $vars;
    }

    /**
     * Template dari `.env.example` (nilai contoh dipakai apa adanya).
     *
     * @return array<int,array{key:string,value:string,default:?string,required:bool}>
     */
    public static function fromEnvExample(string $dir): array
    {
        $vars = [];
        foreach (EnvManager::parseEnvExample($dir) as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || self::isReservedKey($key)) {
                continue;
            }
            $vars[] = [
                'key'      => $key,
                'value'    => (string) $value,
                'default'  => null,
                'required' => false,
            ];
            if (count($vars) >= self::MAX_VARS) {
                break;
            }
        }

        return $vars;
    }

    /**
     * Gabungkan isi compose file app (file yang ada saja) lalu ekstrak variabel.
     *
     * @param array<int,string> $composeFiles
     * @return array<int,array{key:string,value:string,default:?string,required:bool}>
     */
    public static function fromComposeFiles(string $dir, array $composeFiles): array
    {
        $content = '';
        foreach ($composeFiles as $file) {
            $file = trim((string) $file);
            // Path relatif di dalam direktori app saja (tidak boleh keluar folder).
            if ($file === '' || str_starts_with($file, '/') || str_contains($file, '..')) {
                continue;
            }
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $read = @file_get_contents($path);
            if (is_string($read)) {
                $content .= "\n" . $read;
            }
        }

        return self::fromCompose($content);
    }

    /**
     * Ekstrak referensi variabel dari isi docker-compose.yml.
     *
     * - `${VAR}` / `$VAR`            → wajib diisi (tidak ada default).
     * - `${VAR:-x}` / `${VAR-x}`     → opsional, default `x`.
     * - `${VAR:?err}` / `${VAR?err}` → wajib (compose gagal bila kosong).
     * - `${VAR:+alt}` / `${VAR+alt}` → opsional tanpa default.
     * - `$$VAR`                      → literal `$VAR`, bukan referensi.
     *
     * Kemunculan pertama menentukan urutan; bila ada kemunculan lain dengan
     * default, default itu yang dipakai (supaya variabel tidak dipaksa "wajib").
     *
     * @return array<int,array{key:string,value:string,default:?string,required:bool}>
     */
    public static function fromCompose(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        // Referensi variabel hanya dicari di nilai YAML — komentar (mis. `$_ENV`
        // di dokumentasi compose) tidak boleh ikut terdeteksi sebagai variabel.
        $content = self::composeText($content);

        // `$$` adalah escape literal compose — jangan dianggap referensi variabel.
        $content = str_replace('$$', "\x01", $content);

        $pattern = '/\$(?:\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+?])([^}]*))?\}|([A-Za-z_][A-Za-z0-9_]*))/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === 0) {
            return [];
        }

        $vars = [];
        foreach ($matches as $match) {
            $key = (string) (($match[1] ?? null) ?? ($match[4] ?? ''));
            if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1 || self::isReservedKey($key)) {
                continue;
            }

            $operator  = (string) ($match[2] ?? '');
            $arg       = $match[3] ?? null;
            $default   = in_array($operator, [':-', '-'], true) ? (string) ($arg ?? '') : null;
            $required  = in_array($operator, ['', ':?', '?'], true);

            if (isset($vars[$key])) {
                // Sudah tercatat: jangan menurunkan info default yang sudah ada,
                // tapi jangan pula memaksa "wajib" bila ada varian ber-default.
                if ($vars[$key]['default'] === null && $default !== null) {
                    $vars[$key]['default']  = $default;
                    $vars[$key]['value']    = $default;
                    $vars[$key]['required'] = false;
                }
                continue;
            }

            $vars[$key] = [
                'key'      => $key,
                'value'    => $default ?? '',
                'default'  => $default,
                'required' => $required,
            ];
            if (count($vars) >= self::MAX_VARS) {
                break;
            }
        }

        return array_values($vars);
    }

    /**
     * Teks YAML yang boleh mengandung referensi variabel: seluruh string pada
     * struktur hasil parse. Bila YAML tidak bisa di-parse (tag compose khusus
     * seperti `!reset`, dsb.), fallback ke pembuangan komentar baris-per-baris.
     */
    private static function composeText(string $content): string
    {
        try {
            $parsed = Yaml::parse($content);
        } catch (\Throwable) {
            return self::stripYamlComments($content);
        }

        if (!is_array($parsed)) {
            return self::stripYamlComments($content);
        }

        $strings = [];
        self::collectStrings($parsed, $strings);

        return implode("\n", $strings);
    }

    /**
     * @param array<mixed,mixed> $node
     * @param array<int,string>  $out
     */
    private static function collectStrings(array $node, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $out[] = $key;
            }
            if (is_string($value)) {
                $out[] = $value;
            } elseif (is_array($value)) {
                self::collectStrings($value, $out);
            }
        }
    }

    /**
     * Buang komentar YAML: baris `# ...` dan komentar inline ` # ...` di luar
     * tanda kutip (supaya nilai seperti `"a # b"` tetap utuh).
     */
    private static function stripYamlComments(string $content): string
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $result = '';
            $quote  = null;
            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                if ($quote !== null) {
                    $result .= $char;
                    if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                        $result .= $line[++$i];
                        continue;
                    }
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $result .= $char;
                    continue;
                }
                if ($char === '#' && ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t")) {
                    break;
                }
                $result .= $char;
            }
            $lines[] = $result;
        }

        return implode("\n", $lines);
    }

    /**
     * Validasi isian form create → map KEY => value untuk {@see EnvManager}.
     *
     * Kunci/​nilai dipakai apa adanya (termasuk nilai kosong yang sengaja
     * dikosongkan user). Variabel yang ditandai `required` oleh template wajib
     * terisi; bila tidak, create ditolak dengan pesan yang menyebut kuncinya.
     *
     * @param array<mixed,mixed>  $input    map KEY => value
     * @param array<string,mixed> $template hasil {@see detect()} (boleh kosong)
     * @return array<string,string>
     * @throws RuntimeException isian tidak valid / variabel wajib masih kosong
     */
    public static function resolveValues(array $input, array $template = []): array
    {
        $values = [];
        foreach ($input as $rawKey => $rawValue) {
            $key = trim((string) $rawKey);
            // Baris tambahan yang dibiarkan kosong tidak dianggap variabel.
            if ($key === '') {
                continue;
            }
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new RuntimeException('Nama variabel environment tidak valid: ' . $key);
            }
            if (self::isReservedKey($key)) {
                throw new RuntimeException('Variabel environment ' . $key . ' dikelola dashboard dan tidak boleh diisi.');
            }
            if (is_array($rawValue) || is_object($rawValue)) {
                throw new RuntimeException('Nilai variabel environment ' . $key . ' tidak valid.');
            }

            $value = (string) $rawValue;
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new RuntimeException('Nilai variabel environment ' . $key . ' tidak boleh mengandung baris baru.');
            }
            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new RuntimeException('Nilai variabel environment ' . $key . ' terlalu panjang (maks ' . self::MAX_VALUE_LENGTH . ' karakter).');
            }

            $values[$key] = $value;
        }

        if (count($values) > self::MAX_VARS) {
            throw new RuntimeException('Terlalu banyak variabel environment (maks ' . self::MAX_VARS . ').');
        }

        foreach ((array) ($template['vars'] ?? []) as $spec) {
            if (!is_array($spec) || empty($spec['required'])) {
                continue;
            }
            $key = is_scalar($spec['key'] ?? null) ? (string) $spec['key'] : '';
            if ($key === '') {
                continue;
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                throw new RuntimeException(
                    'Nilai wajib diisi: ' . $key . ' (dipakai di docker-compose.yml tanpa nilai default).'
                );
            }
        }

        return $values;
    }
}
