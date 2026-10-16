<?php
declare(strict_types=1);

namespace app\library\Template;

use app\library\Support\DetachedWorker;
use RuntimeException;

/**
 * Orkestrasi "tarik image template" — tombol `docker pull` di halaman Config
 * (`/nginx`, tab Template; SPECS.md §7.13).
 *
 * Masalah nyata: template memakai image prebuilt (`yllumi/fpm8.4-nginx`,
 * `mysql:8.4`, …). Ketika tag di registry diperbarui, `docker compose up -d`
 * default `--pull missing` **tidak** menariknya (tag `:latest` lokal yang basi
 * terus dipakai) — dashboard tidak punya jalur apa pun untuk memaksa pull.
 * Fitur ini menambahkannya: pilih template → tarik SEMUA image-nya sekaligus.
 *
 * Alur: `start()` = preflight → tulis kerangka status (`TemplatePullState`) →
 * spawn worker `cli/pull.php` detached (`DetachedWorker`, pola sama dengan
 * `AppController::spawnWorker()`) → request HTTP langsung kembali; progres
 * dipantau `panel()`. Controller hanya mediator — logika ada di sini.
 *
 * Worker di-spawn **tanpa env tambahan** ⇒ `DetachedWorker` mewariskan seluruh
 * lingkungan proses pemanggil (termasuk `PATH`/`HOME` yang dibutuhkan
 * `docker pull`, dan `RAMES_DB_DIR` di jalur agent) — jangan menambahkan env
 * kosong/parsial di sini (lihat catatan `envp` di `DetachedWorker`).
 *
 * Stateless (tanpa cache di properti): aman untuk worker Webman persistent.
 */
final class TemplatePullService
{
    /** Nama default worker CLI (di repo). */
    public const WORKER_SCRIPT = 'cli/pull.php';

    private string $workerScript;

    /** @var callable(array<int,string>, ?int&):bool */
    private $spawn;

    /**
     * @param callable|null $spawn        seam uji: pengganti `DetachedWorker::spawn()`
     *                                    (dipanggil `($spawn)($argv, $pid)` dan
     *                                    mengembalikan bool; boleh hanya menerima
     *                                    satu argumen).
     * @param string        $workerScript seam uji: path worker (default `cli/pull.php`).
     */
    public function __construct(
        private readonly TemplateCatalog $catalog,
        private readonly TemplatePullState $state,
        ?callable $spawn = null,
        string $workerScript = '',
    ) {
        $this->spawn = $spawn ?? static fn (array $argv, ?int &$pid = null): bool => DetachedWorker::spawn($argv, $pid);
        $this->workerScript = $workerScript !== '' ? $workerScript : base_path(self::WORKER_SCRIPT);
    }

    public function state(): TemplatePullState
    {
        return $this->state;
    }

    public function workerScript(): string
    {
        return $this->workerScript;
    }

    // ==================================================================
    // Preflight (tanpa efek samping)
    // ==================================================================

    /**
     * Prasyarat menjalankan tarik image — gagal cepat dengan pesan yang bisa
     * langsung ditampilkan UI, bukan gagal di tengah proses.
     *
     * @return array{ok:bool,error:?string,images:array<int,string>}
     */
    public function preflight(string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return ['ok' => false, 'error' => 'Slug template tidak boleh kosong.', 'images' => []];
        }

        $template = $this->catalog->find($slug);
        if ($template === null) {
            return ['ok' => false, 'error' => 'Template "' . $slug . '" tidak ditemukan.', 'images' => []];
        }
        if (!$template['valid']) {
            return [
                'ok' => false,
                'error' => 'Template "' . $slug . '" tidak valid: ' . (string) $template['error'],
                'images' => [],
            ];
        }

        $images = TemplateCatalog::images($template);
        if ($images === []) {
            return [
                'ok' => false,
                'error' => 'Template "' . $slug . '" tidak punya image prebuilt untuk ditarik.',
                'images' => [],
            ];
        }

        if ($this->state->isRunning($this->state->run())) {
            return [
                'ok' => false,
                'error' => 'Masih ada proses tarik image yang berjalan — tunggu sampai selesai.',
                'images' => [],
            ];
        }

