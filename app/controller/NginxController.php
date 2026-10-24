<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccessDenied;
use app\library\Agent\AgentError;
use app\library\Branding\BrandingLogo;
use app\library\Branding\BrandingManager;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxStatusReader;
use app\library\Storage\AppStore;
use app\library\Storage\BrandingStore;
use app\library\Storage\ServerStore;
use app\library\Template\AgentTemplatePull;
use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullService;
use app\library\Template\TemplatePullState;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Webman\Http\UploadFile;

/**
 * Halaman & aksi Nginx (status + reload host dari dashboard).
 *
 * Nginx bersifat GLOBAL (berlaku untuk semua app) — status & tombol reload
 * dipindah ke halaman tersendiri /nginx, bukan di detail app.
 *
 * Halaman Config ini menampung empat tab:
 *  - **Update** — panel **self-update dashboard** (SPECS.md §7.8),
 *  - **Nginx** — status reload Nginx host,
 *  - **Template** — daftar template + tombol `docker pull` (SPECS.md §7.13),
 *  - **Branding** — nama brand + logo dashboard (rebranding).
 *
 * Multi-server (F4 lanjutan): tab Template bisa diarahkan ke **server agent**
 * (POST/GET `server=<id>`). Pull untuk server agent dijalankan **di server itu**
 * (`AgentTemplatePull` → job async `template_pull` di agen) sehingga image masuk
 * ke daemon server tersebut; server lokal tetap memakai jalur lama
 * (`TemplatePullService` + worker `cli/pull.php` di sini). State/riwayat pull
 * dipisah per server (`TemplatePullState` ber-kunci id server). Halaman ini
 * mengirim daftar server (`servers`) agar UI dapat menawarkan pemilih server —
 * rendering pemilihnya ada di misi UI.
 *
 * Semuanya operasi host, sehingga digabung di satu halaman (tanpa menu nav baru).
 */
class NginxController
{
    /** Tab halaman Config yang boleh dipilih lewat POST `tab` (whitelist). */
    private const TABS = ['update', 'nginx', 'template', 'branding'];

    /**
     * GET /nginx — status reload Nginx host terakhir + tombol Reload + panel update
     * + daftar template (tab Template).
     *
     * **Admin-only** (penolakan **404**, bukan 403 — konsisten dengan halaman
     * operasional host lain): halaman ini berisi operasi host (Nginx global) dan
     * panel self-update dashboard. Menu "Config" juga disembunyikan dari member;
     * namun penyembunyian UI **bukan** pengaman — pemeriksaan di sini yang menolak.
     */
    public function index(Request $request)
    {
        if (!$this->isAdmin()) {
            throw new AppAccessDenied('nginx');
        }

        $status = (new NginxStatusReader((string) config('deploy.nginx_reload_status_file')))->lastReload();
        return view('nginx/index', [
            'status' => $status,
            'update' => $this->updatePanel(),
            'templates' => $this->templatesPanel(),
            'pull' => $this->pullPanel(),
            // Panel rebranding (tab Branding): identitas brand aktif + batas
            // unggahan. Kegagalan baca store dikembalikan ke default di dalam
            // `brandingPanel()` supaya halaman Config tidak pernah 500.
            'branding' => $this->brandingPanel(),
            // Pemilih server untuk tab Template (multi-server F4 lanjutan): id/nama/
            // kind/capabilities — **tanpa** token/PEM/base_url. Panel per server
            // diambil on-demand lewat `GET /nginx/templates/pull/status?server=<id>`
            // (bukan saat render) supaya halaman tidak menunggu jaringan ke agent.
            'servers' => $this->serverOptions(),
            // Tab awal halaman (`?tab=`): view hanya merender, jadi nilai sudah
            // divalidasi di sini (whitelist TABS) — bukan dibaca dari request di view.
            // Daftar tab ikut dikirim sebagai data agar view bisa fallback sendiri
            // tanpa menduplikasi whitelist.
            'tab' => $this->tabFromRequest($request),
            'tabs' => self::TABS,
            // Versi PHP proses worker yang merender halaman ini — ditampilkan di
            // header Config supaya admin tahu runtime dashboard yang sedang aktif.
            'php_version' => PHP_VERSION,
        ]);
    }

    /**
     * Tab awal dari query `?tab=` (whitelist `TABS`, selain itu `update`).
     */
    private function tabFromRequest(Request $request): string
    {
        $tab = $request->get('tab');

        return is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'update';
    }

