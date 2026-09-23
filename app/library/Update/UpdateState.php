<?php
declare(strict_types=1);

namespace app\library\Update;

use app\library\Storage\JsonStore;
use RuntimeException;

/**
 * Status fitur self-update dashboard (SPECS.md §7.8 / ARCHITECTURE.md §5.14).
 *
 * Dua berkas, **satu penulis masing-masing** — disengaja agar tidak ada perebutan
 * kepemilikan file antara dashboard (root di dalam container) dan helper container
 * (uid pemilik repo, lihat `cli/self-update.sh`):
 *
 *  1. `runtime/update/check.json` — hasil cek pembaruan. Ditulis HANYA oleh PHP
 *     (`UpdateChecker`); dibaca helper bila perlu.
 *  2. `runtime/logs/update/run.json` — status update yang sedang/terakhir berjalan.
 *     Ditulis HANYA oleh helper (lewat `cli/update-report.php`); dibaca PHP/UI.
 *
 * Dashboard tetap boleh menulis `run.json` untuk menutup run yang ditinggalkan
 * helper (mis. container helper mati) — setelah itu kepemilikan file dikembalikan
 * ke uid pemilik repo agar helper berikutnya tetap bisa menulis.
 */
final class UpdateState
{
    /** Nama berkas status update (di dalam run_dir, ditulis helper). */
    public const RUN_FILE = 'run.json';

    /** Pola nama berkas log update (aman untuk dijadikan input endpoint). */
    public const LOG_PATTERN = '/^[A-Za-z0-9._-]+\.log$/';

    private JsonStore $checkStore;
    private string $runFile;

    public function __construct(
        private readonly string $checkFile,
        private readonly string $runDir,
    ) {
        $this->checkStore = new JsonStore($this->checkFile);
        $this->runFile = rtrim($this->runDir, '/') . '/' . self::RUN_FILE;
    }

