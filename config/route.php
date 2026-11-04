<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

use app\controller\AgentAdminerController;
use app\controller\AgentController;
use app\controller\AgentDockerController;
use app\controller\AgentFilesController;
use app\controller\AgentJobController;
use app\controller\AgentLogController;
use app\controller\AgentNetworkController;
use app\controller\AgentTerminalController;
use app\controller\AppMetricsController;
use app\controller\AuthController;
use app\controller\backup\BackupArchiveController;
use app\controller\backup\BackupDbController;
use app\controller\backup\BackupOverviewController;
use app\controller\backup\BackupRunController;
use app\controller\backup\BackupStatusController;
use app\controller\BrandingController;
use app\controller\CreditController;
use app\controller\IndexController;
use app\controller\LogController;
use app\controller\MonitorController;
use app\controller\NetworkController;
use app\controller\NginxController;
use app\controller\app\AccessController;
use app\controller\app\ComposeController;
use app\controller\app\ContainerController;
use app\controller\app\CreateController;
use app\controller\app\CreateDeployController;
use app\controller\app\DeploymentController;
use app\controller\app\DomainSettingsController;
use app\controller\app\EnvironmentController;
use app\controller\app\InfoController;
use app\controller\app\ListController;
use app\controller\app\TemplateController;
use app\controller\DatabaseController;
use app\controller\DomainController;
use app\controller\ErrorController;
use app\controller\FileController;
use app\controller\HealthController;
use app\controller\PaymentController;
use app\controller\ProfileController;
use app\controller\server\ProvisionController;
use app\controller\ServerController;
use app\controller\SslController;
use app\controller\TerminalController;
use app\controller\UpdateController;
use app\controller\UserController;
use app\controller\VolumeController;

/*
|--------------------------------------------------------------------------
| Gerbang mode runtime (multi-server F0: satu kode & image, dua peran)
|--------------------------------------------------------------------------
| Mode dibaca dari `config('deploy.mode')` (bersumber env `RAMES_MODE`, lihat
| config/deploy.php):
|
|   - `dashboard` (default) → SELURUH route UI/API dashboard di file ini;
|   - `agent`               → HANYA route mesin agent (diagnostik `/healthz`
|     `/version`, job async `/jobs*`, proyeksi app `/apps/{id}`). Route
|     dashboard (login, apps, nginx, ssl, backups, monitor, database, credits,
|     users, api/*) **tidak didaftarkan sama sekali** — bukan sekadar
|     disembunyikan — sehingga di server agent memang tidak ada permukaan
|     dashboard/UI.
|
| FAIL-CLOSED: route agent hanya didaftarkan bila `AGENT_TOKEN` terisi. Tanpa
| token, server agent tidak mengekspos route apa pun (semua 404) — permukaan
| tanpa token hanya akan jadi pintu terbuka. Penegakan token/nonce sendiri
| datang bersama AgentAuthMiddleware (misi Auth & Security).
|
| Catatan middleware: route agent sengaja TIDAK memasang middleware sesi/CSRF
| apa pun di sini (lihat tests/AgentModeTest.php). Gerbang daftar middleware
| global per mode ada di config/middleware.php — di luar wilayah misi F0 ini.
*/
$ramesMode = config('deploy.mode');
if (!is_string($ramesMode) || $ramesMode === '') {
    // Di luar runtime webman (mis. uji unit) `config()` bisa kosong.
    $ramesMode = 'dashboard';
}
$ramesAgentToken = config('deploy.agent_token');
$ramesAgentToken = is_string($ramesAgentToken) ? $ramesAgentToken : '';

