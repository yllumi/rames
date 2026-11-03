<?php
declare(strict_types=1);

namespace Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Kontrak **ikon SVG** dashboard (pengganti emoji/glif).
 *
 * Sumber tunggal geometri ikon = `icon_paths()` di `app/functions.php`. Semua
 * pemakaian ikon harus lewat salah satu jalur berikut, dan kontraknya dikunci
 * di sini:
 *
 *   1. `app/view/partials/icon-sprite.php` merender `<symbol id="ico-NAME">`
 *      **tepat satu kali** untuk setiap entri `icon_paths()`, dan partial itu
 *      di-`include` dari `app/view/partials/footer.php`;
 *   2. `svg_icon($name, $class)` ⇒ `<svg class="icon …"><use href="#ico-NAME">`
 *      untuk nama yang dikenal, string kosong untuk nama tak dikenal (agar
 *      render halaman tidak pecah), kelas tambahan digabung dengan spasi;
 *   3. `template_icon($icon, $class)` ⇒ ikon `package` bila nama kosong atau tak
 *      dikenal (template pihak ketiga yang masih menyimpan emoji di
 *      `template.yml` tidak boleh mencetak emoji);
 *   4. setiap **literal** nama ikon pada `app/view/**` dan `public/js/**` yang
 *      dipakai lewat `svg_icon('X')`, `template_icon('X')`, `ramesIcon('X')`,
 *      `ramesIconNode('X')` wajib ada di `icon_paths()` — salah ketik nama =
 *      ikon tak tampil (diam-diam), jadi harus gagal di CI. Argumen non-literal
 *      (variabel, ternary, pemanggilan fungsi) sengaja dilewati. Pembungkus
 *      lokal yang meneruskan argumen pertama sebagai nama ikon
 *      (`actionBtn('X', …)`/`iconNode('X')` di `public/js/app-files.js`)
 *      didaftarkan di {@see self::WRAPPER_ICON_PATTERNS} — daftar itu sempit
 *      dan spesifik berkas, bukan pola generik;
 *   5. `app/view/**`, `public/js/**`, `public/css/**` tidak boleh memakai
 *      karakter emoji/dingbat/panah sebagai ikon. Deteksinya **berbasis
 *      kategori Unicode** (`So`/`Sm`/`Sk` + rentang emoji/piktograf), bukan
 *      daftar rentang tertutup — daftar rentang selalu ketinggalan glif baru
 *      (⟳ U+27F3, ⌫ U+232B, ⏱ U+23F1, ⏳ U+23F3, ⧉ U+29C9 sempat lolos);
 *   6. setiap `template.yml` boleh mengosongkan `icon:`, tetapi nilai yang
 *      diisi wajib nama di `icon_paths()` (bukan emoji/path sembarang).
 *
 * Pengecualian tes #5 (sempit & terdokumentasi — JANGAN diperluas tanpa alasan):
 *   - {@see self::ALLOWED_PROSE_GLYPHS}: tipografi prosa yang sah di teks
 *     (`— · … → § ⇒ ≈ ± ≥ ≤ ← – “ ” • ‹ › ↔ ∈ × −`). Panah di sini adalah kata
 *     penghubung di kalimat/keterangan ("A → B"), bukan ikon; menggantinya
 *     tidak menambah keamanan apa pun.
 *   - {@see self::ALLOWED_STATUS_MARKERS}: penanda status teks yang disengaja
 *     (`✔ ✖ ⚠ ⏱ ⏳`), **hanya** diizinkan pada berkas yang terdaftar di
 *     {@see self::STATUS_MARKER_FILES} (`public/js/server.js`). Penanda itu
 *     ditulis sebagai `textContent` status job, bukan ikon tombol.
 *
 * Berkas ini hanya membaca sumber + merender partial sprite (tanpa HTTP,
 * session, store, Docker, atau data runtime).
 */
final class IconSpriteContractTest extends TestCase
{
    /** Akar repo. */
    private const ROOT = __DIR__ . '/..';

    /** Direktori view (`app/view`). */
    private const VIEW_DIR = self::ROOT . '/app/view';

    /** Partial sprite ikon. */
    private const SPRITE = self::VIEW_DIR . '/partials/icon-sprite.php';

