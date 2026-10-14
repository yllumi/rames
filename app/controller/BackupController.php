<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\UserStore;
use app\library\Backup\BackupAccess;
use app\library\Backup\BackupCatalog;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupJobRunner;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupReport;
use app\library\Backup\BackupSelection;
use app\library\Backup\BackupStrategyResolver;
use app\library\Backup\DumpStaging;
use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Billing\BackupUsage;
use app\library\Billing\BillingGate;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Storage\DbBackup;
use app\library\Storage\ServerStore;
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
 *     Baris `volumes[]` = **cache** (`BackupCatalog`) + **satu** panggilan Engine
 *     read-only (`GET /containers/json?all=1`) yang hanya menyegarkan
 *     `container_state`; Engine gagal ⇒ nilai cache dipakai (kunci JSON tetap
 *     sama, `container_state` tetap string).
 *   GET  /api/backups/snapshots → {code:0,data:{volume,snapshots:[…]}}
 *   POST /backups/run           → {code:0,msg}
 *   POST /backups/restore       → {code:0,msg}
 *   POST /backups/refresh       → {code:0,data:{running,cached_at,status,volumes:[…],archived:[…]}} (admin)
 *   POST /backups/schedule      → {code:0,data:{volume,scheduled,explicit,backup_gb,required}}
 *                                 (hak `backup` = owner/operator; 402 bila kredit kurang)
 *   GET  /api/backups/archive/snapshots → {code:0,data:{volume,snapshots:[…]}} (admin)
 *   POST /backups/archive/restore       → {code:0,msg} (admin)
 *   GET  /backups/archive/sql           → unduhan berkas .sql (admin)
 *   GET  /backups/guide                 → halaman panduan setup Restic (admin)
 * Tiap baris `volumes[]` (status & refresh) menyertakan `scheduled:bool` segar
 * dari `BackupSelection` (bukan dari cache), `can_schedule:bool`
 * (`BackupAccess::can('backup', …)` per baris), serta biaya backup
 * (`backup_gb`, `backup_credits_month`) — lihat `withScheduled()`.
 * `archived[]` (riwayat volume yang sudah dihapus) **admin-only** (keputusan 4a):
 * non-admin selalu menerima `[]`; tiap baris diberi `restorable:bool`. Lihat
 * `archivedRows()`.
 * Error akses → 404 (via `AppAccessDenied`), bukan 403.
 *
 * **Multi-server F4** (plan §4.2/§4.3/§5): daftar target volume kini menggabungkan
 * volume server **lokal** (Engine host ini) dengan volume **server agent** yang
 * dibaca dari server itu (`agentVolumeTargets()` ⇒ `AgentDeployer::backupVolumes()`),
 * masing-masing ber-`server_id`. Pekerjaan backup/restore/dump untuk target server
 * agent diteruskan ke server itu oleh `cli/backup.php` **di sana** (`server_id`
 * ikut sebagai argumen worker; rinciannya di `BackupDispatcher`) — host control
 * plane tidak pernah menjalankan restic/helper terhadap volume server agent.
 * Riwayat/registri tetap milik control plane (`BackupRegistry`, kunci ter-scope
 * `server_id`), sedangkan seleksi jadwal per volume juga ter-scope server.
 */
class BackupController
{
    /** Teks konfirmasi restore DB SQLite (kartu "Database dashboard"). */
    private const DB_RESTORE_CONFIRM = 'RESTORE';