if ($ramesMode === 'agent') {
    if (trim($ramesAgentToken) !== '') {
        Route::get('/healthz', [AgentController::class, 'health']);
        Route::get('/version', [AgentController::class, 'version']);

        // Job async (F1 multi-server, plan §4.1/§11 catatan 7): agent menerima
        // job deploy dari control plane, menjalankannya sendiri lewat
        // `DeployWorkerLauncher` (`cli/deploy.php` detached), lalu dilaporkan
        // lewat polling status + log (tanpa streaming panjang — timeout HTTP
        // ≤30 detik). Semua route tertutup `AgentAuthMiddleware` (token +
        // timestamp + nonce + signature) yang dipasang global di mode agent.
        Route::post('/jobs', [AgentJobController::class, 'create']);
        Route::get('/jobs/{id}', [AgentJobController::class, 'show']);
        Route::get('/jobs/{id}/log', [AgentJobController::class, 'log']);
        // Pembatalan job: hentikan worker + grup proses build di server ini.
        Route::post('/jobs/{id}/cancel', [AgentJobController::class, 'cancel']);

        // Proyeksi app di agent (F1, plan §4.3 sisi pull): control plane menarik
        // state eksekusi app (status/containers/deploy_history/ssl) sesudah job.
        // Whitelist field ada di `AgentJobController::APP_FIELDS` — nilai `env`
        // & deploy key tidak pernah keluar dari agent.
        Route::get('/apps/{id}', [AgentJobController::class, 'showApp']);

        // Nginx (F2 multi-server, plan §5): perakitan config dibaca dari server
        // ini (control plane tidak menyalin logika/path Nginx), dan preflight
        // `ensureWritable()` hanya memeriksa direktori Nginx server ini.
        // Operasi tulis/hapus/reload & SSL **bukan** route tersendiri: semuanya
        // job di `POST /jobs` (op `nginx_write|nginx_remove|nginx_reload|
        // ssl_issue|ssl_revoke`) supaya pipeline & log tetap satu jalur.
        Route::get('/apps/{id}/nginx', [AgentJobController::class, 'renderNginx']);
        // Service + limit base compose **server ini** (batas CPU/memori app
        // agent): control plane tidak punya base compose app remote, jadi form
        // "Batas resource" membaca daftar service dari sini. Read-only, tanpa
        // secret (nama service + angka limit saja).
        Route::get('/apps/{id}/services', [AgentJobController::class, 'services']);
        Route::post('/nginx/check', [AgentJobController::class, 'checkNginx']);

        // Preflight create app (F1 lanjutan): ketersediaan direktori artefak
        // `apps/<name>` di server ini **sebelum** control plane membuat record
        // app, supaya create gagal cepat di wizard (bukan "deploy gagal"
        // sesudah record app terlanjur ada). Read-only, bukan job.
        Route::post('/apps/check', [AgentJobController::class, 'checkApp']);

        // F3a multi-server (plan §5, operasional per server): **log container**
        // & **terminal** app yang berjalan di server ini. Sesi terminal hidup di
        // sini (`DockerExec`); control plane mem-proxy aliran SSE-nya apa adanya
        // (byte passthrough chunked) — sesi TIDAK ditutup saat koneksi SSE klien
        // drop (aturan repo), hanya `POST /terminal/{token}/close`, proses yang
        // keluar, atau prune TTL.
        //
        // Catatan kontrak: `app_id` ikut di query/body setiap panggilan supaya
        // validasi "token milik app ini" tetap satu sumber di
        // `DockerExec::sessionInfo($token, $appId)` — token saja tidak cukup.
        Route::get('/apps/{id}/logs', [AgentLogController::class, 'index']);
        Route::post('/terminal/open', [AgentTerminalController::class, 'openSession']);
        Route::get('/terminal/{token}/stream', [AgentTerminalController::class, 'streamSession']);
        Route::post('/terminal/{token}/input', [AgentTerminalController::class, 'inputSession']);
        Route::post('/terminal/{token}/close', [AgentTerminalController::class, 'closeSession']);
        // Perintah satu-kali (tombol "Run"): dijalankan DI server ini lewat
        // mekanisme yang sama dengan jalur lokal (`DockerExec::runCommand()`:
        // non-interaktif, timeout, stdout/stderr/exit code) — bukan sesi
        // terminal, jadi tidak ada sesi/FIFO yang tertinggal di agent.
        Route::post('/terminal/run', [AgentTerminalController::class, 'runSession']);

        // F3b/F3c multi-server (plan §4.1/§5): **file manager** container & **monitoring**
        // yang dijalankan di server ini. Control plane mem-proxy semuanya
        // (`FileController`/`MonitorController` ⇒ `AgentDeployer`); Docker Engine
        // & `/proc` server ini adalah satu-satunya sumber datanya — host control
        // plane tidak pernah dibaca untuk app remote (plan §3 butir 4).
        //
        // File manager: operasi memakai library yang sama dengan jalur lokal
        // (`ContainerFiles`/`PathGuard`/`FilesInput`/`ArchiveGuard`) sehingga
        // batas 2 MiB editor, 5000 entri listing, tolak biner, dan proteksi
        // zip-slip tetap berlaku; byte ditransfer streaming (unduh: respons
        // berkas; unggah: body biner mentah) dengan plafon
        // `AgentDeployer::MAX_TRANSFER_BYTES`. Berkas temp selalu dibersihkan.
        // `app_id` ikut di query (GET) / body (POST) supaya app dibaca dari
        // proyeksi agent, bukan dari tebakan pemanggil.
        Route::get('/files/list', [AgentFilesController::class, 'list']);
        Route::get('/files/read', [AgentFilesController::class, 'read']);
        Route::get('/files/download', [AgentFilesController::class, 'download']);
        Route::post('/files/write', [AgentFilesController::class, 'write']);
        Route::post('/files/mkdir', [AgentFilesController::class, 'mkdir']);
        Route::post('/files/rename', [AgentFilesController::class, 'rename']);
        Route::post('/files/delete', [AgentFilesController::class, 'delete']);
        Route::post('/files/move', [AgentFilesController::class, 'move']);
        Route::post('/files/upload', [AgentFilesController::class, 'upload']);
        Route::post('/files/extract', [AgentFilesController::class, 'extract']);

        // Monitoring: `/proc` server ini (`/system/host-usage`) + baris
        // monitoring container dari Engine server ini, dirakit
        // `ResourceCollector` di sini (`/monitor/containers`). Penyaringan hak
        // akses (`AppAccess::visible()`) dilakukan control plane, bukan di sini.
        Route::get('/system/host-usage', [AgentDockerController::class, 'hostUsage']);
        Route::get('/monitor/containers', [AgentDockerController::class, 'containers']);
        // Ukuran storage satu app **server ini** (volume project + writable
        // layer container) untuk tab Info detail app di control plane.
        Route::get('/system/disk-usage', [AgentDockerController::class, 'diskUsage']);
        // Ukuran terpakai tiap volume ber-label compose **server ini** — dipakai
        // control plane untuk kolom Ukuran baris volume agent di `/volumes`.
        Route::get('/system/volume-usage', [AgentDockerController::class, 'volumeUsage']);

        // F3d multi-server (plan §4.1/§5): **database/Adminer** & **network/volume**
        // app yang berjalan di server ini. Helper Adminer dijalankan di sini
        // (`AgentAdminerController` ⇒ `AdminerHelper`/`AdminerProxy`, helper tanpa
        // port publik) dan control plane mem-forward permintaannya dengan body
        // mentah + `X-Forwarded-Prefix` — HTML Adminer tidak pernah disunting.
        // Otorisasi (ability `database`) & kepemilikan container diputuskan control
        // plane; agent hanya memvalidasi bentuk nama & memastikan container itu
        // memang container DB server ini.
        //
        // Network & volume = resource Engine **server ini**; endpoint-nya dipakai
        // control plane untuk cakupan per server (shared network hanya bermakna
        // dalam satu server — penolakan lintas server ada di control plane).
        Route::get('/database/containers', [AgentAdminerController::class, 'containers']);
        Route::any('/adminer/{container}[/{path:.*}]', [AgentAdminerController::class, 'proxy']);
        Route::get('/networks', [AgentNetworkController::class, 'index']);
        Route::get('/networks/{id}', [AgentNetworkController::class, 'detail']);
        Route::get('/volumes', [AgentNetworkController::class, 'volumes']);
        Route::post('/networks/create', [AgentNetworkController::class, 'create']);
        Route::post('/networks/{id}/connect', [AgentNetworkController::class, 'connect']);
        Route::post('/networks/{id}/disconnect', [AgentNetworkController::class, 'disconnect']);
        Route::post('/networks/{id}/delete', [AgentNetworkController::class, 'delete']);

        // F4 multi-server (plan §4.3/§5): **data** — daftar volume backup & daftar
        // snapshot restic yang HIDUP di server ini. Control plane memakainya untuk
        // menampilkan volume server ini di halaman `/backups` dan memilih snapshot
        // untuk restore **tanpa** menyentuh daemon/repo restic control plane.
        // Operasi backup/restore/dump-nya sendiri bukan route tersendiri: semuanya
        // job di `POST /jobs` (op `backup_run|restore|db_dump`) supaya pipeline &
        // log tetap satu jalur.
        Route::get('/backups/volumes', [AgentJobController::class, 'backupVolumes']);
        Route::get('/backups/snapshots', [AgentJobController::class, 'backupSnapshots']);
        // Unduh **bertahap** hasil `db_dump` (staging server ini): body = potongan
        // berkas `.sql`, kontrol `offset`/`limit` + header `X-Rames-*`, sehingga
        // control plane dapat meneruskan isi berkas ke pengguna tanpa satu
        // respons raksasa (setiap panggilan HTTP ≤30 s).
        Route::get('/backups/dump', [AgentJobController::class, 'backupDump']);
    }

    // Berhenti di sini: route dashboard di bawah tidak pernah didaftarkan.
    return;
}

