<?php
declare(strict_types=1);

namespace app\controller\backup;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\UserStore;
use app\library\Backup\BackupAccess;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupSelection;
use app\library\Backup\VolumeStateGuard;
use app\library\Billing\BackupUsage;
use app\library\Billing\BillingGate;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Billing\ServerRates;
use app\library\Deploy\AgentDeployer;
use app\library\Storage\AppStore;
use app\library\Storage\DbBackup;
use app\library\Support\Markdown;
use support\Request;

/**
 * Halaman & seleksi backup: `/backups`, panduan Restic, daftar snapshot volume,
 * dan toggle backup berkala per volume.
 *
 * Kontrak JSON (kunci stabil — dipakai Frontend UI):
 *   GET  /api/backups/snapshots → {code:0,data:{volume,snapshots:[…]}}
 *   POST /backups/schedule      → {code:0,data:{volume,scheduled,explicit,backup_gb,required}}
 *                                 (hak `backup` = owner/operator; 402 bila kredit kurang)
 *   POST /backups/mode          → {code:0,data:{volume,server_id,mode}}
 *                                 (hak `backup`; tanpa gerbang kredit)
 *   GET  /backups/guide         → halaman panduan setup Restic (admin)
 *
 * Halaman `/backups` mengambil data volume via AJAX
 * (`BackupStatusController::status()`) agar tidak menunggu Engine/restic, dan
 * kartu "Database dashboard (SQLite)" hanya dirender untuk admin
 * (`dbDashboard()`).
 */
class BackupOverviewController extends BackupControllerBase
{
    /**
     * Halaman /backups. Data volume diambil via AJAX
     * (`BackupStatusController::status()`) agar halaman
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
     * (satu pintu, konsisten dengan `BackupStatusController::refresh()`/`setScheduled()`).
     * Best-effort:
     * berkas tak ada/gagal dibaca → `guideHtml = ''` — halaman tetap dirender,
     * panduan tidak pernah menggagalkan halaman.
     */
    public function guide(Request $request)
    {
        if (!is_admin()) {
            return $this->notFound($request);
        }

        return view('backup/guide', [
            'guideHtml' => self::guideHtml(base_path('host/restic-setup.md')),
            'isAdmin' => true,
        ]);
    }

