<?php
declare(strict_types=1);

namespace app\library\Adminer;

/**
 * Berkas sementara respons proxy Adminer (`runtime/adminer-proxy/*.body`).
 *
 * Kelas ini menutup temuan verifikasi Fase 5a (RENDAH):
 *  1. **Izin longgar** — direktori dulu dibuat `0775` dan berkas hasil `fopen`
 *     mewarisi umask proses (`0644`), sehingga isi respons Adminer (termasuk
 *     halaman DB yang sedang dibuka operator) bisa dibaca pengguna lain di host.
 *     Sekarang direktori ditegaskan `0700` dan berkas dibuat dengan umask `0077`
 *     (+ `chmod 0600` sebagai penegasan) sehingga hanya pemilik proses yang bisa
 *     membacanya.
 *  2. **Berkas menumpuk** — pembersihan dulu hanya bergantung `Workerman\Timer`
 *     (no-op di CLI) dan satu jalur request. Sekarang {@see prune()} idempoten,
 *     dipanggil juga dari `ensureRunning()` helper dan tepat sebelum berkas baru
 *     dibuat.
 *
 * Semua operasi **best-effort**: kegagalan filesystem tidak pernah melempar —
 * `create()` mengembalikan `null` (pemanggil yang memutuskan error proxy) dan
 * `prune()` mengembalikan jumlah berkas yang benar-benar terhapus. Statik murni,
 * tanpa state lintas-request; direktori selalu parameter (default
 * `runtime_path()/adminer-proxy`) agar bisa diuji dengan direktori temp.
 */
final class AdminerTempDir
{
    /** Umur maksimum berkas respons sebelum dipangkas (detik). */
    public const TTL = 3600;

    /** Suffix berkas respons proxy. */
    public const SUFFIX = '.body';

    /**
     * Direktori temp efektif (`$dir` kosong → `runtime/adminer-proxy`).
     */
    public static function path(string $dir = ''): string
    {
        $dir = trim($dir);

        return rtrim($dir !== '' ? $dir : (string) runtime_path() . '/adminer-proxy', '/');
    }

    /**
     * Pastikan direktori ada dengan izin `0700` (best-effort, termasuk mengetatkan
     * direktori lama yang terlanjur `0755`/`0775`).
     *
     * @return string path direktori efektif
     */
    public static function ensure(string $dir = ''): string
    {
        $dir = self::path($dir);
        if (!is_dir($dir)) {
            // 0700 → berkas di dalamnya tidak bisa ditelusuri pengguna lain.
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir)) {
            @chmod($dir, 0700);
        }

        return $dir;
    }

    /**
     * Buat berkas respons baru berizin `0600`.
     *
     * urutan: ensure() → prune() → buat berkas. Prune dijalankan lebih dulu
     * supaya satu request yang gagal di tengah jalan tidak menambah berkas baru
     * di atas tumpukan lama.
     *
     * @return array{0:string,1:resource}|null null bila direktori/berkas gagal dibuat
     */
    public static function create(string $dir = ''): ?array
    {
        $dir = self::ensure($dir);
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }

        self::prune($dir);

        $path = $dir . '/' . bin2hex(random_bytes(8)) . self::SUFFIX;

        // umask dipersempit HANYA di sekitar pembuatan berkas: berkas lahir
        // langsung 0600 (tanpa jendela "0644 lalu chmod"), lalu dipulihkan.
        $previous = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previous);
        }

        if ($handle === false) {
            return null;
        }
        @chmod($path, 0600);

        return [$path, $handle];
    }

    /**
     * Pangkas berkas respons yang lebih tua dari `$ttl` detik.
     *
     * Tidak pernah melempar: direktori hilang, glob gagal, atau berkas sudah
     * dihapus request lain semuanya dianggap "tidak ada yang perlu dilakukan".
     *
     * @param int|null $ttl jumlah detik; null → {@see TTL}
     * @return int jumlah berkas yang benar-benar terhapus
     */
    public static function prune(string $dir = '', ?int $ttl = null): int
    {
        $removed = 0;
        try {
            $dir = self::path($dir);
            if (!is_dir($dir)) {
                return 0;
            }

            $cutoff = time() - max(0, $ttl ?? self::TTL);
            foreach ((array) glob($dir . '/*' . self::SUFFIX) as $file) {
                $file = (string) $file;
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime <= $cutoff && @unlink($file)) {
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            // prune bersifat oportunistik — jangan pernah menggagalkan request.
            return $removed;
        }

        return $removed;
    }
}
