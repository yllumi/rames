<?php
declare(strict_types=1);

namespace app\library\Template;

use RuntimeException;

/**
 * Status & log fitur "tarik image template" (SPECS.md §7.13, tab Template di `/nginx`).
 *
 * Dua jenis berkas di dalam satu `run_dir` (dibaca UI, ditulis worker):
 *  1. `run.json` — status run terakhir/sedang berjalan (`TemplatePullService::start()`
 *     menulis kerangka awal, worker `cli/pull.php` memperbaruinya, dan pemanggil
 *     panel menutup run yang ditinggalkan worker).
 *  2. `<id>.log` — keluaran **mentah** `docker pull` per image (stdout+stderr),
 *     pola mengikuti `UpdateState` supaya bisa dibaca lewat endpoint status.
 *
 * Penulisan JSON atomik (tmp + `rename` + `LOCK_EX`) dan `mkdir` 0775 — pola
 * `Update\UpdateState`. Kelas ini TIDAK menyimpan cache di properti: setiap
 * pembacaan menyentuh disk (worker persistent Webman, banyak proses).
 */
final class TemplatePullState
{
    /** Nama berkas status run (di dalam run_dir). */
    public const RUN_FILE = 'run.json';

    /** Pola nama berkas log (aman dijadikan input endpoint: tanpa traversal). */
    public const LOG_PATTERN = '/^[A-Za-z0-9._-]+\.log$/';

    private string $runFile;

    public function __construct(private readonly string $runDir)
    {
        $this->runFile = rtrim($this->runDir, '/') . '/' . self::RUN_FILE;
    }

    public function runDir(): string
    {
        return $this->runDir;
    }

    public function runFile(): string
    {
        return $this->runFile;
    }

    // ==================================================================
    // Status run
    // ==================================================================

