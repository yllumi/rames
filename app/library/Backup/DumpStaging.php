<?php
declare(strict_types=1);

namespace app\library\Backup;

use RuntimeException;

/**
 * Akses direktori **staging** hasil dump logis DB (`DumpRunner`:
 * `{root}/{project}/{timestamp}/{database|all-databases}.sql`).
 *
 * Dipakai dua sisi multi-server (plan §4.1/§4.3/§10.5) tanpa jalur kode kedua:
 *  - **sisi agent** — melayani unduhan berkas `.sql` hasil `db_dump` ke control
 *    plane (`GET /backups/dump`) secara bertahap (offset + batas per panggilan);
 *  - **sisi mana pun yang menjalankan dump** — retensi staging supaya berkas
 *    `.sql` tidak menumpuk tanpa batas (`prune()`), dipanggil setelah dump sukses
 *    (`BackupJobRunner::dbDump()` ⇒ `cli/deploy.php db_dump` di server agent).
 *
 * Aturan keselamatan (tidak boleh dilanggar):
 *  - nama project divalidasi `VolumeStateGuard::assertProjectName()`; nama
 *    direktori timestamp yang tidak berbentuk aman **diabaikan** (bukan dihapus);
 *  - `read()` hanya menerima berkas `*.sql` **di dalam** root staging (realpath
 *    prefix check) — path dari klien tidak pernah dipercaya;
 *  - `prune()` tidak pernah menghapus berkas di luar root staging, dan selalu
 *    **mempertahankan** `keep` direktori terbaru per project.
 *
 * Tanpa state lintas-request: instans dibuat per pemanggilan (hanya `$root`),
 * sehingga aman di worker Webman persistent.
 */
final class DumpStaging
{
    /** Jumlah direktori dump terbaru yang dipertahankan per project (default). */
    public const DEFAULT_KEEP = 5;

    /** Umur maksimum (detik) di luar `keep` terbaru (default 7 hari). */
    public const DEFAULT_MAX_AGE_SECONDS = 604800;

    /** Plafon satu pembacaan (byte) — satu respons HTTP tidak boleh tanpa batas. */
    public const MAX_READ_BYTES = 4194304; // 4 MiB per panggilan

    private readonly string $root;

