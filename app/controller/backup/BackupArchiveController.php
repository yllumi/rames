<?php
declare(strict_types=1);

namespace app\controller\backup;

use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupRegistry;
use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\DockerClient;
use app\library\Storage\DbBackup;
use app\library\Storage\ServerStore;
use support\Request;
use Webman\Http\Response;

/**
 * Arsip volume yang sudah dihapus (admin-only, PLAN §8h): daftar snapshot arsip,
 * restore ke volume **baru**, dan unduh berkas `.sql` dari snapshot strategi
 * `dump`.
 *
 * Non-admin ⇒ 404 (bukan 403 — satu pintu, keberadaan endpoint tidak bocor).
 * Multi-server F4: arsip volume server **agent** dibaca/dipulihkan **di server
 * itu**; control plane tidak membaca repo restic server agent secara langsung.
 */
class BackupArchiveController extends BackupControllerBase
{
    /**
     * Daftar snapshot restic sebuah volume **arsip** (admin-only, keputusan 4a).
     *
     * Berbeda dari `BackupOverviewController::snapshots()`, ia **tidak** membutuhkan volume di Docker
     * Engine: snapshot dibaca langsung dari repo restic via tag `volume:<nama>`,
     * sehingga volume yang sudah dihapus beserta app-nya tetap bisa dipulihkan.
     * Non-admin ⇒ 404 (bukan 403 — satu pintu, keberadaan endpoint tak bocor).
     */
    public function archivedSnapshots(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound($request);
        }

