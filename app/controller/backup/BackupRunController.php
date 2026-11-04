<?php
declare(strict_types=1);

namespace app\controller\backup;

use app\library\Backup\BackupAccess;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\ResticRunner;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Jalankan backup & restore satu volume (worker detached) — `POST /backups/run`
 * dan `POST /backups/restore`.
 *
 * Multi-server F4: `server_id` (opsional, dari baris volume) memilih server
 * pemilik volume. Worker `cli/backup.php` di control plane meneruskan pekerjaan
 * ke **server agent** bila `server_id` bukan server ini (`BackupDispatcher`) —
 * host ini tidak pernah menjalankan restic untuk volume server agent.
 *
 * Kontrak JSON: `{code:0,msg}` sukses; 422/500 pada validasi/otorisasi/spawn.
 */
class BackupRunController extends BackupControllerBase
{
    /**
     * Jalankan backup satu volume (worker detached, ability `backup`).
     *
     * Multi-server F4: `server_id` (opsional, dari baris volume) memilih server
     * pemilik volume. Worker `cli/backup.php` di control plane meneruskan
     * pekerjaan ke **server agent** bila `server_id` bukan server ini
     * (`BackupDispatcher`) — host ini tidak pernah menjalankan restic untuk
     * volume server agent.
     */
    public function run(Request $request)
    {
        $volume = trim((string) $request->post('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        try {
            $targets = $this->volumeTargets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        try {
            $target = $this->findTarget($targets, $volume, trim((string) $request->post('server_id', '')));
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }
        if ($target === null) {
            return $this->notFound($request);
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('backup', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $serverId = self::serverIdOf($target);
        try {
            $this->assertAgentSupports($serverId, 'backup_run');
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }

        if (!$this->spawnWorker(['run', $volume, 'manual', $serverId])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker backup.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Backup dijalankan.']);
    }

    /**
     * Pulihkan satu volume dari snapshot (worker detached, ability `restore`;
     * konfirmasi = nama volume).
     *
     * Multi-server F4: sama seperti {@see self::run()} — `server_id` menentukan
     * server pemilik volume; restore volume server agent dieksekusi **di server
     * itu** (job `restore`), termasuk validasi kepemilikan volume oleh worker di
     * sana.
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

        try {
            $targets = $this->volumeTargets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        try {
            $target = $this->findTarget($targets, $volume, trim((string) $request->post('server_id', '')));
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }
        if ($target === null) {
            return $this->notFound($request);
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('restore', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $serverId = self::serverIdOf($target);
        try {
            $this->assertAgentSupports($serverId, 'restore');
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }

        // Volume yatim tidak punya appId → worker menerima '-' dan memetakan
        // project dari label volume (hanya admin yang bisa sampai di sini).
        $appArg = ($target['app_id'] !== null && $target['app_id'] !== '') ? (string) $target['app_id'] : '-';
        if (!$this->spawnWorker(['restore', $appArg, $volume, $snapshot, $serverId])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker restore.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Restore dijalankan.']);
    }
}
