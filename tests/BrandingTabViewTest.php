<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render tab **Branding** di halaman Config (`app/view/nginx/index.php`).
 *
 * View repo ini tidak bisa dirender di test biasa: template memanggil helper
 * global yang butuh session/request Webman (`csrf_token()`, `current_user()`,
 * `is_admin()`, `flash_pull()`, `brand_name()`, ...). Karena itu tiap test
 * berjalan di **proses terpisah** dengan helper global di-stub lewat berkas
 * temp (pola `tests/NginxServerViewTest.php`), sehingga stub tidak bocor.
 *
 * Kontrak payload = `NginxController::index()` (`$branding` dari
 * `brandingPanel()`). Yang dibuktikan:
 *  (i)  tab `data-tab="branding"` + pane `#pane-branding` dirender, dan pane
 *       aktif saat `$tab === 'branding'`;
 *  (ii) form simpan memakai `action="/nginx/branding"`,
 *       `enctype="multipart/form-data"`, `_token` CSRF, input `name` + `logo`
 *       dengan `accept` PNG/JPEG/WebP (tanpa SVG/GIF);
 *  (iii) form reset memakai `action="/nginx/branding/reset"`;
 *  (iv) nama brand kustom di-escape (`<Ops>` ⇒ `&lt;Ops&gt;`) di nilai input
 *       maupun atribut `alt`;
 *  (v)  payload `$branding = null` jatuh ke default `Rames` + `/logo-small.png`
 *       tanpa warning/notice.
 *
 * Test ini hanya merender berkas view; tidak menyentuh store, Docker, HTTP,
 * atau jaringan.
 */
class BrandingTabViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari test ber-`RunInSeparateProcess`.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        /**
         * Stub helper dashboard untuk smoke render view (tests/BrandingTabViewTest.php).
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
                    return ['u1' => 'admin'];
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

    /**
     * Tab Branding dengan payload kustom: bentuk form lengkap + escape nama.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBrandingTabRendersCustomBrandFormAndEscapesName(): void
    {
        $html = $this->renderView('nginx/index.php', $this->nginxVars([
            'name' => '<Ops>',
            'logo_url' => '/branding/logo?v=abcdef123456.png',
            'logo_custom' => true,
            'updated_at' => '2026-10-20T10:00:00+07:00',
            'max_name' => 32,
            'max_bytes' => 524288,
            'allowed' => 'PNG, JPEG, WebP',
        ]));

        // Tab + pane Branding ada, pane aktif karena `$tab === 'branding'`.
        self::assertStringContainsString('data-tab="branding"', $html);
        self::assertStringContainsString('id="pane-branding"', $html);
        self::assertMatchesRegularExpression(
            '/<div class="tab-pane fade show active" id="pane-branding"/',
            $html,
            'pane Branding harus aktif saat tab=branding'
        );

        // Form simpan: target, enctype, CSRF, nama field, accept gambar.
        self::assertStringContainsString('action="/nginx/branding"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('name="logo"', $html);
        self::assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $html);
        self::assertStringNotContainsString('image/svg', $html, 'SVG tidak boleh ditawarkan di accept');
        self::assertStringNotContainsString('image/gif', $html, 'GIF tidak boleh ditawarkan di accept');

        // Form reset terpisah.
        self::assertStringContainsString('action="/nginx/branding/reset"', $html);

        // Nama brand di-escape di value input dan atribut alt.
        self::assertStringContainsString('value="&lt;Ops&gt;"', $html);
        self::assertStringNotContainsString('value="<Ops>"', $html, 'nama brand tidak boleh dirender mentah');
        self::assertStringContainsString('alt="&lt;Ops&gt;"', $html);

        // Logo kustom + penanda status.
        self::assertStringContainsString('src="/branding/logo?v=abcdef123456.png"', $html);
        self::assertStringContainsString('>Kustom</span>', $html);
        self::assertStringContainsString('Diperbarui 2026-10-20T10:00:00+07:00', $html);
    }

    /**
     * Payload `$branding = null`: partial jatuh ke default `Rames` +
     * `/logo-small.png` tanpa warning/notice.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBrandingTabFallsBackToDefaultsWhenPayloadMissing(): void
    {
        $html = $this->renderView('nginx/index.php', $this->nginxVars(null));

        self::assertStringContainsString('data-tab="branding"', $html);
        self::assertStringContainsString('id="pane-branding"', $html);
        self::assertStringContainsString('value="Rames"', $html);
        self::assertStringContainsString('src="/logo-small.png"', $html);
        self::assertStringContainsString('>Bawaan</span>', $html);
        self::assertStringContainsString('action="/nginx/branding"', $html);
        self::assertStringContainsString('action="/nginx/branding/reset"', $html);
    }

    /**
     * Payload kontrak `NginxController::index()` (subset yang dibutuhkan view).
     *
     * @param array<string,mixed>|null $branding
     * @return array<string,mixed>
     */
    private function nginxVars(?array $branding): array
    {
        return [
            'status' => null,
            'update' => [
                'running' => false,
                'branch' => 'main',
                'check' => ['update_available' => false, 'local_sha' => 'abcdef1234567890', 'remote_sha' => null],
                'preflight' => ['ok' => false, 'errors' => [], 'warnings' => []],
            ],
            'templates' => [],
            'pull' => null,
            'branding' => $branding,
            'servers' => [],
            'tab' => 'branding',
            'tabs' => ['update', 'nginx', 'template', 'branding'],
            'php_version' => '8.4.99-smoke',
        ];
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

        $file = sys_get_temp_dir() . '/rames-branding-tab-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }
}