        $volume = trim((string) $request->get('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        // Volume server agent: repo restic-nya ada di server itu. Daftar snapshot
        // arsip volume agent dibaca lewat endpoint `snapshots` agent, bukan dari
        // repo control plane (yang bisa saja punya volume bernama sama).
        $serverId = trim((string) $request->get('server_id', ''));
        if (!BackupDispatcher::isLocalServer($serverId)) {
            return json([
                'code' => 422,
                'msg' => "Daftar snapshot arsip untuk server agent ({$serverId}) dibaca dari server itu "
                    . '(pakai `GET /api/backups/snapshots?volume=…&server_id=…`) — bukan dari repo control plane.',
            ])->withStatus(422);
        }

        try {
            $snapshots = (new VolumeBackupService())->snapshotsFor($volume);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal membaca snapshot: ' . $e->getMessage()])->withStatus(500);
        }

        return json(['code' => 0, 'data' => ['volume' => $volume, 'snapshots' => $snapshots]]);
    }

    /**
     * Pulihkan volume **arsip** (yang sudah dihapus) ke volume **baru** bernama
     * `target_name` (keputusan 1c). Admin-only ⇒ non-admin 404.
     *
     * Backend spawn worker detached `cli/backup.php restore-archived …` — worker
     * yang membuat volume target & menjalankan restic (lihat CLI). Controller
     * hanya memvalidasi & memutuskan kelayakan lewat satu sumber kebenaran
     * `VolumeRestoreService::planArchiveRestore()`.
     *
     * **Multi-server F4 lanjutan**: volume arsip milik server agent dipulihkan
     * **di server itu** — worker menerima `serverId` dan meneruskan pekerjaan ke
     * `AgentDispatcher`/`AgentDeployer` (job `restore_archived`): volume target
     * dibuat & restic dijalankan di sana, dengan `VolumeStateGuard` tetap
     * ditegakkan. Tidak ada jalur mundur ke control plane.
     */
    public function restoreArchived(Request $request)
    {
        // Seam `isAdmin()` (produksi identik dengan helper `is_admin()`) — satu
        // pola dengan endpoint admin lain di controller ini, sehingga routing
        // arsip dapat diuji tanpa sesi nyata.
        if (!$this->isAdmin()) {
            return $this->notFound($request);
        }

        $volume = trim((string) $request->post('volume', ''));
        $snapshot = trim((string) $request->post('snapshot', ''));
        $targetName = trim((string) $request->post('target_name', ''));

        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }
        try {
            ResticRunner::assertSnapshotId($snapshot);
        } catch (\Throwable $e) {
            return json(['code' => 422, 'msg' => 'Id snapshot tidak valid.'])->withStatus(422);
        }
        if (!$this->validVolume($targetName)) {
            return json(['code' => 422, 'msg' => 'Nama volume target tidak valid.'])->withStatus(422);
        }

        try {
            $registry = (new BackupRegistry())->read();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal membaca arsip backup: ' . $e->getMessage()])->withStatus(500);
        }

        // Server pemilik arsip: `server_id` eksplisit bila dikirim; selain itu
        // entri **lokal** (`<volume>`) lebih diutamakan, dan bila hanya ada entri
        // server agent yang volumenya cocok, entri itulah yang dipakai.
        $serverId = trim((string) $request->post('server_id', ''));
        $entry = $serverId !== ''
            ? ($registry[BackupRegistry::scopeKey($serverId, $volume)] ?? null)
            : ($registry[$volume] ?? null);

        if ($entry === null && $serverId === '') {
            foreach ($registry as $key => $candidate) {
                $key = (string) $key;
                if (BackupRegistry::volumeOfKey($key) !== $volume) {
                    continue;
                }
                $owner = BackupRegistry::serverOfKey($key);
                if (BackupDispatcher::isLocalServer($owner)) {
                    continue;
                }
                $entry = is_array($candidate) ? $candidate : null;
                $serverId = $owner;
                break;
            }
        }

        $serverId = $serverId === '' ? ServerStore::LOCAL_ID : $serverId;
        $isAgent = !BackupDispatcher::isLocalServer($serverId);

        if ($isAgent) {
            try {
                $this->assertAgentSupports($serverId, 'restore_archived');
            } catch (\RuntimeException $e) {
                return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
            }
        }

        // Cek keberadaan volume target hanya bisa dilakukan untuk server lokal
        // (Engine host ini). Untuk server agent, keberadaannya diperiksa di server
        // itu oleh worker/job — control plane tidak menebak.
        $targetExists = false;
        if (!$isAgent && $entry !== null) {
            try {
                $targetExists = $this->volumeNameExists($targetName);
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()])->withStatus(500);
            }
        }

        $plan = VolumeRestoreService::planArchiveRestore(is_array($entry) ? $entry : null, $targetExists);
        if (!$plan['ok']) {
            if ($plan['code'] === 404) {
                return $this->notFound($request);
            }
            return json(['code' => $plan['code'], 'msg' => $plan['msg']])->withStatus($plan['code']);
        }

        if (!$this->spawnWorker(['restore-archived', $volume, $snapshot, $targetName, $serverId])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker restore.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Restore dijalankan.']);
    }

    /**
     * Unduh berkas `.sql` dari snapshot arsip strategi `dump` (keputusan 2a).
     * Admin-only ⇒ non-admin 404.
     *
     * Berkas dibuka ke direktori temp (`VolumeRestoreService::extractSnapshotSql`),
     * isinya dibaca, lalu direktori temp **selalu** dibersihkan lewat `finally`.
     * Respons = `Webman\Http\Response` (unduhan), bukan helper `json()`.
     */
    public function downloadArchiveSql(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound($request);
        }

        $volume = trim((string) $request->get('volume', ''));
        $snapshot = trim((string) $request->get('snapshot', ''));

        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }
        try {
            ResticRunner::assertSnapshotId($snapshot);
        } catch (\Throwable $e) {
            return json(['code' => 422, 'msg' => 'Id snapshot tidak valid.'])->withStatus(422);
        }

        // Arsip volume server agent ada di repo restic **server itu**; ekstraksi di
        // control plane akan membaca repo yang salah. Ditolak tegas (bukan 500
        // setelah beberapa detik menunggu restic).
        if (!BackupDispatcher::isLocalServer(trim((string) $request->get('server_id', '')))) {
            return json([
                'code' => 422,
                'msg' => 'Unduh `.sql` dari arsip server agent harus dijalankan di server itu (job `db_dump`), '
                    . 'bukan dari control plane.',
            ])->withStatus(422);
        }

        $dir = null;
        try {
            $extracted = (new VolumeRestoreService())->extractSnapshotSql($volume, $snapshot);
            $dir = $extracted['dir'];

            $content = @file_get_contents($extracted['path']);
            if ($content === false) {
                return json(['code' => 500, 'msg' => 'Berkas dump tidak dapat dibaca.'])->withStatus(500);
            }

            return new Response(200, [
                'Content-Type' => 'application/sql; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . self::safeDownloadName($volume, $snapshot) . '"',
                'Content-Length' => (string) strlen($content),
                'X-Content-Type-Options' => 'nosniff',
            ], $content);
        } catch (\InvalidArgumentException $e) {
            // Snapshot tidak memuat `.sql` (input tak dapat diproses).
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal menyiapkan dump: ' . $e->getMessage()])->withStatus(500);
        } finally {
            if ($dir !== null) {
                VolumeRestoreService::removeWorkDir($dir);
            }
        }
    }

    // ==================================================================
    // Database dashboard (SQLite) — kartu & aksi di halaman /backups
    //
    // Semua endpoint admin-only (pola 404, bukan 403). Controller hanya
    // mediator: seluruh logika ada di `DbBackup` (snapshot/retensi/restore).
    // Aksi memakai POST + flash lalu redirect kembali ke `#db-backup`.
    // ==================================================================

    /**
     * Apakah nama volume sudah dipakai Docker Engine **server ini** — satu-satunya
     * jalur `restoreArchived()` yang menyentuh Engine lokal, dan hanya untuk server
     * **lokal** (server agent diperiksa di server itu oleh worker).
     *
     * `protected` (bukan `private`) semata agar unit test dapat meng-override-nya:
     * menguji routing arsip tidak boleh menuntut Docker Engine nyata (pola yang
     * sama dengan `BackupControllerBase::volumeTargets()`).
     */
    protected function volumeNameExists(string $name): bool
    {
        $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);

        return self::volumeListed($docker->listVolumes(['name' => [$name]]), $name);
    }

    /**
     * Apakah sebuah nama ada persis di daftar volume Engine.
     *
     * Filter `name` Docker bersifat fuzzy (cocok sebagian), jadi hasilnya harus
     * dicocokkan **persis** sebelum dipakai menyimpulkan "volume sudah ada".
     * Statik murni → mudah diuji.
     *
     * @param array<int,array<string,mixed>> $volumes respons `DockerClient::listVolumes()`
     */
    public static function volumeListed(array $volumes, string $name): bool
    {
        foreach ($volumes as $volume) {
            if (is_array($volume) && (string) ($volume['Name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }
}
