<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\AppFilter;
use app\library\Deploy\ServerResolver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Webman\Config;

// Helper webman (`config()`, `app_subdomain_of()`) hanya dimuat di runtime
// webman: definisikan konstanta + fungsi sebelum kelas dipakai.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain_of')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Test {@see AppFilter} — statik murni: pencocokan `name`/`domain`/`owner`
 * untuk filter daftar app (SPECS.md §8e).
 *
 * Kelas ini **tidak** mengurus otorisasi; seluruh uji di sini murni pencocokan.
 * `app_subdomain_of()` (sumber resolusi domain efektif) membaca registry server
 * lewat config — karena itu config diarahkan ke direktori temp
 * (`sys_get_temp_dir()`) dan **tidak pernah** menyentuh `database/`/`apps/`
 * nyata (pola sama `tests/ServerResolverTest.php`).
 */
class AppFilterTest extends TestCase
{
    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        ServerResolver::forgetMemo();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
        $this->tmp = '';
    }

    // ==================================================================
    // name
    // ==================================================================

    public function testNameMatchesCaseInsensitiveSubstringAndTrimsInput(): void
    {
        $app = $this->app(['id' => 'a1', 'name' => 'Blog Budi', 'owner_id' => 'u1']);

        $this->assertTrue(AppFilter::matches($app, ['name' => 'blog']));
        $this->assertTrue(AppFilter::matches($app, ['name' => '  BLOG  ']));
        $this->assertTrue(AppFilter::matches($app, ['name' => 'budi']));
        $this->assertFalse(AppFilter::matches($app, ['name' => 'toko']));
        $this->assertFalse(AppFilter::matches($app, ['name' => 'g-bu'])); // bukan substring
    }

    public function testNameMatchingIsUnicodeSafe(): void
    {
        $app = $this->app(['id' => 'a1', 'name' => 'Café Rames', 'owner_id' => 'u1']);

        // `strtolower()` pada string non-ASCII tidak menurunkan 'É'/'É' dengan benar.
        $this->assertTrue(AppFilter::matches($app, ['name' => 'CAFÉ']));
        $this->assertTrue(AppFilter::matches($app, ['name' => 'café rames']));
        $this->assertFalse(AppFilter::matches($app, ['name' => 'cafe'])); // tanpa diakritik ≠ cocok
    }

    // ==================================================================
    // domain
    // ==================================================================

    public function testDomainMatchesEffectiveSubdomainNotName(): void
    {
        // Nama app ('toko') berbeda dari label subdomain ('warung-siti') —
        // pembuktian domain diambil dari `app_subdomain_of()`, bukan dari `name`.
        $app = $this->app([
            'id' => 'a1',
            'name' => 'toko',
            'subdomain' => 'warung-siti',
            'owner_id' => 'u1',
        ]);

        $this->assertSame('warung-siti.example.test', app_subdomain_of($app));

        $this->assertTrue(AppFilter::matches($app, ['domain' => 'warung-siti']));
        $this->assertTrue(AppFilter::matches($app, ['domain' => 'WARUNG']));
        $this->assertTrue(AppFilter::matches($app, ['domain' => 'example.test']));
        $this->assertFalse(AppFilter::matches($app, ['domain' => 'toko.example.test']));
        $this->assertFalse(AppFilter::matches($app, ['domain' => 'siti.example.org']));
    }

    public function testDomainWithoutSubdomainLabelFallsBackToNameThenDomain(): void
    {
        $app = $this->app(['id' => 'a1', 'name' => 'ramas', 'owner_id' => 'u1']);

        $this->assertSame('ramas.example.test', app_subdomain_of($app));
        $this->assertTrue(AppFilter::matches($app, ['domain' => 'ramas.']));
        $this->assertFalse(AppFilter::matches($app, ['domain' => 'ramas.example.org']));
    }

    public function testDomainAlsoMatchesCustomDomain(): void
    {
        $app = $this->app([
            'id' => 'a1',
            'name' => 'toko',
            'subdomain' => 'toko-a',
            'custom_domain' => 'Tokokita.test',
            'owner_id' => 'u1',
        ]);

        // Cocok lewat `custom_domain` walau subdomain efektif tidak memuatnya.
        $this->assertTrue(AppFilter::matches($app, ['domain' => 'tokokita']));
        $this->assertTrue(AppFilter::matches($app, ['domain' => 'TOKOKITA.TEST']));
        $this->assertTrue(AppFilter::matches($app, ['domain' => 'toko-a']));
        $this->assertFalse(AppFilter::matches($app, ['domain' => 'tokokita.org']));
    }

    public function testBlankCustomDomainDoesNotMatchEmptyNeedle(): void
    {
        $app = $this->app(['id' => 'a1', 'name' => 'toko', 'subdomain' => 'toko-a', 'owner_id' => 'u1']);

        // Filter kosong = tidak aktif ⇒ selalu lolos (bukan "cocok karena kosong").
        $this->assertTrue(AppFilter::matches($app, ['domain' => '   ']));
        $this->assertTrue(AppFilter::matches($app, []));
    }

    // ==================================================================
    // owner
    // ==================================================================

    public function testOwnerMatchesExactly(): void
    {
        $app = $this->app(['id' => 'a1', 'name' => 'blog', 'owner_id' => 'u1']);

        $this->assertTrue(AppFilter::matches($app, ['owner' => 'u1']));
        $this->assertTrue(AppFilter::matches($app, ['owner' => ' u1 ']));
        $this->assertFalse(AppFilter::matches($app, ['owner' => 'U1'])); // id = cocok persis
        $this->assertFalse(AppFilter::matches($app, ['owner' => 'u']));
        $this->assertFalse(AppFilter::matches($app, ['owner' => 'u12']));
    }

    public function testOwnerAbsentOnAppNeverMatchesActiveOwnerFilter(): void
    {
        $app = $this->app(['id' => 'a1', 'name' => 'blog']);

        $this->assertFalse(AppFilter::matches($app, ['owner' => 'u1']));
        // Filter tidak aktif ⇒ lolos (app tanpa owner tetap tampil di daftar).
        $this->assertTrue(AppFilter::matches($app, ['owner' => '']));
    }

    // ==================================================================
    // apply(): AND, urutan, reindex, tanpa filter
    // ==================================================================

    public function testApplyCombinesFiltersWithAnd(): void
    {
        $apps = [
            $this->app(['id' => 'a1', 'name' => 'blog-budi', 'subdomain' => 'blog-budi', 'owner_id' => 'u1']),
            $this->app(['id' => 'a2', 'name' => 'blog-siti', 'subdomain' => 'blog-siti', 'owner_id' => 'u2']),
            $this->app(['id' => 'a3', 'name' => 'toko-budi', 'subdomain' => 'toko-budi', 'owner_id' => 'u1']),
        ];

        $this->assertSame(
            ['a1'],
            array_column(AppFilter::apply($apps, ['name' => 'blog', 'owner' => 'u1']), 'id')
        );
        $this->assertSame(
            ['a1', 'a2'],
            array_column(AppFilter::apply($apps, ['name' => 'blog']), 'id')
        );
        $this->assertSame(
            ['a1', 'a3'],
            array_column(AppFilter::apply($apps, ['owner' => 'u1']), 'id')
        );
        $this->assertSame(
            ['a2'],
            array_column(AppFilter::apply($apps, ['name' => 'blog', 'domain' => 'siti']), 'id')
        );
        // Kombinasi yang tidak menyisakan apa pun ⇒ list kosong (bukan null).
        $this->assertSame([], AppFilter::apply($apps, ['name' => 'toko', 'owner' => 'u2']));
    }

    public function testApplyWithoutActiveFilterKeepsEverythingAndOrder(): void
    {
        $apps = [
            'kiri' => $this->app(['id' => 'a1', 'name' => 'zeta', 'owner_id' => 'u1']),
            'kanan' => $this->app(['id' => 'a2', 'name' => 'alfa', 'owner_id' => 'u2']),
        ];

        // Tanpa filter: isi & urutan tetap, hanya di-reindex jadi `list`.
        $this->assertSame(['a1', 'a2'], array_column(AppFilter::apply($apps, []), 'id'));
        $this->assertSame(
            ['a1', 'a2'],
            array_column(AppFilter::apply($apps, ['name' => '', 'domain' => '  ', 'owner' => '']), 'id')
        );
        $this->assertSame(array_values($apps), AppFilter::apply($apps, ['name' => '']));
    }

    public function testApplyPreservesInputOrderForAssociativeInput(): void
    {
        $apps = [
            7 => $this->app(['id' => 'a1', 'name' => 'blog-budi', 'owner_id' => 'u1']),
            3 => $this->app(['id' => 'a2', 'name' => 'blog-siti', 'owner_id' => 'u2']),
            5 => $this->app(['id' => 'a3', 'name' => 'blog-adi', 'owner_id' => 'u1']),
        ];

        $filtered = AppFilter::apply($apps, ['name' => 'blog']);

        $this->assertSame([0, 1, 2], array_keys($filtered));
        $this->assertSame(['a1', 'a2', 'a3'], array_column($filtered, 'id'));
    }

    // ==================================================================
    // normalize() / isActive() — masukan query string mentah
    // ==================================================================

    public function testNormalizeTrimsLowercasesAndRejectsNonScalar(): void
    {
        $this->assertSame(
            ['name' => 'blog', 'domain' => 'toko-siti.example.test', 'owner' => 'U1'],
            AppFilter::normalize([
                'name' => '  BLOG ',
                'domain' => ' Toko-Siti.Example.Test ',
                'owner' => ' U1 ',
            ])
        );

        // `?name[]=x` (array) / nilai non-skalar ⇒ dianggap kosong, bukan error.
        $this->assertSame(
            ['name' => '', 'domain' => '', 'owner' => ''],
            AppFilter::normalize(['name' => ['blog'], 'domain' => null, 'owner' => ['u1']])
        );
    }

    public function testIsActiveOnlyWhenSomeFilterIsNonEmpty(): void
    {
        $this->assertFalse(AppFilter::isActive([]));
        $this->assertFalse(AppFilter::isActive(['name' => '  ', 'domain' => '', 'owner' => '']));
        $this->assertTrue(AppFilter::isActive(['name' => 'blog']));
        $this->assertTrue(AppFilter::isActive(['domain' => 'example.test']));
        $this->assertTrue(AppFilter::isActive(['owner' => 'u1']));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function app(array $extra): array
    {
        return array_merge(['status' => 'running'], $extra);
    }

    /** Config temp: domain dasar tetap + basis data di temp (tanpa data nyata). */
    private function useConfig(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-appfilter-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'app_domain' => 'example.test',
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
