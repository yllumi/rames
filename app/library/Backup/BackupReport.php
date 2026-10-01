<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Storage\JsonStore;
use RuntimeException;

/**
 * Laporan status & riwayat run backup (PLAN_VOLUME_BACKUP.md §4.4, §5.3).
 *
 * Berkas (semua di bawah `runtime/backup/`, gitignored):
 *  - `status.json`      — status run terakhir (dibaca UI untuk polling);
 *  - `runs/{ISO8601}.json` — riwayat run (retensi N terakhir).
 *
 * Mutasi lewat `JsonStore` (atomic + `flock` + backup `.bak`) — **bukan**
 * `file_put_contents` langsung.
 *
 * **Hanya metadata** yang disimpan (nama volume, project, app, strategi, status,
 * snapshot id, waktu). Sebagai pertahanan berlapis, `writeStatus()`/`appendRun()`
 * menyaring kunci lewat daftar-putih + meredaksi pola `KEY=VALUE` yang menyerupai
 * kredensial (`PASSWORD`/`SECRET`/`TOKEN`/`ACCESS_KEY`/…) sehingga kebocoran
 * tidak bisa terjadi karena kelalaian pemanggil.
 */
class BackupReport
{
    /** Retensi default riwayat run. */
    public const DEFAULT_KEEP_RUNS = 30;

    /** Kunci yang boleh tersimpan di status.json / runs/*.json. */
    private const RUN_KEYS = [
        'run_id', 'trigger', 'host', 'status', 'running',
        'started_at', 'finished_at', 'duration_ms',
        'totals', 'summary', 'volumes', 'error',
    ];

    /** Kunci yang boleh tersimpan per volume. */
    private const VOLUME_KEYS = [
        'name', 'project', 'app_id', 'app_name', 'orphaned',
        'strategy', 'status', 'snapshot_id',
        'started_at', 'finished_at', 'duration_ms', 'bytes', 'error',
    ];

    private string $dir;
    private int $keepRuns;

    public function __construct(?string $dir = null, int $keepRuns = self::DEFAULT_KEEP_RUNS)
    {
        $this->dir = $dir ?? (runtime_path() . '/backup');
        $this->keepRuns = max(1, $keepRuns);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function statusPath(): string
    {
        return $this->dir . '/status.json';
    }

    public function runsDir(): string
    {
        return $this->dir . '/runs';
    }

    /**
     * Status run terakhir (array kosong bila belum pernah ada run).
     *
     * @return array
     */
    public function readStatus(): array
    {
        return (new JsonStore($this->statusPath()))->read();
    }

    /**
     * Tulis status run terakhir (atomic, disaring & diredaksi).
     *
     * @param array $status
     */
    public function writeStatus(array $status): void
    {
        (new JsonStore($this->statusPath()))->write($this->sanitize($status));
    }

    /**
     * Simpan satu run ke riwayat, lalu pertahankan hanya `keepRuns` terakhir.
     *
     * @param array $run
     * @return string path berkas run
     */
    public function appendRun(array $run): string
    {
        $runsDir = $this->runsDir();
        if (!is_dir($runsDir) && !@mkdir($runsDir, 0775, true) && !is_dir($runsDir)) {
            throw new RuntimeException("Tidak bisa membuat direktori riwayat run: {$runsDir}.");
        }

        $name = self::fileName((string) ($run['started_at'] ?? ''));
        $path = $runsDir . '/' . $name;
        if (is_file($path)) {
            $path = $runsDir . '/' . substr($name, 0, -5) . '-' . bin2hex(random_bytes(3)) . '.json';
        }

        (new JsonStore($path))->write($this->sanitize($run));
        $this->prune();

        return $path;
    }

    /**
     * Riwayat run terbaru lebih dulu.
     *
     * @return array<int,array{file:string,stored_at:?int,data:array}>
     */
    public function listRuns(): array
    {
        $rows = [];
        foreach (glob($this->runsDir() . '/*.json') ?: [] as $file) {
            if (!is_file($file) || str_ends_with($file, '.bak')) {
                continue;
            }
            try {
                $data = (new JsonStore($file))->read();
            } catch (\Throwable $e) {
                continue; // berkas rusak tidak boleh menggagalkan seluruh daftar
            }
            $rows[] = ['file' => $file, 'stored_at' => filemtime($file) ?: null, 'data' => $data];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp(basename((string) $b['file']), basename((string) $a['file']));
        });

        return $rows;
    }

    /**
     * Buang run lama sehingga hanya `keepRuns` terbaru yang tersisa.
     */
    public function prune(): void
    {
        $files = [];
        foreach (glob($this->runsDir() . '/*.json') ?: [] as $file) {
            if (is_file($file) && !str_ends_with($file, '.bak')) {
                $files[] = $file;
            }
        }
        if (count($files) <= $this->keepRuns) {
            return;
        }

        // nama berkas = ISO8601 → urutan leksikografis ≈ kronologis
        sort($files, SORT_STRING);
        $excess = count($files) - $this->keepRuns;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
            @unlink($files[$i] . '.bak');
        }
    }

    // ==================================================================
    // Penyaringan & redaksi (pertahanan berlapis: tanpa kredensial)
    // ==================================================================

    /**
     * Saring ke daftar-putih kunci + redaksi nilai yang menyerupai kredensial.
     *
     * @param array $data
     * @return array
     */
    private function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !in_array($key, self::RUN_KEYS, true)) {
                continue;
            }
            $clean[$key] = match ($key) {
                'volumes' => $this->sanitizeVolumes($value),
                'summary', 'totals' => $this->sanitizeScalarMap($value),
                default => $this->redact($value),
            };
        }
        return $clean;
    }

    /**
     * @param mixed $volumes
     * @return array<int,array>
     */
    private function sanitizeVolumes(mixed $volumes): array
    {
        if (!is_array($volumes)) {
            return [];
        }
        $rows = [];
        foreach ($volumes as $volume) {
            if (!is_array($volume)) {
                continue;
            }
            $row = [];
            foreach ($volume as $key => $value) {
                if (is_string($key) && in_array($key, self::VOLUME_KEYS, true)) {
                    $row[$key] = $this->redact($value);
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Peta datar (mis. jumlah per status) — nilai non-skalar dibuang.
     *
     * @param mixed $map
     * @return array<string,int|float|string|bool|null>
     */
    private function sanitizeScalarMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }
        $clean = [];
        foreach ($map as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $clean[$key] = is_scalar($value) || $value === null ? $this->redact($value) : null;
        }
        return $clean;
    }

    /**
     * Redaksi nilai string; array ditelusuri rekursif.
     */
    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = $this->redact($item);
            }
            return $clean;
        }
        if (!is_string($value)) {
            return $value;
        }

        return preg_replace(
            '/([A-Za-z_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|ACCESS_KEY|CREDENTIAL)[A-Za-z_]*\s*[=:]\s*)(\S+)/i',
            '$1***',
            $value
        ) ?? $value;
    }

    /**
     * Nama berkas aman dari waktu ISO8601 (`2026-09-30T02:30:00+07:00` →
     * `2026-09-30T02-30-00-07-00.json`).
     */
    private static function fileName(string $iso): string
    {
        $iso = $iso !== '' ? $iso : date('c');
        $safe = preg_replace('/[^0-9A-Za-z._-]/', '-', $iso) ?? $iso;
        $safe = trim($safe, '-');
        if ($safe === '') {
            $safe = 'run-' . bin2hex(random_bytes(4));
        }
        return $safe . '.json';
    }
}