    /**
     * Seam pengujian (pola `CreditController`): produksi memakai helper global
     * `is_admin()` yang membaca user session.
     */
    protected function isAdmin(): bool
    {
        return is_admin();
    }

    /**
     * Nama aktor untuk jejak audit operasi host ini (seam uji: tanpa session).
     */
    protected function actorName(): string
    {
        return (string) (current_user()['username'] ?? '');
    }

    /**
     * Data panel self-update; `null` bila fitur dimatikan, atau memuat kunci
     * `failed` bila pembacaannya gagal (halaman tetap tampil, tidak 500).
     *
     * @return array<string,mixed>|null
     */
    private function updatePanel(): ?array
    {
        if (!(bool) config('deploy.update_enabled', true)) {
            return null;
        }

        try {
            $service = new UpdateService(
                new RepoInfo((string) config('deploy.update_path', base_path())),
                new UpdateState(
                    (string) config('deploy.update_check_file', runtime_path('update/check.json')),
                    (string) config('deploy.update_run_dir', runtime_path('logs/update'))
                ),
                null,
                null,
                (string) config('deploy.update_branch', '')
            );

            $panel = $service->panel();
            // Ekor log run terakhir untuk tampilan awal panel (JS menyegarkannya
            // lewat /api/update/status saat update berjalan).
            $logName = (string) ($panel['run']['log'] ?? '');
            $panel['log_tail'] = $logName !== '' ? $service->state()->logTail($logName, 12000) : '';

            return $panel;
        } catch (\Throwable $e) {
            return ['failed' => $e->getMessage()];
        }
    }

    /**
     * Daftar template + image-nya untuk tab Template (SPECS.md §7.13).
     *
     * `images` = hasil `TemplateCatalog::images()` (murni — dari service hasil
     * parse, bukan regex atas compose), `apps` = aplikasi yang dibuat dari
     * template itu (field `template.slug` di store app). Daftar app bersifat
     * informasional bagi admin: ia menjawab "image siapa yang ikut terbarui bila
     * tombol ini ditekan" — kegagalan membacanya (store rusak) TIDAK boleh
     * menggagalkan halaman, jadi hanya daftar app yang dikosongkan.
     *
     * @return array<int,array<string,mixed>>
     */
    private function templatesPanel(): array
    {
        $appsBySlug = [];
        try {
            $appsBySlug = $this->appsByTemplate();
        } catch (\Throwable) {
            // daftar app informasional — jangan gagalkan halaman karenanya
        }

        $templates = [];
        foreach ((new TemplateCatalog())->all() as $template) {
            $slug = (string) $template['slug'];
            $templates[] = [
                'slug' => $slug,
                'title' => (string) $template['title'],
                'description' => (string) $template['description'],
                'category' => (string) $template['category'],
                'icon' => (string) $template['icon'],
                'docs_url' => (string) $template['docs_url'],
                'valid' => (bool) $template['valid'],
                'error' => $template['error'] !== null ? (string) $template['error'] : null,
                'images' => TemplateCatalog::images($template),
                'apps' => $appsBySlug[$slug] ?? [],
            ];
        }

        return $templates;
    }

