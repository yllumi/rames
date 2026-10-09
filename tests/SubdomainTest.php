<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvManager;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\SubdomainManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxConfigGuard;
use app\library\Nginx\NginxReloader;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;
use RuntimeException;

// Helper webman (`base_path()`, `config()`, `app_subdomain*()`) tidak dimuat di
// luar runtime webman: definisikan konstanta + fungsi yang dibutuhkan.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Deployer fake yang merekam setiap writeNginxConfig() (isi app) dan bisa
 * dipaksa melempar pada pemanggilan ke-N. Tidak menyentuh Docker.
 */
class SubdomainRecordingDeployer extends FakeCliDeployer
{
    /** @var array<int,array<string,mixed>> */
    public array $writes = [];

    public ?int $failOnCall = null;

    public function writeNginxConfig(array $app): void
    {
        $this->writes[] = $app;
        if ($this->failOnCall !== null && count($this->writes) === $this->failOnCall) {
            throw new RuntimeException('tulis config gagal (test)');
        }
    }
}

/**
 * Reloader fake (override reload()) — hasil bisa diatur, tanpa menyentuh Docker.
 */
class SubdomainFakeReloader extends NginxReloader
{
    public int $calls = 0;

    /** @var array<string,mixed> */
    private array $result;

    /**
     * @param array<string,mixed> $result
     */
    public function __construct(array $result = ['ok' => true, 'error' => null])
    {
        // Sengaja tidak memanggil parent::__construct — reload() di-override dan
        // tidak memakai state induk (ProcessRunner/config).
        $this->result = $result;
    }

    public function reload(): array
    {
        $this->calls++;
        return $this->result;
    }
}

/**
 * Test fitur "pisahkan nama app dari subdomain": helper resolusi, validasi &
 * keunikan, render config Nginx, dan alur ubah subdomain (sukses/rollback).
 *
 * Semua test memakai apps.json temp — TIDAK menyentuh database/*.json nyata.
 */
class SubdomainTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-subdomain-apps-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    /**
     * @param array<string,mixed> $app
     */
    private function seed(array $app): array
    {
        $app += ['name' => 'app'];
        return (new AppStore($this->file))->create($app);
    }

    private function store(): AppStore
    {
        return new AppStore($this->file);
    }

    /**
     * App dengan host port terpublish (syarat subdomain/vhost berlaku).
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function appWithPort(string $name, array $extra = []): array
    {
        return $extra + [
            'name' => $name,
            'containers' => [
                ['service_name' => 'web', 'container_name' => $name, 'ports' => [['host' => 30000, 'container' => 80]]],
            ],
        ];
    }

    private function guard(AppStore $store, SubdomainRecordingDeployer $deployer, SubdomainFakeReloader $reloader): NginxConfigGuard
    {
        return new NginxConfigGuard($reloader, $deployer, $store);
    }

    // ==================================================================
    // Helper resolusi
    // ==================================================================

    public function testHelperFallsBackToNameWhenFieldAbsent(): void
    {
        $this->assertSame('myblog.example.com', app_subdomain_of(['name' => 'myblog']));
        $this->assertSame('myblog.example.com', app_subdomain_of(['name' => 'myblog', 'subdomain' => '']));
        $this->assertSame('myblog.example.com', app_subdomain_of(['name' => 'myblog', 'subdomain' => null]));
    }

    public function testHelperUsesLabelField(): void
    {
        $this->assertSame('blog-x7k2.example.com', app_subdomain_of(['name' => 'myblog', 'subdomain' => 'blog-x7k2']));
    }

    public function testHelperHonorsLegacyFqdnValue(): void
    {
        // Data lama menyimpan FQDN penuh — dipakai apa adanya (tanpa migrasi).
        $this->assertSame(
            'blog.example.org',
            app_subdomain_of(['name' => 'myblog', 'subdomain' => 'blog.example.org'])
        );
    }

    public function testLabelAndCustomHelpers(): void
    {
        $this->assertSame('myblog', app_subdomain_label(['name' => 'myblog']));
        $this->assertFalse(app_subdomain_is_custom(['name' => 'myblog']));

        $this->assertSame('blog-x7k2', app_subdomain_label(['name' => 'myblog', 'subdomain' => 'blog-x7k2']));
        $this->assertTrue(app_subdomain_is_custom(['name' => 'myblog', 'subdomain' => 'blog-x7k2']));

        // Legacy FQDN = default app → tetap dianggap fallback (bukan custom).
        $this->assertSame('myblog', app_subdomain_label(['name' => 'myblog', 'subdomain' => 'myblog.example.com']));
        $this->assertFalse(app_subdomain_is_custom(['name' => 'myblog', 'subdomain' => 'myblog.example.com']));

        // Legacy FQDN berbeda dari default → custom, label di-strip APP_DOMAIN.
        $this->assertSame('lain', app_subdomain_label(['name' => 'myblog', 'subdomain' => 'lain.example.com']));
        $this->assertTrue(app_subdomain_is_custom(['name' => 'myblog', 'subdomain' => 'lain.example.com']));
    }

    // ==================================================================
    // Validasi format label
    // ==================================================================

    public function testLabelValidationAcceptsValidSlugs(): void
    {
        $this->assertTrue(app_subdomain_valid('a'));
        $this->assertTrue(app_subdomain_valid('blog-x7k2'));
        $this->assertTrue(app_subdomain_valid('app2'));
        $this->assertTrue(app_subdomain_valid(str_repeat('a', 63)));
    }

    public function testLabelValidationRejectsInvalidSlugs(): void
    {
        $this->assertFalse(app_subdomain_valid(''));            // kosong (bukan label)
        $this->assertFalse(app_subdomain_valid('-awal'));       // diawali strip
        $this->assertFalse(app_subdomain_valid('akhir-'));      // diakhiri strip
        $this->assertFalse(app_subdomain_valid('a_'));          // underscore
        $this->assertFalse(app_subdomain_valid('a b'));         // spasi
        $this->assertFalse(app_subdomain_valid('Blog'));        // huruf besar (harus lowercase)
        $this->assertFalse(app_subdomain_valid('a.b'));         // titik (itu FQDN, bukan label)
        $this->assertFalse(app_subdomain_valid(str_repeat('a', 64))); // > 63
    }

    public function testNormalizeLowercasesAndTrims(): void
    {
        $this->assertSame('blog-x7k2', SubdomainManager::normalize('  Blog-X7K2  '));
    }

    // ==================================================================
    // Keunikan atas subdomain efektif
    // ==================================================================

    public function testAssertAvailableReturnsEffectiveFallback(): void
    {
        $manager = new SubdomainManager($this->store());
        $this->assertSame('fresh.example.com', $manager->assertAvailable('', 'fresh'));
    }

    public function testAssertAvailableRejectsConflictWithOtherLabel(): void
    {
        $this->seed(['name' => 'alpha', 'subdomain' => 'blog-x7k2']);
        $manager = new SubdomainManager($this->store());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/blog-x7k2\.example\.com sudah dipakai/');
        $manager->assertAvailable('blog-x7k2', 'beta');
    }

    public function testAssertAvailableRejectsConflictWithOtherName(): void
    {
        // App lama tanpa field `subdomain` memakai `name` sebagai subdomain efektif.
        $this->seed(['name' => 'alpha']);
        $manager = new SubdomainManager($this->store());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/alpha\.example\.com sudah dipakai/');
        $manager->assertAvailable('alpha', 'beta');
    }

    public function testAssertAvailableRejectsConflictWithCustomDomain(): void
    {
        $this->seed(['name' => 'alpha', 'custom_domain' => 'blog.example.com']);
        $manager = new SubdomainManager($this->store());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bentrok dengan custom domain/');
        $manager->assertAvailable('blog', 'beta');
    }

    public function testAssertAvailableExcludesOwnApp(): void
    {
        $app = $this->seed(['name' => 'alpha']);
        $manager = new SubdomainManager($this->store());

        $this->assertSame('alpha.example.com', $manager->assertAvailable('alpha', 'alpha', $app['id']));
    }

    // ==================================================================
    // Render config Nginx (server_name efektif)
    // ==================================================================

    private function renderer(): LocalDeployer
    {
        return new LocalDeployer(
            new DockerComposeRunner(),
            new DockerClient(sys_get_temp_dir() . '/rames-no.sock'),
            new NginxConfigGenerator(sys_get_temp_dir(), ''),
            sys_get_temp_dir(),
            new EnvManager(sys_get_temp_dir() . '/rames-sub-env-' . bin2hex(random_bytes(4))),
            new NetworkManager()
        );
    }

    public function testRenderNginxConfigFallsBackToName(): void
    {
        $config = $this->renderer()->renderNginxConfig($this->appWithPort('myblog'));

        $this->assertStringContainsString('server_name myblog.example.com;', $config);
    }

    public function testRenderNginxConfigUsesStoredSubdomainLabel(): void
    {
        $app = $this->appWithPort('myblog', ['subdomain' => 'blog-x7k2']);
        $config = $this->renderer()->renderNginxConfig($app);

        $this->assertStringContainsString('server_name blog-x7k2.example.com;', $config);
        $this->assertStringNotContainsString('server_name myblog.example.com;', $config);
    }

    public function testRenderNginxConfigHonorsLegacyFqdn(): void
    {
        $app = $this->appWithPort('myblog', ['subdomain' => 'blog.example.org']);
        $config = $this->renderer()->renderNginxConfig($app);

        $this->assertStringContainsString('server_name blog.example.org;', $config);
    }

    // ==================================================================
    // Alur ubah subdomain (sukses / no-op / rollback)
    // ==================================================================

    public function testChangePersistsSubdomainAndRewritesConfig(): void
    {
        $store = $this->store();
        $app = $this->seed($this->appWithPort('alpha'));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader(['ok' => true, 'error' => null]);
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($app['id'], 'blog-x7k2');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['noop']);
        $this->assertSame('blog-x7k2.example.com', $result['effective']);
        $this->assertSame('alpha.example.com', $result['previous']);
        $this->assertTrue($result['reloaded']);

        $saved = $store->find($app['id']);
        $this->assertSame('blog-x7k2', $saved['subdomain']);
        $this->assertCount(1, $deployer->writes);
        $this->assertSame('blog-x7k2', $deployer->writes[0]['subdomain']);
        $this->assertSame(1, $reloader->calls);
    }

    public function testChangeIsNoopWhenEffectiveUnchanged(): void
    {
        $store = $this->store();
        $app = $this->seed($this->appWithPort('alpha', ['subdomain' => 'blog-x7k2']));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader(['ok' => true, 'error' => null]);
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($app['id'], 'Blog-X7K2'); // dinormalisasi → sama

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['noop']);
        $this->assertSame('blog-x7k2.example.com', $result['effective']);
        $this->assertCount(0, $deployer->writes);
        $this->assertSame(0, $reloader->calls);
        $this->assertSame('blog-x7k2', $store->find($app['id'])['subdomain']);
    }

    public function testChangeClearsFieldToFallback(): void
    {
        $store = $this->store();
        $app = $this->seed($this->appWithPort('alpha', ['subdomain' => 'blog-x7k2']));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader(['ok' => true, 'error' => null]);
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($app['id'], '');

        $this->assertTrue($result['ok']);
        $this->assertSame('alpha.example.com', $result['effective']);
        $saved = $store->find($app['id']);
        $this->assertArrayNotHasKey('subdomain', $saved);
        $this->assertArrayNotHasKey('subdomain', $deployer->writes[0]);
    }

    public function testChangeRollsBackSubdomainAndSslWhenNginxTestFails(): void
    {
        $store = $this->store();
        $app = $this->seed($this->appWithPort('alpha', [
            'subdomain' => 'oldlabel',
            'ssl_status' => 'active',
            'needs_ssl' => true,
        ]));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader(['ok' => false, 'error' => 'nginx: [emerg] host not found']);
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($app['id'], 'newlabel');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);
        $this->assertSame('oldlabel.example.com', $result['previous']);
        $this->assertStringContainsString('nginx: [emerg] host not found', $result['error']);

        // Field dipulihkan (termasuk status SSL).
        $saved = $store->find($app['id']);
        $this->assertSame('oldlabel', $saved['subdomain']);
        $this->assertSame('active', $saved['ssl_status']);
        $this->assertTrue($saved['needs_ssl']);

        // Config baru ditulis, lalu config lama ditulis ulang saat rollback.
        $this->assertCount(2, $deployer->writes);
        $this->assertSame('newlabel', $deployer->writes[0]['subdomain']);
        $this->assertSame('oldlabel', $deployer->writes[1]['subdomain']);
    }

    public function testChangeResetsSslStateOnSuccess(): void
    {
        $store = $this->store();
        $app = $this->seed($this->appWithPort('alpha', [
            'ssl_status' => 'active',
            'ssl_message' => 'aktif',
            'needs_ssl' => true,
        ]));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader(['ok' => true, 'error' => null]);
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $manager->change($app['id'], 'blog');

        $saved = $store->find($app['id']);
        $this->assertSame('disabled', $saved['ssl_status']);
        $this->assertFalse($saved['needs_ssl']);
        $this->assertSame('blog', $deployer->writes[0]['subdomain']);
    }

    public function testChangeRejectsAppWithoutHostPort(): void
    {
        $store = $this->store();
        $app = $this->seed(['name' => 'alpha', 'containers' => []]);

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader();
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($app['id'], 'blog-x7k2');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('port host', $result['error']);
        $this->assertCount(0, $deployer->writes);
        $this->assertSame(0, $reloader->calls);
    }

    public function testChangeRejectsInvalidLabel(): void
    {
        $store = $this->store();
        $app = $this->seed($this->appWithPort('alpha'));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader();
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($app['id'], '-bad');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Subdomain hanya boleh', $result['error']);
        $this->assertCount(0, $deployer->writes);
    }

    public function testChangeRejectsConflictWithOtherApp(): void
    {
        $store = $this->store();
        $this->seed(['name' => 'alpha', 'subdomain' => 'blog-x7k2']);
        $beta = $this->seed($this->appWithPort('beta'));

        $deployer = new SubdomainRecordingDeployer();
        $reloader = new SubdomainFakeReloader();
        $manager = new SubdomainManager($store, $this->guard($store, $deployer, $reloader));

        $result = $manager->change($beta['id'], 'blog-x7k2');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sudah dipakai', $result['error']);
        $this->assertCount(0, $deployer->writes);
    }
}
