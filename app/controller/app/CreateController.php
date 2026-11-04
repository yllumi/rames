<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Auth\ServerAccess;
use app\library\Billing\BillingGate;
use app\library\Billing\CreditAccount;
use app\library\Billing\Pricing;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\EnvTemplate;
use app\library\Deploy\ServerResolver;
use app\library\Deploy\SubdomainManager;
use app\library\Docker\ComposeParser;
use app\library\Git\GitService;
use app\library\Git\SshKeyManager;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use app\library\Storage\ServerStore;
use app\library\Support\Markdown;
use app\library\System\HostUsage;
use app\library\Template\TemplateCatalog;
use RuntimeException;
use support\Request;

/**
 * Wizard create app — langkah analisis (docs/SPECS.md §7).
 *
 * Route: `GET /apps/create`, `POST /apps/create`, `POST /apps/create/compose`.
 * Clone/parse repo dan penyusunan rencana deploy ada di `app/library/**`.
 */
class CreateController extends AppArtifactControllerBase
{
    /**
     * Nama direktori akar untuk **clone analisis sementara** app di server agent
     * (di `sys_get_temp_dir()`): dipakai hanya untuk membaca file compose repo
     * pada langkah analisis wizard create, lalu dibuang — bukan artefak app.
     */
    private const REPO_ANALYSIS_DIR = 'rames-agent-repo-analysis';

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
    protected function resolveCreateServer(string $requestedId, ?array $user, ?ServerResolver $resolver = null): array
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
    protected function serverFormContext(?array $user, string $selectedServerId = '', ?ServerResolver $resolver = null): array
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
    protected function requestedServerId(Request $request): string
    {
        $raw = $request->post('server_id');

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

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

    /**
     * Resolusi konflik host port untuk daftar service hasil parse compose
     * (dipakai ketiga mode create — clone repo, compose, template).
     *
     * Scope server (multi-server F1): rentang port & port terpakai mengikuti
     * **server target** app baru — lihat {@see AppArtifactControllerBase::portContext()}.
     *
     * @param array<string,array> $services
     * @param string              $serverId id server target (''/`local` = lokal)
     * @return array<string,array>
     */
    protected function resolveServicePorts(array $services, string $serverId = ''): array
    {
        $context = $this->portContext($serverId);

        return $context['manager']->resolve($services, $context['used']);
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
    protected function assertSubdomainAvailable(string $label, string $name, string $excludeId = '', ?string $serverId = null): string
    {
        return (new SubdomainManager(new AppStore(), null, null, new ParkedDomainStore()))
            ->assertAvailable($label, $name, $excludeId, $serverId);
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
    protected function defaultLimitsFor(array $services): array
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
    protected function limitsScaleMax(?array $user = null, ?string $procPath = null): array
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
    protected function billingContext(
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
    protected function validateCreateName(string $name): void
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
}
