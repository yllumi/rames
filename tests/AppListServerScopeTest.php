<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
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
 * HTTP) dan jumlah resolusi registry server dihitung — bukti kolom Server tidak
 * di-resolve ulang per baris (bukan N+1).
 *
 * Store tetap memakai `config()` (path temp di proses anak) supaya perilaku
 * produksi `new AppStore()`/`new ServerResolver()` ikut teruji.
 */
final class AppListScopeController extends AppController
{
    /** @var list<array{template:string,vars:array<string,mixed>}> */
    public array $rendered = [];

    public int $resolverCalls = 0;

    protected function serverResolver(): ServerResolver
    {
        $this->resolverCalls++;

        return new ServerResolver();
    }

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered[] = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered:' . $template);
    }
}

/**
 * Kolom & filter **Server** pada halaman `/apps` (multi-server, plan §9.3/§12):
 *  - admin melihat nama server setiap app + pemilih filter semua server;
 *  - non-admin **tidak** menerima daftar server lain (hanya server yang
 *    menaungi app yang boleh ia lihat) dan tidak pernah dapat pemilih;
 *  - filter `?server=` menyempitkan baris & tab count, id asing diabaikan;
 *  - perilaku lama (tanpa filter) tidak berubah: scope, urutan, counts.
 *
 * Tes berjalan di proses terpisah dengan stub helper global (`current_user`,
 * `session`, ...) supaya tidak bocor; store memakai path temp (`database/` dan
 * `apps/` nyata tidak pernah disentuh).
 */
