<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerResolver;
use app\library\Domain\AppDomains;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
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
 * Test {@see AppDomains} — pemetaan domain app ↔ slot SSL.
 *
 * Kelas yang diuji statik murni: tanpa store, tanpa otorisasi. `app_subdomain_of()`
 * membaca registry server lewat config, jadi config diarahkan ke direktori
 * temp (`sys_get_temp_dir()`) — `database/` nyata **tidak** disentuh.
 *
 * Yang dikunci:
 *  - sanitasi alias (normalisasi, duplikat/invalid dibuang, maks 20);
 *  - `allDomains()` = subdomain + domain utama + alias, lowercase & unik;
 *  - round-trip `slotOf()`/`domainOfSlot()`/`status()`/`writeStatus()`;
 *  - `writeStatus()` untuk alias yang sudah dihapus = no-op (tidak membuat entri);
 *  - `ownerOf()` lintas app + filter server;
 *  - `assertAliasAddable()` menolak duplikat/subdomain sendiri/utama sendiri/
 *    batas.
 */
class AppDomainsTest extends TestCase
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
    // Normalisasi & validasi
    // ==================================================================

    public function testNormalizeDomainLowercasesTrimsAndDropsTrailingDot(): void
    {
        $this->assertSame('alias.example.org', AppDomains::normalizeDomain('  Alias.Example.ORG. '));
        $this->assertSame('', AppDomains::normalizeDomain('   '));
    }

    public function testIsValidFqdnRequiresPublicDottedDomain(): void
    {
        $this->assertTrue(AppDomains::isValidFqdn('Alias.Example.ORG'));
        $this->assertFalse(AppDomains::isValidFqdn('alias'), 'harus FQDN ber-titik');
        $this->assertFalse(AppDomains::isValidFqdn('alias.local'), 'TLD non-publik ditolak');
        $this->assertFalse(AppDomains::isValidFqdn(''));
    }

    // ==================================================================
    // aliases()
    // ==================================================================

    public function testAliasesSanitizeDuplicatesAndInvalidEntries(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domains' => [
                ['domain' => 'A.example.org', 'ssl_status' => 'active'],
                ['domain' => 'a.example.org', 'ssl_status' => 'disabled'],
                ['domain' => ' not-a-fqdn '],
                ['domain' => 123],
                'bukan-array',
                ['ssl_status' => 'active'],
                ['domain' => 'b.example.org'],
            ],
        ];

        $aliases = AppDomains::aliases($app);

        $this->assertSame(['a.example.org', 'b.example.org'], array_column($aliases, 'domain'));
        $this->assertSame('active', $aliases[0]['ssl_status'], 'entri pertama (setelah normalisasi) yang menang');
    }

    public function testAliasesCoerceNonScalarStatusFields(): void
    {
        $aliases = AppDomains::aliases([
            'name' => 'toko',
            'custom_domains' => [[
                'domain' => 'a.example.org',
                'ssl_status' => ['rusak'],
                'ssl_stage' => ['rusak'],
                'ssl_error' => ['rusak'],
                'ssl_expires_at' => ['rusak'],
                'ssl_message' => 'ok',
            ]],
        ]);

        $this->assertSame('disabled', $aliases[0]['ssl_status']);
        $this->assertNull($aliases[0]['ssl_stage']);
        $this->assertNull($aliases[0]['ssl_error']);
        $this->assertNull($aliases[0]['ssl_expires_at']);
        $this->assertSame('ok', $aliases[0]['ssl_message'], 'skalar tetap dipertahankan');

        // Jalur status() juga tidak boleh memuntahkan warning cast untuk entri
        // mentah yang tidak lewat aliases().
        $status = AppDomains::status(['name' => 'toko', 'custom_domains' => [
            ['domain' => 'a.example.org', 'ssl_status' => ['rusak']],
        ]], 'alias:a.example.org');
        $this->assertSame('disabled', $status['ssl_status']);
    }

    public function testHasAliasNormalizesDomain(): void
    {
        $app = ['name' => 'toko', 'custom_domains' => [['domain' => 'a.example.org']]];

        $this->assertTrue(AppDomains::hasAlias($app, 'A.Example.ORG.'));
        $this->assertFalse(AppDomains::hasAlias($app, 'b.example.org'));
        $this->assertFalse(AppDomains::hasAlias($app, ''));
    }

    public function testAliasesAreCappedAtTwenty(): void
    {
        $entries = [];
        for ($i = 1; $i <= 25; $i++) {
            $entries[] = ['domain' => 'alias' . $i . '.example.org'];
        }

        $aliases = AppDomains::aliases(['name' => 'toko', 'custom_domains' => $entries]);

        $this->assertCount(AppDomains::MAX_ALIASES, $aliases);
        $this->assertSame('alias1.example.org', $aliases[0]['domain']);
        $this->assertSame('alias20.example.org', $aliases[19]['domain']);
    }

    public function testAliasesOfMissingOrBrokenFieldIsEmpty(): void
    {
        $this->assertSame([], AppDomains::aliases(['name' => 'toko']));
        $this->assertSame([], AppDomains::aliases(['name' => 'toko', 'custom_domains' => 'bukan-array']));
    }

    // ==================================================================
    // allDomains()
    // ==================================================================

    public function testAllDomainsIsSubdomainThenPrimaryThenAliases(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domain' => 'Toko.Example.NET',
            'custom_domains' => [
                ['domain' => 'alias-one.example.org'],
                ['domain' => 'alias-two.example.org'],
            ],
        ];

        $this->assertSame([
            'toko.example.org',
            'toko.example.net',
            'alias-one.example.org',
            'alias-two.example.org',
        ], AppDomains::allDomains($app));
    }

    public function testAllDomainsIsUniqueAndLowercase(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domain' => 'toko.example.org',
            'custom_domains' => [['domain' => 'TOKO.example.org']],
        ];

        $this->assertSame(['toko.example.org'], AppDomains::allDomains($app));
    }

    // ==================================================================
    // slotOf / domainOfSlot / status / writeStatus
    // ==================================================================

    public function testSlotOfMapsSubdomainPrimaryAndAlias(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domain' => 'toko.example.net',
            'custom_domains' => [['domain' => 'multi.example.org']],
        ];

        $this->assertSame('ssl', AppDomains::slotOf($app, 'toko.example.org'));
        $this->assertSame('custom_ssl', AppDomains::slotOf($app, 'TOKO.example.net.'));
        $this->assertSame('alias:multi.example.org', AppDomains::slotOf($app, 'Multi.Example.ORG'));
        $this->assertNull(AppDomains::slotOf($app, 'bukan-milik.example.org'));
        $this->assertNull(AppDomains::slotOf($app, ''));
    }

    public function testDomainOfSlotRoundTrips(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domain' => 'toko.example.net',
            'custom_domains' => [['domain' => 'multi.example.org']],
        ];

        $this->assertSame('toko.example.org', AppDomains::domainOfSlot($app, 'ssl'));
        $this->assertSame('toko.example.net', AppDomains::domainOfSlot($app, 'custom_ssl'));
        $this->assertSame('multi.example.org', AppDomains::domainOfSlot($app, 'alias:multi.example.org'));
        $this->assertNull(AppDomains::domainOfSlot($app, 'alias:gone.example.org'), 'alias yang tidak terdaftar = null');
        $this->assertNull(AppDomains::domainOfSlot($app, 'slot-aneh'));
        $this->assertNull(AppDomains::domainOfSlot(['name' => 'x'], 'custom_ssl'), 'tanpa domain utama = null');
    }

    public function testIsAliasSlotDetectsPrefixOnly(): void
    {
        $this->assertTrue(AppDomains::isAliasSlot('alias:a.example.org'));
        $this->assertFalse(AppDomains::isAliasSlot('alias:'));
        $this->assertFalse(AppDomains::isAliasSlot('ssl'));
        $this->assertFalse(AppDomains::isAliasSlot('custom_ssl'));
    }

    public function testStatusReadsFlatFieldsForOldSlotsAndEntryForAlias(): void
    {
        $app = [
            'name' => 'toko',
            'ssl_status' => 'active',
            'ssl_error' => null,
            'custom_domain' => 'toko.example.net',
            'custom_ssl_status' => 'failed',
            'custom_ssl_error' => 'gagal',
            'custom_domains' => [[
                'domain' => 'multi.example.org',
                'ssl_status' => 'pending',
                'ssl_stage' => 'issue',
                'ssl_message' => 'Memproses ...',
                'ssl_error' => null,
                'ssl_expires_at' => null,
            ]],
        ];

        $this->assertSame('active', AppDomains::status($app, 'ssl')['ssl_status']);
        $this->assertSame('failed', AppDomains::status($app, 'custom_ssl')['ssl_status']);
        $this->assertSame('gagal', AppDomains::status($app, 'custom_ssl')['ssl_error']);

        $alias = AppDomains::status($app, 'alias:multi.example.org');
        $this->assertSame('pending', $alias['ssl_status']);
        $this->assertSame('issue', $alias['ssl_stage']);
        $this->assertSame('Memproses ...', $alias['ssl_message']);

        $gone = AppDomains::status($app, 'alias:gone.example.org');
        $this->assertSame('disabled', $gone['ssl_status']);
        $this->assertNull($gone['ssl_error']);
    }

    public function testWriteStatusRoundTripsEverySlot(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domain' => 'toko.example.net',
            'custom_domains' => [['domain' => 'multi.example.org', 'ssl_status' => 'disabled']],
        ];

        AppDomains::writeStatus($app, 'ssl', ['ssl_status' => 'active', 'ssl_expires_at' => '2027-01-01T00:00:00+00:00']);
        AppDomains::writeStatus($app, 'custom_ssl', ['ssl_status' => 'failed', 'ssl_error' => 'boom']);
        AppDomains::writeStatus($app, 'alias:multi.example.org', [
            'ssl_status' => 'active',
            'ssl_stage' => null,
            'ssl_message' => 'Aktif',
            'ssl_error' => null,
            'ssl_expires_at' => '2027-02-01T00:00:00+00:00',
        ]);

        $this->assertSame('active', $app['ssl_status']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $app['ssl_expires_at']);
        $this->assertSame('failed', $app['custom_ssl_status']);
        $this->assertSame('boom', $app['custom_ssl_error']);

        $this->assertSame('active', $app['custom_domains'][0]['ssl_status']);
        $this->assertSame('Aktif', $app['custom_domains'][0]['ssl_message']);
        $this->assertSame('2027-02-01T00:00:00+00:00', $app['custom_domains'][0]['ssl_expires_at']);

        // Round-trip lewat pembaca status.
        $this->assertSame('active', AppDomains::status($app, 'ssl')['ssl_status']);
        $this->assertSame('failed', AppDomains::status($app, 'custom_ssl')['ssl_status']);
        $this->assertSame('active', AppDomains::status($app, 'alias:multi.example.org')['ssl_status']);
    }

    public function testWriteStatusIgnoresMissingAliasEntry(): void
    {
        $app = [
            'name' => 'toko',
            'custom_domains' => [['domain' => 'multi.example.org', 'ssl_status' => 'disabled']],
        ];

        AppDomains::writeStatus($app, 'alias:gone.example.org', ['ssl_status' => 'active']);

        $this->assertCount(1, $app['custom_domains'], 'tidak membuat entri baru');
        $this->assertSame('disabled', $app['custom_domains'][0]['ssl_status']);
        $this->assertArrayNotHasKey('alias:gone.example.org_status', $app);
    }

    // ==================================================================
    // ownerOf()
    // ==================================================================

    public function testOwnerOfFindsEveryKindOfUsage(): void
    {
        $apps = [
            ['id' => 'a1', 'name' => 'alpha', 'subdomain' => 'alpha-svc'],
            ['id' => 'a2', 'name' => 'beta', 'custom_domain' => 'beta.example.net'],
            ['id' => 'a3', 'name' => 'gamma', 'custom_domains' => [['domain' => 'multi.example.org']]],
        ];

        $this->assertSame(['app_name' => 'alpha', 'kind' => 'subdomain'], AppDomains::ownerOf($apps, 'alpha-svc.example.org'));
        $this->assertSame(['app_name' => 'beta', 'kind' => 'primary'], AppDomains::ownerOf($apps, 'beta.example.net'));
        $this->assertSame(['app_name' => 'gamma', 'kind' => 'alias'], AppDomains::ownerOf($apps, 'MULTI.example.org'));
        $this->assertNull(AppDomains::ownerOf($apps, 'free.example.org'));
    }

    public function testOwnerOfHonoursExcludeId(): void
    {
        $apps = [['id' => 'a1', 'name' => 'alpha', 'custom_domains' => [['domain' => 'multi.example.org']]]];

        $this->assertNull(AppDomains::ownerOf($apps, 'multi.example.org', 'a1'));
        $this->assertSame('alias', AppDomains::ownerOf($apps, 'multi.example.org')['kind']);
    }

    public function testOwnerOfFiltersByServer(): void
    {
        $apps = [
            ['id' => 'a1', 'name' => 'local-app', 'server_id' => 'local', 'custom_domains' => [['domain' => 'multi.example.org']]],
            ['id' => 'a2', 'name' => 'remote-app', 'server_id' => 'srv2', 'custom_domains' => [['domain' => 'multi2.example.org']]],
        ];

        $this->assertSame('local-app', AppDomains::ownerOf($apps, 'multi.example.org', '', 'local')['app_name']);
        $this->assertNull(AppDomains::ownerOf($apps, 'multi2.example.org', '', 'local'), 'server lain tidak saling berebut');
        $this->assertSame('remote-app', AppDomains::ownerOf($apps, 'multi2.example.org', '', 'srv2')['app_name']);
        // Tanpa filter server: semua server (perilaku lama).
        $this->assertSame('remote-app', AppDomains::ownerOf($apps, 'multi2.example.org')['app_name']);
    }

    // ==================================================================
    // assertAliasAddable()
    // ==================================================================

    public function testAssertAliasAddableAcceptsFreeDomain(): void
    {
        AppDomains::assertAliasAddable(
            ['name' => 'toko', 'custom_domains' => [['domain' => 'a.example.org']]],
            'Baru.Example.ORG.'
        );

        $this->assertTrue(true, 'tidak melempar');
    }

    public function testAssertAliasAddableRejectsInvalidFqdn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/FQDN publik yang valid/');

        AppDomains::assertAliasAddable(['name' => 'toko'], 'bukan-fqdn');
    }

    public function testAssertAliasAddableRejectsDuplicate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah terdaftar sebagai domain tambahan/');

        AppDomains::assertAliasAddable(
            ['name' => 'toko', 'custom_domains' => [['domain' => 'multi.example.org']]],
            'MULTI.example.org'
        );
    }

    public function testAssertAliasAddableRejectsOwnSubdomain(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak boleh sama dengan subdomain bawaan app/');

        AppDomains::assertAliasAddable(['name' => 'toko'], 'toko.example.org');
    }

    public function testAssertAliasAddableRejectsOwnPrimaryDomain(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak boleh sama dengan custom domain utama app/');

        AppDomains::assertAliasAddable(['name' => 'toko', 'custom_domain' => 'toko.example.net'], 'toko.example.net');
    }

    public function testAssertAliasAddableRejectsWhenLimitReached(): void
    {
        $entries = [];
        for ($i = 1; $i <= AppDomains::MAX_ALIASES; $i++) {
            $entries[] = ['domain' => 'alias' . $i . '.example.org'];
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Maksimal 20 domain tambahan/');

        AppDomains::assertAliasAddable(['name' => 'toko', 'custom_domains' => $entries], 'satu-lagi.example.org');
    }

    // ==================================================================
    // Config temp (registry server dibaca `app_subdomain_of()`)
    // ==================================================================

    private function useConfig(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-appdomains-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'app_domain' => 'example.org',
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
