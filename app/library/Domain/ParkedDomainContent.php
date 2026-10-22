<?php
declare(strict_types=1);

namespace app\library\Domain;

use RuntimeException;

/**
 * Penyimpanan **berkas konten** halaman domain parkir.
 *
 * Satu entri parkir mode `html` disajikan Nginx sebagai berkas statis:
 *
 *   {parked_root}/{id}/index.html
 *
 * Kelas ini hanya mengurus filesystem (bukan metadata — itu
 * {@see \app\library\Storage\ParkedDomainStore}). `$rootPath` di-inject dari
 * `config('deploy.parked_root')` sehingga path dapat di-override saat tes.
 *
 * Keamanan path: id entri **wajib** berpola `pd_` + 12 heksadesimal
 * ({@see self::ID_PATTERN}) dan diperiksa **sebelum** operasi filesystem apa
 * pun. Tanpa pemeriksaan ini id berisi `../` bisa menulis di luar akar parkir.
 * `$rootPath` juga wajib absolut tanpa segmen `..`: path relatif di-resolve
 * relatif oleh Nginx host (bukan `${PWD}` dashboard) sehingga halaman 404.
 *
 * Penulisan atomik: isi ditulis ke `index.html.tmp` lalu `rename()` (satu
 * direktori ⇒ atomik). Pembaca tidak pernah melihat berkas setengah jadi;
 * kegagalan apa pun membersihkan berkas sementara.
 */
final class ParkedDomainContent
{
    /** Pola id entri parkir — satu sumber untuk lapisan Nginx & tes.
     *  Memakai `\z` (bukan `$`) agar newline di ujung id ikut ditolak. */
    public const ID_PATTERN = '/^pd_[a-f0-9]{12}\z/';

    /** Nama berkas konten di dalam direktori entri. */
    public const FILE_NAME = 'index.html';

    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * Akar direktori konten (apa adanya dari konfigurasi).
     */
    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * Direktori konten satu entri (`{root}/{id}`).
     *
     * @throws RuntimeException bila id tidak berpola aman atau akar parkir tidak absolut/ber-`..`
     */
    public function dirFor(string $id): string
    {
        $this->assertId($id);

        return $this->assertRootPath() . '/' . $id;
    }

    /**
     * Path berkas HTML satu entri (`{root}/{id}/index.html`).
     *
     * @throws RuntimeException bila id tidak berpola aman atau akar parkir tidak absolut/ber-`..`
     */
    public function pathFor(string $id): string
    {
        return $this->dirFor($id) . '/' . self::FILE_NAME;
    }

    /**
     * Pastikan akar parkir ada (dibuat bila perlu) dan writable — fail-fast
     * sebelum entri disimpan / config Nginx ditulis.
     *
     * @throws RuntimeException dengan path + perintah perbaikan
     */
    public function ensureWritable(): void
    {
        $root = $this->assertRootPath();

        if (!is_dir($root)) {
            if (!@mkdir($root, 0755, true) && !is_dir($root)) {
                throw new RuntimeException(
                    'Direktori konten domain parkir tidak ada dan gagal dibuat: ' . $root . '. '
                    . 'Buat manual: sudo mkdir -p ' . $root . ' && sudo chown -R $(id -u):$(id -g) ' . $root
                    . ' — pastikan path ini sama di host (di bawah bind mount ${PWD}) agar Nginx host bisa membacanya.'
                );
            }
            @chmod($root, 0755);
        }

        if (!is_writable($root)) {
            throw new RuntimeException(
                'Dashboard tidak punya izin tulis ke ' . $root . '. Perbaiki dengan: sudo chown -R $(id -u):$(id -g) '
                . $root . ' (atau jalankan dashboard sebagai user yang punya akses).'
            );
        }
    }

    /**
     * Tulis (atomik) berkas `index.html` untuk satu entri.
     *
     * @return string path berkas final
     *
     * @throws RuntimeException bila direktori/berkas gagal dibuat/ditulis
     */
    public function write(string $id, string $html): string
    {
        // Validasi id SEBELUM operasi filesystem apa pun (anti path traversal).
        $dir = $this->dirFor($id);
        $this->ensureWritable();

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(
                'Gagal membuat direktori konten domain parkir: ' . $dir . '. Periksa izin tulis akar parkir '
                . '(PARKED_ROOT) lalu coba lagi.'
            );
        }
        @chmod($dir, 0755);