        return ['ok' => true, 'error' => null, 'images' => $images];
    }

    // ==================================================================
    // Menjalankan tarik image
    // ==================================================================

    /**
     * Tulis status awal lalu spawn worker — detached, request langsung kembali
     * dan progres dipantau lewat `GET /nginx/templates/pull/status`.
     *
     * @return array{id:string,images:array<int,string>}
     * @throws RuntimeException bila preflight gagal atau worker tidak bisa di-spawn
     */
    public function start(string $slug, string $actor): array
    {
        $slug = trim($slug);
        $preflight = $this->preflight($slug);
        if (!$preflight['ok']) {
            throw new RuntimeException((string) $preflight['error']);
        }

        $template = $this->catalog->find($slug) ?? [];
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $logName = $id . '.log';

        $this->state->startRun([
            'id' => $id,
            'slug' => $slug,
            'title' => (string) ($template['title'] ?? $slug),
            'stage' => 'starting',
            'message' => 'Menjalankan worker tarik image…',
            'result' => null,
            'error' => null,
            'started_at' => date('c'),
            'finished_at' => null,
            'actor' => $actor,
            'images' => $preflight['images'],
            'results' => [],
            'log' => $logName,
            'pid' => 0,
        ]);

        $argv = [PHP_BINARY, $this->workerScript, $slug, $actor];
        $pid = 0;

        try {
            $spawned = (bool) ($this->spawn)($argv, $pid);
        } catch (\Throwable $e) {
            $message = 'Gagal menjalankan worker tarik image: ' . $e->getMessage();
            $this->state->finalize('error', $message);
            throw new RuntimeException($message, 0, $e);
        }

        if (!$spawned) {
            $message = 'Gagal menjalankan worker tarik image (spawn gagal).';
            $this->state->finalize('error', $message);
            throw new RuntimeException($message);
        }

        // Pid anak dicatat supaya panel bisa membedakan "masih berjalan" dari
        // "worker mati tanpa laporan" (lihat `TemplatePullState::isRunning()`).
        if ($pid > 0) {
            $this->state->update(['pid' => $pid]);
        }

        return ['id' => $id, 'images' => $preflight['images']];
    }

    // ==================================================================
    // Ringkasan untuk UI
    // ==================================================================

    /**
     * Data siap-pakai untuk panel tab Template di halaman `/nginx`.
     *
     * @return array{enabled:bool,running:bool,run:array<string,mixed>,log_tail:string,logs:array<int,array<string,mixed>>}
     */
    public function panel(): array
    {
        $run = $this->state->run();
        $unfinished = $run['id'] !== null && $run['result'] === null;
        $running = $unfinished && $this->isWorkerRunning((string) $run['id']);

        // Run yang ditinggalkan worker (mis. worker mati mendadak) ditutup di sini
        // supaya UI tidak macet di status "sedang berjalan" — sama semangatnya
        // dengan `UpdateService::panel()`.
        if ($unfinished && !$running) {
            $this->state->finalize('error', 'Worker tarik image berhenti tanpa laporan — periksa log.');
            $run = $this->state->run();
            $running = false;
        }

        $logName = (string) ($run['log'] ?? '');

        return [
            'enabled' => $this->enabled(),
            'running' => $running,
            'run' => $run,
            'log_tail' => $logName !== '' ? $this->state->logTail($logName, 12000) : '',
            'logs' => $this->state->logs(10),
        ];
    }

    /**
     * Apakah worker untuk run `$id` masih hidup (pid-nya masih ada)?
     */
    public function isWorkerRunning(string $id): bool
    {
        $run = $this->state->run();
        if ($id === '' || (string) ($run['id'] ?? '') !== $id) {
            return false;
        }

        return $this->state->isRunning($run);
    }

    /**
     * Fitur bisa dipakai bila worker & katalog template ada.
     */
    private function enabled(): bool
    {
        return $this->workerScript !== ''
            && is_file($this->workerScript)
            && $this->catalog->root() !== ''
            && is_dir($this->catalog->root());
    }
}