    /**
     * Karakter yang sah sebagai **prosa** (bukan ikon) di view/JS/CSS.
     * Daftar eksplisit supaya setiap penambahan harus lewat review.
     *
     * @var array<int,string>
     */
    private const ALLOWED_PROSE_GLYPHS = [
        '—', // em dash — pemisah prosa
        '–', // en dash — rentang angka
        '·', // middle dot — pemisah label
        '…', // ellipsis
        '→', // panah kanan — "A → B" di keterangan
        '←', // panah kiri — asal/arah kembali di prosa
        '↔', // panah dua arah — "dipetakan ↔" di prosa
        '§', // section sign — rujukan docs/SPECS.md
        '⇒', // double arrow — implikasi di keterangan
        '≈', // approx
        '±', // plus-minus
        '≥', // greater-or-equal
        '≤', // less-or-equal
        '“', // curly quote buka
        '”', // curly quote tutup
        '•', // bullet prosa
        '‹', // single guillemet
        '›', // single guillemet
        '∈', // element-of
        '×', // multiplication sign
        '−', // minus sign (bukan hyphen)
    ];

    /** Penanda status teks yang disengaja (bukan ikon). */
    private const ALLOWED_STATUS_MARKERS = ['✔', '✖', '⚠', '⏱', '⏳'];

    /**
     * Satu-satunya berkas (relatif terhadap akar repo) yang boleh memuat
     * {@see self::ALLOWED_STATUS_MARKERS}.
     *
     * @var array<int,string>
     */
    private const STATUS_MARKER_FILES = ['public/js/server.js'];

    /**
     * Rentang kode titik emoji/piktograf & pembentuk emoji yang dilarang.
     * Rentang simbol (dingbat/panah/geometri/misc) **tidak** didaftarkan di
     * sini — itu tugas kategori Unicode di {@see self::FORBIDDEN_CATEGORY_PATTERN}
     * supaya tidak ada lagi glif yang lolos karena lupa didaftarkan.
     *
     * @var array<int,array{0:int,1:int,2:string}>
     */
    private const FORBIDDEN_EMOJI_RANGES = [
        // Subset U+1F000–U+1FAFF; didaftarkan lebih dulu agar labelnya spesifik.
        [0x1F1E6, 0x1F1FF, 'indikator regional (bendera)'],
        [0x1F000, 0x1FAFF, 'emoji/piktograf'],
        [0xFE0F, 0xFE0F, 'variation selector-16 (pemaksa emoji)'],
        [0x200D, 0x200D, 'zero-width joiner (penggabung emoji)'],
        [0x20E3, 0x20E3, 'combining enclosing keycap'],
    ];

    /**
     * Kategori Unicode yang dianggap glif ikon: `So` (Symbol, other — ⌫ U+232B,
     * ⏳ U+23F3, ⚠ U+26A0), `Sm` (Symbol, math — ⟳ U+27F3, ⧉ U+29C9, → U+2192)
     * dan `Sk` (Symbol, modifier). PCRE mendukung properti Unicode tanpa
     * ekstensi `intl`.
     */
    private const FORBIDDEN_CATEGORY_PATTERN = '/\p{So}|\p{Sm}|\p{Sk}/u';

    /**
     * Karakter ASCII berkategori `Sm`/`Sk` yang di kode sumber PHP/JS/CSS
     * adalah **operator/sintaksis**, bukan ikon. Daftar ini sempit dan harus
     * tetap **sama persis** dengan himpunan ASCII berkategori itu (dijaga oleh
     * {@see self::testAsciiOperatorsAreNotTreatedAsIcons()}).
     *
     * @var array<int,string>
     */
    private const ASCII_SYNTAX_GLYPHS = ['+', '<', '=', '>', '^', '`', '|', '~'];

    /**
     * Pola pemanggilan helper ikon dengan argumen **literal** nama ikon.
     * Argumen non-literal (variabel/ternary/pemanggilan) tidak cocok sehingga
     * otomatis dilewati.
     */
    private const ICON_CALL_PATTERN = '/\b(?:svg_icon|template_icon|ramesIcon|ramesIconNode)\(\s*\'([^\']*)\'/';

