<?php
declare(strict_types=1);

namespace app\library\Backup;

/**
 * Env-file kredensial S3 **per-run** untuk helper restic
 * (PLAN_VOLUME_BACKUP.md §4.4/§7 — higiene kredensial).
 *
 * Satu sumber kebenaran untuk mekanisme yang dipakai jalur **backup** maupun
 * **restore**: kredensial S3 wajib diteruskan ke `docker run` lewat `--env-file`
 * (bukan `-e KEY=VALUE` yang bocor ke `ps`). Berkasnya:
 *
 *  - ditulis di direktori sementara (default `<baseDir>/tmp`, mode 0700) dengan
 *    nama unik `restic.env.<hex>` dan **chmod 0600 SEBELUM** isi ditulis
 *    (menghindari jendela singkat berizin longgar);
 *  - **wajib dihapus** pemanggil lewat `remove()` di blok `finally` — sukses,
 *    exception, maupun gagal tulis parsial;
 *  - sisa berkas dari run yang crash (SIGKILL dsb.) dibersihkan best-effort
 *    lewat `sweepStale()`: hanya berkas ber-prefix `restic.env` (termasuk
 *    `restic.env` tanpa suffix milik implementasi lama) yang lebih tua dari
 *    ambang; berkas asing tidak pernah disentuh.
 *
 * Baris env ditulis **mentah** (`KEY=VALUE`, tanpa kutip). Ini WAJIB: berkas ini
 * diteruskan ke `docker run --env-file`, dan `docker run --env-file` **tidak**
 * memproses/menghapus kutip (berbeda dari `docker compose env_file:` yang
 * memang mengupas kutip). Bila nilai dibungkus `"…"`, restic/AWS menerima
 * karakter kutip sebagai bagian nilai → tanda tangan S3 salah → `Access Denied`.
 * Karena itu nilai juga dinormalisasi: karakter `\r`/`\n` dibuang agar tidak
 * bisa menyuntik variabel env palsu (satu baris = satu variabel).
 *
 * **Tidak ada secret di argv/log/JSON** — kelas ini hanya menyentuh file.
 */
class CredentialEnvFile
{
    /** Prefix berkas env kredensial per-run (`restic.env.<hex>`). */
    public const PREFIX = 'restic.env';

    /** Subdirektori default untuk env-file per-run (di bawah `baseDir`). */
    private const DIR_SUFFIX = 'tmp';

    /** @var array<string,string> kredensial non-kosong (KEY => VALUE). */
    private array $credentials;

    private string $dir;

    private int $staleMaxAge;

    private ?string $legacyPath;

    /** Penanda sweep dijalankan sekali per instance. */
    private bool $swept = false;

    /**
     * @param array<string,string> $credentials kredensial (KEY => VALUE); nilai kosong diabaikan
     * @param string|null          $dir         direktori env-file; null/'' = `<baseDir>/tmp`
     * @param string|null          $baseDir     direktori induk (mis. `BackupReport::dir()`)
     * @param int                  $staleMaxAge umur minimum (detik) sebelum sisa env-file dianggap basi
     * @param string|null          $legacyPath  path sisa implementasi lama yang ikut di-sweep;
     *                                          null = `<baseDir>/restic.env`
     */
    public function __construct(
        array $credentials,
        ?string $dir = null,
        ?string $baseDir = null,
        int $staleMaxAge = 3600,
        ?string $legacyPath = null,
    ) {
        $this->credentials = self::sanitize($credentials);
        $this->dir = self::resolveDir($dir, $baseDir);
        $this->staleMaxAge = max(60, $staleMaxAge);
        $this->legacyPath = $legacyPath ?? (trim((string) $baseDir) !== ''
            ? rtrim((string) $baseDir, '/') . '/' . self::PREFIX
            : null);
    }

