<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\UserStore;
use app\library\Billing\Pricing;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\PortOverrides;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\ServerResolver;
use app\library\Db\DbContainerDetector;
use app\library\Docker\DockerClient;
use app\library\Git\SshKeyManager;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Support\Markdown;
use app\library\Template\TemplateCatalog;
use support\Request;

/**
 * Detail app & data baca-saja halaman detail (docs/SPECS.md §7).
 *
 * Route: `GET /apps/{id}`, `GET /apps/{id}/versions`,
 * `GET /api/apps/{id}/status`.
 */
class InfoController extends AppArtifactControllerBase
{
    public function detail(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'view');

        // Server app bisa SUDAH dihapus dari registry (app yatim). Halaman detail
        // tetap WAJIB terbuka: tab Hapus App adalah satu-satunya aksi yang masih
        // bisa menuntaskan pekerjaan lokal (lihat `delete()`); tanpa ini halaman
        // 500 dan record app tak bisa dibuang sama sekali.
        $deployer = null;
        try {
            $deployer = DeployerFactory::createForApp($app);
        } catch (\Throwable $e) {
            // tidak ada server tujuan: daftar container/volume dibiarkan kosong
        }

        // status container live (best effort — engine/agent mungkin tidak tersedia)
        $live = [];
        try {
            if ($deployer !== null) {
                // Server agent: container dibaca dari agent (`GET /apps/{id}`) — jangan
                // pernah membaca Docker Engine host control plane untuk app remote.
                $live = $deployer instanceof AgentDeployer
                    ? $deployer->getContainersForApp($app)
                    : $deployer->getContainers((string) $app['name']);
            }
        } catch (\Throwable $e) {
            // pakai data tersimpan
        }

        // named volume project (untuk modal konfirmasi delete)
        $volumes = [];
        try {
            if ($deployer !== null) {
                $volumes = $deployer->getProjectVolumes($app['name']);
            }
        } catch (\Throwable $e) {
            // engine tidak tersedia — modal tampil tanpa daftar volume
        }

        // Network eksternal (shared) yang bisa di-join app ini. Kandidat dibaca
        // dari **server tempat app berjalan** (F3c): network server lain BUKAN
        // kandidat — satu network hanya bermakna di satu server, jadi
        // menawarkannya adalah bug lintas-server.
        //
        // Satu instance resolver dipakai ulang untuk seluruh render
        // (`resolveForApp()` + alamat publik server): snapshot registry di-memo
        // per berkas store, jadi tidak ada pembacaan registry berulang.
        $resolver = $this->serverResolver();
        $appServer = null;
        try {
            $appServer = $resolver->resolveForApp($app);
        } catch (\Throwable $e) {
            // server tak dikenal: biarkan daftar kosong (jalur operasi lain yang
            // melaporkan galatnya dengan pesan yang lebih tepat)
        }
        $availableNetworks = $this->availableNetworksFor($store->all(), $appServer);

        // Public key deploy key app (untuk repo private via SSH)
        $sshPubkey = null;
        if (($app['auth_method'] ?? 'none') === 'ssh') {
            $sshPubkey = (new SshKeyManager())->publicKey($app['name']);
        }

        // Panduan template app (opsional). Kegagalan membaca katalog TIDAK boleh
        // menggagalkan halaman detail — panduan hanya pelengkap.
        $guideHtml = '';
        if (is_array($app['template'] ?? null) && (string) ($app['template']['slug'] ?? '') !== '') {
            try {
                $t = (new TemplateCatalog())->find((string) $app['template']['slug']);
                if ($t !== null) {
                    $guideHtml = Markdown::toHtml((string) ($t['guide'] ?? ''));
                }
            } catch (\Throwable $e) {
                // katalog tidak terbaca — halaman detail tetap tampil tanpa panduan
            }
        }

        // Riwayat deploy (terbaru dulu) + versi aktif untuk tombol rollback
        $deployHistory = array_reverse($app['deploy_history'] ?? []);
        $activeSha = $this->resolveActiveSha($app);