    public function checkFile(): string
    {
        return $this->checkFile;
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
    // Cek pembaruan (ditulis PHP)
    // ==================================================================

    /**
     * Hasil cek terakhir (tanpa jaringan). Selalu berbentuk lengkap.
     *
     * @return array{checked_at:?string,ok:bool,error:?string,branch:string,local_sha:?string,remote_sha:?string,update_available:bool,compare_url:?string,head:?array,untracked:array,tracked_changes:array}
     */
    public function check(): array
    {
        $data = [];
        try {
            $data = $this->checkStore->read();
        } catch (RuntimeException) {
            // berkas korup → dianggap belum pernah dicek
            $data = [];
        }

        return [
            'checked_at' => isset($data['checked_at']) ? (string) $data['checked_at'] : null,
            'ok' => (bool) ($data['ok'] ?? false),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
            'branch' => (string) ($data['branch'] ?? ''),
            'local_sha' => isset($data['local_sha']) ? (string) $data['local_sha'] : null,
            'remote_sha' => isset($data['remote_sha']) ? (string) $data['remote_sha'] : null,
            'update_available' => (bool) ($data['update_available'] ?? false),
            'compare_url' => isset($data['compare_url']) ? (string) $data['compare_url'] : null,
            'head' => is_array($data['head'] ?? null) ? $data['head'] : null,
            'untracked' => is_array($data['untracked'] ?? null) ? array_values($data['untracked']) : [],
            'tracked_changes' => is_array($data['tracked_changes'] ?? null) ? array_values($data['tracked_changes']) : [],
        ];
    }

    public function writeCheck(array $data): void
    {
        $this->checkStore->write($data);
    }

    // ==================================================================
    // Status update (ditulis helper)
    // ==================================================================

    /**
     * Status update terakhir/sedang berjalan.
     *
     * @return array{id:?string,mode:string,stage:string,message:string,result:?string,error:?string,started_at:?string,finished_at:?string,old_sha:?string,target_sha:?string,actor:string,log:?string,health_url:?string,rollback_from:?string}
     */
    public function run(): array
    {
        $data = $this->readJson($this->runFile);

        return [
            'id' => isset($data['id']) ? (string) $data['id'] : null,
            'mode' => (string) ($data['mode'] ?? 'update'),
            'stage' => (string) ($data['stage'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'result' => isset($data['result']) && $data['result'] !== null ? (string) $data['result'] : null,
            'error' => isset($data['error']) && $data['error'] !== null ? (string) $data['error'] : null,
            'started_at' => isset($data['started_at']) ? (string) $data['started_at'] : null,
            'finished_at' => isset($data['finished_at']) ? (string) $data['finished_at'] : null,
            'old_sha' => isset($data['old_sha']) ? (string) $data['old_sha'] : null,
            'target_sha' => isset($data['target_sha']) ? (string) $data['target_sha'] : null,
            'actor' => (string) ($data['actor'] ?? ''),
            'log' => isset($data['log']) ? (string) $data['log'] : null,
            'health_url' => isset($data['health_url']) ? (string) $data['health_url'] : null,
            'rollback_from' => isset($data['rollback_from']) ? (string) $data['rollback_from'] : null,
        ];
    }

    /**
     * Tulis status awal (sebelum helper dijalankan) supaya UI langsung menampilkan
     * progres — helper akan menimpa berkas ini saat mulai bekerja.
     */
    public function startRun(array $run, int $repoUid = 0, int $repoGid = 0): void
    {
        $this->prepareRunDir($repoUid, $repoGid);
        $this->writeJson($this->runFile, $run);
        $this->handOverToHelper($repoUid, $repoGid);
    }

    /**
     * Tutup run yang tidak pernah dilaporkan helper (mis. helper mati mendadak).
     */
    public function finalize(string $result, string $message, int $repoUid = 0, int $repoGid = 0): void
    {
        $run = $this->run();
        if ($run['id'] === null || $run['finished_at'] !== null) {
            return;
        }
        $run['result'] = $result;
        $run['stage'] = 'finished';
        $run['message'] = $message;
        $run['finished_at'] = date('c');
        $run['error'] = $result === 'error' ? $message : $run['error'];
        $this->prepareRunDir($repoUid, $repoGid);
        $this->writeJson($this->runFile, $run);
        $this->handOverToHelper($repoUid, $repoGid);
    }

    /**
     * Run dianggap sedang berjalan bila belum ada hasil & belum ada `finished_at`.
     */
    public function isRunning(array $run): bool
    {
        return $run['id'] !== null && $run['result'] === null && $run['finished_at'] === null;
    }

    // ==================================================================
    // Log update
    // ==================================================================

    /**
     * Daftar berkas log update (terbaru dulu) — dipakai sebagai riwayat.
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
            $name = basename($path);
            if (preg_match(self::LOG_PATTERN, $name) !== 1) {
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

    /**
     * Ekor berkas log (dibatasi ukuran) — nama divalidasi & dikurung di run_dir
     * agar tidak bisa dipakai membaca file sembarang.
     */
    public function logTail(string $name, int $maxBytes = 20000): string
    {
        $path = $this->resolveLogPath($name);
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

    public function resolveLogPath(string $name): ?string
    {
        $name = basename(trim($name));
        if ($name === '' || preg_match(self::LOG_PATTERN, $name) !== 1) {
            return null;
        }
        $path = rtrim($this->runDir, '/') . '/' . $name;
        if (!is_file($path)) {
            return null;
        }

        return $path;
    }

    // ==================================================================
    // Direktori & kepemilikan
    // ==================================================================

    /**
     * Pastikan direktori status/log ada dan BISA DITULIS helper (uid pemilik repo)
     * sekaligus dashboard (root). Dipanggil sebelum spawn helper & sebelum
     * dashboard menulis run.json.
     */
    public function prepareRunDir(int $repoUid = 0, int $repoGid = 0): void
    {
        $dir = rtrim($this->runDir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori status update: ' . $dir);
        }
        $this->handOverToHelper($repoUid, $repoGid);
    }

    /**
     * Serahkan kepemilikan berkas/direktori status ke uid pemilik repo supaya
     * helper (yang berjalan sebagai user itu) bisa menulis. No-op bila dashboard
     * tidak berjalan sebagai root atau uid tidak diketahui.
     */
    private function handOverToHelper(int $repoUid, int $repoGid): void
    {
        if ($repoUid <= 0 || !function_exists('posix_getuid') || posix_getuid() !== 0) {
            return;
        }
        $dir = rtrim($this->runDir, '/');
        if (is_dir($dir)) {
            @chown($dir, $repoUid);
            if ($repoGid > 0) {
                @chgrp($dir, $repoGid);
            }
        }
        if (is_file($this->runFile)) {
            @chown($this->runFile, $repoUid);
            if ($repoGid > 0) {
                @chgrp($this->runFile, $repoGid);
            }
        }
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Tulis JSON dengan pola tmp+rename (penulis tunggal per berkas, jadi tidak
     * butuh flock; rename menjamin pembaca tidak melihat berkas setengah jadi).
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori: ' . $dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal meng-encode status update.');
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis status update: ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal mengganti status update: ' . $path);
        }
    }
}