    /**
     * Pola tambahan yang **hanya** berlaku untuk berkas tertentu: pembungkus
     * lokal yang meneruskan argumen pertamanya sebagai nama ikon ke
     * `ramesIconNode()`/`ramesIcon()`, sehingga nama literal tak terlihat oleh
     * {@see self::ICON_CALL_PATTERN}. Sengaja sempit & spesifik — jangan
     * mengubahnya menjadi pola generik lintas berkas.
     *
     * @var array<string,array<int,string>>
     */
    private const WRAPPER_ICON_PATTERNS = [
        // public/js/app-files.js:
        //   `function iconNode(name, cls) { return ramesIconNode(name, cls); }`
        //   `function actionBtn(icon, label, fn, cls) { b.appendChild(iconNode(icon)); }`
        // ⇒ argumen pertama `iconNode('X')` / `actionBtn('X', …)` = nama ikon.
        'public/js/app-files.js' => [
            '/\bactionBtn\(\s*\'([^\']*)\'/',
            '/\biconNode\(\s*\'([^\']*)\'/',
        ],
    ];

    protected function setUp(): void
    {
        // Helper global asli (guard `function_exists` di dalamnya membuat
        // require ini aman diulang): e(), icon_paths(), svg_icon(), template_icon().
        require_once dirname(__DIR__) . '/app/functions.php';
    }

    // ------------------------------------------------------------------
    // 1. Peta ikon ↔ sprite
    // ------------------------------------------------------------------

    public function testIconNamesAreSafeIdentifiers(): void
    {
        $names = array_keys(icon_paths());

        $this->assertNotSame([], $names, 'icon_paths() tidak boleh kosong');

        foreach ($names as $name) {
            // Nama dipakai mentah di `id="ico-NAME"` (HTML) dan `#ico-NAME`
            // (fragmen URL) — wajib slug huruf kecil/angka berpemisah dash.
            $this->assertMatchesRegularExpression(
                '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                (string) $name,
                'Nama ikon wajib slug huruf kecil: ' . $name
            );
        }
    }

    public function testSpriteRendersExactlyOneSymbolPerIconName(): void
    {
        $map = icon_paths();

        $html = $this->renderSprite();

        $this->assertSame(
            count($map),
            substr_count($html, '<symbol'),
            'Jumlah <symbol> di sprite harus sama dengan jumlah entri icon_paths()'
        );

        foreach (array_keys($map) as $name) {
            $this->assertSame(
                1,
                substr_count($html, '<symbol id="ico-' . $name . '"'),
                'Simbol `ico-' . $name . '` harus dirender tepat satu kali'
            );
        }

        // Geometri kosong = ikon tak terlihat; peta adalah satu-satunya sumber.
        $this->assertNotContains('', $map, 'Setiap entri icon_paths() wajib punya geometri');
    }

    public function testSpriteIsIncludedByFooter(): void
    {
        $footer = (string) file_get_contents(self::VIEW_DIR . '/partials/footer.php');

        $this->assertStringContainsString(
            'icon-sprite.php',
            $footer,
            'Footer wajib meng-include partial sprite ikon supaya #ico-* tersedia'
        );
    }

    // ------------------------------------------------------------------
    // 2. svg_icon()
    // ------------------------------------------------------------------

    public function testSvgIconReferencesSpriteForEveryKnownName(): void
    {
        foreach (array_keys(icon_paths()) as $name) {
            $svg = svg_icon($name);

            $this->assertStringContainsString('#ico-' . $name, $svg, 'svg_icon(' . $name . ')');
            $this->assertStringContainsString('class="icon"', $svg, 'svg_icon(' . $name . ') kelas dasar');
            $this->assertStringContainsString('aria-hidden="true"', $svg, 'ikon dekoratif, bukan teks');
        }
    }

    public function testSvgIconReturnsEmptyStringForUnknownName(): void
    {
        $this->assertSame('', svg_icon('tidak-ada'));
        $this->assertSame('', svg_icon(''));
        $this->assertSame('', svg_icon('EYE'), 'nama ikon peka huruf besar/kecil');
    }

    public function testSvgIconAppendsExtraClassAfterBaseClass(): void
    {
        $svg = svg_icon('eye', 'icon-lg');

        $this->assertStringContainsString('class="icon icon-lg"', $svg);
        $this->assertStringContainsString('#ico-eye', $svg);
    }

    // ------------------------------------------------------------------
    // 3. template_icon()
    // ------------------------------------------------------------------

    public function testTemplateIconUsesKnownName(): void
    {
        $svg = template_icon('database');

        $this->assertStringContainsString('#ico-database', $svg);
        $this->assertStringContainsString('class="icon"', $svg);
    }