    /**
     * Peta `slug template` → daftar app yang memakainya (`{id, name}`).
     *
     * @return array<string,array<int,array{id:string,name:string}>>
     */
    private function appsByTemplate(): array
    {
        $map = [];
        foreach ((new AppStore())->all() as $app) {
            $template = $app['template'] ?? null;
            $slug = is_array($template) ? (string) ($template['slug'] ?? '') : '';
            if ($slug === '') {
                continue;
            }
            $map[$slug][] = [
                'id' => (string) ($app['id'] ?? ''),
                'name' => (string) ($app['name'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * Data panel tarik image; memuat kunci `failed` bila pembacaannya gagal
     * supaya halaman tetap tampil (pola `updatePanel()`).
     *
     * @return array<string,mixed>|null
     */
    private function pullPanel(): ?array
    {
        try {
            return $this->pullService()->panel();
        } catch (\Throwable $e) {
            return ['failed' => $e->getMessage()];
        }
    }

    /**
     * Data tab Branding: identitas brand aktif + batas unggahan untuk view.
     *
     * Kegagalan membaca store (SQLite rusak/terkunci) **tidak** boleh membuat
     * halaman Config 500 — panel jatuh ke default (`Rames` + logo bawaan) dan
     * halaman tetap tampil. Nilai default sengaja ditulis eksplisit (bukan
     * memanggil store lagi) agar kegagalan yang sama tidak terulang.
     *
     * @return array<string,mixed>
     */
    private function brandingPanel(): array
    {
        try {
            $panel = $this->branding()->current();
        } catch (\Throwable) {
            $panel = [
                'name' => BrandingStore::DEFAULT_NAME,
                'logo_url' => BrandingManager::DEFAULT_LOGO_URL,
                'logo_custom' => false,
                'updated_at' => null,
            ];
        }

        return $panel + [
            'max_name' => BrandingManager::MAX_NAME,
            'max_bytes' => BrandingLogo::MAX_BYTES,
            'allowed' => 'PNG, JPEG, WebP',
        ];
    }

    /**
     * Orkestrasi branding (seam uji: manager dengan store/logo temp).
     * Tanpa properti state — instance baru per pemanggilan (worker persistent).
     */
    protected function branding(): BrandingManager
    {
        return new BrandingManager();
    }

    /**
     * Flash session (seam uji, pola `CreditController::flash()`).
     */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }

    /**
     * Berkas logo yang benar-benar diunggah, atau `null` bila tidak ada.
     *
     * Bentuk `['tmp','mime','size','error','name']` sesuai kontrak
     * `BrandingLogo::save()`. Nama asli unggahan hanya dipakai untuk mendeteksi
     * field kosong — **tidak** pernah dicatat/log/ditampilkan. Bentuk
     * `logo[]=…` (defensif) diambil elemen pertamanya.
     *
     * @return array{tmp:string,mime:string,size:int,error:int,name:string}|null
     */
    protected function uploadedLogo(Request $request): ?array
    {
        $file = $request->file('logo');
        if (is_array($file)) {
            $file = array_values($file)[0] ?? null;
        }
        if (!$file instanceof UploadFile) {
            return null;
        }

        $uploadName = trim((string) $file->getUploadName());
        if ($uploadName === '') {
            return null;
        }

        $tmp = (string) $file->getPathname();
        $mime = trim((string) ($file->getUploadMimeType() ?? ''));
        if ($mime === '' && $tmp !== '' && is_file($tmp)) {
            // Klien tidak mengirim tipe: deteksi isi (validasi akhir tetap
            // dilakukan `BrandingLogo` lewat `getimagesize()`).
            $mime = trim((string) @mime_content_type($tmp));
        }

        try {
            $size = (int) $file->getSize();
        } catch (\Throwable) {
            $size = 0;
        }

        return [
            'tmp' => $tmp,
            'mime' => $mime,
            'size' => $size,
            'error' => (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE),
            'name' => $uploadName,
        ];
    }

    /**
     * Service tarik image **server lokal** (seam uji: worker & spawner palsu).
     * `protected` supaya tes dapat menggantinya tanpa menjalankan `cli/pull.php`.
     */
    protected function pullService(): TemplatePullService
    {
        return new TemplatePullService(
            new TemplateCatalog(),
            new TemplatePullState(
                (string) config('deploy.template_pull_run_dir', runtime_path('logs/template-pull'))
            )
        );
    }

    /**
     * Server lokal? `''`/`local` = jalur lama (worker `cli/pull.php` di sini).
     * Selain itu id harus ada di registry (resolusi dilakukan pemanggil).
     */
    protected function isLocalServer(string $serverId): bool
    {
        $serverId = trim($serverId);

        return $serverId === '' || $serverId === ServerStore::LOCAL_ID;
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
     * Deployer agent untuk satu dokumen server (seam uji) — pola yang sama
     * dengan `BackupController::agentDeployerForServer()`.
     *
     * @param array<string,mixed> $server
     */
    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return AgentDeployer::forServer($server);
    }

    /**
     * Koordinator tarik image **di server agent** untuk satu dokumen server —
     * state/riwayat dipisah per server (`TemplatePullState` ber-kunci id server).
     *
     * `kind=local` tidak pernah sampai ke sini (ditolak `isLocalServer()` di
     * pemanggil) ⇒ tidak ada fallback eksekusi lokal untuk server agent.
     *
     * @param array<string,mixed> $server
     * @throws RuntimeException server agent tidak bisa dibangun (kind tak dikenal)
     */
    protected function agentPull(array $server): AgentTemplatePull
    {
        $serverId = trim((string) ($server['id'] ?? ''));
        $deployer = $this->agentDeployerForServer($server);
        if (!$deployer instanceof AgentDeployer) {
            throw new RuntimeException('Tarik image di server agent membutuhkan server `kind=agent` yang valid.');
        }

        return new AgentTemplatePull(
            $deployer,
            new TemplatePullState(
                (string) config('deploy.template_pull_run_dir', runtime_path('logs/template-pull')),
                $serverId
            ),
            $serverId,
            (string) ($server['name'] ?? ''),
            // Dokumen server untuk redaksi pesan galat yang dipantulkan agent
            // (`AgentError::sanitizeForServer()` — `base_url`/token/PEM).
            $server
        );
    }

    /**
     * Daftar server untuk pemilih di tab Template (`ServerResolver::option()`:
     * id/nama/kind/domain/enabled/status/capabilities — tanpa rahasia).
     *
     * Kegagalan membaca registry **tidak** boleh menggagalkan halaman (pola
     * `templatesPanel()`): daftarnya hanya untuk pemilih, jadi dikosongkan.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function serverOptions(): array
    {
        try {
            return array_map(
                static fn (array $server): array => ServerResolver::option($server),
                $this->serverResolver()->all()
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * POST /nginx/templates/pull — tarik ulang SEMUA image satu template
     * (`docker pull` per image).
     *
     * Dua jalur, dipilih dari POST `server` (id server):
     *  - **lokal/`local`/kosong** — jalur lama: worker detached `cli/pull.php` di
     *    server ini (`TemplatePullService`);
     *  - **server agent** — pull dijalankan **di server itu** lewat job async
     *    (`AgentTemplatePull` → `template_pull`); image masuk ke daemon server
     *    tersebut. Control plane **tidak pernah** menarik image untuk server itu.
     *
     * **Admin-only** (code 403 untuk non-admin), bentuk respons sama dengan
     * `UpdateController`: `json(['code' => 0|400|403, 'msg' => …, 'data' => …])`.
     */
    public function pullTemplates(Request $request)
    {
        if (!$this->isAdmin()) {
            return json(['code' => 403, 'msg' => 'Menarik image template hanya bisa dijalankan admin.']);
        }

        $slug = trim((string) $request->post('slug', ''));
        $serverId = trim((string) $request->post('server', ''));
        $actor = $this->actorName();

        if (!$this->isLocalServer($serverId)) {
            $server = null;
            try {
                $server = $this->serverResolver()->resolve($serverId);
                $result = $this->agentPull($server)->start($slug, $actor);
            } catch (AgentError $e) {
                // Galat transport menyisipkan `base_url`/host agent; buang identitas
                // server lewat pintu tunggal sebelum dikirim ke UI.
                return json(['code' => 400, 'msg' => AgentError::sanitizeForServer($e->getMessage(), is_array($server) ? $server : [])]);
            } catch (RuntimeException $e) {
                // Jalur yang sama bisa membawa pesan dari job agent (mis. galat
                // pembacaan state) — tetap lewat pintu tunggal; dokumen kosong
                // membuatnya setara `sanitize()` biasa.
                return json(['code' => 400, 'msg' => AgentError::sanitizeForServer($e->getMessage(), is_array($server) ? $server : [])]);
            }

            return json([
                'code' => 0,
                'data' => array_merge($result, ['images' => []]),
                'msg' => 'Menarik image template "' . $slug . '" di server "' . (string) ($server['name'] ?? $serverId)
                    . '". Progresnya bisa dipantau di panel server itu.',
            ]);
        }

        try {
            $result = $this->pullService()->start($slug, $actor);
        } catch (RuntimeException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => 'Gagal memulai tarik image: ' . $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'data' => $result,
            'msg' => 'Menarik ' . count($result['images']) . ' image template "' . $slug . '". Progres bisa dipantau di bawah.',
        ]);
    }

    /**
     * GET /nginx/templates/pull/status — status run tarik image (dipoll UI).
     *
     * `?server=<id>` memilih state **server itu** (state per server tidak pernah
     * tercampur); tanpa parameter/kosong = server lokal (perilaku lama).
     *
     * **Admin-only** (code 403 untuk non-admin) — satu-satunya konsumennya panel
     * di halaman Config yang memang admin-only.
     */
    public function pullStatus(Request $request)
    {
        if (!$this->isAdmin()) {
            return json(['code' => 403, 'msg' => 'Status tarik image hanya bisa dibaca admin.']);
        }

        $serverId = trim((string) $request->get('server', ''));

        if (!$this->isLocalServer($serverId)) {
            $server = null;
            try {
                $server = $this->serverResolver()->resolve($serverId);
                $panel = $this->agentPull($server)->panel();
            } catch (\Throwable $e) {
                return json([
                    'code' => 400,
                    'msg' => 'Gagal membaca status tarik image: '
                        . AgentError::sanitizeForServer($e->getMessage(), is_array($server) ? $server : []),
                ]);
            }

            return json([
                'code' => 0,
                'data' => [
                    'server_id' => $serverId,
                    'running' => $panel['running'],
                    'run' => $panel['run'],
                    'log_tail' => $panel['log_tail'],
                    'logs' => $panel['logs'],
                ],
            ]);
        }

        try {
            $panel = $this->pullService()->panel();
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => 'Gagal membaca status tarik image: ' . $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'data' => [
                'running' => $panel['running'],
                'run' => $panel['run'],
                'log_tail' => $panel['log_tail'],
                'logs' => $panel['logs'],
            ],
        ]);
    }

    /**
     * POST /nginx/branding — simpan nama brand (dan logo bila diunggah).
     *
     * **Admin-only**: penolakan **404** (`AppAccessDenied('nginx')`, pola
     * `index()`) dan diperiksa **sebelum** efek samping apa pun. Validasi nama
     * dilakukan `BrandingManager::update()`; pesan galatnya diteruskan apa
     * adanya ke flash. Respons selalu redirect ke tab Branding (PRG), termasuk
     * saat gagal — halaman tidak pernah dirender ulang dari POST.
     */
    public function saveBranding(Request $request)
    {
        if (!$this->isAdmin()) {
            throw new AppAccessDenied('nginx');
        }

        $name = $request->post('name', '');
        if (!is_scalar($name)) {
            // `name[]=…` bukan string: tolak sebagai input tidak valid (bukan 500).
            $this->flash('error', 'Nama brand tidak valid.');

            return redirect('/nginx?tab=branding');
        }

        try {
            $this->branding()->update(trim((string) $name), $this->uploadedLogo($request));
            $this->flash('success', 'Branding diperbarui.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/nginx?tab=branding');
    }

    /**
     * POST /nginx/branding/reset — kembalikan brand ke default (`Rames`, logo
     * bawaan). Admin-only dengan penolakan 404 sebelum efek samping; kegagalan
     * apa pun hanya menjadi flash error (halaman tetap dapat dibuka).
     */
    public function resetBranding(Request $request)
    {
        if (!$this->isAdmin()) {
            throw new AppAccessDenied('nginx');
        }

        try {
            $this->branding()->reset();
            $this->flash('success', 'Branding dikembalikan ke default (' . BrandingStore::DEFAULT_NAME . ').');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/nginx?tab=branding');
    }

    /**
     * POST /nginx/reload — validasi (nginx -t) lalu reload (nginx -s reload)
     * pada nginx HOST. Hasil ditulis ke last-reload.json (dibaca NginxStatusReader).
     */
    public function reload(Request $request)
    {
        if (!is_admin()) {
            flash_set('error', 'Reload Nginx berlaku untuk seluruh host — hanya admin yang boleh melakukannya.');
            return redirect('/nginx');
        }

        $result = (new NginxReloader())->reload();
        if ($result['ok']) {
            flash_set('success', $result['message']);
        } else {
            flash_set('error', 'Reload Nginx GAGAL: ' . ($result['error'] ?? 'unknown error'));
        }

        return redirect($this->configBackUrl(
            (string) $request->header('referer', ''),
            trim((string) $request->post('tab', ''))
        ));
    }

    /**
     * URL kembali setelah aksi halaman Config.
     *
     * Referer hanya boleh **path internal** (menolak URL absolut/protokol-relatif
     * → anti open redirect) — perilaku ini TIDAK berubah. Tambahan baru: bila
     * pengirim menyertakan POST `tab` yang sah
     * (`update|nginx|template|branding`) dan kembali ke halaman Config, tab
     * aktif dipertahankan (`/nginx?tab=<tab>`) supaya tidak melompat ke tab
     * Update setiap kali reload Nginx ditekan.
     */
    protected function configBackUrl(string $referer, string $tab): string
    {
        $back = $referer;
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || str_starts_with($back, '/\\')) {
            $back = '/apps';
        }

        $path = $back;
        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }

        if (in_array($tab, self::TABS, true) && rtrim($path, '/') === '/nginx') {
            $back = '/nginx?tab=' . $tab;
        }

        return $back;
    }
}
