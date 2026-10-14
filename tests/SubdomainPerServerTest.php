<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Deploy\ServerResolver;
use app\library\Deploy\SubdomainManager;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

// Helper webman (`config()`, `app_subdomain*()`) tidak dimuat di luar runtime
// webman: pastikan `app/functions.php` ikut dimuat (pola `SubdomainTest`).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Perakitan FQDN per server (misi `ms-f1-domain`, plan §4.2, §9 keputusan 4).
 *
 * Yang dikunci:
 *  - FQDN app memakai `servers.domain` **server pemilik app** (`{label}.{domain}`);
 *  - app di server lokal tetap persis seperti sebelum perubahan (APP_DOMAIN),
 *    dan `app_subdomain()` tanpa argumen = domain server lokal;
 *  - server ber-`domain` kosong ⇒ fallback `config('deploy.app_domain')`;
 *  - label yang sama di dua server berbeda **tidak** dianggap bentrok;
 *  - `app_subdomain_valid()`/`app_subdomain_is_custom()`/`app_subdomain_label()`
 *    tetap benar untuk app di server non-lokal;
 *  - memo domain tidak menyajikan data basi saat `servers.domain` berubah.
 *
 * Semua uji memakai SQLite temp (`sys_get_temp_dir()`) — tidak menyentuh
 * `database/rames.sqlite`, `apps/`, atau `nginx-status/`.
 */