    public function testTemplateIconFallsBackToPackage(): void
    {
        foreach (['', 'tidak-ada', '📦', '🐳', '⚙️'] as $value) {
            $svg = template_icon($value);

            $this->assertStringContainsString(
                '#ico-package',
                $svg,
                'Nilai ikon "' . $value . '" harus jatuh ke ikon package'
            );
        }
    }

    public function testTemplateIconKeepsExtraClassOnFallback(): void
    {
        $svg = template_icon('📦', 'icon-lg');

        $this->assertStringContainsString('class="icon icon-lg"', $svg);
        $this->assertStringContainsString('#ico-package', $svg);
    }

    // ------------------------------------------------------------------
    // 4. Pemindaian statis nama ikon
    // ------------------------------------------------------------------

    public function testEveryLiteralIconNameExistsInIconPaths(): void
    {
        $scan = $this->literalIconViolations(self::ROOT);
        $unknown = $scan['unknown'];
        $seen = $scan['seen'];

        $this->assertSame(
            [],
            $unknown,
            "Nama ikon literal yang tidak ada di icon_paths() (salah ketik?):\n" . implode("\n", $unknown)
        );
        // Ambang rendah: menjaga tes tetap hijau saat ikon dihapus, tetapi
        // menangkap regex/direktori yang berhenti membaca berkas (0–2 temuan).
        $this->assertGreaterThan(
            10,
            count($seen),
            'Pemindaian harus menemukan literal nama ikon; temuan terlalu sedikit berarti pola/berkas tidak terbaca'
        );

        // Bukti pembungkus di app-files.js ikut terbaca (bukan hanya `ramesIcon*`).
        foreach (['download', 'trash', 'folder'] as $name) {
            $this->assertContains(
                $name,
                $seen,
                'Literal ikon lewat pembungkus actionBtn()/iconNode() harus terbaca: ' . $name
            );
        }
    }

    // ------------------------------------------------------------------
    // 5. Larangan emoji/glif ikon
    // ------------------------------------------------------------------

    public function testNoEmojiOrGlyphIconsInViewsJsAndCss(): void
    {
        $violations = $this->glyphViolations(self::ROOT);

        $this->assertSame(
            [],
            $violations,
            "Emoji/glif dipakai sebagai ikon (pakai svg_icon()/ramesIcon()):\n"
                . implode("\n", $violations)
        );
    }

    /**
     * Penjaga regresi **detektor**: glif yang dulu dipakai di HEAD wajib
     * tertangkap, penanda status hanya lolos di {@see self::STATUS_MARKER_FILES},
     * prosa tetap lolos, dan ASCII tidak pernah dianggap ikon.
     */
    public function testGlyphDetectorFlagsLegacyIconGlyphs(): void
    {
        $legacy = [
            '⟳' => 'U+27F3', // symbol, math — dulu tombol refresh
            '⌫' => 'U+232B', // symbol, other — dulu tombol hapus
            '⏱' => 'U+23F1', // symbol, other — penanda status, hanya server.js
            '⏳' => 'U+23F3', // symbol, other — penanda status, hanya server.js
            '⧉' => 'U+29C9', // symbol, math — dulu tombol duplikat
        ];

        foreach ($legacy as $glyph => $code) {
            $found = $this->glyphViolationsInSource('public/js/app-files.js', "var x = '" . $glyph . "';\n");

            $this->assertCount(1, $found, 'Glif ' . $glyph . ' (' . $code . ') wajib terdeteksi');
            $this->assertStringContainsString($code, $found[0], 'Alasan wajib menyebut kode titik ' . $code);
        }

        // Penanda status: lolos hanya di berkas terdaftar.
        $this->assertSame(
            [],
            $this->glyphViolationsInSource('public/js/server.js', "msg = '⏱ lalu ⏳';\n"),
            'Penanda status di public/js/server.js masih diizinkan'
        );
        $this->assertCount(
            2,
            $this->glyphViolationsInSource('public/js/other.js', "msg = '⏱ lalu ⏳';\n"),
            'Penanda status di berkas lain wajib ditolak'
        );

        // Prosa (tipografi/panah penghubung) tetap lolos.
        $this->assertSame([], $this->glyphViolationsInSource(
            'app/view/x.php',
            "A → B · rentang 1–2 … § — “kutip” • × ≈ ≥ ≤ ± ⇒ ∈ ↔ ‹ › −\n"
        ));

        // ASCII berkategori Sm/Sk adalah operator, bukan ikon.
        $this->assertSame([], $this->glyphViolationsInSource(
            'app/view/x.php',
            "<?php if (\$a >= 1 && \$b <= 2 || \$c != 3) { echo 1 + 2; } // < > = | ~ ^ `\n"
        ));

        // Emoji dipaksa lewat VS16/ZWJ tetap ditolak walau glif dasarnya lolos.
        $this->assertCount(
            1,
            $this->glyphViolationsInSource('public/js/server.js', "msg = '⚠\u{FE0F}';\n"),
            'Variation selector-16 wajib ditolak (pemaksa presentasi emoji)'
        );
        $this->assertCount(
            1,
            $this->glyphViolationsInSource('public/js/server.js', "msg = 'a\u{200D}b';\n"),
            'Zero-width joiner wajib ditolak'
        );
        $this->assertCount(
            1,
            $this->glyphViolationsInSource('app/view/x.php', "'😀'\n"),
            'Emoji U+1F600 wajib ditolak'
        );
    }

