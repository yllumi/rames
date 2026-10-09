<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\BillingGate;
use app\library\Billing\CreditAccount;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\EnvManager;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\SubdomainManager;
use app\library\Db\DbContainerDetector;
use app\library\Docker\AppPorts;
use app\library\Docker\ComposeParser;
use app\library\Docker\DockerClient;
use app\library\Docker\PortManager;
use app\library\Git\GitService;
use app\library\Git\SshKeyManager;
use app\library\Nginx\NginxConfigGuard;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxRoutes;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Support\Markdown;
use app\library\Template\TemplateCatalog;
use RuntimeException;
use support\Request;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * App Management (SPECS.md §7).
 *
 * Controller hanya mediator — seluruh logika bisnis di app/library.
 * Tidak ada state di properti controller (Webman persistent, lihat
 * copilot-instructions).
 */
class AppController
{
    // ==================================================================
    // Daftar & detail
    // ==================================================================

    public function index(Request $request)
    {
        $store = new AppStore();
        $user = current_user();
        $userId = (string) ($user['id'] ?? '');

        // Hanya app milik sendiri / yang dibagikan ke user ini. Admin melihat
        // semua app (dengan label owner).
        $visible = AppAccess::visible($store->all(), $user);

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
        // `$app['subdomain']` sebagai FQDN → kirim FQDN efektif.
        foreach ($apps as &$app) {
            $app['subdomain'] = app_subdomain_of($app);
        }
        unset($app);

        return view('app/index', [
            'apps' => $apps,
            'roles' => $roles,
            'ownedIds' => $owned,
            'scope' => $scope,
            'counts' => ['mine' => count($mine), 'shared' => count($shared), 'all' => count($visible)],
            'isAdmin' => is_admin($user),
            'ownerNames' => is_admin($user) ? user_names() : [],
        ]);
    }

    public function detail(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'view');

        $deployer = DeployerFactory::create();

        // status container live (best effort — engine mungkin tidak tersedia)
        $live = [];
        try {
            $live = $deployer->getContainers($app['name']);
        } catch (\Throwable $e) {
            // pakai data tersimpan
        }

        // named volume project (untuk modal konfirmasi delete)
        $volumes = [];
        try {
            $volumes = $deployer->getProjectVolumes($app['name']);
        } catch (\Throwable $e) {
            // engine tidak tersedia — modal tampil tanpa daftar volume
        }

        // Network eksternal (shared) yang bisa di-join app ini. Kandidat:
        // bukan built-in & bukan milik app aktif (compose) — biasanya shared
        // network yang dibuat lewat halaman /networks.
        $availableNetworks = [];
        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            $activeNames = [];
            foreach ($store->all() as $s) {
                $activeNames[(string) ($s['name'] ?? '')] = true;
            }
            foreach ($docker->listNetworks() as $n) {
                $nname = (string) ($n['Name'] ?? '');
                if ($nname === '' || in_array($nname, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                    continue;
                }
                $project = (string) (($n['Labels'] ?? [])['com.docker.compose.project'] ?? '');
                if ($project !== '' && isset($activeNames[$project])) {
                    continue; // network milik app aktif dikelola lewat app tsb
                }
                $availableNetworks[] = $nname;
            }
            sort($availableNetworks);
        } catch (\Throwable $e) {
            // engine tidak tersedia — form tampil tanpa daftar
        }

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

        // Batas CPU/memori per service (tab Container). Tanpa panggilan Engine
        // tambahan; bila base compose tidak terbaca → data minimal + pesan error.
        $limitsDir = (string) config('deploy.apps_path') . '/' . $app['name'];
        $savedLimits = [];
        try {
            $savedLimits = ResourceLimits::of($app);
        } catch (\Throwable $e) {
            // data limits di apps.json tidak valid → form tampil tanpa nilai tersimpan
        }

        // Presentasi subdomain: view memakai `$app['subdomain']` sebagai FQDN.
        // Field tersimpan menyimpan label → kirim FQDN efektif, plus label & flag
        // "eksplisit" agar tab Domain & SSL bisa menampilkan/mengeditnya.
        $viewApp = $app;
        $viewApp['subdomain'] = app_subdomain_of($app);
        $viewApp['subdomain_label'] = app_subdomain_label($app);
        $viewApp['subdomain_custom'] = app_subdomain_is_custom($app);

