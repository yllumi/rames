<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccessDenied;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxStatusReader;
use app\library\Storage\AppStore;
use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullService;
use app\library\Template\TemplatePullState;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use RuntimeException;
use support\Request;

/**
 * Halaman & aksi Nginx (status + reload host dari dashboard).
 *
 * Nginx bersifat GLOBAL (berlaku untuk semua app) — status & tombol reload
 * dipindah ke halaman tersendiri /nginx, bukan di detail app.
 *
 * Halaman Config ini menampung tiga tab:
 *  - **Update** — panel **self-update dashboard** (SPECS.md §7.8),
 *  - **Nginx** — status reload Nginx host,
 *  - **Template** — daftar template + tombol `docker pull` (SPECS.md §7.13).
 *
 * Semuanya operasi host, sehingga digabung di satu halaman (tanpa menu nav baru).
 */
class NginxController
{
    /** Tab halaman Config yang boleh dipilih lewat POST `tab` (whitelist). */
    private const TABS = ['update', 'nginx', 'template'];

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
            // Tab awal halaman (`?tab=`): view hanya merender, jadi nilai sudah
            // divalidasi di sini (whitelist TABS) — bukan dibaca dari request di view.
            // Daftar tab ikut dikirim sebagai data agar view bisa fallback sendiri
            // tanpa menduplikasi whitelist.
            'tab' => $this->tabFromRequest($request),
            'tabs' => self::TABS,
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

    private function pullService(): TemplatePullService
    {
        return new TemplatePullService(
            new TemplateCatalog(),
            new TemplatePullState(
                (string) config('deploy.template_pull_run_dir', runtime_path('logs/template-pull'))
            )
        );
    }

    /**
     * POST /nginx/templates/pull — tarik ulang SEMUA image satu template
     * (`docker pull` per image, dijalankan worker detached `cli/pull.php`).
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
        try {
            $result = $this->pullService()->start($slug, (string) (current_user()['username'] ?? ''));
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
     * **Admin-only** (code 403 untuk non-admin) — satu-satunya konsumennya panel
     * di halaman Config yang memang admin-only.
     */
    public function pullStatus(Request $request)
    {
        if (!$this->isAdmin()) {
            return json(['code' => 403, 'msg' => 'Status tarik image hanya bisa dibaca admin.']);
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
     * pengirim menyertakan POST `tab` yang sah (`update|nginx|template`) dan
     * kembali ke halaman Config, tab aktif dipertahankan (`/nginx?tab=<tab>`)
     * supaya tidak melompat ke tab Update setiap kali reload Nginx ditekan.
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
