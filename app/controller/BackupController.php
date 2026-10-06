<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Backup\BackupAccess;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupReport;
use app\library\Backup\BackupSelection;
use app\library\Backup\BackupStrategyResolver;
use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Support\Markdown;
use support\Request;
use Webman\Http\Response;

/**
 * Halaman & API backup volume ke S3 via restic (PLAN_VOLUME_BACKUP.md §4.1).
 *
 * Controller hanya **mediator**: validasi input, cek otorisasi (satu pintu —
 * `BackupAccess`), delegasi ke library, kembalikan respons. Tanpa logika bisnis
 * dan tanpa state (worker Webman persistent, `controller_reuse=false`).
 *
 * Kontrak JSON (kunci stabil — dipakai Frontend UI):
 *   GET  /api/backups/status    → {code:0,data:{running,cached_at,status,volumes:[…],archived:[…]}}
 *   GET  /api/backups/snapshots → {code:0,data:{volume,snapshots:[…]}}
 *   POST /backups/run           → {code:0,msg}
 *   POST /backups/restore       → {code:0,msg}
 *   POST /backups/refresh       → {code:0,data:{running,cached_at,status,volumes:[…],archived:[…]}} (admin)
 *   POST /backups/schedule      → {code:0,data:{volume,scheduled,explicit}} (admin)
 *   GET  /api/backups/archive/snapshots → {code:0,data:{volume,snapshots:[…]}} (admin)
 *   POST /backups/archive/restore       → {code:0,msg} (admin)
 *   GET  /backups/archive/sql           → unduhan berkas .sql (admin)
 *   GET  /backups/guide                 → halaman panduan setup Restic (admin)
 * Tiap baris `volumes[]` (status & refresh) menyertakan `scheduled:bool` segar
 * dari `BackupSelection` (bukan dari cache) — lihat `withScheduled()`.
 * `archived[]` (riwayat volume yang sudah dihapus) **admin-only** (keputusan 4a):
 * non-admin selalu menerima `[]`; tiap baris diberi `restorable:bool`. Lihat
 * `archivedRows()`.
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
     * Halaman panduan setup Restic (admin-only).
     *
     * Merender Markdown `host/restic-setup.md` (berkas **repo**, bukan data
     * runtime) menjadi HTML aman via `Markdown::toHtml()`. Non-admin ⇒ 404
     * (satu pintu, konsisten dengan `refresh()`/`setScheduled()`). Best-effort:
     * berkas tak ada/gagal dibaca → `guideHtml = ''` — halaman tetap dirender,
     * panduan tidak pernah menggagalkan halaman.
     */
    public function guide(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound();
        }

        return view('backup/guide', [
            'guideHtml' => self::guideHtml(base_path('host/restic-setup.md')),
            'isAdmin' => true,
        ]);
    }

    /**
     * Ringkasan status run + volume yang boleh dilihat user (dibaca dari cache).
     *
     * **Tanpa** panggilan Docker Engine/restic: baris volume diambil dari cache
     * hasil run terakhir / tombol Segarkan. Hanya status run & lock (file) yang
     * diprobe — murah untuk polling.
     */
    public function status(Request $request)
    {
        $service = new VolumeBackupService();

        try {
            $catalog = $service->catalog();
        } catch (\Throwable $e) {
            // Cache rusak/tak terbaca bukan alasan 500 (ia hanya cache).
            $catalog = ['volumes' => [], 'cached_at' => null];
        }

        $apps = (new AppStore())->all();
        $visible = BackupAccess::visible($catalog['volumes'], $apps, current_user());
        $status = self::publicStatus((new BackupReport())->readStatus());

        return json(['code' => 0, 'data' => [
            'running' => $service->isRunning(),
            'cached_at' => $catalog['cached_at'],
            // `status` selalu objek (bukan array kosong) agar kunci stabil di JS.
            'status' => $status === [] ? new \stdClass() : $status,
            'volumes' => self::withScheduled($visible),
            'archived' => $this->archivedPayload($service),
        ]]);
    }

    /**
     * Hitung ulang ringkasan secara **live** (Engine + restic), simpan ke cache,
     * lalu kembalikan baris yang boleh dilihat user. Bentuk respons sama dengan
     * `status()` (termasuk `cached_at` baru).
     *
     * **Gate (WAJIB):**
     *  - **Admin global saja.** Refresh memicu Engine + restic S3 atas SELURUH
     *    volume dan menulis cache bersama, jadi semua user login tidak boleh
     *    memicunya berulang. Non-admin ⇒ 404 (bukan 403) agar keberadaan
     *    endpoint tidak bocor — konsisten satu pintu.
     *  - **Fitur mati ⇒ 422 tanpa menyentuh Engine.** Bila
     *    `deploy.volume_backup_enabled` = false, hentikan lebih awal.
     */
    public function refresh(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound();
        }
        if (config('deploy.volume_backup_enabled', true) === false) {
            return json(['code' => 422, 'msg' => 'Fitur backup volume dimatikan.'])->withStatus(422);
        }

        $service = new VolumeBackupService();

        try {
            // Invarian: cache (`BackupCatalog`) boleh memuat baris SELURUH volume
            // (termasuk app milik user lain); setiap respons SELALU disaring
            // `BackupAccess::visible()` di bawah — cache tidak pernah dikirim
            // mentah ke user.
            $rows = $service->refreshCatalog();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal menyegarkan status backup: ' . $e->getMessage()]);
        }

        $apps = (new AppStore())->all();
        $visible = BackupAccess::visible($rows, $apps, current_user());
        $status = self::publicStatus((new BackupReport())->readStatus());

        return json(['code' => 0, 'data' => [
            'running' => $service->isRunning(),
            'cached_at' => $service->catalog()['cached_at'],
            'status' => $status === [] ? new \stdClass() : $status,
            'volumes' => self::withScheduled($visible),
            'archived' => $this->archivedPayload($service),
        ]]);
    }

    /**
     * Nyalakan/matikan seleksi backup berkala sebuah volume (opt-in per volume).
     * **Hanya admin global** — bukan admin → 404 (satu pintu, konsisten).
     */
    public function setScheduled(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound();
        }

        $volume = trim((string) $request->post('volume', ''));
        try {
            VolumeStateGuard::assertVolumeName($volume);
        } catch (\Throwable $e) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        $raw = (string) $request->post('scheduled', '');
        if ($raw !== '0' && $raw !== '1') {
            return json(['code' => 422, 'msg' => 'Nilai scheduled harus 1 atau 0.'])->withStatus(422);
        }
        $scheduled = $raw === '1';

        $selection = new BackupSelection();
        $actorId = (string) (current_user()['id'] ?? '');
        try {
            $selection->setScheduled($volume, $scheduled, $actorId);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal menyimpan pengaturan jadwal: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => [
            'volume' => $volume,
            'scheduled' => $scheduled,
            'explicit' => $selection->explicit(),
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

    /**
     * Daftar snapshot restic sebuah volume **arsip** (admin-only, keputusan 4a).
     *
     * Berbeda dari `snapshots()`, ia **tidak** membutuhkan volume di Docker
     * Engine: snapshot dibaca langsung dari repo restic via tag `volume:<nama>`,
     * sehingga volume yang sudah dihapus beserta app-nya tetap bisa dipulihkan.
     * Non-admin ⇒ 404 (bukan 403 — satu pintu, keberadaan endpoint tak bocor).
     */
    public function archivedSnapshots(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound();
        }

        $volume = trim((string) $request->get('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
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
     */
    public function restoreArchived(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound();
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
            $entry = (new BackupRegistry())->read()[$volume] ?? null;
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal membaca arsip backup: ' . $e->getMessage()])->withStatus(500);
        }

        // Cek keberadaan volume target hanya bila entri ada (hemat Engine call
        // pada 404). Filter `name` Docker bersifat fuzzy → cocokkan persis.
        $targetExists = false;
        if ($entry !== null) {
            try {
                $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
                $targetExists = self::volumeListed($docker->listVolumes(['name' => [$targetName]]), $targetName);
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()])->withStatus(500);
            }
        }

        $plan = VolumeRestoreService::planArchiveRestore($entry, $targetExists);
        if (!$plan['ok']) {
            if ($plan['code'] === 404) {
                return $this->notFound();
            }
            return json(['code' => $plan['code'], 'msg' => $plan['msg']])->withStatus($plan['code']);
        }

        if (!$this->spawnWorker(['restore-archived', $volume, $snapshot, $targetName])) {
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
            return $this->notFound();
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
    // Helper
    // ==================================================================

    /**
     * Baca & render aman berkas panduan Markdown menjadi HTML.
     *
     * Statik murni (tanpa state controller) & fail-safe: berkas tidak ada,
     * tidak terbaca, kosong, atau whitespace-only → `''` (bukan throw) agar
     * halaman yang merendernya tidak pernah gagal. HTML di-escape oleh
     * `Markdown::toHtml()` (`html_input=escape` + tolak tautan tidak aman);
     * hasilnya **jangan** dibungkus `e()` di view.
     */
    public static function guideHtml(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $markdown = @file_get_contents($path);
        if ($markdown === false || trim($markdown) === '') {
            return '';
        }

        return Markdown::toHtml($markdown);
    }

    /**
     * Sisipkan flag `scheduled` **segar** (dari `BackupSelection`) ke tiap baris
     * volume yang dikirim ke UI.
     *
     * Flag sengaja **tidak** disimpan di cache `BackupCatalog` (bisa basi); ia
     * di-merge saat merespons. Satu instans `BackupSelection` per request
     * (variabel lokal — bukan properti controller; larangan #1).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function withScheduled(array $rows, ?BackupSelection $selection = null): array
    {
        $selection ??= new BackupSelection();
        foreach ($rows as $index => $row) {
            $row['scheduled'] = $selection->isScheduled((string) ($row['name'] ?? ''));
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Daftar arsip registry untuk respons (admin-only).
     *
     * Non-admin ⇒ `[]` **tanpa** menyentuh registry (keputusan 4a: tampilan arsip
     * admin-only). Best-effort: kegagalan baca arsip (berkas rusak) tidak boleh
     * menggagalkan seluruh respons status.
     */
    private function archivedPayload(VolumeBackupService $service): array
    {
        if (!is_admin()) {
            return [];
        }

        try {
            return self::archivedRows($service->archived(), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Baris arsip untuk respons + flag `restorable`.
     *
     * Non-admin ⇒ `[]` (keputusan 4a: tampilan arsip admin-only). `restorable`
     * bernilai `true` hanya bila strategi `snapshot` **dan** snapshot masih ada
     * (`snapshots > 0`) — arsip strategi `dump` tidak dapat direstore dari
     * volume. Statik murni (tanpa I/O) agar mudah diuji.
     *
     * @param array<int,array<string,mixed>> $entries hasil `VolumeBackupService::archived()`
     * @return array<int,array<string,mixed>>
     */
    public static function archivedRows(array $entries, bool $isAdmin): array
    {
        if (!$isAdmin) {
            return [];
        }

        $rows = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry['restorable'] = ((string) ($entry['strategy'] ?? '') === BackupStrategyResolver::STRATEGY_SNAPSHOT)
                && (int) ($entry['snapshots'] ?? 0) > 0;
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * Status run untuk **semua** user login — hanya kunci aman (daftar-putih).
     *
     * `BackupReport::readStatus()` memuat `volumes[]` (hasil run terakhir) dan
     * `error` yang menyebut nama/error volume milik app lain; keduanya TIDAK
     * boleh dikirim ke non-admin. Yang tersisa hanya ringkasan agregat + `totals`
     * (kunci stabil yang dipakai UI). `volumes` sengaja dibuang karena baris
     * per-volume sudah disanitasi terpisah lewat `BackupAccess::visible`.
     *
     * Statik murni (tanpa I/O) agar mudah diuji.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public static function publicStatus(array $status): array
    {
        $keys = ['running', 'started_at', 'finished_at', 'duration_ms', 'status', 'trigger', 'totals'];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $status)) {
                $out[$key] = $status[$key];
            }
        }

        return $out;
    }

    /**
     * Nama berkas unduhan yang aman untuk header `Content-Disposition`.
     *
     * `volume`/`snapshot` sudah divalidasi sebelum ini, tetapi nama tetap
     * disanitasi (buang komponen path → cegah traversal, sisakan `[A-Za-z0-9._-]`)
     * agar header tidak bisa disuntik kutip/baris baru. Statik murni → mudah diuji.
     */
    public static function safeDownloadName(string $volume, string $snapshot): string
    {
        $sanitize = static function (string $value): string {
            $value = basename(str_replace('\\', '/', $value));
            $value = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
            $value = trim($value, '._-');
            return $value === '' ? 'backup' : $value;
        };

        return $sanitize($volume) . '-' . $sanitize($snapshot) . '.sql';
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
