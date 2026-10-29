<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppAccessDenied;
use app\library\Errors\ErrorReporter;
use app\library\Errors\ErrorStore;
use app\library\Storage\AppStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test {@see ErrorReporter} — kebijakan laporan klien vs exception server.
 *
 * Store & rate limiter memakai DB SQLite temp; config (`deploy.*`, `errors.*`)
 * ditulis ke direktori temp lalu registry `Webman\Config` dipulihkan di
 * tearDown. `database/rames.sqlite` tidak pernah disentuh.
 */
class ErrorReporterTest extends TestCase
{
    private const MEMBER = ['id' => 'u1', 'username' => 'budi', 'role' => 'member'];
    private const OTHER = ['id' => 'u2', 'username' => 'siti', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'root', 'role' => 'admin'];

    private string $tmp;

    private string $file;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/errreporter_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';

        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        foreach (glob($this->tmp . '/config/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->tmp . '/config');
        foreach (glob($this->tmp . '/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // reportClient
    // ------------------------------------------------------------------

    public function testUnauthenticatedIsRejectedWithoutStoreWrite(): void
    {
        $reporter = $this->reporter();

        $result = $reporter->reportClient(
            ['kind' => 'client', 'message' => 'Boom'],
            'UA',
            null,
            '/apps/demo'
        );

        self::assertSame([
            'accepted' => false,
            'reason' => 'unauthenticated',
            'group_id' => null,
        ], $result);
        self::assertSame(0, $this->eventCount());
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $reporter = $this->reporter();

        $result = $reporter->reportClient([], 'UA', self::MEMBER, '/apps/demo');

        self::assertFalse($result['accepted']);
        self::assertSame('invalid', $result['reason']);
        self::assertSame(0, $this->eventCount());
    }

    public function testRateLimitPerUser(): void
    {
        $this->useConfig(['rate_per_minute' => 1]);
        $reporter = $this->reporter();

        $first = $reporter->reportClient(
            ['kind' => 'client', 'message' => 'Satu'],
            'UA',
            self::MEMBER,
            '/apps/demo'
        );
        $second = $reporter->reportClient(
            ['kind' => 'client', 'message' => 'Dua'],
            'UA',
            self::MEMBER,
            '/apps/demo'
        );

        self::assertTrue($first['accepted']);
        self::assertSame('rate_limited', $second['reason']);
        self::assertNull($second['group_id']);
        self::assertSame(1, $this->eventCount());
    }

    public function testValidClientReportStoresEventAndGroup(): void
    {
        $app = (new AppStore($this->file))->create([
            'id' => 'app-1',
            'name' => 'demo',
            'owner_id' => self::MEMBER['id'],
        ]);

        $result = $this->reporter()->reportClient([
            'kind' => 'client',
            'message' => 'Gagal memuat <b>monitor</b>',
            'stack' => "Error: x\n    at run (/app/public/js/app.js:5:2)",
            'source' => '/js/app.js',
            'line' => 12,
            'column' => 3,
            'route' => '/apps/' . $app['id'],
            'occurred_at' => date('c'),
            'context' => ['fitur' => 'monitor'],
        ], 'Mozilla/5.0', self::MEMBER, '/apps/' . $app['id']);

        self::assertTrue($result['accepted']);
        self::assertSame('', $result['reason']);
        self::assertNotNull($result['group_id']);

        $store = $this->store();
        $group = $store->findGroup((string) $result['group_id']);
        self::assertNotNull($group);
        self::assertSame('client', $group['kind']);
        self::assertSame('/js/app.js:12:3', $group['location']);
        self::assertSame('/apps/app-1', $group['route']);
        self::assertSame('app-1', $group['app_id']);
        self::assertSame('local', $group['server_id']);
        self::assertSame(self::MEMBER['id'], $group['user_id']);
        self::assertSame('budi', $group['username']);
        self::assertSame(1, (int) $group['count']);

        $events = $store->eventsForGroup((string) $result['group_id']);
        self::assertCount(1, $events);
        self::assertSame(['fitur' => 'monitor'], $events[0]['context']);
        self::assertSame('Mozilla/5.0', $events[0]['user_agent']);
    }

    public function testAppIdDroppedWhenUserCannotViewApp(): void
    {
        (new AppStore($this->file))->create([
            'id' => 'app-private',
            'name' => 'rahasia',
            'owner_id' => self::OTHER['id'],
        ]);

        $result = $this->reporter()->reportClient([
            'kind' => 'client',
            'message' => 'Tidak boleh lihat',
            'app_id' => 'app-private',
            'route' => '/apps/app-private',
        ], 'UA', self::MEMBER, '/apps/app-private');

        self::assertTrue($result['accepted']);
        $group = $this->store()->findGroup((string) $result['group_id']);
        self::assertNull($group['app_id'], 'app milik user lain tidak boleh tercatat');

        // Admin selalu boleh.
        $adminResult = $this->reporter()->reportClient([
            'kind' => 'client',
            'message' => 'Admin lihat',
            'app_id' => 'app-private',
            'route' => '/apps/app-private',
        ], 'UA', self::ADMIN, '/apps/app-private');

        $adminGroup = $this->store()->findGroup((string) $adminResult['group_id']);
        self::assertSame('app-private', $adminGroup['app_id']);
    }

    public function testPayloadRouteTakesPriorityOverEndpointPath(): void
    {
        (new AppStore($this->file))->create([
            'id' => 'app-abc',
            'name' => 'abc',
            'owner_id' => self::MEMBER['id'],
        ]);

        $result = $this->reporter()->reportClient([
            'kind' => 'client',
            'message' => 'Gagal di halaman app',
            // Query dibuang ErrorInput; route ini yang harus tersimpan.
            'route' => '/apps/app-abc?tab=logs',
        ], 'UA', self::MEMBER, '/api/errors');

        self::assertTrue($result['accepted']);
        $group = $this->store()->findGroup((string) $result['group_id']);
        self::assertSame('/apps/app-abc', $group['route'], 'route payload, bukan path endpoint');
        self::assertSame('app-abc', $group['app_id'], 'app_id diturunkan dari route payload');
    }

    public function testEmptyPayloadRouteFallsBackToRequestRoute(): void
    {
        $result = $this->reporter()->reportClient([
            'kind' => 'client',
            'message' => 'Tanpa route payload',
        ], 'UA', self::MEMBER, '/apps/fallback');

        $group = $this->store()->findGroup((string) $result['group_id']);
        self::assertSame('/apps/fallback', $group['route']);
    }

    public function testOccurredAtIsClampedToReasonableWindow(): void
    {
        $reporter = $this->reporter();

        $old = $reporter->reportClient([
            'kind' => 'client',
            'message' => 'Waktu lama',
            'occurred_at' => '2020-01-01T00:00:00Z',
        ], 'UA', self::MEMBER, '/apps/demo');

        $future = $reporter->reportClient([
            'kind' => 'client',
            'message' => 'Waktu masa depan',
            'occurred_at' => gmdate('c', time() + 86400),
        ], 'UA', self::MEMBER, '/apps/demo');

        $store = $this->store();
        foreach ([$old, $future] as $result) {
            $group = $store->findGroup((string) $result['group_id']);
            $seen = strtotime((string) $group['last_seen']);
            self::assertNotFalse($seen);
            self::assertLessThanOrEqual(5, abs(time() - $seen), 'waktu di luar jendela diganti sekarang');
        }
    }

    public function testOccurredAtWithinTenMinuteFutureIsPreserved(): void
    {
        $occurredAt = time() + 300;

        $result = $this->reporter()->reportClient([
            'kind' => 'client',
            'message' => 'Jam klien sedikit maju',
            'occurred_at' => gmdate('c', $occurredAt),
        ], 'UA', self::MEMBER, '/apps/demo');

        $group = $this->store()->findGroup((string) $result['group_id']);
        $seen = strtotime((string) $group['last_seen']);
        self::assertNotFalse($seen);
        self::assertLessThanOrEqual(5, abs($occurredAt - $seen), '+300s masih dalam jendela (±10 menit)');
    }

    public function testOccurredAtBeyondTenMinuteWindowIsClampedToNow(): void
    {
        $reporter = $this->reporter();

        foreach ([601, -601] as $offset) {
            $before = time();
            $result = $reporter->reportClient([
                'kind' => 'client',
                'message' => 'Di luar jendela ' . $offset,
                'occurred_at' => gmdate('c', $before + $offset),
            ], 'UA', self::MEMBER, '/apps/demo');
            $after = time();

            $group = $this->store()->findGroup((string) $result['group_id']);
            $seen = strtotime((string) $group['last_seen']);
            self::assertNotFalse($seen);
            self::assertGreaterThanOrEqual($before - 1, $seen, 'offset ' . $offset . 's di-clamp ke sekarang');
            self::assertLessThanOrEqual($after + 1, $seen);
        }
    }

    // ------------------------------------------------------------------
    // reportServer
    // ------------------------------------------------------------------

    public function testServerReportRecordsException(): void
    {
        $this->reporter()->reportServer(new RuntimeException('Boom di server'));

        self::assertSame(1, $this->eventCount());
        $groups = $this->store()->listGroups(['status' => 'all'], 1, 50)['groups'];
        self::assertCount(1, $groups);
        self::assertSame('server', $groups[0]['kind']);
        self::assertSame(500, (int) $groups[0]['http_status']);
        self::assertStringContainsString('RuntimeException: Boom di server', $groups[0]['message']);
    }

    public function testBusinessExceptionIsNotRecorded(): void
    {
        $this->reporter()->reportServer(new AppAccessDenied('view', 'app-1'));

        self::assertSame(0, $this->eventCount());
    }

    public function testAgentModeIsNotRecorded(): void
    {
        $this->useConfig([], 'agent');

        $this->reporter()->reportServer(new RuntimeException('Boom di agent'));

        self::assertSame(0, $this->eventCount());
    }

    public function testServerReportRedactsSecrets(): void
    {
        $this->reporter()->reportServer(new RuntimeException('Gagal auth access_token=rahasia-super-123'));

        $groups = $this->store()->listGroups(['status' => 'all'], 1, 50)['groups'];
        self::assertCount(1, $groups);
        self::assertStringNotContainsString('rahasia-super-123', (string) $groups[0]['message']);
        self::assertStringContainsString('[REDACTED]', (string) $groups[0]['message']);
    }

    public function testRateLimitPerFingerprint(): void
    {
        $this->useConfig(['rate_per_minute' => 2]);
        $reporter = $this->reporter();

        for ($i = 0; $i < 3; $i++) {
            $reporter->reportServer(new RuntimeException('Sama persis'));
        }

        self::assertSame(2, $this->eventCount(), 'laporan ke-3 untuk fingerprint sama ditolak');
    }

    public function testNeverThrowsWhenStoreFails(): void
    {
        // Path berupa direktori ⇒ PDO gagal membuka berkas.
        mkdir($this->tmp . '/bukan-db', 0777, true);
        $broken = new ErrorStore(new SqliteDatabase($this->tmp . '/bukan-db'));

        $reporter = new ErrorReporter($broken);

        $reporter->reportServer(new RuntimeException('Tidak boleh melempar'));

        self::assertTrue(true, 'reportServer menelan kegagalan store');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function reporter(): ErrorReporter
    {
        return new ErrorReporter($this->store());
    }

    private function store(): ErrorStore
    {
        return new ErrorStore(new SqliteDatabase($this->file));
    }

    private function eventCount(): int
    {
        return (int) (new SqliteDatabase($this->file))->pdo()
            ->query('SELECT COUNT(*) FROM "error_events"')
            ->fetchColumn();
    }

    /**
     * @param array<string,mixed> $errorsOverride
     */
    private function useConfig(array $errorsOverride = [], string $mode = 'dashboard'): void
    {
        $errors = $errorsOverride + [
            'retention_days' => 30,
            'max_events' => 5000,
            'max_samples_per_group' => 20,
            'max_payload_bytes' => 16384,
            'rate_per_minute' => 20,
            'max_message_chars' => 2000,
            'max_stack_bytes' => 8192,
            'per_page' => 50,
        ];

        $configDir = $this->tmp . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }

        file_put_contents($configDir . '/app.php', "<?php return [];\n");
        file_put_contents(
            $configDir . '/deploy.php',
            '<?php return ' . var_export([
                'mode' => $mode,
                'sqlite_file' => $this->file,
                'sqlite_dir' => $this->tmp,
                'apps_path' => $this->tmp . '/apps',
                'app_domain' => 'example.org',
            ], true) . ';' . PHP_EOL
        );
        file_put_contents(
            $configDir . '/errors.php',
            '<?php return ' . var_export($errors, true) . ';' . PHP_EOL
        );

        Config::clear();
        Config::load($configDir);
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
}
