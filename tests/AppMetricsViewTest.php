<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render blok metrik di tab Info halaman detail app
 * (`app/view/app/partials/detail-tab-info.php` #app-metrics) + kontrak aset JS.
 *
 * Kontrak yang dikunci (frontend, data diisi `public/js/app-metrics.js`):
 *  1. section `#app-metrics` punya `data-app-id` dari `$app['id']` (ter-escape);
 *  2. tersedia grid 3 kartu dengan hook stabil: `data-metrics-value="cpu"`,
 *     `"mem"`, `"storage-total"`, `data-metrics-sub="mem"`/`"storage-breakdown"`,
 *     `data-metrics-note="storage"`, serta 2 svg dekoratif + 2 path grafik;
 *  3. semua nilai awal "—" (belum ada data server → bukan 0);
 *  4. data user (`$app['id']` berisi `<script>`) tidak pernah masuk mentah;
 *  5. `detail-scripts.php` memuat `/js/app-metrics.js` (satu-satunya pemuat JS
 *     metrik — lupa memuat berarti blok metrik selamanya kosong);
 *  6. partial dirender **penuh** tanpa warning/notice PHP (harness mengubahnya
 *     menjadi ErrorException), jadi undefined variable langsung menggagalkan tes.
 *
 * Pola sama dengan `DetailEnvTabViewTest`/`CreateEnvFormViewTest`: proses
 * terpisah, helper global di-stub, tanpa menyentuh store/Docker/data runtime.
 */
final class AppMetricsViewTest extends TestCase
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
        }
        PHP;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlokMetrikDirenderDenganHookKontrak(): void
    {
        $html = $this->render();

        // Section + id app untuk endpoint /api/apps/{id}/metrics.
        self::assertStringContainsString('id="app-metrics"', $html);
        self::assertStringContainsString('data-app-id="app-1"', $html);
        self::assertStringContainsString('Penggunaan Resource', $html);
        self::assertStringContainsString('5 menit terakhir', $html);

        // Tiga kartu: CPU, memori, storage.
        self::assertStringContainsString('data-metrics-value="cpu"', $html);
        self::assertStringContainsString('data-metrics-value="mem"', $html);
        self::assertStringContainsString('data-metrics-value="storage-total"', $html);
        self::assertStringContainsString('data-metrics-sub="mem"', $html);
        self::assertStringContainsString('data-metrics-sub="storage-breakdown"', $html);
        self::assertStringContainsString('data-metrics-note="storage"', $html);

        // Dua grafik dekoratif (svg) dengan satu path masing-masing.
        self::assertSame(2, substr_count($html, 'data-metrics-chart="cpu"') + substr_count($html, 'data-metrics-chart="mem"'));
        self::assertSame(2, substr_count($html, 'class="app-metrics-chart"'));
        self::assertSame(2, substr_count($html, '<svg'));
        self::assertSame(2, substr_count($html, '<path'));
        self::assertSame(1, substr_count($html, 'data-metrics-line="cpu"'));
        self::assertSame(1, substr_count($html, 'data-metrics-line="mem"'));
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="Grafik pemakaian CPU 5 menit terakhir"', $html);
        self::assertStringContainsString('aria-label="Grafik pemakaian memori 5 menit terakhir"', $html);
        self::assertStringContainsString('preserveAspectRatio="none"', $html);
        self::assertStringContainsString('vector-effect="non-scaling-stroke"', $html);

        // Nilai awal "—" untuk semua angka (null ≠ 0).
        self::assertMatchesRegularExpression('/data-metrics-value="cpu">—</', $html);
        self::assertMatchesRegularExpression('/data-metrics-value="mem">—</', $html);
        self::assertMatchesRegularExpression('/data-metrics-value="storage-total">—</', $html);
        self::assertMatchesRegularExpression('/data-metrics-sub="mem">—</', $html);

        // Tanpa CSS tambahan milik halaman (chart memakai inline style minimal).
        self::assertStringContainsString('height:72px; width:100%; display:block', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNilaiAppTerEscapeDiAtribut(): void
    {
        $html = $this->render(['app' => ['id' => '<script>alert(1)</script>']]);

        self::assertStringContainsString('data-app-id="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertStringNotContainsString('data-app-id="<script>', $html);
    }

    public function testDetailScriptsMemuatAppMetrics(): void
    {
        $file = dirname(__DIR__) . '/app/view/app/partials/detail-scripts.php';
        self::assertFileExists($file);

        $source = (string) file_get_contents($file);
        self::assertMatchesRegularExpression('#<script src="/js/app-metrics\.js\?v=\d+"></script>#', $source);

        // Aset benar-benar ada dan punya endpoint metrik.
        $js = dirname(__DIR__) . '/public/js/app-metrics.js';
        self::assertFileExists($js);
        $code = (string) file_get_contents($js);
        self::assertStringContainsString("'/metrics'", $code);
        self::assertStringContainsString("'/metrics?storage=1'", $code);
        self::assertStringNotContainsString('innerHTML =', $code);

        // Regresi: `fetchJson()` mengembalikan `data` utuh; blok storage ada di
        // `data.storage`. Meneruskan `data` langsung ke `renderStorage()`
        // membuat `available` tak terbaca → UI salah menampilkan "tidak tersedia".
        self::assertStringContainsString('renderStorage(data && data.storage', $code);
        self::assertStringNotContainsString('.then(renderStorage)', $code);
    }

    // ==================================================================
    // Harness render
    // ==================================================================

    /**
     * Render partial penuh dengan scope yang dipakai view.
     *
     * @param array<string,mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $this->installStubs();

        $file = dirname(__DIR__) . '/app/view/app/partials/detail-tab-info.php';
        self::assertFileExists($file);

        $app = [
            'id' => 'app-1',
            'name' => 'myapp',
            'subdomain' => 'myapp',
            'local_path' => '/srv/apps/myapp',
            'compose_files' => ['docker-compose.yml'],
            'primary_service' => 'web',
            'repo_url' => 'git@example.com:me/myapp.git',
            'branch' => 'main',
            'auth_method' => 'none',
        ];

        // Override `app` digabung (bukan diganti) agar partial tetap mendapat
        // seluruh kunci yang dipakai view.
        if (isset($overrides['app']) && is_array($overrides['app'])) {
            $app = array_replace($app, $overrides['app']);
            unset($overrides['app']);
        }

        $vars = array_replace([
            'app' => $app,
            'hasHostPort' => true,
            'customDomain' => '',
            'appPorts' => [],
            'proxiedPort' => 0,
            'isCompose' => false,
            'isRemoteServer' => false,
            'appServerName' => '',
            'sshPubkey' => null,
        ], $overrides);

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

    private function installStubs(): void
    {
        if (function_exists('e')) {
            return;
        }

        $file = sys_get_temp_dir() . '/rames-metrics-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }
}