        return view('app/detail', [
            'app' => $viewApp,
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
            'resourceLimits' => $this->limitsContext(
                $limitsDir,
                (array) ($app['compose_files'] ?? ['docker-compose.yml']),
                app_can('limits', $app),
                $savedLimits
            ),
            'billingEstimate' => $this->billingEstimateFor($savedLimits, current_user()),
        ]);
    }

    /**
     * Konteks tab "Compose" (app mode compose saja): nama file utama, isinya,
     * dan daftar file sumber (untuk editor & daftar file).
     *
     * @return array{main_file:string,content:string,files:array<int,string>}|null
     */
    private function composeContext(array $app): ?array
    {
        if (!ComposeSource::isCompose($app)) {
            return null;
        }
        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];

        return [
            'main_file' => ComposeSource::resolveMainFile($dir, $app),
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

    public function createForm(Request $request)
    {
        $mode = (string) $request->get('mode', 'git');

        // Mode template: galeri template app siap-pakai (compose prebuilt,
        // SPECS.md §7.2b). Definisi template dibaca dari folder repo
        // `templates/<slug>/` (dikelola admin lewat git, bukan lewat UI).
        if ($mode === 'template') {
            $templates = (new TemplateCatalog())->all();
            foreach ($templates as &$t) {
                $t['guide_html'] = Markdown::toHtml((string) ($t['guide'] ?? ''));
            }
            unset($t);

            return view('app/create', [
                'mode' => 'template',
                'templates' => $templates,
            ]);
        }

        return view('app/create', [
            'mode' => $mode === 'compose' ? 'compose' : 'git',
        ]);
    }

    public function createPreview(Request $request)
    {
        $name = strtolower(trim((string) $request->post('name', '')));
        $repoUrl = trim((string) $request->post('repo_url', ''));
        $branch = trim((string) $request->post('branch', 'main'));
        $authMethod = (string) $request->post('auth_method', 'none');
        $subdomain = SubdomainManager::normalize((string) $request->post('subdomain', ''));

        $keyManager = new SshKeyManager();
        $generatedKey = false;

        try {
            $this->validateCreateInput($name, $repoUrl, $branch, $authMethod);

            $store = new AppStore();
            if ($store->nameExists($name)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai.");
            }

            // Subdomain opsional — validasi format + keunikan sebelum menyentuh disk.
            $this->assertSubdomainAvailable($subdomain, $name);

            $appsPath = (string) config('deploy.apps_path');
            $dest = $appsPath . '/' . $name;
            if (is_dir($dest)) {
                // area apps_path dikelola sistem — bersihkan lalu clone ulang
                $this->cleanupDir($dest);
            }

            // Repo private: generate deploy key SSH sebelum clone. Kalau sudah ada
            // (percobaan ulang setelah user menambah deploy key), dipakai kembali.
            $sshKeyPath = null;
            if ($authMethod === 'ssh') {
                $keyManager->generate($name);
                $generatedKey = true;
                $sshKeyPath = $keyManager->privateKeyPath($name);
            }

            $git = new GitService();
            $git->clone($repoUrl, $branch, $dest, $sshKeyPath);

            $composeFile = $git->findComposeFile($dest);
            if ($composeFile === null) {
                $this->cleanupDir($dest);
                throw new RuntimeException('Repo tidak memiliki docker-compose.yml (atau .yaml) di root.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services']);

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
                ]);
            }
            if ($generatedKey) {
                $keyManager->remove($name);
            }
            flash_set('error', $e->getMessage());
            return redirect('/apps/create');
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

        try {
            $this->validateCreateName($name);

            $store = new AppStore();
            if ($store->nameExists($name)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai.");
            }

            // Subdomain opsional — validasi format + keunikan sebelum menulis file.
            $this->assertSubdomainAvailable($subdomain, $name);

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
            $services = $this->resolveServicePorts($parsed['services']);

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
            ]);
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
     * (SPECS.md §7.2b).
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

        return view('app/template', [
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
                current_user()
            ),
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

        // Fase 1 — validasi yang tidak menyentuh disk (template, nama, nilai env).
        try {
            if ($template === null) {
                throw new RuntimeException('Template "' . $slug . '" tidak ditemukan.');
            }
            if (!$template['valid']) {
                throw new RuntimeException('Template "' . $slug . '" tidak valid: ' . $template['error']);
            }
            $this->validateCreateName($name);
            if ((new AppStore())->nameExists($name)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai.");
            }
            $this->assertSubdomainAvailable($subdomain, $name);
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

            // Gerbang kredit — fail-fast SEBELUM materialisasi/efek samping.
            $this->billingAssertCanCreate($limits, $user);
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

            return view('app/template', [
                'template' => $template,
                'form_name' => $name,
                'form_env' => $envInput,
                'form_error' => $e->getMessage(),
                'resourceLimits' => $limitsContext,
                'billing' => $this->billingContext(
                    $postedLimits === []
                        ? $this->defaultLimitsFor(array_map('strval', array_keys((array) ($template['services'] ?? []))))
                        : $postedLimits,
                    current_user()
                ),
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
            $services = $this->resolveServicePorts($parsed['services']);
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
            $containerPrefix = $this->resolveContainerPrefix($name, $composeFile, $name);

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
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }
            flash_set('error', $e->getMessage());

            return view('app/template', [
                'template' => $template,
                'form_name' => $name,
                'form_env' => $envInput,
                'form_error' => $e->getMessage(),
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
     * (dipakai kedua mode create — clone repo & compose).
     *
     * @param array<string,array> $services
     * @return array<string,array>
     */
    private function resolveServicePorts(array $services): array
    {
        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);
        $usedPorts = $portManager->usedHostPorts((new AppStore())->all());

        return $portManager->resolve($services, $usedPorts);
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

        // Base compose sudah ada di disk (hasil clone/upload langkah analisis).
        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];

        // Data subdomain untuk ditampilkan/diedit di halaman konfirmasi: FQDN
        // efektif + label + flag "eksplisit" (field terisi, bukan fallback name).
        $label = SubdomainManager::normalize((string) ($pending['subdomain'] ?? ''));

        return view('app/confirm', [
            'pending' => $pending,
            'subdomain' => $label === '' ? app_subdomain((string) $pending['name']) : app_subdomain($label),
            'subdomain_label' => $label,
            'subdomain_custom' => $label !== '',
            'resourceLimits' => $this->limitsContext(
                $dir,
                [(string) $pending['compose_file']],
                current_user() !== null,
                [],
                array_map('strval', array_keys((array) ($pending['services'] ?? [])))
            ),
            'billing' => $this->billingContext(
                $this->defaultLimitsFor(array_map('strval', array_keys((array) ($pending['services'] ?? [])))),
                current_user()
            ),
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

        // Prefix nama container divalidasi lebih dulu supaya pesan errornya kembali
        // ke halaman konfirmasi (pending_app masih tersimpan), bukan ke form create.
        try {
            $containerPrefix = $this->resolveContainerPrefix(
                (string) $pending['name'],
                (string) $pending['compose_file'],
                (string) $request->post('container_prefix', '')
            );
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }

        try {
            $services = $this->validateAndApplyPorts($pending['services'], $serviceInput);
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
            $this->assertSubdomainAvailable($subdomain, (string) $pending['name']);

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

            // Gerbang kredit — fail-fast SEBELUM efek samping apa pun.
            $this->billingAssertCanCreate($limits, $user);

            $result = $this->createAndDeploy(
                $pending,
                $services,
                $primary['service'],
                $primary['port'],
                $containerPrefix,
                [],
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
            return $this->billingBlocked($request, $e);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }
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
     * @param array<string,array>       $services  hasil ComposeParser + resolusi host port
     * @param array<string,string>      $env       map KEY => value (kosong = tanpa env)
     * @param array<string,string>|null $template  metadata asal template (bila dibuat dari template)
     * @param array<string,array{cpus:?float,memory_mb:?int}> $limits batas CPU/memori per service (kosong = tidak diatur)
     * @param string|null               $subdomain label subdomain eksplisit (null/'' = tanpa field, fallback `name`)
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
        ?string $subdomain = null
    ): array {
        // Fail-fast: pastikan direktori Nginx dapat ditulis sebelum deploy —
        // hanya bila ada port yang akan di-proxy ke domain. App tanpa `ports:`
        // tidak membuat vhost, jadi direktori Nginx tidak relevan (SPECS §7.2).
        if ($this->hasDeclaredPorts($services)) {
            DeployerFactory::create()->ensureWritable();
        }

        $composeFiles = [$pending['compose_file']];
        $this->writeOverride($pending, $services, $composeFiles, $containerPrefix);

        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];

        // Batas CPU/memori (admin saja) — file override ditulis SEBELUM entri
        // apps.json dibuat; kegagalan ⇒ pemanggil membersihkan direktori app.
        if (ResourceLimits::writeOverride($dir, [$pending['compose_file']], $limits)) {
            $composeFiles[] = ResourceLimits::OVERRIDE_FILE;
        }

        if ($env !== []) {
            $composeFiles[] = EnvManager::OVERRIDE_FILE;
            $composeFiles = $this->orderComposeFiles($composeFiles);

            $envManager = new EnvManager();
            $envManager->write((string) $pending['name'], $env);
            $envManager->writeOverride($dir, $composeFiles, $env);
        } else {
            $composeFiles = $this->orderComposeFiles($composeFiles);
        }

        $appData = [
            'name' => $pending['name'],
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

        // Subdomain opsional: kosong ⇒ field tidak ditulis (fallback ke `name`).
        if ($subdomain !== null && $subdomain !== '') {
            $appData['subdomain'] = $subdomain;
        }

        $app = (new AppStore())->create($appData);

        $spawned = $this->spawnWorker($app['id'], 'deploy');
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

        // Tolak rebuild ganda saat app sedang diproses worker lain.
        if (($app['status'] ?? '') === 'deploying') {
            $msg = 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg, 'busy' => true]);
            }
            flash_set('error', $msg);
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            $msg = 'Direktori app tidak ada. App mungkin sudah dihapus.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);
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

        if (!$this->spawnWorker($id, 'rebuild')) {
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

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
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

        if (!$this->spawnWorker($id, 'rollback', $fullRef)) {
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
            DeployerFactory::create()->stop($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'stopped';
                $s['message'] = 'Stopped';
            });
            flash_set('success', 'App dihentikan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal stop: ' . $e->getMessage());
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
            DeployerFactory::create()->start($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'running';
                $s['message'] = 'Running';
            });
            flash_set('success', 'App dijalankan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal start: ' . $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    public function delete(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'delete');

        // mode: preserve (default, aman — volume tercentang dipertahankan) atau
        // purge (hapus total termasuk semua volume).
        $mode = (string) $request->post('mode', 'preserve');
        $preserve = array_values(array_filter(
            array_map('strval', (array) $request->post('preserve_volumes', [])),
            static fn (string $v): bool => $v !== ''
        ));

        try {
            if ($mode === 'purge') {
                // Hapus total: down -v (semua volume termasuk anonymous terhapus).
                DeployerFactory::create()->teardown($app, null);
            } else {
                // Default aman: pertahankan volume tercentang (data DB dll. tetap
                // ada, dipakai ulang bila app dibuat ulang dengan nama sama).
                DeployerFactory::create()->teardown($app, $preserve);
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
            flash_set('success', 'App "' . $app['name'] . '" dihapus.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus: ' . $e->getMessage());
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
    public function setDomain(Request $request, string $id)
    {
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

            // Custom domain lama (bila ada sertifikat) di-revoke sebelum diganti.
            if ($oldCustom !== '') {
                try {
                    (new SslIssuer())->revoke($oldCustom);
                } catch (\Throwable $e) {
                    flash_set('info', 'Peringatan: gagal revoke sertifikat lama ' . $oldCustom . ' — ' . $e->getMessage());
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

            $app = $store->find($id);
            if ($app !== null) {
                try {
                    $this->applyNginxConfig($app);
                } catch (\Throwable $e) {
                    flash_set('error', 'Custom domain ' . $domain . ' tersimpan, tetapi gagal menulis config Nginx: ' . $e->getMessage() . ' (jalankan Rebuild untuk menerapkan).');
                    return redirect('/apps/' . $id);
                }
            }

            // Reload nginx host agar reverse proxy custom domain langsung aktif.
            $reloadNote = $this->reloadNginxNote();
            flash_set('success', 'Custom domain ' . $domain . ' diset. Subdomain bawaan kini redirect ke domain tersebut.' . ($reloadNote !== '' ? ' ' . $reloadNote : '') . ' Jangan lupa arahkan DNS domain ke server, lalu aktifkan SSL-nya.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Hapus custom domain app. Sertifikat SSL domain (bila ada) di-revoke;
     * bila revoke gagal, domain tetap dihapus dari config (recovery via Rebuild).
     */
    public function removeDomain(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $customDomain = (string) ($app['custom_domain'] ?? '');
        if ($customDomain === '') {
            flash_set('info', 'App tidak memiliki custom domain.');
            return redirect('/apps/' . $id);
        }

        $revokeError = null;
        try {
            (new SslIssuer())->revoke($customDomain);
        } catch (\Throwable $e) {
            $revokeError = $e->getMessage();
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

        $app = $store->find($id);
        if ($app !== null) {
            try {
                $this->applyNginxConfig($app);
                // Reload nginx host agar subdomain kembali melayani app (best-effort).
                $reloadNote = $this->reloadNginxNote();
                if ($reloadNote !== '') {
                    $revokeError = ($revokeError ?? '') . ' ' . $reloadNote;
                }
            } catch (\Throwable $e) {
                $revokeError = ($revokeError ?? '') . ' Gagal menulis config Nginx: ' . $e->getMessage();
            }
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
    public function setSubdomain(Request $request, string $id)
    {
        $store = new AppStore();
        $this->findApp($id, 'domain');

        $label = SubdomainManager::normalize((string) $request->post('subdomain', ''));

        $manager = new SubdomainManager(
            $store,
            new NginxConfigGuard(new NginxReloader(), DeployerFactory::create(), $store)
        );
        $result = $manager->change($id, $label);

        if ($request->expectsJson()) {
            if (!$result['ok']) {
                return json(['code' => 1, 'error' => $result['error'], 'subdomain' => $result['previous']]);
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
            flash_set('error', $result['error']);
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
    public function saveRoutes(Request $request, string $id)
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

            $guard = new NginxConfigGuard(new NginxReloader(), DeployerFactory::create(), $store);
            $result = $guard->applyRoutes($id, $routes);

            if (!$result['ok']) {
                flash_set('error', 'Rute proxy TIDAK diterapkan' . ($result['rolled_back'] ? ' — rute dikembalikan ke kondisi sebelumnya' : '') . ': ' . $result['error']);
                return redirect('/apps/' . $id);
            }

            $count = count($routes);
            flash_set('success', ($count > 0 ? $count . ' rute proxy disimpan & diterapkan.' : 'Semua rute proxy dihapus.') . (!$result['reloaded'] ? ' Nginx host belum ter-reload — klik "Reload Nginx".' : ''));
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Tulis ulang config Nginx app (dipakai saat custom domain di-set/dihapus).
     */
    private function applyNginxConfig(array $app): void
    {
        DeployerFactory::create()->writeNginxConfig($app);
    }

    /**
     * Reload nginx host via NginxReloader (best-effort). Mengembalikan string
     * pesan error bila gagal, atau string kosong bila sukses.
     */
    private function reloadNginxNote(): string
    {
        try {
            $r = (new NginxReloader())->reload();
            return $r['ok'] ? '' : 'Reload Nginx GAGAL: ' . ($r['error'] ?? 'unknown error') . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        } catch (\Throwable $e) {
            return 'Reload Nginx gagal: ' . $e->getMessage() . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        }
    }

    /**
     * Validasi label subdomain create (format + keunikan atas FQDN efektif app
     * lain). Kosong = fallback `name` (tetap dicek unik). Melempar RuntimeException
     * dengan pesan jelas bila tidak valid. Delegasi kebijakan ke SubdomainManager.
     */
    private function assertSubdomainAvailable(string $label, string $name, string $excludeId = ''): string
    {
        return (new SubdomainManager(new AppStore()))->assertAvailable($label, $name, $excludeId);
    }

    /**
     * Pastikan sebuah domain belum dipakai app lain (sebagai subdomain efektif
     * maupun custom domain). Subdomain app itu sendiri sudah dicek pemanggil.
     */
    private function assertDomainUnique(AppStore $store, string $excludeId, string $domain): void
    {
        foreach ($store->all() as $other) {
            if (($other['id'] ?? '') === $excludeId) {
                continue;
            }
            if (app_subdomain_of($other) === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai subdomain app "' . ($other['name'] ?? '?') . '".');
            }
            if (($other['custom_domain'] ?? '') === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai custom domain app "' . ($other['name'] ?? '?') . '".');
            }
        }
    }

    // ==================================================================
    // Environment variables (fitur "Environment Variables")
    // ==================================================================

    /**
     * Simpan environment variable app (POST /apps/{id}/env).
     *
     * Validasi ketat format kunci, lalu: tulis managed env file + override env
     * (EnvManager), persist ke apps.json, dan auto-recreate container via
     * `docker compose up -d` (tanpa build) agar perubahan langsung diterapkan.
     */
    public function saveEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis env & recreate container (applyEnv).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $posted = (array) $request->post('env', []);
            $deleteKeys = array_values(array_filter(
                array_map('strval', (array) $request->post('env_delete', [])),
                static fn (string $k): bool => $k !== ''
            ));
            $env = $this->normalizeEnv($posted, $deleteKeys, is_array($app['env'] ?? null) ? $app['env'] : []);

            // 1) Tulis file dulu (managed + override). Gagal => tidak ada state
            //    yang berubah (apps.json belum disentuh, tetap konsisten).
            $envManager = new EnvManager();
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'], 'env' => $env], $dir, $composeFiles);

            // 2) Persist ke apps.json (env + compose_files).
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== EnvManager::OVERRIDE_FILE));
                if ($env !== []) {
                    $files[] = EnvManager::OVERRIDE_FILE; // selalu terakhir → menang atas repo
                }
                $s['compose_files'] = $files;
            });

            // 3) Auto-recreate container (up -d tanpa build). Sinkron & cepat;
            //    kegagalan penerapan tidak menggagalkan penyimpanan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'Environment variable disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Environment variable tersimpan, tetapi gagal menerapkan ke container: ' . $e->getMessage() . ' — coba Rebuild untuk menerapkan.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Import variabel yang belum ada dari .env.example di direktori app
     * (POST /apps/{id}/env/import). Hanya mengisi nilai default; TIDAK
     * auto-recreate — user meninjau nilainya dulu, lalu klik "Simpan & Terapkan".
     */
    public function importEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        try {
            $envManager = new EnvManager();
            $example = $envManager->parseEnvExample($dir);
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
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'], 'env' => $env], $dir, $composeFiles);
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== EnvManager::OVERRIDE_FILE));
                $files[] = EnvManager::OVERRIDE_FILE;
                $s['compose_files'] = $files;
            });

            flash_set('success', "Diimport {$added} variabel dari .env.example. Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
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

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
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

        // Validasi network benar-benar ada di Engine (bukan built-in).
        if ($selected !== []) {
            try {
                $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
                $existing = [];
                foreach ($docker->listNetworks() as $n) {
                    $existing[(string) ($n['Name'] ?? '')] = true;
                }
                foreach ($selected as $name) {
                    if (!isset($existing[$name]) || in_array($name, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                        flash_set('error', "Network \"{$name}\" tidak ditemukan (atau built-in). Buat shared network dulu di halaman Networks.");
                        return redirect('/apps/' . $id);
                    }
                }
            } catch (\Throwable $e) {
                flash_set('error', 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage());
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
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'External networks disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'External networks tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — pastikan network eksternal ada, lalu coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
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

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
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
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
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
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) Whitelist service terhadap base compose; validasi nilai (normalize).
     *  2) Tulis/hapus `docker-compose.override.limits.yml` + rapikan compose_files.
     *  3) Recreate container (`up -d` tanpa build) agar batas baru dipakai.
     *
     * Field kosong = tidak diatur dashboard → key tidak ditulis, nilai CPU/memori
     * milik base compose repo tetap berlaku (bukan `!reset`).
     */
    public function saveLimits(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'limits');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
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
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
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
     * Validasi prefix nama container dari form:
     *  - normalisasi + validasi format;
     *  - service ber-replica > 1 ditolak (compose tidak mengizinkan container_name);
     *  - nama final harus belum dipakai container lain di host — fail-fast, tanpa
     *    ini `docker compose up` gagal di tengah deploy dengan "Conflict".
     *
     * @return string prefix final ('' = pakai nama default compose)
     */
    private function resolveContainerPrefix(string $appName, string $composeFile, string $rawPrefix): string
    {
        $prefix = ContainerNames::normalizePrefix($rawPrefix);
        ContainerNames::assertValidPrefix($prefix);
        if ($prefix === '') {
            return '';
        }

        $dir = (string) config('deploy.apps_path') . '/' . $appName;
        $services = ContainerNames::services($dir, [$composeFile]);
        ContainerNames::assertNotReplicated($services);
        ContainerNames::assertAvailable(
            ContainerNames::mapFor(array_keys($services), $prefix),
            $this->usedContainerNames($appName)
        );

        return $prefix;
    }

    /**
     * Nama container yang sudah terpakai di host, di luar project app ini.
     * Engine API jadi acuan utama (mencakup container app lain, container
     * eksternal, dan container dashboard sendiri); apps.json dipakai sebagai
     * cadangan bila Engine tidak dapat diakses.
     *
     * @return array<string,string> nama => deskripsi pemilik
     */
    private function usedContainerNames(string $ownProject): array
    {
        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            return ContainerNames::usedFromEngine($docker->listContainers(), $ownProject);
        } catch (\Throwable $e) {
            return ContainerNames::usedFromApps((new AppStore())->all(), $ownProject);
        }
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
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) Validasi isi compose (YAML valid, ada service, tanpa `build:`).
     *  2) Rencanakan host port: port lama tiap service dipertahankan, konflik
     *     dengan app lain digeser ke port bebas (PortManager).
     *  3) Tulis file compose utama + file pendukung baru, hapus file tercentang.
     *  4) Tulis ulang override port (2 lapis: reset + ports).
     *  5) Persist compose_files/primary_service + spawn worker `apply`
     *     (up -d tanpa build + tulis config Nginx; progres via polling).
     */
    public function saveCompose(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');

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

            if (!$this->spawnWorker($id, 'apply')) {
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
     *
     * @param array<int|string,mixed> $limits
     * @throws InsufficientCredits
     */
    private function billingAssertCanCreate(array $limits, ?array $user, ?BillingGate $gate = null): void
    {
        ($gate ?? new BillingGate())->assertCanCreate($limits, $user);
    }

    /**
     * Gerbang kredit untuk app yang **sudah ada** (rebuild/rollback/start/simpan
     * compose/env/network/nama/limits) — limit dibaca dari field `limits` app.
     *
     * Yang dinilai adalah saldo **penanggung biaya** (`billingPayerFor()`), bukan
     * aktor: app yang dibagikan tetap ditagih ke ownernya, sama seperti penilaian
     * ulang di worker `cli/deploy.php` (agar controller & worker tidak berbeda
     * putusan).
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

        $gate ??= new BillingGate();

        // (1) Aktor — siapa pun yang menekan tombol tetap harus punya kredit bila
        //     ia member. Menutup escape: owner dipindahkan ke user admin (bebas)
        //     sementara operator member-nya tetap mengoperasikan app tanpa saldo.
        $gate->assertCanStart($app, $user);

        // (2) Penanggung biaya (owner) — app yang dibagikan tetap ditagih ke
        //     ownernya, selaras dengan penilaian ulang di worker.
        $payer = $this->billingPayerFor($app, $user, $users);
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
     * Admin gratis dan user null (tanpa konteks login) dikecualikan — konsisten
     * dengan aturan lama `is_admin()`.
     */
    private function isBillingMember(?array $user): bool
    {
        return $user !== null && ($user['role'] ?? '') !== UserStore::ROLE_ADMIN;
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
     * Konteks billing **baca-saja** untuk view create/confirm/template
     * (kartu "Batas Sumber Daya" + estimasi biaya kredit).
     *
     * PENTING: method ini **tidak menegakkan** apa pun — penegakan tetap
     * `billingAssertCanCreate()`/`BillingGate` + `Pricing::assertWithinCaps()`.
     * Angka di view hanya lapisan bantuan; server tetap sumber kebenaran.
     *
     * `$user` diberikan pemanggil (tidak membaca `current_user()` di sini) agar
     * mudah diuji tanpa session. `$accounts`/`$gate` hanya untuk pengujian.
     *
     * @param array<int|string,mixed> $limits limit efektif yang dipakai menghitung `required`
     * @return array{enabled:bool,canManage:bool,member:bool,caps:array{cpus:float,memory_mb:int},rates:array<string,float>,defaults:array{cpus:float,memory_mb:int},balance:float,balance_text:string,required:float,required_text:string,estimate_hourly:float,estimate_hourly_text:string,estimate_month:float,estimate_month_text:string,sufficient:bool,days:int,topup_enabled:bool}
     */
    private function billingContext(
        array $limits,
        ?array $user,
        ?CreditAccount $accounts = null,
        ?BillingGate $gate = null
    ): array {
        $enabled = (bool) config('deploy.billing_enabled', true);
        $member = $this->isBillingMember($user);
        $days = (int) config('deploy.billing_min_deposit_days', 30);
        $rates = Pricing::rates();

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
            $required = $gate->requiredFor($limits);
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
     * @param array<int|string,mixed> $limits
     * @return array{enabled:bool,applies:bool,estimate_hourly:float,estimate_month:float,days:int,hourly_text:string,month_text:string,format:array{hourly:string,month:string}}
     */
    private function billingEstimateFor(array $limits, ?array $user): array
    {
        $enabled = (bool) config('deploy.billing_enabled', true);
        $days = (int) config('deploy.billing_min_deposit_days', 30);
        $rates = Pricing::rates();
        $hourly = Pricing::hourlyCredits($limits, $rates);
        $month = Pricing::estimate($limits, $rates, $days);

        return [
            'enabled' => $enabled,
            'applies' => $this->isBillingMember($user),
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
    private function validateAndApplyPorts(array $services, array $input): array
    {
        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);
        $usedPorts = $portManager->usedHostPorts((new AppStore())->all());

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
     * file compose asli dari repo tetap bersih (SPECS.md §7.2 langkah 8).
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
     * Spawn background worker deploy (detached, non-blocking).
     *
     * Dipakai pcntl_fork + pcntl_exec (tanpa shell, sesuai konvensi anti
     * command injection). Alasan: proc_open + proc_close BLOCKING sampai worker
     * selesai — request HTTP yang memanggilnya menggantung selama build (bisa
     * menit), sehingga timeout/refresh browser tampak "menggagalkan" deploy.
     * Dengan fork + exec + SIGCHLD:
     *   - request langsung kembali (hanya fork, tidak menunggu build);
     *   - worker berjalan detached (posix_setsid) dan tetap lanjut meski HTTP
     *     worker di-restart atau browser ditutup;
     *   - di HTTP worker SIGCHLD di-ignore (kernel otomatis reap anak => tanpa
     *     zombie); di worker anak SIGCHLD di-reset ke SIG_DFL sebelum exec agar
     *     proc_get_status/proc_close di dalam worker tetap membaca exit code
     *     proses anaknya (git/docker) dengan benar.
     */
    private function spawnWorker(string $appId, string $mode, ?string $arg = null): bool
    {
        $logDir = runtime_path('logs/deploy');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/' . $appId . '.log';

        $command = [PHP_BINARY, base_path('cli/deploy.php'), $appId, $mode];
        if ($arg !== null && $arg !== '') {
            $command[] = $arg;
        }

        // Otomatis-reap anak saat selesai supaya tidak menumpuk zombie.
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $pid = @pcntl_fork();
        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            // Proses anak: lepas dari sesi/terminal, ganti stdio ke /dev/null,
            // lalu ganti image proses menjadi worker (cli/deploy.php). Logging
            // ditangani worker sendiri via file_put_contents ke logFile.
            @posix_setsid();
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            // Buka ulang fd 0/1/2 ke /dev/null (fd reuse — tidak ada posix_dup2).
            // Variabel sengaja dipertahankan sampai pcntl_exec agar fd tetap terbuka.
            $nullIn = @fopen('/dev/null', 'r');
            $nullOut = @fopen('/dev/null', 'w');
            $nullErr = @fopen('/dev/null', 'w');
            // RESET SIGCHLD ke default sebelum exec. Tanpa ini worker mewarisi
            // SIGCHLD=SIG_IGN dari HTTP worker → kernel auto-reap proses anak
            // (git/docker) saat keluar, sehingga proc_get_status/proc_close di
            // ProcessRunner kehilangan exit code (selalu -1) dan perintah yang
            // sukses (mis. `git checkout main` → "Already on 'main'") dianggap
            // gagal. Disposisi SIG_DFL bertahan melewati exec.
            @pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_exec(PHP_BINARY, array_slice($command, 1));
            // Hanya tercapai bila exec gagal.
            exit(127);
        }

        return true;
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
