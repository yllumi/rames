<?php
declare(strict_types=1);

namespace app\library\Branding;

use InvalidArgumentException;
use RuntimeException;

/**
 * Penyimpanan berkas logo brand kustom di disk.
 *
 * Direktori default mengikuti rantai fallback berikut (yang pertama tidak
 * kosong):
 *  a. `config('deploy.branding_dir')`;
 *  b. `dirname(config('deploy.sqlite_file')) . '/branding'` (menempel volume
 *     basis data, jadi ikut terbawa backup);
 *  c. `base_path() . '/database/branding'`;
 *  d. `database/branding` relatif root repo.
 *
 * Nama berkas **selalu tetap** (`logo.png`/`logo.jpg`/`logo.webp`) sehingga
 * nama asli unggahan (yang bisa memuat path traversal) tidak pernah dipakai.
 * Penulisan atomik: `logo.<ext>.tmp` → `rename()`.
 *
 * Validasi isi berkas dilakukan dua lapis: whitelist MIME yang diklaim
 * (`image/png`, `image/jpeg`, `image/webp` — SVG/GIF ditolak karena bisa
 * memuat skrip) dan verifikasi nyata lewat `getimagesize()` yang harus cocok
 * dengan MIME klaim.
 *
 * Direktori dapat di-override lewat konstruktor agar tes memakai temp.
 */
final class BrandingLogo
{
    /** Batas ukuran berkas logo: 512 KiB. */
    public const MAX_BYTES = 524288;

    /** Batas dimensi (piksel) per sisi. */
    public const MAX_DIMENSION = 4096;

    /** Ekstensi yang mungkin tertinggal di direktori (urutan pencarian). */
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * MIME yang diterima → ekstensi + tipe `getimagesize()`.
     *
     * @var array<string,array{ext:string,type:int}>
     */
    private const ALLOWED = [
        'image/png' => ['ext' => 'png', 'type' => IMAGETYPE_PNG],
        'image/jpeg' => ['ext' => 'jpg', 'type' => IMAGETYPE_JPEG],
        'image/webp' => ['ext' => 'webp', 'type' => IMAGETYPE_WEBP],
    ];

    private readonly string $dir;

    /**
     * @param string|null $dir direktori penyimpanan; null = {@see self::defaultDir()}
     */
    public function __construct(?string $dir = null)
    {
        $requested = $dir !== null ? trim($dir) : '';
        $this->dir = $requested !== '' ? self::normalizeDir($requested) : self::defaultDir();
    }

    /**
     * Direktori default menurut rantai fallback konfigurasi.
     */
    public static function defaultDir(): string
    {
        if (function_exists('config')) {
            $configured = config('deploy.branding_dir');
            if (is_string($configured) && trim($configured) !== '') {
                return self::normalizeDir(trim($configured));
            }

            $sqliteFile = config('deploy.sqlite_file');
            if (is_string($sqliteFile) && trim($sqliteFile) !== '') {
                return self::normalizeDir(dirname(trim($sqliteFile)) . '/branding');
            }
        }

        if (function_exists('base_path')) {
            return self::normalizeDir(base_path() . '/database/branding');
        }

        // `app/library/Branding` → naik tiga level = root repo.
        return self::normalizeDir(dirname(__DIR__, 3) . '/database/branding');
    }

