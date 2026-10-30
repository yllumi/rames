<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\BillingGate;
use app\library\Billing\CreditAccount;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Billing\ServerRates;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\AppNginxReloader;
use app\library\Deploy\AppSslRevoker;
use app\library\Deploy\ComposeImporter;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\DeployCanceller;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\EnvManager;
use app\library\Deploy\EnvTemplate;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\ServerResolver;
use app\library\Deploy\SubdomainManager;
use app\library\Db\DbContainerDetector;
use app\library\Docker\AppPorts;
use app\library\Docker\ComposeDiscovery;
use app\library\Docker\ComposeParser;
use app\library\Docker\DockerClient;
use app\library\Docker\PortManager;
use app\library\Git\GitService;
use app\library\Git\SshKeyManager;
use app\library\Nginx\NginxConfigGuard;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxRoutes;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppFilter;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use app\library\Storage\ServerStore;
use app\library\Support\Markdown;
use app\library\System\HostUsage;
use app\library\Template\TemplateCatalog;
use RuntimeException;
use support\Request;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * App Management (docs/SPECS.md §7).
 *
 * Controller hanya mediator — seluruh logika bisnis di app/library.
 * Tidak ada state di properti controller (Webman persistent, lihat
 * copilot-instructions).
 */
class AppController
{
    /**
     * Nama direktori akar untuk **clone analisis sementara** app di server agent
     * (di `sys_get_temp_dir()`): dipakai hanya untuk membaca file compose repo
     * pada langkah analisis wizard create, lalu dibuang — bukan artefak app.
     */
    private const REPO_ANALYSIS_DIR = 'rames-agent-repo-analysis';

    // ==================================================================
    // Daftar & detail
    // ==================================================================