class AppListServerScopeTest extends TestCase
{
    /** @var array<string,mixed> */
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];

    /** @var array<string,mixed> */
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];

    private const AGENT_SECRET_NAME = 'vps-rahasia';

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
        $this->tmp = sys_get_temp_dir() . '/rames-applist-scope-' . bin2hex(random_bytes(5));
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
    // (iii)+(iv) Admin: nama server per baris + pemilih filter semua server
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminReceivesServerNamesAndEveryServerAsFilterOption(): void
    {
        $this->installStubs(self::ADMIN);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'owner_id' => 'u1']);
        $this->agent('srv-jakarta', 'vps-jakarta');
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'owner_id' => 'u1', 'server_id' => 'srv-jakarta']);
        $this->agent('srv-bandung', 'vps-bandung');
        $this->app(['id' => 'a3', 'name' => 'app-bandung', 'owner_id' => 'u3', 'server_id' => 'srv-bandung']);

        $controller = new AppListScopeController();
        $controller->index($this->get('/apps'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame('app/index', $controller->rendered[0]['template']);

        // Nama server (bukan id) menempel di tiap baris.
        $byName = [];
        foreach ($vars['apps'] as $app) {
            $byName[(string) $app['name']] = [$app['server_name'], $app['server_kind']];
        }
        $this->assertSame('local', $byName['app-lokal'][0]);
        $this->assertSame('local', $byName['app-lokal'][1]);
        $this->assertSame('vps-jakarta', $byName['app-jakarta'][0]);
        $this->assertSame('agent', $byName['app-jakarta'][1]);
        $this->assertSame('vps-bandung', $byName['app-bandung'][0]);

        // Pemilih filter (admin) = seluruh registry, termasuk server tanpa app.
        $this->assertSame(
            [
                ['id' => 'local', 'name' => 'local'],
                ['id' => 'srv-jakarta', 'name' => 'vps-jakarta'],
                ['id' => 'srv-bandung', 'name' => 'vps-bandung'],
            ],
            $vars['serverOptions']
        );
        $this->assertSame('', $vars['serverFilter']);

        // Tidak ada rahasia server yang ikut ke view (hanya id/nama).
        $json = (string) json_encode($vars);
        $this->assertStringNotContainsString('tok-uji-srv-jakarta', $json);
        $this->assertStringNotContainsString('internal.test', $json);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $json);

        // Peta id → nama dibaca sekali per sumber (bukan per baris app).
        $this->assertSame(2, $controller->resolverCalls, '1× peta nama baris + 1× pilihan filter');
    }

    // ==================================================================
    // (i) Non-admin: tanpa daftar server lain, tanpa pemilih
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdminGetsNoServerListAndOnlyOwnServersNames(): void
    {
        $this->installStubs(self::MEMBER);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'owner_id' => 'u2']);
        $this->agent('srv-jakarta', 'vps-jakarta');
        $this->app(['id' => 'a2', 'name' => 'app-jakarta', 'owner_id' => 'u2', 'server_id' => 'srv-jakarta']);
        // Server rahasia: app milik user lain → non-admin tidak pernah tahu.
        $this->agent('srv-rahasia', self::AGENT_SECRET_NAME);
        $this->app(['id' => 'a3', 'name' => 'app-rahasia', 'owner_id' => 'u9', 'server_id' => 'srv-rahasia']);

        $controller = new AppListScopeController();
        $controller->index($this->get('/apps'));

        $vars = $controller->rendered[0]['vars'];

        $this->assertSame(['app-jakarta', 'app-lokal'], array_column($vars['apps'], 'name'));
        $this->assertSame([], $vars['serverOptions'], 'non-admin tidak pernah menerima pemilih server');
        $this->assertSame(['local', 'srv-jakarta'], array_keys($vars['serverNames']));
        $this->assertSame(['local' => 'local', 'srv-jakarta' => 'vps-jakarta'], $vars['serverNames']);

        $json = (string) json_encode($vars);
        $this->assertStringNotContainsString('srv-rahasia', $json);
        $this->assertStringNotContainsString(self::AGENT_SECRET_NAME, $json);
        $this->assertStringNotContainsString('app-rahasia', $json);
        $this->assertSame(1, $controller->resolverCalls, 'non-admin: hanya satu pembacaan registry');
    }

    // ==================================================================
    // (iv) Filter server bekerja & menyempitkan counts
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminServerFilterNarrowsRowsCountsAndTabLinks(): void
    {
        $this->installStubs(self::ADMIN);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'owner_id' => 'u1']);
        $this->app(['id' => 'a2', 'name' => 'app-lain', 'owner_id' => 'u1']);
        $this->agent('srv-jakarta', 'vps-jakarta');
        $this->app(['id' => 'a3', 'name' => 'app-jakarta', 'owner_id' => 'u1', 'server_id' => 'srv-jakarta']);
        $this->app([
            'id' => 'a4',
            'name' => 'app-shared',
            'owner_id' => 'u5',
            'server_id' => 'srv-jakarta',
            'members' => ['u2' => ['role' => 'operator']],
        ]);

        $controller = new AppListScopeController();
        $controller->index($this->get('/apps?scope=all&server=srv-jakarta'));

        $vars = $controller->rendered[0]['vars'];
        // Hanya app server terpilih; counts (tab) mengikuti subset itu.
        // Admin tidak punya baris "Dibagikan" (role-nya admin pada semua app).
        $this->assertSame(['app-jakarta', 'app-shared'], array_column($vars['apps'], 'name'));
        $this->assertSame(['all' => 2, 'mine' => 1, 'shared' => 0], [
            'all' => $vars['counts']['all'],
            'mine' => $vars['counts']['mine'],
            'shared' => $vars['counts']['shared'],
        ]);
        $this->assertSame('srv-jakarta', $vars['serverFilter']);

        // Filter + tab `mine` tetap bekerja bersamaan.
        $controller = new AppListScopeController();
        $controller->index($this->get('/apps?scope=mine&server=srv-jakarta'));
        $this->assertSame(['app-jakarta'], array_column($controller->rendered[0]['vars']['apps'], 'name'));
        $this->assertSame('mine', $controller->rendered[0]['vars']['scope']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownOrUnauthorizedServerFilterIsIgnored(): void
    {
        $this->installStubs(self::MEMBER);
        $this->app(['id' => 'a1', 'name' => 'app-lokal', 'owner_id' => 'u2']);
        $this->agent('srv-rahasia', self::AGENT_SECRET_NAME);
        $this->app(['id' => 'a3', 'name' => 'app-rahasia', 'owner_id' => 'u9', 'server_id' => 'srv-rahasia']);

        foreach (['srv-rahasia', 'srv-tidak-ada'] as $requested) {
            $controller = new AppListScopeController();
            $controller->index($this->get('/apps?server=' . $requested));

            $vars = $controller->rendered[0]['vars'];
            $this->assertSame('', $vars['serverFilter'], 'id di luar jangkauan diabaikan (bukan oracle)');
            $this->assertSame(['app-lokal'], array_column($vars['apps'], 'name'));
            $this->assertSame([], $vars['serverOptions']);
        }
    }

    // ==================================================================
    // (v) Perilaku lama: tanpa filter, scope & urutan tidak berubah
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyBehaviourWithoutServerFilterIsUnchanged(): void
    {
        $this->installStubs(self::MEMBER);
        $this->app(['id' => 'a1', 'name' => 'zeta-milik', 'owner_id' => 'u2']);
        $this->app(['id' => 'a2', 'name' => 'alfa-milik', 'owner_id' => 'u2']);
        $this->app([
            'id' => 'a3',
            'name' => 'beta-shared',
            'owner_id' => 'u5',
            'members' => ['u2' => ['role' => 'viewer']],
        ]);
        $this->app(['id' => 'a4', 'name' => 'app-oranglain', 'owner_id' => 'u9']);

        $controller = new AppListScopeController();
        $controller->index($this->get('/apps?scope=shared'));

        $vars = $controller->rendered[0]['vars'];
        $this->assertSame('shared', $vars['scope']);
        $this->assertSame(['beta-shared'], array_column($vars['apps'], 'name'));
        $this->assertSame(['all' => 3, 'mine' => 2, 'shared' => 1], [
            'all' => $vars['counts']['all'],
            'mine' => $vars['counts']['mine'],
            'shared' => $vars['counts']['shared'],
        ]);
        $this->assertSame('', $vars['serverFilter']);
        $this->assertSame([], $vars['serverOptions'], 'non-admin tetap tanpa pemilih server');
        $this->assertSame('viewer', (string) ($vars['roles']['a3'] ?? ''));
        $this->assertTrue((bool) ($vars['ownedIds']['a1'] ?? false));

        // Urutan lama: app milik sendiri lebih dulu (alfabetis), lalu sisanya.
        $controller = new AppListScopeController();
        $controller->index($this->get('/apps'));
        $this->assertSame(
            ['alfa-milik', 'zeta-milik', 'beta-shared'],
            array_column($controller->rendered[0]['vars']['apps'], 'name')
        );
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @param array<string,mixed> $extra */
    private function app(array $extra): array
    {
        return (new AppStore())->create(array_merge(['status' => 'running'], $extra));
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
            $file = sys_get_temp_dir() . '/rames-applist-stubs-' . getmypid() . '.php';
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

    /**
     * @param array<string,mixed> $state
     */
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
