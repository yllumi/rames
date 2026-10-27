<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

/**
 * Grant pemilihan server per user di jalur create app — **satu pintu**
 * `ServerAccess::maySelect()` pada `AppController::resolveCreateServer()`,
 * daftar pilihan `serverFormContext()`, filter `serverFilterOptions()`, serta
 * tarif per server pada `billingContext()`.
 *
 * Tanpa HTTP/Docker; store & config SQLite temp. Grant **tidak** membuka app
 * user lain (app tetap lewat `AppAccess`).
 */
class AppControllerServerGrantTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
    private const GRANT_ALL = ['id' => 'u3', 'username' => 'sinta', 'role' => 'member', 'server_select' => true, 'server_ids' => []];
    private const GRANT_A = ['id' => 'u4', 'username' => 'tono', 'role' => 'member', 'server_select' => 'on', 'server_ids' => ['srv-a']];

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->tmp = sys_get_temp_dir() . '/rames-appctrl-grant-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/apps', 0777, true);
        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        $servers = new ServerStore();
        $servers->local();
        foreach ([
            ['id' => 'srv-a', 'rates' => ['cpu' => 200, 'ram' => 40, 'backup' => 500]],
            ['id' => 'srv-b', 'rates' => ['cpu' => 300, 'ram' => 60, 'backup' => 900]],
        ] as $row) {
            $servers->create($row + [
                'name' => $row['id'],
                'kind' => 'agent',
                'enabled' => true,
                'domain' => $row['id'] . '.example.test',
                'base_url' => 'https://' . $row['id'] . '.internal.test:8443',
                'token' => 'tok-uji-' . $row['id'],
                'status' => 'online',
                'capabilities' => ['deploy'],
            ]);
        }
        ServerResolver::forgetMemo();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeTree($file) : @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'app_domain' => 'example.test',
            'port_range' => ['start' => 30000, 'end' => 30999],
            'billing_enabled' => true,
            'billing_min_deposit_days' => 30,
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

    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new AppController(), ...$args);
    }

    private function resolve(?array $user, string $serverId): array
    {
        return $this->invoke('resolveCreateServer', [$serverId, $user, new ServerResolver()]);
    }

    // ------------------------------------------------------------------
    // resolveCreateServer — maySelect
    // ------------------------------------------------------------------

    public function testGrantHolderCanCreateOnAllowedServer(): void
    {
        $this->assertSame('srv-a', $this->resolve(self::GRANT_A, 'srv-a')['id']);
        $this->assertSame('local', $this->resolve(self::GRANT_A, 'local')['id']);
        $this->assertSame('local', $this->resolve(self::GRANT_A, '')['id'], 'id kosong = lokal');
    }

    public function testGrantHolderIsRejectedOutsideAllowlistWithGenericMessage(): void
    {
        try {
            $this->resolve(self::GRANT_A, 'srv-b');
            $this->fail('server di luar allowlist harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Anda tidak berhak memilih server untuk app baru.',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('srv-b', $e->getMessage(), 'keberadaan server tidak bocor');
        }
    }

    public function testMemberWithoutGrantCannotPickNonLocalServer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anda tidak berhak memilih server');

        $this->resolve(self::MEMBER, 'srv-a');
    }

    public function testEmptyAllowlistGrantMayPickAnyRegisteredServer(): void
    {
        $this->assertSame('srv-b', $this->resolve(self::GRANT_ALL, 'srv-b')['id']);
    }

    public function testGrantDoesNotBypassUnknownServerFailFast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal/');

        $this->resolve(self::GRANT_ALL, 'ghost');
    }

    // ------------------------------------------------------------------
    // serverFormContext / serverFilterOptions
    // ------------------------------------------------------------------

    public function testFormContextListsLocalPlusAllowlistAndClampsSelection(): void
    {
        $ctx = $this->invoke('serverFormContext', [self::GRANT_A, 'srv-a', new ServerResolver()]);
        $this->assertTrue($ctx['canSelectServer']);
        $this->assertSame(['local', 'srv-a'], array_column($ctx['servers'], 'id'));
        $this->assertSame('srv-a', $ctx['selectedServerId']);

        // Id di luar allowlist tidak boleh "menang" — jatuh ke lokal.
        $ctx = $this->invoke('serverFormContext', [self::GRANT_A, 'srv-b', new ServerResolver()]);
        $this->assertSame('local', $ctx['selectedServerId']);

        // Tidak ada field rahasia pada opsi server.
        foreach ($ctx['servers'] as $option) {
            $this->assertArrayNotHasKey('token', $option);
            $this->assertArrayNotHasKey('tls_cert_pem', $option);
            $this->assertArrayNotHasKey('base_url', $option);
            $this->assertArrayNotHasKey('rates', $option);
        }
    }

    public function testFormContextForMemberWithoutGrantStaysLocalOnly(): void
    {
        $ctx = $this->invoke('serverFormContext', [self::MEMBER, 'srv-a', new ServerResolver()]);

        $this->assertFalse($ctx['canSelectServer']);
        $this->assertSame(['local'], array_column($ctx['servers'], 'id'));
        $this->assertSame('local', $ctx['selectedServerId']);
    }

    public function testFilterOptionsAppearForGrantAndAdminButNotPlainMember(): void
    {
        $apps = [[
            'id' => 'a1',
            'owner_id' => 'u4',
            'server_id' => 'srv-a',
        ]];

        $this->assertSame(
            ['local', 'srv-a'],
            array_column($this->invoke('serverFilterOptions', [self::GRANT_A, $apps]), 'id')
        );
        $this->assertSame(
            ['local', 'srv-a', 'srv-b'],
            array_column($this->invoke('serverFilterOptions', [self::ADMIN, $apps]), 'id')
        );
        $this->assertSame(
            [],
            $this->invoke('serverFilterOptions', [self::MEMBER, $apps]),
            'member biasa tetap tanpa filter server (perilaku lama)'
        );
    }

    // ------------------------------------------------------------------
    // Billing context memakai tarif server target
    // ------------------------------------------------------------------

    public function testBillingContextUsesTargetServerRates(): void
    {
        $ctx = $this->invoke('billingContext', [
            ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]],
            self::MEMBER,
            null,
            null,
            'srv-a',
        ]);

        $this->assertSame(['cpu' => 200.0, 'ram' => 40.0], $ctx['rates']);
        $this->assertSame(240.0, $ctx['estimate_hourly']);
        $this->assertSame(round(240.0 * 24 * 30, 2), $ctx['required']);

        $ctxB = $this->invoke('billingContext', [
            ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]],
            self::MEMBER,
            null,
            null,
            'srv-b',
        ]);
        $this->assertSame(360.0, $ctxB['estimate_hourly']);
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
