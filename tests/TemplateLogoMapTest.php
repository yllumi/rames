<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test pemetaan logo template (`template_logo_map()`) — SPECS.md §7.2b.
 *
 * Logo galeri/tab Template/form deploy diresolusi dari berkas nyata
 * `public/images/templates/<slug>.<svg|png|webp>`, jadi invarian berikut dijaga:
 *   1. hanya ekstensi whitelist (case-insensitive) yang diterima;
 *   2. nama berkas harus slug yang sah (huruf kecil/angka; dash hanya di tengah)
 *      — sama dengan `TemplateCatalog::isValidSlug()`;
 *   3. satu berkas per slug (berkas pertama menang, tidak ambigu);
 *   4. direktori tanpa berkas / tidak ada → peta kosong (view memakai emoji).
 *
 * Dipakai TIGA view (galeri create-app, tab Template halaman Config, judul form
 * deploy) sehingga aturannya diuji sekali di sini, bukan lewat smoke per view.
 */
class TemplateLogoMapTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        // Helper global (guard `function_exists` di dalamnya membuat require ini aman).
        require_once dirname(__DIR__) . '/app/functions.php';

        $this->tmp = sys_get_temp_dir() . '/rames-logo-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{*,.*}', GLOB_BRACE) ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->tmp);
    }

    public function testOnlyWhitelistedExtensionsAreMapped(): void
    {
        foreach (['a.svg', 'b.png', 'c.webp', 'd.svgz', 'e.jpg', 'f.txt', 'g'] as $name) {
            file_put_contents($this->tmp . '/' . $name, 'x');
        }

        $map = template_logo_map($this->tmp);

        $this->assertSame(['a' => 'svg', 'b' => 'png', 'c' => 'webp'], $map);
    }

    public function testExtensionCaseIsAcceptedButSlugMustBeCanonical(): void
    {
        file_put_contents($this->tmp . '/UPPER.PNG', 'x');      // slug belum lowercase
        file_put_contents($this->tmp . '/ok-1.SVG', 'x');       // slug sah, ekstensi huruf besar
        file_put_contents($this->tmp . '/-lead.png', 'x');      // dash di awal
        file_put_contents($this->tmp . '/trail-.png', 'x');     // dash di akhir
        file_put_contents($this->tmp . '/under_score.png', 'x');

        $map = template_logo_map($this->tmp);

        $this->assertSame(['ok-1' => 'SVG'], $map);
    }

    public function testOnlyOneFilePerSlugIsMapped(): void
    {
        // Dua berkas untuk slug yang sama → harus satu entri (tidak ambigu).
        file_put_contents($this->tmp . '/dup.png', 'x');
        file_put_contents($this->tmp . '/dup.svg', 'x');

        $map = template_logo_map($this->tmp);

        $this->assertCount(1, $map);
        $this->assertArrayHasKey('dup', $map);
        $this->assertContains($map['dup'], ['png', 'svg']);
    }

    public function testMissingOrEmptyDirectoryYieldsEmptyMap(): void
    {
        $this->assertSame([], template_logo_map($this->tmp));
        $this->assertSame([], template_logo_map($this->tmp . '/tidak-ada'));
    }

    /**
     * Invarian aset yang IKUT repo (bukan temp): view memakai nama berkas apa
     * adanya, jadi slug berdua-berkas atau ekstensi di luar whitelist akan
     * membuat tampilan galeri tidak deterministik.
     */
    public function testShippedLogoAssetsAreUnambiguous(): void
    {
        $dir = dirname(__DIR__) . '/public/images/templates';
        $map = template_logo_map($dir);

        $this->assertNotSame([], $map, 'Tidak ada aset logo di ' . $dir);

        $files = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => !str_starts_with($f, '.')));
        $this->assertSame(
            count($map),
            count($files),
            'Ada berkas di public/images/templates/ yang tidak masuk peta logo (ekstensi bukan whitelist atau slug tidak sah): '
                . implode(', ', $files)
        );

        foreach ($map as $slug => $extension) {
            $this->assertSame(strtolower($extension), $extension, 'Ekstensi aset tidak lowercase: ' . $slug);
        }

        // Template bawaan yang sudah punya logo berkas (aset diambil dari
        // Dashboard Icons / situs vendor) — dijaga agar tidak hilang tak sengaja.
        foreach (['picoclaw', 'lemp', 'lemp74', 'openclaw', 'raisfast', 'wabaileys', 'opencode'] as $slug) {
            $this->assertArrayHasKey($slug, $map, 'Aset logo hilang: ' . $slug);
        }
    }
}
