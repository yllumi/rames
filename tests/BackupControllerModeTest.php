<?php
declare(strict_types=1);

namespace Tests;

use app\controller\backup\BackupOverviewController;
use app\library\Auth\AppAccessDenied;
use app\library\Backup\BackupSelection;
use app\library\Backup\VolumeTargetMap;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Throwable;
use Webman\Config;

/**
 * Test mediator `BackupOverviewController::setMode()` (POST /backups/mode) —
 * mengubah mode retensi volume (`snapshot`/`sync`).
 *
 * Tanpa HTTP, tanpa Docker: target Engine di-override lewat seam
 * `FakeBackupModeController::volumeTargets()` (`protected`, satu-satunya jalur
 * endpoint ini yang menyentuh Engine), user login lewat seam `currentUser()`,
 * dan seluruh store diarahkan ke basis data SQLite temp
 * (`deploy.sqlite_file`) — `database/` & `runtime/` nyata tidak tersentuh
 * (larangan #15).
 *
 * Membuktikan: 422 untuk nilai mode/nama volume tak sah, 404 (bukan 403) untuk
 * viewer/stranger/volume tak dikenal/volume yatim non-admin, owner & operator
 * boleh menyimpan, **tanpa gerbang kredit** (mengubah mode tidak menyalakan
 * backup), serta bentuk respons `{code:0,data:{volume,server_id,mode}}`.
 */
class BackupControllerModeTest extends TestCase
{
    private const OWNER = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    private const OPERATOR = ['id' => 'u2', 'username' => 'operator', 'role' => 'member'];
    private const VIEWER = ['id' => 'u3', 'username' => 'viewer', 'role' => 'member'];
    private const STRANGER = ['id' => 'u8', 'username' => 'stranger', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    private string $tmp;
    private string $db;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-bkmode-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        SqliteFixture::apps($this->db, [
            [
                'id' => 'app1',
                'name' => 'tonidata',
                'owner_id' => 'u1',
                'members' => [
                    'u2' => ['role' => 'operator'],
                    'u3' => ['role' => 'viewer'],
                ],
            ],
            ['id' => 'app2', 'name' => 'waha', 'owner_id' => 'u7', 'members' => []],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // Sukses & bentuk respons
    // ------------------------------------------------------------------

    public function testOwnerCanSwitchToSyncAndBack(): void
    {
        $controller = $this->controller(self::OWNER);

        $payload = $this->json($controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SYNC,
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertSame(
            ['volume' => 'tonidata_data', 'server_id' => 'local', 'mode' => BackupSelection::MODE_SYNC],
            $payload['data'],
            'bentuk respons stabil {volume,server_id,mode}'
        );

        $selection = new BackupSelection($this->db);
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'), 'mode tersimpan');
        $this->assertSame('u1', SqliteFixture::readAll($this->db, 'backup')['volumes']['tonidata_data']['updated_by'] ?? null);

        $back = $this->json($controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SNAPSHOT,
        ])));
        $this->assertSame(0, $back['code']);
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, (new BackupSelection($this->db))->modeOf('tonidata_data'));
    }

    public function testOperatorOfSharedAppCanChangeMode(): void
    {
        $controller = $this->controller(self::OPERATOR);

        $payload = $this->json($controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SYNC,
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertSame(BackupSelection::MODE_SYNC, (new BackupSelection($this->db))->modeOf('tonidata_data'));
    }

    public function testAdminCanChangeModeOfAnyVolume(): void
    {
        $controller = $this->controller(self::ADMIN);

        $payload = $this->json($controller->setMode($this->post('/backups/mode', [
            'volume' => 'waha_data',
            'mode' => BackupSelection::MODE_SYNC,
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertSame(BackupSelection::MODE_SYNC, (new BackupSelection($this->db))->modeOf('waha_data'));
    }

    /**
     * **Tanpa gerbang kredit**: mengubah mode tidak menyalakan backup, jadi
     * owner bersaldo 0 tetap boleh (billing aktif).
     */
    public function testModeChangeIsNotCreditGated(): void
    {
        $controller = $this->controller(self::OWNER); // saldo 0, billing_enabled = true

        $payload = $this->json($controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SYNC,
        ])));

        $this->assertSame(0, $payload['code'], 'tanpa gerbang kredit');
        $this->assertSame(BackupSelection::MODE_SYNC, (new BackupSelection($this->db))->modeOf('tonidata_data'));
    }

    /**
     * Mengubah mode tidak boleh mematikan/menyalakan jadwal.
     */
    public function testModeChangeKeepsScheduleFlag(): void
    {
        (new BackupSelection($this->db))->setScheduled('tonidata_data', true, 'u1');
        $controller = $this->controller(self::OWNER);

        $payload = $this->json($controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SYNC,
        ])));