/*
|--------------------------------------------------------------------------
| Route publik (tanpa autentikasi)
|--------------------------------------------------------------------------
| / (hello world) dan /login publik; /logout tetap diproses (tidak butuh user login).
|--------------------------------------------------------------------------
*/
Route::get('/login', [AuthController::class, 'loginForm']);
Route::post('/login', [AuthController::class, 'login']);
// Langkah kedua login 2FA TOTP: session `user` belum diisi sebelum kode
// verifikasi lolos, jadi kedua route ini publik (lihat AuthMiddleware).
Route::get('/login/2fa', [AuthController::class, 'twoFactorForm']);
Route::post('/login/2fa', [AuthController::class, 'twoFactorVerify']);
Route::get('/logout', [AuthController::class, 'logout']);

// Kesehatan dashboard (publik, lihat AuthMiddleware::PUBLIC_PATHS): dipakai helper
// self-update untuk memverifikasi versi baru melayani request, + monitoring eksternal.
Route::get('/healthz', [HealthController::class, 'index']);

// Logo brand publik (tanpa login) karena dipakai halaman login sebelum sesi ada.
// Tanpa parameter input: berkas selalu berasal dari store logo (tanpa path/nama
// dari request), responsnya hanya byte gambar brand + ETag untuk revalidasi.
Route::get('/branding/logo', [BrandingController::class, 'logo']);