    /**
     * @param string|null $root direktori staging; null ⇒
     *                    `runtime_path('backup/staging')` (satu sumber dengan
     *                    `DumpRunner`)
     */
    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root !== null && $root !== '' ? $root : runtime_path('backup/staging'), '/');
    }

    /** Direktori staging efektif (tanpa `/` di ujung). */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Berkas `.sql` **terbaru** untuk satu project — `null` bila belum ada dump.
     *
     * Direktori timestamp diurutkan menurun berdasarkan namanya (timestamp
     * `Ymd-His` yang dipakai `DumpRunner` terurut leksikografis = kronologis);
     * direktori pertama yang memuat berkas `.sql` menang.
     *
     * @param string $project nama project (divalidasi fail-fast)
     * @return array{file:string,name:string,dir:string,bytes:int}|null
     * @throws RuntimeException nama project tidak sah
     */
    public function latest(string $project): ?array
    {
        VolumeStateGuard::assertProjectName($project);

        $projectDir = $this->root . '/' . $project;
        if (!is_dir($projectDir)) {
            return null;
        }

        $dirs = [];
        foreach (scandir($projectDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $projectDir . '/' . $entry;
            if (is_dir($path) && self::validSegment($entry)) {
                $dirs[] = $entry;
            }
        }
        if ($dirs === []) {
            return null;
        }

        rsort($dirs, SORT_STRING);

        foreach ($dirs as $entry) {
            $dir = $projectDir . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                if (!str_ends_with($file, '.sql')) {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (!is_file($path)) {
                    continue;
                }

                return [
                    'file' => $path,
                    'name' => $file,
                    'dir' => $dir,
                    'bytes' => (int) (@filesize($path) ?: 0),
                ];
            }
        }

        return null;
    }

    /**
     * Baca potongan berkas `.sql` (offset + batas) — dipotong diam-diam di akhir
     * berkas. Offset di luar berkas ⇒ string kosong (bukan galat): pemanggil
     * (control plane) memakai `X-Rames-Offset`/`eof` untuk berhenti.
     *
     * @param string $file  path berkas (wajib di dalam root staging & `.sql`)
     * @param int    $offset byte awal (≥ 0)
     * @param int    $limit  jumlah byte maksimum (1..{@see self::MAX_READ_BYTES})
     * @throws RuntimeException berkas di luar root staging / tidak dapat dibaca
     */
    public function read(string $file, int $offset, int $limit): string
    {
        $path = $this->assertInside($file);
        $offset = max(0, $offset);
        $limit = max(1, min($limit, self::MAX_READ_BYTES));

        $size = (int) (@filesize($path) ?: 0);
        if ($offset >= $size) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Berkas dump tidak dapat dibaca di server ini.');
        }

        try {
            if ($offset > 0 && @fseek($handle, $offset) !== 0) {
                throw new RuntimeException('Berkas dump tidak dapat dibaca di server ini.');
            }
            $chunk = @fread($handle, $limit);
        } finally {
            @fclose($handle);
        }

        if ($chunk === false) {
            throw new RuntimeException('Berkas dump tidak dapat dibaca di server ini.');
        }

        return $chunk;
    }

    /**
     * Retensi staging: pertahankan `keep` direktori dump terbaru per project;
     * sisanya dihapus bila umurnya melewati `maxAgeSeconds` (`maxAgeSeconds <= 0`
     * ⇒ hapus semua sisanya). Direktori project yang menjadi kosong ikut dibuang.
     *
     * **Best-effort**: kegagalan hapus satu entri tidak menggagalkan pembersihan
     * entri lain (dump berikutnya akan mencobanya lagi) — jumlah yang benar-benar
     * terhapus dilaporkan di nilai kembalian.
     *
     * @return array{removed:int,kept:int,bytes:int}
     * @throws RuntimeException akar staging tidak dapat dibaca
     */
    public function prune(int $keep = self::DEFAULT_KEEP, int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS): array
    {
        $keep = max(1, $keep);
        $maxAgeSeconds = max(0, $maxAgeSeconds);
        $cutoff = time() - $maxAgeSeconds;

        $removed = 0;
        $kept = 0;
        $bytes = 0;

        if (!is_dir($this->root)) {
            return ['removed' => 0, 'kept' => 0, 'bytes' => 0];
        }

        foreach (scandir($this->root) ?: [] as $project) {
            if ($project === '.' || $project === '..') {
                continue;
            }
            $projectDir = $this->root . '/' . $project;
            if (!is_dir($projectDir) || !self::validProject($project)) {
                continue;
            }

            $dirs = [];
            foreach (scandir($projectDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $projectDir . '/' . $entry;
                if (is_dir($path) && self::validSegment($entry)) {
                    $dirs[] = $entry;
                }
            }
            rsort($dirs, SORT_STRING);

            foreach ($dirs as $index => $entry) {
                $path = $projectDir . '/' . $entry;
                if ($index < $keep) {
                    $kept++;
                    continue;
                }
                $mtime = (int) (@filemtime($path) ?: 0);
                if ($maxAgeSeconds > 0 && $mtime >= $cutoff) {
                    $kept++; // masih segar — biarkan sampai batas umur terlampaui
                    continue;
                }

                $bytes += self::sizeOf($path);
                self::removeTree($path);
                $removed++;
            }

            @rmdir($projectDir); // hanya berhasil bila sudah kosong
        }

        return ['removed' => $removed, 'kept' => $kept, 'bytes' => $bytes];
    }

    /**
     * Pastikan berkas ada di dalam root staging & berekstensi `.sql` — jaring
     * pengaman terakhir agar path dari klien tidak pernah keluar dari staging.
     *
     * @throws RuntimeException
     */
    private function assertInside(string $file): string
    {
        $file = trim($file);
        if ($file === '' || !str_ends_with($file, '.sql')) {
            throw new RuntimeException('Berkas dump tidak sah.');
        }

        $real = @realpath($file);
        $rootReal = @realpath($this->root);
        if ($real === false || $rootReal === false) {
            throw new RuntimeException('Berkas dump tidak ditemukan di server ini.');
        }
        if ($real !== $rootReal && !str_starts_with($real, $rootReal . '/')) {
            throw new RuntimeException('Berkas dump di luar direktori staging — ditolak.');
        }

        return $real;
    }

    /** Nama segmen (project/timestamp) yang aman untuk berkas & direktori. */
    private static function validSegment(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && strpbrk($name, "/\\\r\n\0") === false;
    }

    /** Nama project yang sah menurut aturan volume (`VolumeStateGuard`). */
    private static function validProject(string $name): bool
    {
        try {
            VolumeStateGuard::assertProjectName($name);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Ukuran total sebuah direktori (byte) — untuk laporan retensi. */
    private static function sizeOf(string $path): int
    {
        if (!is_dir($path)) {
            return is_file($path) ? (int) (@filesize($path) ?: 0) : 0;
        }

        $total = 0;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $total += self::sizeOf($path . '/' . $entry);
        }

        return $total;
    }

    /** Hapus pohon direktori (best-effort, tidak melempar). */
    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
