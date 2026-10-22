<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\ComposeParser;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Manajemen environment variable per app (fitur "Environment Variables").
 *
 * Sumber kebenaran: field "env" di apps.json (map KEY => value, urutan terjaga).
 * Representasi di disk:
 *   1) Managed env file : {database_path}/env/{name}.env — dipakai docker
 *      compose lewat flag `--env-file` untuk substitusi ${VAR} di compose file.
 *   2) Override file    : apps/{name}/docker-compose.override.env.yml —
 *      meng-inject `environment:` LITERAL ke SETIAP service agar nilai benar-benar
 *      sampai ke environment container (mis. kredensial DB) tanpa bergantung pada
 *      referensi ${VAR} di docker-compose.yml repo.
 *
 * Managed file berada di LUAR direktori repo app (database/env/, gitignored)
 * sehingga tidak pernah konflik dengan `git pull --ff-only` saat Rebuild.
 * Semua mutasi memakai flock/LOCK_EX; file env di-chmod 0600 (bisa berisi secret).
 */
class EnvManager
{
    public const OVERRIDE_FILE = 'docker-compose.override.env.yml';

    /** Batas ukuran file .env yang boleh di-upload (256 KiB). */
    public const MAX_UPLOAD_BYTES = 262144;

    private string $envDir;

    public function __construct(?string $envDir = null)
    {
        $this->envDir = $envDir ?? ((string) config('deploy.database_path') . '/env');
    }

    /**
     * Path managed env file untuk sebuah app.
     */
    public function managedPath(string $appName): string
    {
        return $this->envDir . '/' . $appName . '.env';
    }

    /**
     * Tulis managed env file (format dotenv, satu KEY=VALUE per baris).
     * Nilai yang mengandung karakter khusus di-quote single-quote (literal,
     * tidak ada ekspansi $ oleh docker compose).
     *
     * @param array<string,string> $env
     */
    public function write(string $appName, array $env): void
    {
        if ($env === []) {
            $this->remove($appName);
            return;
        }
        if (!is_dir($this->envDir)) {
            @mkdir($this->envDir, 0775, true);
            if (!is_dir($this->envDir)) {
                throw new RuntimeException('Gagal membuat direktori env: ' . $this->envDir);
            }
        }

        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = $key . '=' . $this->quoteValue((string) $value);
        }

