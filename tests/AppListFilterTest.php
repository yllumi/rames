<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Auth\UserStore;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

/**
 * `AppController` dengan seam uji: renderer view disuntikkan (tanpa konteks
 * HTTP) sehingga variabel view dapat diperiksa langsung.
 */
final class AppListFilterController extends AppController
{
    /** @var list<array{template:string,vars:array<string,mixed>}> */
    public array $rendered = [];

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered[] = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered:' . $template);
    }
}

/**
 * Filter daftar app `?name=` / `?domain=` / `?owner=` pada `/apps`
 * (docs/SPECS.md §8e / §7.7): menyempitkan baris **dan** `counts` tab, bergabung
 * (AND) dengan `?scope=` & `?server=` yang sudah ada, dan **tidak** membocorkan
 * app user lain lewat parameter `owner` bagi non-admin.
 *
 * Tes berjalan di proses terpisah dengan stub helper global (`current_user`,
 * `session`, ...) supaya tidak bocor; store (app/user/server) memakai path temp
 * di `sys_get_temp_dir()` — `database/`/`apps/` nyata tidak pernah disentuh.
 */
class AppListFilterTest extends TestCase
{
    private const PASSWORD = 'rahasia-uji-123';

    /**
     * Stub helper global (hanya di proses anak). `config()`/`is_admin()` dll
     * datang dari `app/functions.php` yang di-require setelah stub ini.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return $GLOBALS['RAMES_TEST_USER'] ?? null;
                }
            }
            if (!function_exists('session')) {
                function session(): object
                {
                    return new class {
                        public function get(?string $key = null): mixed
                        {
                            return $key === 'user' ? ($GLOBALS['RAMES_TEST_USER'] ?? null) : null;
                        }

                        public function set(string $key, mixed $value): void
                        {
                        }

                        public function delete(string $key): void
                        {
                        }

                        public function pull(string $key): mixed
                        {
                            return null;
                        }
                    };
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                    $GLOBALS['RAMES_FLASH'][] = ['type' => $type, 'message' => $message];
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
        }
        PHP;

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-applist-filter-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/apps', 0777, true);

        $GLOBALS['RAMES_FLASH'] = [];
        $GLOBALS['RAMES_TEST_USER'] = null;
        $this->configState = $this->snapshotConfigState();

        ServerResolver::forgetMemo();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
        unset($GLOBALS['RAMES_FLASH'], $GLOBALS['RAMES_TEST_USER']);
    }

    // ==================================================================
    // (1) name / domain / owner menyempitkan baris + counts tab
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNameFilterNarrowsRowsAndTabCounts(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($admin);

        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?name=blog'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame('app/index', $controller->rendered[0]['template']);
        $this->assertSame(['blog-admin', 'blog-budi', 'blog-lama'], array_column($vars['apps'], 'name'));
        // Tab count ikut menyempit (konsisten dengan filter server).
        $this->assertSame(['all' => 3, 'mine' => 1, 'shared' => 0], [
            'all' => $vars['counts']['all'],
            'mine' => $vars['counts']['mine'],
            'shared' => $vars['counts']['shared'],
        ]);
        $this->assertSame(['name' => 'blog', 'domain' => '', 'owner' => ''], $vars['filters']);
        $this->assertTrue($vars['filterActive']);

        // Pencarian case-insensitive + trim (nilai efektif yang dikirim ke view).
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?name=%20BLOG%20'));
        $this->assertSame(['blog-admin', 'blog-budi', 'blog-lama'], array_column(
            $controller->rendered[0]['vars']['apps'],
            'name'
        ));
        $this->assertSame(
            ['name' => 'blog', 'domain' => '', 'owner' => ''],
            $controller->rendered[0]['vars']['filters']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDomainFilterMatchesEffectiveSubdomainAndCustomDomain(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($admin);

        // Lewat subdomain efektif (`toko-siti.example.test`).
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?domain=toko-siti'));
        $this->assertSame(['toko-siti'], array_column($controller->rendered[0]['vars']['apps'], 'name'));
        $this->assertSame(1, $controller->rendered[0]['vars']['counts']['all']);
        $this->assertSame('toko-siti', $controller->rendered[0]['vars']['filters']['domain']);

        // Lewat `custom_domain` app (bukan subdomain efektif).
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?domain=TOKOKITA.TEST'));
        $this->assertSame(['toko-siti'], array_column($controller->rendered[0]['vars']['apps'], 'name'));
        $this->assertSame('tokokita.test', $controller->rendered[0]['vars']['filters']['domain']);

        // Domain milik SEMUA app tidak menyempitkan (semua di `example.test`).
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?domain=example.test'));
        $this->assertSame(5, $controller->rendered[0]['vars']['counts']['all']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminOwnerFilterNarrowsRowsAndExposesOptions(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($admin);

        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?owner=' . (string) $siti['id']));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame(['blog-lama', 'toko-siti'], array_column($vars['apps'], 'name'));
        $this->assertSame(2, $vars['counts']['all']);
        $this->assertSame(0, $vars['counts']['mine']);
        $this->assertSame((string) $siti['id'], $vars['filters']['owner']);
        $this->assertTrue($vars['filterActive']);

        // Pilihan owner = peta id → username (admin saja).
        $this->assertSame([
            (string) $admin['id'] => 'admin',
            (string) $budi['id'] => 'budi',
            (string) $siti['id'] => 'siti',
        ], $vars['ownerOptions']);
        $this->assertSame($vars['ownerOptions'], $vars['ownerNames']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminUnknownOwnerIdIsIgnored(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($admin);

        foreach (['u-tidak-ada', ''] as $requested) {
            $controller = new AppListFilterController();
            $controller->index($this->get('/apps?owner=' . $requested));

            $vars = $controller->rendered[0]['vars'];
            $this->assertSame('', $vars['filters']['owner'], 'id di luar peta user diabaikan (bukan halaman kosong)');
            $this->assertFalse($vars['filterActive']);
            $this->assertSame(5, $vars['counts']['all']);
        }
    }

    // ==================================================================
    // (2) AND dengan `?scope=` & `?server=`
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNameFilterCombinesWithScopeAndServerFilter(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->agent('srv-jakarta', 'vps-jakarta');
        $this->app(['name' => 'blog-jakarta', 'subdomain' => 'blog-jakarta', 'owner_id' => (string) $budi['id'], 'server_id' => 'srv-jakarta']);
        $this->app(['name' => 'toko-jakarta', 'subdomain' => 'toko-jakarta', 'owner_id' => (string) $budi['id'], 'server_id' => 'srv-jakarta']);
        $this->installStubs($budi);

        // `?scope=mine` + `?name=blog`: budi melihat 5 app (2 miliknya lokal,
        // 1 dibagikan, 2 miliknya di agent) ⇒ `name` menyisakan 2, `scope`
        // memilih yang milik budi sendiri.
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?scope=mine&name=blog'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame('mine', $vars['scope']);
        $this->assertSame(['blog-budi', 'blog-jakarta'], array_column($vars['apps'], 'name'));
        $this->assertSame(['all' => 2, 'mine' => 2, 'shared' => 0], [
            'all' => $vars['counts']['all'],
            'mine' => $vars['counts']['mine'],
            'shared' => $vars['counts']['shared'],
        ]);
        $this->assertSame(['name' => 'blog', 'domain' => '', 'owner' => ''], $vars['filters']);

        // `?server=srv-jakarta` + `?name=blog` tetap bergabung (AND).
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?server=srv-jakarta&name=blog'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame('srv-jakarta', $vars['serverFilter']);
        $this->assertSame(['blog-jakarta'], array_column($vars['apps'], 'name'));
        $this->assertSame(1, $vars['counts']['all']);
    }

    // ==================================================================
    // (3)(4) `?owner=` tidak bocor untuk non-admin
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdminOwnerFilterIsIgnoredEntirely(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($budi);

        // `siti` adalah user NYATA (ada di `user_names()`) — jadi pengabaian di
        // sini murni karena bukan admin, bukan karena id tidak dikenal.
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?owner=' . (string) $siti['id']));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame('', $vars['filters']['owner'], 'non-admin: parameter owner diabaikan');
        $this->assertFalse($vars['filterActive']);
        $this->assertSame(['arsip-budi', 'blog-budi', 'toko-siti'], array_column($vars['apps'], 'name'));
        $this->assertSame([], $vars['ownerOptions'], 'non-admin tidak menerima peta owner');
        $this->assertSame([], $vars['ownerNames']);

        // Tidak ada app user lain yang bocor (daftar tetap = app yang boleh dilihat).
        $json = (string) json_encode($vars);
        $this->assertStringNotContainsString('blog-admin', $json);
        $this->assertStringNotContainsString('blog-lama', $json);
        $this->assertStringNotContainsString((string) $admin['id'], $json);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdminNameFilterStillScopedToVisibleApps(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($budi);

        // `?name=blog` dengan app milik siti bernama `blog-lama`: baris siti itu
        // tidak pernah muncul untuk budi walau namanya cocok.
        $controller = new AppListFilterController();
        $controller->index($this->get('/apps?name=blog'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame(['blog-budi'], array_column($vars['apps'], 'name'));
        $this->assertSame(['all' => 1, 'mine' => 1, 'shared' => 0], [
            'all' => $vars['counts']['all'],
            'mine' => $vars['counts']['mine'],
            'shared' => $vars['counts']['shared'],
        ]);
        $this->assertStringNotContainsString('blog-lama', (string) json_encode($vars));
        $this->assertStringNotContainsString('blog-admin', (string) json_encode($vars));
    }

    // ==================================================================
    // (5) Tanpa parameter: perilaku lama tidak berubah
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutParametersFiltersAreEmptyAndResultIsUnchanged(): void
    {
        $admin = $this->user('admin');
        $budi = $this->user('budi');
        $siti = $this->user('siti');
        $this->seedApps($admin, $budi, $siti);
        $this->installStubs($budi);

        $controller = new AppListFilterController();
        $controller->index($this->get('/apps'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame(['name' => '', 'domain' => '', 'owner' => ''], $vars['filters']);
        $this->assertFalse($vars['filterActive']);
        $this->assertSame('', $vars['serverFilter']);
        $this->assertSame('all', $vars['scope']);
        $this->assertSame(['arsip-budi', 'blog-budi', 'toko-siti'], array_column($vars['apps'], 'name'));
        $this->assertSame(['all' => 3, 'mine' => 2, 'shared' => 1], [
            'all' => $vars['counts']['all'],
            'mine' => $vars['counts']['mine'],
            'shared' => $vars['counts']['shared'],
        ]);
        // Key lama tetap ada (tanpa penghapusan).
        foreach (['apps', 'roles', 'ownedIds', 'scope', 'counts', 'isAdmin', 'ownerNames', 'serverNames', 'serverOptions', 'serverFilter'] as $key) {
            $this->assertArrayHasKey($key, $vars);
        }
        $this->assertSame('viewer', (string) ($vars['roles'][(string) $this->appId('toko-siti')] ?? ''));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Lima app lintas tiga owner: `blog-admin` (admin), `blog-budi` &
     * `arsip-budi` (budi), `blog-lama` & `toko-siti` (siti, custom_domain,
     * dibagikan ke budi sebagai viewer).
     *
     * @param array<string,mixed> $admin
     * @param array<string,mixed> $budi
     * @param array<string,mixed> $siti
     */
    private function seedApps(array $admin, array $budi, array $siti): void
    {
        $this->app(['name' => 'blog-admin', 'subdomain' => 'blog-admin', 'owner_id' => (string) $admin['id']]);
        $this->app(['name' => 'blog-budi', 'subdomain' => 'blog-budi', 'owner_id' => (string) $budi['id']]);
        $this->app(['name' => 'arsip-budi', 'subdomain' => 'arsip-budi', 'owner_id' => (string) $budi['id']]);
        $this->app(['name' => 'blog-lama', 'subdomain' => 'blog-lama', 'owner_id' => (string) $siti['id']]);
        $this->app([
            'name' => 'toko-siti',
            'subdomain' => 'toko-siti',
            'custom_domain' => 'tokokita.test',
            'owner_id' => (string) $siti['id'],
            'members' => [(string) $budi['id'] => ['role' => 'viewer']],
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function app(array $extra): array
    {
        return (new AppStore())->create(array_merge(['status' => 'running'], $extra));
    }

    /** @return array<string,mixed> */
    private function user(string $username): array
    {
        return (new UserStore())->create($username, self::PASSWORD);
    }

    private function appId(string $name): string
    {
        foreach ((new AppStore())->all() as $app) {
            if (($app['name'] ?? '') === $name) {
                return (string) $app['id'];
            }
        }

        return '';
    }

    private function agent(string $id, string $name): array
    {
        $server = (new ServerStore())->create([
            'id' => $id,
            'name' => $name,
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://' . $id . '.internal.test:8443',
            'token' => 'tok-uji-' . $id,
            'tls_cert_pem' => "-----BEGIN CERTIFICATE-----\n#" . $id . "\n-----END CERTIFICATE-----\n",
            'status' => 'online',
        ]);
        ServerResolver::forgetMemo();

        return $server;
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n");
    }

    /**
     * Pasang stub helper global + `app/functions.php` (hanya di proses anak),
     * lalu arahkan seluruh path store ke direktori temp uji.
     *
     * @param array<string,mixed>|null $user user login yang dikembalikan `current_user()`
     */
    private function installStubs(?array $user): void
    {
        $GLOBALS['RAMES_TEST_USER'] = $user;
        $GLOBALS['RAMES_FLASH'] = [];

        if (!function_exists('flash_set')) {
            $file = sys_get_temp_dir() . '/rames-applist-filter-stubs-' . getmypid() . '.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
            @unlink($file);
        }

        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }
        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        $this->useConfig();
    }

    /** Seluruh path store diarahkan ke temp (tanpa `database/`/`apps/` nyata). */
    private function useConfig(): void
    {
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
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

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