    /**
     * Kredensial S3 dari override eksplisit (uji) atau `config('deploy.aws_*')`.
     * Hanya nilai non-kosong yang dipakai.
     *
     * @param array<string,mixed>|null $override
     * @return array<string,string>
     */
    public static function credentialsFromConfig(?array $override = null): array
    {
        if ($override !== null) {
            return self::sanitize($override);
        }

        return array_filter([
            'AWS_ACCESS_KEY_ID' => (string) config('deploy.aws_access_key_id', ''),
            'AWS_SECRET_ACCESS_KEY' => (string) config('deploy.aws_secret_access_key', ''),
            'AWS_DEFAULT_REGION' => (string) config('deploy.aws_default_region', ''),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * Direktori env-file efektif: `$dir` bila diisi, selain itu `<baseDir>/tmp`.
     */
    public static function resolveDir(?string $dir, ?string $baseDir): string
    {
        $dir = trim((string) $dir);
        if ($dir !== '') {
            return rtrim($dir, '/');
        }
        return rtrim((string) $baseDir, '/') . '/' . self::DIR_SUFFIX;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function hasCredentials(): bool
    {
        return $this->credentials !== [];
    }

    /**
     * Tulis env-file kredensial (chmod 0600, nama unik) di direktori sementara.
     *
     * Pemanggil **WAJIB** memanggil `remove()` di `finally`.
     *
     * @return string path env-file; '' bila tak ada kredensial / gagal tulis
     */
    public function create(): string
    {
        if ($this->credentials === []) {
            return '';
        }

        $dir = $this->dir;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        $lines = '';
        foreach ($this->credentials as $key => $value) {
            // TANPA kutip: `docker run --env-file` tidak mengupas kutip
            // (lihat docblock kelas). Nilai sudah bebas `\r`/`\n` dari sanitize().
            $lines .= $key . '=' . $value . "\n";
        }

        $path = $dir . '/' . self::PREFIX . '.' . bin2hex(random_bytes(8));
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return '';
        }

        // Batasi izin SEBELUM menulis isi (hindari jendela singkat 0644).
        @chmod($path, 0600);

        $ok = true;
        try {
            $written = @fwrite($handle, $lines);
            if ($written === false || $written !== strlen($lines)) {
                $ok = false;
            }
        } finally {
            @fclose($handle);
        }

        if (!$ok) {
            // Tulis parsial → jangan biarkan kredensial setengah tertinggal.
            $this->remove($path);
            return '';
        }

        return $path;
    }

    /**
     * Hapus env-file kredensial (idempotent; aman untuk path kosong).
     */
    public function remove(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Hapus sisa env-file kredensial dari run yang crash (SIGKILL dsb.) agar
     * tidak menumpuk. **Best-effort**: hanya menyentuh berkas ber-prefix
     * `restic.env` (termasuk `restic.env` tanpa suffix milik implementasi lama)
     * yang lebih tua dari ambang umur — berkas asing tidak pernah dihapus.
     * Dijalankan sekali per instance.
     */
    public function sweepStale(): void
    {
        if ($this->swept) {
            return;
        }
        $this->swept = true;

        $candidates = glob($this->dir . '/' . self::PREFIX . '.*') ?: [];
        if ($this->legacyPath !== null && is_file($this->legacyPath)) {
            $candidates[] = $this->legacyPath;
        }

        $threshold = time() - $this->staleMaxAge;
        foreach ($candidates as $path) {
            if (!is_file($path) || !self::isManagedEnvFile($path)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($path);
            }
        }
    }

    /**
     * Bersihkan kredensial (KEY => VALUE).
     *
     * Guard injeksi env-file: buang `\r`/`\n` dari nilai agar satu entri tidak
     * pernah menjadi lebih dari satu baris (`KEY=VAL\nAWS_...=...`). Nilai yang
     * tersisa kosong setelah normalisasi diabaikan.
     *
     * @param array<string,mixed> $vars
     * @return array<string,string>
     */
    private static function sanitize(array $vars): array
    {
        $clean = [];
        foreach ($vars as $key => $value) {
            $key = trim((string) $key);
            $value = str_replace(["\r", "\n"], '', (string) $value);
            if ($key !== '' && $value !== '') {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /**
     * Berkas env yang dikelola service ini: `restic.env` atau
     * `restic.env.<hex>` — bukan berkas asing di direktori yang sama.
     */
    private static function isManagedEnvFile(string $path): bool
    {
        return preg_match('/^restic\.env(\.[0-9a-f]+)?$/', basename($path)) === 1;
    }
}