    /**
     * Pengecualian ASCII harus **tetap sempit**: himpunan karakter ASCII
     * berkategori Sm/Sk dihitung ulang di sini, sehingga tidak ada operator
     * yang lolos tanpa didaftarkan dan tidak ada karakter non-operator ikut
     * dikecualikan.
     */
    public function testAsciiOperatorsAreNotTreatedAsIcons(): void
    {
        $derived = [];

        for ($code = 0x20; $code <= 0x7E; $code++) {
            $char = chr($code);

            if (preg_match(self::FORBIDDEN_CATEGORY_PATTERN, $char) === 1) {
                $derived[] = $char;
            }

            // Seluruh ASCII yang bukan operator Sm/Sk tidak boleh jadi pelanggaran.
            if (!in_array($char, self::ASCII_SYNTAX_GLYPHS, true)) {
                $this->assertNull(
                    $this->glyphViolationReason($char),
                    'Karakter ASCII printable tidak boleh dianggap ikon: U+' . strtoupper(dechex($code))
                );
            }
        }

        $expected = self::ASCII_SYNTAX_GLYPHS;
        sort($expected);

        $this->assertSame(
            $expected,
            $derived,
            'ASCII_SYNTAX_GLYPHS wajib tepat sama dengan himpunan ASCII berkategori Sm/Sk'
        );
    }

    /**
     * `templates/*\/template.yml` menyimpan **nama ikon**; nilai kosong sah
     * (jatuh ke ikon `package`), nilai terisi wajib ada di `icon_paths()`.
     */
    public function testTemplateIconValuesAreKnownIconNames(): void
    {
        $scan = $this->templateIconViolations(self::ROOT);

        $this->assertGreaterThan(
            10,
            $scan['files'],
            'templates/*/template.yml harus terbaca; jumlah terlalu sedikit berarti glob/pola tidak jalan'
        );
        $this->assertSame(
            [],
            $scan['violations'],
            "template.yml memakai nama ikon yang tidak ada di icon_paths():\n" . implode("\n", $scan['violations'])
        );

        foreach ($scan['icons'] as $icon) {
            $this->assertMatchesRegularExpression(
                '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                $icon,
                'Nilai icon: di template.yml wajib slug nama ikon (bukan emoji/path): ' . $icon
            );
            $this->assertArrayHasKey($icon, icon_paths(), 'Nilai icon: wajib ada di icon_paths(): ' . $icon);
        }
    }

    public function testJsIconHelpersAreExposedOnWindow(): void
    {
        $js = (string) file_get_contents(self::ROOT . '/public/js/icons.js');

        $this->assertMatchesRegularExpression('/window\.ramesIcon\s*=/', $js, 'ramesIcon() wajib ada');
        $this->assertMatchesRegularExpression('/window\.ramesIconNode\s*=/', $js, 'ramesIconNode() wajib ada');
        // JS tidak boleh menduplikasi geometri: cukup rujuk sprite `#ico-*`.
        $this->assertStringContainsString('#ico-', $js, 'JS wajib memakai sprite #ico-*');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** Render partial sprite dalam buffer terpisah (tanpa HTTP). */
    private function renderSprite(): string
    {
        $obLevel = ob_get_level();
        ob_start();

        try {
            include self::SPRITE;
        } catch (Throwable $error) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }

            throw $error;
        }

