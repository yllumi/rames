<?php
declare(strict_types=1);

namespace app\controller\backup;

use app\library\Auth\ServerAccess;
use app\library\Backup\BackupAccess;
use app\library\Backup\BackupCatalog;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupReport;
use app\library\Backup\BackupSelection;
use app\library\Backup\BackupStrategyResolver;
use app\library\Backup\VolumeBackupService;
use app\library\Billing\BackupUsage;
use app\library\Billing\Pricing;
use app\library\Billing\ServerRates;
use app\library\Deploy\AgentDeployer;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Status & cache backup: `GET /api/backups/status` (poll ringan) dan
 * `POST /backups/refresh` (admin-only, live Engine + restic).
 *
 * Kontrak JSON (kunci stabil — dipakai Frontend UI):
 *   GET  /api/backups/status    → {code:0,data:{running,cached_at,status,volumes:[…],archived:[…]}}
 *     Baris `volumes[]` = **cache** (`BackupCatalog`) + **satu** panggilan Engine
 *     read-only (`GET /containers/json?all=1`) yang hanya menyegarkan
 *     `container_state`; Engine gagal ⇒ nilai cache dipakai.
 *   POST /backups/refresh       → bentuk sama dengan `status()`, `cached_at` baru (admin)
 *
 * Tiap baris `volumes[]` menyertakan `scheduled:bool` segar dari
 * `BackupSelection` (bukan dari cache), `can_schedule:bool`
 * (`BackupAccess::can('backup', …)` per baris), biaya backup (`backup_gb`,
 * `backup_credits_month`) — lihat `withScheduled()` — serta `server_name` yang
 * dipetakan **sekali** per respons (`withServerNames()`).
 * `archived[]` (riwayat volume yang sudah dihapus) **admin-only** (keputusan 4a):
 * non-admin selalu menerima `[]`; tiap baris diberi `restorable:bool`
 * (`archivedRows()`).
 *
 * Multi-server F4: baris volume server agent disinkronkan best-effort dengan TTL
 * (`syncAgentRows()` → `AgentDeployer::backupVolumes()`) supaya volume app agent
 * terlihat tanpa menunggu admin, tanpa menghajar server di tiap poll.
 */
class BackupStatusController extends BackupControllerBase
{
    /**
     * TTL sinkron baris volume server agent di `GET /api/backups/status`
     * (detik). Endpoint ini dipolling tiap ±20 dtk; daftar volume agent dibaca
     * live (Engine + hitung snapshot restic server itu) paling sering sekali per
     * TTL, sehingga volume agent **muncul tanpa menunggu admin** tetapi server
     * tidak dihajar tiap poll. Percobaan yang gagal pun dicatat agar server
     * offline tidak dicoba ulang terus-menerus.
     */
    private const AGENT_ROWS_TTL = 300;

    /**
     * Batas server agent yang disinkronkan **per request** status. Agent yang
     * lambat/tak terjangkau bisa memakan timeout transport (±25–30 dtk); dengan
     * batas ini satu poll tidak pernah menahan N×timeout. Server sisanya
     * disinkronkan pada poll berikutnya (TTL server yang sudah dicoba dicatat,
     * jadi server mati tidak diulang terus).
     */
    private const AGENT_ROWS_SYNC_PER_REQUEST = 2;

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
        // Baris volume server agent disegarkan best-effort (TTL) supaya volume
        // app agent terlihat & bisa di-backup tanpa harus menunggu admin menekan
        // "Segarkan status" — dan tetap bertahan setelah run lokal menulis cache.
        $catalog = $this->syncAgentRows($service, $apps, $catalog);

        $user = $this->currentUser();
        $visible = BackupAccess::visible($catalog['volumes'], $apps, $user);
        // Registri control plane = riwayat **sukses** per server. Isi kolom
        // "Backup terakhir" yang belum dilaporkan baris (mis. baris agent yang
        // baru saja di-backup sebelum sinkron TTL berikutnya, atau volume lain
        // pada run terakhir server itu) supaya tidak tampil "belum pernah"
        // padahal snapshot-nya ada.
        $visible = self::withRegistryFallback($visible, $this->registryEntries());
        $status = self::publicStatus((new BackupReport())->readStatus());

