<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Storage\AppStore;

/**
 * Satu-satunya jalur "spawn worker deploy + catat PID"-nya.
 *
 * Mekanisme spawn sendiri milik {@see DeployWorkerLauncher} (`pcntl_fork` +
 * `pcntl_exec` + `SigchldGuard`, lihat docblock kelas itu). Kelas ini hanya
 * menambahkan pencatatan `worker_pid`/`worker_mode`/`worker_started_at` ke
 * dokumen app supaya deploy bisa dibatalkan {@see DeployCanceller}.
 *
 * Dipakai `AppControllerBase::spawnWorker()` (dashboard) **dan**
 * {@see AppBuildService} (jalur API) — jangan menyalin pencatatan PID ke
 * pemanggil baru.
 */
final class DeploySpawner
{
    private readonly ?DeployWorkerLauncher $launcher;
    private readonly ?AppStore $store;

    /**
     * @param DeployWorkerLauncher|null $launcher seam uji: launcher dengan spawn palsu
     * @param AppStore|null             $store    seam uji: store app temp
     */
    public function __construct(?DeployWorkerLauncher $launcher = null, ?AppStore $store = null)
    {
        $this->launcher = $launcher;
        $this->store = $store;
    }

    /**
     * Spawn worker untuk `$mode` (lihat `DeployWorkerLauncher::MODES`).
     *
     * @param string      $appId   id app (bukan nama/slug)
     * @param string      $mode    mode `cli/deploy.php`
     * @param string|null $arg     ref rollback (mode lain: null)
     * @param string      $actorId id user aktor (`argv[4]`), '' bila tak ada
     */
    public function spawn(string $appId, string $mode, ?string $arg = null, string $actorId = ''): bool
    {
        $pid = 0;
        $ok = ($this->launcher ?? new DeployWorkerLauncher())->launch($appId, $mode, $arg, $actorId, '', $pid);

        // Best-effort: kegagalan mencatat PID tidak boleh menggagalkan deploy —
        // canceller masih bisa menemukan worker lewat pemindaian `/proc`.
        if ($ok && $pid > 0) {
            try {
                ($this->store ?? new AppStore())->update($appId, static function (array &$s) use ($pid, $mode): void {
                    $s['worker_pid'] = $pid;
                    $s['worker_mode'] = $mode;
                    $s['worker_started_at'] = date('c');
                });
            } catch (\Throwable) {
                // abaikan
            }
        }

        return $ok;
    }
}