        $html = ob_get_clean();
        $this->assertIsString($html);

        return $html;
    }

    /**
     * Berkas sumber di bawah `$dir` dengan ekstensi tertentu (terurut, stabil).
     *
     * @param array<int,string> $extensions
     * @return array<int,string>
     */
    private function sourceFiles(string $dir, array $extensions): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ) as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                continue;
            }

            if (in_array(strtolower($entry->getExtension()), $extensions, true)) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** Path absolut → relatif terhadap `$root` (untuk pesan gagal). */
    private function relative(string $path, ?string $root = null): string
    {
        $root = (string) realpath($root ?? self::ROOT);
        $real = (string) realpath($path);

        return str_starts_with($real, $root . '/') ? substr($real, strlen($root) + 1) : $path;
    }

    /** Nomor baris (1-based) dari offset byte. */
    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    // ------------------------------------------------------------------
    // Pemindai (parameter `$root` = akar repo; bisa diarahkan ke fixture)
    // ------------------------------------------------------------------

    /**
     * Pemindaian literal nama ikon pada `app/view/**` dan `public/js/**`.
     *
     * @return array{unknown:array<int,string>,seen:array<int,string>}
     */
    private function literalIconViolations(string $root): array
    {
        $map = icon_paths();
        $unknown = [];
        $seen = [];

        $files = array_merge(
            $this->sourceFiles($root . '/app/view', ['php']),
            $this->sourceFiles($root . '/public/js', ['js'])
        );

        foreach ($files as $file) {
            $relative = $this->relative($file, $root);
            $source = (string) file_get_contents($file);

            // Pola generik + pola pembungkus khusus berkas (mis. app-files.js).
            $patterns = array_merge(
                [self::ICON_CALL_PATTERN],
                self::WRAPPER_ICON_PATTERNS[$relative] ?? []
            );

            foreach ($patterns as $pattern) {
                $found = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
                if ($found === false) {
                    $this->fail('Pola pemindaian ikon tidak valid: ' . $pattern);
                }

                foreach ($matches[1] as [$name, $offset]) {
                    $name = (string) $name;

                    // Literal kosong = bug pemanggilan, bukan nama ikon valid.
                    if ($name === '' || !isset($map[$name])) {
                        $unknown[] = $relative . ':' . $this->lineAt($source, (int) $offset)
                            . ' ⇒ "' . $name . '"';
                        continue;
                    }

                    $seen[$name] = true;
                }
            }
        }

        $seenNames = array_keys($seen);
        sort($seenNames);

        return ['unknown' => $unknown, 'seen' => $seenNames];
    }

    /**
     * Pemindaian glif terlarang pada `app/view/**`, `public/js/**`,
     * `public/css/**` di bawah `$root`.
     *
     * @return array<int,string>
     */
    private function glyphViolations(string $root): array
    {
        $violations = [];

        $files = array_merge(
            $this->sourceFiles($root . '/app/view', ['php']),
            $this->sourceFiles($root . '/public/js', ['js']),
            $this->sourceFiles($root . '/public/css', ['css'])
        );

        foreach ($files as $file) {
            $violations = array_merge(
                $violations,
                $this->glyphViolationsInSource(
                    $this->relative($file, $root),
                    (string) file_get_contents($file)
                )
            );
        }

        return $violations;
    }

    /**
     * Glif terlarang pada **isi** satu berkas (`$relative` menentukan berkas
     * mana yang boleh memuat penanda status).
     *
     * @return array<int,string>
     */
    private function glyphViolationsInSource(string $relative, string $source): array
    {
        $violations = [];

        $allowed = self::ALLOWED_PROSE_GLYPHS;
        if (in_array($relative, self::STATUS_MARKER_FILES, true)) {
            $allowed = array_merge($allowed, self::ALLOWED_STATUS_MARKERS);
        }

        $lines = preg_split('/\R/u', $source);
        if ($lines === false) {
            $this->fail('Berkas bukan UTF-8 valid: ' . $relative);
        }

        foreach ($lines as $index => $line) {
            $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                $this->fail('Berkas bukan UTF-8 valid: ' . $relative);
            }

            foreach ($chars as $char) {
                if (in_array($char, $allowed, true)) {
                    continue;
                }

                $reason = $this->glyphViolationReason($char);
                if ($reason === null) {
                    continue;
                }

                $violations[] = $relative . ':' . ($index + 1)
                    . ' U+' . strtoupper(dechex($this->codePoint($char)))
                    . ' "' . $char . '" (' . $reason . ') ⇒ ' . trim($line);
            }
        }

        return $violations;
    }

    /**
     * Nilai `icon:` pada setiap `templates/*\/template.yml`.
     *
     * @return array{files:int,violations:array<int,string>,icons:array<int,string>}
     */
    private function templateIconViolations(string $root): array
    {
        $map = icon_paths();
        $files = glob($root . '/templates/*/template.yml') ?: [];
        sort($files);

        $violations = [];
        $icons = [];

        foreach ($files as $file) {
            $yaml = (string) file_get_contents($file);
            $relative = $this->relative($file, $root);

            if (preg_match('/^icon:[ \t]*(.*)$/m', $yaml, $matches) !== 1) {
                continue;
            }

            $icon = $this->yamlScalar($matches[1]);

            // Kosong sah: `template_icon()` jatuh ke ikon `package`.
            if ($icon === '') {
                continue;
            }

            $icons[] = $icon;

            if (!isset($map[$icon])) {
                $violations[] = $relative . ' ⇒ "' . $icon . '"';
            }
        }

        return ['files' => count($files), 'violations' => $violations, 'icons' => $icons];
    }

    /** Nilai skalar YAML sederhana: `"..."`, `'...'`, atau plain + komentar. */
    private function yamlScalar(string $raw): string
    {
        $value = trim($raw);

        if (str_starts_with($value, '"')) {
            $end = strrpos($value, '"');
            $inner = ($end !== false && $end > 0) ? substr($value, 1, $end - 1) : substr($value, 1);

            // Escape ganda-kutip yang relevan (nama ikon praktis tanpa escape).
            return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
        }

        if (str_starts_with($value, "'")) {
            $end = strrpos($value, "'");
            $inner = ($end !== false && $end > 0) ? substr($value, 1, $end - 1) : substr($value, 1);

            return str_replace("''", "'", $inner);
        }

        // Plain scalar: komentar YAML diawali ` #`.
        $value = (string) preg_replace('/[ \t]+#.*$/', '', $value);

        return trim($value);
    }

    /**
     * Alasan larangan untuk satu karakter UTF-8, atau null bila aman.
     *
     * Deteksi berbasis **kategori Unicode**, bukan daftar rentang tertutup:
     * rentang tertutup selalu ketinggalan glif ikon baru. `\p{So}` mencakup
     * ⌫ U+232B & ⏳ U+23F3, `\p{Sm}` mencakup ⟳ U+27F3, ⧉ U+29C9 & → U+2192.
     * ASCII di luar {@see self::ASCII_SYNTAX_GLYPHS} tetap diperiksa; delapan
     * karakter operator (`+ < = > ^ \` | ~`) dilewati karena berkategori
     * Sm/Sk padahal di kode sumber ia sintaksis, bukan ikon.
     */
    private function glyphViolationReason(string $char): ?string
    {
        if (in_array($char, self::ASCII_SYNTAX_GLYPHS, true)) {
            return null;
        }

        $code = $this->codePoint($char);

        foreach (self::FORBIDDEN_EMOJI_RANGES as [$start, $end, $label]) {
            if ($code >= $start && $code <= $end) {
                return $label;
            }
        }

        if (preg_match(self::FORBIDDEN_CATEGORY_PATTERN, $char) === 1) {
            return 'kategori simbol Unicode (So/Sm/Sk)';
        }

        return null;
    }

    /**
     * Kode titik UTF-8 tanpa bergantung pada ekstensi `mbstring`
     * (`composer.json` tidak mewajibkannya).
     */
    private function codePoint(string $char): int
    {
        /** @var array<int,int>|false $bytes */
        $bytes = unpack('C*', $char);
        if ($bytes === false || $bytes === []) {
            return -1;
        }

        $bytes = array_values($bytes);
        $length = count($bytes);

        if ($length === 1) {
            return $bytes[0];
        }

        $code = $bytes[0] & (0xFF >> ($length + 1));
        for ($i = 1; $i < $length; $i++) {
            $code = ($code << 6) | ($bytes[$i] & 0x3F);
        }

        return $code;
    }
}