        // Container MySQL/MariaDB milik app (untuk tab Database).
        $dbContainers = [];
        try {
            $dbContainers = (new DbContainerDetector())->detectForApp($app);
        } catch (\Throwable $e) {
            // engine tidak tersedia — tab Database tidak muncul
        }

        // Batas CPU/memori per service (tab Container). Server **lokal** membaca
        // base compose di disk (perilaku lama); server **agent** meminta daftar
        // service + nilai base ke agent — control plane tidak punya salinan
        // compose app remote. Satu panggilan HTTP per render detail.
        //
        // App impor TIDAK punya base compose lokal (berkasnya di direktori
        // proyek user): daftar service diambil dari container yang nyata dan
        // form tidak bisa dikelola (canManage=false).
        $limitsDir = (string) config('deploy.apps_path') . '/' . $app['name'];
        $savedLimits = [];
        try {
            $savedLimits = ResourceLimits::of($app);
        } catch (\Throwable $e) {
            // data limits di apps.json tidak valid → form tampil tanpa nilai tersimpan
        }
        $imported = ImportedApp::context($app);
        if ($imported['imported']) {
            $resourceLimits = $this->importedLimitsContext($app, $savedLimits);
        } else {
            $resourceLimits = ($appServer === null || ServerResolver::isLocal($appServer))
                ? $this->limitsContext(
                    $limitsDir,
                    (array) ($app['compose_files'] ?? ['docker-compose.yml']),
                    app_can('limits', $app),
                    $savedLimits
                )
                : $this->agentLimitsContext($app, app_can('limits', $app), $savedLimits);
        }

        // Port container (tab Container): tiap baris = mapping `host:container`;
        // host port adalah identitas baris (tetap, target `proxy_pass`),
        // port container yang boleh diedit. Server lokal membaca override/base
        // compose di disk; server agent membaca metadata `host_ports` (control
        // plane tidak punya artefak app remote).
        $portRows = [];
        $portError = null;
        if (!$imported['imported']) {
            $portLocal = $appServer !== null && ServerResolver::isLocal($appServer);
            try {
                $portRows = PortOverrides::rows(
                    $app,
                    $portLocal ? $limitsDir : '',
                    (array) ($app['compose_files'] ?? [])
                );
            } catch (\Throwable $e) {
                $portError = $this->appErrorText($app, $e);
            }
        }
        $portMappings = [
            'canManage' => app_can('compose', $app) && !$imported['imported'],
            'rows' => $portRows,
            'error' => $portError,
        ];

        // Presentasi subdomain: view memakai `$app['subdomain']` sebagai FQDN.
        // Field tersimpan menyimpan label → kirim FQDN efektif, plus label & flag
        // "eksplisit" agar tab Domain & SSL bisa menampilkan/mengeditnya.
        $viewApp = $app;
        $viewApp['subdomain'] = app_subdomain_of($app);
        $viewApp['subdomain_label'] = app_subdomain_label($app);
        $viewApp['subdomain_custom'] = app_subdomain_is_custom($app);

        // Nama server tempat app berjalan (badge header & baris Info). Hanya
        // `name` server yang dikirim — kolom rahasia registry tidak pernah masuk
        // konteks view (`serverNameForApp()` juga mengembalikan `''` untuk app di
        // server lokal supaya view tetap menampilkan "lokal" seperti sebelumnya).
        $serverName = $this->serverNameForApp($appServer);

        // Alamat publik server pemilik app (target DNS record custom domain).
        // Satu sumber: `ServerAddress` — murni dari registry, **tanpa DNS**
        // (worker persistent tidak boleh menunggu resolver). Hanya pengguna
        // ber-ability `domain` yang melihat tab Domain, jadi role `viewer` tidak
        // perlu membayar pembacaan registry server sama sekali
        // (GOTCHA no. 25: token, PEM, dan URL dasar server tidak pernah masuk
        // konteks view).
        $serverAddress = app_can('domain', $app) ? $this->serverAddressForApp($app, $resolver) : '';

