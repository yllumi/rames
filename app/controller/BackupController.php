<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Backup\BackupAccess;
use app\library\Backup\BackupReport;
use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeStateGuard;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Halaman & API backup volume ke S3 via restic (PLAN_VOLUME_BACKUP.md §4.1).
 *
 * Controller hanya **mediator**: validasi input, cek otorisasi (satu pintu —
 * `BackupAccess`), delegasi ke library, kembalikan respons. Tanpa logika bisnis
 * dan tanpa state (worker Webman persistent, `controller_reuse=false`).
 *
 * Kontrak JSON (kunci stabil — dipakai Frontend UI):
 *   GET  /api/backups/status    → {code:0,data:{running,status,volumes:[…]}}
 *   GET  /api/backups/snapshots → {code:0,data:{volume,snapshots:[…]}}
 *   POST /backups/run           → {code:0,msg}
 *   POST /backups/restore       → {code:0,msg}
 * Error akses → 404 (via `AppAccessDenied`), bukan 403.
 */
class BackupController
{
    /**
     * Halaman /backups. Data volume diambil via AJAX (`status()`) agar halaman
     * tidak menunggu Engine/restic.
     */
    public function index(Request $request)
    {
        return view('backup/index', [
            'enabled' => (bool) config('deploy.volume_backup_enabled', true),
            'policy' => (string) config('deploy.volume_backup_snapshot_policy', 'stop'),
            'isAdmin' => is_admin(),
        ]);
    }

    /**
     * Ringkasan status run + volume yang boleh dilihat user.
     */
    public function status(Request $request)
    {
        $service = new VolumeBackupService();

        try {
            $targets = $service->targets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        $apps = (new AppStore())->all();
        $visible = BackupAccess::visible($targets, $apps, current_user());
        $status = (new BackupReport())->readStatus();

        try {
            $volumes = $service->overview($visible, $status);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal menyusun status backup: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => [
            'running' => $service->isRunning(),
            // `status` selalu objek (bukan array kosong) agar kunci stabil di JS.
            'status' => $status === [] ? new \stdClass() : $status,
            'volumes' => $volumes,
        ]]);
    }

    /**
     * Daftar snapshot restic sebuah volume.
     */
    public function snapshots(Request $request)
    {
        $volume = trim((string) $request->get('volume', ''));
        try {
            VolumeStateGuard::assertVolumeName($volume);
        } catch (\Throwable $e) {
            return $this->notFound();
        }

        $service = new VolumeBackupService();
        try {
            $targets = $service->targets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        $target = $this->findTarget($targets, $volume);
        if ($target === null) {
            return $this->notFound();
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('view', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), current_user());

        try {
            $snapshots = $service->snapshotsFor($volume);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal membaca snapshot: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => ['volume' => $volume, 'snapshots' => $snapshots]]);
    }

    /**
     * Jalankan backup satu volume (worker detached, ability `backup`).
     */
    public function run(Request $request)
    {
        $volume = trim((string) $request->post('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        $service = new VolumeBackupService();
        try {
            $targets = $service->targets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        $target = $this->findTarget($targets, $volume);
        if ($target === null) {
            return $this->notFound();
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('backup', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), current_user());

        if (!$this->spawnWorker(['run', $volume, 'manual'])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker backup.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Backup dijalankan.']);
    }

    /**
     * Pulihkan satu volume dari snapshot (worker detached, ability `restore`;
     * konfirmasi = nama volume).
     */
    public function restore(Request $request)
    {
        $volume = trim((string) $request->post('volume', ''));
        $snapshot = trim((string) $request->post('snapshot', ''));
        $confirm = trim((string) $request->post('confirm', ''));

        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }
        try {
            ResticRunner::assertSnapshotId($snapshot);
        } catch (\Throwable $e) {
            return json(['code' => 422, 'msg' => 'Id snapshot tidak valid.'])->withStatus(422);
        }
        if ($confirm !== $volume) {
            return json(['code' => 422, 'msg' => 'Konfirmasi tidak cocok — ketik nama volume persis.'])->withStatus(422);
        }

        $service = new VolumeBackupService();
        try {
            $targets = $service->targets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        $target = $this->findTarget($targets, $volume);
        if ($target === null) {
            return $this->notFound();
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('restore', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), current_user());

        // Volume yatim tidak punya appId → worker menerima '-' dan memetakan
        // project dari label volume (hanya admin yang bisa sampai di sini).
        $appArg = ($target['app_id'] !== null && $target['app_id'] !== '') ? (string) $target['app_id'] : '-';
        if (!$this->spawnWorker(['restore', $appArg, $volume, $snapshot])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker restore.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Restore dijalankan.']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<int,array{name:string}> $targets
     * @return array|null
     */
    private function findTarget(array $targets, string $volume): ?array
    {
        foreach ($targets as $target) {
            if ((string) ($target['name'] ?? '') === $volume) {
                return $target;
            }
        }
        return null;
    }

    /**
     * @param array<int,array> $apps
     * @return array|null
     */
    private function findApp(array $apps, string $appId): ?array
    {
        if ($appId === '') {
            return null;
        }
        foreach ($apps as $app) {
            if ((string) ($app['id'] ?? '') === $appId) {
                return $app;
            }
        }
        return null;
    }

    private function validVolume(string $volume): bool
    {
        try {
            VolumeStateGuard::assertVolumeName($volume);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function notFound()
    {
        return json(['code' => 404, 'msg' => 'Not Found'])->withStatus(404);
    }

    /**
     * Spawn worker CLI detached (non-blocking) — pola sama dengan
     * `AppController::spawnWorker()`: `pcntl_fork` + `pcntl_exec` (tanpa shell).
     * `proc_open`/`proc_close` BLOCKING sampai worker selesai sehingga tidak
     * dipakai untuk run backup/restore yang bisa berjalan lama.
     *
     * @param array<int,string> $args argumen setelah `cli/backup.php`
     */
    private function spawnWorker(array $args): bool
    {
        if (!function_exists('pcntl_fork')) {
            return false;
        }

        $command = array_merge([PHP_BINARY, base_path('cli/backup.php')], $args);

        // Otomatis-reap anak saat selesai supaya tidak menumpuk zombie.
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $pid = @pcntl_fork();
        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            // Proses anak: lepas dari sesi, ganti stdio ke /dev/null, lalu exec.
            @posix_setsid();
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            // Variabel dipertahankan sampai pcntl_exec agar fd tetap terbuka.
            $nullIn = @fopen('/dev/null', 'r');
            $nullOut = @fopen('/dev/null', 'w');
            $nullErr = @fopen('/dev/null', 'w');
            // SIGCHLD=SIG_DFL sebelum exec agar worker bisa membaca exit code
            // proses anaknya (docker/restic) — lihat SigchldGuard.
            @pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_exec(PHP_BINARY, array_slice($command, 1));
            exit(127);
        }

        return true;
    }
}