// Callback pembayaran Duitku (publik & path eksak, docs/SPECS.md §7.12 / plan §5.7):
// tanpa sesi — keamanannya dari signature HMAC + kecocokan amount + idempotensi
// di PaymentController; CSRF dikecualikan hanya untuk POST path ini
// (CsrfMiddleware::isPaymentCallbackPath). Selalu balas 200 agar Duitku tak retry.
Route::post('/payments/duitku/callback', [PaymentController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| Route dilindungi (AuthMiddleware global di config/middleware.php)
|--------------------------------------------------------------------------
*/
Route::get('/', [IndexController::class, 'index']);
Route::get('/apps', [ListController::class, 'index']);

// Wizard create app
Route::get('/apps/create', [CreateController::class, 'createForm']);
Route::post('/apps/create', [CreateController::class, 'createPreview']);
// Mode compose: paste/upload docker-compose.yml tanpa repo Git
Route::post('/apps/create/compose', [CreateController::class, 'composePreview']);
// Mode template: deploy app dari template siap-pakai (compose prebuilt)
Route::get('/apps/create/template/{slug}', [TemplateController::class, 'templateForm']);
Route::post('/apps/create/template/{slug}', [TemplateController::class, 'templateDeploy']);
Route::get('/apps/create/confirm', [CreateDeployController::class, 'confirmForm']);
Route::post('/apps/create/confirm', [CreateDeployController::class, 'confirmCreate']);

// Impor project docker compose yang SUDAH berjalan (admin-only). WAJIB
// didaftarkan SEBELUM pola `/apps/{id}` agar tidak tertangkap sebagai id app.
Route::get('/apps/import', [ListController::class, 'importForm']);
Route::post('/apps/import', [ListController::class, 'importStore']);

// Detail & polling
Route::get('/apps/{id}', [InfoController::class, 'detail']);
Route::get('/apps/{id}/versions', [InfoController::class, 'versions']);
Route::get('/api/apps/{id}/status', [InfoController::class, 'status']);
// Log container app (popup modal di detail app)
Route::get('/api/apps/{id}/logs', [LogController::class, 'index']);
// Log deploy app (popup modal; output build live + tahap worker)
Route::get('/api/apps/{id}/deploy-log', [LogController::class, 'deployLog']);
// Snapshot metrik CPU/RAM + total storage app (tab Info; grafik dirakit browser)
Route::get('/api/apps/{id}/metrics', [AppMetricsController::class, 'show']);

// Aksi app
Route::post('/apps/{id}/rebuild', [DeploymentController::class, 'rebuild']);
// Batalkan deploy/build yang sedang berjalan (hentikan worker + grup proses)
Route::post('/apps/{id}/deploy/cancel', [DeploymentController::class, 'cancelDeploy']);
Route::post('/apps/{id}/rollback', [DeploymentController::class, 'rollback']);
Route::post('/apps/{id}/stop', [DeploymentController::class, 'stop']);
Route::post('/apps/{id}/start', [DeploymentController::class, 'start']);
Route::post('/apps/{id}/delete', [DeploymentController::class, 'delete']);

// Custom domain
Route::post('/apps/{id}/domain/set', [DomainSettingsController::class, 'setDomain']);
Route::post('/apps/{id}/domain/remove', [DomainSettingsController::class, 'removeDomain']);

// Subdomain app (field apps.json `subdomain`; ability `domain`, operator+)
Route::post('/apps/{id}/subdomain', [DomainSettingsController::class, 'setSubdomain']);

// Rute proxy tambahan per app (field apps.json `nginx_routes`, Lapis A)
Route::post('/apps/{id}/routes', [DomainSettingsController::class, 'saveRoutes']);

// Environment variables per app
Route::post('/apps/{id}/env', [EnvironmentController::class, 'saveEnv']);
Route::post('/apps/{id}/env/import', [EnvironmentController::class, 'importEnv']);
Route::post('/apps/{id}/env/upload', [EnvironmentController::class, 'uploadEnv']);

// Compose (app mode compose: edit docker-compose.yml + file pendukung)
Route::post('/apps/{id}/compose', [ComposeController::class, 'saveCompose']);

// External network per app (shared network lintas-app)
Route::post('/apps/{id}/network', [ContainerController::class, 'saveNetworks']);

// Nama container per app (override container_name via compose override)
Route::post('/apps/{id}/container-names', [ContainerController::class, 'saveContainerNames']);

// Port container per app (mapping host:container — override port + op apply_ports).
// Ability `compose` (operator+); app impor ditolak (project compose eksternal
// dikelola user sendiri), server agent menerima metadata `host_ports` + worker.
Route::post('/apps/{id}/ports', [ContainerController::class, 'savePorts']);

// Batas maksimum CPU/memori per service (admin; override compose terpisah)
Route::post('/apps/{id}/limits', [ContainerController::class, 'saveLimits']);

// Kepemilikan & sharing app (owner + members)
Route::post('/apps/{id}/members', [AccessController::class, 'addMember']);
Route::post('/apps/{id}/members/{userId}/remove', [AccessController::class, 'removeMember']);
Route::post('/apps/{id}/owner', [AccessController::class, 'transferOwner']);

// Halaman & reload Nginx host (global — berlaku untuk semua app)
Route::get('/nginx', [NginxController::class, 'index']);
Route::post('/nginx/reload', [NginxController::class, 'reload']);

// Halaman /nginx tab Template (§7.13): tarik image template (admin-only).
Route::post('/nginx/templates/pull', [NginxController::class, 'pullTemplates']);
Route::get('/nginx/templates/pull/status', [NginxController::class, 'pullStatus']);

// Rebranding dashboard (tab Branding di halaman Config): simpan nama + logo,
// atau kembalikan ke default. Keduanya admin-only (non-admin ⇒ 404 di controller)
// dan tetap wajib CSRF (tidak ada pengecualian CsrfMiddleware).
Route::post('/nginx/branding', [NginxController::class, 'saveBranding']);
Route::post('/nginx/branding/reset', [NginxController::class, 'resetBranding']);

// Domain & SSL (global, di luar app; fase 1 server lokal).
// `GET /domains` (tabel "Semua Domain": app + parkir) terbuka untuk semua user
// login — baris app disaring `AppAccess::visible()`, tombol mengikuti ability
// `ssl`. Pengelolaan domain parkir tetap admin-only: non-admin ⇒ 404 (bukan
// 403) dari `DomainController`. /domains/create didaftarkan sebelum pola
// /domains/{id}/edit agar tidak tertukar.
Route::get('/domains', [DomainController::class, 'index']);
Route::get('/api/domains/status', [DomainController::class, 'apiStatus']);
Route::get('/domains/create', [DomainController::class, 'createForm']);
Route::post('/domains', [DomainController::class, 'create']);
Route::get('/domains/{id}/edit', [DomainController::class, 'editForm']);
Route::post('/domains/{id}', [DomainController::class, 'update']);
Route::post('/domains/{id}/delete', [DomainController::class, 'delete']);
// Aktifkan SSL domain parkir (halaman & AJAX) — admin-only (non-admin ⇒ 404).
Route::post('/domains/parked/{id}/ssl', [DomainController::class, 'enableParked']);
Route::post('/api/domains/parked/{id}/enable', [DomainController::class, 'apiEnableParked']);

// Self-update dashboard (panel di halaman /nginx) — cek, jalankan, rollback, status.
// Mutasi hanya admin; status boleh dibaca semua user yang sudah login.
Route::post('/api/update/check', [UpdateController::class, 'check']);
Route::post('/api/update/start', [UpdateController::class, 'start']);
Route::post('/api/update/rollback', [UpdateController::class, 'rollback']);
Route::get('/api/update/status', [UpdateController::class, 'status']);

/*
|--------------------------------------------------------------------------
| Registry server (multi-server, fase F0) — admin-only
|--------------------------------------------------------------------------
| Halaman & aksi untuk server yang dikendalikan dashboard: server lokal (diri
| sendiri, baris seed `local`) + server `kind=agent`. Non-admin ⇒ **404** (bukan
| 403) di semua route di bawah — penjagaan ada di `ServerController` lewat
| `ServerAccess::requireManageServers()` (pola `NginxController`), supaya
| keberadaan registry server tidak bocor ke member.
|
| Semua mutasi lewat POST (kena CSRF & AuthMiddleware global), otorisasi dicek
| SEBELUM efek samping apa pun. `apiIndex` hanya menyajikan data tanpa token/PEM.
| `/servers/create` didaftarkan sebelum `/servers/{id}` agar tidak tertukar.
|--------------------------------------------------------------------------
*/
Route::get('/servers', [ServerController::class, 'index']);
Route::get('/servers/create', [ServerController::class, 'createForm']);
Route::post('/servers', [ServerController::class, 'store']);

// Provisioning server agent lewat Ansible (opsi B) — admin-only ⇒ 404 untuk
// non-admin (dijaga `ProvisionController::guard()`), mutasi POST kena CSRF
// global. Didaftarkan SEBELUM `/servers/{id}` agar `/servers/provision` tidak
// tertangkap sebagai id server.
Route::get('/servers/provision', [ProvisionController::class, 'form']);
Route::post('/servers/provision', [ProvisionController::class, 'store']);
// Buat/baca kunci provisioning bersama (`rames-agent-key`, public key saja).
Route::post('/servers/provision/keypair', [ProvisionController::class, 'keypair']);
// Daftar job provisioning terbaru (JSON) — dipantau dari `/servers` & `/servers/provision`.
Route::get('/servers/provision/jobs', [ProvisionController::class, 'jobList']);
Route::get('/servers/provision/status', [ProvisionController::class, 'status']);
Route::post('/servers/provision/cancel', [ProvisionController::class, 'cancel']);

Route::get('/servers/{id}', [ServerController::class, 'editForm']);
Route::post('/servers/{id}', [ServerController::class, 'update']);
Route::post('/servers/{id}/delete', [ServerController::class, 'delete']);
// Uji koneksi ke agent (JSON; panggilan HTTP ≤30 s ke `GET /healthz` + `/version`).
Route::post('/servers/{id}/test', [ServerController::class, 'testConnection']);
// Perbarui agen di server itu (JSON; job async `self_update` dijalankan DI agen,
// dengan gerbang versi/kapabilitas sebelum efek samping) — F4 lanjutan.
Route::post('/servers/{id}/agent-update', [ServerController::class, 'agentUpdate']);
// Polling status job update agen (JSON, admin-only ⇒ 404 untuk non-admin): rekaman
// job + potongan log baru dari agen, supaya kegagalan preflight di sana terlihat.
Route::get('/servers/{id}/agent-update/status', [ServerController::class, 'agentUpdateStatus']);
// Daftar server untuk JS dashboard (tanpa token/PEM).
Route::get('/api/servers', [ServerController::class, 'apiIndex']);

// SSL otomatis (Let's Encrypt). Tabel SSL kini menyatu di `/domains`; route
// lama dipertahankan sebagai alias kompatibel: `GET /ssl` ⇒ redirect `/domains`,
// sedangkan aksi enable & status JSON tetap dilayani `SslController`.
Route::get('/ssl', [DomainController::class, 'legacySsl']);
Route::post('/ssl/{id}/enable', [SslController::class, 'enable']);
// Status tabel SSL (JSON, read-only) untuk memperbarui halaman `/ssl` di tempat.
Route::get('/api/ssl/status', [SslController::class, 'apiStatus']);
// Aktifkan SSL via JSON (AJAX, tanpa reload; CSRF tetap lewat CsrfMiddleware).
Route::post('/api/ssl/{id}/enable', [SslController::class, 'apiEnable']);

// Volume Docker (lihat & bersihkan volume yatim)
Route::get('/volumes', [VolumeController::class, 'index']);
Route::post('/volumes/purge', [VolumeController::class, 'purge']);
// Ukuran terpakai tiap volume (AJAX — GET /system/df bisa lambat)
Route::get('/api/volumes/usage', [VolumeController::class, 'usage']);

// Backup volume harian ke S3 via restic (PLAN_VOLUME_BACKUP.md §4.1)
Route::get('/backups', [BackupOverviewController::class, 'index']);
// Panduan setup Restic (admin-only) — merender Markdown repo `host/restic-setup.md`
Route::get('/backups/guide', [BackupOverviewController::class, 'guide']);
Route::get('/api/backups/status', [BackupStatusController::class, 'status']);
Route::get('/api/backups/snapshots', [BackupOverviewController::class, 'snapshots']);
Route::post('/backups/run', [BackupRunController::class, 'run']);
Route::post('/backups/restore', [BackupRunController::class, 'restore']);
// Dump logis DB sebuah volume (multi-server F4: di server agent dijalankan **di
// server itu** lewat job `db_dump`; volume lokal memakai jalur worker setempat).
Route::post('/backups/db-dump', [BackupDbController::class, 'dbDump']);
// Unduh berkas `.sql` hasil `db_dump` (staging) sebuah volume — ability `backup`
// (operator+). Multi-server F4: volume server agent diunduh **dari server itu**
// (potongan bertahap), volume lokal dari staging host ini.
Route::get('/backups/dump/sql', [BackupDbController::class, 'downloadDumpSql']);
// Segarkan cache status (live Engine/restic) & seleksi backup berkala per volume
Route::post('/backups/refresh', [BackupStatusController::class, 'refresh']);
Route::post('/backups/schedule', [BackupOverviewController::class, 'setScheduled']);
// Aksi arsip — volume yang sudah dihapus (admin-only, PLAN §8h): daftar snapshot,
// restore ke volume baru (nama diisi user), unduh dump SQL.
Route::get('/api/backups/archive/snapshots', [BackupArchiveController::class, 'archivedSnapshots']);
Route::post('/backups/archive/restore', [BackupArchiveController::class, 'restoreArchived']);
Route::get('/backups/archive/sql', [BackupArchiveController::class, 'downloadArchiveSql']);
// Database dashboard (SQLite) — snapshot/unduh/restore DB dashboard sendiri
// (admin-only, 404 untuk non-admin). Bukan bagian restic/S3.
Route::post('/backups/db/run', [BackupDbController::class, 'dbRun']);
Route::post('/backups/db/prune', [BackupDbController::class, 'dbPrune']);
Route::post('/backups/db/restore', [BackupDbController::class, 'dbRestore']);
Route::get('/backups/db/download', [BackupDbController::class, 'dbDownload']);

// Network Docker (lihat, buat, hubungkan/putuskan container, hapus)
Route::get('/networks', [NetworkController::class, 'index']);
Route::post('/networks/create', [NetworkController::class, 'create']);
Route::get('/networks/{id}', [NetworkController::class, 'detail']);
Route::post('/networks/{id}/connect', [NetworkController::class, 'connect']);
Route::post('/networks/{id}/disconnect', [NetworkController::class, 'disconnect']);
Route::post('/networks/{id}/delete', [NetworkController::class, 'delete']);

// Monitoring resource container & VM (halaman global; nav sidebar)
Route::get('/monitor', [MonitorController::class, 'index']);
Route::get('/api/monitor/overview', [MonitorController::class, 'overview']);
// Snapshot host saja — dipoll halaman /monitor selama terbuka (tanpa Engine)
Route::get('/api/monitor/host', [MonitorController::class, 'host']);

/*
|--------------------------------------------------------------------------
| Database manager (MySQL/MariaDB di container) — daftar container + Adminer
|--------------------------------------------------------------------------
| Halaman global /database (daftar container DB) + reverse proxy Adminer per
| container (fitur D1). Semua POST kena CSRF & AuthMiddleware; otorisasi
| per-container dicek di controller (penolakan → 404).
|--------------------------------------------------------------------------
*/
Route::get('/database', [DatabaseController::class, 'index']);

// Adminer (fitur D1): reverse proxy HTTP ke helper container Adminer (tanpa port
// publik). GET (halaman/aset/unduhan) + POST (query, import multipart) lewat sini;
// AuthMiddleware global tetap berlaku dan AppAccess dicek di controller (404).
Route::any('/database/{container}/adminer[/{path:.*}]', [DatabaseController::class, 'adminer']);

/*
|--------------------------------------------------------------------------
| Terminal container (docker exec interaktif & one-shot run command)
|--------------------------------------------------------------------------
| Stream output = SSE (GET), input/close/run = POST (kebagian CSRF). Semua
| dilindungi AuthMiddleware (401 JSON untuk /api/* bila belum login).
|--------------------------------------------------------------------------
*/
Route::post('/api/apps/{id}/terminal/open', [TerminalController::class, 'open']);
Route::get('/api/apps/{id}/terminal/{token}/stream', [TerminalController::class, 'stream']);
Route::post('/api/apps/{id}/terminal/{token}/input', [TerminalController::class, 'input']);
Route::post('/api/apps/{id}/terminal/{token}/close', [TerminalController::class, 'close']);
Route::post('/api/apps/{id}/terminal/run', [TerminalController::class, 'run']);

/*
|--------------------------------------------------------------------------
| File manager container (jelajah, unggah, unduh, edit, rename, pindah, hapus, mkdir,
| ekstrak arsip). Semua endpoint dilindungi AuthMiddleware; mutasi lewat POST
| (CSRF). Otorisasi ability `files` (operator+) lewat AppAccess.
|--------------------------------------------------------------------------
*/
Route::get('/api/apps/{id}/files', [FileController::class, 'list']);
Route::get('/api/apps/{id}/files/read', [FileController::class, 'read']);
Route::get('/apps/{id}/files/download', [FileController::class, 'download']);
Route::post('/apps/{id}/files/write', [FileController::class, 'write']);
Route::post('/apps/{id}/files/mkdir', [FileController::class, 'mkdir']);
Route::post('/apps/{id}/files/rename', [FileController::class, 'rename']);
Route::post('/apps/{id}/files/delete', [FileController::class, 'delete']);
Route::post('/apps/{id}/files/move', [FileController::class, 'move']);
Route::post('/apps/{id}/files/upload', [FileController::class, 'upload']);
Route::post('/apps/{id}/files/extract', [FileController::class, 'extract']);

/*
|--------------------------------------------------------------------------
| Rekam Error (error klien + exception server 5xx)
|--------------------------------------------------------------------------
| Halaman `/errors` admin-only (non-admin ⇒ 404); laporan klien
| (`POST /api/errors`) wajib login & CSRF (tidak ada pengecualian middleware).
| Semua route ini dashboard-only — mode agent tidak mendaftarkannya.
|--------------------------------------------------------------------------
*/
Route::post('/api/errors', [ErrorController::class, 'report']);
Route::get('/errors', [ErrorController::class, 'index']);
Route::get('/api/errors', [ErrorController::class, 'apiIndex']);
Route::get('/errors/{id}', [ErrorController::class, 'show']);
Route::post('/errors/{id}/status', [ErrorController::class, 'status']);
Route::post('/errors/{id}/delete', [ErrorController::class, 'delete']);

/*
|--------------------------------------------------------------------------
| Manage Users
|--------------------------------------------------------------------------
*/
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'create']);
Route::post('/users/{id}/delete', [UserController::class, 'delete']);
Route::post('/users/{id}/password', [UserController::class, 'changePassword']);
Route::post('/users/{id}/role', [UserController::class, 'changeRole']);
// Email member (dipakai untuk top-up Duitku) — admin set/hapus dari halaman Users.
Route::post('/users/{id}/email', [UserController::class, 'setEmail']);
// Grant pemilihan server per user (flag `server_select` + allowlist `server_ids`).
Route::post('/users/{id}/servers', [UserController::class, 'setServerAccess']);