class SubdomainPerServerTest extends TestCase
{
    private string $appsFile;
    private string $serversFile;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $suffix = bin2hex(random_bytes(4));
        $this->appsFile = sys_get_temp_dir() . '/rames-perserver-apps-' . $suffix . '.sqlite';
        $this->serversFile = sys_get_temp_dir() . '/rames-perserver-servers-' . $suffix . '.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        StoreDbFiles::remove($this->appsFile);
        StoreDbFiles::remove($this->serversFile);
    }

    private function resolver(): ServerResolver
    {
        return new ServerResolver(new ServerStore($this->serversFile));
    }

    private function store(): AppStore
    {
        return new AppStore($this->appsFile);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function seed(array $extra): array
    {
        return $this->store()->create($extra + ['name' => 'app']);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function server(string $domain, array $extra = []): array
    {
        return (new ServerStore($this->serversFile))->create($extra + [
            'name' => 'vps-' . bin2hex(random_bytes(2)),
            'kind' => 'agent',
            'domain' => $domain,
        ]);
    }

    // ==================================================================
    // FQDN per server
    // ==================================================================

    public function testFqdnUsesOwnerServerDomain(): void
    {
        $satu = $this->server('a.example.com');
        $dua = $this->server('b.example.com');
        $resolver = $this->resolver();

        $this->assertSame(
            'myblog.a.example.com',
            app_subdomain_of(['name' => 'myblog', 'server_id' => $satu['id']], $resolver)
        );
        $this->assertSame(
            'blog.b.example.com',
            app_subdomain_of(['name' => 'myblog', 'subdomain' => 'blog', 'server_id' => $dua['id']], $resolver)
        );
        // Bentuk array pada `app_subdomain()` = FQDN efektif app (kontrak misi).
        $this->assertSame(
            'blog.b.example.com',
            app_subdomain(['name' => 'myblog', 'subdomain' => 'blog', 'server_id' => $dua['id']], $resolver)
        );
    }

    public function testLocalServerBehaviourIsUnchanged(): void
    {
        $resolver = $this->resolver();

        // Tanpa `server_id` (app lama) = server lokal ⇒ APP_DOMAIN seperti dulu.
        $this->assertSame('myblog.example.com', app_subdomain_of(['name' => 'myblog'], $resolver));
        $this->assertSame('blog.example.com', app_subdomain_of(['name' => 'myblog', 'subdomain' => 'blog'], $resolver));
        $this->assertSame('myblog.example.com', app_subdomain('myblog', $resolver));
        // Tanpa argumen = domain server lokal (dipakai UI dashboard/global).
        $this->assertSame(ServerResolver::configDomain(), app_subdomain(null, $resolver));
        $this->assertSame(ServerResolver::configDomain(), app_subdomain(null, $resolver));
        // FQDN lama (data lama) dihormati apa adanya.
        $this->assertSame('blog.example.org', app_subdomain_of(['name' => 'myblog', 'subdomain' => 'blog.example.org'], $resolver));
    }

    public function testServerWithoutDomainFallsBackToConfig(): void
    {
        $polos = $this->server('');
        $resolver = $this->resolver();

        $this->assertSame(
            'blog.' . ServerResolver::configDomain(),
            app_subdomain_of(['name' => 'myblog', 'subdomain' => 'blog', 'server_id' => $polos['id']], $resolver)
        );
    }

    // ==================================================================
    // Keunikan & label per server
    // ==================================================================

    public function testSameLabelOnDifferentServersIsNotAConflict(): void
    {
        $satu = $this->server('a.example.com');
        $dua = $this->server('b.example.com');
        $this->seed(['name' => 'alpha', 'subdomain' => 'blog', 'server_id' => $satu['id']]);
        $manager = new SubdomainManager($this->store(), null, $this->resolver());

        // Server lain: FQDN-nya di domain lain, jadi label `blog` masih bebas.
        $this->assertSame('blog.b.example.com', $manager->assertAvailable('blog', 'beta', '', $dua['id']));
        // Tanpa scope server: app lokal pun masih boleh memakai label `blog`.
        $this->assertSame('blog.example.com', $manager->assertAvailable('blog', 'beta'));

        // Server yang sama: bentrok (FQDN identik).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/blog\.a\.example\.com sudah dipakai/');
        $manager->assertAvailable('blog', 'beta', '', $satu['id']);
    }

    /**
     * Keunikan **custom domain** di `AppController::assertDomainUnique()`
     * di-scope per server (misi `ms-f1-deploy`, plan §4.2): dua server boleh
     * memakai domain custom yang sama; bentrok hanya di server yang sama.
     *
     * Method privat diuji langsung (reflection) — jalurnya murni (store + helper
     * FQDN), tanpa HTTP/Docker.
     */
    public function testControllerCustomDomainUniquenessIsScopedPerServer(): void
    {
        $satu = $this->server('a.example.com');
        $this->seed(['id' => 'app-local', 'name' => 'lokal', 'custom_domain' => 'shared.example.org']);
        $this->seed(['id' => 'app-a', 'name' => 'alpha', 'server_id' => $satu['id'], 'custom_domain' => 'shared.example.org']);
        $this->seed(['id' => 'app-a2', 'name' => 'alpha2', 'server_id' => $satu['id']]);

        $assert = new \ReflectionMethod(AppController::class, 'assertDomainUnique');
        $controller = new AppController();

        // Server berbeda: domain custom yang sama BUKAN bentrok.
        $assert->invoke($controller, $this->store(), 'app-a', 'shared.example.org');

        // Server yang sama: bentrok ⇒ melempar.
        try {
            $assert->invoke($controller, $this->store(), 'app-a2', 'shared.example.org');
            $this->fail('domain custom yang sama di server yang sama harus dianggap bentrok');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dipakai', $e->getMessage());
        }
    }

    public function testLabelAndCustomHelpersUseServerDomain(): void
    {
        $satu = $this->server('a.example.com');
        $dua = $this->server('b.example.com');
        $resolver = $this->resolver();

        // Label di-strip memakai domain server app (bukan APP_DOMAIN global).
        $this->assertSame(
            'blog',
            app_subdomain_label(['name' => 'blog', 'subdomain' => 'blog.b.example.com', 'server_id' => $dua['id']], $resolver)
        );
        $this->assertFalse(
            app_subdomain_is_custom(['name' => 'blog', 'subdomain' => 'blog.b.example.com', 'server_id' => $dua['id']], $resolver),
            'FQDN bawaan app di server itu bukan subdomain custom'
        );
        $this->assertTrue(
            app_subdomain_is_custom(['name' => 'myblog', 'subdomain' => 'blog', 'server_id' => $satu['id']], $resolver)
        );
        $this->assertFalse(
            app_subdomain_is_custom(['name' => 'myblog', 'subdomain' => '', 'server_id' => $satu['id']], $resolver)
        );

        // Validasi label identik untuk semua server (bentuk label, bukan domain).
        $this->assertTrue(app_subdomain_valid('blog', $resolver));
        $this->assertTrue(app_subdomain_valid(['name' => 'myblog', 'subdomain' => 'blog', 'server_id' => $dua['id']], $resolver));
        $this->assertTrue(
            app_subdomain_valid(['name' => 'myblog', 'subdomain' => 'blog.b.example.com', 'server_id' => $dua['id']], $resolver)
        );
        $this->assertFalse(app_subdomain_valid('-blog', $resolver));
        $this->assertFalse(app_subdomain_valid('blog.b.example.com', $resolver), 'FQDN bukan label');
    }

    // ==================================================================
    // Memo melalui helper + noop `change()`
    // ==================================================================

    public function testHelperSeesFreshDomainAfterServerUpdate(): void
    {
        $dua = $this->server('b.example.com');
        $resolver = $this->resolver();
        $app = ['name' => 'myblog', 'subdomain' => 'blog', 'server_id' => $dua['id']];

        $this->assertSame('blog.b.example.com', app_subdomain_of($app, $resolver));

        (new ServerStore($this->serversFile))->update($dua['id'], static function (array &$row): void {
            $row['domain'] = 'c.example.com';
        });

        $this->assertSame('blog.c.example.com', app_subdomain_of($app, $resolver));
    }

    public function testChangeTreatsLegacyFqdnOnOwnServerAsNoop(): void
    {
        $dua = $this->server('b.example.com');
        $app = $this->seed([
            'name' => 'blog',
            'subdomain' => 'blog.b.example.com',
            'server_id' => $dua['id'],
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'blog', 'ports' => [['host' => 30000, 'container' => 80]]],
            ],
        ]);
        $manager = new SubdomainManager($this->store(), null, $this->resolver());

        $result = $manager->change($app['id'], 'blog');

        // FQDN efektif lama == baru (keduanya `blog.b.example.com`) ⇒ idempoten,
        // tanpa menyentuh Nginx. Perilaku lama (domain global) akan menganggapnya
        // berubah dan menulis config.
        $this->assertTrue($result['noop']);
        $this->assertTrue($result['ok']);
        $this->assertSame('blog.b.example.com', $result['effective']);
        $this->assertSame('blog.b.example.com', $result['previous']);
    }
}