    public function index(Request $request)
    {
        $store = new AppStore();
        $user = current_user();
        $userId = (string) ($user['id'] ?? '');
        $isAdmin = is_admin($user);

        // Hanya app milik sendiri / yang dibagikan ke user ini. Admin melihat
        // semua app (dengan label owner).
        $visible = AppAccess::visible($store->all(), $user);

        // Multi-server: peta `id server → nama` untuk kolom **Server** dan
        // pilihan filter server. Peta dibaca **sekali** (bukan per baris) dari
        // registry server. Non-admin hanya mengenal server yang menaungi app yang
        // boleh ia lihat, ditambah server yang boleh ia pilih bila admin memberi
        // grant `server_select` (`ServerAccess::visibleServers()` — satu pintu).
        // Daftar server lain tidak pernah dikirim ke UI, jadi menyembunyikan
        // pemilih bukan satu-satunya pengaman.
        $serverNames = $this->serverNameMapFor($visible);
        $serverOptions = $this->serverFilterOptions($user, $store->all());

        // Filter `?server=<id>` (query string aman — id server, bukan rahasia).
        // Hanya berlaku untuk server yang boleh dilihat user; id asing diabaikan
        // (bukan oracle keberadaan server).
        $serverFilter = trim((string) $request->get('server', ''));
        $allowedServers = array_fill_keys(array_keys($serverNames), true);
        foreach ($serverOptions as $option) {
            $allowedServers[(string) ($option['id'] ?? '')] = true;
        }
        if ($serverFilter === '' || !isset($allowedServers[$serverFilter])) {
            $serverFilter = '';
        } else {
            $visible = array_values(array_filter(
                $visible,
                static fn (array $a): bool => AppStore::serverIdOf($a) === $serverFilter
            ));
        }

        // Filter `?name=` / `?domain=` / `?owner=` (query string, docs/SPECS.md §8e).
        // Nilai mentah dinormalisasi lebih dulu lewat satu pintu `AppFilter`
        // (trim + lowercase, non-skalar ⇒ kosong) supaya nilai efektif yang
        // dikirim ke view identik dengan yang dipakai penyaringan.
        $filters = AppFilter::normalize([
            'name' => $request->get('name', ''),
            'domain' => $request->get('domain', ''),
            'owner' => $request->get('owner', ''),
        ]);

        // `?owner=` hanya dihormati untuk **admin**: bagi non-admin parameter itu
        // diabaikan total (tidak menyaring, tidak membocorkan app user lain —
        // penyaringan sisi server tetap `AppAccess::visible()`).
        // Id owner di luar peta `user_names()` juga diabaikan (pola sama dengan
        // `?server=<id asing>`): bukan oracle keberadaan user dan tidak
        // menghasilkan halaman kosong.
        $ownerNames = $isAdmin ? user_names() : [];
        if (!$isAdmin || ($filters['owner'] !== '' && !isset($ownerNames[$filters['owner']]))) {
            $filters['owner'] = '';
        }

        // Filter menyempitkan baris **dan** tab count (`counts`), konsisten
        // dengan filter server yang sudah ada.
        if (AppFilter::isActive($filters)) {
            $visible = AppFilter::apply($visible, $filters);
        }

        $roles = [];
        $owned = [];
        foreach ($visible as $app) {
            $id = (string) $app['id'];
            $roles[$id] = (string) (AppAccess::roleFor($app, $user) ?? '');
            $owned[$id] = ((string) ($app['owner_id'] ?? '') === $userId);
        }

        $mine = array_filter($visible, static fn (array $a): bool => $owned[(string) $a['id']] ?? false);
        $shared = array_filter($visible, static fn (array $a): bool => in_array(
            $roles[(string) $a['id']] ?? '',
            [AppAccess::ROLE_OPERATOR, AppAccess::ROLE_VIEWER],
            true
        ));

        // Filter tab: all (default) | mine | shared.
        $scope = (string) $request->get('scope', 'all');
        $apps = match ($scope) {
            'mine' => array_values($mine),
            'shared' => array_values($shared),
            default => $visible,
        };

        // App milik sendiri tampil lebih dulu, selebihnya alfabetis.
        usort($apps, static function (array $a, array $b) use ($owned): int {
            $oa = ($owned[(string) $a['id']] ?? false) ? 0 : 1;
            $ob = ($owned[(string) $b['id']] ?? false) ? 0 : 1;
            return $oa <=> $ob ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        // `apps.json.subdomain` menyimpan label (boleh kosong); view memakai
        // `$app['subdomain']` sebagai FQDN → kirim FQDN efektif. Asal server
        // (nama + kind, bukan id mentah) juga dikirim agar view tidak menebak.
        foreach ($apps as &$app) {
            $app['subdomain'] = app_subdomain_of($app);
            $serverId = AppStore::serverIdOf($app);
            $app['server_name'] = $serverNames[$serverId] ?? $serverId;
            $app['server_kind'] = $serverId === ServerStore::LOCAL_ID ? 'local' : 'agent';
        }
        unset($app);

        return $this->renderView('app/index', [
            'apps' => $apps,
            'roles' => $roles,
            'ownedIds' => $owned,
            'scope' => $scope,
            'counts' => ['mine' => count($mine), 'shared' => count($shared), 'all' => count($visible)],
            'isAdmin' => $isAdmin,
            'ownerNames' => $ownerNames,
            'ownerOptions' => $ownerNames,
            'serverNames' => $serverNames,
            'serverOptions' => $serverOptions,
            'serverFilter' => $serverFilter,
            'filters' => $filters,
            'filterActive' => AppFilter::isActive($filters),
            // Tombol "Impor project compose" hanya untuk admin global (daftar
            // project terdeteksi juga admin-only). Halaman daftar TIDAK memanggil
            // Engine — discovery baru dijalankan di halaman impor.
            'canImport' => $isAdmin,
        ]);
    }

    // ==================================================================
    // Impor project docker compose yang sudah berjalan (admin-only)
    // ==================================================================

    /**
     * Form impor project compose terdeteksi (GET /apps/import).
     *
     * Admin-only (kebijakan "container eksternal hanya admin"): non-admin
     * mendapat **404** lewat {@see AppAccessDenied}, bukan 403.
     *
     * Kegagalan Engine tidak menggagalkan halaman: pesannya dikirim sebagai
     * `engineError` dan halaman tetap 200.
     */
    public function importForm(Request $request)
    {
        if (!is_admin(current_user())) {
            throw new AppAccessDenied();
        }

        $projects = [];
        $primaryChoices = [];
        $engineError = null;

        try {
            $discovery = new ComposeDiscovery(
                new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'))
            );
            $projects = $discovery->projects((new AppStore())->all());
            foreach ($projects as $project) {
                $primaryChoices[(string) $project['project']] = $discovery->primaryChoices($project);
            }
        } catch (\Throwable $e) {
            $engineError = $e->getMessage();
        }

        return $this->renderView('app/import', [
            'projects' => $projects,
            'primaryChoices' => $primaryChoices,
            'isAdmin' => true,
            'engineError' => $engineError,
        ]);
    }

    /**
     * Proses impor project compose (POST /apps/import).
     *
     * Admin-only (404). Seluruh keputusan (discovery ulang, validasi primary,
     * keunikan nama, penyusunan record) ada di {@see ComposeImporter} —
     * controller hanya mediator. Tidak ada deploy/worker yang dijalankan.
     */
    public function importStore(Request $request)
    {
        $user = current_user();
        if (!is_admin($user)) {
            throw new AppAccessDenied();
        }

        $project = trim((string) $request->post('project', ''));
        $primary = trim((string) $request->post('primary', ''));

        try {
            $importer = new ComposeImporter(
                new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')),
                new AppStore()
            );
            $app = $importer->import($project, $primary, (string) ($user['id'] ?? ''));

            $message = 'Project "' . $app['name'] . '" diimpor. Rames hanya memantau — deploy/rebuild tetap di terminal proyek itu.';
            if ($request->expectsJson()) {
                return json(['code' => 0, 'id' => $app['id'], 'message' => $message]);
            }

            flash_set('success', $message);

            return redirect('/apps/' . $app['id']);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }

            flash_set('error', $e->getMessage());

            return redirect('/apps/import');
        }
    }

    /**
     * Peta `id server → nama` untuk **kolom Server** daftar app.
     *
     * Dipanggil dengan app yang sudah disaring `AppAccess::visible()` sehingga
     * untuk non-admin hanya memuat server yang menaungi app yang boleh ia lihat
     * (tidak ada daftar server lain). Registry dibaca **sekali** per request —
     * bukan satu pembacaan per baris app (hindari N+1).
     *
     * Registry tak terbaca ⇒ peta kosong; view memakai id server sebagai
     * fallback tampilan (tidak pernah menggagalkan halaman).
     *
     * @param array<int,array> $apps app yang boleh dilihat user
     * @return array<string,string>
     */
    private function serverNameMapFor(array $apps): array
    {
        $ids = [];
        foreach ($apps as $app) {
            if (is_array($app)) {
                $ids[AppStore::serverIdOf($app)] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $names = [];
        try {
            $servers = $this->serverResolver()->all();
        } catch (\Throwable $e) {
            return $names;
        }

        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $id = (string) ($server['id'] ?? '');
            if ($id === '' || !isset($ids[$id])) {
                continue;
            }
            $name = trim((string) ($server['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : $id;
        }

        return $names;
    }

    /**
     * Pilihan **filter server** untuk halaman `/apps` — satu pintu
     * `ServerAccess::visibleServers()`:
     *  - admin ⇒ semua server;
     *  - pemegang grant `server_select` ⇒ union server app yang terlihat +
     *    server yang boleh dipilih (allowlist);
     *  - member biasa ⇒ `[]` (perilaku lama: filter server tidak muncul).
     *
     * Hanya `id`/`name` yang keluar (tanpa token/PEM/base_url/rates).
     *
     * @param array<int,array> $apps semua app (sumber keputusan visibilitas)
     * @return array<int,array{id:string,name:string}>
     */
    private function serverFilterOptions(?array $user, array $apps): array
    {
        if (!ServerAccess::canSelectServer($user)) {
            return [];
        }

        try {
            $servers = ServerAccess::visibleServers($this->serverResolver()->all(), $user, $apps);
        } catch (\Throwable $e) {
            return [];
        }

        $options = [];
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $id = (string) ($server['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($server['name'] ?? ''));
            $options[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }

        return $options;
    }

    /** Registry server (seam uji: path store temp). */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Render view (seam uji — `view()` butuh konteks HTTP).
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): \Webman\Http\Response
    {
        return view($template, $vars);
    }

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
        $appServer = null;
        try {
            $appServer = (new ServerResolver())->resolveForApp($app);
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

        return $this->renderView('app/detail', [
            'app' => $viewApp,
            'serverName' => $serverName,
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
     * (`compose_content`, ditulis {@see self::saveComposeOnAgent()}) — host ini
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

    // ==================================================================
    // Wizard create — langkah 1 (form + clone + parse + saran port)
    // ==================================================================

    // ==================================================================
    // Server target (multi-server F1) — mediator; keputusan di app\library
    // ==================================================================

    /**
     * Server target pembuatan app — **satu pintu** untuk seluruh wizard create
     * (git/compose/template/konfirmasi).
     *
     * Kontrak (plan §4/§4.2 + keputusan user §9.3, misi `ms-f1-create-server`
     * dan misi grant pemilihan server per user):
     *  1. `server_id` kosong/`local` ⇒ server lokal — perilaku lama persis;
     *  2. pemilihan server selain server lokal divalidasi **satu pintu**
     *     `ServerAccess::maySelect()`: admin boleh semua; pemegang grant
     *     `server_select` boleh server di allowlist (allowlist kosong = semua);
     *     non-grant ditolak **sebelum** id-nya dicari, sehingga keberadaan
     *     server lain tidak bocor (pesan generik — bukan "server tidak
     *     dikenal", dan **tidak** diam-diam memakai server lokal);
     *  3. id tidak dikenal ⇒ `ServerResolver::resolve()` melempar (`fail-fast` —
     *     tidak pernah jatuh ke server lokal);
     *  4. server dinonaktifkan / offline / agent tanpa capability dasar ⇒
     *     `ServerResolver::assertReadyForCreate()` menolak dengan pesan jelas
     *     (untuk capability: jalankan Test connection dulu).
     *
     * Tanpa efek samping: tidak menyentuh store app & tidak menulis berkas.
     * Seluruh keputusan hak ada di `ServerAccess`, kesiapan server di
     * `ServerResolver`; controller hanya mediator.
     *
     * @param string             $requestedId `server_id` mentah dari request (''/absen = lokal)
     * @param array|null         $user        user login (`current_user()`)
     * @param ServerResolver|null $resolver  seam uji (store temp)
     * @return array<string,mixed> dokumen server target
     * @throws RuntimeException server tidak boleh dipilih / tidak dikenal / belum siap
     */
    private function resolveCreateServer(string $requestedId, ?array $user, ?ServerResolver $resolver = null): array
    {
        $requested = trim($requestedId);

        if (!ServerAccess::maySelect($user, $requested)) {
            throw new RuntimeException(
                'Anda tidak berhak memilih server untuk app baru.'
            );
        }

        $server = ($resolver ?? new ServerResolver())->resolve($requested);
        ServerResolver::assertReadyForCreate($server);

        return $server;
    }

    /**
     * Konteks server untuk form wizard — **kontrak view** (dipakai misi
     * Frontend UI; jangan mengganti nama field tanpa memberi tahu):
     *
     *  - `servers`          : daftar pilihan server yang boleh dipakai user ini.
     *                         Admin ⇒ seluruh registry; pemegang grant
     *                         `server_select` ⇒ server lokal + allowlist
     *                         (`ServerAccess::selectableServers()`); user tanpa
     *                         grant ⇒ **hanya server lokal** (nama/host server
     *                         lain tidak pernah dikirim ke browser). Setiap
     *                         entri = `ServerResolver::option()` (`id`, `name`,
     *                         `kind`, `domain`, `enabled`, `status`,
     *                         `capabilities` — tanpa token/PEM/base_url/rates).
     *  - `selectedServerId` : id server terpilih (default server lokal;
     *                         diabaikan bila tidak ada di daftar pilihan).
     *  - `canSelectServer`  : `ServerAccess::canSelectServer(current_user())` —
     *                         UI boleh menyembunyikan selektor, server tetap
     *                         sumber kebenaran.
     *
     * @param array|null         $user             user login (null = belum login)
     * @param string             $selectedServerId id yang sebaiknya terpilih
     * @param ServerResolver|null $resolver        seam uji (store temp)
     * @return array{servers:array<int,array<string,mixed>>,selectedServerId:string,canSelectServer:bool}
     */
    private function serverFormContext(?array $user, string $selectedServerId = '', ?ServerResolver $resolver = null): array
    {
        $resolver ??= new ServerResolver();
        $canSelect = ServerAccess::canSelectServer($user);

        $servers = $canSelect
            ? array_map(
                static fn (array $server): array => ServerResolver::option($server),
                ServerAccess::selectableServers($resolver->all(), $user)
            )
            : [ServerResolver::option($resolver->local())];

        // Baris server lokal wajib ada di daftar (ia pilihan default) — registry
        // yang kehilangan baris lokal di-self-heal di sini, bukan menghasilkan
        // select tanpa opsi terpilih.
        if ($canSelect && !in_array(ServerStore::LOCAL_ID, array_column($servers, 'id'), true)) {
            array_unshift($servers, ServerResolver::option($resolver->local()));
        }

        $selected = AppStore::normalizeServerId($selectedServerId);
        if ($selected !== ServerStore::LOCAL_ID) {
            $allowed = array_column($servers, 'id');
            if (!$canSelect || !in_array($selected, $allowed, true)) {
                $selected = ServerStore::LOCAL_ID;
            }
        }

        return [
            'servers' => array_values($servers),
            'selectedServerId' => $selected,
            'canSelectServer' => $canSelect,
        ];
    }

    /**
     * `server_id` dari request sebagai string aman (array/objek ditolak → `''`
     * = server lokal). Dipakai jalur create; nilai tetap divalidasi ulang
     * `resolveCreateServer()` (nama server dari request tidak pernah dipercaya).
     */
    private function requestedServerId(Request $request): string
    {
        $raw = $request->post('server_id');

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * Konteks alokasi host port pada scope server (multi-server F1, plan §4.2):
     * `PortManager` berentang port **efektif server** + host port yang sudah
     * terpakai **app di server itu** (app di server lain tidak menahan port di
     * sini). Server lokal tidak berubah: rentangnya tetap
     * `config('deploy.port_range')` dan seluruh app lama (tanpa `server_id`)
     * memang ternormalisasi ke server lokal.
     *
     * @param string $serverId id server (''/`local` = server lokal); id tak
     *                         dikenal ⇒ melempar (fail-fast `ServerResolver`)
     * @return array{manager:PortManager,used:array<int,int>}
     */
    private function portContext(string $serverId): array
    {
        $server = (new ServerResolver())->resolve($serverId);
        $range = ServerResolver::portRange($server);
        $manager = new PortManager((int) $range['start'], (int) $range['end']);

        return [
            'manager' => $manager,
            'used' => $manager->usedHostPorts(AppPorts::onServer(
                (new AppStore())->all(),
                (string) ($server['id'] ?? ServerStore::LOCAL_ID)
            )),
        ];
    }

    /**
     * Server target untuk app **yang sudah ada** (jalur app-scoped). Fail-fast
     * bila `server_id` app menunjuk server yang hilang.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function serverForApp(array $app): array
    {
        return (new ServerResolver())->resolveForApp($app);
    }

    /**
     * Dokumen server app untuk **redaksi** pesan galat (bukan keputusan hak).
     *
     * Server hilang dari registry / dokumen cacat ⇒ `[]`;
     * `AgentError::sanitizeForServer()` dengan dokumen kosong berperilaku seperti
     * `sanitize()` biasa, jadi pemanggil tidak perlu bercabang.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function agentServerDocFor(array $app): array
    {
        try {
            return $this->serverForApp($app);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pesan galat aksi app yang tampil ke user (flash/JSON/view).
     *
     * Jalur agent menyisipkan `base_url`/host internal pada galat transport
     * (`AgentClient`) dan blok PEM bisa ikut terpantul — redaksi **wajib** lewat
     * satu pintu `AgentError::sanitizeForServer()`. Untuk app lokal hasilnya
     * no-op (server tanpa `base_url`/token), jadi aman dipakai tanpa percabangan.
     *
     * @param array<string,mixed> $app
     */
    private function appErrorText(array $app, \Throwable|string $error): string
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;

        return AgentError::sanitizeForServer($message, $this->agentServerDocFor($app));
    }

    /**
     * Direktori artefak app **di host ini** untuk jalur yang masih menulis file
     * lokal (network/nama container, dan cabang **lokal** env/limits) — atau
     * `null` + flash error.
     *
     * Multi-server F1/F2: jalur-jalur itu menulis berkas di server lokal, jadi
     * untuk app di server **agent** operasinya ditolak **eksplisit** dengan
     * alasan sesungguhnya ("belum aktif untuk server agent (fase F2/F3)") —
     * bukan pesan menyesatkan "Direktori app tidak ada" yang membuat user
     * mengira app/direktori sudah terhapus. Perilaku server lokal **tidak
     * berubah** (pesan & alur lama).
     *
     * Jalur env & limits kini **server-aware**: pemanggilnya (`saveEnv`,
     * `uploadEnv`, `importEnv`, `saveLimits`) memutuskan lebih dulu berdasarkan
     * `ServerResolver::isLocal()` sehingga app agent tidak lagi sampai ke guard
     * ini (yang berarti menulis artefak lokal untuk server yang salah).
     *
     * @param array<string,mixed> $app
     * @param string              $feature nama fitur untuk pesan galat
     * @return string|null direktori app, atau null bila sudah ditolak (flash error ditulis)
     */
    private function appArtifactDirOrFail(array $app, string $feature): ?string
    {
        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            // `server_id` app menunjuk server yang hilang dari registry: tolak
            // eksplisit (bukan 500 mentah) & jangan sentuh apa pun.
            flash_set('error', $feature . ' tidak bisa dijalankan: ' . $e->getMessage());

            return null;
        }

        if (!$local) {
            flash_set(
                'error',
                $feature . ' belum aktif untuk server agent (fase F2/F3) — jalur ini masih menulis '
                . 'artefak app di server lokal, jadi tidak dijalankan agar tidak menyentuh server yang salah.'
            );

            return null;
        }

        $dir = (string) config('deploy.apps_path') . '/' . (string) ($app['name'] ?? '');
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');

            return null;
        }

        return $dir;
    }

    /**
     * Pesan penolakan pra-deploy untuk aksi yang membaca artefak app **di host
     * ini** (rebuild/rollback) — `null` bila aksi boleh dilanjutkan.
     *
     * Multi-server F1: app milik server **agent** **tidak** diperiksa —
     * checkout repo-nya hidup di server itu (`AgentArtifactMaterializer`), jadi
     * ketiadaan direktori lokal bukan kesalahan dan bukan alasan menolak; worker
     * yang mengerjakan aksinya lewat `AgentDeployer` di server target. Server
     * lokal mempertahankan pesan lamanya persis.
     *
     * @param array<string,mixed> $app
     * @param string              $feature nama aksi untuk pesan galat
     */
    private function localArtifactsError(array $app, string $feature): ?string
    {
        try {
            if (!ServerResolver::isLocal($this->serverForApp($app))) {
                return null;
            }
        } catch (\Throwable $e) {
            // `server_id` app menunjuk server yang hilang dari registry: tolak
            // eksplisit (bukan 500 mentah) — jangan menyentuh host yang salah.
            return $feature . ' tidak bisa dijalankan: ' . $e->getMessage();
        }

        $dir = (string) config('deploy.apps_path') . '/' . (string) ($app['name'] ?? '');

        return is_dir($dir) ? null : 'Direktori app tidak ada. App mungkin sudah dihapus.';
    }

    // ==================================================================
    // Wizard create — langkah 1 (analisis repo / compose / template)
    // ==================================================================

    public function createForm(Request $request)
    {
        $mode = (string) $request->get('mode', 'git');

        // Pilihan server untuk form: admin boleh memilih server mana pun;
        // pemegang grant `server_select` hanya server lokal + allowlist; user
        // lain hanya server lokal (registry server tidak pernah bocor —
        // kontrak `serverFormContext()`).
        $rawServerId = $request->get('server_id');
        $serverContext = $this->serverFormContext(
            current_user(),
            is_scalar($rawServerId) ? (string) $rawServerId : ''
        );

        // Mode template: galeri template app siap-pakai (compose prebuilt,
        // docs/SPECS.md §7.2b). Definisi template dibaca dari folder repo
        // `templates/<slug>/` (dikelola admin lewat git, bukan lewat UI).
        if ($mode === 'template') {
            $templates = (new TemplateCatalog())->all();
            foreach ($templates as &$t) {
                $t['guide_html'] = Markdown::toHtml((string) ($t['guide'] ?? ''));
            }
            unset($t);

            return view('app/create', $serverContext + [
                'mode' => 'template',
                'templates' => $templates,
            ]);
        }

        return view('app/create', $serverContext + [
            'mode' => $mode === 'compose' ? 'compose' : 'git',
        ]);
    }

    /**
     * Wizard create — langkah 1 (mode "Clone repo Git"): validasi input, clone
     * repo, parse compose, simpan `pending_app` di session.
     *
     * Multi-server F1: untuk server **agent** clone di sini hanya dipakai
     * **analisis** (nama file compose + daftar service untuk halaman konfirmasi)
     * dan dibuang lagi — checkout repo app dibuat agent saat menerima job
     * (`AgentArtifactMaterializer`), bukan di host control plane.
     *
     * @param GitService|null $git seam uji: operasi git (null = bawaan)
     */
    public function createPreview(Request $request, ?GitService $git = null)
    {
        $name = strtolower(trim((string) $request->post('name', '')));
        $repoUrl = trim((string) $request->post('repo_url', ''));
        $branch = trim((string) $request->post('branch', 'main'));
        $authMethod = (string) $request->post('auth_method', 'none');
        $subdomain = SubdomainManager::normalize((string) $request->post('subdomain', ''));
        $serverId = (string) ServerStore::LOCAL_ID;
        $git ??= new GitService();

        $keyManager = new SshKeyManager();
        $generatedKey = false;
        // Diisi hanya untuk app di server **agent** (clone analisis sementara);
        // dideklarasikan sebelum `try` supaya `finally` selalu aman.
        $analysisDir = null;

        try {
            $this->validateCreateInput($name, $repoUrl, $branch, $authMethod);

            // Server target (admin, atau member bergrant `server_select`) — fail-fast
            // SEBELUM clone/kunci/berkas apa pun.
            $server = $this->resolveCreateServer($this->requestedServerId($request), current_user());
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
            $localTarget = ServerResolver::isLocal($server);

            $store = new AppStore();
            // Keunikan nama berlaku **per server** (plan §4.2): nama yang sama
            // boleh ada di server berbeda.
            if ($store->nameExistsOnServer($name, $serverId)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai di server ini.");
            }

            // Subdomain opsional — validasi format + keunikan per server sebelum
            // menyentuh disk.
            $this->assertSubdomainAvailable($subdomain, $name, '', $serverId);

            $appsPath = (string) config('deploy.apps_path');
            if ($localTarget) {
                $dest = $appsPath . '/' . $name;
                if (is_dir($dest)) {
                    // area apps_path dikelola sistem — bersihkan lalu clone ulang
                    $this->cleanupDir($dest);
                }
            } else {
                // App di server **agent**: checkout repo TIDAK dibuat di host ini —
                // clone untuk deploy terjadi di agent (`AgentArtifactMaterializer`,
                // satu host dengan container-nya). Analisis langkah ini (nama file
                // compose + daftar service untuk halaman konfirmasi) tetap harus
                // membaca repo, jadi dipakai clone sementara di luar `apps_path`
                // yang dibuang lagi setelah diparse (lihat `finally`).
                $analysisDir = $this->newRepoAnalysisDir($name);
                $dest = $analysisDir;
            }

            // Repo private: generate deploy key SSH sebelum clone. Kalau sudah ada
            // (percobaan ulang setelah user menambah deploy key), dipakai kembali.
            $sshKeyPath = null;
            if ($authMethod === 'ssh') {
                $keyManager->generate($name);
                $generatedKey = true;
                $sshKeyPath = $keyManager->privateKeyPath($name);
            }

            $git->clone($repoUrl, $branch, $dest, $sshKeyPath);

            $composeFile = $git->findComposeFile($dest);
            if ($composeFile === null) {
                $this->cleanupDir($dest);
                throw new RuntimeException('Repo tidak memiliki docker-compose.yml (atau .yaml) di root.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services'], $serverId);

            // simpan data pending (belum commit) di session
            $primary = $this->defaultPrimary($services);
            $pending = [
                'name' => $name,
                'source' => ComposeSource::SOURCE_GIT,
                'repo_url' => $repoUrl,
                'branch' => $branch,
                'local_path' => 'apps/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $primary,
                'primary_port' => $this->defaultPrimaryPort($services, $primary),
                'auth_method' => $authMethod,
                'ssh_key' => $authMethod === 'ssh' ? 'keys/' . $name : null,
                'server_id' => $serverId,
                // Template environment variable untuk form konfirmasi: dari
                // `.env.example` repo, atau variabel ${...} docker compose.
                // Dihitung di sini karena checkout repo (khusus server agent)
                // dibuang di blok `finally`.
                'env_template' => EnvTemplate::detect($dest, [$composeFile]),
            ];
            // Kosong ⇒ jangan tulis field (fallback ke `name`).
            if ($subdomain !== '') {
                $pending['subdomain'] = $subdomain;
            }
            $request->session()->set('pending_app', $pending);

            return redirect('/apps/create/confirm');
        } catch (\Throwable $e) {
            // Repo private via SSH: kalau clone gagal (deploy key belum ditambahkan),
            // tampilkan public key di form agar user bisa menambahkannya ke repo
            // lalu mencoba Analisis Repo lagi (kunci dipakai ulang).
            if ($authMethod === 'ssh' && $keyManager->exists($name)) {
                return view('app/create', [
                    'mode' => 'git',
                    'sshPubkey' => $keyManager->publicKey($name),
                    'auth_method' => $authMethod,
                    'preview_error' => $e->getMessage(),
                    'form_name' => $name,
                    'form_repo_url' => $repoUrl,
                    'form_branch' => $branch,
                // Server terpilih di form = id yang **diminta** request: `$serverId`
                // masih bernilai default server lokal bila `resolveCreateServer()`
                // gagal, sehingga pilihan user akan ter-reset. `serverFormContext()`
                // menormalkan id tak dikenal/tak boleh dipakai kembali ke lokal.
                ] + $this->serverFormContext(current_user(), $this->requestedServerId($request)));
            }
            if ($generatedKey) {
                $keyManager->remove($name);
            }
            flash_set('error', $e->getMessage());

            // Pilihan server yang gagal tidak boleh ikut hilang: form create (mode
            // git) membacanya dari query `server_id`. Syarat & pola query sama
            // dengan tautan nav view (berhak memilih, id non-kosong, bukan `local`)
            // sehingga URL tanpa parameter tetap berarti server lokal.
            $requestedServerId = $this->requestedServerId($request);
            $mayPickServer = ServerAccess::canSelectServer(current_user())
                && $requestedServerId !== ''
                && strtolower($requestedServerId) !== ServerStore::LOCAL_ID;
            $serverQuery = $mayPickServer ? '?server_id=' . rawurlencode($requestedServerId) : '';

            return redirect('/apps/create' . $serverQuery);
        } finally {
            // Clone analisis app di server agent bersifat sekali pakai: jangan
            // meninggalkan checkout repo di host control plane (berhasil maupun
            // gagal) — checkout sesungguhnya hidup di agent.
            if ($analysisDir !== null) {
                $this->cleanupDir($analysisDir);
            }
        }
    }

    /**
     * Wizard create — mode "Compose": nama app + file `docker-compose.yml`
     * (paste di textarea atau upload) + file pendukung opsional, tanpa repo Git.
     *
     * Ditujukan untuk app yang memakai image prebuilt (tanpa build context).
     * File ditulis ke `apps/{name}` (seperti mode clone), compose di-parse untuk
     * deteksi port, lalu memakai halaman konfirmasi yang sama.
     */
    public function composePreview(Request $request)
    {
        $name = strtolower(trim((string) $request->post('name', '')));
        $composeContent = (string) $request->post('compose', '');
        $subdomain = SubdomainManager::normalize((string) $request->post('subdomain', ''));
        $dest = '';
        $serverId = (string) ServerStore::LOCAL_ID;

        try {
            $this->validateCreateName($name);

            // Server target (admin, atau member bergrant `server_select`) — fail-fast
            // SEBELUM menulis berkas apa pun.
            $server = $this->resolveCreateServer($this->requestedServerId($request), current_user());
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);

            $store = new AppStore();
            // Keunikan nama per server (plan §4.2).
            if ($store->nameExistsOnServer($name, $serverId)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai di server ini.");
            }

            // Subdomain opsional — validasi format + keunikan per server sebelum
            // menulis file.
            $this->assertSubdomainAvailable($subdomain, $name, '', $serverId);

            // area apps_path dikelola sistem — bersihkan lalu tulis ulang file
            $dest = (string) config('deploy.apps_path') . '/' . $name;
            if (is_dir($dest)) {
                $this->cleanupDir($dest);
            }
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                throw new RuntimeException('Gagal membuat direktori app.');
            }

            ComposeSource::store(
                $dest,
                $this->uploadsFrom($request),
                $composeContent,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES)
            );

            $composeFile = ComposeSource::detectMainFile($dest);
            if ($composeFile === '') {
                throw new RuntimeException('File docker-compose.yml tidak ditemukan setelah upload.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services'], $serverId);

            $pending = [
                'name' => $name,
                'source' => ComposeSource::SOURCE_COMPOSE,
                'repo_url' => null,
                'branch' => null,
                'local_path' => 'apps/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $this->defaultPrimary($services),
                'primary_port' => $this->defaultPrimaryPort($services, $this->defaultPrimary($services)),
                'auth_method' => 'none',
                'ssh_key' => null,
                'server_id' => $serverId,
                // Template env dari file upload (`.env.example` bila ikut
                // di-upload) atau variabel ${...} di compose.
                'env_template' => EnvTemplate::detect($dest, [$composeFile]),
            ];
            if ($subdomain !== '') {
                $pending['subdomain'] = $subdomain;
            }
            $request->session()->set('pending_app', $pending);

            return redirect('/apps/create/confirm');
        } catch (\Throwable $e) {
            // Gagal sebelum konfirmasi → direktori app dibersihkan, form dirender
            // ulang dengan isi yang sudah ditulis user (tidak hilang).
            if ($dest !== '' && is_dir($dest)) {
                $this->cleanupDir($dest);
            }

            return view('app/create', [
                'mode' => 'compose',
                'compose_error' => $e->getMessage(),
                'form_name' => $name,
                'form_compose' => $composeContent,
            // Server terpilih = id yang diminta request, bukan `$serverId` yang masih
            // default lokal saat `resolveCreateServer()` gagal (pilihan user ter-reset).
            ] + $this->serverFormContext(current_user(), $this->requestedServerId($request)));
        }
    }

    // ==================================================================
    // Wizard create — mode Template (galeri app siap-pakai)
    // ==================================================================

    /**
     * Form deploy template (langkah tunggal): nama app + field env yang
     * dideklarasikan template. Port/primary/prefix container tidak ditanyakan —
     * port diresolusi otomatis (konflik digeser ke port bebas), primary
     * diambil dari deklarasi template, prefix nama container = nama app
     * (docs/SPECS.md §7.2b).
     */
    public function templateForm(Request $request, string $slug)
    {
        $catalog = new TemplateCatalog();
        $template = $catalog->find($slug);
        if ($template === null) {
            flash_set('error', 'Template "' . $slug . '" tidak ditemukan.');
            return redirect('/apps/create?mode=template');
        }
        if (!$template['valid']) {
            flash_set('error', 'Template "' . $slug . '" tidak valid: ' . $template['error']);
            return redirect('/apps/create?mode=template');
        }

        // Server target untuk estimasi biaya: id yang diminta, tetapi hanya bila
        // user memang berhak memilihnya (id di luar jangkauan ⇒ estimasi lokal,
        // bukan membocorkan tarif/keberadaan server lain).
        $user = current_user();
        $requestedServer = is_scalar($request->get('server_id')) ? (string) $request->get('server_id') : '';
        $previewServerId = ServerAccess::maySelect($user, AppStore::normalizeServerId($requestedServer))
            ? AppStore::normalizeServerId($requestedServer)
            : ServerStore::LOCAL_ID;

        return view('app/template', $this->serverFormContext(
            $user,
            $requestedServer
        ) + [
            'template' => $template,
            'guide_html' => Markdown::toHtml((string) ($template['guide'] ?? '')),
            'form_name' => (string) $request->get('name', $template['slug']),
            'form_env' => [],
            'form_error' => null,
            'resourceLimits' => $this->limitsContext(
                (string) $template['dir'],
                [TemplateCatalog::COMPOSE_FILE],
                current_user() !== null,
                [],
                array_map('strval', array_keys((array) ($template['services'] ?? [])))
            ),
            'billing' => $this->billingContext(
                $this->defaultLimitsFor(array_map('strval', array_keys((array) ($template['services'] ?? [])))),
                $user,
                null,
                null,
                $previewServerId
            ),
            'limitsScaleMax' => $this->limitsScaleMax(),
        ]);
    }

    /**
     * Deploy app dari template (POST): satu klik, langsung spawn worker deploy.
     *
     * Urutan: validasi template/nama/env → materialisasi file template ke
     * `apps/{name}` → parse compose & resolusi host port → tulis override
     * port/nama + env → simpan entri app → worker deploy. Kegagalan sebelum
     * entri app dibuat membersihkan direktori app (tidak ada state setengah jadi).
     */
    public function templateDeploy(Request $request, string $slug)
    {
        $catalog = new TemplateCatalog();
        $template = $catalog->find($slug);
        $name = strtolower(trim((string) $request->post('name', '')));
        $subdomain = SubdomainManager::normalize((string) $request->post('subdomain', ''));
        $envInput = (array) $request->post('env', []);
        $env = [];
        $generated = [];
        $limits = [];
        $serverId = (string) ServerStore::LOCAL_ID;

        // Fase 1 — validasi yang tidak menyentuh disk (template, nama, nilai env).
        try {
            if ($template === null) {
                throw new RuntimeException('Template "' . $slug . '" tidak ditemukan.');
            }
            if (!$template['valid']) {
                throw new RuntimeException('Template "' . $slug . '" tidak valid: ' . $template['error']);
            }
            $this->validateCreateName($name);

            // Server target (admin, atau member bergrant `server_select`), lalu keunikan
            // nama & subdomain **per server** (plan §4.2).
            $server = $this->resolveCreateServer($this->requestedServerId($request), current_user());
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);

            if ((new AppStore())->nameExistsOnServer($name, $serverId)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai di server ini.");
            }
            $this->assertSubdomainAvailable($subdomain, $name, '', $serverId);
            $env = $catalog->resolveEnv($template, $envInput);
            $generated = $catalog->generatedKeys($template, $envInput);

            // Batas CPU/memori: owner/member boleh memilih saat create (D2=a),
            // dibatasi plafon billing. Admin bebas (billing bukan untuk admin).
            $limits = $this->resolveLimits(
                (array) $request->post('limits', []),
                array_map('strval', array_keys((array) ($template['services'] ?? [])))
            );
            $user = current_user();
            if ($this->isBillingMember($user)) {
                Pricing::assertWithinCaps($limits);
                if ($limits === []) {
                    // Anti-lubang harga: app member selalu punya limit nyata.
                    $limits = $this->defaultLimitsFor(
                        array_map('strval', array_keys((array) ($template['services'] ?? [])))
                    );
                }
            }

            // Gerbang kredit — fail-fast SEBELUM materialisasi/efek samping;
            // tarif mengikuti server target yang sudah di-resolve.
            $this->billingAssertCanCreate($limits, $user, null, $serverId);
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        } catch (\Throwable $e) {
            // Template tidak ada/rusak → tidak ada form yang bisa dirender ulang.
            if ($template === null || !$template['valid']) {
                flash_set('error', $e->getMessage());
                return redirect('/apps/create?mode=template');
            }

            // Kartu "Batas Sumber Daya" wajib ikut dirender ulang — kontrak sama
            // dengan templateForm(). View mem-prefill kolom dari `repo`, jadi
            // nilai yang dikirim user ditempatkan di sana juga agar tidak hilang.
            // Server terpilih juga dipulihkan dari request (bukan `$serverId` yang
            // masih default lokal saat resolve gagal).
            $postedLimits = $this->prefillLimits((array) $request->post('limits', []));
            $limitsContext = $this->limitsContext(
                (string) $template['dir'],
                [TemplateCatalog::COMPOSE_FILE],
                current_user() !== null,
                $postedLimits,
                array_map('strval', array_keys((array) ($template['services'] ?? [])))
            );
            foreach ($postedLimits as $service => $limit) {
                $limitsContext['repo'][$service] = $limit;
            }

            // Server untuk estimasi biaya: server yang diminta bila user berhak
            // memilihnya, selain itu server lokal hasil resolusi (default).
            $asked = AppStore::normalizeServerId($this->requestedServerId($request));
            $previewServerId = ServerAccess::maySelect(current_user(), $asked) ? $asked : $serverId;

            return view('app/template', $this->serverFormContext(
                current_user(),
                $this->requestedServerId($request)
            ) + [
                'template' => $template,
                'form_name' => $name,
                'form_env' => $envInput,
                'form_error' => $e->getMessage(),
                'resourceLimits' => $limitsContext,
                'billing' => $this->billingContext(
                    $postedLimits === []
                        ? $this->defaultLimitsFor(array_map('strval', array_keys((array) ($template['services'] ?? []))))
                        : $postedLimits,
                    current_user(),
                    null,
                    null,
                    $previewServerId
                ),
                'limitsScaleMax' => $this->limitsScaleMax(),
            ]);
        }

        // Fase 2 — materialisasi + pembuatan app.
        $dest = '';
        $app = null;
        $spawned = false;
        try {
            $dest = (string) config('deploy.apps_path') . '/' . $name;
            if (is_dir($dest)) {
                $this->cleanupDir($dest); // area apps_path dikelola sistem
            }
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                throw new RuntimeException('Gagal membuat direktori app.');
            }
            $catalog->materialize(
                $template,
                $dest,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES)
            );

            $composeFile = ComposeSource::detectMainFile($dest);
            if ($composeFile === '') {
                throw new RuntimeException('File docker-compose.yml tidak ditemukan setelah template disalin.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services'], $serverId);
            // Template tanpa `ports:` (mis. server database) tidak punya primary:
            // app dibuat tanpa vhost/subdomain (SPECS §7.2b).
            $tplPrimaryService = (string) ($template['primary']['service'] ?? '');
            $tplPrimaryPort = (int) ($template['primary']['port'] ?? 0);
            $primary = $this->resolvePrimarySelection(
                $services,
                $tplPrimaryService !== '' ? $tplPrimaryService . ':' . $tplPrimaryPort : '',
                $tplPrimaryService,
                $tplPrimaryPort
            );

            // Template dilarang menulis container_name → prefix = nama app.
            $containerPrefix = $this->resolveContainerPrefix($name, $composeFile, $name, $serverId);

            $pending = [
                'name' => $name,
                'source' => ComposeSource::SOURCE_COMPOSE,
                'repo_url' => null,
                'branch' => null,
                'local_path' => 'apps/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $primary['service'],
                'primary_port' => $primary['port'],
                'auth_method' => 'none',
                'ssh_key' => null,
                'server_id' => $serverId,
            ];
            if ($subdomain !== '') {
                $pending['subdomain'] = $subdomain;
            }

            $result = $this->createAndDeploy(
                $pending,
                $services,
                $primary['service'],
                $primary['port'],
                $containerPrefix,
                $env,
                ['slug' => $template['slug'], 'title' => $template['title']],
                $limits,
                $subdomain
            );
            $app = $result['app'];
            $spawned = $result['spawned'];
        } catch (\Throwable $e) {
            // Belum ada entri app (createAndDeploy menulis file dulu, baru
            // apps.json) → direktori app aman dibersihkan.
            if ($app === null && $dest !== '' && is_dir($dest)) {
                $this->cleanupDir($dest);
            }
            // `createAndDeploy()` dapat memanggil agent (preflight nama app):
            // pesan transport memuat `base_url`/host server — redaksi dulu.
            $errorText = $this->appErrorText(['server_id' => $serverId], $e);
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $errorText]);
            }
            flash_set('error', $errorText);

