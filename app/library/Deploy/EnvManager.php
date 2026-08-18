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
}