        $target = $dir . '/' . self::FILE_NAME;
        $tmp = $target . '.tmp';

        if (@file_put_contents($tmp, $html, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException(
                'Gagal menulis berkas konten domain parkir: ' . $target . '. Periksa izin tulis ' . $dir
                . ' (mis. sudo chown -R $(id -u):$(id -g) ' . $this->rootPath . ').'
            );
        }
        @chmod($tmp, 0644);

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException(
                'Gagal memindahkan berkas konten domain parkir ke ' . $target . '. Periksa izin tulis direktori '
                . $dir . ' lalu coba lagi.'
            );
        }

        return $target;
    }

    /**
     * Baca isi HTML satu entri; `null` bila belum ada/tak terbaca.
     */
    public function read(string $id): ?string
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return null;
        }

        $data = @file_get_contents($path);

        return $data === false ? null : $data;
    }

    /**
     * Hapus berkas + direktori entri (best-effort; direktori hanya dihapus
     * bila kosong). Tidak melempar untuk kegagalan filesystem — pemanggil
     * (manager) sudah menghapus metadata/config lebih dulu.
     *
     * @throws RuntimeException hanya bila id tidak berpola aman
     */
    public function remove(string $id): void
    {
        $dir = $this->dirFor($id);

        foreach ([self::FILE_NAME, self::FILE_NAME . '.tmp'] as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (!is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if (is_array($entries) && array_diff($entries, ['.', '..']) === []) {
            @rmdir($dir);
        }
    }

    /**
     * Tolak akar konten yang tidak bisa dipakai Nginx host: kosong, mengandung
     * karakter kontrol/NUL, relatif, atau ber-segmen `..`.
     *
     * Path relatif akan ditafsirkan relatif oleh Nginx host (bukan terhadap
     * `${PWD}` dashboard) sehingga halaman parkir 404 senyap. Pemeriksaan ini
     * berjalan **sebelum** operasi filesystem apa pun (`mkdir`, tulis, hapus).
     *
     * @throws RuntimeException
     */
    private function assertRootPath(): string
    {
        $root = rtrim(trim($this->rootPath), '/\\');
        if ($root === '') {
            throw new RuntimeException(
                'Path akar konten domain parkir kosong. Setel PARKED_ROOT (config deploy.parked_root) '
                . 'ke path absolut yang sama di host, mis. /srv/rames/database/parked.'
            );
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $root) === 1) {
            throw new RuntimeException(
                'Path akar konten domain parkir mengandung karakter kontrol/NUL dan tidak bisa dipakai. '
                . 'Setel PARKED_ROOT (config deploy.parked_root) ke path absolut, mis. /srv/rames/database/parked.'
            );
        }
        if (!str_starts_with($root, '/')) {
            throw new RuntimeException(
                'Path akar konten domain parkir wajib absolut (diawali "/") dan sama persis di host: '
                . '"' . $this->rootPath . '". Setel PARKED_ROOT (config deploy.parked_root) ke path absolut '
                . 'di bawah ${PWD}, mis. /srv/rames/database/parked.'
            );
        }
        if (in_array('..', explode('/', $root), true)) {
            throw new RuntimeException(
                'Path akar konten domain parkir tidak boleh mengandung segmen "..": "' . $this->rootPath . '". '
                . 'Gunakan path absolut tanpa "..", mis. /srv/rames/database/parked.'
            );
        }

        return $root;
    }

    /**
     * Tolak id yang tidak berpola `pd_` + 12 hex (path traversal/injeksi).
     *
     * @throws RuntimeException
     */
    private function assertId(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new RuntimeException(
                'Id domain parkir tidak valid: "' . $id . '". Id harus berbentuk "pd_" diikuti 12 karakter heksadesimal.'
            );
        }
    }
}