        // `container_state` LIVE: satu panggilan Engine read-only untuk seluruh
        // baris **lokal** (gagal ⇒ nilai cache dipertahankan, lihat overlay).
        // Baris server agent TIDAK di-overlay: state-nya milik server itu (dikirim
        // apa adanya dari sana) — Engine host ini tidak tahu apa-apa soal volume itu.
        $rows = self::withScheduled($visible, null, $apps, $user);
        $rows = $this->withServerNames($rows);
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
            return $this->notFound($request);
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
            // Pertahankan baris server agent yang tidak terjangkau saat refresh
            // ini (baris dari server yang ikut terkirim tetap menang).
            (new BackupCatalog((new BackupReport())->dir() . '/catalog.json'))->write($rows, true);
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
            'volumes' => $this->withServerNames(self::withScheduled($visible, null, $apps, $user)),
            'archived' => $this->archivedPayload($service),
        ]]);
    }

    /**
     * Riwayat backup control plane (best-effort; kegagalan = tanpa fallback).
     *
     * @return array<string,array<string,mixed>>
     */
    protected function registryEntries(): array
    {
        try {
            return (new BackupRegistry())->read();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Isi `last_run_at`/`last_ok`/`snapshots` dari riwayat sukses control plane
     * **hanya bila baris belum punya** nilai lebih baru dari status server.
     *
     * Dipakai `status()` untuk baris volume agent yang belum tersinkron ulang
     * (TTL) setelah backup selesai, dan untuk volume yang tidak ikut run
     * terakhir server itu. Murni (tanpa I/O) supaya mudah diuji.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,array<string,mixed>> $registry keluaran `BackupRegistry::read()`
     * @return array<int,array<string,mixed>>
     */
    public static function withRegistryFallback(array $rows, array $registry): array
    {
        if ($rows === [] || $registry === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $entry = $registry[BackupRegistry::scopeKey(self::serverIdOf($row), $name)] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $lastAt = trim((string) ($entry['last_backed_up_at'] ?? ''));
            if ($lastAt !== '' && trim((string) ($row['last_run_at'] ?? '')) === '') {
                $row['last_run_at'] = $lastAt;
                $row['last_ok'] = true;
                $row['last_message'] = null;
            }
            if ((int) ($row['snapshots'] ?? 0) === 0 && (int) ($entry['snapshots'] ?? 0) > 0) {
                $row['snapshots'] = (int) $entry['snapshots'];
            }
            $rows[$index] = $row;
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
     *   - `backup_gb` / `backup_rate` / `backup_credits_month` — biaya snapshot
     *     app baris ini (`BackupUsage` + `Pricing`), `0.0` bila app tak ada/tak
     *     bersnapshot. `backup_rate` = tarif efektif server baris (fallback
     *     global) supaya tooltip JS tidak memakai tarif global yang salah.
     *     Peta GB dibaca **sekali** per pemanggilan (`chargedGigabytesByApp()`),
     *     bukan per baris — `chargedGigabytes($appId)` per baris akan membaca
     *     ulang seluruh `BackupRegistry` tiap baris (N+1; endpoint ini dipolling
     *     tiap ~20 s oleh dashboard). Tarif backup mengikuti **server baris itu**
     *     (`ServerRates`, argumen opsional terakhir); server tanpa tarif jatuh
     *     ke tarif global.
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
        ?ServerRates $serverRates = null,
    ): array {
        $selection ??= new BackupSelection();
        $usage ??= new BackupUsage();
        $serverRates ??= new ServerRates();

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
            // Tarif efektif baris (server itu, fallback global) dikirim terpisah
            // supaya JS tooltip tidak memakai tarif global untuk volume server
            // bertarif khusus.
            $row['backup_rate'] = $serverRates->backupMonthlyFor((string) ($row['server_id'] ?? ''));
            $row['backup_credits_month'] = $usage->monthlyCredits($gb, $row['backup_rate']);
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Sisipkan `server_name` (nama server, bukan id mentah) ke baris volume.
     *
     * UI `/backups` menampilkan kolom Server; tanpa field ini ia hanya punya
     * `server_id` (dua volume bernama sama di dua server jadi tidak dapat
     * dibedakan oleh mata). Peta nama dibaca **sekali** per respons
     * ({@see self::serverNames()}) dan **hanya bila ada baris** — bukan kueri per
     * baris (endpoint ini dipolling dashboard).
     *
     * Baris tanpa `server_id` = volume server lokal. Server tak ada di registry ⇒
     * id dipakai apa adanya (jangan kosongkan: UI tetap bisa membedakan baris).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    protected function withServerNames(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $names = $this->serverNames();

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $serverId = self::serverIdOf($row);
            $row['server_name'] = $names[$serverId] ?? $serverId;
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Peta `server_id ⇒ nama server` (termasuk baris `local`) — dibaca **sekali**
     * per respons, pola sama `ListController::index()`.
     *
     * Best-effort: registry tak terbaca ⇒ peta kosong (baris memakai id apa
     * adanya), status backup tidak pernah gagal karena registry server.
     *
     * `protected` (seam uji) supaya jumlah pembacaan registry dapat dihitung
     * tanpa menyentuh `database/` nyata.
     *
     * @return array<string,string>
     */
    protected function serverNames(): array
    {
        try {
            $servers = $this->serverResolver()->all();
        } catch (\Throwable $e) {
            return [];
        }

        $names = [];
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $id = trim((string) ($server['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($server['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : $id;
        }

        return $names;
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
     * Hanya server yang **boleh dilihat** pemanggil yang dihubungi
     * (`visibleAgentServers()` — satu pintu `ServerAccess::visibleServers()`),
     * sama dengan {@see self::agentVolumeTargets()}; pemanggilnya (`refresh()`)
     * memang admin-only, jadi daftar server tidak berubah untuk admin.
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
        $byId = self::appsById($apps);

        $rows = [];
        foreach ($this->visibleAgentServers($apps) as $server) {
            $agent = $this->agentDeployerForServer($server);
            if ($agent === null) {
                continue;
            }

            try {
                $rows = array_merge($rows, $this->agentRowsForServer($server, $agent, $byId));
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $rows;
    }

    /**
     * Baris satu server agent dari `GET /backups/volumes` server itu, dengan
     * metadata app diambil dari **control plane** (sumber metadata, plan §4.3).
     * Baris mentah agent disanitasi ke kunci yang dikenal `BackupCatalog`.
     *
     * @param array<string,mixed> $server
     * @param array<string,array<string,mixed>> $byId app id ⇒ dokumen app control plane
     * @return array<int,array<string,mixed>>
     * @throws \Throwable server tak terjangkau / kontrak respons salah
     */
    private function agentRowsForServer(array $server, AgentDeployer $agent, array $byId): array
    {
        $serverId = (string) ($server['id'] ?? '');
        $listing = $agent->backupVolumes();

        $rows = [];
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

        return $rows;
    }

    /**
     * Sinkronkan baris volume server agent ke cache dengan **TTL** — hanya
     * server yang boleh dilihat pemanggil (satu pintu `visibleAgentServers()`)
     * dan hanya bila sinkron terakhirnya lebih tua dari {@see self::AGENT_ROWS_TTL}.
     *
     * Tujuannya: volume app server agent terlihat di `/backups` (dan bisa
     * di-backup/dijadwalkan) **tanpa** menuntut admin menekan "Segarkan status",
     * sambil tetap menghormati invarian "polling tidak menyentuh Engine/restic
     * tiap kali": satu panggilan per server per TTL. Kegagalan server dicatat
     * (`touchAgentSync`) agar server offline tidak dicoba ulang tiap poll.
     *
     * Best-effort: kegagalan baca/tulis cache tidak pernah menggagalkan status.
     * `VolumeBackupService::catalogStore()` adalah seam uji (service palsu boleh
     * menonaktifkan sinkron); baris tetap disaring `BackupAccess::visible()`
     * oleh pemanggil.
     *
     * @param array<string,mixed> $catalog hasil `$service->catalog()`
     * @param array<int,array> $apps daftar app control plane
     * @return array<string,mixed> katalog terbaru (atau `$catalog` bila tak bersinkron)
     */
    protected function syncAgentRows(VolumeBackupService $service, array $apps, array $catalog): array
    {
        $store = $service->catalogStore();
        $servers = $this->visibleAgentServers($apps);
        if ($store === null || $servers === []) {
            return $catalog;
        }

        $now = time();
        $synced = $store->agentSyncTimes();
        $byId = self::appsById($apps);
        $changed = false;
        $attempts = 0;

        foreach ($servers as $server) {
            $serverId = trim((string) ($server['id'] ?? ''));
            if ($serverId === '') {
                continue;
            }
            $last = (int) ($synced[$serverId] ?? 0);
            if ($last > 0 && $now - $last < self::AGENT_ROWS_TTL) {
                continue;
            }

            $agent = $this->agentDeployerForServer($server);
            if ($agent === null) {
                continue;
            }

            $attempts++;
            try {
                $rows = $this->agentRowsForServer($server, $agent, $byId);
                try {
                    $store->writeAgentRows($serverId, $rows, $now);
                    $changed = true;
                } catch (\Throwable) {
                    // Cache best-effort: baris lama tetap dipakai.
                }
            } catch (\Throwable $e) {
                try {
                    $store->touchAgentSync($serverId, $now);
                } catch (\Throwable) {
                    // Cache best-effort.
                }
            }

            if ($attempts >= self::AGENT_ROWS_SYNC_PER_REQUEST) {
                break;
            }
        }

        if (!$changed) {
            return $catalog;
        }

        try {
            return $store->read();
        } catch (\Throwable) {
            return $catalog;
        }
    }

    /**
     * @param array<int,array> $apps
     * @return array<string,array<string,mixed>>
     */
    private static function appsById(array $apps): array
    {
        $byId = [];
        foreach ($apps as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                $byId[(string) $app['id']] = $app;
            }
        }

        return $byId;
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
}
