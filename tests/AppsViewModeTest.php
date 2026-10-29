<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Pemilih mode tampilan halaman Apps (`/apps`) — rows / thumbs.
 *
 * Kontrak yang dikunci (frontend):
 *  1. tombol ikon `[data-apps-view-mode="rows|thumbs"]` hanya dirender bila ada
 *     app, dengan `aria-pressed` awal `true`/`false` (rows default);
 *  2. dua wadah dirender server-side: `#apps-rows` (tabel lama) dan
 *     `#apps-thumbs` (grid kartu) yang awalnya `hidden` + `d-none` — aman
 *     tanpa JS (default baris);
 *  3. kartu memakai logo template bila ada (`template_logo_map()`) dan jatuh ke
 *     inisial nama app bila tidak; seluruh nilai user di-`e()`;
 *  4. owner (kolom & kartu) hanya untuk admin;
 *  5. `app/index.php` memuat `/js/apps-view.js?v=1`, dan skrip itu memakai
 *     kunci localStorage `rames.apps.view` + atribut `hidden`/`aria-pressed`
 *     (tanpa `innerHTML`/`console`).
 *
 * Pola sama dengan `AppMetricsViewTest`: proses terpisah, helper global
 * di-stub, tanpa menyentuh store/Docker/data runtime.
 */
