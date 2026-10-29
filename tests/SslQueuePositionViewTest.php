<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render catatan baris `pending` (posisi antrean SSL) di:
 *  - `app/view/domain/index.php` (tab "Semua Domain", `/domains`), dan
 *  - `app/view/ssl/index.php` (halaman lama `/ssl`).
 *
 * Kontrak yang dikunci (frontend, field BEKU dari backend):
 *  1. baris `ssl_status = pending` dengan `ssl_queue_position = 2` dan
 *     `ssl_queue_size = 3` ⇒ `[data-ssl-note="pending"]` berisi
 *     `antrean #2 dari 3` (1-based, teks di-`e()`);
 *  2. baris `pending` tanpa kedua field ⇒ tetap `proses ...`;
 *  3. baris `pending` `#1 dari 1` ⇒ `antrean #1 dari 1`;
 *  4. cache-buster naik (`/js/ssl.js?v=4` di `/domains`, `v=3` di `/ssl`);
 *  5. `public/js/ssl.js` membaca `ssl_queue_position`/`ssl_queue_size` dan
 *     menulis teks lewat `textContent` — tanpa `innerHTML`.
 *
 * Pola sama dengan `AppsViewModeTest`: proses terpisah, helper global di-stub,
 * partial header/footer diganti stub (view tetap dirender penuh — warning PHP
 * diubah menjadi ErrorException), tanpa menyentuh store/Docker/data runtime.
 */
