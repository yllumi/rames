<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render UI multi-server (F4 lanjutan) — dua halaman:
 *  (A) tab **Template** di `/nginx` (Config): pemilih server admin-only yang
 *      mengarahkan panel status pull ke server terpilih (`?server=<id>`), aksi
 *      pull mengirim field `server` yang sama, status/job per server; server
 *      lokal berperilaku sama seperti sebelumnya (tanpa parameter/field `server`);
 *  (B) halaman `/servers`: kolom **Versi & kompatibilitas** (`agent_version`,
 *      `min_control_plane_version`, `agent_compat.ok/errors/warnings`) dan tombol
 *      **Perbarui agen** untuk server agent (POST `/servers/{id}/agent-update`
 *      dengan `_token` di body + konfirmasi).
 *
 * View repo ini TIDAK bisa dirender di test biasa: template memanggil helper
 * global yang butuh session/request Webman (`csrf_token()`, `current_user()`,
 * `is_admin()`, `flash_pull()`, ...). Karena itu tiap test berjalan di **proses
 * terpisah** dengan helper global di-stub lewat berkas temp (pola
 * `tests/MultiServerViewTest.php` / `tests/ServerViewTest.php`), sehingga stub
 * tidak bocor ke tes lain.
 *
 * Kontrak payload yang diuji = `NginxController::index()` (tanpa `server` GET
 * — pemilihan dilakukan di browser) dan `ServerController::index()`.
 * PHPUnit mengubah warning/notice jadi kegagalan (`failOnWarning`), jadi
 * variabel template yang hilang di view mana pun akan menggagalkan tes ini.
 *
 * Test ini hanya merender berkas view + membaca berkas JS; tidak menyentuh
 * store, Docker, HTTP, atau jaringan.
 */
class NginxServerViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /** Akar repo (untuk membaca berkas `public/js/*.js`). */
    private const ROOT_DIR = __DIR__ . '/..';

    /** Nilai yang **tidak boleh** muncul di HTML pemilih server/`/servers`. */
    private const SAMPLE_TOKEN = 'agt_uji_nginx_server_ui_7d2f8a';

    private const SAMPLE_BASE_URL = 'https://10.0.0.12:8443';

    private const SAMPLE_PEM = '-----BEGIN CERTIFICATE-----';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari test ber-`RunInSeparateProcess`.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        /**
         * Stub helper dashboard untuk smoke render view (tests/NginxServerViewTest.php).
         * Hanya hidup di proses anak proses-terpisah PHPUnit.
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
                    return ($GLOBALS['RAMES_VIEW_ADMIN'] ?? true)
                        ? ['id' => 'u1', 'username' => 'admin', 'role' => 'admin']
                        : ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return (bool) ($GLOBALS['RAMES_VIEW_ADMIN'] ?? true);
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('brand_name')) {
                function brand_name(): string
                {
                    return 'Rames';
                }
            }
            if (!function_exists('brand_logo_url')) {
                function brand_logo_url(): string
                {
                    return '/logo-small.png';
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
            if (!function_exists('app_role')) {
                function app_role(array $app, ?array $user = null): ?string
                {
                    return 'owner';
                }
            }
            if (!function_exists('app_can')) {
                function app_can(string $ability, array $app, ?array $user = null): bool
                {
                    return true;
                }
            }
            if (!function_exists('app_role_label')) {
                function app_role_label(?string $role): string
                {
                    return ucfirst((string) $role);
                }
            }
            if (!function_exists('user_names')) {
                function user_names(): array
                {
                    return ['u1' => 'admin', 'u2' => 'budi'];
                }
            }
            if (!function_exists('template_logo_map')) {
                function template_logo_map(?string $dir = null): array
                {
                    return [];
                }
            }
            if (!function_exists('app_subdomain')) {
                function app_subdomain(string|array|null $value = null, ?\app\library\Deploy\ServerResolver $resolver = null): string
                {
                    $label = is_array($value) ? (string) ($value['subdomain'] ?? '') : (string) $value;

                    return $label === '' ? '' : $label . '.contoh.id';
                }
            }
            if (!function_exists('app_subdomain_of')) {
                function app_subdomain_of(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): string
                {
                    return app_subdomain($app, $resolver);
                }
            }
            if (!function_exists('app_subdomain_label')) {
                function app_subdomain_label(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): string
                {
                    return (string) ($app['subdomain'] ?? '');
                }
            }
            if (!function_exists('app_subdomain_is_custom')) {
                function app_subdomain_is_custom(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): bool
                {
                    return false;
                }
            }
            if (!function_exists('app_subdomain_valid')) {
                function app_subdomain_valid(string|array|null $value, ?\app\library\Deploy\ServerResolver $resolver = null): bool
                {
                    return true;
                }
            }
        }
        PHP;

    protected function setUp(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;
    }

    // ==================================================================
    // (A) tab Template di /nginx — pemilih server
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateTabAdminGetsServerPickerTargetingAgent(): void
    {
        $html = $this->renderView('nginx/index.php', $this->nginxVars($this->serverOptions()));

        self::assertStringContainsString('id="template-server-picker"', $html);
        self::assertSame(2, substr_count($html, 'js-template-server'), 'Dua server ⇒ dua tombol pemilih');

        // Server agent offline tetap boleh dipilih (pull akan gagal tegas di sana).
        self::assertStringContainsString('data-server-id="vps-jakarta"', $html);
        self::assertStringContainsString('data-server-name="VPS Jakarta"', $html);
        self::assertStringContainsString('data-server-local="0"', $html);
        self::assertStringContainsString('data-server-status="offline"', $html);
        self::assertStringContainsString('data-server-status="online"', $html);
        // Server lokal = pilihan awal, jadi perilaku lama tetap menjadi default.
        self::assertStringContainsString('data-server-id="local"', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);

        // Panel status menandai dirinya milik server lokal pada render awal
        // (JS memindahkannya ke server terpilih tanpa reload).
        self::assertStringContainsString('data-server-local="1"', $html);
        self::assertStringContainsString('data-server-id=""', $html);
        self::assertStringContainsString('id="template-pull-server"', $html);
        self::assertStringContainsString('server lokal (dashboard ini)', $html);

        // Cache-bust JS dinaikkan (kontrak §10: query `?v=` satu-satunya buster).
        self::assertStringContainsString('src="/js/template-pull.js?v=2"', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConfigHeaderShowsPhpRuntimeVersion(): void
    {
        $html = $this->renderView('nginx/index.php', $this->nginxVars($this->serverOptions()));

        // Header Config wajib menampilkan versi PHP worker (dikirim controller
        // sebagai `$php_version`), bukan nilai karangan di view.
        self::assertStringContainsString('>PHP 8.3.99-smoke</span>', $html);
        self::assertStringContainsString('Versi PHP yang menjalankan dashboard', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateTabMemberNeverSeesServerPickerNorOtherServers(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        $html = $this->renderView('nginx/index.php', $this->nginxVars($this->serverOptions()));

        self::assertStringNotContainsString('template-server-picker', $html);
        self::assertStringNotContainsString('js-template-server', $html);
        self::assertStringNotContainsString('VPS Jakarta', $html, 'Nama server lain tidak boleh bocor ke member');
        self::assertStringNotContainsString('vps-jakarta', $html);
        self::assertStringContainsString('Tarik image hanya untuk admin', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateTabSingleServerKeepsOldBehaviour(): void
    {
        $servers = [$this->serverOptions()[0]]; // hanya server lokal
        $html = $this->renderView('nginx/index.php', $this->nginxVars($servers));

        // Tanpa server lain: tanpa pemilih, panel tetap menunjuk server lokal.
        self::assertStringNotContainsString('id="template-server-picker"', $html);
        self::assertSame(0, substr_count($html, 'js-template-server'));
        self::assertStringContainsString('data-server-local="1"', $html);
        self::assertStringContainsString('id="template-pull-panel"', $html);
        self::assertStringContainsString('data-running="0"', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateTabShowsJobOfAgentRun(): void
    {
        $vars = $this->nginxVars($this->serverOptions());
        $vars['pull']['run']['job'] = 'job-7f2c1b';
        $vars['pull']['run']['message'] = 'Polling job di server agen.';

        $html = $this->renderView('nginx/index.php', $vars);

        self::assertStringContainsString('Job job-7f2c1b', $html);
        self::assertStringContainsString('Polling job di server agen.', $html);
    }

    public function testTemplatePullJsTargetsSelectedServer(): void
    {
        $js = (string) file_get_contents(self::ROOT_DIR . '/public/js/template-pull.js');

        // Pemilih server + target per server.
        self::assertStringContainsString("getElementById('template-server-picker')", $js);
        self::assertStringContainsString("querySelectorAll('.js-template-server')", $js);
        self::assertStringContainsString("'/nginx/templates/pull/status?server=' + encodeURIComponent(selectedServerId)", $js);
        self::assertStringContainsString("body.set('server', selectedServerId)", $js);
        // Server lokal tidak mengirim `server` (perilaku lama dipertahankan).
        self::assertStringContainsString('function isLocalTarget()', $js);
        self::assertStringContainsString("return selectedServerId === '' || selectedServerId === 'local';", $js);
        // CSRF tetap di body (middleware hanya membaca `_token` dari body) +
        // polling dijeda saat tab tersembunyi & dibersihkan saat halaman ditinggalkan.
        self::assertStringContainsString('new URLSearchParams({ _token: CSRF, slug: slug })', $js);
        self::assertStringContainsString('if (document.hidden) return;', $js);
        self::assertStringContainsString("window.addEventListener('pagehide', stopPolling);", $js);
        self::assertStringContainsString("window.addEventListener('beforeunload', stopPolling);", $js);
        self::assertStringNotContainsString('innerHTML', $js);
    }

    // ==================================================================
    // (B) /servers — versi, kompatibilitas, tombol Perbarui agen
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testServersShowsVersionsCompatibilityAndAgentUpdateButton(): void
    {
        $html = $this->renderView('server/index.php', [
            'servers' => $this->serverDocs(),
            'localId' => 'local',
            'active' => 'servers',
        ]);

        self::assertStringContainsString('Versi &amp; kompatibilitas', $html);

        // Versi agen + minimum control plane yang diminta agen (per server).
        self::assertStringContainsString('agen <span class="mono">0.1.0</span>', $html);
        self::assertStringContainsString('butuh control plane ≥', $html);
        self::assertStringContainsString('<span class="mono">0.2.0</span>', $html);
        self::assertStringContainsString('belum diketahui', $html);

        // Penanda kompatibilitas: ok / peringatan / tidak kompatibel.
        self::assertSame(3, substr_count($html, 'data-server-compat'), 'Tiga server agent ⇒ tiga badge');
        self::assertStringContainsString('>kompatibel (peringatan)</span>', $html);
        self::assertStringContainsString('>tidak kompatibel</span>', $html);
        self::assertStringContainsString('>kompatibel</span>', $html);
        self::assertStringContainsString('versi agen &quot;0.0.9&quot; lebih tua dari minimum', $html);
        self::assertStringContainsString('versi agen belum diketahui', $html);
        self::assertStringContainsString('tidak berlaku — dashboard ini sendiri', $html);

        // Tombol Perbarui agen: hanya server agent (3 dari 4 baris), plus target
        // hasil job per baris.
        self::assertSame(3, substr_count($html, 'js-server-agent-update'));
        self::assertSame(4, substr_count($html, 'data-server-update='));
        self::assertStringContainsString('data-id="vps-jakarta"', $html);
        self::assertStringContainsString('data-version="0.1.0"', $html);
        // Baris lokal tidak punya tombol update agen.
        self::assertStringNotContainsString('data-name="Server ini"', $html);
        // Server tidak kompatibel / nonaktif: tombol tampil nonaktif + alasan.
        self::assertStringContainsString('Perbarui agen tidak tersedia: versi agen belum kompatibel', $html);
        self::assertStringContainsString('Perbarui agen tidak tersedia: server ini nonaktif', $html);

        // Sudah dinaikkan cache-bust JS-nya.
        self::assertStringContainsString('src="/js/server.js?v=2"', $html);

        $this->assertNoServerIndexSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testServersLocalRowHasNoAgentUpdateButtonAndRendersWithoutCompat(): void
    {
        // Seperti dokumen F0: baris lokal tanpa `agent_compat` sama sekali.
        $html = $this->renderView('server/index.php', [
            'servers' => [[
                'id' => 'local', 'name' => 'Server ini', 'kind' => 'local', 'enabled' => true,
                'status' => 'online', 'has_token' => false,
            ]],
            'localId' => 'local',
        ]);

        self::assertSame(0, substr_count($html, 'js-server-agent-update'));
        self::assertSame(0, substr_count($html, 'data-server-compat'));
        self::assertStringContainsString('tidak berlaku — dashboard ini sendiri', $html);
    }

    public function testServerJsPostsAgentUpdateWithBodyTokenAndConfirm(): void
    {
        $js = (string) file_get_contents(self::ROOT_DIR . '/public/js/server.js');

        self::assertStringContainsString("querySelectorAll('.js-server-agent-update')", $js);
        self::assertStringContainsString("'/servers/' + encodeURIComponent(id) + '/agent-update'", $js);
        self::assertStringContainsString("new URLSearchParams({ _token: CSRF, mode: 'update' })", $js);
        self::assertStringContainsString('window.confirm(', $js);
        self::assertStringContainsString("target('data-server-update', id)", $js);
        self::assertStringNotContainsString('.innerHTML', $js, 'JS tidak boleh menyisipkan HTML dari data server');
        // Aksi manual: tanpa polling/timer.
        self::assertStringNotContainsString('setInterval', $js);
        self::assertStringNotContainsString('setTimeout', $js);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Variabel tab Template sesuai kontrak `NginxController::index()`.
     *
     * @param array<int,array<string,mixed>> $servers
     * @return array<string,mixed>
     */
    private function nginxVars(array $servers): array
    {
        return [
            'status' => null,
            'update' => [
                'running' => false,
                'branch' => 'main',
                'check' => ['update_available' => false, 'local_sha' => 'abcdef1234567890', 'remote_sha' => null],
                'preflight' => ['ok' => false, 'errors' => [], 'warnings' => []],
            ],
            'templates' => [[
                'slug' => 'n8n', 'title' => 'n8n', 'description' => 'Workflow automation', 'category' => 'Automation',
                'icon' => '', 'docs_url' => 'https://example.test/docs', 'valid' => true, 'error' => '',
                'images' => ['docker.n8n.io/n8nio/n8n:latest'], 'apps' => [['id' => 'a1', 'name' => 'n8n-app']],
            ]],
            'pull' => [
                'enabled' => true,
                'running' => false,
                'logs' => [],
                'log_tail' => "Pulling image\n",
                'run' => [
                    'id' => '20261014-101500-abc123', 'slug' => 'n8n', 'title' => 'n8n', 'stage' => 'finished',
                    'message' => 'Selesai.', 'result' => 'success', 'error' => null,
                    'started_at' => '2026-10-14 10:15:00', 'finished_at' => '2026-10-14 10:16:00',
                    'actor' => 'admin', 'images' => ['docker.n8n.io/n8nio/n8n:latest'],
                    'results' => [['image' => 'docker.n8n.io/n8nio/n8n:latest', 'ok' => true, 'seconds' => 12.5, 'message' => 'Pulled']],
                    'log' => 'run.log', 'pid' => 0, 'job' => '', 'log_offset' => 0,
                ],
            ],
            'servers' => $servers,
            'tab' => 'template',
            'tabs' => ['update', 'nginx', 'template'],
            'php_version' => '8.3.99-smoke',
        ];
    }

    /**
     * Bentuk `ServerResolver::option()` (tanpa rahasia) + **sengaja** disisipi
     * token/`base_url` untuk membuktikan view tidak pernah merendernya.
     *
     * @return array<int,array<string,mixed>>
     */
    private function serverOptions(): array
    {
        return [
            [
                'id' => 'local', 'name' => 'Server ini', 'kind' => 'local', 'domain' => 'contoh.id',
                'enabled' => true, 'status' => 'online', 'capabilities' => ['template_pull'],
                'token' => self::SAMPLE_TOKEN, 'base_url' => self::SAMPLE_BASE_URL,
            ],
            [
                'id' => 'vps-jakarta', 'name' => 'VPS Jakarta', 'kind' => 'agent', 'domain' => 'jkt.contoh.id',
                'enabled' => true, 'status' => 'offline', 'capabilities' => ['template_pull', 'deploy'],
                'token' => self::SAMPLE_TOKEN, 'base_url' => self::SAMPLE_BASE_URL,
            ],
        ];
    }

    /**
     * Dokumen server untuk halaman `/servers` (bentuk `ServerController::publicServer()`).
     *
     * @return array<int,array<string,mixed>>
     */
    private function serverDocs(): array
    {
        return [
            [
                'id' => 'local', 'name' => 'Server ini', 'kind' => 'local', 'enabled' => true,
                'status' => 'online', 'has_token' => false,
                'agent_compat' => ['ok' => true, 'errors' => [], 'warnings' => [], 'agent_version' => '',
                    'min_control_plane_version' => '', 'min_agent_version' => '0.1.0',
                    'control_plane_version' => '0.1.0', 'capabilities' => []],
            ],
            [
                'id' => 'vps-jakarta', 'name' => 'VPS Jakarta', 'kind' => 'agent',
                'base_url' => self::SAMPLE_BASE_URL, 'domain' => 'jkt.contoh.id', 'enabled' => true,
                'status' => 'online', 'has_token' => true, 'agent_version' => '0.1.0',
                'min_control_plane_version' => '0.1.0', 'last_seen_at' => '2026-10-14 10:00:00',
                'tls_fingerprint' => str_repeat('a1', 32),
                'capabilities' => ['template_pull', 'deploy', 'self_update'],
                'agent_compat' => ['ok' => true, 'errors' => [], 'warnings' => [],
                    'agent_version' => '0.1.0', 'min_control_plane_version' => '0.1.0',
                    'min_agent_version' => '0.1.0', 'control_plane_version' => '0.1.0',
                    'capabilities' => ['template_pull', 'deploy', 'self_update']],
            ],
            [
                'id' => 'vps-lama', 'name' => 'VPS Lama', 'kind' => 'agent',
                'base_url' => self::SAMPLE_BASE_URL, 'domain' => 'lama.contoh.id', 'enabled' => true,
                'status' => 'online', 'has_token' => true, 'agent_version' => '0.0.9',
                'min_control_plane_version' => '0.2.0',
                'agent_compat' => ['ok' => false,
                    'errors' => ['VPS Lama: versi agen "0.0.9" lebih tua dari minimum yang didukung dashboard ini (0.1.0).'],
                    'warnings' => [], 'agent_version' => '0.0.9', 'min_control_plane_version' => '0.2.0',
                    'min_agent_version' => '0.1.0', 'control_plane_version' => '0.1.0', 'capabilities' => []],
            ],
            [
                'id' => 'vps-baru', 'name' => 'VPS Baru', 'kind' => 'agent',
                'base_url' => self::SAMPLE_BASE_URL, 'domain' => '', 'enabled' => false,
                'status' => 'unknown', 'has_token' => false, 'agent_version' => '',
                'min_control_plane_version' => '',
                'agent_compat' => ['ok' => true, 'errors' => [],
                    'warnings' => ['VPS Baru: versi agen belum diketahui — jalankan Test connection di halaman Servers.'],
                    'agent_version' => '', 'min_control_plane_version' => '', 'min_agent_version' => '0.1.0',
                    'control_plane_version' => '0.1.0', 'capabilities' => []],
            ],
        ];
    }

    // ==================================================================
    // (C) urutan DOM: skrip panel update vs markup modal konfirmasi
    // ==================================================================

    /**
     * Regresi: `/js/update.js` **wajib** dimuat setelah markup modal konfirmasi.
     *
     * Skrip itu mengikat handler ke `#update-confirm-btn`/`#rollback-confirm-btn`
     * (yang berada **di dalam** modal) dan mencari `#update-confirm-modal` /
     * `#rollback-confirm-modal`. Ketika skrip masih ikut di dalam
     * `partials/tab-update.php` — yang dirender sebelum modal, karena modal ada di
     * luar `.tab-pane` — semua lookup itu `null` saat skrip dimuat: tombol
     * "Update Sekarang" hanya menampilkan pesan "Modal konfirmasi tidak dapat
     * ditampilkan" walau Bootstrap termuat, dan tombol "Ya, update sekarang" tidak
     * pernah terpasang handler.
     *
     * Bukti = posisi byte pada HTML hasil render halaman Config + isi partial
     * (tanpa JS/browser).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateScriptIsLoadedAfterTheConfirmModals(): void
    {
        $vars = $this->nginxVars($this->serverOptions());
        $vars['tab'] = 'update';
        $vars['update']['check'] = [
            'ok' => true,
            'update_available' => true,
            'local_sha' => 'abcdef1234567890',
            'remote_sha' => 'fedcba0987654321',
            'checked_at' => '2026-10-15 09:00:00',
        ];
        $vars['update']['preflight'] = ['ok' => true, 'errors' => [], 'warnings' => []];

        $html = $this->renderView('nginx/index.php', $vars);

        $updateModal = strpos($html, 'id="update-confirm-modal"');
        $rollbackModal = strpos($html, 'id="rollback-confirm-modal"');
        $confirmBtn = strpos($html, 'id="update-confirm-btn"');
        $script = strpos($html, '<script src="/js/update.js');

        self::assertNotFalse($updateModal, 'modal konfirmasi update harus dirender untuk admin');
        self::assertNotFalse($rollbackModal, 'modal konfirmasi rollback harus dirender untuk admin');
        self::assertNotFalse($confirmBtn, 'tombol konfirmasi di dalam modal harus dirender');
        self::assertNotFalse($script, 'skrip panel update harus dimuat halaman Config');
        self::assertGreaterThan($updateModal, $script, 'update.js harus dimuat SETELAH markup modal konfirmasi update');
        self::assertGreaterThan($rollbackModal, $script, 'update.js harus dimuat SETELAH markup modal konfirmasi rollback');
        self::assertGreaterThan($confirmBtn, $script, 'update.js harus dimuat SETELAH tombol konfirmasi (handler diikat saat skrip dimuat)');

        $partial = (string) file_get_contents(self::VIEW_DIR . '/nginx/partials/tab-update.php');
        self::assertStringNotContainsString(
            '<script src="/js/update.js',
            $partial,
            'partial tab tidak boleh memuat update.js sendiri (selalu dirender sebelum modal)'
        );
    }

    /**
     * Skrip panel update hanya dimuat bila panelnya benar-benar dirender: fitur
     * update dimatikan / panel gagal dibaca (`$update = null`) ⇒ tanpa
     * `#update-panel` sekaligus tanpa request `/js/update.js` yang sia-sia.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateScriptIsSkippedWhenThePanelIsNotRendered(): void
    {
        $vars = $this->nginxVars($this->serverOptions());
        $vars['tab'] = 'update';
        $vars['update'] = null;

        $html = $this->renderView('nginx/index.php', $vars);

        self::assertStringNotContainsString('id="update-panel"', $html, 'panel update tidak dirender saat fitur mati');
        self::assertStringNotContainsString('/js/update.js', $html, 'skrip panel tidak perlu dimuat tanpa panel');
        self::assertStringContainsString('id="pane-update"', $html, 'tab Update tetap ada (pesan "fitur mati" milik panel)');
    }

    /**
     * Render view dengan variabel sesuai kontrak controller (tanpa HTTP/webman).
     *
     * @param array<string,mixed> $vars
     */
    private function renderView(string $relative, array $vars): string
    {
        $this->installStubs();

        $file = self::VIEW_DIR . '/' . $relative;
        self::assertFileExists($file, 'View tidak ditemukan: ' . $relative);

        extract($vars, EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
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

        $file = sys_get_temp_dir() . '/rames-nginx-server-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }

    /**
     * Pemilih server tidak boleh memuat jejak rahasia/endpoint agent.
     */
    private function assertNoSecretLeak(string $html): void
    {
        foreach ([self::SAMPLE_TOKEN, self::SAMPLE_BASE_URL, self::SAMPLE_PEM, 'Bearer'] as $needle) {
            self::assertStringNotContainsString($needle, $html, 'HTML view tidak boleh memuat: ' . $needle);
        }
        self::assertStringNotContainsString('tls_fingerprint', $html);
        self::assertStringNotContainsString('tls_cert_pem', $html);
    }

    /**
     * `/servers` memang menampilkan `base_url` server (endpoint admin) — yang
     * dilarang hanya token & PEM.
     */
    private function assertNoServerIndexSecretLeak(string $html): void
    {
        self::assertStringNotContainsString(self::SAMPLE_TOKEN, $html);
        self::assertStringNotContainsString(self::SAMPLE_PEM, $html);
        self::assertStringNotContainsString('Bearer', $html);
    }
}