final class AppsViewModeTest extends TestCase
{
    private const STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('e')) {
                function e(mixed $value): string
                {
                    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            if (!function_exists('app_path')) {
                function app_path(): string
                {
                    return (string) ($GLOBALS['RAMES_APP_PATH'] ?? '');
                }
            }
            if (!function_exists('config')) {
                function config(?string $key = null, mixed $default = null): mixed
                {
                    return $key === 'deploy.app_domain' ? 'contoh.test' : $default;
                }
            }
            if (!function_exists('csrf_token')) {
                function csrf_token(): string
                {
                    return 'uji-csrf-token';
                }
            }
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return $GLOBALS['RAMES_TEST_USER'] ?? null;
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return (bool) ($GLOBALS['RAMES_VIEW_ADMIN'] ?? false);
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
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
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
            if (!function_exists('update_badge')) {
                function update_badge(): array
                {
                    return ['available' => false];
                }
            }
            if (!function_exists('error_badge_count')) {
                function error_badge_count(): int
                {
                    return 0;
                }
            }
            if (!function_exists('app_role_label')) {
                function app_role_label(?string $role): string
                {
                    $labels = ['owner' => 'Pemilik', 'operator' => 'Operator', 'viewer' => 'Viewer'];

                    return $labels[(string) $role] ?? ucfirst((string) $role);
                }
            }
            if (!function_exists('template_logo_map')) {
                function template_logo_map(?string $dir = null): array
                {
                    return (array) ($GLOBALS['RAMES_TEST_LOGO_MAP'] ?? []);
                }
            }
        }
        PHP;

    private string $appOne;
    private string $appTwo;

    protected function setUp(): void
    {
        $this->appOne = '<script>alert(1)</script>';
        $this->appTwo = '<script>evil(2)</script>';
    }

    // ------------------------------------------------------------------
    // Kasus 1 — toggle + dua wadah + isi kartu
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testToggleDanDuaWadahDirenderDenganIsiKartu(): void
    {
        $html = $this->render();

        // Toggle: tepat dua tombol, rows aktif.
        self::assertSame(1, substr_count($html, 'data-apps-view-mode="rows"'));
        self::assertSame(1, substr_count($html, 'data-apps-view-mode="thumbs"'));
        self::assertMatchesRegularExpression('/data-apps-view-mode="rows"[^>]*aria-pressed="true"/', $html);
        self::assertMatchesRegularExpression('/data-apps-view-mode="thumbs"[^>]*aria-pressed="false"/', $html);
        self::assertStringContainsString('aria-label="Mode tampilan daftar app"', $html);

        // Dua wadah server-side; thumbs default tersembunyi (hidden + d-none).
        self::assertStringContainsString('id="apps-rows"', $html);
        self::assertStringContainsString('<table', substr($html, (int) strpos($html, 'id="apps-rows"')));
        $thumbsTag = $this->tag($html, 'id="apps-thumbs"');
        self::assertStringContainsString('d-none', $thumbsTag);
        self::assertStringContainsString('hidden', $thumbsTag);

        // Logo template (ghost) untuk app pertama.
        self::assertStringContainsString('src="/images/templates/ghost.png"', $html);

        $thumbs = $this->thumbsRegion($html);
        // Fallback inisial untuk app tanpa template.
        self::assertStringContainsString('rounded-circle', $thumbs);
        self::assertStringContainsString('>B</span>', $thumbs);

        // Data kartu: nama, status, domain, owner, container, updated, Detail.
        self::assertStringContainsString('>Ghost Blog</a>', $thumbs);
        self::assertStringContainsString('badge-running', $thumbs);
        self::assertStringContainsString('ghost.contoh.test', $thumbs);
        self::assertStringContainsString('>admin</span>', $thumbs);
        self::assertStringContainsString('1 container', $thumbs);
        self::assertStringContainsString('2026-10-29 08:00', $thumbs);
        self::assertSame(2, substr_count($thumbs, '>Detail</a>'));
        // App yang bukan milik user memakai ikon shared (bukan label teks);
        // peran tetap terbaca lewat title/aria-label.
        self::assertStringContainsString('aria-label="Dibagikan · Viewer"', $thumbs);
        self::assertStringContainsString('Anda punya akses Viewer ke app ini', $thumbs);
        self::assertStringNotContainsString('Dibagikan · Viewer</span>', $thumbs);
    }

    // ------------------------------------------------------------------
    // Kasus 2 — escaping di tabel & kartu
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNamaDanDomainTerEscapeDiTabelDanKartu(): void
    {
        $apps = $this->apps();
        $apps[0]['name'] = $this->appOne;
        $apps[0]['subdomain'] = $this->appTwo;
        $apps[0]['custom_domain'] = $this->appTwo;

        $html = $this->render(['apps' => $apps]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<script>evil(2)</script>', $html);

        $escapedName = e($this->appOne);
        $escapedDomain = e($this->appTwo);
        // Nama muncul di tabel + kartu (2×).
        self::assertSame(2, substr_count($html, $escapedName));
        // Subdomain + custom domain, masing-masing href + teks, di tabel & kartu.
        self::assertSame(8, substr_count($html, $escapedDomain));

        $rows = $this->rowsRegion($html);
        $thumbs = $this->thumbsRegion($html);
        self::assertStringContainsString($escapedName, $rows);
        self::assertStringContainsString($escapedName, $thumbs);
        self::assertStringContainsString($escapedDomain, $rows);
        self::assertStringContainsString($escapedDomain, $thumbs);
    }

    // ------------------------------------------------------------------
    // Kasus 3 — empty state
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTanpaAppTidakAdaToggleDanTidakAdaWadahThumbs(): void
    {
        $html = $this->render(['apps' => [], 'counts' => ['mine' => 0, 'shared' => 0, 'all' => 0]]);

        self::assertStringNotContainsString('data-apps-view-mode', $html);
        self::assertStringNotContainsString('id="apps-thumbs"', $html);
        self::assertStringNotContainsString('id="apps-rows"', $html);
        self::assertSame(1, substr_count($html, 'href="/apps/create"'));
        self::assertStringContainsString('Belum ada app', $html);
    }

    // ------------------------------------------------------------------
    // Kasus 4 — owner hanya untuk admin
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOwnerTidakDirenderUntukNonAdmin(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;
        $html = $this->render(['isAdmin' => false, 'ownerOptions' => []]);

        self::assertStringNotContainsString('<th>Owner</th>', $html);
        self::assertStringNotContainsString('>Owner</span>', $html);
        self::assertStringNotContainsString('budi', $html);
        // Toggle tetap ada (ada app), hanya owner yang hilang.
        self::assertStringContainsString('data-apps-view-mode="rows"', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOwnerDirenderUntukAdmin(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<th>Owner</th>', $html);
        self::assertStringContainsString('>Owner</span>', $html);
        self::assertStringContainsString('budi', $this->thumbsRegion($html));
    }

    // ------------------------------------------------------------------
    // Kasus 5 — kontrak aset statik
    // ------------------------------------------------------------------

    public function testViewMemuatSkripDanJsMematuhiKontrak(): void
    {
        $root = dirname(__DIR__);

        $view = (string) file_get_contents($root . '/app/view/app/index.php');
        self::assertStringContainsString('<script src="/js/apps-view.js?v=1"></script>', $view);

        $js = (string) file_get_contents($root . '/public/js/apps-view.js');
        foreach (['rames.apps.view', 'aria-pressed', "setAttribute('hidden'", 'localStorage', 'apps-thumbs', 'apps-rows'] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('console.', $js);
        self::assertStringNotContainsString('alert(', $js);
    }

    // ==================================================================
    // Harness render
    // ==================================================================

    /**
     * @param array<string,mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $this->installStubs();

        $file = dirname(__DIR__) . '/app/view/app/index.php';
        self::assertFileExists($file);

        $vars = array_replace($this->baseVars(), $overrides);
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
     * @return array<string,mixed>
     */
    private function baseVars(): array
    {
        $GLOBALS['RAMES_TEST_USER'] = ['id' => 'u1', 'username' => 'admin'];

        return [
            'pageTitle' => 'Apps',
            'active' => 'apps',
            'scope' => 'all',
            'counts' => ['mine' => 1, 'shared' => 1, 'all' => 2],
            'serverFilter' => '',
            'serverOptions' => [],
            'isAdmin' => true,
            'filters' => [],
            'filterActive' => false,
            'ownerOptions' => ['u1' => 'admin', 'u2' => 'budi'],
            'apps' => $this->apps(),
            'ownedIds' => ['app-1' => true],
            'roles' => ['app-2' => 'viewer'],
            'ownerNames' => ['u1' => 'admin', 'u2' => 'budi'],
        ];
    }

    /**
     * Dua app: satu dari template (logo ghost), satu tanpa template (inisial).
     *
     * @return list<array<string,mixed>>
     */
    private function apps(): array
    {
        return [
            [
                'id' => 'app-1',
                'name' => 'Ghost Blog',
                'status' => 'running',
                'message' => 'Sehat',
                'subdomain' => 'ghost.contoh.test',
                'custom_domain' => 'blog.contoh.org',
                'custom_ssl_status' => 'active',
                'owner_id' => 'u1',
                'server_kind' => 'local',
                'containers' => [
                    ['service_name' => 'web', 'ports' => [['host' => 8080, 'container' => 2368]]],
                ],
                'updated_at' => '2026-10-29 08:00',
                'template' => ['slug' => 'ghost'],
            ],
            [
                'id' => 'app-2',
                'name' => 'Beta App',
                'status' => 'stopped',
                'subdomain' => 'beta.contoh.test',
                'owner_id' => 'u2',
                'server_kind' => 'agent',
                'server_name' => 'vps-jakarta',
                'containers' => [
                    ['service_name' => 'web', 'ports' => [['host' => 8081, 'container' => 3000]]],
                ],
                'updated_at' => '2026-10-28 10:00',
            ],
        ];
    }

    private function tag(string $html, string $needle): string
    {
        $pos = strpos($html, $needle);
        self::assertNotFalse($pos, 'Tag tidak ditemukan: ' . $needle);
        $end = strpos($html, '>', (int) $pos);
        self::assertNotFalse($end);

        return substr($html, (int) $pos, (int) $end - (int) $pos + 1);
    }

    private function rowsRegion(string $html): string
    {
        $start = strpos($html, 'id="apps-rows"');
        $end = strpos($html, 'id="apps-thumbs"');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function thumbsRegion(string $html): string
    {
        $start = strpos($html, 'id="apps-thumbs"');
        self::assertNotFalse($start);

        return substr($html, (int) $start);
    }

    private function installStubs(): void
    {
        $GLOBALS['RAMES_APP_PATH'] = dirname(__DIR__) . '/app';
        $GLOBALS['RAMES_TEST_LOGO_MAP'] = ['ghost' => 'png', 'opencode' => 'svg'];
        // Default admin untuk header; tes non-admin menimpanya sebelum render.
        $GLOBALS['RAMES_VIEW_ADMIN'] ??= true;

        if (function_exists('e')) {
            return;
        }

        $file = sys_get_temp_dir() . '/rames-apps-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }
}