final class SslQueuePositionViewTest extends TestCase
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
            if (!function_exists('csrf_token')) {
                function csrf_token(): string
                {
                    return 'uji-csrf-token';
                }
            }
            if (!function_exists('csrf_field')) {
                function csrf_field(): string
                {
                    return '<input type="hidden" name="_token" value="uji-csrf-token">';
                }
            }
            if (!function_exists('config')) {
                function config(?string $key = null, mixed $default = null): mixed
                {
                    return $key === 'deploy.app_domain' ? 'contoh.test' : $default;
                }
            }
            if (!function_exists('app_can')) {
                function app_can(string $ability, mixed $app = null): bool
                {
                    return (bool) ($GLOBALS['RAMES_TEST_CAN_SSL'] ?? true);
                }
            }
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return ['id' => 'u1', 'username' => 'admin'];
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return false;
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
        }
        PHP;

    private string $stubFile = '';

    protected function setUp(): void
    {
        $suffix = getmypid() . '-' . bin2hex(random_bytes(4));
        $this->stubFile = sys_get_temp_dir() . '/rames-ssl-queue-stubs-' . $suffix . '.php';
        file_put_contents($this->stubFile, self::STUB_SOURCE);
        require $this->stubFile;
    }

    protected function tearDown(): void
    {
        if (is_file($this->stubFile)) {
            unlink($this->stubFile);
        }
    }

    // ------------------------------------------------------------------
    // Kasus 1 — halaman /domains (tab Semua Domain)
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDomainPendingMenampilkanPosisiAntrean(): void
    {
        $html = $this->renderDomain([
            $this->domainRow('a', 'antre.contoh.test', 'pending', 2, 3),
            $this->domainRow('b', 'tanpa.contoh.test', 'pending'),
            $this->domainRow('c', 'satu.contoh.test', 'pending', 1, 1),
        ]);

        $antre = $this->rowSlice($html, 0);
        self::assertStringContainsString('>antrean #2 dari 3</span>', $antre);
        self::assertStringNotContainsString('>proses ...</span>', $antre);

        $tanpa = $this->rowSlice($html, 1);
        self::assertStringContainsString('>proses ...</span>', $tanpa);
        self::assertStringNotContainsString('antrean #', $tanpa);

        $satu = $this->rowSlice($html, 2);
        self::assertStringContainsString('>antrean #1 dari 1</span>', $satu);

        // Polling tetap dimulai otomatis karena ada baris pending.
        self::assertStringContainsString('data-ssl-autostart="1"', $html);
        self::assertStringContainsString('/js/ssl.js?v=4', $html);
    }

    // ------------------------------------------------------------------
    // Kasus 2 — halaman lama /ssl
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSslPendingMenampilkanPosisiAntrean(): void
    {
        $html = $this->renderSsl([
            $this->sslRow('a', 'antre.contoh.test', 'pending', 2, 3),
            $this->sslRow('b', 'tanpa.contoh.test', 'pending'),
            $this->sslRow('c', 'satu.contoh.test', 'pending', 1, 1),
        ]);

        $antre = $this->rowSlice($html, 0);
        self::assertStringContainsString('>antrean #2 dari 3</span>', $antre);
        self::assertStringNotContainsString('>proses ...</span>', $antre);

        $tanpa = $this->rowSlice($html, 1);
        self::assertStringContainsString('>proses ...</span>', $tanpa);
        self::assertStringNotContainsString('antrean #', $tanpa);

        $satu = $this->rowSlice($html, 2);
        self::assertStringContainsString('>antrean #1 dari 1</span>', $satu);

        self::assertStringContainsString('data-ssl-autostart="1"', $html);
        self::assertStringContainsString('/js/ssl.js?v=3', $html);
    }

    // ------------------------------------------------------------------
    // Kasus 3 — field tidak relevan saat status bukan `pending`
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStatusAktifTetapTanpaTeksAntrean(): void
    {
        $html = $this->renderDomain([
            $this->domainRow('a', 'aktif.contoh.test', 'active', 2, 3),
        ]);

        $row = $this->rowSlice($html, 0);
        self::assertStringNotContainsString('antrean #', $row);
        self::assertStringContainsString('>aktif</span>', $row);
    }

    // ------------------------------------------------------------------
    // Kasus 4 — kontrak aset statik (JS + cache-buster)
    // ------------------------------------------------------------------

    public function testJsMembacaFieldAntreanDanMemakaiTextContent(): void
    {
        $root = dirname(__DIR__);

        $js = (string) file_get_contents($root . '/public/js/ssl.js');
        foreach (['ssl_queue_position', 'ssl_queue_size', 'queuePosition', 'queueSize', 'antrean #', 'proses ...'] as $needle) {
            self::assertStringContainsString($needle, $js, 'ssl.js harus memuat: ' . $needle);
        }
        // Teks selalu lewat textContent; tidak ada penulisan `.innerHTML =` (komentar
        // dokumen boleh menyebut larangannya).
        self::assertStringContainsString('.textContent =', $js);
        self::assertDoesNotMatchRegularExpression('/\.innerHTML\s*=/', $js);

        // Cache-buster: satu-satunya mekanisme pembaruan skrip di browser.
        self::assertStringContainsString(
            '<script src="/js/ssl.js?v=4"></script>',
            (string) file_get_contents($root . '/app/view/domain/index.php')
        );
        self::assertStringContainsString(
            '<script src="/js/ssl.js?v=3"></script>',
            (string) file_get_contents($root . '/app/view/ssl/index.php')
        );
    }

    // ==================================================================
    // Harness render
    // ==================================================================

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function renderDomain(array $rows): string
    {
        return $this->render('app/view/domain/index.php', [
            'rows' => $rows,
            'domains' => [],
            'isAdmin' => false,
            'tab' => 'all',
            'maxHtmlBytes' => 1048576,
            'appDomain' => 'contoh.test',
            'errors' => [],
            'sslSupported' => true,
            'filter' => '',
            'filterActive' => false,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function renderSsl(array $rows): string
    {
        return $this->render('app/view/ssl/index.php', [
            'rows' => $rows,
            'isAdmin' => false,
            'sslSupported' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function render(string $relative, array $vars): string
    {
        $file = dirname(__DIR__) . '/' . $relative;
        self::assertFileExists($file);

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
     * Baris tabel domain app (`DomainController::index()` → `$rows`).
     *
     * @return array<string,mixed>
     */
    private function domainRow(
        string $key,
        string $domain,
        string $status,
        ?int $position = null,
        ?int $size = null
    ): array {
        $row = [
            'kind' => 'app',
            'id' => 'app-' . $key,
            'app_id' => 'app-' . $key,
            'domain' => $domain,
            'source' => 'App ' . $key,
            'role' => 'serve',
            'is_custom' => false,
            'can_ssl' => true,
            'ssl_status' => $status,
            'ssl_message' => '',
            'ssl_error' => '',
            'ssl_error_summary' => '',
            'ssl_expires_at' => '',
            'form_url' => '/ssl/app-' . $key . '/enable',
            'ajax_url' => '/api/ssl/app-' . $key . '/enable',
        ];

        if ($position !== null) {
            $row['ssl_queue_position'] = $position;
        }
        if ($size !== null) {
            $row['ssl_queue_size'] = $size;
        }

        return $row;
    }

    /**
     * Baris tabel halaman lama `/ssl` (`SslController::index()` → `$rows`).
     *
     * @return array<string,mixed>
     */
    private function sslRow(
        string $key,
        string $domain,
        string $status,
        ?int $position = null,
        ?int $size = null
    ): array {
        $row = [
            'app' => [
                'id' => 'app-' . $key,
                'name' => 'App ' . $key,
                'status' => 'running',
                'owner_id' => 'u1',
            ],
            'domain' => $domain,
            'is_custom' => false,
            'ssl_status' => $status,
            'ssl_message' => '',
            'ssl_error' => '',
            'ssl_expires_at' => '',
        ];

        if ($position !== null) {
            $row['ssl_queue_position'] = $position;
        }
        if ($size !== null) {
            $row['ssl_queue_size'] = $size;
        }

        return $row;
    }

    /** Potongan HTML satu baris `[data-ssl-row]` (0-based, urutan render). */
    private function rowSlice(string $html, int $index): string
    {
        $parts = explode('<tr data-ssl-row', $html);
        self::assertArrayHasKey($index + 1, $parts, 'Baris ke-' . $index . ' tidak ditemukan');

        $chunk = $parts[$index + 1];
        $end = strpos($chunk, '</tr>');
        self::assertNotFalse($end);

        return substr($chunk, 0, (int) $end);
    }
}