    /**
     * Direktori penyimpanan logo (tanpa garis miring akhir).
     */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Path absolut berkas logo tersimpan, atau null bila belum ada.
     */
    public function path(): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            $file = $this->dir . '/logo.' . $extension;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * MIME berkas logo tersimpan, atau null bila belum ada.
     */
    public function mime(): ?string
    {
        $path = $this->path();
        if ($path === null) {
            return null;
        }

        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => null,
        };
    }

    /**
     * Ukuran berkas logo dalam byte (0 bila belum ada).
     */
    public function size(): ?int
    {
        $path = $this->path();
        if ($path === null) {
            return 0;
        }

        $size = @filesize($path);
        return is_int($size) ? $size : 0;
    }

    /**
     * ETag berkas logo (`sha1_file` 12 karakter + ekstensi), atau null.
     *
     * Dipakai sebagai cache-buster URL `/branding/logo?v=...`.
     */
    public function etag(): ?string
    {
        $path = $this->path();
        if ($path === null) {
            return null;
        }

        $hash = @sha1_file($path);
        if (!is_string($hash) || $hash === '') {
            return null;
        }

        return substr($hash, 0, 12) . '.' . strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Validasi & simpan berkas logo; mengembalikan nama berkas final
     * (`logo.png`/`logo.jpg`/`logo.webp`).
     *
     * Komposisi {@see self::validate()} + {@see self::store()}: pemanggil baru
     * yang butuh urutan "store dulu, berkas kemudian" memanggil keduanya
     * terpisah.
     *
     * @param array{tmp:string,mime:string,size:int,error:int,name:string} $upload
     *
     * @throws InvalidArgumentException bila unggahan tidak valid
     * @throws RuntimeException bila berkas gagal ditulis
     */
    public function save(array $upload): string
    {
        $name = $this->validate($upload);
        $this->store($upload, $name);

        return $name;
    }

    /**
     * Validasi unggahan **tanpa efek samping** (tidak menulis/menghapus
     * berkas) dan kembalikan nama berkas final (`logo.png`/`logo.jpg`/`logo.webp`).
     *
     * Dipisah dari {@see self::store()} agar pemanggil bisa menyimpan metadata
     * lebih dulu dan baru menulis berkas.
     *
     * @param array{tmp:string,mime:string,size:int,error:int,name:string} $upload
     *
     * @throws InvalidArgumentException bila unggahan tidak valid
     */
    public function validate(array $upload): string
    {
        $error = array_key_exists('error', $upload) ? (int) $upload['error'] : UPLOAD_ERR_OK;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Tidak ada berkas yang diunggah.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Berkas logo gagal diunggah (kode error ' . $error . ').');
        }

        $tmp = is_string($upload['tmp'] ?? null) ? trim((string) $upload['tmp']) : '';
        if ($tmp === '' || !is_file($tmp)) {
            throw new InvalidArgumentException('Berkas unggahan tidak ditemukan.');
        }

        $declaredSize = is_numeric($upload['size'] ?? null) ? (int) $upload['size'] : 0;
        $actualSize = (int) (@filesize($tmp) ?: 0);
        if (max($declaredSize, $actualSize) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Logo maksimal 512 KB.');
        }

        $claimed = is_string($upload['mime'] ?? null) ? strtolower(trim((string) $upload['mime'])) : '';
        if (!isset(self::ALLOWED[$claimed])) {
            // SVG/GIF ikut tertolak di sini: SVG dapat memuat skrip (XSS).
            throw new InvalidArgumentException('Format logo harus PNG, JPEG, atau WebP.');
        }
        $expected = self::ALLOWED[$claimed];

        $info = @getimagesize($tmp);
        if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
            throw new InvalidArgumentException('Isi berkas bukan gambar yang valid.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ((int) $info[2] !== $expected['type']) {
            throw new InvalidArgumentException('Jenis berkas tidak cocok dengan isinya.');
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException(
                'Dimensi logo maksimal ' . self::MAX_DIMENSION . '×' . self::MAX_DIMENSION . ' piksel.'
            );
        }

        $detected = image_type_to_mime_type((int) $info[2]);
        if (strtolower((string) $detected) !== $claimed) {
            throw new InvalidArgumentException('Jenis berkas tidak cocok dengan isinya.');
        }

        return 'logo.' . $expected['ext'];
    }

    /**
     * Tulis berkas logo hasil {@see self::validate()} ke direktori penyimpanan
     * secara atomik (`logo.<ext>.tmp` → `rename`), batasi ke `0644`, lalu
     * bersihkan varian ekstensi lain agar hanya ada satu `logo.*`.
     *
     * @param array{tmp:string,mime:string,size:int,error:int,name:string} $upload
     * @param string $name nama berkas final dari {@see self::validate()}
     *
     * @throws RuntimeException bila berkas gagal ditulis
     */
    public function store(array $upload, string $name): void
    {
        if (preg_match('/^logo\.(png|jpg|webp)$/', $name) !== 1) {
            // Pertahanan tambahan: `$name` hanya boleh berasal dari validate().
            throw new RuntimeException('Nama berkas logo tidak valid: ' . $name);
        }

        $tmp = is_string($upload['tmp'] ?? null) ? trim((string) $upload['tmp']) : '';
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Berkas unggahan tidak ditemukan.');
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Tidak bisa membuat direktori logo: ' . $this->dir);
        }

        $target = $this->dir . '/' . $name;
        $temporary = $target . '.tmp';

        if (!@copy($tmp, $temporary)) {
            throw new RuntimeException('Gagal menyiapkan berkas logo.');
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Gagal menyimpan berkas logo.');
        }

        // Bersihkan varian ekstensi lain agar hanya ada satu `logo.*`.
        $this->removeAllExcept($name);
    }

    /**
     * Hapus semua berkas `logo.*` (termasuk sisa `.tmp`). Idempoten.
     */
    public function delete(): void
    {
        $this->removeAllExcept(null);
    }

    /**
     * Hapus seluruh entri `logo.*` kecuali `$keep` (bila diberikan).
     */
    private function removeAllExcept(?string $keep): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $entries = @scandir($this->dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($keep !== null && $entry === $keep) {
                continue;
            }
            if (str_starts_with($entry, 'logo.')) {
                @unlink($this->dir . '/' . $entry);
            }
        }
    }

    /**
     * Buang garis miring akhir; akar (`/`) dipertahankan.
     */
    private static function normalizeDir(string $dir): string
    {
        $trimmed = rtrim($dir, '/');
        return $trimmed !== '' ? $trimmed : '/';
    }
}