    /**
     * Halaman /backups. Data volume diambil via AJAX (`status()`) agar halaman
     * tidak menunggu Engine/restic.
     */
    public function index(Request $request)
    {
        $isAdmin = $this->isAdmin();

        return view('backup/index', [
            'enabled' => (bool) config('deploy.volume_backup_enabled', true),
            'policy' => (string) config('deploy.volume_backup_snapshot_policy', 'stop'),
            'isAdmin' => $isAdmin,
            // Kartu "Database dashboard (SQLite)" — admin-only (data infra);
            // non-admin menerima null sehingga kartu tidak dirender.
            'db' => $isAdmin ? $this->dbDashboard() : null,
            // Tarif & floor biaya backup (satu sumber: `Pricing`/`BackupUsage`) —
            // view TIDAK boleh hardcode angka; nilainya juga dikirim ke JS lewat
            // `data-*` di `#backup-page` (tooltip & alert biaya).
            'billingEnabled' => (bool) config('deploy.billing_enabled', true),
            'backupRatePerGb' => Pricing::backupMonthlyRate(),
            'backupMinGb' => (new BackupUsage())->minGigabytes(),
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
     * Ringkasan status run + volume yang boleh dilihat user.
     *
     * Baris volume diambil dari **cache** (`BackupCatalog`: `snapshots`,
     * `last_run_at`, `last_ok`, `last_message`, `strategy`, `orphaned`, `app_*`)
     * dan hanya kolom `container_state` yang disegarkan **live** lewat **satu**
     * panggilan Engine read-only (`VolumeBackupService::overlayLiveContainerStates()`
     * → `GET /containers/json?all=1`) agar kolom itu tidak basi antara dua run /
     * tombol Segarkan. Bila Engine tak terjangkau, baris cache dikembalikan apa
     * adanya (tidak ada error ke user).
     *
     * Selain itu hanya status run & lock (file) yang diprobe — murah untuk polling.
     */
    public function status(Request $request)
    {
        $service = $this->backupService();

        try {
            $catalog = $service->catalog();
        } catch (\Throwable $e) {
            // Cache rusak/tak terbaca bukan alasan 500 (ia hanya cache).
            $catalog = ['volumes' => [], 'cached_at' => null];
        }

        $apps = (new AppStore())->all();
        $user = $this->currentUser();
        $visible = BackupAccess::visible($catalog['volumes'], $apps, $user);
        $status = self::publicStatus((new BackupReport())->readStatus());

        // `container_state` LIVE: satu panggilan Engine read-only untuk seluruh
        // baris **lokal** (gagal ⇒ nilai cache dipertahankan, lihat overlay).
        // Baris server agent TIDAK di-overlay: state-nya milik server itu (dikirim
        // apa adanya dari sana) — Engine host ini tidak tahu apa-apa soal volume itu.
        $rows = self::withScheduled($visible, null, $apps, $user);
        $rows = self::overlayLocalRows($service, $rows);

        return json(['code' => 0, 'data' => [
            'running' => $service->isRunning(),
            'cached_at' => $catalog['cached_at'],
            // `status` selalu objek (bukan array kosong) agar kunci stabil di JS.
            'status' => $status === [] ? new \stdClass() : $status,
            'volumes' => $rows,
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

        // Multi-server F4: volume server **agent** dibaca dari server itu lalu
        // ikut disimpan di cache control plane (satu sumber untuk polling status;
        // agent tidak pernah dihubungi oleh `GET /api/backups/status`).
        $rows = array_merge($rows, $this->agentVolumeRows());

        try {
            (new BackupCatalog((new BackupReport())->dir() . '/catalog.json'))->write($rows);
        } catch (\Throwable $e) {
            // Cache bersifat best-effort: baris live tetap dikembalikan.
        }

        $apps = (new AppStore())->all();
        $user = $this->currentUser();
        $visible = BackupAccess::visible($rows, $apps, $user);
        $status = self::publicStatus((new BackupReport())->readStatus());

        return json(['code' => 0, 'data' => [
            'running' => $service->isRunning(),
            'cached_at' => $service->catalog()['cached_at'],
            'status' => $status === [] ? new \stdClass() : $status,
            'volumes' => self::withScheduled($visible, null, $apps, $user),
            'archived' => $this->archivedPayload($service),
        ]]);
    }

    /**
     * Nyalakan/matikan seleksi backup berkala sebuah volume (opt-in per volume).
     *
     * Otorisasi lewat satu pintu `BackupAccess::require('backup', …)` (owner &
     * operator boleh; viewer/stranger ⇒ `AppAccessDenied` → **404**, bukan 403).
     * Volume yatim tetap admin-only. Target di-resolve dari Engine (bukan dari
     * nama container/volume yang dikirim request) — lihat `volumeTargets()`.
     *
     * Gerbang kredit **hanya saat menyalakan** (`scheduled=1`) dan hanya untuk
     * pihak yang menanggung biaya (lihat `assertCanScheduleBackup()`); saldo
     * kurang ⇒ **402** JSON (`{code:402,error}`) dan seleksi **tidak** berubah.
     * Mematikan backup tidak pernah digerbang.
     */
    public function setScheduled(Request $request)
    {
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
            // Termasuk volume DB dashboard (§8i.7) yang bukan target backup app.
            return $this->notFound();
        }

        $actor = $this->currentUser();
        $appId = (string) ($target['app_id'] ?? '');
        $app = $this->findApp((new AppStore())->all(), $appId);

        // Otorisasi SEBELUM efek samping apa pun (AppAccessDenied → 404).
        BackupAccess::require('backup', $target, $app, $actor);

        $usage = new BackupUsage();
        $gb = $usage->chargedGigabytes($appId);

        if ($scheduled) {
            try {
                $this->assertCanScheduleBackup($gb, $app, $actor);
            } catch (InsufficientCredits $e) {
                // Endpoint JSON/AJAX: 402 (bukan redirect) + pesan dari gerbang.
                return json(['code' => 402, 'error' => $e->getMessage()])->withStatus(402);
            }
        }

        $selection = new BackupSelection();
        $actorId = (string) ($actor['id'] ?? '');
        $targetServerId = self::serverIdOf($target);
        try {
            $selection->setScheduled($volume, $scheduled, $actorId, $targetServerId);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal menyimpan pengaturan jadwal: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => [
            'volume' => $volume,
            'server_id' => $targetServerId,
            'scheduled' => $scheduled,
            'explicit' => $selection->explicit($targetServerId),
            'backup_gb' => $gb,
            'required' => $usage->requiredDeposit($gb),
        ]]);
    }

    /**
     * Gerbang kredit sebelum **menyalakan** backup berkala — semantik sama dengan
     * `AppController::billingAssertCanStart()` (§7.12), urutannya:
     *   (1) billing mati / tanpa konteks user login ⇒ tanpa gerbang;
     *   (2) aktor bebas tagihan (admin) ⇒ tanpa gerbang;
     *   (3) app **milik user bebas tagihan** (admin) ⇒ bebas untuk siapa pun;
     *   (4) gerbang untuk **aktor** (`balance < required` ⇒ `InsufficientCredits`);
     *   (5) **penanggung biaya** (owner app) yang berbeda dari aktor dinilai
     *       **juga** — biaya diakru ke owner, jadi operator bersaldo cukup tidak
     *       boleh menyalakan fitur berbayar pada app milik member bersaldo kurang
     *       (pesan khusus menyebut pemilik app).
     *
     * `$gate`/`$users` adalah seam uji (path temp) — produksi memakai default.
     *
     * @throws InsufficientCredits
     */
    private function assertCanScheduleBackup(float $gb, ?array $app, ?array $user, ?BillingGate $gate = null, ?UserStore $users = null): void
    {
        $gate ??= new BillingGate(null, null, $users);

        if (!$gate->isEnabled() || $user === null) {
            return;
        }

        // (2) Aktor admin bebas kredit — tidak pernah diblokir, bahkan untuk app
        //     milik member yang saldonya kurang.
        if ($gate->isExempt($user)) {
            return;
        }

        // Penanggung biaya: owner app; fallback aktor bila `owner_id` kosong
        // (pola `AppController::billingPayerFor()`).
        $payer = $this->billingPayerFor($app ?? [], $user, $users);

        // (3) App milik user bebas tagihan (admin) ⇒ bebas kredit untuk siapa pun.
        if ($payer !== null && $gate->isExempt($payer)) {
            return;
        }

        // (4) Aktor wajib punya saldo.
        $gate->assertCanBackup($gb, $user);

        // (5) Owner yang berbeda dari aktor menanggung biaya akrual ⇒ saldonya
        //     dinilai juga, dengan pesan yang menyebut pemilik app.
        if ($payer === null || (string) ($payer['id'] ?? '') === (string) ($user['id'] ?? '')) {
            return;
        }

        try {
            $gate->assertCanBackup($gb, $payer);
        } catch (InsufficientCredits $e) {
            throw new InsufficientCredits($e->balance, $e->required, sprintf(
                'Saldo kredit pemilik app tidak mencukupi (saldo %s, minimum %s). '
                . 'Minta pemilik menambah kredit di halaman Kredit.',
                Pricing::format($e->balance),
                Pricing::format($e->required)
            ));
        }
    }

    /**
     * User yang menanggung biaya backup sebuah app — **owner** app; fallback
     * aktor bila `owner_id` kosong/tak diketahui (`AppController::billingPayerFor()`).
     *
     * Owner dibaca lewat `UserStore::findPublicById()` (tanpa hash sandi); store
     * tak terbaca ⇒ jatuh ke aktor (jangan gagalkan aksi karena masalah store —
     * `AuthMiddleware` tetap penjaga utama autentikasi).
     */
    private function billingPayerFor(array $app, ?array $user, ?UserStore $users = null): ?array
    {
        $ownerId = trim((string) ($app['owner_id'] ?? ''));
        if ($ownerId === '') {
            return $user;
        }
        if ($user !== null && (string) ($user['id'] ?? '') === $ownerId) {
            return $user;
        }

        try {
            return ($users ?? new UserStore())->findPublicById($ownerId) ?? $user;
        } catch (\Throwable) {
            return $user;
        }
    }

    /**
     * Daftar snapshot restic sebuah volume.
     *
     * Multi-server F4: volume milik **server agent** dibaca dari repo restic
     * server itu (`AgentDeployer::backupSnapshots()`), volume lokal dari repo host
     * ini seperti sebelumnya.
     */
    public function snapshots(Request $request)
    {
        $volume = trim((string) $request->get('volume', ''));
        try {
            VolumeStateGuard::assertVolumeName($volume);
        } catch (\Throwable $e) {
            return $this->notFound();
        }

        $service = $this->backupService();
        try {
            $targets = $this->volumeTargets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        try {
            $target = $this->findTarget($targets, $volume, trim((string) $request->get('server_id', '')));
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }
        if ($target === null) {
            return $this->notFound();
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('view', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        try {
            $snapshots = BackupDispatcher::isLocalServer(self::serverIdOf($target))
                ? $service->snapshotsFor($volume)
                : $this->agentDeployerForTarget($target)->backupSnapshots($volume);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal membaca snapshot: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => ['volume' => $volume, 'snapshots' => $snapshots]]);
    }

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
            return $this->notFound();
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
            return $this->notFound();
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

    /**
     * Dump logis DB satu volume (worker detached, ability `backup`).
     *
     * Multi-server F4: volume server agent di-dump **di server itu** (job
     * `db_dump`, kredensial DB dibaca di sana); volume lokal memakai jalur worker
     * host ini. Tidak ada UI khusus untuk aksi ini (dipakai operator/admin lewat
     * API) — berkas `.sql` tetap di staging server yang menjalankannya.
     */
    public function dbDump(Request $request)
    {
        if (config('deploy.volume_backup_enabled', true) === false) {
            return json(['code' => 422, 'msg' => 'Fitur backup volume dimatikan.'])->withStatus(422);
        }

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
            return $this->notFound();
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('backup', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $serverId = self::serverIdOf($target);
        try {
            $this->assertAgentSupports($serverId, 'db_dump');
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }

        $appArg = ($target['app_id'] !== null && $target['app_id'] !== '') ? (string) $target['app_id'] : '-';
        if (!$this->spawnWorker(['db-dump', $appArg, $volume, $serverId])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker dump.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Dump database dijalankan.']);
    }

    /**
     * Unduh berkas `.sql` hasil `db_dump` (staging) sebuah volume — ability
     * `backup` (operator+).
     *
     * Multi-server F4 lanjutan: volume server **agent** diunduh **dari server
     * itu** lewat `AgentDeployer::downloadDump()` (potongan bertahap, setiap
     * panggilan HTTP ≤30 s, total dibatasi plafon); volume lokal dibaca dari
     * staging host ini lewat `BackupJobRunner::dumpChunk()` — kontrol plane tidak
     * pernah membaca staging server agent secara langsung.
     *
     * Respons = `Webman\Http\Response` (unduhan), bukan helper `json()`.
     */
    public function downloadDumpSql(Request $request)
    {
        if (config('deploy.volume_backup_enabled', true) === false) {
            return json(['code' => 422, 'msg' => 'Fitur backup volume dimatikan.'])->withStatus(422);
        }

        $volume = trim((string) $request->get('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        try {
            $targets = $this->volumeTargets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()])->withStatus(500);
        }

        try {
            $target = $this->findTarget($targets, $volume, trim((string) $request->get('server_id', '')));
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }
        if ($target === null) {
            return $this->notFound();
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('backup', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $maxBytes = max(1, (int) config('deploy.volume_backup_dump_download_max_bytes', 134217728));
        $serverId = self::serverIdOf($target);

        if (!BackupDispatcher::isLocalServer($serverId)) {
            try {
                $this->assertAgentSupports($serverId, 'db_dump');
                $dump = $this->agentDeployerForTarget($target)->downloadDump($volume);
            } catch (\RuntimeException $e) {
                return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
            } catch (\Throwable $e) {
                $code = AgentDeployer::failureCode($e);

                return json(['code' => $code, 'msg' => AgentError::sanitize($e->getMessage())])->withStatus($code);
            }
        } else {
            try {
                $dump = $this->readLocalDump($volume, $maxBytes);
            } catch (\RuntimeException $e) {
                return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => 'Gagal menyiapkan dump: ' . $e->getMessage()])->withStatus(500);
            }
        }

        $content = (string) ($dump['content'] ?? '');
        $name = (string) ($dump['name'] ?? '');
        if (strlen($content) > $maxBytes) {
            return json(['code' => 422, 'msg' => 'Berkas dump melebihi batas unduh.'])->withStatus(422);
        }

        return new Response(200, [
            'Content-Type' => 'application/sql; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . self::safeDownloadName($volume, $name !== '' ? $name : 'dump') . '"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ], $content);
    }

    /**
     * Baca berkas dump staging **host ini** (jalur lokal) secara bertahap —
     * memakai `BackupJobRunner::dumpChunk()` (satu sumber dengan endpoint agent),
     * dibatasi `$maxBytes` supaya satu permintaan tidak menahan data tanpa batas.
     *
     * `protected` (bukan `private`) semata agar unit test dapat menyuntikkan isi
     * dump tanpa menyentuh staging `runtime/` nyata — perilaku produksi tidak
     * berubah (pola yang sama dengan `volumeTargets()`).
     *
     * @return array{name:string,bytes:int,content:string}
     * @throws \RuntimeException belum ada dump / melebihi batas / berkas tak terbaca
     */
    protected function readLocalDump(string $volume, int $maxBytes): array
    {
        $runner = new BackupJobRunner();
        $content = '';
        $name = '';
        $offset = 0;

        while (true) {
            $chunk = $runner->dumpChunk($volume, $offset, DumpStaging::MAX_READ_BYTES);
            if ($name === '') {
                $name = (string) $chunk['name'];
            }
            $content .= (string) $chunk['data'];
            $offset += strlen((string) $chunk['data']);

            if (strlen($content) > $maxBytes) {
                throw new \RuntimeException('Berkas dump melebihi batas unduh — unduh langsung di server tersebut.');
            }
            if ($chunk['eof'] === true) {
                break;
            }
            if ((string) $chunk['data'] === '') {
                throw new \RuntimeException('Berkas dump tidak dapat dibaca sepenuhnya.');
            }
        }

        if ($content === '') {
            throw new \RuntimeException('Berkas dump kosong — tidak ada yang bisa diunduh.');
        }

        return ['name' => $name, 'bytes' => strlen($content), 'content' => $content];
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
                return $this->notFound();
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
     * Buat snapshot DB dashboard sekarang (manual).
     */
    public function dbRun(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound();
        }

        try {
            $result = (new DbBackup())->run('manual');
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal membuat snapshot database: ' . $e->getMessage());

            return redirect('/backups#db-backup');
        }

        if (!empty($result['skipped'])) {
            // Busy bukan kegagalan — beri tahu, jangan tampilkan sebagai error.
            $this->flash('info', 'Snapshot dilewati: backup lain sedang berjalan (busy). Coba lagi sebentar lagi.');
        } else {
            $this->flash('success', 'Snapshot database dibuat: ' . (string) ($result['file'] ?? ''));
        }

        return redirect('/backups#db-backup');
    }

    /**
     * Jalankan retensi (prune) snapshot DB dashboard sekarang.
     */
    public function dbPrune(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound();
        }

        try {
            $result = (new DbBackup())->prune();
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal menjalankan prune: ' . $e->getMessage());

            return redirect('/backups#db-backup');
        }

        if (!empty($result['skipped'])) {
            $this->flash('info', 'Prune dilewati: backup lain sedang berjalan (busy).');
        } else {
            $this->flash('success', sprintf(
                'Prune selesai — %d snapshot dihapus, %d dipertahankan.',
                (int) ($result['removed'] ?? 0),
                (int) ($result['kept'] ?? 0)
            ));
        }

        return redirect('/backups#db-backup');
    }

    /**
     * Pulihkan DB dashboard dari snapshot terpilih.
     *
     * **Wajib** konfirmasi teks `RESTORE` + nama snapshot valid. Setelah sukses,
     * worker **tidak** di-reload di sini (proses ini sendiri memakai DB) — pesan
     * flash meminta operator menjalankan `php start.php reload` manual.
     */
    public function dbRestore(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound();
        }

        $confirm = trim((string) $request->post('confirm', ''));
        if ($confirm !== self::DB_RESTORE_CONFIRM) {
            $this->flash('error', 'Konfirmasi restore tidak cocok — ketik RESTORE untuk melanjutkan.');

            return redirect('/backups#db-backup');
        }

        $file = trim((string) $request->post('file', ''));

        try {
            $result = (new DbBackup())->restore($file);
        } catch (\Throwable $e) {
            $this->flash('error', 'Restore gagal: ' . $e->getMessage());

            return redirect('/backups#db-backup');
        }

        $this->flash('success', 'Database dipulihkan dari ' . (string) ($result['file'] ?? '') . '. '
            . 'Jalankan `php start.php reload` agar worker memakai DB baru.');

        return redirect('/backups#db-backup');
    }

    /**
     * Unduh satu snapshot DB dashboard (`Content-Disposition: attachment`).
     *
     * Nama berkas divalidasi `DbBackup::download()` (basename `*.sqlite`, di
     * dalam direktori backup) sehingga path traversal ditolak **sebelum**
     * berkas disentuh. Streaming via `Response::download()` (sendfile) — isi
     * tidak dimuat ke memori. Nama tidak sah ⇒ 404 (bukan 403/422) agar
     * keberadaan berkas tidak bocor.
     */
    public function dbDownload(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound();
        }

        $file = trim((string) $request->get('file', ''));

        try {
            $path = (new DbBackup())->download($file);
        } catch (\Throwable $e) {
            return $this->notFound();
        }

        return (new Response(200, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]))->download($path, basename($path));
    }

    /**
     * Data kartu "Database dashboard (SQLite)" untuk `index()` (admin-only).
     *
     * Fail-safe: `DbBackup` tak dapat dibangun (mis. `deploy.sqlite_file` belum
     * diset) atau daftar snapshot tak terbaca tidak boleh menggagalkan halaman —
     * kembalikan `available=false` + pesan error. Tanpa logika bisnis: hanya
     * membaca data & meneruskan angka mentah; format tampilan dihitung view.
     *
     * @return array<string,mixed>
     */
    private function dbDashboard(): array
    {
        try {
            $backup = new DbBackup();
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage(), 'snapshots' => [], 'state' => [], 'keep' => []];
        }

        $snapshots = [];
        $state = [];
        $available = true;
        $error = null;

        try {
            $snapshots = $backup->list();
            $state = $backup->state();
        } catch (\Throwable $e) {
            $available = false;
            $error = $e->getMessage();
        }

        $totalBytes = 0;
        foreach ($snapshots as $row) {
            $totalBytes += (int) ($row['bytes'] ?? 0);
        }

        $dbFile = $backup->dbFile();

        return [
            'available' => $available,
            'error' => $error,
            'path' => $dbFile,
            'db_bytes' => is_file($dbFile) ? (int) (filesize($dbFile) ?: 0) : 0,
            'snapshots' => $snapshots,
            'count' => count($snapshots),
            'total_bytes' => $totalBytes,
            'latest' => $snapshots[0] ?? null,
            'enabled' => (bool) config('deploy.db_backup_enabled', true),
            'hour' => (int) config('deploy.db_backup_hour', 3),
            'due' => $available && $backup->isDue(),
            'keep' => [
                'daily' => (int) config('deploy.db_backup_keep_daily', 7),
                'weekly' => (int) config('deploy.db_backup_keep_weekly', 4),
                'monthly' => (int) config('deploy.db_backup_keep_monthly', 3),
            ],
            'state' => $state,
        ];
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
     * Terapkan overlay `container_state` **live** hanya pada baris volume server
     * lokal; baris server agent dibiarkan apa adanya (state-nya dikirim dari
     * server itu — Engine host ini tidak tahu apa-apa soal volume tersebut).
     *
     * Statik murni kecuali pemanggilan overlay service (seam `backupService()`).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function overlayLocalRows(VolumeBackupService $service, array $rows): array
    {
        $local = [];
        $agent = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (BackupDispatcher::isLocalServer(self::serverIdOf($row))) {
                $local[$index] = $row;
            } else {
                $agent[$index] = $row;
            }
        }

        if ($local === []) {
            return $rows;
        }

        $overlaid = $service->overlayLiveContainerStates($local);
        foreach ($overlaid as $index => $row) {
            $local[$index] = $row;
        }

        // Urutan asli dipertahankan (baris lokal di-overlay, baris agent apa adanya).
        $out = [];
        foreach ($rows as $index => $row) {
            $out[] = $local[$index] ?? $agent[$index] ?? $row;
        }

        return $out;
    }

    /**
     * Baris ringkasan volume **server agent** (multi-server F4) — dibaca dari
     * server itu (`AgentDeployer::backupVolumes()`), lengkap dengan `server_id`
     * dan nama app dari control plane.
     *
     * Best-effort: server tak terjangkau ⇒ dilewati (halaman & cache tetap jalan).
     * Baris mentah dari agent disanitasi ke kunci yang dikenal `BackupCatalog`
     * ({@see BackupCatalog::write()} menyaring ulang), jadi tidak ada kunci asing
     * yang masuk cache.
     *
     * `protected` supaya tes dapat meng-override tanpa jaringan.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function agentVolumeRows(): array
    {
        $apps = (new AppStore())->all();
        $byId = [];
        foreach ($apps as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                $byId[(string) $app['id']] = $app;
            }
        }

        $rows = [];
        foreach ($this->agentServers() as $server) {
            $serverId = (string) ($server['id'] ?? '');
            $agent = $this->agentDeployerForServer($server);
            if ($agent === null) {
                continue;
            }

            try {
                $listing = $agent->backupVolumes();
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($listing['volumes'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $appId = (string) ($row['app_id'] ?? '');
                $app = $appId !== '' ? ($byId[$appId] ?? null) : null;

                $row['app_id'] = $app !== null ? $appId : null;
                $row['app_name'] = $app !== null ? (string) ($app['name'] ?? '') : null;
                $row['orphaned'] = $app === null;
                $row['server_id'] = $serverId;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Sisipkan flag `scheduled` **segar** (dari `BackupSelection`) + data hak &
     * biaya backup ke tiap baris volume yang dikirim ke UI.
     *
     * Flag `scheduled` sengaja **tidak** disimpan di cache `BackupCatalog` (bisa
     * basi); ia di-merge saat merespons. Parameter `$apps`/`$user`/`$usage`
     * bersifat **aditif opsional** (pemanggil lama tetap sah):
     *   - `can_schedule` — `BackupAccess::can('backup', $row, $app, $user)` per
     *     baris (owner & operator boleh; viewer/stranger tidak; volume yatim
     *     hanya admin). `$user === null` ⇒ false (UI hanya lapisan kedua).
     *   - `backup_gb` / `backup_credits_month` — biaya snapshot app baris ini
     *     (`BackupUsage` + `Pricing`), `0.0` bila app tak ada/tak bersnapshot.
     *     Peta GB dibaca **sekali** per pemanggilan (`chargedGigabytesByApp()`),
     *     bukan per baris — `chargedGigabytes($appId)` per baris akan membaca
     *     ulang seluruh `BackupRegistry` tiap baris (N+1; endpoint ini dipolling
     *     tiap ~20 s oleh dashboard).
     *
     * Satu instans per request (variabel lokal — bukan properti controller).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array> $apps daftar app (`AppStore::all()`)
     * @return array<int,array<string,mixed>>
     */
    public static function withScheduled(
        array $rows,
        ?BackupSelection $selection = null,
        array $apps = [],
        ?array $user = null,
        ?BackupUsage $usage = null,
    ): array {
        $selection ??= new BackupSelection();
        $usage ??= new BackupUsage();

        $appsById = [];
        foreach ($apps as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                $appsById[(string) $app['id']] = $app;
            }
        }

        // Peta GB dibaca sekali per pemanggilan, **lazy** (hanya saat ada baris)
        // supaya registry tidak disentuh untuk daftar baris kosong.
        $chargedGbByApp = null;

        foreach ($rows as $index => $row) {
            $appId = (string) ($row['app_id'] ?? '');
            $app = $appId !== '' ? ($appsById[$appId] ?? null) : null;
            $chargedGbByApp ??= $usage->chargedGigabytesByApp();
            // appId kosong/yatim tidak pernah ada di peta ⇒ `0.0` (sama dengan
            // `BackupUsage::chargedGigabytes('')`).
            $gb = $chargedGbByApp[$appId] ?? 0.0;

            $row['scheduled'] = $selection->isScheduled(
                (string) ($row['name'] ?? ''),
                (string) ($row['server_id'] ?? '')
            );
            $row['can_schedule'] = BackupAccess::can('backup', $row, $app, $user);
            $row['backup_gb'] = $gb;
            $row['backup_credits_month'] = Pricing::backupMonthlyEstimate($gb);
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
        // Lewat seam `isAdmin()` (produksi identik dengan `is_admin()`) supaya
        // `status()`/`refresh()` dapat diuji tanpa konteks session HTTP.
        if (!$this->isAdmin()) {
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
     * Apakah nama volume sudah dipakai Docker Engine **server ini** — satu-satunya
     * jalur `restoreArchived()` yang menyentuh Engine lokal, dan hanya untuk server
     * **lokal** (server agent diperiksa di server itu oleh worker).
     *
     * `protected` (bukan `private`) semata agar unit test dapat meng-override-nya:
     * menguji routing arsip tidak boleh menuntut Docker Engine nyata (pola yang
     * sama dengan `volumeTargets()`).
     */
    protected function volumeNameExists(string $name): bool
    {
        $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);

        return self::volumeListed($docker->listVolumes(['name' => [$name]]), $name);
    }

    /**
     * @param array<int,array{name:string}> $targets
     * @return array|null
     */
    /**
     * Cari target volume berdasarkan nama (dan opsional server).
     *
     * Nama volume bisa sama di dua server (keunikan bersifat per server, plan
     * §4.2), jadi:
     *  - `$serverId` diisi ⇒ hanya target server itu;
     *  - `$serverId` kosong dan hanya ada satu kandidat ⇒ kandidat itu (perilaku
     *    lama untuk instalasi satu server);
     *  - lebih dari satu kandidat di server berbeda ⇒ **RuntimeException** dengan
     *    pesan yang meminta pemanggil menyebut server (fail-fast; tidak menebak).
     *
     * @param array<int,array<string,mixed>> $targets
     * @throws \RuntimeException volume ada di beberapa server tanpa `server_id`
     */
    private function findTarget(array $targets, string $volume, string $serverId = ''): ?array
    {
        $serverId = trim($serverId);

        $matches = [];
        foreach ($targets as $target) {
            if ((string) ($target['name'] ?? '') !== $volume) {
                continue;
            }
            if ($serverId !== '' && self::serverIdOf($target) !== $serverId) {
                continue;
            }
            $matches[] = $target;
        }

        if ($matches === []) {
            return null;
        }

        $serverIds = [];
        foreach ($matches as $match) {
            $serverIds[self::serverIdOf($match)] = true;
        }
        if (count($serverIds) > 1) {
            throw new \RuntimeException(
                "Volume \"{$volume}\" ada di lebih dari satu server (" . implode(', ', array_keys($serverIds)) . '). '
                . 'Sebutkan `server_id` untuk memilih server yang benar — operasi tidak dijalankan.'
            );
        }

        return $matches[0];
    }

    /**
     * Deployer agent untuk target volume (multi-server F4).
     *
     * `null`/server lokal tidak pernah sampai di sini; server agent yang tidak
     * dapat dipakai ⇒ exception (bukan fallback lokal).
     *
     * @param array<string,mixed> $target
     * @throws \RuntimeException
     */
    private function agentDeployerForTarget(array $target): AgentDeployer
    {
        $serverId = self::serverIdOf($target);
        $agent = $this->agentDeployerForServer($this->serverResolver()->resolve($serverId));
        if ($agent === null) {
            throw new \RuntimeException(
                "Server \"{$serverId}\" bukan server agent — operasi volume server itu dibatalkan (tidak dijalankan lokal)."
            );
        }

        return $agent;
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

    /**
     * Apakah user login admin global (seam uji; default helper `is_admin()`).
     *
     * Ditulis agar mediator dapat diuji tanpa memuat `app/functions.php`
     * (pola `CreditController`) — perilaku produksi identik dengan `is_admin()`.
     */
    protected function isAdmin(): bool
    {
        return is_admin();
    }

    /**
     * User login saat ini (seam uji; default helper `current_user()`).
     *
     * Session Webman butuh konteks HTTP, jadi tes menyuntik user lewat seam ini
     * (pola `CreditController`).
     */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /**
     * Target backup (volume → app) dari Docker Engine — **satu-satunya jalur
     * endpoint ini yang menyentuh Engine** (PLAN_VOLUME_BACKUP.md §5.3).
     *
     * Method `protected` (bukan `private`) supaya tes dapat meng-override-nya
     * dengan daftar volume tiruan: menguji matriks hak & gerbang kredit tidak
     * boleh menuntut Docker Engine nyata di lingkungan tes.
     *
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    protected function volumeTargets(): array
    {
        $volumes = (new DockerClient())->listVolumes(['label' => [VolumeTargetMap::LABEL_PROJECT]]);

        $apps = (new AppStore())->all();
        $local = VolumeTargetMap::build($volumes, $apps);
        foreach ($local as $index => $target) {
            $local[$index]['server_id'] = ServerStore::LOCAL_ID;
        }

        return array_merge($local, $this->agentVolumeTargets($apps));
    }

    /**
     * Target volume yang hidup di **server agent** (multi-server F4) — dibaca dari
     * server itu (`AgentDeployer::backupVolumes()`), bukan dari Engine host ini.
     *
     * Nama app diambil dari **control plane** (sumber metadata, plan §4.3): baris
     * agent hanya memberi `app_id`; app yang tidak ada di control plane ditandai
     * `orphaned` (perlakukan seperti volume yatim — admin-only).
     *
     * Best-effort: server agent yang tidak dapat dihubungi **dilewati** (halaman
     * `/backups` tetap jalan) — ia tidak pernah membuat control plane menjalankan
     * operasi lokal sebagai pengganti.
     *
     * `protected` supaya tes dapat meng-override tanpa jaringan.
     *
     * @param array<int,array> $apps daftar app control plane
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool,server_id:string}>
     */
    protected function agentVolumeTargets(array $apps = []): array
    {
        $byId = [];
        foreach ($apps as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                $byId[(string) $app['id']] = $app;
            }
        }

        $out = [];
        foreach ($this->agentServers() as $server) {
            $serverId = (string) ($server['id'] ?? '');
            $agent = $this->agentDeployerForServer($server);
            if ($agent === null) {
                continue;
            }

            try {
                $listing = $agent->backupVolumes();
            } catch (\Throwable $e) {
                continue; // server agent tak terjangkau: jangan jatuhkan halaman
            }

            foreach ($listing['volumes'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $appId = (string) ($row['app_id'] ?? '');
                $app = $appId !== '' ? ($byId[$appId] ?? null) : null;

                $out[] = [
                    'name' => $name,
                    'project' => (string) ($row['project'] ?? ($app['name'] ?? '')),
                    'app_id' => $app !== null ? $appId : null,
                    'app_name' => $app !== null ? (string) ($app['name'] ?? '') : null,
                    'orphaned' => $app === null,
                    'server_id' => $serverId,
                ];
            }
        }

        return $out;
    }

    /**
     * Dokumen server **agent** yang aktif (bukan server lokal, bukan yang
     * dimatikan). Best-effort: registry server tak terbaca ⇒ daftar kosong.
     *
     * @return array<int,array<string,mixed>>
     */
    private function agentServers(): array
    {
        try {
            $servers = $this->serverResolver()->all();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($servers as $server) {
            if (!is_array($server) || ServerResolver::isLocal($server) || !ServerResolver::isEnabled($server)) {
                continue;
            }
            $out[] = $server;
        }

        return $out;
    }

    /**
     * Resolver registry server (seam uji) — produksi: store bawaan
     * (`config('deploy.sqlite_file')`).
     */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Deployer agent untuk satu dokumen server (seam uji) — `null` = server lokal.
     *
     * @param array<string,mixed> $server
     */
    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return AgentDeployer::forServer($server);
    }

    /**
     * Fail-fast: op yang belum didukung server agent ⇒ pesan jelas (plan §5).
     *
     * Bila server agent belum pernah melaporkan `capabilities` (daftar kosong),
     * op **tidak** ditolak di sini: worker di server itu sendiri yang memutuskan
     * (`AgentJobController::OPS`), sehingga control plane tidak menebak-nebak
     * kemampuan agent. Yang dipastikan: **tidak** ada fallback ke eksekusi lokal.
     *
     * @throws \RuntimeException
     */
    private function assertAgentSupports(string $serverId, string $op): void
    {
        if (BackupDispatcher::isLocalServer($serverId)) {
            return;
        }

        $server = $this->serverResolver()->resolve($serverId);
        $capabilities = $server['capabilities'] ?? [];
        if (!is_array($capabilities) || $capabilities === []) {
            return; // kemampuan belum diketahui — keputusan di server agent
        }

        if (!in_array($op, array_map('strval', $capabilities), true)) {
            throw new \RuntimeException(
                "Server \"{$serverId}\" belum mendukung op `{$op}` — backup/restore volume di server itu belum tersedia. "
                . 'Operasi dibatalkan (tidak dijalankan di server lokal).'
            );
        }
    }

    /**
     * Id server sebuah baris volume (`''`/`local` = server ini).
     *
     * @param array<string,mixed> $row
     */
    private static function serverIdOf(array $row): string
    {
        $serverId = isset($row['server_id']) && is_scalar($row['server_id']) ? trim((string) $row['server_id']) : '';

        return $serverId === '' ? ServerStore::LOCAL_ID : $serverId;
    }

    /**
     * Service backup untuk mediator ini (seam uji).
     *
     * Method `protected` (bukan `private`) supaya tes dapat meng-inject
     * `VolumeBackupService` dengan `DockerClient` palsu — pola sama dengan
     * `volumeTargets()`: menguji overlay `container_state` live di `status()`
     * tidak boleh menuntut Docker Engine nyata di lingkungan tes.
     */
    protected function backupService(): VolumeBackupService
    {
        return new VolumeBackupService();
    }

    /**
     * Set pesan flash (seam uji; default memakai session).
     */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
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
     * `protected` (bukan private) semata agar unit test dapat meng-override-nya
     * dan memeriksa argumen worker (mis. `server_id` untuk volume server agent)
     * tanpa membuat proses nyata — perilaku produksi tidak berubah.
     *
     * @param array<int,string> $args argumen setelah `cli/backup.php`
     */
    protected function spawnWorker(array $args): bool
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