        $path = $this->managedPath($appName);
        if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis env file: ' . $path);
        }
        @chmod($path, 0600);
    }

    /**
     * Hapus managed env file app (saat env dikosongkan atau app dihapus).
     */
    public function remove(string $appName): void
    {
        $path = $this->managedPath($appName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Tulis override file yang meng-inject `environment:` literal ke SETIAP
     * service dari base compose. Nilai yang tidak relevan bagi sebuah service
     * akan diabaikan oleh runtime service tsb.
     *
     * @param string              $dir          direktori app
     * @param array<int,string>   $composeFiles daftar compose files app
     * @param array<string,string> $env         map KEY => value
     */
    public function writeOverride(string $dir, array $composeFiles, array $env): void
    {
        $services = $this->serviceNames($dir, $composeFiles);
        if ($services === []) {
            throw new RuntimeException('Tidak dapat menentukan daftar service untuk env override.');
        }

        $data = ['services' => []];
        foreach ($services as $svc) {
            $data['services'][$svc] = ['environment' => $env];
        }

        $yaml = Yaml::dump($data, 4, 2);
        if (@file_put_contents($dir . '/' . self::OVERRIDE_FILE, $yaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . self::OVERRIDE_FILE . '.');
        }
    }

    /**
     * Hapus override file dari direktori app (saat env dikosongkan).
     */
    public function removeOverride(string $dir): void
    {
        $path = $dir . '/' . self::OVERRIDE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Sinkronkan file env (managed + override) dengan state app. Idempoten —
     * dipanggil dari controller (saveEnv) dan LocalDeployer (deploy/rebuild/
     * rollback/applyEnv) agar file di disk selalu konsisten dengan apps.json.
     *
     * @return bool true bila env aktif (file ditulis), false bila dikosongkan
     */
    public function sync(array $app, string $dir, array $composeFiles): bool
    {
        $env = $this->envOf($app);
        if ($env === []) {
            $this->remove((string) ($app['name'] ?? ''));
            $this->removeOverride($dir);
            return false;
        }
        $this->write((string) ($app['name'] ?? ''), $env);
        $this->writeOverride($dir, $composeFiles, $env);
        return true;
    }

    /**
     * Parse file .env.example di direktori app untuk fitur import.
     * Mendukung komentar (#), baris kosong, dan nilai ber-quote tunggal/ganda.
     *
     * @return array<string,string>
     */
    public function parseEnvExample(string $dir): array
    {
        $path = $dir . '/.env.example';
        if (!is_file($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $result[$key] = $this->unquoteValue($value);
        }
        return $result;
    }

    /**
     * Parse isi file .env hasil upload dengan mode STRICT (berbeda dari
     * {@see parseEnvExample()} yang lenient): baris rusak, kunci tidak valid,
     * dan kunci duplikat **ditolak** dengan nomor baris — supaya user tidak
     * diam-diam kehilangan variabel saat import.
     *
     * Mendukung BOM UTF-8, CRLF/CR/LF, baris kosong, komentar `#` (boleh
     * didahului spasi), prefix opsional `export `, nilai single-quote
     * (literal), double-quote (escape `\\ \" \n \r \t`; `\n` ditolak karena
     * nilai env tidak boleh multi-baris), dan nilai tanpa quote (trim +
     * komentar inline `#` hanya bila didahului whitespace).
     *
     * Pesan error tidak pernah memuat nilai variabel (bisa berupa secret).
     *
     * @return array<string,string> map KEY => value sesuai urutan file
     * @throws RuntimeException isi file tidak valid / melebihi batas ukuran
     */
    public function parseEnvContent(string $content, int $maxBytes = self::MAX_UPLOAD_BYTES): array
    {
        $maxBytes = max(1, $maxBytes);
        if (strlen($content) > $maxBytes) {
            throw new RuntimeException(sprintf('File .env terlalu besar (maks %d KiB).', max(1, (int) ceil($maxBytes / 1024))));
        }
        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('File .env tidak valid: konten biner tidak didukung.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3); // BOM UTF-8
        }

        $result = [];
        $seen = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        foreach ($lines as $index => $rawLine) {
            $lineNo = $index + 1;
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^export\s+(.*)$/s', $line, $m) === 1) {
                $line = ltrim($m[1]);
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                throw new RuntimeException("Baris {$lineNo}: bukan format KEY=VALUE (tanda \"=\" tidak ditemukan).");
            }
            $key = trim(substr($line, 0, $eq));
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException("Baris {$lineNo}: nama variabel tidak valid. Gunakan huruf/angka/underscore, diawali huruf atau underscore.");
            }
            if (isset($seen[$key])) {
                throw new RuntimeException("Variabel \"{$key}\" didefinisikan dua kali (baris {$seen[$key]} dan {$lineNo}).");
            }
            $seen[$key] = $lineNo;

            $value = $this->parseEnvValue(substr($line, $eq + 1), $lineNo);
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new RuntimeException("Baris {$lineNo}: nilai mengandung karakter kontrol yang tidak didukung.");
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Gabungkan env hasil upload dengan env app yang sudah ada (murni, tanpa I/O).
     *
     * - `$replace = true`  : hanya env hasil upload (mode "ganti semua").
     * - `$replace = false` : env lama (hanya kunci valid & value string, urutan
     *   dipertahankan) lalu di-overlay env upload; kunci baru masuk di akhir.
     *
     * @param array<mixed,mixed> $existing
     * @param array<string,string> $uploaded
     * @return array<string,string>
     */
    public static function mergeUploaded(array $existing, array $uploaded, bool $replace): array
    {
        if ($replace) {
            return $uploaded;
        }

        $merged = [];
        foreach ($existing as $key => $value) {
            $key = (string) $key;
            if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1 || !is_string($value)) {
                continue;
            }
            $merged[$key] = $value;
        }
        foreach ($uploaded as $key => $value) {
            $merged[(string) $key] = (string) $value;
        }

        return $merged;
    }

    /**
     * Nama-nama service dari base compose (file pertama yang bukan override).
     *
     * @param array<int,string> $composeFiles
     * @return array<int,string>
     */
    private function serviceNames(string $dir, array $composeFiles): array
    {
        $baseFile = null;
        foreach ($composeFiles as $f) {
            if (!str_starts_with($f, 'docker-compose.override')) {
                $baseFile = $f;
                break;
            }
        }
        $baseFile = $baseFile ?? 'docker-compose.yml';

        $path = $dir . '/' . $baseFile;
        if (!is_file($path)) {
            throw new RuntimeException('Base compose tidak ditemukan: ' . $path);
        }

        $parsed = (new ComposeParser())->parse($path);
        return array_keys($parsed['services']);
    }

    /**
     * @return array<string,string>
     */
    private function envOf(array $app): array
    {
        $env = $app['env'] ?? [];
        if (!is_array($env)) {
            return [];
        }
        $result = [];
        foreach ($env as $key => $value) {
            $key = (string) $key;
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $result[$key] = (string) $value;
        }
        return $result;
    }

    /**
     * Quote nilai sesuai konvensi dotenv: nilai "aman" (hanya alfanumerik dan
     * beberapa simbol umum) dibiarkan polos; sisanya (spasi tepi, #, $, backtick,
     * quote, dsb.) dibungkus single-quote agar dibaca literal oleh compose.
     */
    private function quoteValue(string $value): string
    {
        if ($value === '') {
            return "''";
        }
        if (preg_match('/^[A-Za-z0-9_@%+=:,.\/\-]+$/', $value)) {
            return $value;
        }
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private function unquoteValue(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2 && $value[0] === $value[$len - 1] && ($value[0] === "'" || $value[0] === '"')) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /**
     * Parse satu nilai .env (strict) dari sisi kanan tanda `=`.
     */
    private function parseEnvValue(string $raw, int $lineNo): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if ($quote === "'") {
            $end = strpos($value, "'", 1);
            if ($end === false) {
                throw new RuntimeException("Baris {$lineNo}: kutip tunggal penutup tidak ditemukan.");
            }
            $literal = substr($value, 1, $end - 1);
            $this->assertNoTrailingGarbage(substr($value, $end + 1), $lineNo);

            return $literal;
        }

        if ($quote === '"') {
            $len = strlen($value);
            $out = '';
            $closed = false;
            for ($i = 1; $i < $len; $i++) {
                $ch = $value[$i];
                if ($ch === '\\') {
                    if ($i + 1 >= $len) {
                        throw new RuntimeException("Baris {$lineNo}: escape tidak lengkap di akhir nilai.");
                    }
                    $next = $value[$i + 1];
                    $out .= match ($next) {
                        '\\' => '\\',
                        '"' => '"',
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        // Escape tak dikenal dipertahankan apa adanya (tidak menebak
                        // maksud user, tidak memuat nilai di pesan error).
                        default => '\\' . $next,
                    };
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $closed = true;
                    $i++;
                    break;
                }
                $out .= $ch;
            }
            if (!$closed) {
                throw new RuntimeException("Baris {$lineNo}: kutip ganda penutup tidak ditemukan.");
            }
            $this->assertNoTrailingGarbage(substr($value, $i), $lineNo);
            if (str_contains($out, "\n") || str_contains($out, "\r")) {
                throw new RuntimeException("Baris {$lineNo}: nilai tidak boleh mengandung baris baru (escape \\n/\\r tidak didukung).");
            }

            return $out;
        }

        // Tanpa quote: trim + buang komentar inline `#` hanya bila didahului
        // whitespace (mis. `PASS=a#b` tetap `a#b`).
        if (preg_match('/\s#/', $value, $m, PREG_OFFSET_CAPTURE) === 1) {
            $value = rtrim(substr($value, 0, (int) $m[0][1]));
        }

        return $value;
    }

    /**
     * Setelah nilai ber-quote hanya boleh ada whitespace atau komentar.
     */
    private function assertNoTrailingGarbage(string $rest, int $lineNo): void
    {
        $rest = trim($rest);
        if ($rest === '' || str_starts_with($rest, '#')) {
            return;
        }
        throw new RuntimeException("Baris {$lineNo}: teks setelah nilai ber-quote tidak valid.");
    }
}
