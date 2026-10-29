<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render halaman **Error Log** (`/errors`) & detail (`/errors/{id}`).
 *
 * Kontrak yang dijaga (data dari `ErrorController`, BEKU):
 *  (i)   index merender ringkasan `counts`, filter (status/kind/app/q) dengan
 *        nilai terpilih, tautan Detail, dan pagination;
 *  (ii)  semua nilai dari DB/request di-escape (`<script>` tidak pernah mentah),
 *        termasuk option app, q, pesan, user, stack, dan context JSON;
 *  (iii) index tanpa grup merender state kosong yang ramah;
 *  (iv)  detail merender form status & hapus dengan `_token` (CSRF), stack dan
 *        context ter-escape, serta tombol "Salin stack";
 *  (v)   teks non-ASCII/emoji tampil utuh;
 *  (vi)  nav admin "Errors" + badge jumlah open dan footer memuat
 *        `/js/error-report.js` untuk user login.
 *
 * Pola `CreateEnvFormViewTest`/`DetailEnvTabViewTest`: view dirender penuh di
 * proses terpisah dengan helper global di-stub; warning PHP menjadi exception
 * sehingga variabel template yang hilang menggagalkan tes. Tidak menyentuh
 * `apps/`, `database/`, atau data runtime.
 */
final class ErrorReportViewTest extends TestCase
{
    private const VIEW_DIR = __DIR__ . '/../app/view';

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
            if (!function_exists('error_badge_count')) {
                function error_badge_count(): int
                {
                    return 2;
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
            if (!function_exists('current_credit_balance')) {
                function current_credit_balance(): ?float
                {
                    return null;
                }
            }
            if (!function_exists('format_credits')) {
                function format_credits(float $credits, int $decimals = 2): string
                {
                    return number_format($credits, $decimals, ',', '.');
                }
            }
        }
        PHP;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexMerenderDuaGrupDanFilterDenganEscaping(): void
    {
        $html = $this->renderView('errors/index.php', [
            'groups' => [
                [
                    'id' => 'g1',
                    'fingerprint' => 'fp-client-1',
                    'kind' => 'client',
                    'status' => 'open',
                    'message' => "<script>alert('xss')</script> 💥 café",
                    'location' => 'app.js:12',
                    'route' => '/apps/abc',
                    'http_status' => null,
                    'app_id' => 'a1',
                    'app_name' => '<b>Demo</b>',
                    'server_id' => 'local',
                    'server_name' => 'lokal',
                    'user_id' => 'u2',
                    'username' => '<b>admin</b>',
                    'first_seen' => '2026-10-28 09:00:00',
                    'last_seen' => '2026-10-29 10:00:00',
                    'count' => 7,
                    'samples' => 3,
                ],
                [
                    'id' => 'g2',
                    'fingerprint' => 'fp-server-2',
                    'kind' => 'server',
                    'status' => 'resolved',
                    'message' => 'Timeout saat koneksi DB',
                    'location' => '',
                    'route' => '/apps/demo',
                    'http_status' => 502,
                    'app_id' => 'a2',
                    'app_name' => 'Demo',
                    'server_id' => 'srv-1',
                    'server_name' => 'vps-1',
                    'user_id' => 'u3',
                    'username' => 'siti',
                    'first_seen' => '2026-10-27 08:00:00',
                    'last_seen' => '2026-10-27 09:30:00',
                    'count' => 3,
                    'samples' => 1,
                ],
            ],
            'filters' => ['status' => 'all', 'kind' => 'all', 'q' => '<script>cari</script>', 'app_id' => 'a1'],
            'counts' => ['open' => 1, 'resolved' => 1, 'ignored' => 0],
            'total' => 25,
            'page' => 2,
            'pages' => 3,
            'apps' => [['id' => 'a1', 'name' => '<b>App</b>'], ['id' => 'a2', 'name' => 'Demo']],
        ]);

        self::assertStringContainsString('Error Log', $html);
        self::assertMatchesRegularExpression('/badge text-bg-danger">open 1</', $html);
        self::assertMatchesRegularExpression('/badge text-bg-success">resolved 1</', $html);
        self::assertMatchesRegularExpression('/badge text-bg-secondary">ignored 0</', $html);
        self::assertStringContainsString('25 grup', $html);
        self::assertStringContainsString('Halaman 2 dari 3', $html);
        self::assertStringContainsString('href="/errors?status=all&amp;q=%3Cscript%3Ecari%3C%2Fscript%3E&amp;app_id=a1&amp;page=3"', $html);

        // Filter terpilih + q/opsi app ter-escape.
        self::assertStringContainsString('<option value="all" selected>Semua status</option>', $html);
        self::assertStringContainsString('<option value="all" selected>Semua jenis</option>', $html);
        self::assertStringContainsString('<option value="a1" selected>&lt;b&gt;App&lt;/b&gt;</option>', $html);
        self::assertStringContainsString('value="&lt;script&gt;cari&lt;/script&gt;"', $html);

        // Tautan detail per grup.
        self::assertStringContainsString('href="/errors/g1"', $html);
        self::assertStringContainsString('href="/errors/g2"', $html);

        // Escaping nilai berbahaya: tidak ada payload mentah.
        self::assertStringNotContainsString("<script>alert('xss')</script>", $html);
        self::assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<b>admin</b>', $html);
        self::assertStringContainsString('&lt;b&gt;admin&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Demo</b>', $html);

        // Teks non-ASCII utuh.
        self::assertStringContainsString('💥', $html);
        self::assertStringContainsString('café', $html);

        // Nav admin + badge open, dan reporter dimuat untuk user login.
        self::assertStringContainsString('href="/errors" data-label="Errors"', $html);
        self::assertStringContainsString('title="2 grup error open"', $html);
        self::assertStringContainsString('/js/error-report.js', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexKosongMenampilkanStateKosong(): void
    {
        $html = $this->renderView('errors/index.php', [
            'groups' => [],
            'filters' => ['status' => 'open', 'kind' => 'all', 'q' => '', 'app_id' => ''],
            'counts' => ['open' => 0, 'resolved' => 0, 'ignored' => 0],
            'total' => 0,
            'page' => 1,
            'pages' => 1,
            'apps' => [],
        ]);

        self::assertStringContainsString('Belum ada error yang tercatat.', $html);
        self::assertStringContainsString('0 grup', $html);
        self::assertStringContainsString('Error JavaScript yang tidak tertangani', $html);
        self::assertStringNotContainsString('href="/errors/g', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDetailMerenderFormCsrfDanStackContextTerEscape(): void
    {
        $html = $this->renderView('errors/detail.php', [
            'group' => [
                'id' => 'g1',
                'fingerprint' => 'fp-<script>1</script>',
                'kind' => 'client',
                'status' => 'open',
                'message' => 'Gagal <b>memuat</b> 💥',
                'location' => 'app.js:7',
                'route' => '/apps/abc',
                'http_status' => 500,
                'app_id' => 'a1',
                'app_name' => 'Demo',
                'server_id' => 'local',
                'server_name' => 'lokal',
                'user_id' => 'u2',
                'username' => 'budi',
                'first_seen' => '2026-10-28 09:00:00',
                'last_seen' => '2026-10-29 10:00:00',
                'count' => 4,
                'samples' => 1,
            ],
            'events' => [
                [
                    'id' => 'e1',
                    'occurred_at' => '2026-10-29 10:00:00',
                    'kind' => 'client',
                    'message' => "Boom <b>!</b>",
                    'stack' => "<script>boom()</script>\n    at app.js:12",
                    'context' => [
                        'path' => '</pre><script>x</script>',
                        'n' => 1,
                        'emoji' => '💥',
                    ],
                    'user_agent' => '<img src=x onerror=alert(1)>',
                ],
            ],
        ]);

        self::assertStringContainsString('Detail Error', $html);
        self::assertStringContainsString('action="/errors/g1/status"', $html);
        self::assertStringContainsString('action="/errors/g1/delete"', $html);
        self::assertSame(2, preg_match_all('/name="_token"/', $html), 'dua form mutasi wajib ber-CSRF');
        self::assertStringContainsString('<option value="open" selected>open</option>', $html);
        self::assertStringContainsString('onsubmit="return confirm(', $html);
        self::assertStringContainsString('data-copy-target="err-event-0-stack"', $html);
        self::assertStringContainsString('max-height: 320px', $html);

        // Stack & context di-escape; tidak ada HTML payload mentah.
        self::assertStringNotContainsString('<script>boom()</script>', $html);
        self::assertStringContainsString('&lt;script&gt;boom()&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<b>memuat</b>', $html);
        self::assertStringContainsString('&lt;b&gt;memuat&lt;/b&gt;', $html);

        // Emoji tetap utuh (termasuk di context JSON).
        self::assertStringContainsString('💥', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTeksNonAsciiDanEmojiAman(): void
    {
        $html = $this->renderView('errors/index.php', [
            'groups' => [
                [
                    'id' => 'g9',
                    'fingerprint' => 'fp-unicode',
                    'kind' => 'server',
                    'status' => 'ignored',
                    'message' => 'エラー発生 🚨 — café',
                    'location' => '',
                    'route' => '/apps/日本語',
                    'http_status' => null,
                    'app_id' => '',
                    'app_name' => '',
                    'server_id' => '',
                    'server_name' => '',
                    'user_id' => '',
                    'username' => 'オペレーター',
                    'first_seen' => '2026-10-28 09:00:00',
                    'last_seen' => '2026-10-29 10:00:00',
                    'count' => 1,
                    'samples' => 1,
                ],
            ],
            'filters' => ['status' => 'ignored', 'kind' => 'server', 'q' => '', 'app_id' => ''],
            'counts' => ['open' => 0, 'resolved' => 0, 'ignored' => 1],
            'total' => 1,
            'page' => 1,
            'pages' => 1,
            'apps' => [],
        ]);

        self::assertStringContainsString('エラー発生 🚨 — café', $html);
        self::assertStringContainsString('オペレーター', $html);
        self::assertStringContainsString('/apps/日本語', $html);
        self::assertStringNotContainsString('�', $html, 'tidak ada karakter rusak');
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderView(string $relative, array $vars): string
    {
        $this->installStubs();

        $file = self::VIEW_DIR . '/' . $relative;
        self::assertFileExists($file, 'View tidak ditemukan: ' . $relative);

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
        if (function_exists('csrf_field')) {
            return; // stub sudah terpasang di proses anak ini
        }

        $file = sys_get_temp_dir() . '/rames-error-report-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }
}
