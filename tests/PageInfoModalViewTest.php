<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Tombol info judul halaman + modal "Tentang halaman ini" (partial bersama).
 *
 * Dua lapis pembuktian:
 *
 *  (i) **Render partial** (`partials/page-info-button.php`,
 *      `partials/page-info-modal.php`) di proses terpisah. Partial memakai
 *      helper global `e()` yang di repo ini hanya ada lewat `app/functions.php`
 *      (bukan autoload Composer), jadi helper itu di-stub dari berkas temp di
 *      dalam anak proses — pola yang sama dengan `tests/ServerViewTest.php`.
 *      PHPUnit mengubah warning/notice menjadi kegagalan (`failOnWarning`),
 *      sehingga notice saat merender partial langsung menggagalkan tes. Variabel
 *      partial yang hilang **tidak** memunculkan warning (partial memakai `??`);
 *      kelengkapan variabel dijaga lapis (ii).
 *      `app_path()`/`config()` datang dari framework (sudah ada di anak proses).
 *
 *  (ii) **Statik** pada kelima view pemakai: tiap halaman meng-include kedua
 *      partial, memakai ID modal yang benar, menaruh tombol **di dalam** `<h1>`,
 *      menaruh modal **setelah** `.page-head`, dan benar-benar memindahkan
 *      paragraf lama ke `$__infoHtml` (frasa khas paragraf lama harus berada
 *      **setelah** include modal — bukan bagian dari ringkasan satu baris).
 *
 * Tidak menyentuh data runtime, HTTP, session, Docker, atau jaringan.
 */
class PageInfoModalViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /**
     * Kontrak statik per halaman: ID modal, judul, ringkasan satu baris, dan
     * frasa khas paragraf panjang yang wajib ikut pindah ke modal.
     *
     * @var array<string, array{id:string, title:string, summary:string, phrases:list<string>}>
     */
    private const PAGES = [
        'volume/index.php' => [
            'id' => 'volumes-info',
            'title' => 'Tentang Volumes',
            'summary' => 'Volume compose dari setiap server yang berhak Anda lihat; volume yatim dapat dibersihkan di server lokal.',
            'phrases' => [
                'Volume ber-label <span class="mono">docker compose</span>',
                'Kolom <strong>Ukuran</strong>',
            ],
        ],
        'backup/index.php' => [
            'id' => 'backup-info',
            'title' => 'Tentang Backup Volume',
            'summary' => 'Backup harian volume app ke S3 (restic) — dump logis untuk database, snapshot untuk volume lain.',
            'phrases' => [
                '<strong>dump logis</strong>',
                'Kolom <strong>Berkala</strong>',
            ],
        ],
        'monitor/index.php' => [
            'id' => 'monitor-info',
            'title' => 'Tentang Monitor',
            'summary' => 'Pemakaian CPU, memori, storage, load, dan uptime host serta container.',
            'phrases' => [
                '$monitorPollMs > 0',
                'round($monitorPollMs / 1000)',
            ],
        ],
        'nginx/index.php' => [
            'id' => 'config-info',
            'title' => 'Tentang Config',
            'summary' => 'Update dashboard, Nginx host, katalog template, dan branding.',
            'phrases' => [
                'katalog app dalam empat tab',
                '<strong>Branding</strong> — nama &amp; logo dashboard',
            ],
        ],
        'server/index.php' => [
            'id' => 'servers-info',
            'title' => 'Tentang Servers',
            'summary' => 'Kelola server lokal dan server agent (HTTPS + pinning sertifikat).',
            'phrases' => [
                '<strong>pinning sertifikat</strong>',
                '<strong>Versi &amp; kompatibilitas</strong>',
            ],
        ],
    ];

    // ---------------------------------------------------------------------
    // (i) Render partial
    // ---------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testButtonPartialRendersIconOnlyButtonTargetingModal(): void
    {
        $html = $this->renderPartial('partials/page-info-button.php', ['__infoTarget' => 'volumes-info']);

        self::assertStringContainsString('data-bs-toggle="modal"', $html);
        self::assertStringContainsString('data-bs-target="#volumes-info"', $html);
        self::assertStringContainsString('title="Tentang halaman ini"', $html);
        self::assertStringContainsString('aria-label="Tentang halaman ini"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html, 'SVG dekoratif');
        self::assertStringContainsString('<svg', $html);

        foreach (['btn', 'btn-link', 'btn-sm', 'text-muted', 'p-0', 'ms-1', 'align-text-top'] as $class) {
            self::assertMatchesRegularExpression(
                '/(?:class="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*")/',
                $html,
                'kelas tombol wajib: ' . $class
            );
        }

        // Ikon saja: tidak ada teks label di dalam tombol.
        self::assertSame('', trim(strip_tags($html)), 'tombol tidak boleh memuat teks label');

        // Ikon diangkat ke atas judul (`align-text-top` + inline style) tanpa
        // margin tambahan yang bisa menggeser judul; ukuran ikon tetap 16×16.
        self::assertStringContainsString('style="vertical-align: text-top; line-height: 1;"', $html);
        self::assertStringNotContainsString('align-baseline', $html);
        self::assertStringContainsString('width="16" height="16"', $html);
        self::assertStringNotContainsString('margin', $html);

        // Tidak ada whitespace pembungkus yang menggeser posisi judul.
        self::assertStringStartsWith('<button', $html);
        self::assertStringEndsWith('</button>', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testButtonPartialEscapesModalTargetAndSkipsWithoutTarget(): void
    {
        $html = $this->renderPartial('partials/page-info-button.php', ['__infoTarget' => 'a"b']);
        self::assertStringContainsString('data-bs-target="#a&quot;b"', $html);
        self::assertStringNotContainsString('data-bs-target="#a"b"', $html);

        $empty = $this->renderPartial('partials/page-info-button.php', ['__infoTarget' => '']);
        self::assertSame('', trim($empty), 'tanpa target tidak ada yang bisa dibuka ⇒ tidak merender tombol');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testModalPartialRendersStructureAndEscapesTitle(): void
    {
        $html = $this->renderPartial('partials/page-info-modal.php', [
            '__infoId' => 'demo-info',
            '__infoTitle' => '<script>alert(1)</script>',
            '__infoHtml' => '<p class="text-muted mb-0">Isi <strong>aman</strong></p>',
        ]);

        self::assertStringContainsString('<div class="modal fade" id="demo-info" tabindex="-1"', $html);
        self::assertStringContainsString('aria-labelledby="demo-info-title"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('modal-dialog modal-dialog-scrollable modal-lg', $html);
        self::assertStringContainsString('<div class="modal-content">', $html);
        self::assertStringContainsString('<h2 class="modal-title h6" id="demo-info-title">', $html);
        self::assertStringContainsString('class="modal-body"', $html);
        self::assertStringContainsString('class="modal-footer"', $html);

        // Judul = data view ⇒ wajib ter-escape.
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);

        // $__infoHtml = HTML statis milik view ⇒ dirender sebagai HTML, bukan escaped.
        self::assertStringContainsString('Isi <strong>aman</strong>', $html);
        self::assertStringNotContainsString('&lt;strong&gt;aman&lt;/strong&gt;', $html);

        // Footer: satu tombol Tutup yang menutup modal.
        self::assertMatchesRegularExpression(
            '/<button[^>]*class="btn btn-outline-secondary btn-sm"[^>]*data-bs-dismiss="modal"[^>]*>\s*Tutup\s*<\/button>/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<button[^>]*type="button"[^>]*class="btn-close"[^>]*data-bs-dismiss="modal"[^>]*aria-label="Tutup"[^>]*><\/button>/',
            $html
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testModalPartialRendersNothingWithoutId(): void
    {
        $html = $this->renderPartial('partials/page-info-modal.php', [
            '__infoId' => '',
            '__infoTitle' => 'Tentang X',
            '__infoHtml' => '<p>x</p>',
        ]);

        self::assertSame('', trim($html));
    }

    // ---------------------------------------------------------------------
    // (ii) Statik pada kelima view
    // ---------------------------------------------------------------------

    public function testEveryPageWiresButtonAndModalAndMovesLongParagraph(): void
    {
        foreach (self::PAGES as $file => $page) {
            $src = (string) file_get_contents(self::VIEW_DIR . '/' . $file);
            $label = $file;

            // Kedua partial di-include lewat `app_path()` (pola view repo).
            $buttonInclude = "include app_path() . '/view/partials/page-info-button.php'";
            $modalInclude = "include app_path() . '/view/partials/page-info-modal.php'";
            self::assertStringContainsString($buttonInclude, $src, $label . ': tombol info belum di-include');
            self::assertStringContainsString($modalInclude, $src, $label . ': modal info belum di-include');

            // ID modal + judul.
            self::assertStringContainsString("'" . $page['id'] . "'", $src, $label . ': ID modal salah');
            self::assertStringContainsString("'" . $page['title'] . "'", $src, $label . ': judul modal salah');

            // Ringkasan satu baris di bawah judul.
            self::assertStringContainsString(
                '<p class="text-muted mb-0">' . $page['summary'] . '</p>',
                $src,
                $label . ': ringkasan satu baris tidak sesuai'
            );

            // Tombol berada DI DALAM `<h1>`.
            $h1Start = strpos($src, '<h1');
            self::assertNotFalse($h1Start, $label . ': <h1> tidak ditemukan');
            $h1End = strpos($src, '</h1>', (int) $h1Start);
            self::assertNotFalse($h1End, $label . ': </h1> tidak ditemukan');
            $buttonPos = strpos($src, 'partials/page-info-button.php');
            self::assertNotFalse($buttonPos, $label . ': include tombol hilang');
            self::assertGreaterThan((int) $h1Start, (int) $buttonPos, $label . ': tombol harus di dalam <h1>');
            self::assertLessThan((int) $h1End, (int) $buttonPos, $label . ': tombol harus di dalam <h1>');

            // Modal dirender setelah `.page-head` (di luar area judul).
            $pageHeadPos = strpos($src, 'class="page-head');
            self::assertNotFalse($pageHeadPos, $label . ': .page-head tidak ditemukan');
            $modalPos = strpos($src, 'partials/page-info-modal.php');
            self::assertNotFalse($modalPos, $label . ': include modal hilang');
            self::assertGreaterThan((int) $pageHeadPos, (int) $modalPos, $label . ': modal harus setelah .page-head');

            // Paragraf lama benar-benar pindah ke `$__infoHtml` (di dalam blok
            // `ob_start()` … `ob_get_clean()`, bukan di ringkasan bawah judul).
            $captureEnd = strpos($src, '$__infoHtml = (string) ob_get_clean();');
            self::assertNotFalse($captureEnd, $label . ': blok ob_get_clean hilang');
            $captureStart = strrpos(substr($src, 0, (int) $captureEnd), 'ob_start();');
            self::assertNotFalse($captureStart, $label . ': blok ob_start hilang');
            self::assertGreaterThan((int) $pageHeadPos, (int) $captureStart, $label . ': blok info harus setelah .page-head');
            foreach ($page['phrases'] as $phrase) {
                $phrasePos = strpos($src, $phrase);
                self::assertNotFalse($phrasePos, $label . ': frasa paragraf lama hilang: ' . $phrase);
                self::assertGreaterThan(
                    (int) $captureStart,
                    (int) $phrasePos,
                    $label . ': frasa lama harus di dalam blok $__infoHtml: ' . $phrase
                );
                self::assertLessThan(
                    (int) $captureEnd,
                    (int) $phrasePos,
                    $label . ': frasa lama harus di dalam blok $__infoHtml: ' . $phrase
                );
                self::assertStringNotContainsString(
                    '<p class="text-muted mb-0">' . $phrase,
                    $src,
                    $label . ': paragraf lama masih menjadi ringkasan: ' . $phrase
                );
            }
        }
    }

    /**
     * Render partial view dengan scope lokal (pola include view Webman).
     *
     * @param array<string, mixed> $vars
     */
    private function renderPartial(string $file, array $vars): string
    {
        if (!function_exists('e')) {
            $stub = self::stubFile();
            require $stub;
            @unlink($stub);
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        include self::VIEW_DIR . '/' . $file;

        return (string) ob_get_clean();
    }

    /**
     * Berkas stub helper global `e()` (hanya hidup di anak proses terpisah).
     */
    private static function stubFile(): string
    {
        static $path = null;
        if ($path !== null && is_file($path)) {
            return $path;
        }

        $path = sys_get_temp_dir() . '/rames-page-info-stub-' . getmypid() . '.php';
        file_put_contents($path, <<<'PHP'
            <?php
            namespace {
                if (!function_exists('e')) {
                    function e(mixed $value): string
                    {
                        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                }
            }
            PHP);

        return $path;
    }
}