    /**
     * Nyalakan/matikan seleksi backup berkala sebuah volume (opt-in per volume).
     *
     * Otorisasi lewat satu pintu `BackupAccess::require('backup', …)` (owner &
     * operator boleh; viewer/stranger ⇒ `AppAccessDenied` → **404**, bukan 403).
     * Volume yatim tetap admin-only. Target di-resolve dari Engine (bukan dari
     * nama container/volume yang dikirim request) — lihat
     * `BackupControllerBase::volumeTargets()`.
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
            return $this->notFound($request);
        }

        $actor = $this->currentUser();
        $appId = (string) ($target['app_id'] ?? '');
        $app = $this->findApp((new AppStore())->all(), $appId);

        // Otorisasi SEBELUM efek samping apa pun (AppAccessDenied → 404).
        BackupAccess::require('backup', $target, $app, $actor);

        $usage = new BackupUsage();
        $gb = $usage->chargedGigabytes($appId);
        $targetServerId = self::serverIdOf($target);
        $serverRates = new ServerRates();

        if ($scheduled) {
            try {
                $this->assertCanScheduleBackup($gb, $app, $actor, null, null, $targetServerId);
            } catch (InsufficientCredits $e) {
                // Endpoint JSON/AJAX: 402 (bukan redirect) + pesan dari gerbang.
                return json(['code' => 402, 'error' => $e->getMessage()])->withStatus(402);
            }
        }

        $selection = new BackupSelection();
        $actorId = (string) ($actor['id'] ?? '');
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
            'required' => $usage->requiredDeposit($gb, $serverRates->backupMonthlyFor($targetServerId)),
        ]]);
    }

    /**
     * Ubah **mode retensi** sebuah volume (`snapshot` / `sync`).
     *
     * Otorisasi lewat satu pintu `BackupAccess::require('backup', …)` (owner &
     * operator boleh; viewer/stranger ⇒ `AppAccessDenied` → **404**, bukan 403),
     * **sebelum** efek samping apa pun. Volume yatim tetap admin-only. Target
     * di-resolve dari Engine (bukan dari nama volume yang dikirim request) —
     * pola persis {@see self::setScheduled()}.
     *
     * **Tanpa gerbang kredit**: mengubah mode tidak menyalakan backup (hanya
     * mengubah kebijakan retensi), jadi tidak ada biaya baru yang timbul di sini.
     *
     * Kontrak: `POST /backups/mode` → `{code:0,data:{volume,server_id,mode}}`;
     * nama volume / nilai mode tak sah ⇒ **422**; target tak dikenal ⇒ **404**.
     */
    public function setMode(Request $request)
    {
        $volume = trim((string) $request->post('volume', ''));
        try {
            VolumeStateGuard::assertVolumeName($volume);
        } catch (\Throwable $e) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        $mode = trim((string) $request->post('mode', ''));
        if (!in_array($mode, BackupSelection::MODES, true)) {
            return json(['code' => 422, 'msg' => 'Nilai mode harus snapshot atau sync.'])->withStatus(422);
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
            // Termasuk volume DB dashboard (§8i.7) yang bukan target backup app.
            return $this->notFound($request);
        }

        $actor = $this->currentUser();
        $appId = (string) ($target['app_id'] ?? '');
        $app = $this->findApp((new AppStore())->all(), $appId);

        // Otorisasi SEBELUM efek samping apa pun (AppAccessDenied → 404).
        BackupAccess::require('backup', $target, $app, $actor);

        $targetServerId = self::serverIdOf($target);
        $selection = new BackupSelection();
        try {
            $selection->setMode($volume, $mode, (string) ($actor['id'] ?? ''), $targetServerId);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal menyimpan mode backup: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => [
            'volume' => $volume,
            'server_id' => $targetServerId,
            'mode' => $mode,
        ]]);
    }

    /**
     * Gerbang kredit sebelum **menyalakan** backup berkala — semantik sama dengan
     * `AppControllerBase::billingAssertCanStart()` (§7.12), urutannya:
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
    private function assertCanScheduleBackup(
        float $gb,
        ?array $app,
        ?array $user,
        ?BillingGate $gate = null,
        ?UserStore $users = null,
        ?string $serverId = null,
    ): void {
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
        // (pola `AppControllerBase::billingPayerFor()`).
        $payer = $this->billingPayerFor($app ?? [], $user, $users);

        // (3) App milik user bebas tagihan (admin) ⇒ bebas kredit untuk siapa pun.
        if ($payer !== null && $gate->isExempt($payer)) {
            return;
        }

        // (4) Aktor wajib punya saldo — tarif backup **server target**.
        $gate->assertCanBackup($gb, $user, $serverId);

        // (5) Owner yang berbeda dari aktor menanggung biaya akrual ⇒ saldonya
        //     dinilai juga, dengan pesan yang menyebut pemilik app.
        if ($payer === null || (string) ($payer['id'] ?? '') === (string) ($user['id'] ?? '')) {
            return;
        }

        try {
            $gate->assertCanBackup($gb, $payer, $serverId);
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
     * aktor bila `owner_id` kosong/tak diketahui (`AppControllerBase::billingPayerFor()`).
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
            return $this->notFound($request);
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
            return $this->notFound($request);
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('view', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $isLocal = BackupDispatcher::isLocalServer(self::serverIdOf($target));
        try {
            $snapshots = $isLocal
                ? $service->snapshotsFor($volume)
                : $this->agentDeployerForTarget($target)->backupSnapshots($volume);
        } catch (\Throwable $e) {
            // Cabang agent: pesan transport memuat `base_url`/host & token server
            // ⇒ wajib lewat pintu redaksi. Cabang lokal dipertahankan apa adanya
            // (tidak ada identitas server yang mungkin bocor).
            $detail = $isLocal
                ? $e->getMessage()
                : AgentError::sanitizeForServer($e->getMessage(), $this->agentServerOf($target));

            return json(['code' => 500, 'msg' => 'Gagal membaca snapshot: ' . $detail]);
        }

        return json(['code' => 0, 'data' => ['volume' => $volume, 'snapshots' => $snapshots]]);
    }

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
}
