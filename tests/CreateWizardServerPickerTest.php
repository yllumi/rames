<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render wizard create app — pemilihan server tujuan (multi-server).
 *
 * View repo ini tidak bisa dirender di test biasa (template memanggil helper
 * global yang butuh session/request Webman). Pola yang dipakai sama dengan
 * `tests/MultiServerViewTest.php`: tiap test berjalan di **proses terpisah**
 * dengan helper global di-stub lewat berkas temp, sehingga stub tidak bocor.
 *
 * Kontrak yang dijaga (SPECS.md §2a — "Harus ada pemilihan di server mana app
 * diinstal saat create app"):
 *  (i)   admin dengan >1 server ⇒ `<select name="server_id">` **di dalam** form
 *        POST yang ada: `/apps/create` (git), `/apps/create/compose`,
 *        `/apps/create/template/{slug}`; opsi `value=id` + `selected` untuk
 *        server terpilih + `disabled` untuk server `enabled=false`;
 *  (ii)  non-admin / hanya satu server / server lokal ⇒ **tidak ada** select,
 *        hanya catatan read-only, dan nama server lain tidak dirender;
 *  (iii) langkah konfirmasi ⇒ ringkasan read-only + `<input type="hidden"
 *        name="server_id">` (tanpa select);
 *  (iv)  tautan navigasi wizard membawa `server_id` **hanya** bila server
 *        terpilih bukan `local`, tanpa merusak `?mode=…`, nilai di-`rawurlencode()`
 *        dan atribut `href` di-`e()`;
 *  (v)   payload absen/kosong/rusak (array/objek di field teks) ⇒ render tanpa
 *        warning/notice (harness ini mengubah warning PHP menjadi exception);
 *  (vi)  tidak ada JS/`fetch()` baru di blok pemilih.
 *
 * Test ini hanya merender berkas view; tidak menyentuh store, Docker, jaringan,
 * `database/`, `apps/`, maupun `nginx-status/`.
 */
final class CreateWizardServerPickerTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /** Kelas badge yang baku ada di `public/css/app.css`. */
    private const ALLOWED_BADGE_CLASSES = ['badge-running', 'badge-error', 'badge-unknown', 'badge-stopped'];

    private const STUB_SOURCE = <<<'PHP'
        <?php
        /**
         * Stub helper dashboard untuk smoke render wizard create
         * (tests/CreateWizardServerPickerTest.php). Hanya hidup di proses anak
         * proses-terpisah PHPUnit.
         */
        namespace {
            if (!function_exists('e')) {
                function e(mixed $value): string
                {
                    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            if (!function_exists('csrf_token')) {
                function csrf_token(): string
                {
                    return 'smoke-csrf-token';
                }
            }
            if (!function_exists('csrf_field')) {
                function csrf_field(): string
                {
                    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
                }
            }
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return true;
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                }
            }
            if (!function_exists('update_badge')) {
                function update_badge(): array
                {
                    return ['available' => false];
                }
            }
            if (!function_exists('current_credit_balance')) {
                function current_credit_balance(): ?float
                {
                    return null;
                }
            }
            if (!function_exists('format_credits')) {
                function format_credits(float $value, int $decimals = 2): string
                {
                    return number_format($value, $decimals, ',', '.');
                }
            }
            if (!function_exists('config')) {
                function config(string $key, mixed $default = null): mixed
                {
                    $map = [
                        'deploy.app_domain' => 'contoh.id',
                        'deploy.port_range.start' => 10000,
                        'deploy.port_range.end' => 10999,
                        'deploy.apps_path' => '/tmp/rames-wizard-test-apps',
                    ];

                    return $map[$key] ?? $default;
                }
            }
            if (!function_exists('app_path')) {
                function app_path(string $path = ''): string
                {
                    // Diarahkan ke salinan view (uji mutasi) lewat global;
                    // default = akar repo.
                    $base = $GLOBALS['RAMES_WIZARD_APP_PATH'] ?? dirname(__DIR__) . '/app';

                    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
                }
            }
            if (!function_exists('template_logo_map')) {
                function template_logo_map(?string $dir = null): array
                {
                    return [];
                }
            }
            if (!function_exists('app_subdomain_valid')) {
                function app_subdomain_valid(mixed $value = null, mixed $resolver = null): bool
                {
                    return true;
                }
            }
        }
        PHP;

    protected function setUp(): void
    {
        unset($GLOBALS['RAMES_WIZARD_APP_PATH']);
    }

    // ==================================================================
    // (i) admin multi-server: select di dalam setiap form POST
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminSeesServerPickerInsideGitAndComposeForms(): void
    {
        $context = $this->adminMultiServerContext();

        foreach ([['git', '/apps/create'], ['compose', '/apps/create/compose']] as [$mode, $action]) {
            $html = $this->renderView('app/create.php', $context + ['mode' => $mode]);

            $this->assertServerPickerInsideForm($html, $action);
            self::assertStringContainsString('id="' . $mode . '-server-id"', $html);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminSeesServerPickerInsideTemplateForm(): void
    {
        $html = $this->renderView('app/template.php', $this->adminMultiServerContext() + $this->templateVars());

        $this->assertServerPickerInsideForm($html, '/apps/create/template/wordpress');
    }

    /** Kurung <select> wajib berada di dalam blok `<form action="...">`. */
    private function assertServerPickerInsideForm(string $html, string $action): void
    {
        self::assertSame(1, $this->countServerSelects($html), 'harus ada tepat satu <select name="server_id">');

        $inner = null;
        foreach ($this->formRegions($html) as $region) {
            if ($region['action'] === $action) {
                $inner = $region['inner'];
            }
        }
        self::assertNotNull($inner, 'form ' . $action . ' tidak ditemukan');
        self::assertStringContainsString('name="server_id"', $inner, 'select wajib DI DALAM form ' . $action);
        self::assertStringContainsString('name="_token"', $inner, 'csrf_field() wajib tetap ada di form ' . $action);
        self::assertStringContainsString('method="post"', $inner, 'method form tidak boleh berubah');

        // Opsi: value=id, selected hanya untuk server terpilih, disabled untuk nonaktif.
        self::assertSame(3, preg_match_all('/<option\b/', $inner), 'tiga server ⇒ tiga opsi');
        self::assertStringContainsString('<option value="local" selected>', $inner, 'server lokal harus terpilih (default)');
        self::assertMatchesRegularExpression(
            '/<option value="srv-jkt">[^<]*VPS Jakarta[^<]*agent[^<]*jkt\.contoh\.id/',
            $inner,
            'label opsi memuat nama + jenis + domain'
        );
        self::assertDoesNotMatchRegularExpression(
            '/<option value="srv-jkt"[^>]*\bdisabled\b/',
            $inner,
            'server aktif tidak boleh disabled'
        );
        self::assertMatchesRegularExpression(
            '/<option value="srv-sby"[^>]*\bdisabled\b[^>]*>[^<]*VPS Surabaya[^<]*\(nonaktif\)/',
            $inner,
            'server enabled=false ⇒ opsi disabled + penanda (nonaktif)'
        );
        self::assertDoesNotMatchRegularExpression('/<option value="srv-sby"[^>]*\bselected\b/', $inner);
        self::assertStringContainsString('aktifkan dulu di halaman Servers', $inner, 'catatan kesiapan untuk server nonaktif');
        self::assertStringContainsString('Agent belum online', $inner, 'catatan kesiapan untuk agent offline');
        self::assertStringContainsString('badge-error', $inner, 'status offline memakai badge error (bukan dirakit dari data)');
        // Agent online & aktif tidak diberi catatan kesiapan (tidak ada noise).
        self::assertStringNotContainsString('Server <strong>VPS Jakarta</strong>', $inner);
        $picker = $this->pickerBlock($inner);
        self::assertSame(
            2,
            substr_count($picker, '<div class="form-text">'),
            'blok pemilih = satu hint umum + satu catatan untuk server bermasalah'
        );

        // Kelas badge hanya dari himpunan baku repo (`public/css/app.css`).
        // Hanya kelas ber-prefix `badge-` yang diperiksa: utility Bootstrap
        // (`text-bg-*`) milik markup lain (mis. label env "wajib").
        preg_match_all('/class="badge (badge-[a-z-]+)"/', $inner, $badges);
        foreach ($badges[1] as $badgeClass) {
            self::assertContains($badgeClass, self::ALLOWED_BADGE_CLASSES, 'kelas badge di luar himpunan baku: ' . $badgeClass);
        }
        self::assertNotSame([], $badges[1], 'blok pemilih wajib memakai badge status baku');
    }

    /** Potongan HTML blok pemilih (label "Server tujuan" s/d label berikutnya). */
    private function pickerBlock(string $formHtml): string
    {
        $start = strpos($formHtml, 'Server tujuan');
        self::assertIsInt($start, 'label "Server tujuan" tidak ditemukan');
        $end = strpos($formHtml, '<label class="form-label"', $start + 1);
        self::assertIsInt($end, 'blok pemilih tidak diikuti label lain');

        return (string) substr($formHtml, $start, $end - $start);
    }

    /**
     * (vi) Blok pemilih server ditambahkan **tanpa** JS baru: tidak ada
     * `<script>`, `fetch()`, atau handler inline. Diperiksa di tingkat sumber
     * (form template memang sudah punya `<script>` lama untuk auto-slug).
     */
    public function testPickerMarkupAddsNoJavaScript(): void
    {
        $markers = [
            'app/create.php' => ['compose-server-id', 'git-server-id'],
            'app/template.php' => ['template-server-id'],
        ];

        $seen = 0;
        foreach ($markers as $view => $ids) {
            $source = (string) file_get_contents(self::VIEW_DIR . '/' . $view);
            foreach ($ids as $id) {
                $start = strpos($source, 'for="' . $id . '">Server tujuan</label>');
                self::assertIsInt($start, $view . ': label pemilih "' . $id . '" tidak ditemukan');
                $end = strpos($source, '<?php else: ?>', $start);
                self::assertIsInt($end, $view . ': blok pemilih "' . $id . '" tidak tertutup cabang else');

                $block = (string) substr($source, $start, $end - $start);
                foreach (['<script', 'fetch(', 'addEventListener', 'onchange', 'XMLHttpRequest', 'dialog'] as $needle) {
                    self::assertStringNotContainsString($needle, $block, $view . ' (' . $id . '): blok pemilih server tidak boleh memakai ' . $needle);
                }

                // Kontrol yang dikirim dari blok ini: hanya select `server_id`.
                preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="([^"]*)"/', $block, $names);
                self::assertSame(['server_id'], $names[1], $view . ' (' . $id . '): kontrol tak terduga di blok pemilih');
                $seen++;
            }
        }

        self::assertSame(3, $seen, 'tiga form (git, compose, template) harus punya blok pemilih');
    }

    // ==================================================================
    // (ii) non-admin / satu server / server lokal
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdminGetsNoPickerAndNoForeignServerName(): void
    {
        $context = ['servers' => [$this->localOption()], 'selectedServerId' => 'local', 'canSelectServer' => false];

        $views = [
            'app/create.php' => ['mode' => 'git'],
            'app/template.php' => $this->templateVars(),
            'app/confirm.php' => $this->confirmVars('local'),
        ];

        foreach ($views as $view => $vars) {
            $html = $this->renderView($view, $context + $vars);

            self::assertSame(0, $this->countServerSelects($html), $view . ': non-admin tidak boleh mendapat select server');
            self::assertStringContainsString('Server Ini', $html, $view . ': catatan read-only server lokal tetap ada');
            foreach (['VPS Jakarta', 'jkt.contoh.id', 'srv-jkt', 'VPS Surabaya'] as $secret) {
                self::assertStringNotContainsString($secret, $html, $view . ': nama/domain/id server lain tidak boleh dirender');
            }
            self::assertStringNotContainsString('server_id=', $html, $view . ': tautan non-admin tidak boleh membawa server_id');
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLocalOnlySelectionKeepsNavigationClean(): void
    {
        // Admin multi-server tetap melihat select (ia memang boleh memilih); yang
        // bersih saat pilihan = server lokal adalah tautan navigasinya.
        foreach ([['app/create.php', 'git'], ['app/create.php', 'compose']] as [$view, $mode]) {
            $html = $this->renderView($view, $this->adminMultiServerContext() + ['mode' => $mode]);

            self::assertSame(1, $this->countServerSelects($html), 'select tetap ada untuk admin (mode ' . $mode . ')');
            self::assertStringNotContainsString('server_id=', $html, 'pilihan lokal ⇒ tautan wizard tanpa server_id (mode ' . $mode . ')');
        }

        $gallery = $this->renderView('app/create.php', $this->adminMultiServerContext() + [
            'mode' => 'template',
            'templates' => [$this->templateOption()],
        ]);
        self::assertStringNotContainsString('server_id=', $gallery, 'pilihan lokal ⇒ tautan navigasi galeri bersih');
        self::assertStringContainsString('href="/apps/create?mode=template"', $gallery);
        self::assertStringContainsString('href="/apps/create?mode=compose"', $gallery);
        self::assertStringContainsString('href="/apps/create/template/wordpress"', $gallery);

        $confirm = $this->renderView('app/confirm.php', $this->adminMultiServerContext() + $this->confirmVars('local'));
        self::assertStringNotContainsString('server_id=local', $confirm, 'pilihan lokal ⇒ URL tanpa parameter (absen = lokal)');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSingleServerAdminGetsReadOnlyNote(): void
    {
        $context = ['servers' => [$this->localOption()], 'selectedServerId' => 'local', 'canSelectServer' => true];
        $html = $this->renderView('app/create.php', $context + ['mode' => 'git']);

        self::assertSame(0, $this->countServerSelects($html), 'satu server ⇒ tidak ada select');
        self::assertStringContainsString('App akan dibuat di server <strong>Server Ini</strong>', $html);
    }

    // ==================================================================
    // (iii) langkah konfirmasi
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfirmStepSendsHiddenServerIdWithoutSelect(): void
    {
        $agent = $this->adminMultiServerContext();
        $agent['selectedServerId'] = 'srv-jkt';
        $html = $this->renderView('app/confirm.php', $agent + $this->confirmVars('srv-jkt'));

        self::assertSame(0, $this->countServerSelects($html), 'konfirmasi tidak boleh punya select');

        $inner = null;
        foreach ($this->formRegions($html) as $region) {
            if ($region['action'] === '/apps/create/confirm') {
                $inner = $region['inner'];
            }
        }
        self::assertNotNull($inner, 'form konfirmasi tidak ditemukan');
        self::assertStringContainsString('<input type="hidden" name="server_id" value="srv-jkt">', $inner);
        self::assertStringContainsString('name="_token"', $inner);
        self::assertSame(1, preg_match_all('/name="server_id"/', $inner), 'server_id hanya sekali (hidden), tanpa kolisi');

        // Ringkasan read-only nama server terpilih.
        self::assertStringContainsString('<strong>VPS Jakarta</strong>', $html);
        self::assertStringContainsString('agent (server terdaftar)', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfirmStepWithLocalServerStillSendsHiddenId(): void
    {
        $html = $this->renderView('app/confirm.php', $this->adminMultiServerContext() + $this->confirmVars('local'));

        self::assertStringContainsString('<input type="hidden" name="server_id" value="local">', $html);
        self::assertStringContainsString('lokal (dashboard ini)', $html);
    }

    // ==================================================================
    // (iv) tautan navigasi
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNavLinksCarryServerIdOnlyWhenNonLocal(): void
    {
        $context = $this->adminMultiServerContext();
        $context['selectedServerId'] = 'srv-jkt';

        $html = $this->renderView('app/create.php', $context + ['mode' => 'git']);
        self::assertStringContainsString('href="/apps/create?mode=template&amp;server_id=srv-jkt"', $html, 'tab mode tidak rusak (mode + server_id)');
        self::assertStringContainsString('href="/apps/create?mode=compose&amp;server_id=srv-jkt"', $html);
        self::assertStringContainsString('href="/apps/create?server_id=srv-jkt"', $html);

        // Mode compose: alert "Clone repo Git" juga dipertahankan.
        $compose = $this->renderView('app/create.php', $context + ['mode' => 'compose']);
        self::assertStringContainsString('Clone repo Git', $compose);
        self::assertStringContainsString('href="/apps/create?server_id=srv-jkt"', $compose, 'tautan mode Git di dalam alert');

        $gallery = $this->renderView('app/create.php', $context + ['mode' => 'template', 'templates' => [$this->templateOption()]]);
        self::assertStringContainsString('href="/apps/create/template/wordpress?server_id=srv-jkt"', $gallery, 'tautan Deploy galeri');

        $template = $this->renderView('app/template.php', $context + $this->templateVars());
        self::assertStringContainsString('href="/apps/create?mode=template&amp;server_id=srv-jkt"', $template, 'tombol Batal + breadcrumb');
        self::assertStringContainsString('<option value="srv-jkt" selected>', $template, 'preselect dari ?server_id=');

        $confirm = $this->renderView('app/confirm.php', $context + $this->confirmVars('srv-jkt'));
        self::assertStringContainsString('href="/apps/create?server_id=srv-jkt"', $confirm, 'tombol Kembali + breadcrumb');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNavServerIdIsEncodedAndEscaped(): void
    {
        $context = [
            'servers' => [
                $this->localOption(),
                [
                    'id' => 'srv " onmouseover="alert(1)',
                    'name' => '<script>alert(1)</script>',
                    'kind' => 'agent',
                    'domain' => '"><img src=x onerror=alert(1)>',
                    'enabled' => true,
                    'status' => 'online"><script>alert(2)</script>',
                ],
            ],
            'selectedServerId' => 'srv " onmouseover="alert(1)',
            'canSelectServer' => true,
        ];

        $html = $this->renderView('app/create.php', $context + ['mode' => 'git']);

        self::assertStringContainsString('server_id=srv%20%22%20onmouseover%3D%22alert%281%29', $html, 'server_id wajib rawurlencode');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'nama server wajib ter-escape');
        self::assertStringNotContainsString('<img src=x', $html, 'domain server wajib ter-escape');
        self::assertStringNotContainsString('onmouseover="alert', $html, 'kutip pada id tidak boleh memutus atribut');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        // Status server dipakai sebagai kelas badge → hanya boleh dari himpunan baku,
        // tidak boleh dirakit dari data registry.
        preg_match_all('/class="badge (badge-[a-z-]+)"/', $html, $badges);
        foreach ($badges[1] as $badgeClass) {
            self::assertContains($badgeClass, self::ALLOWED_BADGE_CLASSES, 'kelas badge dari data server: ' . $badgeClass);
        }
    }

    // ==================================================================
    // (v) view defensif: payload absen / kosong / rusak
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBrokenOrMissingPayloadRendersWithoutWarning(): void
    {
        $payloads = [
            'absen' => [],
            'kosong' => ['servers' => [], 'selectedServerId' => '', 'canSelectServer' => false],
            'rusak' => [
                'servers' => [
                    ['id' => ['x' => 1], 'name' => ['nested'], 'kind' => new \stdClass(), 'domain' => ['d'], 'enabled' => ['yes'], 'status' => ['online']],
                    ['id' => 'srv-jkt', 'name' => new \stdClass(), 'domain' => ['array'], 'enabled' => '1', 'status' => null],
                    'bukan array',
                    ['id' => '', 'name' => 'tanpa-id'],
                ],
                'selectedServerId' => new \stdClass(),
                'canSelectServer' => 'ya',
            ],
            'assosiatif' => ['servers' => ['a' => $this->agentOption(), 'local' => $this->localOption()], 'selectedServerId' => 'a', 'canSelectServer' => true],
        ];

        $views = [
            'app/create.php' => ['mode' => 'git'],
            'app/template.php' => $this->templateVars(),
            'app/confirm.php' => $this->confirmVars('local'),
        ];

        foreach ($payloads as $label => $payload) {
            foreach ($views as $view => $vars) {
                // `renderView()` memasang error handler yang mengubah
                // warning/notice/deprecation menjadi exception ⇒ render gagal = test gagal.
                $html = $this->renderView($view, $payload + $vars);
                self::assertNotSame('', $html, $view . ' (' . $label . ') tidak merender apa pun');
            }
        }

        // Payload rusak tapi masih punya satu id valid + opsi terpilih hilang:
        // tetap tanpa warning, dan tidak ada opsi `selected` (browser memilih
        // opsi pertama = lokal); server yang memvalidasi ulang saat submit.
        $html = $this->renderView('app/create.php', [
            'servers' => [$this->localOption(), $this->agentOption()],
            'selectedServerId' => 'srv-hilang',
            'canSelectServer' => true,
            'mode' => 'git',
        ]);
        self::assertSame(0, preg_match_all('/<option\b[^>]*\bselected/', $html), 'id tak ada di daftar ⇒ tidak ada opsi selected');
    }

    // ==================================================================
    // (viii) payload di luar kontrak — gerbang `canSelectServer` di view
    // ==================================================================

    /**
     * Payload **di luar kontrak**: user yang tidak berhak (`canSelectServer`
     * false) tetapi payload tetap memuat seluruh registry + id server agent
     * terpilih (pemanggil lupa menyaring / registry bocor). View wajib menjaga
     * diri sendiri: selektor tidak dirender **dan** identitas server agent
     * (nama/domain/badge) tidak muncul di catatan read-only maupun parameter
     * tautan (`href`).
     *
     * Hidden `server_id` pada langkah konfirmasi sengaja **tidak** ditulis ulang
     * ke `local` (fail-fast di `confirmCreate()` lebih aman daripada memindahkan
     * target deploy diam-diam) — yang dilarang adalah identitas server di UI.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOutOfContractPayloadHidesForeignServerIdentity(): void
    {
        $context = [
            'servers' => [$this->localOption(), $this->agentOption(), $this->disabledAgentOption()],
            'selectedServerId' => 'srv-jkt',
            'canSelectServer' => false,
        ];

        $views = [
            'app/create.php' => ['mode' => 'git'],
            'app/template.php' => $this->templateVars(),
            'app/confirm.php' => $this->confirmVars('srv-jkt'),
        ];

        foreach ($views as $view => $vars) {
            $html = $this->renderView($view, $context + $vars);

            self::assertSame(0, $this->countServerSelects($html), $view . ': payload luar kontrak tidak boleh menampilkan selektor');
            foreach (['VPS Jakarta', 'jkt.contoh.id', 'badge-running', 'agent (server terdaftar)'] as $leak) {
                self::assertStringNotContainsString($leak, $html, $view . ': identitas server agent bocor ke render (' . $leak . ')');
            }
            self::assertDoesNotMatchRegularExpression(
                '/href="[^"]*server_id=/',
                $html,
                $view . ': tautan tidak boleh membawa server_id bila user tidak berhak'
            );
            // Proyeksi server lokal dipakai apa adanya: label ringkas `lokal`
            // (bukan nama entri registry yang bisa saja berasal dari payload).
            self::assertStringContainsString(
                $view === 'app/confirm.php' ? 'lokal (dashboard ini)' : 'App akan dibuat di server <strong>lokal</strong>',
                $html,
                $view . ': catatan read-only wajib memakai proyeksi server lokal'
            );

            if ($view !== 'app/confirm.php') {
                self::assertStringNotContainsString('srv-jkt', $html, $view . ': id server agent tidak boleh dirender sama sekali');
            }
        }
    }

    /**
     * Regresi (d): admin multi-server tetap mendapat selektor di **ketiga** form
     * — opsi server terpilih ber-`selected`, lokal tidak ter-`selected` — dan
     * langkah konfirmasi tetap mengirim hidden `server_id` tanpa selektor.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminMultiServerRegressionKeepsSelectorAndHiddenId(): void
    {
        $context = $this->adminMultiServerContext();
        $context['selectedServerId'] = 'srv-jkt';

        $forms = [
            ['app/create.php', '/apps/create', ['mode' => 'git']],
            ['app/create.php', '/apps/create/compose', ['mode' => 'compose']],
            ['app/template.php', '/apps/create/template/wordpress', $this->templateVars()],
        ];

        foreach ($forms as [$view, $action, $vars]) {
            $html = $this->renderView($view, $context + $vars);

            $this->assertOneSelectInsideForm($html, $action, $view);
            self::assertStringContainsString('<option value="srv-jkt" selected>', $html, $view . ' (' . $action . '): pilihan non-lokal wajib ter-`selected`');
            self::assertStringNotContainsString('<option value="local" selected>', $html, $view . ' (' . $action . '): server lokal tidak boleh ter-`selected`');
            self::assertDoesNotMatchRegularExpression(
                '/<option value="srv-jkt"[^>]*\bdisabled\b/',
                $html,
                $view . ' (' . $action . '): server aktif tidak boleh disabled'
            );
        }

        $confirm = $this->renderView('app/confirm.php', $context + $this->confirmVars('srv-jkt'));
        self::assertSame(0, $this->countServerSelects($confirm), 'konfirmasi tidak boleh punya selektor');
        self::assertStringContainsString('<input type="hidden" name="server_id" value="srv-jkt">', $confirm, 'hidden server_id wajib ada di konfirmasi');
        self::assertStringContainsString('<strong>VPS Jakarta</strong>', $confirm, 'ringkasan read-only server terpilih');
    }

    /** Tepat satu `<select name="server_id">` **dan** ia berada di dalam form POST `$action`. */
    private function assertOneSelectInsideForm(string $html, string $action, string $label): void
    {
        self::assertSame(1, $this->countServerSelects($html), $label . ': harus ada tepat satu <select name="server_id">');

        $inner = null;
        foreach ($this->formRegions($html) as $region) {
            if ($region['action'] === $action) {
                $inner = $region['inner'];
            }
        }
        self::assertNotNull($inner, $label . ': form ' . $action . ' tidak ditemukan');
        self::assertStringContainsString('name="server_id"', $inner, $label . ': select wajib DI DALAM form ' . $action);
        self::assertStringContainsString('name="_token"', $inner, $label . ': csrf_field() wajib tetap ada di form ' . $action);
    }

    // ==================================================================
    // (vii) uji mutasi — membuktikan assert di atas menggigit
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMutationsAreCaughtByAssertions(): void
    {
        $mirror = $this->mirrorViews();

        $report = [];

        try {
            // M1: field `server_id` di-rename di form git (setara select hilang).
            $report[] = $this->mutate(
                $mirror,
                'app/create.php',
                '<select class="form-select" name="server_id" id="git-server-id"',
                '<select class="form-select" name="target_server" id="git-server-id"',
                function (string $html): void {
                    $context = $this->adminMultiServerContext();
                    $this->assertServerPickerInsideForm($html, '/apps/create');
                },
                ['mode' => 'git']
            );

            // M2: select dikeluarkan dari form compose (form ditutup sebelum select).
            $report[] = $this->mutate(
                $mirror,
                'app/create.php',
                "<?= csrf_field() ?>\n      <?php if (\$wizardShowServerSelect): ?>\n      <div class=\"mb-3\">\n        <label class=\"form-label\" for=\"compose-server-id\">",
                "<?= csrf_field() ?>\n      </form>\n      <?php if (\$wizardShowServerSelect): ?>\n      <div class=\"mb-3\">\n        <label class=\"form-label\" for=\"compose-server-id\">",
                function (string $html): void {
                    $context = $this->adminMultiServerContext();
                    $this->assertServerPickerInsideForm($html, '/apps/create/compose');
                },
                ['mode' => 'compose']
            );

            // M3: select dirender juga untuk non-admin (gerbang canSelectServer dibuang).
            $report[] = $this->mutate(
                $mirror,
                'app/create.php',
                '$wizardShowServerSelect = $wizardCanSelectServer && count($wizardServers) > 1;',
                '$wizardShowServerSelect = count($wizardServers) > 1;',
                function (string $html): void {
                    self::assertSame(0, $this->countServerSelects($html), 'non-admin tidak boleh mendapat select server');
                },
                ['servers' => [$this->localOption(), $this->agentOption()], 'selectedServerId' => 'local', 'canSelectServer' => false, 'mode' => 'git']
            );

            // M4: tautan navigasi kehilangan server_id.
            $report[] = $this->mutate(
                $mirror,
                'app/create.php',
                "    ? 'server_id=' . rawurlencode(\$wizardSelectedServerId)",
                "    ? ''",
                function (string $html): void {
                    self::assertStringContainsString('href="/apps/create?mode=compose&amp;server_id=srv-jkt"', $html);
                },
                (function (): array {
                    $context = $this->adminMultiServerContext();
                    $context['selectedServerId'] = 'srv-jkt';

                    return $context + ['mode' => 'git'];
                })()
            );

            // M5: hidden server_id di langkah konfirmasi dihapus.
            $report[] = $this->mutate(
                $mirror,
                'app/confirm.php',
                '  <input type="hidden" name="server_id" value="<?= e($wizardSelectedServerId) ?>">',
                '',
                function (string $html): void {
                    self::assertStringContainsString('<input type="hidden" name="server_id" value="srv-jkt">', $html);
                },
                (function (): array {
                    $context = $this->adminMultiServerContext();
                    $context['selectedServerId'] = 'srv-jkt';

                    return $context + $this->confirmVars('srv-jkt');
                })()
            );

            // M6: output label opsi tidak di-escape (XSS).
            $report[] = $this->mutate(
                $mirror,
                'app/create.php',
                '<option value="<?= e($wizardServerOption[\'id\']) ?>"',
                '<option value="<?= $wizardServerOption[\'id\'] ?>"',
                function (string $html): void {
                    self::assertStringNotContainsString('onmouseover="alert', $html, 'kutip pada id tidak boleh memutus atribut');
                },
                [
                    'servers' => [$this->localOption(), ['id' => 'srv" onmouseover="alert(1)', 'name' => 'X', 'kind' => 'agent', 'domain' => '', 'enabled' => true, 'status' => 'online']],
                    'selectedServerId' => 'local',
                    'canSelectServer' => true,
                    'mode' => 'git',
                ]
            );
        } finally {
            $this->removeTree($mirror);
            unset($GLOBALS['RAMES_WIZARD_APP_PATH']);
        }

        echo "\n";
        foreach ($report as $line) {
            echo '  ' . $line . "\n";
        }
        self::assertCount(6, $report);
    }

    /**
     * Mutan gerbang baru (temuan verifier #2): melepas gerbang
     * `$wizardCanSelectServer` pada parameter tautan **dan** proyeksi lokal pada
     * catatan read-only harus **tertangkap** oleh assert (viii).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOutOfContractGatesAreCaughtByMutations(): void
    {
        $mirror = $this->mirrorViews();

        $outOfContract = [
            'servers' => [$this->localOption(), $this->agentOption(), $this->disabledAgentOption()],
            'selectedServerId' => 'srv-jkt',
            'canSelectServer' => false,
        ];

        $report = [];

        try {
            // G1: parameter `server_id` dipasang tanpa melihat `canSelectServer`.
            $report[] = $this->mutate(
                $mirror,
                'app/create.php',
                '$wizardNavServerParam = ($wizardCanSelectServer',
                '$wizardNavServerParam = (true',
                function (string $html): void {
                    self::assertDoesNotMatchRegularExpression('/href="[^"]*server_id=/', $html, 'tautan tidak boleh membawa server_id');
                },
                $outOfContract + ['mode' => 'git']
            );

            // G2..G4: proyeksi lokal pada catatan read-only dilepas (satu mutan per view).
            $readOnlyViews = [
                ['app/create.php', ['mode' => 'git']],
                ['app/template.php', $this->templateVars()],
                ['app/confirm.php', $this->confirmVars('srv-jkt')],
            ];

            foreach ($readOnlyViews as [$view, $vars]) {
                $report[] = $this->mutate(
                    $mirror,
                    $view,
                    "if (!\$wizardCanSelectServer && !\$wizardSelectedServer['local']) {",
                    'if (false) {',
                    function (string $html): void {
                        self::assertStringNotContainsString('VPS Jakarta', $html, 'identitas server agent bocor');
                    },
                    $outOfContract + $vars
                );
            }
        } finally {
            $this->removeTree($mirror);
            unset($GLOBALS['RAMES_WIZARD_APP_PATH']);
        }

        echo "\n";
        foreach ($report as $line) {
            echo '  ' . $line . "\n";
        }
        self::assertCount(4, $report);
    }

    /**
     * Terapkan satu mutasi pada salinan view di temp (BUKAN berkas repo), render
     * hasilnya, dan buktikan assert yang sama **gagal** (mutan tertangkap).
     *
     * @param array<string,mixed> $vars
     */
    private function mutate(string $mirror, string $relative, string $from, string $to, callable $assert, array $vars): string
    {
        $file = $mirror . '/app/view/' . $relative;
        $source = (string) file_get_contents($file);
        self::assertStringContainsString($from, $source, 'anchor mutasi tidak ditemukan: ' . $relative);

        file_put_contents($file, str_replace($from, $to, $source));
        $GLOBALS['RAMES_WIZARD_APP_PATH'] = $mirror . '/app';

        try {
            $html = $this->renderView($relative, $vars);
            $caught = 'TIDAK tertangkap';
            try {
                $assert($html);
            } catch (AssertionFailedError $failure) {
                $caught = 'tertangkap (' . $this->shorten($failure->getMessage()) . ')';
            }
        } finally {
            file_put_contents($file, $source);
            unset($GLOBALS['RAMES_WIZARD_APP_PATH']);
        }

        self::assertStringStartsWith('tertangkap', $caught, 'mutan lolos: ' . $relative . ' / ' . $this->shorten($from));

        return $relative . ': ' . $this->shorten($from) . ' ⇒ ' . $caught;
    }

    private function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($text) > 110 ? substr($text, 0, 107) . '...' : $text;
    }

    /** Salin `app/view` (kecil) ke temp agar mutasi tidak menyentuh repo. */
    private function mirrorViews(): string
    {
        $root = sys_get_temp_dir() . '/rames-wizard-views-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root . '/app/view/app', 0777, true));
        self::assertTrue(mkdir($root . '/app/view/partials', 0777, true));

        foreach (glob(self::VIEW_DIR . '/app/*.php') ?: [] as $file) {
            copy($file, $root . '/app/view/app/' . basename($file));
        }
        foreach (glob(self::VIEW_DIR . '/partials/*.php') ?: [] as $file) {
            copy($file, $root . '/app/view/partials/' . basename($file));
        }

        return $root;
    }

    /** Hapus hanya direktori temp buatan test ini (guard: di dalam sys_temp_dir). */
    private function removeTree(string $dir): void
    {
        if (!str_starts_with(realpath($dir) ?: '', (string) realpath(sys_get_temp_dir()))) {
            return;
        }
        foreach (glob($dir . '/app/view/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir . '/app/view/app');
        @rmdir($dir . '/app/view/partials');
        @rmdir($dir . '/app/view');
        @rmdir($dir . '/app');
        @rmdir($dir);
    }

    // ==================================================================
    // fixture & helper render
    // ==================================================================

    private function adminMultiServerContext(): array
    {
        return [
            'servers' => [$this->localOption(), $this->agentOption(), $this->disabledAgentOption()],
            'selectedServerId' => 'local',
            'canSelectServer' => true,
        ];
    }

    /** `ServerResolver::option()` untuk server lokal. */
    private function localOption(): array
    {
        return [
            'id' => 'local',
            'name' => 'Server Ini',
            'kind' => 'local',
            'domain' => 'contoh.id',
            'enabled' => true,
            'status' => 'online',
            'capabilities' => [],
        ];
    }

    private function agentOption(): array
    {
        return [
            'id' => 'srv-jkt',
            'name' => 'VPS Jakarta',
            'kind' => 'agent',
            'domain' => 'jkt.contoh.id',
            'enabled' => true,
            'status' => 'online',
            'capabilities' => ['compose', 'logs'],
        ];
    }

    private function disabledAgentOption(): array
    {
        return [
            'id' => 'srv-sby',
            'name' => 'VPS Surabaya',
            'kind' => 'agent',
            'domain' => 'sby.contoh.id',
            'enabled' => false,
            'status' => 'offline',
            'capabilities' => [],
        ];
    }

    private function templateOption(): array
    {
        return [
            'slug' => 'wordpress',
            'title' => 'WordPress',
            'category' => 'CMS',
            'description' => 'Blog & CMS',
            'icon' => '📝',
            'image' => 'wordpress:latest',
            'valid' => true,
            'error' => null,
            'dir' => '/tmp/rames-wizard-test-templates/wordpress',
            'guide' => '# WordPress',
            'ports' => [80],
            'env' => [[
                'key' => 'DB_PASSWORD',
                'label' => 'Password DB',
                'required' => true,
                'secret' => true,
                'generate' => false,
                'default' => null,
                'help' => 'Password database',
            ]],
            'services' => ['web' => ['image' => 'wordpress:latest']],
            'primary' => ['service' => 'web', 'port' => 80],
        ];
    }

    private function limitsContext(): array
    {
        return [
            'canManage' => true,
            'services' => ['web'],
            'saved' => [],
            'repo' => ['web' => ['cpus' => null, 'memory_mb' => null]],
            'hasSaved' => false,
            'error' => null,
        ];
    }

    /** Vars non-server untuk `app/template.php`. */
    private function templateVars(): array
    {
        return [
            'template' => $this->templateOption(),
            'guide_html' => '<h1>WordPress</h1>',
            'form_name' => 'wordpress',
            'form_env' => [],
            'form_error' => null,
            'resourceLimits' => $this->limitsContext(),
            'limitsScaleMax' => 4,
        ];
    }

    /** Vars non-server untuk `app/confirm.php`. */
    private function confirmVars(string $serverId): array
    {
        return [
            'pending' => [
                'name' => 'myapp',
                'source' => 'git',
                'repo_url' => 'https://github.com/example/app.git',
                'branch' => 'main',
                'local_path' => '/tmp/rames-wizard-test-apps/myapp',
                'compose_file' => 'docker-compose.yml',
                'subdomain' => 'myapp',
                'services' => ['web' => ['image' => 'nginx:alpine']],
                'server_id' => $serverId,
            ],
            'subdomain' => 'myapp.contoh.id',
            'subdomain_label' => 'myapp',
            'subdomain_custom' => true,
            'resourceLimits' => $this->limitsContext(),
            'billing' => [
                'member' => false,
                'format' => ['hourly' => '0.00', 'month' => '0.00'],
                'estimate_hourly' => 0.0,
                'estimate_month' => 0.0,
                'hourly_text' => '0.00',
                'month_text' => '0.00',
                'enabled' => false,
                'applies' => false,
                'days' => 30,
            ],
            'limitsScaleMax' => 4,
        ];
    }

    /**
     * Render view dengan variabel sesuai kontrak controller, warning/notice PHP
     * menjadi exception (view defensif diuji betulan, bukan sekadar "tidak error").
     *
     * @param array<string,mixed> $vars
     */
    private function renderView(string $relative, array $vars): string
    {
        $this->installStubs();

        $base = $GLOBALS['RAMES_WIZARD_APP_PATH'] ?? dirname(__DIR__) . '/app';
        $file = $base . '/view/' . $relative;
        self::assertFileExists($file, 'View tidak ditemukan: ' . $file);

        extract($vars, EXTR_SKIP);

        set_error_handler(static function (int $no, string $message, string $source, int $line): bool {
            throw new \ErrorException($message, 0, $no, $source, $line);
        });

        ob_start();
        try {
            include $file;
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        } finally {
            restore_error_handler();
        }

        return (string) ob_get_clean();
    }

    /**
     * Pasang stub helper global lewat berkas temp ber-namespace global, lalu
     * `require` (fungsi tetap hidup setelah berkasnya dihapus).
     */
    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return; // sudah terpasang di proses anak ini
        }

        $file = sys_get_temp_dir() . '/rames-wizard-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }

    /** @return array<int,array{action:string,inner:string}> */
    private function formRegions(string $html): array
    {
        $regions = [];
        if (preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $html, $matches) === false) {
            return $regions;
        }
        foreach ($matches[0] as $block) {
            $action = '';
            if (preg_match('/<form\b[^>]*\baction="([^"]*)"/s', $block, $found) === 1) {
                $action = $found[1];
            }
            $regions[] = ['action' => $action, 'inner' => $block];
        }

        return $regions;
    }

    private function countServerSelects(string $html): int
    {
        return preg_match_all('/<select\b[^>]*\bname="server_id"/', $html);
    }
}