            return view('app/template', $this->serverFormContext(current_user(), $this->requestedServerId($request)) + [
                'template' => $template,
                'form_name' => $name,
                'form_env' => $envInput,
                'form_error' => $errorText,
                'limitsScaleMax' => $this->limitsScaleMax(),
            ]);
        }

        $note = $generated !== []
            ? ' Nilai ' . implode(', ', $generated) . ' dibuat otomatis — lihat tab Environment untuk menyalin/mengubahnya.'
            : '';

        if ($request->expectsJson()) {
            return json([
                'code' => $spawned ? 0 : 1,
                'id' => $app['id'],
                'name' => $app['name'],
                'error' => $spawned ? null : 'Gagal menjalankan worker deploy.',
            ]);
        }

        if (!$spawned) {
            flash_set('error', 'App "' . $app['name'] . '" dibuat, tetapi worker deploy gagal dijalankan. Coba Deploy Ulang dari halaman detail.');
            return redirect('/apps/' . $app['id']);
        }

        flash_set('success', 'App "' . $app['name'] . '" dari template ' . $template['title'] . ' sedang di-deploy.' . $note);
        return redirect('/apps/' . $app['id']);
    }

    /**
     * Resolusi konflik host port untuk daftar service hasil parse compose
     * (dipakai ketiga mode create — clone repo, compose, template).
     *
     * Scope server (multi-server F1): rentang port & port terpakai mengikuti
     * **server target** app baru — lihat {@see self::portContext()}.
     *
     * @param array<string,array> $services
     * @param string              $serverId id server target (''/`local` = lokal)
     * @return array<string,array>
     */
    private function resolveServicePorts(array $services, string $serverId = ''): array
    {
        $context = $this->portContext($serverId);

        return $context['manager']->resolve($services, $context['used']);
    }

    /**
     * Apakah minimal satu service mem-publikasikan port (kandidat `proxy_pass`)?
     * App yang tidak punya port sama sekali dibuat tanpa vhost/subdomain, jadi
     * direktori Nginx tidak perlu bisa ditulis (SPECS §7.2).
     *
     * @param array<string,array> $services
     */
    private function hasDeclaredPorts(array $services): bool
    {
        foreach ($services as $svc) {
            if (is_array($svc) && !empty($svc['ports'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalisasi file unggahan dari request (Webman UploadFile / struktur
     * $_FILES) menjadi daftar entri yang dipahami ComposeSource::store().
     *
     * @return array<int,array{name:string,tmp_name:string,size:int,error:int}>
     */
    private function uploadsFrom(Request $request): array
    {
        $files = $request->file('files');
        if (!is_array($files) || $files === []) {
            return [];
        }

        $result = [];
        foreach (array_values($files) as $file) {
            if ($file instanceof \Webman\Http\UploadFile) {
                $name = trim((string) $file->getUploadName());
                // Input file kosong dikirim browser dengan nama kosong → abaikan.
                if ($name === '') {
                    continue;
                }
                $path = (string) $file->getPathname();
                $size = 0;
                try {
                    $size = (int) $file->getSize();
                } catch (\Throwable $e) {
                    $size = 0; // file gagal di-upload → error code yang menentukan
                }
                $result[] = [
                    'name' => $name,
                    'tmp_name' => $file->isValid() ? $path : '',
                    'size' => $size,
                    'error' => (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE),
                ];
                continue;
            }
            if (is_array($file)) {
                foreach (ComposeSource::normalizeUploads($file) as $entry) {
                    if (trim((string) ($entry['name'] ?? '')) === '') {
                        continue;
                    }
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    // ==================================================================
    // Wizard create — langkah 2 (konfirmasi port & primary service)
    // ==================================================================

    public function confirmForm(Request $request)
    {
        $pending = $request->session()->get('pending_app');
        if (!$pending) {
            return redirect('/apps/create');
        }

        // Server target yang dipilih di langkah analisis (kosong = server lokal).
        // Validasi ulang fail-fast: registry server bisa berubah di antara dua
        // langkah (server dihapus/dinonaktifkan) — jangan sampai halaman
        // konfirmasi menampilkan FQDN/limit untuk server yang salah.
        $serverId = (string) ServerStore::LOCAL_ID;
        try {
            $server = $this->resolveCreateServer((string) ($pending['server_id'] ?? ''), current_user());
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
        } catch (\Throwable $e) {
            $request->session()->delete('pending_app');
            flash_set('error', $e->getMessage());
            return redirect('/apps/create');
        }
        $pending['server_id'] = $serverId;

        // Base compose sudah ada di disk (hasil clone/upload langkah analisis).
        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];

        // Data subdomain untuk ditampilkan/diedit di halaman konfirmasi: FQDN
        // efektif + label + flag "eksplisit" (field terisi, bukan fallback name).
        // FQDN-nya milik **server target** (keputusan 4: `{label}.{domain server}`).
        $label = SubdomainManager::normalize((string) ($pending['subdomain'] ?? ''));
        $asIf = ['name' => (string) $pending['name'], 'subdomain' => $label, 'server_id' => $serverId];

        return view('app/confirm', $this->serverFormContext(current_user(), $serverId) + [
            'pending' => $pending,
            'subdomain' => app_subdomain_of($asIf),
            'subdomain_label' => $label,
            'subdomain_custom' => $label !== '',
            // Template environment variable (dari `.env.example` repo atau
            // referensi ${...} compose) + draft isian user bila create gagal.
            'envTemplate' => is_array($pending['env_template'] ?? null)
                ? $pending['env_template']
                : ['source' => EnvTemplate::SOURCE_NONE, 'vars' => []],
            'envDraft' => is_array($pending['env_draft'] ?? null) ? $pending['env_draft'] : [],
            'resourceLimits' => $this->limitsContext(
                $dir,
                [(string) $pending['compose_file']],
                current_user() !== null,
                [],
                array_map('strval', array_keys((array) ($pending['services'] ?? [])))
            ),
            'billing' => $this->billingContext(
                $this->defaultLimitsFor(array_map('strval', array_keys((array) ($pending['services'] ?? [])))),
                current_user(),
                null,
                null,
                $serverId
            ),
            'limitsScaleMax' => $this->limitsScaleMax(),
        ]);
    }

    public function confirmCreate(Request $request)
    {
        $pending = $request->session()->get('pending_app');
        if (!$pending) {
            return redirect('/apps/create');
        }

        $serviceInput = (array) $request->post('services', []);
        $primarySelection = (string) $request->post('primary', '');

        // Isian environment variable dari halaman konfirmasi. Template-nya
        // dideteksi di langkah analisis (`createPreview()`/`composePreview()`)
        // dan disimpan di session — POST hanya membawa nilainya.
        $envInput = $this->collectCreateEnvInput($request);
        $envTemplate = is_array($pending['env_template'] ?? null) ? $pending['env_template'] : [];

        // Server target: form konfirmasi boleh mengirim ulang `server_id`
        // (dipilih di langkah pertama); selain itu pilihan tersimpan di session
        // yang dipakai. Validasi fail-fast SEBELUM efek samping apa pun.
        $postedServerId = $request->post('server_id') === null
            ? (string) ($pending['server_id'] ?? '')
            : $this->requestedServerId($request);

        try {
            $server = $this->resolveCreateServer($postedServerId, current_user());
        } catch (\Throwable $e) {
            // Sengaja render ulang dari `pending_app` (id langkah analisis), bukan
            // id kiriman: langkah konfirmasi tidak punya pemilih server di UI
            // (hanya hidden input), jadi jalan ini hanya bisa dicapai dengan
            // memanipulasi POST — fail-fast + target deploy tetap pilihan yang
            // sudah divalidasi lebih aman daripada diam-diam memakai id kiriman.
            $this->persistEnvDraft($request, $pending, $envInput);
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }
        $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
        $pending['server_id'] = $serverId;

        // Prefix nama container divalidasi lebih dulu supaya pesan errornya kembali
        // ke halaman konfirmasi (pending_app masih tersimpan), bukan ke form create.
        try {
            $containerPrefix = $this->resolveContainerPrefix(
                (string) $pending['name'],
                (string) $pending['compose_file'],
                (string) $request->post('container_prefix', ''),
                $serverId
            );
        } catch (\Throwable $e) {
            $this->persistEnvDraft($request, $pending, $envInput);
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }

        try {
            $services = $this->validateAndApplyPorts($pending['services'], $serviceInput, $serverId);
            $primary = $this->resolvePrimarySelection(
                $services,
                $primarySelection,
                (string) ($pending['primary_service'] ?? ''),
                (int) ($pending['primary_port'] ?? 0)
            );

            // Subdomain: form konfirmasi boleh menimpanya (bila UI mengirim field);
            // selain itu pakai nilai yang sudah divalidasi di langkah analisis.
            $subdomain = $request->post('subdomain') !== null
                ? SubdomainManager::normalize((string) $request->post('subdomain'))
                : SubdomainManager::normalize((string) ($pending['subdomain'] ?? ''));
            $this->assertSubdomainAvailable($subdomain, (string) $pending['name'], '', $serverId);

            // Environment variable: validasi isian + pastikan variabel yang
            // "wajib" menurut template tidak dibiarkan kosong. Dilakukan sebelum
            // efek samping apa pun (credit gate, pembuatan direktori app).
            $env = EnvTemplate::resolveValues($envInput, $envTemplate);

            // Batas CPU/memori: owner/member boleh memilih saat create (D2=a),
            // dibatasi plafon billing. Admin bebas (billing bukan untuk admin).
            $limits = $this->resolveLimits(
                (array) $request->post('limits', []),
                array_map('strval', array_keys((array) ($pending['services'] ?? [])))
            );
            $user = current_user();
            if ($this->isBillingMember($user)) {
                Pricing::assertWithinCaps($limits);
                if ($limits === []) {
                    // Anti-lubang harga: app member selalu punya limit nyata.
                    $limits = $this->defaultLimitsFor(
                        array_map('strval', array_keys((array) ($pending['services'] ?? [])))
                    );
                }
            }

            // Gerbang kredit — fail-fast SEBELUM efek samping apa pun; tarif
            // mengikuti server target yang sudah di-resolve.
            $this->billingAssertCanCreate($limits, $user, null, $serverId);

            $result = $this->createAndDeploy(
                $pending,
                $services,
                $primary['service'],
                $primary['port'],
                $containerPrefix,
                $env,
                null,
                $limits,
                $subdomain
            );
            $app = $result['app'];
            $spawned = $result['spawned'];

            $request->session()->delete('pending_app');

            // Panggilan AJAX (fetch) mengembalikan JSON; form biasa tetap redirect.
            if ($request->expectsJson()) {
                return json([
                    'code' => $spawned ? 0 : 1,
                    'id' => $app['id'],
                    'name' => $app['name'],
                    'error' => $spawned ? null : 'Gagal menjalankan worker deploy.',
                ]);
            }

            flash_set('success', 'App "' . $app['name'] . '" sedang di-deploy.');
            return redirect('/apps/' . $app['id']);
        } catch (InsufficientCredits $e) {
            $this->persistEnvDraft($request, $pending, $envInput);
            return $this->billingBlocked($request, $e);
        } catch (\Throwable $e) {
            // Redirect balik ke halaman konfirmasi tidak membawa POST: simpan
            // isian env user sebagai draft supaya form terisi lagi.
            $this->persistEnvDraft($request, $pending, $envInput);
            // `createAndDeploy()` dapat memanggil agent (preflight nama app):
            // pesan transport memuat `base_url`/host server — redaksi dulu.
            $errorText = $this->appErrorText($pending, $e);
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $errorText]);
            }
            flash_set('error', $errorText);
            return redirect('/apps/create/confirm');
        }
    }

    /**
     * Kumpulkan isian environment variable dari halaman konfirmasi.
     *
     * Form mengirim dua bentuk:
     *   - `env[KEY]=value`                → variabel hasil deteksi template
     *   - `env_extra[i][key]` + `[value]` → variabel tambahan yang diketik user
     *
     * @return array<string,mixed>
     */
    private function collectCreateEnvInput(Request $request): array
    {
        $input = [];
        $primary = $request->post('env', []);
        if (is_array($primary)) {
            foreach ($primary as $key => $value) {
                $input[(string) $key] = $value;
            }
        }

        $extra = $request->post('env_extra', []);
        if (is_array($extra)) {
            foreach ($extra as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $input[$key] = $row['value'] ?? '';
            }
        }

        return $input;
    }

    /**
     * Simpan isian env sebagai draft di session `pending_app` agar tidak hilang
     * saat controller me-redirect kembali ke halaman konfirmasi.
     *
     * @param array<string,mixed> $pending
     * @param array<string,mixed> $envInput
     */
    private function persistEnvDraft(Request $request, array $pending, array $envInput): void
    {
        if ($envInput === []) {
            return;
        }
        $pending['env_draft'] = $envInput;
        $request->session()->set('pending_app', $pending);
    }

    /**
     * Buat app + spawn worker deploy — jalur bersama halaman konfirmasi (mode
     * git/compose) dan deploy template.
     *
     * Semua penulisan file (override port/nama container melalui
     * `writeOverride()`, managed env file + override env melalui `EnvManager`)
     * selesai SEBELUM entri `apps.json` dibuat, sehingga kegagalan tidak
     * meninggalkan app setengah jadi — pemanggil cukup membersihkan direktori
     * app. Env sengaja juga dipersist ke `apps.json` agar `EnvManager::sync()`
     * di `LocalDeployer` tidak menghapus file env saat deploy berjalan.
     *
     * Preflight ketersediaan nama app di server **target** dijalankan paling awal
     * (`DeployerFactory::assertAppTargetAvailable()`, no-op untuk server lokal):
     * app di server agent tidak boleh lahir kalau direktori `apps/<name>` di sana
     * berisi data yang bukan milik Rames.
     *
     * @param array<string,array>       $services  hasil ComposeParser + resolusi host port
     * @param array<string,string>      $env       map KEY => value (kosong = tanpa env)
     * @param array<string,string>|null $template  metadata asal template (bila dibuat dari template)
     * @param array<string,array{cpus:?float,memory_mb:?int}> $limits batas CPU/memori per service (kosong = tidak diatur)
     * @param string|null               $subdomain label subdomain eksplisit (null/'' = tanpa field, fallback `name`)
     * @param DeployWorkerLauncher|null $launcher  seam uji: peluncur worker (null = bawaan)
     * @param AgentClient|null          $agentClient seam uji: klien agent untuk preflight
     *                                  nama app di server target (null = dibangun dari
     *                                  dokumen server, seperti produksi)
     * @return array{app:array,spawned:bool}
     */
    private function createAndDeploy(
        array $pending,
        array $services,
        string $primaryService,
        int $primaryPort,
        string $containerPrefix,
        array $env = [],
        ?array $template = null,
        array $limits = [],
        ?string $subdomain = null,
        ?DeployWorkerLauncher $launcher = null,
        ?AgentClient $agentClient = null
    ): array {
        // Server target app baru (`$pending['server_id']`, absen/kosong = lokal).
        // Fail-fast bila id server sudah tidak ada di registry.
        $server = (new ServerResolver())->resolveForApp($pending);

        // Id app dibuat **di sini**, bukan diserahkan ke `AppStore::create()`:
        // preflight di bawah harus membandingkan id yang persis sama dengan yang
        // nanti dipakai penanda kepemilikan/`dropStaleDir()` di server agent.
        // Formatnya sama dengan `AppStore::create()` (`bin2hex(random_bytes(8))`).
        $appId = bin2hex(random_bytes(8));

        // Preflight ketersediaan nama/direktori app di server target SEBELUM efek
        // samping apa pun (termasuk record app di store): server agent yang
        // direktori `apps/<name>`-nya berisi data tanpa penanda Rames menolak di
        // sini, sehingga wizard menampilkan pesan yang bisa ditindaklanjuti —
        // bukan "deploy gagal" sesudah record app terlanjur dibuat. Server lokal
        // = no-op ({@see DeployerFactory::assertAppTargetAvailable()}).
        //
        // Dokumen minimal: hanya field yang dipakai keputusan "nama ini bisa
        // dipakai?" (`source`/`repo_url`/`template`) + identitas app. `repo_url`
        // diperlukan agent untuk membandingkan remote checkout yang **sudah ada di
        // sana** (read-only) — tanpa itu app repo yang pernah di-deploy dilaporkan
        // bentrok. `env`, deploy key, dan token tidak pernah ikut.
        $preflightApp = [
            'id' => $appId,
            'name' => (string) $pending['name'],
            'source' => (string) ($pending['source'] ?? ComposeSource::SOURCE_GIT),
        ];
        $repoUrl = is_scalar($pending['repo_url'] ?? null) ? trim((string) $pending['repo_url']) : '';
        if ($repoUrl !== '') {
            $preflightApp['repo_url'] = $repoUrl;
        }
        if (is_array($template) && is_scalar($template['slug'] ?? null) && trim((string) $template['slug']) !== '') {
            $preflightApp['template'] = ['slug' => trim((string) $template['slug'])];
        }

        DeployerFactory::assertAppTargetAvailable($server, $preflightApp, $agentClient);

        // Fail-fast: pastikan direktori Nginx dapat ditulis sebelum deploy —
        // hanya bila ada port yang akan di-proxy ke domain. App tanpa `ports:`
        // tidak membuat vhost, jadi direktori Nginx tidak relevan (SPECS §7.2).
        //
        // Prasyarat itu milik host INI, jadi hanya berlaku untuk server lokal:
        // untuk server `kind=agent` vhost dibuat di server itu (fase F2) dan
        // `AgentDeployer::ensureWritable()` memang melempar "belum didukung" —
        // karena itu tidak dipanggil (bukan diam-diam memakai direktori lokal).
        if ($this->hasDeclaredPorts($services) && ServerResolver::isLocal($server)) {
            DeployerFactory::createFor($server)->ensureWritable();
        }

        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];
        $agentServer = !ServerResolver::isLocal($server);

        // Rencana host port pilihan user (server **agent**): disimpan di metadata
        // app (`host_ports`) dan dikirim `AgentArtifactPackager` lewat
        // `artifacts.files` saat job berangkat — host ini **tidak** menulis
        // berkas override untuk app di server agent (misi F1 celah B).
        $portPlan = $agentServer ? $this->hostPortPlan($services) : [];

        // Artefak lokal app (override port/nama container, batas CPU/memori,
        // override env) hanya bisa ditulis bila direktori app lokal memang ada di
        // host ini **dan app memang milik server ini**. App **repo** milik server
        // **agent** tidak punya direktori itu — checkout-nya dibuat agent
        // (`AgentArtifactMaterializer`), jadi penulisan lokal **dilewati** dengan
        // alasan yang benar (bukan gagal "Gagal menulis
        // docker-compose.override.yml"); override env/limits/nama container
        // dibuat worker di agent dari metadata app ini.
        $localArtifacts = is_dir($dir) && !$agentServer;

        $composeFiles = [$pending['compose_file']];
        if ($agentServer) {
            $composeFiles = $this->agentComposeFiles(
                (string) $pending['compose_file'],
                $portPlan,
                $containerPrefix,
                $limits,
                $env,
                (array) ($pending['compose_files'] ?? [])
            );
        } else {
            if ($localArtifacts) {
                $this->writeOverride($pending, $services, $composeFiles, $containerPrefix);
            }

            // Batas CPU/memori (admin saja) — file override ditulis SEBELUM entri
            // apps.json dibuat; kegagalan ⇒ pemanggil membersihkan direktori app.
            if ($localArtifacts && ResourceLimits::writeOverride($dir, [$pending['compose_file']], $limits)) {
                $composeFiles[] = ResourceLimits::OVERRIDE_FILE;
            }

            if ($env !== [] && $localArtifacts) {
                $composeFiles[] = EnvManager::OVERRIDE_FILE;
                $composeFiles = $this->orderComposeFiles($composeFiles);

                $envManager = new EnvManager();
                $envManager->write((string) $pending['name'], $env);
                $envManager->writeOverride($dir, $composeFiles, $env);
            } else {
                // Nilai env tetap dipersist di `apps.json` (`$appData['env']`) supaya
                // worker di server target bisa menulis override-nya sendiri.
                $composeFiles = $this->orderComposeFiles($composeFiles);
            }
        }

        $appData = [
            'id' => $appId,
            'name' => $pending['name'],
            // Server pemilik app (multi-server F1): selalu eksplisit — kosong
            // ternormalisasi ke server lokal oleh `AppStore::create()`.
            'server_id' => AppStore::normalizeServerId($pending['server_id'] ?? ''),
            'owner_id' => (string) (current_user()['id'] ?? ''),
            'members' => [],
            'source' => $pending['source'] ?? ComposeSource::SOURCE_GIT,
            'repo_url' => $pending['repo_url'] ?? null,
            'branch' => $pending['branch'] ?? null,
            'local_path' => $pending['local_path'],
            'primary_service' => $primaryService,
            'primary_port' => $primaryPort,
            'status' => 'deploying',
            'stage' => 'queued',
            'message' => 'Menunggu worker deploy ...',
            'compose_files' => $composeFiles,
            'container_prefix' => $containerPrefix !== '' ? $containerPrefix : null,
            'env' => $env,
            'limits' => $limits !== [] ? $limits : null,
            'template' => $template,
            'needs_ssl' => false,
            'ssl_status' => null,
            'auth_method' => $pending['auth_method'] ?? 'none',
            'ssh_key' => $pending['ssh_key'] ?? null,
            'containers' => [],
        ];

        // Rencana host port (server agent) ikut metadata app: `AgentArtifactPackager`
        // membangkitkan override port dari field ini saat job berangkat, sehingga
        // agent memakai pilihan user — bukan port bawaan repo (misi F1 celah B).
        if ($portPlan !== []) {
            $appData['host_ports'] = $portPlan;
        }

        // Subdomain opsional: kosong ⇒ field tidak ditulis (fallback ke `name`).
        if ($subdomain !== null && $subdomain !== '') {
            $appData['subdomain'] = $subdomain;
        }

        $app = (new AppStore())->create($appData);

        $spawned = $this->spawnWorker($app['id'], 'deploy', null, $this->currentActorId(), $launcher);
        if (!$spawned) {
            (new AppStore())->update($app['id'], function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker deploy. Cek log & coba Rebuild.';
                $s['error'] = 'Gagal spawn worker deploy.';
            });
        }

        return ['app' => $app, 'spawned' => $spawned];
    }

    // ==================================================================
    // Polling status (API)
    // ==================================================================

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

    // ==================================================================
    // Aksi: rebuild / stop / start / delete
    // ==================================================================

    public function rebuild(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        // App impor dikelola `docker compose` di terminal user — Rames tidak
        // menulis override & tidak menciptakan ulang container.
        try {
            ImportedApp::assertManageable($app, 'Rebuild');
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Tolak rebuild ganda saat app sedang diproses worker lain.
        if (($app['status'] ?? '') === 'deploying') {
            $msg = 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg, 'busy' => true]);
            }
            flash_set('error', $msg);
            return redirect('/apps/' . $id);
        }

        $blocked = $this->localArtifactsError($app, 'Rebuild');
        if ($blocked !== null) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $blocked]);
            }
            flash_set('error', $blocked);
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum mengubah status / spawn worker.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        $store->update($id, function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rebuild ...';
            $s['error'] = null;
        });

        if (!$this->spawnWorker($id, 'rebuild', null, $this->currentActorId())) {
            $store->update($id, function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rebuild.';
                $s['error'] = 'Gagal spawn worker rebuild.';
            });
            $msg = 'Gagal menjalankan rebuild.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);
        } else {
            if ($request->expectsJson()) {
                return json([
                    'code' => 0,
                    'status' => 'deploying',
                    'stage' => 'queued',
                    'message' => 'Rebuild dimulai.',
                ]);
            }
            flash_set('success', 'Rebuild dijalankan.');
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Batalkan deploy/rebuild/rollback yang sedang berjalan.
     *
     * Bukan sekadar reset status: `DeployCanceller` menghentikan worker (grup
     * proses, termasuk `docker compose`/build) dan — untuk app server agent —
     * membatalkan job di agent lebih dulu. Status app dipulihkan (`running`
     * bila container lama masih hidup, selain itu `stopped`) sehingga UI tidak
     * tertahan di `deploying` meski worker sudah mati tanpa jejak.
     *
     * Otorisasi: ability `deploy` (Operator ke atas) — sama dengan tombol
     * rebuild/rollback. App tanpa hak tetap **404** lewat `findApp()`.
     */
    public function cancelDeploy(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        if (($app['status'] ?? '') !== 'deploying') {
            $msg = 'Tidak ada deploy yang sedang berjalan untuk app ini.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg, 'idle' => true]);
            }
            flash_set('error', $msg);

            return redirect('/apps/' . $id);
        }

        // Job di server agent harus dihentikan di sana; bila konfigurasi server
        // cacat, jangan gagalkan pembatalan worker lokal — laporkan sebagai
        // peringatan (build di agent mungkin masih berjalan).
        $agent = null;
        $agentError = '';
        try {
            $agent = AgentDeployer::forApp($app);
        } catch (\Throwable $e) {
            $agentError = $this->appErrorText($app, $e);
        }

        $user = current_user();
        $actor = trim((string) ($user['username'] ?? ($user['id'] ?? '')));

        try {
            $result = $this->deployCanceller($app)->cancelApp($app, $agent, $actor);
        } catch (\Throwable $e) {
            $msg = 'Gagal membatalkan deploy: ' . $this->appErrorText($app, $e);
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);

            return redirect('/apps/' . $id);
        }

        // Peringatan bisa berasal dari agent (mis. `cancelJob` transport) —
        // identitas server tetap wajib dibuang sebelum masuk flash/JSON.
        $warnings = array_map(
            fn (string $warning): string => $this->appErrorText($app, $warning),
            $result['warnings']
        );
        if ($agentError !== '') {
            $warnings[] = 'Server agent bermasalah: ' . $agentError;
        }

        $summary = 'Deploy dibatalkan (' . ($result['killed'] > 0 ? $result['killed'] . ' proses dihentikan' : 'tidak ada proses aktif') . ').';
        if ($warnings !== []) {
            $summary .= ' ' . implode(' ', $warnings);
        }

        if ($request->expectsJson()) {
            flash_set($warnings === [] ? 'success' : 'error', $summary);

            return json([
                'code' => 0,
                'status' => $result['status'],
                'message' => $summary,
                'warnings' => $warnings,
            ]);
        }

        flash_set($warnings === [] ? 'success' : 'error', $summary);

        return redirect('/apps/' . $id);
    }

    /**
     * Seam uji: canceller dengan proses/Engine palsu. Produksi: implementasi
     * bawaan (proc nyata + Docker Engine host ini).
     *
     * @param array<string,mixed> $app
     */
    protected function deployCanceller(array $app): DeployCanceller
    {
        return new DeployCanceller();
    }

    /**
     * Rollback app ke versi (commit) yang pernah sukses.
     *
     * Ref divalidasi: harus ada di deploy_history dengan status sukses/restored
     * dan bukan versi yang sedang aktif. Ditandai busy (status deploying) agar
     * tidak bentrok dengan deploy/rebuild/rollback lain, lalu worker di-spawn.
     */
    public function rollback(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        try {
            ImportedApp::assertManageable($app, 'Rollback');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (ComposeSource::isCompose($app)) {
            flash_set('error', 'App ini dibuat dari file compose (tanpa repo Git) — rollback versi tidak tersedia. Gunakan tab Compose untuk mengubah compose lalu Deploy Ulang.');
            return redirect('/apps/' . $id);
        }
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $ref = trim((string) $request->post('ref', ''));
        if ($ref === '' || !preg_match('/^[0-9a-fA-F]{7,40}$/', $ref)) {
            flash_set('error', 'Ref rollback tidak valid.');
            return redirect('/apps/' . $id);
        }

        // Cari di history — resolve ke full SHA bila user kirim short SHA.
        $fullRef = '';
        foreach (($app['deploy_history'] ?? []) as $h) {
            if (in_array(($h['status'] ?? ''), ['success', 'restored'], true) && str_starts_with((string) ($h['sha'] ?? ''), $ref)) {
                $fullRef = (string) $h['sha'];
                break;
            }
        }
        if ($fullRef === '') {
            flash_set('error', 'Ref tidak ditemukan di riwayat deploy yang sukses.');
            return redirect('/apps/' . $id);
        }

        // Tolak rollback ke versi yang sedang aktif (no-op).
        if ($fullRef === $this->resolveActiveSha($app)) {
            flash_set('error', 'Ref yang dipilih adalah versi yang sedang aktif.');
            return redirect('/apps/' . $id);
        }

        $blocked = $this->localArtifactsError($app, 'Rollback');
        if ($blocked !== null) {
            flash_set('error', $blocked);
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum mengubah status / spawn worker rollback.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        $store->update($id, function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rollback ...';
            $s['error'] = null;
        });

        if (!$this->spawnWorker($id, 'rollback', $fullRef, $this->currentActorId())) {
            $store->update($id, function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rollback.';
                $s['error'] = 'Gagal spawn worker rollback.';
            });
            flash_set('error', 'Gagal menjalankan rollback.');
        } else {
            flash_set('success', 'Rollback ke ' . substr($fullRef, 0, 7) . ' dijalankan.');
        }
        return redirect('/apps/' . $id);
    }

    public function stop(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'stop');
        try {
            DeployerFactory::createForApp($app)->stop($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'stopped';
                $s['message'] = 'Stopped';
            });
            flash_set('success', 'App dihentikan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal stop: ' . $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    public function start(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'stop');

        // Gerbang kredit — sebelum menyalakan container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            DeployerFactory::createForApp($app)->start($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'running';
                $s['message'] = 'Running';
            });
            flash_set('success', 'App dijalankan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal start: ' . $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    public function delete(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'delete');

        // App impor = project compose milik user di terminal. "Hapus" berarti
        // MELEPAS record dari dashboard saja: container/volume/berkas proyek
        // TIDAK disentuh (tanpa teardown, tanpa cleanupDir, tanpa volume).
        if (ImportedApp::isImported($app)) {
            try {
                $server = (new ServerResolver())->findForApp($app);
                if ($server !== null) {
                    // Buang vhost app bila pernah dibuat lewat domain/SSL.
                    DeployerFactory::createFor($server)->removeNginxConfig($app);
                }
                (new EnvManager())->remove((string) $app['name']);
                $store->delete($id);
                flash_set('success', 'App "' . $app['name'] . '" dilepas dari dashboard. Container TIDAK dihentikan/dihapus — kelola project compose di terminal proyek itu.');
            } catch (\Throwable $e) {
                flash_set('error', 'Gagal menghapus: ' . $this->appErrorText($app, $e));
            }

            return redirect('/apps');
        }

        // mode: preserve (default, aman — volume tercentang dipertahankan) atau
        // purge (hapus total termasuk semua volume).
        $mode = (string) $request->post('mode', 'preserve');
        $preserve = array_values(array_filter(
            array_map('strval', (array) $request->post('preserve_volumes', [])),
            static fn (string $v): bool => $v !== ''
        ));

        $orphanServerId = null;

        try {
            // Server pemilik app bisa SUDAH dihapus dari halaman Servers
            // (registry tidak lagi memuat barisnya). App seperti itu yatim:
            // tidak ada jalur jaringan untuk teardown, jadi teardown remote
            // DILEWATI — DILARANG jatuh ke server lokal — dan record app tetap
            // bisa dibersihkan. Registry yang tidak terbaca tetap melempar
            // (`findForApp()` hanya `null` untuk baris yang benar-benar hilang).
            $server = (new ServerResolver())->findForApp($app);

            if ($server === null) {
                $orphanServerId = trim((string) ($app['server_id'] ?? ''));
            } else {
                // Satu deployer untuk server pemilik app: app di server agent
                // di-teardown LEWAT AGENT (job `teardown`), tidak pernah di host ini.
                // `purge` ⇒ null (down -v: semua volume termasuk anonymous ikut
                // terhapus); default aman ⇒ pertahankan volume tercentang.
                DeployerFactory::createFor($server)->teardown($app, $mode === 'purge' ? null : $preserve);
            }

            $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
            if (is_dir($dir)) {
                $this->cleanupDir($dir);
            }

            // Bersihkan pasangan kunci SSH deploy key app
            if (($app['auth_method'] ?? 'none') === 'ssh') {
                (new SshKeyManager())->remove($app['name']);
            }

            // Bersihkan managed env file app (override env di dalam dir app
            // ikut terhapus bersama cleanupDir).
            (new EnvManager())->remove($app['name']);

            $store->delete($id);

            if ($orphanServerId !== null) {
                flash_set('info', 'App "' . $app['name'] . '" dihapus dari dashboard. Server "'
                    . ($orphanServerId !== '' ? $orphanServerId : '(kosong)')
                    . '" sudah tidak ada di registry, jadi teardown container/volume di server itu '
                    . 'TIDAK dijalankan — bersihkan manual di mesin tersebut (mis. '
                    . 'docker compose -p <project> down -v) bila mesinnya masih hidup.');
            } else {
                flash_set('success', 'App "' . $app['name'] . '" dihapus.');
            }
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus: ' . $this->appErrorText($app, $e));
        }
        return redirect('/apps');
    }

    // ==================================================================
    // Kepemilikan & sharing (owner_id + members)
    // ==================================================================

    /**
     * Tambah / ubah akses seorang user ke app (POST /apps/{id}/members).
     * Hanya owner app (atau admin) yang boleh — ability 'sharing'.
     */
    public function addMember(Request $request, string $id)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        $userId = trim((string) $request->post('user_id', ''));
        $role = strtolower(trim((string) $request->post('role', AppAccess::ROLE_VIEWER)));

        try {
            if (!in_array($role, AppAccess::ASSIGNABLE_ROLES, true)) {
                throw new RuntimeException('Role tidak valid.');
            }
            $target = (new UserStore())->findPublicById($userId);
            if ($target === null) {
                throw new RuntimeException('User tidak ditemukan.');
            }
            $store->addMember($id, $userId, $role, (string) (current_user()['id'] ?? ''));
            flash_set('success', 'Akses "' . $target['username'] . '" diset sebagai ' . AppAccess::label($role) . '.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    /**
     * Cabut akses seorang member (POST /apps/{id}/members/{userId}/remove).
     */
    public function removeMember(Request $request, string $id, string $userId)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        try {
            $current = $store->find($id);
            if ($current !== null && (string) ($current['owner_id'] ?? '') === $userId) {
                throw new RuntimeException('Owner app tidak bisa dicabut. Pindahkan kepemilikan (transfer owner) lebih dulu.');
            }
            if ((string) (current_user()['id'] ?? '') === $userId) {
                throw new RuntimeException('Tidak bisa mencabut akses Anda sendiri.');
            }
            $store->removeMember($id, $userId);
            flash_set('success', 'Akses user dicabut.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    /**
     * Pindahkan kepemilikan app ke user lain (POST /apps/{id}/owner).
     * Owner lama tetap terdaftar sebagai co-owner (role owner) agar tidak
     * kehilangan akses mendadak.
     */
    public function transferOwner(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'sharing');

        $userId = trim((string) $request->post('user_id', ''));

        try {
            $users = new UserStore();
            $target = $users->findPublicById($userId);
            if ($target === null) {
                throw new RuntimeException('User tidak ditemukan.');
            }

            $previousOwner = $users->findPublicById((string) ($app['owner_id'] ?? ''));

            $store->transferOwner($id, $userId, (string) (current_user()['id'] ?? ''));
            flash_set('success', 'Kepemilikan app dipindahkan ke "' . $target['username'] . '". Anda tetap terdaftar sebagai co-owner.');

            // Pemilik lama ditagih, pemilik baru bebas tagihan (admin) & app masih
            // hidup ⇒ app akan berjalan tanpa akrual. Hentikan supaya tidak ada
            // pemakaian gratis; admin bisa menyalakannya kembali.
            $previousOwnerBillable = $previousOwner !== null && !$users->isAdmin($previousOwner);
            $newOwnerExempt = $users->isAdmin($target);
            if ((bool) config('deploy.billing_enabled', true)
                && AppStopper::shouldStopOnTransfer($app, $previousOwnerBillable, $newOwnerExempt)
            ) {
                $updated = $store->find($id) ?? $app;
                if ((new AppStopper())->stopForBillingEscape($updated, $previousOwnerBillable, $newOwnerExempt)) {
                    flash_set('info', 'App dihentikan otomatis karena pemilik barunya bebas tagihan kredit — nyalakan kembali bila diperlukan.');
                }
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    // ==================================================================
    // Custom domain (set / hapus)
    // ==================================================================

    /**
     * Set / ganti custom domain app.
     *
     * Validasi: FQDN publik valid, bukan subdomain bawaan app sendiri, unik di
     * semua app (subdomain & custom domain app lain). Bila ada custom domain
     * lama yang punya sertifikat, di-revoke dulu. Setelah tersimpan, config
     * Nginx ditulis ulang (subdomain → redirect ke custom domain).
     */
    public function setDomain(
        Request $request,
        string $id,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ) {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $domain = strtolower(trim((string) $request->post('domain', '')));
        $subdomain = app_subdomain_of($app);
        $oldCustom = (string) ($app['custom_domain'] ?? '');

        try {
            // App tanpa port terpublish tidak punya target `proxy_pass` — domain
            // tak akan pernah dilayani Nginx (vhost dilewati saat deploy).
            if (!AppPorts::hasHostPort($app)) {
                throw new RuntimeException('App ini tidak mem-publish port host, jadi tidak ada yang bisa di-proxy ke domain. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
            }
            if (!SslIssuer::isPublicDomain($domain)) {
                throw new RuntimeException('Custom domain harus FQDN publik yang valid (mis. example.org).');
            }
            if ($domain === $subdomain) {
                throw new RuntimeException('Custom domain tidak boleh sama dengan subdomain bawaan app.');
            }
            if ($domain === $oldCustom) {
                flash_set('info', 'Custom domain sudah di-set ke ' . $domain . '.');
                return redirect('/apps/' . $id);
            }
            $this->assertDomainUnique($store, $id, $domain);

            // Custom domain lama (bila ada sertifikat) di-revoke sebelum diganti —
            // di server pemilik app (agent ⇒ job `ssl_revoke` di sana).
            if ($oldCustom !== '') {
                try {
                    $this->revokeDomainCert($app, $oldCustom, $localIssuer, $agentClient);
                } catch (\Throwable $e) {
                    flash_set('info', 'Peringatan: gagal revoke sertifikat lama ' . $oldCustom . ' — ' . $this->appErrorText($app, $e));
                }
            }

            $store->update($id, function (array &$s) use ($domain): void {
                $s['custom_domain'] = $domain;
                $s['custom_ssl_status'] = 'disabled';
                $s['custom_ssl_stage'] = null;
                $s['custom_ssl_message'] = null;
                $s['custom_ssl_error'] = null;
                $s['custom_ssl_expires_at'] = null;
                $s['custom_needs_ssl'] = false;
            });

            // Konteks server tidak boleh hilang bila pembacaan ulang gagal:
            // reload/penulisan harus tetap diarahkan ke server app (bukan lokal).
            $app = $store->find($id) ?? $app;
            try {
                $this->applyNginxConfig($app, $agentClient);
            } catch (\Throwable $e) {
                flash_set('error', 'Custom domain ' . $domain . ' tersimpan, tetapi gagal menulis config Nginx: ' . $this->appErrorText($app, $e) . ' (jalankan Rebuild untuk menerapkan).');
                return redirect('/apps/' . $id);
            }

            // Reload Nginx di server app agar reverse proxy custom domain aktif.
            $reloadNote = $this->reloadNginxNote($app, $localReloader, $agentClient);
            flash_set('success', 'Custom domain ' . $domain . ' diset. Subdomain bawaan kini redirect ke domain tersebut.' . ($reloadNote !== '' ? ' ' . $reloadNote : '') . ' Jangan lupa arahkan DNS domain ke server, lalu aktifkan SSL-nya.');
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Hapus custom domain app. Sertifikat SSL domain (bila ada) di-revoke;
     * bila revoke gagal, domain tetap dihapus dari config (recovery via Rebuild).
     */
    public function removeDomain(
        Request $request,
        string $id,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ) {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $customDomain = (string) ($app['custom_domain'] ?? '');
        if ($customDomain === '') {
            flash_set('info', 'App tidak memiliki custom domain.');
            return redirect('/apps/' . $id);
        }

        $revokeError = null;
        try {
            // Pencabutan terjadi di server pemilik app (agent ⇒ op `ssl_revoke`).
            $this->revokeDomainCert($app, $customDomain, $localIssuer, $agentClient);
        } catch (\Throwable $e) {
            $revokeError = $this->appErrorText($app, $e);
        }

        $store->update($id, function (array &$s): void {
            $s['custom_domain'] = null;
            $s['custom_ssl_status'] = 'disabled';
            $s['custom_ssl_stage'] = null;
            $s['custom_ssl_message'] = null;
            $s['custom_ssl_error'] = null;
            $s['custom_ssl_expires_at'] = null;
            $s['custom_needs_ssl'] = false;
        });

        // Lihat catatan di `setDomain()`: konteks server dipertahankan walau
        // pembacaan ulang gagal (jangan pernah jatuh ke host lokal).
        $app = $store->find($id) ?? $app;
        try {
            $this->applyNginxConfig($app, $agentClient);
            // Reload Nginx di server app agar subdomain kembali melayani app (best-effort).
            $reloadNote = $this->reloadNginxNote($app, $localReloader, $agentClient);
            if ($reloadNote !== '') {
                $revokeError = ($revokeError ?? '') . ' ' . $reloadNote;
            }
        } catch (\Throwable $e) {
            $revokeError = ($revokeError ?? '') . ' Gagal menulis config Nginx: ' . $this->appErrorText($app, $e);
        }

        if ($revokeError !== null) {
            flash_set('error', 'Custom domain ' . $customDomain . ' dihapus, tetapi: ' . $revokeError . ' (jalankan Rebuild untuk menerapkan config).');
        } else {
            flash_set('success', 'Custom domain ' . $customDomain . ' dihapus. Sertifikat SSL-nya di-revoke.');
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Ubah subdomain app (POST /apps/{id}/subdomain) — ability `domain` (operator+).
     *
     * Field `apps.json.subdomain` menyimpan label slug; subdomain efektif =
     * `{label}.{APP_DOMAIN}` (label kosong = kembali ke `name`). Alur "uji dulu,
     * rollback bila gagal" ditangani {@see SubdomainManager} +
     * {@see NginxConfigGuard::applySubdomain()}: validasi → simpan → tulis config
     * Nginx → `nginx -t` + reload → rollback bila gagal.
     *
     * Sertifikat Let's Encrypt terikat nama domain lama → tidak diterbitkan/
     * dihapus otomatis; status SSL subdomain di-reset dan user diarahkan
     * menerbitkan ulang di tab SSL. Otorisasi dicek lewat `findApp()` (404 bila
     * tak berhak) SEBELUM efek samping apa pun.
     */
    public function setSubdomain(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $label = SubdomainManager::normalize((string) $request->post('subdomain', ''));

        $manager = new SubdomainManager(
            $store,
            new NginxConfigGuard($this->nginxReloaderFor($app, $localReloader, $agentClient), DeployerFactory::createForApp($app, null, $agentClient), $store),
            null,
            new ParkedDomainStore()
        );
        $result = $manager->change($id, $label);

        if ($request->expectsJson()) {
            if (!$result['ok']) {
                return json(['code' => 1, 'error' => $this->appErrorText($app, $result['error']), 'subdomain' => $result['previous']]);
            }
            return json([
                'code' => 0,
                'noop' => $result['noop'],
                'subdomain' => $result['effective'],
                'previous' => $result['previous'],
                'reloaded' => $result['reloaded'],
                'message' => $this->subdomainChangeNote($result),
            ]);
        }

        if (!$result['ok']) {
            flash_set('error', $this->appErrorText($app, $result['error']));
        } elseif ($result['noop']) {
            flash_set('info', 'Subdomain sudah ' . $result['effective'] . ' — tidak ada perubahan.');
        } else {
            flash_set('success', $this->subdomainChangeNote($result));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Pesan hasil perubahan subdomain (dipakai flash & respons JSON).
     *
     * @param array{ok:bool,noop:bool,effective:string,previous:string,reloaded:bool,rolled_back:bool,error:string} $result
     */
    private function subdomainChangeNote(array $result): string
    {
        $note = 'Subdomain app diubah ke ' . $result['effective'] . '.';
        if (!$result['reloaded']) {
            $note .= ' Nginx host belum ter-reload — klik "Reload Nginx".';
        }
        $note .= ' Sertifikat SSL lama tidak lagi cocok untuk domain baru — terbitkan ulang di tab SSL bila app memakai HTTPS.';
        return $note;
    }

    /**
     * Simpan rute proxy tambahan per app (field apps.json `nginx_routes`).
     *
     * Alur "uji dulu, rollback bila gagal" — seluruh orkestrasi di
     * `NginxConfigGuard::applyRoutes()` (controller hanya mediator):
     *   1. simpan `nginx_routes` baru,
     *   2. tulis ulang config Nginx app,
     *   3. jalankan `nginx -t` lalu reload host,
     *   4. bila gagal: kembalikan `nginx_routes` ke nilai sebelumnya + tulis
     *      ulang config lama (guard melaporkan `rolled_back` + pesan nginx).
     *
     * Rute disimpan **terstruktur** (bukan snippet Nginx mentah); parsing &
     * validasi fail-fast seluruhnya di `NginxRoutes`. Textarea kosong = `[]` =
     * hapus semua rute. Kegagalan penerapan tidak meninggalkan config rusak —
     * controller hanya menerjemahkan hasil guard menjadi flash + redirect.
     * Otorisasi dicek lewat `findApp()` (app tak berhak = 404) sebelum efek
     * samping apa pun.
     */
    public function saveRoutes(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'routes');

        $text = (string) $request->post('routes', '');

        try {
            // Prasyarat fail-fast sebelum efek samping: app tanpa host port
            // terpublish tidak punya vhost → rute proxy tak akan pernah dilayani.
            if (!AppPorts::hasHostPort($app)) {
                throw new RuntimeException('App ini tidak mem-publish port host, jadi vhost/subdomain — dan rute proxy — tidak berlaku. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
            }

            $routes = NginxRoutes::parse($text);

            $guard = new NginxConfigGuard(
                $this->nginxReloaderFor($app, $localReloader, $agentClient),
                DeployerFactory::createForApp($app, null, $agentClient),
                $store
            );
            $result = $guard->applyRoutes($id, $routes);

            if (!$result['ok']) {
                flash_set('error', 'Rute proxy TIDAK diterapkan' . ($result['rolled_back'] ? ' — rute dikembalikan ke kondisi sebelumnya' : '') . ': ' . $this->appErrorText($app, $result['error']));
                return redirect('/apps/' . $id);
            }

            $count = count($routes);
            flash_set('success', ($count > 0 ? $count . ' rute proxy disimpan & diterapkan.' : 'Semua rute proxy dihapus.') . (!$result['reloaded'] ? ' Nginx host belum ter-reload — klik "Reload Nginx".' : ''));
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Tulis ulang config Nginx app (dipakai saat custom domain di-set/dihapus).
     *
     * App di server agent ⇒ job `nginx_write` **di server itu**
     * ({@see AgentDeployer::writeNginxConfig()}) — host kontrol tidak pernah
     * menulis config Nginx untuk app remote.
     */
    private function applyNginxConfig(array $app, ?AgentClient $agentClient = null): void
    {
        DeployerFactory::createForApp($app, null, $agentClient)->writeNginxConfig($app);
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
     * Reloader Nginx untuk app ini: lokal ⇒ `NginxReloader` (perilaku lama),
     * server agent ⇒ {@see AppNginxReloader} (job `nginx_reload` di server itu).
     *
     * Dipakai jalur `NginxConfigGuard` (subdomain & rute proxy) supaya guard —
     * termasuk **jalur rollback**-nya — tidak pernah menyentuh Nginx host
     * kontrol untuk app di server agent.
     *
     * @param array<string,mixed> $app
     * @param NginxReloader|null  $localReloader seam uji: reloader lokal (dipakai
     *                                           hanya bila app memang lokal)
     * @param AgentClient|null    $agentClient   seam uji: klien agent (tanpa jaringan)
     */
    private function nginxReloaderFor(array $app, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null): NginxReloader
    {
        return new AppNginxReloader($app, null, $agentClient, $localReloader);
    }

    /**
     * Cabut sertifikat `$domain` di **server pemilik app** — lokal lewat
     * `SslIssuer` (perilaku lama), server agent lewat job `ssl_revoke` di sana
     * ({@see AppSslRevoker}). Fail-fast bila server app tak dikenal atau agent
     * belum mendukung op — tidak pernah mencabut di host yang salah.
     *
     * @param array<string,mixed> $app
     * @param SslIssuer|null       $localIssuer seam uji (dipakai hanya untuk app lokal)
     * @param AgentClient|null     $agentClient seam uji
     */
    private function revokeDomainCert(array $app, string $domain, ?SslIssuer $localIssuer = null, ?AgentClient $agentClient = null): void
    {
        (new AppSslRevoker(null, $agentClient, $localIssuer))->revoke($app, $domain);
    }

    /**
     * Reload Nginx **di server pemilik app** (best-effort). Mengembalikan string
     * pesan error bila gagal, atau string kosong bila sukses.
     *
     * App di server agent memakai job `nginx_reload` di server itu
     * ({@see AppNginxReloader}) — host kontrol tidak pernah me-reload Nginx untuk
     * app remote.
     *
     * @param array<string,mixed> $app
     * @param NginxReloader|null  $localReloader seam uji
     * @param AgentClient|null    $agentClient   seam uji
     */
    private function reloadNginxNote(array $app, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null): string
    {
        try {
            $r = $this->nginxReloaderFor($app, $localReloader, $agentClient)->reload();

            return $r['ok'] ? '' : 'Reload Nginx GAGAL: ' . $this->appErrorText($app, (string) ($r['error'] ?? 'unknown error')) . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        } catch (\Throwable $e) {
            return 'Reload Nginx gagal: ' . $this->appErrorText($app, $e) . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        }
    }

    /**
     * Validasi label subdomain create (format + keunikan atas FQDN efektif app
     * lain **di server yang sama**). Kosong = fallback `name` (tetap dicek unik).
     * Melempar RuntimeException dengan pesan jelas bila tidak valid. Delegasi
     * kebijakan ke SubdomainManager.
     *
     * @param string      $serverId id server target (''/`local` = server lokal;
     *                              `null` = seluruh server — hanya untuk pemanggil lama)
     */
    private function assertSubdomainAvailable(string $label, string $name, string $excludeId = '', ?string $serverId = null): string
    {
        return (new SubdomainManager(new AppStore(), null, null, new ParkedDomainStore()))
            ->assertAvailable($label, $name, $excludeId, $serverId);
    }

    /**
     * Pastikan sebuah domain belum dipakai app lain **di server yang sama**
     * (sebagai subdomain efektif maupun custom domain).
     *
     * Multi-server F1 (plan §4.2 + §9 keputusan 4): keunikan domain berlaku
     * **per server** — dua server berbeda boleh memakai domain yang sama (FQDN
     * efektifnya memakai domain dasar server masing-masing, dan custom domain
     * selalu diterbitkan di server pemilik app). Scope ditentukan dari app
     * `$excludeId` lewat {@see AppStore::serverIdOf()} (tanpa query per baris).
     * Subdomain app itu sendiri sudah dicek pemanggil.
     */
    private function assertDomainUnique(AppStore $store, string $excludeId, string $domain): void
    {
        $scope = AppStore::serverIdOf($store->find($excludeId) ?? []);
        $parked = new ParkedDomainStore();

        foreach ($store->all() as $other) {
            if (($other['id'] ?? '') === $excludeId) {
                continue;
            }
            if (AppStore::serverIdOf($other) !== $scope) {
                continue; // server lain: domainnya tidak saling berebut
            }
            if (app_subdomain_of($other) === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai subdomain app "' . ($other['name'] ?? '?') . '".');
            }
            if (($other['custom_domain'] ?? '') === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai custom domain app "' . ($other['name'] ?? '?') . '".');
            }
        }

        // Domain parkir adalah entitas global (tidak terikat app/server), jadi
        // diperiksa lintas server — cermin dari ParkedDomainValidator::assertDomainAvailable().
        if ($parked->byDomain($domain) !== null) {
            throw new RuntimeException(
                'Domain ' . $domain . ' sudah dipakai sebagai domain parkir.'
                . ' Hapus/ubah domain parkir itu di halaman /domains terlebih dahulu.'
            );
        }
    }

    // ==================================================================
    // Environment variables (fitur "Environment Variables")
    // ==================================================================

    /**
     * Simpan environment variable app (POST /apps/{id}/env).
     *
     * Validasi ketat format kunci, lalu — bergantung **server pemilik app**:
     *  - server **lokal** (perilaku lama, tidak berubah): tulis managed env file
     *    + override env (EnvManager), persist ke apps.json, dan auto-recreate
     *    container via `docker compose up -d` (tanpa build) secara sinkron;
     *  - server **agent**: host ini **tidak** menulis artefak app apa pun
     *    (tidak ada `apps/<name>` / `database/env/*` di control plane). `env` +
     *    `compose_files` disimpan sebagai metadata app, lalu worker
     *    `apply_env` mengirim job ke agent — agent menulis managed env +
     *    override env dari metadata itu lalu `up -d` tanpa build.
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi
     *                                            tidak pernah mengisinya
     */
    public function saveEnv(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Environment variable');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server pemilik app (fail-fast bila `server_id` menunjuk server hilang):
        // jangan pernah menulis artefak app agent di host control plane.
        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Environment variable tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (!$local) {
            return $this->saveEnvOnAgent($request, $app, $store, $launcher);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Environment variable');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis env & recreate container (applyEnv).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $env = $this->normalizedEnvFromRequest($request, $app);

            // 1) Tulis file dulu (managed + override). Gagal => tidak ada state
            //    yang berubah (apps.json belum disentuh, tetap konsisten).
            $envManager = new EnvManager();
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'], 'env' => $env], $dir, $composeFiles);

            // 2) Persist ke apps.json (env + compose_files).
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $s['compose_files'] = self::composeFilesWithEnv(
                    array_map('strval', (array) ($s['compose_files'] ?? ['docker-compose.yml'])),
                    $env
                );
            });

            // 3) Auto-recreate container (up -d tanpa build). Sinkron & cepat;
            //    kegagalan penerapan tidak menggagalkan penyimpanan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'Environment variable disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Environment variable tersimpan, tetapi gagal menerapkan ke container: ' . $this->appErrorText($app, $e) . ' — coba Rebuild untuk menerapkan.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Simpan environment variable app yang berjalan di server **agent**
     * (POST /apps/{id}/env — cabang non-lokal `saveEnv()`).
     *
     * Host control plane **tidak** memiliki artefak app agent, jadi tidak ada
     * direktori/berkas yang dibuat di sini: `env` + `compose_files` (override env
     * selalu paling akhir) disimpan sebagai **metadata** app, lalu worker
     * `apply_env` mengirim job ke agent. `AgentAppProjection::METADATA` sudah
     * memuat `env`/`compose_files` — jadi payload job membawa nilai barunya, dan
     * `LocalDeployer::applyEnv()` di agent yang menulis managed env + override
     * env lalu `up -d` (tanpa build).
     *
     * Urutan: gerbang kredit **sebelum** normalisasi/persist; normalisasi
     * `normalizeEnv()` (validasi kunci) **sebelum** apps.json disentuh sehingga
     * input tidak sah tidak meninggalkan state setengah jadi.
     *
     * @param array<string,mixed>       $app      dokumen app (server agent)
     * @param DeployWorkerLauncher|null $launcher seam uji (null = bawaan)
     */
    private function saveEnvOnAgent(Request $request, array $app, AppStore $store, ?DeployWorkerLauncher $launcher = null): \Webman\Http\Response
    {
        $id = (string) $app['id'];

        // Gerbang kredit — sebelum metadata ditulis & worker `apply_env` di-spawn
        // (op itu menciptakan ulang container di agent).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $env = $this->normalizedEnvFromRequest($request, $app);

            // Persist metadata SAJA (tanpa artefak lokal): worker agent yang
            // menulis managed env + override env dari dokumen app ini.
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $s['compose_files'] = self::composeFilesWithEnv(
                    array_map('strval', (array) ($s['compose_files'] ?? ['docker-compose.yml'])),
                    $env
                );
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan environment variable di server agent ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply_env', null, $this->currentActorId(), $launcher)) {
                $store->update($id, static function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker penerapan environment variable di server agent.';
                    $s['error'] = 'Gagal spawn worker apply_env.';
                });
                flash_set('error', 'Environment variable tersimpan, tetapi gagal menjalankan worker penerapan di server agent. Coba Rebuild untuk menerapkan.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', 'Environment variable disimpan — container diciptakan ulang di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Import variabel yang belum ada dari .env.example di direktori app
     * (POST /apps/{id}/env/import). Hanya mengisi nilai default; TIDAK
     * auto-recreate — user meninjau nilainya dulu, lalu klik "Simpan & Terapkan".
     *
     * `.env.example` hanya bisa dibaca bila checkout app ada di host ini, jadi
     * app di server **agent** ditolak dengan pesan akurat (redirect + flash,
     * tanpa efek samping) — bukan pesan menyesatkan "Direktori app tidak ada".
     */
    public function importEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Environment variable');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Environment variable tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (!$local) {
            flash_set(
                'error',
                'Import .env.example belum tersedia untuk app di server agent — unggah file .env atau isi variabel manual, lalu klik "Simpan & Terapkan".'
            );

            return redirect('/apps/' . $id);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Environment variable');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        try {
            $example = EnvManager::parseEnvExample($dir);
            if ($example === []) {
                flash_set('error', 'Tidak ada .env.example di direktori app (atau tidak dapat di-parse).');
                return redirect('/apps/' . $id);
            }

            $env = is_array($app['env'] ?? null) ? $app['env'] : [];
            $added = 0;
            foreach ($example as $key => $value) {
                if (!array_key_exists($key, $env)) {
                    $env[$key] = $value;
                    $added++;
                }
            }

            if ($added === 0) {
                flash_set('info', 'Semua variabel di .env.example sudah ada di app.');
                return redirect('/apps/' . $id);
            }

            // Simpan + tulis file (tanpa recreate — biarkan user meninjau dulu).
            $this->persistEnvForReview($store, $id, $app, $dir, $env);

            flash_set('success', "Diimport {$added} variabel dari .env.example. Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Upload file .env (POST /apps/{id}/env/upload).
     *
     * Jalur mediator murni: parser strict ada di {@see EnvManager::parseEnvContent()}
     * (batas ukuran, NUL/biner, kunci & format baris, quote/escape) dan
     * penggabungan di {@see EnvManager::mergeUploaded()}. Mode `merge` (default)
     * menimpa kunci yang sama & menambah kunci baru; `mode=replace` mengganti
     * seluruh env app.
     *
     * Seperti `importEnv()`, hasil upload **tidak** auto-apply ke container —
     * file (managed env + override) & apps.json diperbarui agar bisa ditinjau,
     * lalu user menekan "Simpan & Terapkan". Karena tidak menyalakan/menciptakan
     * container, tidak ada gerbang kredit di sini.
     *
     * Multi-server: untuk app di server **agent** tidak ada artefak lokal yang
     * ditulis (parameter `$dir` = null) — hanya metadata `env` + `compose_files`
     * yang diperbarui; file managed env + override ditulis worker agent saat
     * "Simpan & Terapkan".
     */
    public function uploadEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Environment variable');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Environment variable tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server agent: review-only, tanpa menyentuh filesystem host ini.
        $dir = null;
        if ($local) {
            $dir = $this->appArtifactDirOrFail($app, 'Environment variable');
            if ($dir === null) {
                return redirect('/apps/' . $id);
            }
        }

        try {
            $limit = (int) config('deploy.env_upload_max_bytes', EnvManager::MAX_UPLOAD_BYTES);
            if ($limit <= 0) {
                $limit = EnvManager::MAX_UPLOAD_BYTES;
            }

            $envManager = new EnvManager();
            $uploaded = $envManager->parseEnvContent($this->envUploadContent($request, $limit), $limit);
            if ($uploaded === []) {
                throw new RuntimeException('File .env tidak berisi variabel yang valid.');
            }

            $existing = is_array($app['env'] ?? null) ? $app['env'] : [];
            $existingValid = EnvManager::mergeUploaded($existing, [], false);
            $replace = $request->post('mode') === 'replace';
            $merged = EnvManager::mergeUploaded($existing, $uploaded, $replace);

            // Persist tanpa recreate — user meninjau dulu (alur "Simpan & Terapkan").
            $this->persistEnvForReview($store, $id, $app, $dir, $merged);

            $total = count($uploaded);
            if ($replace) {
                $removed = count(array_diff_key($existingValid, $uploaded));
                flash_set('success', "Di-upload {$total} variabel dari .env (mode ganti semua: {$removed} variabel lama dihapus). Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
            } else {
                $added = 0;
                foreach (array_keys($uploaded) as $key) {
                    if (!array_key_exists((string) $key, $existingValid)) {
                        $added++;
                    }
                }
                $updated = $total - $added;
                flash_set('success', "Di-upload {$total} variabel dari .env ({$added} baru, {$updated} diperbarui). Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Ambil isi file `.env` dari request (upload tunggal atau array, ambil yang
     * pertama) + validasi error upload & batas ukuran.
     *
     * Pesan galat tidak pernah memuat isi/nilai file (bisa berisi secret).
     *
     * @throws RuntimeException
     */
    private function envUploadContent(Request $request, int $limit): string
    {
        $file = $request->file('env_file');
        if (is_array($file)) {
            // Bentuk `env_file[]` (defensif): ambil berkas pertama.
            $file = array_values($file)[0] ?? null;
        }
        if (!$file instanceof \Webman\Http\UploadFile) {
            throw new RuntimeException('Pilih file .env yang akan di-upload.');
        }

        $error = (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE);
        if (trim((string) $file->getUploadName()) === '' || $error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Pilih file .env yang akan di-upload.');
        }
        if ($error !== UPLOAD_ERR_OK || !$file->isValid()) {
            throw new RuntimeException('Upload file .env gagal (kode error ' . $error . ').');
        }

        try {
            $size = (int) $file->getSize();
        } catch (\Throwable $e) {
            $size = 0; // biarkan panjang isi yang menentukan (cek di bawah/parser)
        }
        if ($size > $limit) {
            throw new RuntimeException(sprintf('File .env terlalu besar (maks %d KiB).', max(1, (int) ceil($limit / 1024))));
        }

        $content = @file_get_contents((string) $file->getPathname());
        if ($content === false) {
            throw new RuntimeException('Gagal membaca file .env yang di-upload.');
        }
        if (strlen($content) > $limit) {
            throw new RuntimeException(sprintf('File .env terlalu besar (maks %d KiB).', max(1, (int) ceil($limit / 1024))));
        }

        return $content;
    }

    /**
     * Persist env app untuk ditinjau (TANPA recreate container).
     *
     * `$dir !== null` (server lokal): tulis managed env file + override YAML
     * lewat {@see EnvManager::sync()}, lalu simpan `env` & `compose_files` ke
     * apps.json. `$dir === null` (server agent): **tidak menyentuh filesystem**
     * sama sekali — hanya metadata yang diperbarui; berkasnya ditulis worker
     * agent saat "Simpan & Terapkan". Override env selalu ditaruh paling akhir
     * (menang atas compose repo); bila env kosong, entri override dihapus.
     *
     * Dipakai bersama oleh `importEnv()` (import .env.example) dan
     * `uploadEnv()` (upload .env) agar tidak ada dua versi logika persist.
     *
     * @param array<string,mixed>  $app
     * @param array<string,string> $env
     */
    private function persistEnvForReview(AppStore $store, string $id, array $app, ?string $dir, array $env): void
    {
        if ($dir !== null) {
            $envManager = new EnvManager();
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'] ?? '', 'env' => $env], $dir, $composeFiles);
        }

        $store->update($id, function (array &$s) use ($env): void {
            $s['env'] = $env;
            $s['compose_files'] = self::composeFilesWithEnv(
                array_map('strval', (array) ($s['compose_files'] ?? ['docker-compose.yml'])),
                $env
            );
        });
    }

    /**
     * Daftar `compose_files` dengan override env sebagai **satu-satunya** entri
     * env — selalu di akhir (menang atas compose repo), dibuang bila env kosong.
     *
     * Satu sumber kebenaran untuk `saveEnv()` (lokal & agent), `saveEnvOnAgent()`,
     * dan `persistEnvForReview()`: urutan relatif file lain tidak diubah, entri
     * duplikat/stale `docker-compose.override.env.yml` tidak bisa tertinggal.
     *
     * @param array<int,string>    $files
     * @param array<string,string> $env
     * @return array<int,string>
     */
    private static function composeFilesWithEnv(array $files, array $env): array
    {
        $files = array_values(array_filter(
            $files,
            static fn (string $f): bool => $f !== EnvManager::OVERRIDE_FILE
        ));
        if ($env !== []) {
            $files[] = EnvManager::OVERRIDE_FILE;
        }

        return $files;
    }

    /**
     * Normalisasi env var dari form (validasi ketat) — satu salinan untuk jalur
     * lokal & agent `saveEnv()`, supaya aturan kunci/nilai tidak menyimpang.
     *
     * @param array<string,mixed> $app
     * @return array<string,string>
     */
    private function normalizedEnvFromRequest(Request $request, array $app): array
    {
        $posted = (array) $request->post('env', []);
        $deleteKeys = array_values(array_filter(
            array_map('strval', (array) $request->post('env_delete', [])),
            static fn (string $k): bool => $k !== ''
        ));

        return $this->normalizeEnv($posted, $deleteKeys, is_array($app['env'] ?? null) ? $app['env'] : []);
    }

    /**
     * Simpan external network app (POST /apps/{id}/network).
     *
     * Menghubungkan seluruh service app ke shared network (via compose override
     * `external: true` + `networks: [default, <ext>]`) agar persisten lintas
     * Rebuild/Rollback. Validasi ketat: nama network & keberadaannya di Engine;
     * lalu tulis override, persist ke apps.json, dan recreate container
     * (`up -d` tanpa build) untuk menerapkan.
     */
    public function saveNetworks(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'network');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Jaringan eksternal');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Jaringan eksternal');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        // Normalisasi pilihan dari form + validasi format nama.
        $selected = [];
        foreach (array_map('strval', (array) $request->post('external_networks', [])) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                flash_set('error', "Nama network tidak valid: {$name}");
                return redirect('/apps/' . $id);
            }
            if (!in_array($name, $selected, true)) {
                $selected[] = $name;
            }
        }

        // Validasi network benar-benar ada **di server app** (bukan built-in).
        // Satu network hanya bermakna di satu server: untuk app di server agent
        // daftarnya dibaca dari agent itu (`GET /networks`), untuk app lokal dari
        // daemon lokal. Network yang hanya ada di server lain ⇒ ditolak dengan
        // pesan jelas (bukan diteruskan sampai `compose up` gagal).
        if ($selected !== []) {
            $server = null;
            try {
                $server = (new ServerResolver())->resolveForApp($app);
            } catch (\Throwable $e) {
                flash_set('error', $e->getMessage());
                return redirect('/apps/' . $id);
            }

            try {
                $deployer = DeployerFactory::createFor($server);
                $networks = $deployer instanceof AgentDeployer
                    ? $deployer->networks()
                    : (new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')))->listNetworks();

                $existing = [];
                foreach ($networks as $n) {
                    $existing[(string) ($n['Name'] ?? '')] = true;
                }
                foreach ($selected as $name) {
                    if (!isset($existing[$name]) || in_array($name, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                        flash_set('error', "Network \"{$name}\" tidak ditemukan di server app ini (atau built-in). Buat shared network dulu di halaman Networks server yang sama.");
                        return redirect('/apps/' . $id);
                    }
                }
            } catch (\Throwable $e) {
                flash_set('error', 'Tidak dapat membaca daftar network server app: ' . $this->appErrorText($app, $e));
                return redirect('/apps/' . $id);
            }
        }

        // Gerbang kredit — sebelum menulis override & recreate container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // 1) Tulis override dulu — gagal => state apps.json tidak berubah.
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            (new NetworkManager())->sync(['name' => $app['name'], 'external_networks' => $selected], $dir, $composeFiles);

            // 2) Persist ke apps.json (external_networks + compose_files).
            $store->update($id, function (array &$s) use ($selected): void {
                $s['external_networks'] = $selected;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== NetworkManager::OVERRIDE_FILE));
                if ($selected !== []) {
                    $files[] = NetworkManager::OVERRIDE_FILE; // selalu terakhir
                }
                $s['compose_files'] = $files;
            });

            // 3) Recreate container (up -d tanpa build) agar network diterapkan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'External networks disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'External networks tersimpan, tetapi gagal diterapkan ke container: ' . $this->appErrorText($app, $e) . ' — pastikan network eksternal ada, lalu coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Simpan prefix nama container app (POST /apps/{id}/container-names) —
     * form "Nama container" di tab Container (ability `compose`, operator+).
     *
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) Normalisasi & validasi prefix; tolak service ber-replica; fail-fast bila
     *     nama final sudah dipakai container lain (nama container unik se-host,
     *     tanpa prefix project).
     *  2) Tulis/hapus `docker-compose.override.names.yml` + rapikan compose_files.
     *  3) Recreate container (`up -d` tanpa build) agar nama baru dipakai.
     */
    public function saveContainerNames(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Nama container');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Nama container');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        $composeFiles = (array) ($app['compose_files'] ?? ['docker-compose.yml']);

        // Gerbang kredit — sebelum menulis override nama & recreate container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $prefix = $this->resolveContainerPrefix(
                (string) $app['name'],
                ComposeSource::resolveMainFile($dir, $app),
                (string) $request->post('container_prefix', '')
            );

            // 1) Tulis file dulu — gagal => state apps.json tidak berubah.
            if ($prefix === '') {
                ContainerNames::removeOverride($dir);
            } else {
                ContainerNames::writeOverride($dir, array_keys(ContainerNames::services($dir, $composeFiles)), $prefix);
            }

            // 2) Persist prefix + compose_files (urutan tetap: reset → ports → names → lain).
            $store->update($id, function (array &$s) use ($prefix, $composeFiles): void {
                $s['container_prefix'] = $prefix !== '' ? $prefix : null;
                $files = array_values(array_filter(
                    $composeFiles,
                    static fn (string $f): bool => $f !== ContainerNames::OVERRIDE_FILE
                ));
                if ($prefix !== '') {
                    $files[] = ContainerNames::OVERRIDE_FILE;
                }
                $s['compose_files'] = $this->orderComposeFiles($files);
            });

            // 3) Recreate container agar nama baru dipakai (tanpa build).
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', $prefix === ''
                    ? 'Nama container dikembalikan ke default compose & container diciptakan ulang.'
                    : 'Nama container disimpan (prefix "' . $prefix . '") & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Nama container tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan batas maksimum CPU/memori per service (POST /apps/{id}/limits) —
     * form "Batas resource" di tab Container. Ability `limits` = **admin saja**
     * (penolakan → 404, satu pintu lewat AppAccess).
     *
     * Dua jalur, dipilih dari **server pemilik app**:
     *  - server **lokal** (perilaku lama, tidak berubah): whitelist service
     *    terhadap base compose lokal → validasi nilai → tulis/hapus
     *    `docker-compose.override.limits.yml` + rapikan `compose_files` →
     *    recreate container (`applyEnv`, `up -d` tanpa build) secara sinkron;
     *  - server **agent**: control plane **tidak** punya base compose app itu,
     *    jadi daftar service dibaca dari agent (`GET /apps/{id}/services`),
     *    `limits`/`compose_files` disimpan sebagai **metadata**, lalu worker
     *    `apply_limits` menerapkannya di agent. Tidak ada artefak lokal ditulis.
     *
     * Field kosong = tidak diatur dashboard → key tidak ditulis, nilai CPU/memori
     * milik base compose repo tetap berlaku (bukan `!reset`).
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi
     *                                            tidak pernah mengisinya
     */
    public function saveLimits(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'limits');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        // App impor tidak punya base compose di host ini: Rames tidak menulis
        // override limits dan tidak menciptakan ulang container.
        try {
            ImportedApp::assertManageable($app, 'Batas CPU/memori');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server pemilik app (fail-fast bila `server_id` menunjuk server hilang):
        // jangan pernah menulis artefak app agent di host control plane.
        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Batas CPU/memori tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (!$local) {
            return $this->saveLimitsOnAgent($request, $app, $store, $launcher);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Batas CPU/memori');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis override limit & recreate container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // 1) Validasi → tulis/hapus file override → persist state (di library).
            $result = ResourceLimits::persist($store, $app, $dir, (array) $request->post('limits', []));
            $limits = $result['limits'];

            // 2) Recreate container agar batas baru dipakai (tanpa build).
            $app = $result['app'];
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', $limits === []
                    ? 'Batas CPU/memori dihapus (nilai base compose berlaku) & container diciptakan ulang.'
                    : 'Batas CPU/memori disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', $limits === []
                    ? 'Batas CPU/memori dihapus, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.'
                    : 'Batas CPU/memori tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan batas CPU/memori app yang berjalan di server **agent**
     * (POST /apps/{id}/limits — cabang non-lokal `saveLimits()`).
     *
     * Control plane tidak punya base compose app agent, sehingga:
     *  1) daftar service dibaca **dari agent** (`GET /apps/{id}/services`).
     *     Agent lama (endpoint 404) ⇒ pesan "perbarui agent" — bukan galat 500;
     *  2) validasi service/nilai + persist **metadata** (`limits`,
     *     `compose_files`) lewat `ResourceLimits::persistMetadata()` — fail-fast,
     *     tanpa menyentuh filesystem;
     *  3) worker `apply_limits` dipicu; agent menulis override limits & `up -d`
     *     tanpa build dari metadata itu.
     *
     * Urutan: gerbang kredit **sebelum** panggilan agent/metadata (op ini
     * menciptakan ulang container di agent).
     *
     * @param array<string,mixed>       $app      dokumen app (server agent)
     * @param DeployWorkerLauncher|null $launcher seam uji (null = bawaan)
     */
    private function saveLimitsOnAgent(
        Request $request,
        array $app,
        AppStore $store,
        ?DeployWorkerLauncher $launcher = null
    ): \Webman\Http\Response {
        $id = (string) $app['id'];

        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // 1) Daftar service dari base compose server agent.
            try {
                $data = $this->agentAppServices($app);
            } catch (AgentError $e) {
                if ($e->reason === AgentError::NOT_FOUND) {
                    throw new RuntimeException(
                        'Perbarui agent di server ini agar batas resource (CPU/memori) bisa diatur dari dashboard.'
                    );
                }

                throw new RuntimeException($this->appErrorText($app, $e), 0, $e);
            }

            $services = array_values(array_map('strval', (array) ($data['services'] ?? [])));
            if ($services === []) {
                $detail = trim((string) ($data['error'] ?? ''));
                throw new RuntimeException(
                    'Daftar service app tidak bisa dibaca di server agent'
                    . ($detail !== '' ? ': ' . $this->appErrorText($app, $detail) : '.')
                    . ' Pastikan base compose app tersedia di server itu, lalu coba lagi.'
                );
            }

            // 2) Validasi + persist metadata SAJA (tanpa artefak lokal).
            $result = ResourceLimits::persistMetadata(
                $store,
                $app,
                (array) $request->post('limits', []),
                $services
            );
            $limits = $result['limits'];

            // 3) Serahkan penerapan ke worker agent.
            $store->update($id, static function (array &$s): void {
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan batas CPU/memori di server agent ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply_limits', null, $this->currentActorId(), $launcher)) {
                $store->update($id, static function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker penerapan batas CPU/memori di server agent.';
                    $s['error'] = 'Gagal spawn worker apply_limits.';
                });
                flash_set('error', 'Batas CPU/memori tersimpan, tetapi gagal menjalankan worker penerapan di server agent. Coba Rebuild untuk menerapkan.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', $limits === []
                ? 'Batas CPU/memori dihapus — container diciptakan ulang di server agent (tanpa build).'
                : 'Batas CPU/memori disimpan — container diciptakan ulang di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Validasi prefix nama container dari form:
     *  - normalisasi + validasi format;
     *  - service ber-replica > 1 ditolak (compose tidak mengizinkan container_name);
     *  - nama final harus belum dipakai container lain di host — fail-fast, tanpa
     *    ini `docker compose up` gagal di tengah deploy dengan "Conflict".
     *
     * @return string prefix final ('' = pakai nama default compose)
     */
    private function resolveContainerPrefix(string $appName, string $composeFile, string $rawPrefix, string $serverId = ''): string
    {
        $prefix = ContainerNames::normalizePrefix($rawPrefix);
        ContainerNames::assertValidPrefix($prefix);
        if ($prefix === '') {
            return '';
        }

        $dir = (string) config('deploy.apps_path') . '/' . $appName;
        if (!is_dir($dir)) {
            // App **repo** milik server **agent**: base compose-nya hanya ada di
            // server itu (checkout di agent), jadi daftar service & pemeriksaan
            // `deploy.replicas` tidak bisa dijalankan dari control plane. Ditolak
            // **eksplisit** (bukan diloloskan tanpa cek / bukan pesan menyesatkan
            // "base compose tidak ditemukan").
            throw new RuntimeException(
                'Nama container kustom belum aktif untuk app repo di server agent pada fase ini: base compose '
                . 'app ini ada di server agent, sehingga pemeriksaan daftar service & `deploy.replicas` tidak '
                . 'bisa dilakukan dari dashboard. Biarkan kolom "Prefix nama container" kosong lalu deploy lagi.'
            );
        }
        $services = ContainerNames::services($dir, [$composeFile]);
        ContainerNames::assertNotReplicated($services);
        ContainerNames::assertAvailable(
            ContainerNames::mapFor(array_keys($services), $prefix),
            $this->usedContainerNames($appName, $serverId)
        );

        return $prefix;
    }

    /**
     * Nama container yang sudah terpakai **di server app ini**, di luar project
     * app ini.
     *
     * - Server **lokal**: Engine API jadi acuan utama (mencakup container app
     *   lain, container eksternal, dan container dashboard sendiri); apps.json
     *   dipakai sebagai cadangan bila Engine tidak dapat diakses — perilaku
     *   lama, tidak berubah.
     * - Server **agent**: Engine server itu belum bisa dibaca dari control plane
     *   (F3 akan merutekannya lewat agent), jadi Engine **lokal tidak pernah**
     *   dibaca untuk app milik server lain (kesalahan paling berbahaya, plan §3
     *   butir 4). Yang bisa dipastikan dari control plane adalah bentrok antar
     *   app dashboard di server itu lewat apps.json (`ContainerNames::usedFromApps()`
     *   berscope server); container di luar dashboard baru terlihat pada F3.
     *
     * @param string $ownProject nama project app ini (dilewati)
     * @param string $serverId   id server app (''/`local` = lokal)
     * @return array<string,string> nama => deskripsi pemilik
     */
    private function usedContainerNames(string $ownProject, string $serverId = ''): array
    {
        $server = (new ServerResolver())->resolve($serverId);
        $scope = (string) ($server['id'] ?? ServerStore::LOCAL_ID);

        if (!ServerResolver::isLocal($server)) {
            return ContainerNames::usedFromApps((new AppStore())->all(), $ownProject, $scope);
        }

        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            return ContainerNames::usedFromEngine($docker->listContainers(), $ownProject, $scope);
        } catch (\Throwable $e) {
            return ContainerNames::usedFromApps((new AppStore())->all(), $ownProject, $scope);
        }
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
     * Konteks data batas CPU/memori (`resourceLimits`) untuk view.
     *
     * Kontrak data (dipakai view — jangan diubah tanpa memberi tahu Frontend UI):
     * `canManage`, `services`, `saved`, `repo`, `hasSaved`, `error`.
     *
     * Bila base compose tidak terbaca (mis. file belum dimaterialisasi), `error`
     * diisi & daftar service jatuh ke `$servicesFallback` — halaman tetap bisa
     * dirender, bukan error.
     *
     * @param array<int,string>                                    $composeFiles
     * @param array<string,array{cpus:?float,memory_mb:?int}>      $saved
     * @param array<int,string>                                    $servicesFallback
     * @return array{canManage:bool,services:array<int,string>,saved:array<string,array{cpus:?float,memory_mb:?int}>,repo:array<string,array{cpus:?float,memory_mb:?int}>,hasSaved:bool,error:?string}
     */
    private function limitsContext(
        string $dir,
        array $composeFiles,
        bool $canManage,
        array $saved,
        array $servicesFallback = []
    ): array {
        $services = [];
        $repo = [];
        $error = null;

        try {
            $services = ResourceLimits::services($dir, $composeFiles);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $services = array_values(array_filter(
                array_map('strval', $servicesFallback),
                static fn (string $s): bool => $s !== ''
            ));
        }

        if ($services !== []) {
            try {
                $detected = ResourceLimits::detect($dir, $composeFiles);
                foreach ($services as $service) {
                    $repo[$service] = [
                        'cpus' => $detected[$service]['cpus'] ?? null,
                        'memory_mb' => $detected[$service]['memory_mb'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // Daftar service tetap valid; hanya prefill dari base yang hilang.
                $error ??= $e->getMessage();
            }
        }

        return [
            'canManage' => $canManage,
            'services' => $services,
            'saved' => $saved,
            'repo' => $repo,
            'hasSaved' => $saved !== [],
            'error' => $error,
        ];
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
     * Data service + limit base compose app dari **server agent** (satu panggilan
     * HTTP `GET /apps/{id}/services`).
     *
     * `protected` = seam uji: subclass dapat menimpanya supaya render detail &
     * simpan batas bisa diuji tanpa jaringan (pola `renderView()`).
     *
     * @param array<string,mixed> $app
     * @return array{services:array<int,string>,detected:array<string,array{cpus:?float,memory_mb:?int}>,error:?string}
     * @throws AgentError|RuntimeException
     */
    protected function agentAppServices(array $app): array
    {
        $deployer = DeployerFactory::createForApp($app);
        if (!$deployer instanceof AgentDeployer) {
            throw new RuntimeException('App ini tidak berjalan di server agent.');
        }

        return $deployer->appServices($app);
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
     * Validasi input batas CPU/memori dari form: tolak service yang tidak dikenal,
     * normalkan nilai, lalu buang entri yang tidak mengatur apa pun.
     *
     * @param array<string,mixed> $posted
     * @param array<int,string>   $services nama service yang dikenal
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    private function resolveLimits(array $posted, array $services): array
    {
        return ResourceLimits::fromInput($posted, $services);
    }

    /**
     * Nilai batas CPU/memori dari input user untuk **prefill render ulang** —
     * best effort & tidak melempar (berbeda dari `resolveLimits()` yang
     * memvalidasi). Hanya nilai yang bisa tampil di input number yang
     * dipertahankan; sisanya dibuang supaya render ulang tidak gagal.
     *
     * @param array<string,mixed> $posted
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    private function prefillLimits(array $posted): array
    {
        $result = [];
        foreach ($posted as $service => $fields) {
            $service = trim((string) $service);
            if ($service === '' || !is_array($fields)) {
                continue;
            }

            $rawCpus = $fields['cpus'] ?? null;
            $rawMemory = $fields['memory_mb'] ?? null;

            $cpus = is_numeric($rawCpus) && !is_bool($rawCpus) ? (float) $rawCpus : null;
            $memory = is_int($rawMemory) || (is_string($rawMemory) && ctype_digit(trim($rawMemory)))
                ? (int) $rawMemory
                : null;

            if ($cpus === null && $memory === null) {
                continue;
            }
            $result[$service] = ['cpus' => $cpus, 'memory_mb' => $memory];
        }
        return $result;
    }

    /**
     * Urutkan compose_files: base compose → override reset/ports/names/limits →
     * override lain (network, env). Override env wajib paling akhir agar tetap
     * menang atas file repo.
     *
     * @param array<int,string> $files
     * @return array<int,string>
     */
    private function orderComposeFiles(array $files): array
    {
        return ComposeSource::orderFiles($files);
    }

    /**
     * Simpan perubahan compose app mode compose (POST /apps/{id}/compose) —
     * tab "Compose" di detail app.
     *
     * Dua jalur, dipilih dari **server pemilik app**:
     *  - server **lokal** (perilaku lama, tidak berubah): validasi → rencana host
     *    port → tulis file compose + berkas pendukung di `apps/<name>` → tulis
     *    ulang override port (2 lapis) → persist + spawn worker `apply`.
     *  - server **agent**: stdout compose disimpan sebagai metadata app
     *    (`compose_content`) + rencana host port (`host_ports`), lalu worker
     *    `apply` mengirimnya ke agent lewat `artifacts.compose`/`artifacts.files`
     *    (`AgentArtifactPackager` → `AgentArtifactMaterializer`). Host ini
     *    **tidak** menulis direktori/berkas artefak app agent; nama file utama
     *    tetap mengikuti aturan repo ({@see ComposeSource::resolveMainFile()}).
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi tidak
     *                                            pernah mengisinya
     */
    public function saveCompose(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');

        try {
            ImportedApp::assertManageable($app, 'Editor compose');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (ComposeSource::isGit($app)) {
            flash_set('error', 'App ini dibuat dari repo Git — perbarui source lewat Rebuild (git pull).');
            return redirect('/apps/' . $id);
        }
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis file compose / mengubah apps.json /
        // spawn worker `apply` (jalur ignition yang membuat ulang container).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];

        // Server pemilik app (fail-fast: `server_id` menunjuk server yang hilang).
        try {
            $server = $this->serverForApp($app);
        } catch (\Throwable $e) {
            flash_set('error', 'Compose tidak bisa disimpan: ' . $e->getMessage());
            return redirect('/apps/' . $id);
        }

        if (!ServerResolver::isLocal($server)) {
            return $this->saveComposeOnAgent($request, $app, $store, $launcher);
        }

        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        try {
            $content = (string) $request->post('compose', '');
            $mainFile = ComposeSource::resolveMainFile($dir, $app);

            // 1) Validasi & rencana port DULU (belum ada file/state yang diubah).
            ComposeSource::assertDeployable($content);
            $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
            $services = ComposeSource::planHostPorts(
                ComposeSource::parseContent($content),
                ComposeSource::existingHostPorts($app),
                $this->usedHostPortsExcept($id),
                (int) $range['start'],
                (int) $range['end']
            );
            $primaryPortSelection = $this->resolvePrimarySelection(
                $services,
                '',
                (string) ($app['primary_service'] ?? ''),
                (int) ($app['primary_port'] ?? 0)
            );
            $primary = $primaryPortSelection['service'];
            $primaryPort = $primaryPortSelection['port'];

            // 2) Tulis file: compose utama (divalidasi ulang oleh store) +
            //    file pendukung baru (upload), lalu hapus file tercentang.
            $uploads = $this->uploadsFrom($request);
            ComposeSource::store(
                $dir,
                $uploads,
                $content,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES),
                $mainFile
            );
            $delete = array_values(array_filter(
                array_map('strval', (array) $request->post('file_delete', [])),
                static fn (string $f): bool => $f !== ''
            ));
            $removed = ComposeSource::removeFiles($dir, $delete, $mainFile);

            // 3) Tulis ulang override port; override lain (network/env) tetap
            //    di urutan belakang agar prioritas compose tidak berubah.
            $composeFiles = [$mainFile];
            $this->writeOverride(
                ['name' => $app['name']],
                $services,
                $composeFiles,
                ContainerNames::normalizePrefix($app['container_prefix'] ?? '')
            );
            $composeFiles = array_values(array_unique(array_merge($composeFiles, $this->generatedExtras($app))));

            // 4) Persist + spawn worker apply (up -d tanpa build + Nginx).
            $store->update($id, function (array &$s) use ($composeFiles, $primary, $primaryPort): void {
                $s['compose_files'] = $composeFiles;
                $s['primary_service'] = $primary;
                $s['primary_port'] = $primaryPort;
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan perubahan compose ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply', null, $this->currentActorId(), $launcher)) {
                $store->update($id, function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
                flash_set('error', 'Compose tersimpan, tetapi gagal menjalankan deploy ulang.');
                return redirect('/apps/' . $id);
            }

            $extra = $removed !== [] ? ' ' . count($removed) . ' file dihapus.' : '';
            flash_set('success', 'Compose disimpan — container diciptakan ulang di latar belakang.' . $extra);
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan compose app yang tinggal di server **agent** (misi F1 celah A).
     *
     * Perbedaan pokok dari jalur lokal: host ini **tidak** memiliki (dan tidak
     * membuat) direktori artefak app agent, jadi compose hasil edit disimpan
     * sebagai metadata app (`compose_content`) + rencana host port (`host_ports`)
     * dan dikirim worker `apply` lewat `artifacts.compose`/`artifacts.files`;
     * `AgentArtifactMaterializer` di agent yang menulis berkasnya (compose +
     * override port) sebelum worker agent berjalan — "simpan dulu, baru up -d".
     *
     * Urutan fail-fast: validasi isi compose & bentuk berkas unggahan **sebelum**
     * `apps.json` disentuh; job hanya di-spawn bila semuanya sah. `apply` dipakai
     * (bukan `deploy`) supaya tidak ada build di server app.
     *
     * Catatan urutan persist: seperti jalur lokal, `compose_content` +
     * `compose_files` + rencana port ditulis **sebelum** worker di-spawn — payload
     * job membawa dokumen app itu (`AgentDeployer` → proyeksi agent), dan packager
     * membaca rencana port dari sana. Worker gagal ⇒ status app ditandai `error`
     * (perilaku yang sama dengan jalur lokal), bukan rollback metadata.
     *
     * @param array<string,mixed>       $app      dokumen app (server agent)
     * @param DeployWorkerLauncher|null $launcher seam uji (null = bawaan)
     */
    private function saveComposeOnAgent(Request $request, array $app, AppStore $store, ?DeployWorkerLauncher $launcher = null): \Webman\Http\Response
    {
        $id = (string) $app['id'];
        $name = (string) $app['name'];
        $dir = (string) config('deploy.apps_path') . '/' . $name;

        try {
            $content = (string) $request->post('compose', '');

            // 1) Validasi isi compose DULU (fail-fast, belum ada efek samping).
            ComposeSource::assertDeployable($content);

            // 2) Berkas pendukung (upload/hapus) belum bisa dikirim ke agent —
            //    tolak eksplisit, jangan dibuang diam-diam.
            $delete = array_values(array_filter(
                array_map('strval', (array) $request->post('file_delete', [])),
                static fn (string $f): bool => $f !== ''
            ));
            if ($this->uploadsFrom($request) !== [] || $delete !== []) {
                throw new RuntimeException(
                    'Berkas pendukung compose (unggah/hapus) belum didukung untuk app di server agent — '
                    . 'simpan compose tanpa berkas pendukung dulu; berkas yang sudah ada di server agent tidak diubah.'
                );
            }

            // 3) Rencana host port untuk service di compose baru (scope server app
            //    ini: rentang port & port terpakai milik server itu) + primary.
            $server = $this->serverForApp($app);
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
            $range = ServerResolver::portRange($server);
            $context = $this->portContext($serverId);
            $services = ComposeSource::planHostPorts(
                ComposeSource::parseContent($content),
                ComposeSource::existingHostPorts($app),
                $context['used'],
                (int) $range['start'],
                (int) $range['end']
            );
            $portPlan = $this->hostPortPlan($services);
            $primarySelection = $this->resolvePrimarySelection(
                $services,
                '',
                (string) ($app['primary_service'] ?? ''),
                (int) ($app['primary_port'] ?? 0)
            );

            // 4) Daftar compose_files kanonik (base → override port → … → env).
            $composeFiles = $this->agentComposeFiles(
                ComposeSource::resolveMainFile($dir, $app),
                $portPlan,
                ContainerNames::normalizePrefix($app['container_prefix'] ?? ''),
                $this->limitsOf($app),
                is_array($app['env'] ?? null) ? $app['env'] : [],
                (array) ($app['compose_files'] ?? [])
            );

            // 5) Persist metadata (master compose + rencana port + daftar berkas).
            $store->update($id, function (array &$s) use ($content, $composeFiles, $primarySelection, $portPlan): void {
                $s['compose_content'] = $content;
                $s['compose_files'] = $composeFiles;
                if ($portPlan === []) {
                    unset($s['host_ports']);   // compose baru tanpa port → jangan pertahankan rencana lama
                } else {
                    $s['host_ports'] = $portPlan;
                }
                $s['primary_service'] = $primarySelection['service'];
                $s['primary_port'] = $primarySelection['port'];
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan perubahan compose di server agent ...';
                $s['error'] = null;
            });

            // 6) Spawn worker `apply` — worker control plane mengirim job ke agent
            //    (artifacts.compose + files), agent menulis berkas lalu up -d.
            if (!$this->spawnWorker($id, 'apply', null, $this->currentActorId(), $launcher)) {
                $store->update($id, function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
                flash_set('error', 'Compose tersimpan, tetapi gagal menjalankan deploy ulang di server agent.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', 'Compose disimpan & diterapkan di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Rencana host port app (metadata `host_ports`) dari hasil resolusi port
     * wizard/create: daftar `{service, container, host}` — `host` `null` untuk
     * port yang hanya diekspos (tidak dipublikasikan ke host).
     *
     * Isinya = pilihan user (atau alokasi otomatis `PortManager`), bukan port
     * bawaan repo; dibangkitkan ulang menjadi berkas override port oleh
     * {@see \app\library\Agent\AgentArtifactPackager} saat job berangkat.
     *
     * @param array<string,array> $services hasil ComposeParser + resolusi host port
     * @return array<int,array{service:string,container:int,host:?int}>
     */
    private function hostPortPlan(array $services): array
    {
        $plan = [];
        foreach ($services as $service => $svc) {
            $service = (string) $service;
            if ($service === '' || !is_array($svc) || empty($svc['ports'])) {
                continue;
            }
            foreach ((array) $svc['ports'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $container = (int) ($entry['container'] ?? 0);
                if ($container <= 0) {
                    continue;
                }
                $host = isset($entry['host']) && (int) $entry['host'] > 0 ? (int) $entry['host'] : null;
                $plan[] = ['service' => $service, 'container' => $container, 'host' => $host];
            }
        }

        return $plan;
    }

    /**
     * Batas CPU/memori app (metadata `limits`) sebagai array — `[]` bila tidak
     * diatur/tidak valid (jalur agent tidak boleh gagal karena data lama).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function limitsOf(array $app): array
    {
        try {
            return ResourceLimits::of($app);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Daftar `compose_files` app di server **agent**, urutan kanonik repo:
     * base → override port (reset + ports, hanya bila ada rencana port) → override
     * yang diregenerasi worker agent dari metadata app (nama container, limits,
     * jaringan) → override env **paling akhir**.
     *
     * Berbeda dari jalur lokal, host ini **tidak** menulis berkas override
     * tersebut: berkas override port dikirim `AgentArtifactPackager` lewat
     * `artifacts.files` (materializer di agent yang menulis), sedangkan
     * nama/limits/env/jaringan ditulis worker agent dari metadata app. Entri-nya
     * tetap dicatat supaya `docker compose -f …` di agent benar-benar memakainya
     * — dan hanya dicatat bila berkasnya memang akan ada (worker agent selalu
     * menulisnya lebih dulu); `LocalDeployer::resolveComposeFiles()` di agent
     * membuang entri generated yang berkasnya hilang, jadi tidak pernah ada entri
     * yatim.
     *
     * @param array<int,array{service:string,container:int,host:?int}> $portPlan
     * @param array<string,mixed>  $limits
     * @param array<string,string> $env
     * @param array<int,string>    $existing compose_files app saat ini (override lain dipertahankan)
     * @return array<int,string>
     */
    private function agentComposeFiles(
        string $mainFile,
        array $portPlan,
        ?string $containerPrefix,
        array $limits,
        array $env,
        array $existing = []
    ): array {
        $managed = [
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            EnvManager::OVERRIDE_FILE,
        ];

        $files = [$mainFile];
        if ($portPlan !== []) {
            $files[] = ComposeSource::RESET_OVERRIDE_FILE;
            $files[] = ComposeSource::PORTS_OVERRIDE_FILE;
        }
        if (ContainerNames::normalizePrefix((string) ($containerPrefix ?? '')) !== '') {
            $files[] = ContainerNames::OVERRIDE_FILE;
        }
        if ($limits !== []) {
            $files[] = ResourceLimits::OVERRIDE_FILE;
        }
        foreach ($existing as $file) {
            $file = (string) $file;
            if (!str_starts_with($file, ComposeSource::GENERATED_PREFIX) || in_array($file, $managed, true)) {
                continue;
            }
            $files[] = $file;   // mis. override jaringan
        }
        if ($env !== []) {
            $files[] = EnvManager::OVERRIDE_FILE;   // selalu paling akhir → menang atas repo
        }

        return $this->orderComposeFiles($files);
    }

    /**
     * Host port yang terpakai app LAIN (untuk mengedit compose app ini: port
     * milik app sendiri bukan konflik).
     *
     * @return array<int,int>
     */
    private function usedHostPortsExcept(string $id): array
    {
        $others = array_values(array_filter(
            (new AppStore())->all(),
            static fn (array $a): bool => (string) ($a['id'] ?? '') !== $id
        ));

        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);

        return $portManager->usedHostPorts($others);
    }

    /**
     * File override generated milik app (selain override port yang ditulis ulang
     * di sini) — mis. override network & env. Urutannya dipertahankan agar
     * override env tetap paling akhir (menang atas repo).
     *
     * @return array<int,string>
     */
    private function generatedExtras(array $app): array
    {
        $keep = [];
        foreach ((array) ($app['compose_files'] ?? []) as $file) {
            $file = (string) $file;
            if (!str_starts_with($file, ComposeSource::GENERATED_PREFIX)) {
                continue;
            }
            if (in_array($file, [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE, ContainerNames::OVERRIDE_FILE], true)) {
                continue; // ditulis ulang oleh writeOverride()
            }
            $keep[] = $file;
        }
        return $keep;
    }

    /**
     * Normalisasi & validasi input env var dari form.
     *
     * - Kunci wajib ^[A-Za-z_][A-Za-z0-9_]*$ (validasi ketat).
     * - Nilai tidak boleh mengandung baris baru.
     * - Kunci duplikat di form ditolak.
     * - Kunci yang ditandai hapus (env_delete[]) dibuang.
     * - Existing yang tidak di-overwrite form tetap dipertahankan (tidak hilang).
     *
     * @param array $posted     baris env[i][key] / env[i][value]
     * @param array $deleteKeys kunci yang ditandai hapus
     * @param array $existing   env lama dari apps.json
     * @return array<string,string>
     */
    private function normalizeEnv(array $posted, array $deleteKeys, array $existing): array
    {
        $env = [];
        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || in_array($key, $deleteKeys, true)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new RuntimeException("Nama variabel tidak valid: \"{$key}\". Gunakan huruf/angka/underscore, diawali huruf atau underscore.");
            }
            $value = (string) ($row['value'] ?? '');
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException("Nilai untuk \"{$key}\" tidak boleh mengandung baris baru.");
            }
            if (array_key_exists($key, $env)) {
                throw new RuntimeException("Variabel \"{$key}\" duplikat. Hapus salah satunya.");
            }
            $env[$key] = $value;
        }

        // Sisa existing yang tidak dihapus & tidak di-overwrite form.
        foreach ($existing as $key => $value) {
            $key = (string) $key;
            if (in_array($key, $deleteKeys, true) || array_key_exists($key, $env)) {
                continue;
            }
            $env[$key] = (string) $value;
        }
        return $env;
    }

    // ==================================================================
    // Helper privat
    // ==================================================================

    /**
     * Ambil app + pastikan user berhak melakukan `ability`.
     *
     * Melempar AppAccessDenied (dirender sebagai 404 oleh webman) bila app tidak
     * ada ATAU tidak boleh diakses — supaya keberadaan app milik user lain tidak
     * bocor lewat perbedaan pesan 403 vs 404.
     *
     * @throws AppAccessDenied
     */
    private function findApp(string $id, string $ability): array
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied($ability, $id);
        }
        AppAccess::require($ability, $app, current_user());

        return $app;
    }

    // ==================================================================
    // Gerbang kredit (billing) — mediator; logika di app\library\Billing
    // ==================================================================

    /**
     * Gerbang kredit untuk jalur **membuat** app (create/confirm & template).
     * `$limits` wajib sudah divalidasi & (untuk member) diberi basis default.
     * Melempar `InsufficientCredits` — blokir **fungsional**, bukan otorisasi
     * (`AppAccess` tetap satu-satunya pintu hak akses); pemanggil menanganinya
     * lewat `billingBlocked()`.
     *
     * `$gate` hanya untuk pengujian (inject); produksi memakai default.
     * `$serverId` = server target create — gate memakai **tarif server itu**
     * (fallback tarif global bila server tak punya tarif).
     *
     * @param array<int|string,mixed> $limits
     * @throws InsufficientCredits
     */
    private function billingAssertCanCreate(
        array $limits,
        ?array $user,
        ?BillingGate $gate = null,
        ?string $serverId = null
    ): void {
        $gate ??= new BillingGate();

        // Aktor bebas tagihan (admin) tidak pernah diblokir kredit — eksplisit,
        // meski `BillingGate` juga sudah mengecualikannya.
        if ($gate->isExempt($user)) {
            return;
        }

        $gate->assertCanCreate($limits, $user, $serverId);
    }

    /**
     * Gerbang kredit untuk app yang **sudah ada** (rebuild/rollback/start/simpan
     * compose/env/network/nama/limits) — limit dibaca dari field `limits` app.
     *
     * Urutan keputusan (kebijakan produk: admin bebas kredit & app milik admin
     * tidak dibatasi kredit siapa pun):
     *  1. aktor admin ⇒ **tidak pernah** diblokir kredit;
     *  2. app milik user bebas tagihan (admin) ⇒ bebas untuk siapa pun;
     *  3. aktor member wajib punya saldo;
     *  4. penanggung biaya (owner) yang berbeda dari aktor tetap dinilai —
     *     konsisten dengan penilaian ulang di worker `cli/deploy.php`.
     *
     * @throws InsufficientCredits
     */
    private function billingAssertCanStart(array $app, ?array $user, ?BillingGate $gate = null, ?UserStore $users = null): void
    {
        if ($user === null) {
            // Tanpa konteks user login: penilaian pemilik dilakukan worker
            // `cli/deploy.php` (gerbang kredit bukan lapisan autentikasi).
            return;
        }

        $gate ??= new BillingGate(null, null, $users);

        // (1) Aktor admin bebas kredit — tidak pernah diblokir, bahkan untuk app
        //     milik member yang saldonya kurang.
        if ($gate->isExempt($user)) {
            return;
        }

        $payer = $this->billingPayerFor($app, $user, $users);

        // (2) App milik user bebas tagihan (admin) ⇒ bebas untuk siapa pun,
        //     tanpa memandang saldo aktor.
        if ($payer !== null && $gate->isExempt($payer)) {
            return;
        }

        // (3) Aktor member wajib punya kredit.
        $gate->assertCanStart($app, $user);

        // (4) Penanggung biaya (owner) — app yang dibagikan tetap ditagih ke
        //     ownernya, selaras dengan penilaian ulang di worker.
        if ($payer !== null && (string) ($payer['id'] ?? '') !== (string) ($user['id'] ?? '')) {
            $gate->assertCanStart($app, $payer);
        }
    }

    /**
     * User yang menanggung biaya sebuah app: **owner** bila diketahui, selain itu
     * aktor saat ini (app tanpa owner tetap dinilai atas nama aktor, dan user
     * `null` tetap dilewatkan `BillingGate` — bukan lapisan autentikasi).
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
            // `auth.json` tak terbaca/korup: jatuh ke aktor, jangan gagalkan aksi
            // (AuthMiddleware tetap penjaga utama lapisan autentikasi).
            return $user;
        }
    }

    /**
     * Bentuk respons blokir kredit: 402 JSON untuk AJAX/`/api/*`, flash pesan +
     * redirect `/credits` untuk form biasa. Pesan (saldo, kebutuhan minimum, cara
     * menambah kredit) sudah disusun `InsufficientCredits`.
     */
    private function billingBlocked(Request $request, InsufficientCredits $e): \Webman\Http\Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 402, 'error' => $e->getMessage()])->withStatus(402);
        }

        flash_set('error', $e->getMessage());

        return redirect('/credits');
    }

    /**
     * Apakah user tunduk pada plafon & basis limit billing (member login)?
     * Admin gratis dan user null (tanpa konteks login) dikecualikan — resolusi
     * role memakai `BillingGate::roleIsExempt()` (role legacy tanpa field `role`
     * di-resolve lewat `UserStore`), bukan `$user['role']` mentah.
     */
    private function isBillingMember(?array $user, ?UserStore $users = null): bool
    {
        return $user !== null && !BillingGate::roleIsExempt($user, $users);
    }

    /**
     * Basis limit default untuk setiap service (anti-lubang harga): app milik
     * member **selalu** punya limit nyata sehingga harga = limit yang ditegakkan
     * (bukan "tanpa limit"). Dipakai saat member tidak mengirim limit (mis.
     * deploy dari template).
     *
     * @param array<int,string> $services
     * @return array<string,array{cpus:float,memory_mb:int}>
     */
    private function defaultLimitsFor(array $services): array
    {
        $cpus = (float) config('deploy.billing_default_cpus', 0.5);
        $memoryMb = (int) config('deploy.billing_default_memory_mb', 512);

        $limits = [];
        foreach ($services as $service) {
            $service = trim((string) $service);
            if ($service === '') {
                continue;
            }
            $limits[$service] = ['cpus' => $cpus, 'memory_mb' => $memoryMb];
        }

        return $limits;
    }

    /**
     * Batas atas skala slider batas CPU/memori (form create) untuk pengguna ini:
     * member → plafon billing; admin/bukan member → kapasitas host (limit di atas
     * kapasitas host tidak bermakna). Kapasitas host tak terbaca → plafon config.
     *
     * Murni hitung (tanpa efek samping); `$procPath` opsional agar bisa diuji
     * tanpa `/proc` nyata — pola sama dengan `billingContext()`.
     *
     * @return array{cpus:float,memory_mb:int}
     */
    private function limitsScaleMax(?array $user = null, ?string $procPath = null): array
    {
        $user ??= current_user();

        $capCpus = (float) config('deploy.billing_max_cpus', 4.0);
        $capMemoryMb = (int) config('deploy.billing_max_memory_mb', 8192);

        if ($this->isBillingMember($user)) {
            return ['cpus' => $capCpus, 'memory_mb' => $capMemoryMb];
        }

        $procPath ??= (string) config('deploy.host_proc_path', '/proc');

        $hostCpus = HostUsage::cpuCount($procPath);
        $cpus = $hostCpus !== null && $hostCpus > 0 ? (float) $hostCpus : $capCpus;

        $hostMemory = HostUsage::memory($procPath);
        $totalBytes = $hostMemory['total'] ?? 0;
        $memoryMb = $totalBytes > 0
            ? (int) (floor($totalBytes / 1048576 / 1024) * 1024)
            : $capMemoryMb;

        return ['cpus' => $cpus, 'memory_mb' => $memoryMb];
    }

    /**
     * Konteks billing **baca-saja** untuk view create/confirm/template
     * (kartu "Batas Sumber Daya" + estimasi biaya kredit).
     *
     * PENTING: method ini **tidak menegakkan** apa pun — penegakan tetap
     * `billingAssertCanCreate()`/`BillingGate` + `Pricing::assertWithinCaps()`.
     * Angka di view hanya lapisan bantuan; server tetap sumber kebenaran.
     *
     * `$user` diberikan pemanggil (tidak membaca `current_user()` di sini) agar
     * mudah diuji tanpa session. `$accounts`/`$gate` hanya untuk pengujian.
     * `$serverId` = server target yang sudah di-resolve (opsional; `null` =
     * tarif global, perilaku lama) — estimasi & deposit memakai tarif server
     * itu, bukan tarif global.
     *
     * @param array<int|string,mixed> $limits limit efektif yang dipakai menghitung `required`
     * @return array{enabled:bool,canManage:bool,member:bool,caps:array{cpus:float,memory_mb:int},rates:array<string,float>,defaults:array{cpus:float,memory_mb:int},balance:float,balance_text:string,required:float,required_text:string,estimate_hourly:float,estimate_hourly_text:string,estimate_month:float,estimate_month_text:string,sufficient:bool,days:int,topup_enabled:bool}
     */
    private function billingContext(
        array $limits,
        ?array $user,
        ?CreditAccount $accounts = null,
        ?BillingGate $gate = null,
        ?string $serverId = null
    ): array {
        $enabled = (bool) config('deploy.billing_enabled', true);
        $member = $this->isBillingMember($user);
        $days = (int) config('deploy.billing_min_deposit_days', 30);
        $rates = $this->pricingRatesFor($serverId);

        $caps = [
            'cpus' => (float) config('deploy.billing_max_cpus', 4.0),
            'memory_mb' => (int) config('deploy.billing_max_memory_mb', 8192),
        ];
        $defaults = [
            'cpus' => (float) config('deploy.billing_default_cpus', 0.5),
            'memory_mb' => (int) config('deploy.billing_default_memory_mb', 512),
        ];

        $balance = 0.0;
        $required = 0.0;
        if ($enabled && $member) {
            $gate ??= new BillingGate($accounts, null);
            $required = $gate->requiredFor($limits, $serverId);
            $userId = (string) ($user['id'] ?? '');
            if ($userId !== '') {
                $balance = ($accounts ?? new CreditAccount())->balance($userId);
            }
        }

        $hourly = $enabled && $member ? Pricing::hourlyCredits($limits, $rates) : 0.0;
        $month = $enabled && $member ? Pricing::estimate($limits, $rates, $days) : 0.0;

        return [
            'enabled' => $enabled,
            'canManage' => $user !== null,
            'member' => $member,
            'caps' => $caps,
            'rates' => $rates,
            'defaults' => $defaults,
            'balance' => $balance,
            'balance_text' => Pricing::format($balance),
            'required' => $required,
            'required_text' => Pricing::format($required),
            'estimate_hourly' => $hourly,
            'estimate_hourly_text' => Pricing::format($hourly),
            'estimate_month' => $month,
            'estimate_month_text' => Pricing::format($month),
            'sufficient' => $balance >= $required,
            'days' => $days,
            'topup_enabled' => (bool) config('deploy.billing_topup_enabled', false),
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
     * Tarif CPU/RAM untuk `$serverId` (server target/penyimpanan app).
     *
     * `null`/kosong ⇒ tarif global tanpa membaca registry (perilaku lama &
     * hemat I/O untuk pemanggil yang belum punya konteks server). Server tak
     * dikenal/tanpa tarif ⇒ fallback global (`Pricing`/`ServerRates`).
     *
     * @return array{cpu:float,ram:float}
     */
    private function pricingRatesFor(?string $serverId = null): array
    {
        $id = $serverId === null ? '' : trim($serverId);
        if ($id === '') {
            return Pricing::rates();
        }

        return (new ServerRates())->ratesFor($id);
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

    private function validateCreateInput(string $name, string $repoUrl, string $branch, string $authMethod = 'none'): void
    {
        $this->validateCreateName($name);
        if (!in_array($authMethod, ['none', 'ssh'], true)) {
            throw new RuntimeException('Metode akses repo tidak valid.');
        }
        if (!$this->isRepoUrlValid($repoUrl, $authMethod)) {
            throw new RuntimeException(
                $authMethod === 'ssh'
                    ? 'URL repo tidak valid untuk SSH. Gunakan git@host:user/repo.git atau ssh://git@host/user/repo.git'
                    : 'URL repo tidak valid (harus http/https).'
            );
        }
        if ($branch === '' || !preg_match('/^[a-zA-Z0-9._\/-]+$/', $branch)) {
            throw new RuntimeException('Branch tidak valid.');
        }
    }

    /**
     * Validasi nama app (slug) — dipakai kedua mode create.
     */
    private function validateCreateName(string $name): void
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            throw new RuntimeException('Nama app hanya boleh huruf kecil a-z, angka, dan strip (-).');
        }
        if (strlen($name) > 63) {
            throw new RuntimeException('Nama app maksimal 63 karakter.');
        }
    }

    /**
     * Validasi format URL repo sesuai metode akses:
     *   - publik (none): http/https
     *   - ssh          : scp-like (git@host:user/repo.git) atau ssh:// / git://
     */
    private function isRepoUrlValid(string $repoUrl, string $authMethod): bool
    {
        if ($repoUrl === '' || preg_match('/[\s\x00-\x1F]/', $repoUrl)) {
            return false;
        }
        // scp-like: git@github.com:user/repo.git
        if (preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:[^\s]+$/', $repoUrl)) {
            return true;
        }
        try {
            $scheme = strtolower((string) parse_url($repoUrl, PHP_URL_SCHEME));
        } catch (\Throwable $e) {
            return false;
        }
        if ($authMethod === 'ssh') {
            return in_array($scheme, ['ssh', 'git'], true) && filter_var($repoUrl, FILTER_VALIDATE_URL) !== false;
        }
        return in_array($scheme, ['http', 'https'], true) && filter_var($repoUrl, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param array<string,array> $services
     */
    private function defaultPrimary(array $services): string
    {
        foreach ($services as $name => $svc) {
            if (($svc['host_port'] ?? null) !== null) {
                return (string) $name;
            }
        }
        // tidak ada service dengan port exposed -> dibiarkan kosong; konfirmasi
        // akan menolak dengan pesan jelas (resolvePrimaryService)
        return '';
    }

    /**
     * Port container default yang di-proxy untuk sebuah service: port pertama
     * yang ditulis di `ports:` compose (0 bila service tidak punya port).
     *
     * @param array<string,array> $services
     */
    private function defaultPrimaryPort(array $services, string $service): int
    {
        if ($service === '' || !isset($services[$service]['ports'])) {
            return 0;
        }
        return (int) ($services[$service]['ports'][0]['container'] ?? 0);
    }

    /**
     * Validasi & terapkan host port hasil edit user (langkah konfirmasi).
     *
     * Form mengirim satu input per port service:
     * `services[<svc>][ports][<containerPort>][host_port]` — jadi service dengan
     * lebih dari satu port (mis. web + gateway API) bisa punya host port sendiri
     * masing-masing. Bentuk lama `services[<svc>][host_port]` (hanya port pertama)
     * tetap diterima sebagai fallback.
     *
     * @param array<string,array> $services
     * @param array               $input
     * @return array<string,array>
     */
    private function validateAndApplyPorts(array $services, array $input, string $serverId = ''): array
    {
        $context = $this->portContext($serverId);
        $portManager = $context['manager'];
        $usedPorts = $context['used'];

        $localUsed = [];
        foreach ($services as $svcName => $svc) {
            foreach ((array) ($svc['ports'] ?? []) as $i => $entry) {
                $containerPort = (int) ($entry['container'] ?? 0);
                $posted = $input[$svcName]['ports'][$containerPort]['host_port']
                    ?? $input[$svcName]['ports'][$i]['host_port']
                    ?? ($i === 0 ? ($input[$svcName]['host_port'] ?? null) : null);

                $hostPort = $posted !== null && $posted !== '' ? (int) $posted : (int) ($entry['host'] ?? 0);
                $label = 'service "' . $svcName . '"' . ($containerPort > 0 ? ', container port ' . $containerPort : '');

                if ($hostPort <= 0 || !$portManager->validatePort($hostPort)) {
                    throw new RuntimeException("Port tidak valid untuk {$label}.");
                }
                if (in_array($hostPort, $usedPorts, true) || in_array($hostPort, $localUsed, true)) {
                    throw new RuntimeException("Port {$hostPort} ({$label}) sudah terpakai. Pilih port lain.");
                }

                $localUsed[] = $hostPort;
                $usedPorts[] = $hostPort;

                $services[$svcName]['ports'][$i]['host'] = $hostPort;
                if ($i === 0) {
                    $services[$svcName]['host_port'] = $hostPort;
                }
            }
        }

        return $services;
    }

    /**
     * Resolusi service + port yang di-proxy Nginx dari input halaman konfirmasi.
     *
     * `primary` berbentuk `<service>:<containerPort>` (boleh hanya `<service>`
     * untuk kompatibilitas). Bila tidak ada/tidak valid dipakai `$fallbackService`
     * + `$preferredPort` (dari langkah analisis), else port pertama service tsb.
     *
     * App yang tidak mem-publikasikan port sama sekali (mis. server database tanpa
     * `ports:`) mengembalikan `['service' => '', 'port' => 0]` — app dibuat tanpa
     * vhost/subdomain, bukan ditolak (SPECS §7.2).
     *
     * @param array<string,array> $services
     * @return array{service:string,port:int}
     */
    private function resolvePrimarySelection(array $services, string $selection, string $fallbackService = '', int $preferredPort = 0): array
    {
        $service = '';
        $port = 0;
        if ($selection !== '') {
            if (str_contains($selection, ':')) {
                [$svc, $p] = explode(':', $selection, 2);
                $service = trim($svc);
                $port = (int) $p;
            } else {
                $service = trim($selection);
            }
        }

        if ($service === '') {
            $service = $this->resolvePrimaryService($services, $fallbackService);
        } elseif (!isset($services[$service])) {
            throw new RuntimeException('Service "' . $service . '" tidak ditemukan pada compose app.');
        }

        // Port yang valid = port yang benar-benar punya host port di service itu.
        $available = [];
        foreach ((array) ($services[$service]['ports'] ?? []) as $entry) {
            if (!empty($entry['host'])) {
                $available[] = (int) $entry['container'];
            }
        }

        if ($port <= 0 && $preferredPort > 0 && in_array($preferredPort, $available, true)) {
            $port = $preferredPort;
        }
        if ($port > 0 && !in_array($port, $available, true)) {
            throw new RuntimeException(
                'Port ' . $port . ' tidak tersedia pada service "' . $service . '" — pilih salah satu port yang terdaftar.'
            );
        }
        if ($port <= 0) {
            $port = (int) ($available[0] ?? 0);
        }
        if ($port <= 0) {
            // Tidak ada port yang dipublikasikan ke host → app tanpa domain
            // (vhost & subdomain dilewati saat deploy, SPECS §7.2).
            return ['service' => '', 'port' => 0];
        }

        return ['service' => $service, 'port' => $port];
    }

    /**
     * Service primary (service yang sudah punya host port) atau '' bila app tidak
     * mem-publikasikan port sama sekali.
     *
     * @param array<string,array> $services
     */
    private function resolvePrimaryService(array $services, string $primary): string
    {
        $candidates = array_filter(array_keys($services), static fn (string $n): bool => isset($services[$n]['host_port']));
        if ($primary !== '' && in_array($primary, array_keys($services), true) && isset($services[$primary]['host_port'])) {
            return $primary;
        }
        if ($candidates !== []) {
            return (string) array_values($candidates)[0];
        }

        return '';
    }

    /**
     * Tulis docker-compose override berisi ports hasil edit user —
     * file compose asli dari repo tetap bersih (docs/SPECS.md §7.2 langkah 8).
     *
     * Penting: docker compose MENGGABUNGKAN daftar "ports" (base + override,
     * bukan mengganti). Supaya port bawaan repo benar-benar diganti, dipakai
     * dua lapis override sesuai compose spec:
     *   1) docker-compose.override.yml       -> ports: !reset [] (hapus port bawaan)
     *   2) docker-compose.override.ports.yml -> ports: [host:container] (port final)
     *
     * Lapis ketiga (opsional) = override nama container
     * (docker-compose.override.names.yml) bila prefix nama diisi user.
     *
     * @param array                  $pending
     * @param array<string,array>    $services
     * @param array<int,string>      $composeFiles (by reference — ditambah nama override)
     * @param string|null            $containerPrefix '' = pakai nama default compose
     */
    private function writeOverride(array $pending, array $services, array &$composeFiles, ?string $containerPrefix = null): void
    {
        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];
        $reset = ['services' => []];
        $ports = ['services' => []];

        foreach ($services as $svcName => $svc) {
            // Service tanpa port exposed (mis. php-fpm) tidak perlu override.
            if (empty($svc['ports'])) {
                continue;
            }

            $reset['services'][$svcName]['ports'] = new TaggedValue('reset', []);

            $portEntries = [];
            foreach ($svc['ports'] as $entry) {
                $container = (int) $entry['container'];
                $host = $entry['host'] ?? null;
                $portEntries[] = $host !== null && $host > 0
                    ? $host . ':' . $container
                    : (string) $container;
            }
            $ports['services'][$svcName]['ports'] = $portEntries;
        }

        $resetYaml = Yaml::dump($reset, 4, 2);
        if (file_put_contents($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE, $resetYaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . ComposeSource::RESET_OVERRIDE_FILE . '.');
        }
        $composeFiles[] = ComposeSource::RESET_OVERRIDE_FILE;

        $portsYaml = Yaml::dump($ports, 4, 2);
        if (file_put_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE, $portsYaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . ComposeSource::PORTS_OVERRIDE_FILE . '.');
        }
        $composeFiles[] = ComposeSource::PORTS_OVERRIDE_FILE;

        // Lapis 3 (opsional): nama container (container_name) hasil override user.
        // Daftar service diambil dari base compose yang ada di disk (bukan dari
        // $services pemanggil) supaya jalur tab Compose pun ikut tervalidasi
        // replicanya — container_name tidak kompatibel dengan deploy.replicas > 1.
        if ($containerPrefix !== null && $containerPrefix !== '') {
            $declared = ContainerNames::services($dir, $composeFiles);
            ContainerNames::assertNotReplicated($declared);
            ContainerNames::writeOverride($dir, array_keys($declared), $containerPrefix);
            $composeFiles[] = ContainerNames::OVERRIDE_FILE;
        } else {
            ContainerNames::removeOverride($dir);
        }
    }

    /**
     * Id user yang sedang login ('' bila tak ada konteks login). Dipakai untuk
     * meneruskan identitas aktor ke worker deploy (argv[4]) supaya controller &
     * worker memakai pengecualian kredit yang sama (admin bebas kredit).
     */
    private function currentActorId(): string
    {
        $user = current_user();

        return $user === null ? '' : (string) ($user['id'] ?? '');
    }

    /**
     * Spawn background worker deploy (detached, non-blocking).
     *
     * Satu implementasi bersama ada di
     * {@see \app\library\Deploy\DeployWorkerLauncher} (dipakai juga oleh
     * `AgentJobController` di server agent, misi F1) — di sini hanya delegasi,
     * supaya perilaku spawn tidak punya dua salinan yang bisa menyimpang.
     * Mekanisme & alasannya (pcntl_fork + pcntl_exec, SIGCHLD lewat
     * `SigchldGuard`, log ke `runtime/logs/deploy/{appId}.log`) ada di
     * docblock kelas launcher.
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: launcher dengan spawn
     *                                            palsu (null = bawaan). Dipakai
     *                                            jalur create agar tes tidak
     *                                            benar-benar membuat proses.
     */
    private function spawnWorker(
        string $appId,
        string $mode,
        ?string $arg = null,
        ?string $actorId = null,
        ?DeployWorkerLauncher $launcher = null
    ): bool {
        $pid = 0;
        $ok = ($launcher ?? new DeployWorkerLauncher())->launch($appId, $mode, $arg, (string) $actorId, '', $pid);

        // Catat PID worker supaya deploy bisa dibatalkan (`DeployCanceller`).
        // Best-effort: kegagalan mencatat PID tidak boleh menggagalkan deploy —
        // canceller masih bisa menemukan worker lewat pemindaian `/proc`.
        if ($ok && $pid > 0) {
            try {
                (new AppStore())->update($appId, static function (array &$s) use ($pid, $mode): void {
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

    /**
     * SHA versi yang sedang aktif = entri sukses/restored terakhir di history.
     * Dipakai untuk menampilkan "versi aktif" dan mencegah rollback ke versi aktif.
     */
    private function resolveActiveSha(array $app): string
    {
        foreach (array_reverse($app['deploy_history'] ?? []) as $h) {
            if (in_array(($h['status'] ?? ''), ['success', 'restored'], true)) {
                return (string) ($h['sha'] ?? '');
            }
        }
        return '';
    }

    /**
     * Direktori **sementara** untuk analisis repo app yang akan di-deploy ke
     * server **agent** (multi-server F1).
     *
     * Wizard create perlu membaca file compose repo (nama file + daftar service)
     * untuk halaman konfirmasi, sedangkan checkout repo app di server agent
     * adalah milik agent (`AgentArtifactMaterializer`) dan **tidak boleh**
     * ditinggalkan di host control plane. Karena itu clone analisis dibuat di
     * luar `apps_path` (folder temp sistem) dan dihapus lagi setelah diparse.
     */
    private function newRepoAnalysisDir(string $name): string
    {
        $base = rtrim(sys_get_temp_dir(), '/') . '/' . self::REPO_ANALYSIS_DIR;
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('Gagal menyiapkan direktori analisis repo sementara.');
        }

        return $base . '/' . ComposeSource::sanitizeName($name) . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Hapus direktori beserta isinya (hanya dipakai untuk area apps_path).
     */
    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