        $this->assertSame(0, $payload['code']);
        $selection = new BackupSelection($this->db);
        $this->assertTrue($selection->isScheduled('tonidata_data'), 'jadwal tetap ON');
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'));
    }

    // ------------------------------------------------------------------
    // Otorisasi → 404 (bukan 403), tanpa mutasi
    // ------------------------------------------------------------------

    public function testViewerIsDeniedWith404AndNoMutation(): void
    {
        $controller = $this->controller(self::VIEWER);

        try {
            $controller->setMode($this->post('/backups/mode', [
                'volume' => 'tonidata_data',
                'mode' => BackupSelection::MODE_SYNC,
            ]));
            $this->fail('viewer harus ditolak (AppAccessDenied → 404)');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('backup', $e->ability);
        }

        $this->assertSame(
            BackupSelection::MODE_SNAPSHOT,
            (new BackupSelection($this->db))->modeOf('tonidata_data'),
            'penolakan tanpa efek samping'
        );
    }

    public function testStrangerIsDeniedWith404(): void
    {
        $controller = $this->controller(self::STRANGER);

        $this->expectException(AppAccessDenied::class);
        $controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SYNC,
        ]));
    }

    public function testOtherAppsVolumeIsDeniedWith404(): void
    {
        $controller = $this->controller(self::OWNER);

        $this->expectException(AppAccessDenied::class);
        $controller->setMode($this->post('/backups/mode', [
            'volume' => 'waha_data',
            'mode' => BackupSelection::MODE_SYNC,
        ]));
    }

    public function testOrphanVolumeIsAdminOnly(): void
    {
        $member = $this->controller(self::OWNER);
        try {
            $member->setMode($this->post('/backups/mode', [
                'volume' => 'ghost_data',
                'mode' => BackupSelection::MODE_SYNC,
            ]));
            $this->fail('volume yatim harus ditolak untuk non-admin');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
        }

        $admin = $this->controller(self::ADMIN);
        $payload = $this->json($admin->setMode($this->post('/backups/mode', [
            'volume' => 'ghost_data',
            'mode' => BackupSelection::MODE_SNAPSHOT,
        ])));
        $this->assertSame(0, $payload['code']);
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, (new BackupSelection($this->db))->modeOf('ghost_data'));
    }

    public function testUnknownVolumeIs404(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->setMode($this->post('/backups/mode', [
            'volume' => 'tidak_ada_data',
            'mode' => BackupSelection::MODE_SYNC,
        ]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(404, json_decode($response->rawBody(), true)['code']);
    }

    /**
     * Volume DB dashboard bukan target backup app (§8i.7) ⇒ 404 walau namanya
     * valid.
     */
    public function testDashboardVolumeIsNotFound(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->setMode($this->post('/backups/mode', [
            'volume' => 'rames',
            'mode' => BackupSelection::MODE_SYNC,
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Validasi 422
    // ------------------------------------------------------------------

    public function testInvalidModeValueIs422WithoutMutation(): void
    {
        $controller = $this->controller(self::OWNER);

        foreach (['', 'bulanan', 'SNAPSHOT', '1'] as $mode) {
            $response = $controller->setMode($this->post('/backups/mode', [
                'volume' => 'tonidata_data',
                'mode' => $mode,
            ]));

            $this->assertSame(422, $response->getStatusCode(), 'mode "' . $mode . '" harus 422');
            $payload = json_decode($response->rawBody(), true);
            $this->assertSame(422, $payload['code']);
            $this->assertSame('Nilai mode harus snapshot atau sync.', $payload['msg']);
        }

        $this->assertSame(
            BackupSelection::MODE_SNAPSHOT,
            (new BackupSelection($this->db))->modeOf('tonidata_data'),
            'mode invalid tidak menulis apa pun'
        );
    }

    public function testInvalidVolumeNameIs422(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->setMode($this->post('/backups/mode', [
            'volume' => 'bad/../name',
            'mode' => BackupSelection::MODE_SYNC,
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Nama volume tidak valid.', json_decode($response->rawBody(), true)['msg']);
    }

    public function testEngineFailureIsReportedAs500(): void
    {
        $controller = $this->controller(self::ADMIN);
        $controller->engineError = new RuntimeException('socket tidak tersedia');

        $response = $controller->setMode($this->post('/backups/mode', [
            'volume' => 'tonidata_data',
            'mode' => BackupSelection::MODE_SYNC,
        ]));

        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(500, $payload['code']);
        $this->assertStringContainsString('Docker Engine', (string) $payload['msg']);
        $this->assertStringContainsString('socket tidak tersedia', (string) $payload['msg']);
    }

    /**
     * Route `POST /backups/mode` terdaftar dekat `/backups/schedule` (audit
     * statik — tanpa menjalankan router).
     */
    public function testRouteIsRegistered(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/config/route.php');

        $this->assertStringContainsString(
            "Route::post('/backups/mode', [BackupOverviewController::class, 'setMode']);",
            $routes
        );
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?array $user): FakeBackupModeController
    {
        $controller = new FakeBackupModeController();
        $controller->user = $user;
        $controller->admin = (($user['role'] ?? '') === 'admin');
        $controller->dbPath = $this->db;
        $controller->volumes = [
            ['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']],
            ['Name' => 'waha_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'waha']],
            ['Name' => 'ghost_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'ghost']],
            ['Name' => 'rames', 'Labels' => [
                VolumeTargetMap::LABEL_PROJECT => 'rames',
                VolumeTargetMap::DASHBOARD_VOLUME_LABEL_KEY => VolumeTargetMap::DASHBOARD_VOLUME_LABEL_VALUE,
            ]],
        ];

        return $controller;
    }

    /** @return array<string,mixed> */
    private function json(\Webman\Http\Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,string> $fields
     */
    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Requested-With: XMLHttpRequest\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /** @param array<string,mixed> $overrides */
    private function useConfig(array $overrides = []): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(array_merge([
                'sqlite_file' => $this->db,
                'database_path' => $this->tmp,
                'billing_enabled' => true,
                'billing_rate_backup_per_gb_month' => 1000.0,
                'billing_backup_min_gb' => 1.0,
                'billing_min_deposit_days' => 30,
            ], $overrides), true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
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

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

/**
 * Subclass seam: user login disuntik tanpa session HTTP dan target Engine
 * (volume → app) di-override sehingga matriks hak `setMode()` teruji **tanpa**
 * Docker Engine nyata (pola `FakeBackupScheduleController`).
 */
class FakeBackupModeController extends BackupOverviewController
{
    public ?array $user = null;

    public bool $admin = false;

    /** @var array<int,array<string,mixed>> */
    public array $volumes = [];

    public ?string $dbPath = null;

    public ?Throwable $engineError = null;

    protected function isAdmin(): bool
    {
        return $this->admin;
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function volumeTargets(): array
    {
        if ($this->engineError !== null) {
            throw $this->engineError;
        }

        return VolumeTargetMap::build($this->volumes, (new AppStore($this->dbPath))->all());
    }
}