        return $this->renderView('app/detail', [
            'app' => $viewApp,
            'serverName' => $serverName,
            'serverAddress' => $serverAddress,
            'live' => $live,
            'volumes' => $volumes,
            'availableNetworks' => $availableNetworks,
            'sshPubkey' => $sshPubkey,
            'guideHtml' => $guideHtml,
            'deployHistory' => $deployHistory,
            'activeSha' => $activeSha,
            'dbContainers' => $dbContainers,
            'access' => $this->accessContext($app),
            'compose' => $this->composeContext($app),
            'imported' => $imported,
            'resourceLimits' => $resourceLimits,
            'portMappings' => $portMappings,
            'billingEstimate' => $this->billingEstimateFor(
                $savedLimits,
                $this->billingPayerFor($app, current_user()),
                AppStore::serverIdOf($app)
            ),
        ]);
    }

    /**
     * Nama server pemilik app untuk konteks view (badge header & baris Info di
     * `app/detail`). **Hanya** `name` (fallback `id`) yang keluar: token, PEM,
     * `base_url`, dan `notes` tidak pernah ikut — pola sama dengan
     * `ServerResolver::option()` yang dipakai form create.
     *
     * `''` (string kosong) berarti "tidak ada nama server yang perlu ditampilkan":
     * `null` (registry/server tak terbaca) **dan** server lokal — untuk app lokal
     * view sudah menampilkan "lokal" seperti sebelum multi-server, jadi perilaku
     * lama tidak berubah.
     *
     * `protected` = seam uji (dipanggil langsung tanpa HTTP/store).
     *
     * @param array<string,mixed>|null $server baris registry server (boleh memuat rahasia)
     */
    protected function serverNameForApp(?array $server): string
    {
        if ($server === null || ServerResolver::isLocal($server)) {
            return '';
        }

        $name = trim((string) ($server['name'] ?? ''));

        return $name !== '' ? $name : trim((string) ($server['id'] ?? ''));
    }

    /**
     * Konteks tab "Compose" (app mode compose saja): nama file utama, isinya,
     * dan daftar file sumber (untuk editor & daftar file).
     *
     * Master compose app di server **agent** hidup di metadata app
     * (`compose_content`, ditulis {@see ComposeController::saveComposeOnAgent()}) — host ini
     * tidak menyimpan direktori artefaknya, jadi isi di disk (bila ada, mis. app
     * lama hasil wizard) hanya dipakai sebagai cadangan.
     *
     * @return array{main_file:string,content:string,files:array<int,string>}|null
     */
    private function composeContext(array $app): ?array
    {
        // App impor tidak punya editor compose di Rames: berkas aslinya hidup di
        // direktori proyek user (di luar dashboard) — jangan menyajikan editor.
        if (ImportedApp::isImported($app)) {
            return null;
        }

        if (!ComposeSource::isCompose($app)) {
            return null;
        }
        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        $mainFile = ComposeSource::resolveMainFile($dir, $app);
        $stored = $app['compose_content'] ?? null;

        if (is_string($stored) && trim($stored) !== '') {
            return [
                'main_file' => $mainFile,
                'content' => $stored,
                'files' => is_dir($dir) ? ComposeSource::listSourceFiles($dir) : [],
            ];
        }

        return [
            'main_file' => $mainFile,
            'content' => ComposeSource::readMain($dir, $app),
            'files' => ComposeSource::listSourceFiles($dir),
        ];
    }

    /**
     * Halaman khusus riwayat versi app (checkpoint rollback).
     * App mode compose tidak punya checkpoint Git — dialihkan ke detail app.
     */
    public function versions(Request $request, string $id)
    {
        $app = $this->findApp($id, 'view');

        if (ComposeSource::isCompose($app)) {
            flash_set('info', 'App ini dibuat dari file compose (tanpa repo Git) — riwayat versi & rollback tidak tersedia.');
            return redirect('/apps/' . $id);
        }

        return view('app/versions', [
            'app' => $app,
            'deployHistory' => array_reverse($app['deploy_history'] ?? []),
            'activeSha' => $this->resolveActiveSha($app),
        ]);
    }

    public function status(Request $request, string $id)
    {
        $app = $this->findApp($id, 'view');
        return json([
            'code' => 0,
            'app' => [
                'id' => $app['id'],
                'name' => $app['name'],
                'status' => $app['status'] ?? 'unknown',
                'stage' => $app['stage'] ?? null,
                'message' => $app['message'] ?? '',
                'error' => $app['error'] ?? null,
            ],
        ]);
    }

    /**
     * Kandidat network eksternal yang boleh ditawarkan ke form app.
     *
     * Network kandidat dibaca dari **server tempat app berjalan** (F3c): server
     * lokal ⇒ daemon lokal, server agent ⇒ `GET /networks` di server itu. Ini
     * mencegah user memilih network yang tidak ada di server app (yang akan
     * membuat `docker compose up` gagal atau — lebih buruk — menunjuk network
     * host yang salah).
     *
     * @param array<int,array<string,mixed>> $allApps seluruh app (untuk mengecualikan
     *                                               network milik app aktif di server
     *                                               yang sama)
     * @param array<string,mixed>|null       $server  dokumen server app (null = lokal)
     * @param DockerClient|null              $docker  seam uji (null = klien bawaan)
     * @return array<int,string>
     */
    private function availableNetworksFor(array $allApps, ?array $server = null, ?DockerClient $docker = null): array
    {
        $isAgent = $server !== null && !ServerResolver::isLocal($server);
        $serverKey = $isAgent ? (string) ($server['id'] ?? '') : '';

        $available = [];
        try {
            if ($isAgent) {
                $deployer = AgentDeployer::forServer($server);
                $networks = $deployer !== null ? $deployer->networks() : [];
            } else {
                $docker ??= new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
                $networks = $docker->listNetworks();
            }

            $activeNames = [];
            foreach ($allApps as $s) {
                $appServerId = trim((string) ($s['server_id'] ?? ''));
                $appServerId = ($appServerId === '' || $appServerId === ServerStore::LOCAL_ID) ? '' : $appServerId;
                if ($appServerId !== $serverKey) {
                    continue; // network app di server lain tidak relevan
                }
                $activeNames[(string) ($s['name'] ?? '')] = true;
            }

            foreach ($networks as $n) {
                $nname = (string) ($n['Name'] ?? '');
                if ($nname === '' || in_array($nname, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                    continue;
                }
                $project = (string) (($n['Labels'] ?? [])['com.docker.compose.project'] ?? '');
                if ($project !== '' && isset($activeNames[$project])) {
                    continue; // network milik app aktif dikelola lewat app tsb
                }
                $available[] = $nname;
            }
            sort($available);
        } catch (\Throwable $e) {
            // engine/agent tidak tersedia — form tampil tanpa daftar
        }

        return $available;
    }

    /**
     * Konteks `resourceLimits` app **impor**: daftar service diambil dari
     * container nyata (bukan base compose lokal yang memang tidak ada) dan form
     * selalu read-only — Rames tidak menulis override untuk app impor.
     *
     * `limitsContext()` tetap satu-satunya perakit bentuk data; `error` sengaja
     * dikosongkan karena "base compose tidak ditemukan" di host ini adalah
     * kondisi normal untuk app impor, bukan kesalahan yang perlu ditampilkan.
     *
     * @param array<string,mixed>                              $app
     * @param array<string,array{cpus:?float,memory_mb:?int}>  $saved
     * @return array{canManage:bool,services:array<int,string>,saved:array<string,array{cpus:?float,memory_mb:?int}>,repo:array<string,array{cpus:?float,memory_mb:?int}>,hasSaved:bool,error:?string}
     */
    private function importedLimitsContext(array $app, array $saved): array
    {
        $services = [];
        foreach ((array) ($app['containers'] ?? []) as $container) {
            if (!is_array($container)) {
                continue;
            }
            $service = (string) ($container['service_name'] ?? '');
            if ($service !== '' && $service !== 'unknown' && !in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        $context = $this->limitsContext(
            '',
            (array) ($app['compose_files'] ?? []),
            false,
            $saved,
            $services
        );
        $context['error'] = null;

        return $context;
    }

    /**
     * Konteks `resourceLimits` app milik server **agent** — daftar service dan
     * nilai base compose dibaca dari server itu (`GET /apps/{id}/services`, satu
     * panggilan HTTP per render), karena control plane tidak punya salinan
     * artefak app agent.
     *
     * Kegagalan (agent tak terjangkau / agent lama tanpa endpoint / base compose
     * belum ada di server itu) **tidak** menggagalkan halaman: daftar service
     * jatuh ke metadata yang ada (`limits`, lalu `primary_service`) dan pesan
     * ramah masuk `error` — tanpa token/host (disanitasi
     * `AgentError::sanitizeForServer()` lewat `appErrorText()`).
     *
     * @param array<string,mixed>                              $app
     * @param array<string,array{cpus:?float,memory_mb:?int}>  $saved
     * @return array{canManage:bool,services:array<int,string>,saved:array<string,array{cpus:?float,memory_mb:?int}>,repo:array<string,array{cpus:?float,memory_mb:?int}>,hasSaved:bool,error:?string}
     */
    private function agentLimitsContext(array $app, bool $canManage, array $saved): array
    {
        $services = [];
        $repo = [];
        $error = null;

        try {
            $data = $this->agentAppServices($app);
            $services = array_values(array_map('strval', (array) ($data['services'] ?? [])));
            $detected = (array) ($data['detected'] ?? []);

            $agentError = trim((string) ($data['error'] ?? ''));
            if ($agentError !== '') {
                $error = 'Daftar service app tidak terbaca lengkap di server agent: '
                    . $this->appErrorText($app, $agentError);
            }

            foreach ($services as $service) {
                $entry = is_array($detected[$service] ?? null) ? $detected[$service] : [];
                $repo[$service] = [
                    'cpus' => $entry['cpus'] ?? null,
                    'memory_mb' => $entry['memory_mb'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $error = ($e instanceof AgentError && $e->reason === AgentError::NOT_FOUND)
                ? 'Agent di server ini belum mendukung pembacaan daftar service — perbarui agent agar batas resource bisa diatur dari dashboard.'
                : 'Daftar service app tidak bisa dibaca dari server agent: ' . $this->appErrorText($app, $e);
        }

        if ($services === []) {
            // Fallback metadata: service yang sudah punya limit, lalu primary.
            $fallback = array_keys((array) ($app['limits'] ?? []));
            $primary = trim((string) ($app['primary_service'] ?? ''));
            if ($fallback === [] && $primary !== '') {
                $fallback[] = $primary;
            }
            foreach ($fallback as $service) {
                $service = trim((string) $service);
                if ($service !== '' && !in_array($service, $services, true)) {
                    $services[] = $service;
                }
            }
        }

        return $this->limitsContextResult($services, $repo, $canManage, $saved, $error);
    }

    /**
     * Bentuk baku konteks `resourceLimits` untuk view (kontrak view tidak
     * berubah) — satu tempat untuk jalur lokal & agent.
     *
     * @param array<int,string>                               $services
     * @param array<string,array{cpus:?float,memory_mb:?int}> $repo
     * @param array<string,array{cpus:?float,memory_mb:?int}> $saved
     * @return array{canManage:bool,services:array<int,string>,saved:array<string,array{cpus:?float,memory_mb:?int}>,repo:array<string,array{cpus:?float,memory_mb:?int}>,hasSaved:bool,error:?string}
     */
    private function limitsContextResult(
        array $services,
        array $repo,
        bool $canManage,
        array $saved,
        ?string $error
    ): array {
        return [
            'canManage' => $canManage,
            'services' => array_values(array_map('strval', $services)),
            'saved' => $saved,
            'repo' => $repo,
            'hasSaved' => $saved !== [],
            'error' => $error,
        ];
    }

    /**
     * Estimasi kredit **baca-saja** untuk tab "Sumber Daya" di halaman detail —
     * dihitung dari `ResourceLimits::of($app)` (satu sumber aturan limit).
     *
     * `$payer` adalah **penanggung biaya app** (pemilik), **bukan** penonton:
     * `applies` mengikuti apakah pemilik app benar-benar ditagih. App milik admin
     * (bebas tagihan) ⇒ `applies === false` untuk siapa pun penontonnya; app milik
     * member ⇒ `applies === true`. Pemanggil menyerahkan hasil
     * `billingPayerFor($app, current_user())` (satu pembacaan per render).
     *
     * `$serverId` = server tempat app berjalan (opsional; `null` = tarif
     * global) — estimasi memakai tarif server itu.
     *
     * @param array<int|string,mixed> $limits
     * @return array{enabled:bool,applies:bool,estimate_hourly:float,estimate_month:float,days:int,hourly_text:string,month_text:string,format:array{hourly:string,month:string}}
     */
    private function billingEstimateFor(array $limits, ?array $payer = null, ?string $serverId = null): array
    {
        $enabled = (bool) config('deploy.billing_enabled', true);
        $days = (int) config('deploy.billing_min_deposit_days', 30);
        $rates = $this->pricingRatesFor($serverId);
        $hourly = Pricing::hourlyCredits($limits, $rates);
        $month = Pricing::estimate($limits, $rates, $days);

        return [
            'enabled' => $enabled,
            'applies' => $this->isBillingMember($payer),
            'estimate_hourly' => $hourly,
            'estimate_month' => $month,
            'days' => $days,
            'hourly_text' => Pricing::format($hourly),
            'month_text' => Pricing::format($month),
            // Nilai kredit yang sudah diformat (2 desimal) untuk tampilan read-only.
            'format' => [
                'hourly' => Pricing::format($hourly),
                'month' => Pricing::format($month),
            ],
        ];
    }

    /**
     * Data untuk tab "Akses" di halaman detail app.
     *
     * @return array{role:?string, owner:?array, owner_id:string, members:array<int,array>, candidates:array<int,array>, users:array<int,array>, abilities:array<string,bool>}
     */
    private function accessContext(array $app): array
    {
        $users = (new UserStore())->listWithRoles();
        $byId = [];
        foreach ($users as $user) {
            $byId[(string) ($user['id'] ?? '')] = $user;
        }

        $ownerId = (string) ($app['owner_id'] ?? '');
        $memberIds = [];
        $members = [];

        $rawMembers = is_array($app['members'] ?? null) ? $app['members'] : [];
        foreach ($rawMembers as $userId => $entry) {
            $userId = (string) $userId;
            $role = is_array($entry) ? (string) ($entry['role'] ?? '') : (string) $entry;
            if (!in_array($role, AppAccess::ASSIGNABLE_ROLES, true)) {
                continue;
            }
            $memberIds[] = $userId;
            $members[] = [
                'id' => $userId,
                'username' => (string) ($byId[$userId]['username'] ?? $userId . ' (user dihapus)'),
                'role' => $role,
                'added_at' => is_array($entry) ? (string) ($entry['added_at'] ?? '') : '',
                'exists' => isset($byId[$userId]),
            ];
        }

        // Kandidat user yang belum punya akses.
        $candidates = [];
        foreach ($users as $user) {
            $userId = (string) ($user['id'] ?? '');
            if ($userId === $ownerId || in_array($userId, $memberIds, true)) {
                continue;
            }
            $candidates[] = $user;
        }

        $role = AppAccess::roleFor($app, current_user());

        return [
            'role' => $role,
            'owner' => $ownerId !== '' ? ($byId[$ownerId] ?? null) : null,
            'owner_id' => $ownerId,
            'members' => $members,
            'candidates' => $candidates,
            'users' => array_values($byId),
            'abilities' => AppAccess::abilitiesFor($role),
        ];
    }
}