/*
|--------------------------------------------------------------------------
| Profil self-service (semua user login)
|--------------------------------------------------------------------------
| `/profile` = halaman profil diri sendiri + ubah email/password (pindahan
| form email dari `/credits`). `POST /profile/email` dan
| `POST /profile/password` hanya mengubah data user login
| (anti-IDOR: `id`/`user_id`/`username` dari request diabaikan).
*/
Route::get('/profile', [ProfileController::class, 'index']);
Route::post('/profile/email', [ProfileController::class, 'setEmail']);
Route::post('/profile/password', [ProfileController::class, 'changePassword']);
// 2FA TOTP self-service (anti-IDOR: id user selalu dari session; password
// saat ini + kode verifikasi wajib untuk aksi sensitif).
Route::post('/profile/2fa/setup', [ProfileController::class, 'startTotpSetup']);
Route::post('/profile/2fa/enable', [ProfileController::class, 'enableTotp']);
Route::post('/profile/2fa/disable', [ProfileController::class, 'disableTotp']);
Route::post('/profile/2fa/recovery', [ProfileController::class, 'regenerateTotpRecovery']);

/*
|--------------------------------------------------------------------------
| Kredit, deposit & penagihan (docs/SPECS.md §7.12, plan §5.4/§5.7)
|--------------------------------------------------------------------------
| `deposit`/`charge` admin-only → 404 non-admin. `setEmail` hanya email user
| login sendiri. `topup*` 404 bila fitur/kredensial gateway tidak lengkap.
| `methods` = satu-satunya jalur yang menyentuh jaringan (dipanggil JS saat load).
*/
Route::get('/credits', [CreditController::class, 'index']);
Route::post('/credits/deposit', [CreditController::class, 'deposit']);
Route::post('/credits/charge', [CreditController::class, 'charge']);
Route::post('/credits/email', [CreditController::class, 'setEmail']);
Route::post('/credits/topup', [CreditController::class, 'topup']);
Route::get('/credits/topup/return', [CreditController::class, 'topupReturn']);
Route::get('/api/credits/methods', [CreditController::class, 'methods']);