    /**
     * Status run (selalu berbentuk lengkap — kunci mengikuti kontrak UI).
     *
     * @return array{id:?string,slug:string,title:string,stage:string,message:string,result:?string,error:?string,started_at:?string,finished_at:?string,actor:string,images:array<int,string>,results:array<int,array{image:string,ok:bool,seconds:float,message:string}>,log:?string,pid:int}
     */
    public function run(): array
    {
        $data = $this->readJson();

        return [
            'id' => isset($data['id']) && (string) $data['id'] !== '' ? (string) $data['id'] : null,
            'slug' => (string) ($data['slug'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'stage' => (string) ($data['stage'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'result' => isset($data['result']) && $data['result'] !== null ? (string) $data['result'] : null,
            'error' => isset($data['error']) && $data['error'] !== null && (string) $data['error'] !== ''
                ? (string) $data['error']
                : null,
            'started_at' => isset($data['started_at']) && (string) $data['started_at'] !== '' ? (string) $data['started_at'] : null,
            'finished_at' => isset($data['finished_at']) && (string) $data['finished_at'] !== '' ? (string) $data['finished_at'] : null,
            'actor' => (string) ($data['actor'] ?? ''),
            'images' => self::normalizeImages($data['images'] ?? []),
            'results' => self::normalizeResults($data['results'] ?? []),
            'log' => isset($data['log']) && (string) $data['log'] !== '' ? (string) $data['log'] : null,
            'pid' => (int) ($data['pid'] ?? 0),
        ];
    }

    /**
     * Tulis kerangka run baru (dipanggil service sebelum spawn worker) supaya UI
     * langsung menampilkan progres tanpa menunggu worker hidup.
     */
    public function startRun(array $run): void
    {
        $this->writeJson($run);
    }

    /**
     * Gabungkan `$patch` ke status yang ada (dipakai worker tiap tahap).
     *
     * Bila `$patch` memuat `id` yang **berbeda** dari run tersimpan, kerangka
     * lama TIDAK diwarisi (run baru ⇒ status baru) — mencegah sisa `results`
     * run sebelumnya bocor ke run berikutnya.
     *
     * @return array status setelah penggabungan (bentuk hasil `run()`)
     */
    public function update(array $patch): array
    {
        $data = $this->readJson();

        $id = isset($patch['id']) ? (string) $patch['id'] : '';
        if ($id !== '' && isset($data['id']) && (string) $data['id'] !== '' && (string) $data['id'] !== $id) {
            $data = [];
        }

        foreach ($patch as $key => $value) {
            $data[(string) $key] = $value;
        }

        $this->writeJson($data);

        return $this->run();
    }

    /**
     * Tutup run dengan hasil akhir (`success`/`error`). Dipanggil worker di
     * `finally` (agar laporan SELALU tertulis) dan oleh panel saat run
     * ditinggalkan worker (stale).
     *
     * @return array status setelah penutupan
     */
    public function finalize(string $result, string $error = ''): array
    {
        $run = $this->run();
        if ($run['id'] === null) {
            return $run; // belum pernah ada run — tidak ada yang ditutup
        }

        $ok = $result === 'success';
        $message = $ok
            ? 'Selesai — ' . count($run['results']) . ' image diproses.'
            : ($error !== '' ? $error : 'Gagal tanpa pesan.');

        return $this->update([
            'stage' => 'finished',
            'result' => $ok ? 'success' : 'error',
            'error' => $error !== '' ? $error : null,
            'message' => $message,
            'finished_at' => date('c'),
            // Pid proses yang sudah selesai tidak boleh diperiksa lagi (pid bisa
            // dipakai ulang proses lain → `posix_kill` menandai proses yang salah).
            'pid' => 0,
        ]);
    }

    /**
     * Apakah run dianggap **sedang berjalan** (belum ada hasil DAN worker hidup).
     *
     * `pid <= 0` = worker belum melaporkan pid (baru di-spawn) — dianggap masih
     * hidup. Ini bukan kelonggaran asal: `posix_kill(0, …)` menandai SELURUH
     * process group (bukan satu proses), jadi pid 0 memang tidak boleh diperiksa.
     * Run yang worker-nya sudah mati (pid valid tapi tidak hidup) mengembalikan
     * `false` → pemanggil (`TemplatePullService::panel()`) menutupnya sebagai
     * `error` supaya UI tidak macet di status "berjalan".
     */
    public function isRunning(?array $run = null): bool
    {
        $run ??= $this->run();
        if ($run['id'] === null || $run['result'] !== null) {
            return false;
        }

        $pid = (int) $run['pid'];
        if ($pid <= 0) {
            return true;
        }

        if (!function_exists('posix_kill')) {
            return true;
        }

        return @posix_kill($pid, 0);
    }

    /**
     * Buang status + log run (dipakai test/housekeeping; bukan jalur UI).
     */
    public function clear(): void
    {
        if (is_file($this->runFile)) {
            @unlink($this->runFile);
        }
        foreach (glob(rtrim($this->runDir, '/') . '/*.log') ?: [] as $path) {
            @unlink($path);
        }
    }

    // ==================================================================
    // Log
    // ==================================================================

    /**
     * Path berkas log yang aman (nama divalidasi + dikurung di run_dir);
     * `null` bila nama ilegal atau berkas belum ada.
     */
    public function logPath(string $name): ?string
    {
        $safe = $this->sanitizeLogName($name);
        if ($safe === null) {
            return null;
        }
        $path = rtrim($this->runDir, '/') . '/' . $safe;

        return is_file($path) ? $path : null;
    }

    /**
     * Tambahkan keluaran mentah perintah ke berkas log (dibuat bila belum ada).
     */
    public function appendLog(string $name, string $chunk): void
    {
        $safe = $this->sanitizeLogName($name);
        if ($chunk === '' || $safe === null) {
            return;
        }
        $dir = rtrim($this->runDir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori log tarik image: ' . $dir);
        }
        if (@file_put_contents($dir . '/' . $safe, $chunk, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis log tarik image: ' . $dir . '/' . $safe);
        }
    }

    /**
     * Ekor berkas log (dibatasi ukuran) — untuk panel UI.
     */
    public function logTail(string $name, int $maxBytes = 20000): string
    {
        $path = $this->logPath($name);
        if ($path === null) {
            return '';
        }
        $size = @filesize($path);
        if ($size === false) {
            return '';
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }
        try {
            $offset = $size > $maxBytes ? $size - $maxBytes : 0;
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $content = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return $content;
    }

    /**
     * Daftar berkas log (terbaru dulu).
     *
     * @return array<int,array{name:string,size:int,modified_at:string}>
     */
    public function logs(int $limit = 20): array
    {
        if (!is_dir($this->runDir)) {
            return [];
        }

        $files = [];
        foreach (glob(rtrim($this->runDir, '/') . '/*.log') ?: [] as $path) {
            $name = $this->sanitizeLogName(basename($path));
            if ($name === null) {
                continue;
            }
            $size = @filesize($path);
            $mtime = @filemtime($path);
            $files[] = [
                'name' => $name,
                'size' => $size === false ? 0 : (int) $size,
                'modified_at' => $mtime === false ? '' : date('c', (int) $mtime),
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return array_slice($files, 0, max(1, $limit));
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Nama berkas log yang aman (`basename` + whitelist pola); `null` bila tidak
     * sah. Semua jalur tulis/baca memakai hasil fungsi ini sehingga `../` tidak
     * pernah bisa dipakai keluar dari run_dir.
     */
    private function sanitizeLogName(string $name): ?string
    {
        $name = basename(trim($name));

        return $name !== '' && preg_match(self::LOG_PATTERN, $name) === 1 ? $name : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(): array
    {
        if (!is_file($this->runFile)) {
            return [];
        }
        $raw = @file_get_contents($this->runFile);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Tulis JSON dengan pola tmp + `rename` + `LOCK_EX` (pembaca tidak pernah
     * melihat berkas setengah jadi).
     */
    private function writeJson(array $data): void
    {
        $dir = rtrim($this->runDir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori status tarik image: ' . $dir);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal meng-encode status tarik image.');
        }

        $tmp = $this->runFile . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis status tarik image: ' . $tmp);
        }
        if (!@rename($tmp, $this->runFile)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal mengganti status tarik image: ' . $this->runFile);
        }
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeImages(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $images = [];
        foreach ($raw as $ref) {
            if (!is_scalar($ref)) {
                continue;
            }
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $images[] = $ref;
            }
        }

        return $images;
    }

    /**
     * @return array<int,array{image:string,ok:bool,seconds:float,message:string}>
     */
    private static function normalizeResults(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $results = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $results[] = [
                'image' => (string) ($entry['image'] ?? ''),
                'ok' => (bool) ($entry['ok'] ?? false),
                'seconds' => (float) ($entry['seconds'] ?? 0),
                'message' => (string) ($entry['message'] ?? ''),
            ];
        }

        return $results;
    }
}
