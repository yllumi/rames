<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\UserStore;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupSelection;
use app\library\Backup\VolumeTargetMap;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\Pricing;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Throwable;
use Webman\Config;

/**
 * Test mediator `BackupController::setScheduled()` (POST /backups/schedule) —
 * matriks hak `backup` + gerbang kredit (PLAN_VOLUME_BACKUP.md §4.2, §7.12).
 *
 * Tanpa HTTP, tanpa Docker: target Engine di-override lewat seam
 * `FakeBackupScheduleController::volumeTargets()` (`protected` sengaja, satu-
 * satunya jalur endpoint ini yang menyentuh Engine), user login lewat seam
 * `currentUser()`, dan semua store diarahkan ke basis data SQLite temp
 * (`deploy.sqlite_file`) — `database/` & `runtime/` nyata tidak tersentuh.
 *
 * Membuktikan: owner/operator boleh (viewer/stranger/yatim → 404), gerbang
 * kredit hanya saat menyalakan (402 + tanpa mutasi), app milik admin bebas
 * kredit, `BILLING_ENABLED=false` bebas gerbang, bentuk respons sukses, dan
 * baris `status()`/`refresh()` memuat `can_schedule`/`backup_gb`/
 * `backup_credits_month`.
 */
class BackupScheduleBillingTest extends TestCase
{
    private const OWNER = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    private const OPERATOR = ['id' => 'u2', 'username' => 'operator', 'role' => 'member'];
    private const VIEWER = ['id' => 'u3', 'username' => 'viewer', 'role' => 'member'];
    private const STRANGER = ['id' => 'u8', 'username' => 'stranger', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    /** Biaya: 2,5 GB × 1.000 kredit/GB/bulan × (30/30) = 2.500 kredit. */
    private const REQUIRED = 2500.0;
    private const CHARGED_GB = 2.5;

    private string $tmp;
    private string $db;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-bksched-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'owner', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'operator', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u3', 'username' => 'viewer', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u7', 'username' => 'owner-lain', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u8', 'username' => 'stranger', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u9', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
        ]);

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
            // App milik admin global → bebas kredit untuk siapa pun (§7.12).
            ['id' => 'app3', 'name' => 'adminsite', 'owner_id' => 'u9', 'members' => ['u2' => ['role' => 'operator']]],
        ]);

        SqliteFixture::backup($this->db, ['registry' => [
            'tonidata_data' => ['app_id' => 'app1', 'bytes' => self::bytes(2.5), 'snapshots' => 2],
            'waha_data' => ['app_id' => 'app2', 'bytes' => self::bytes(2.5), 'snapshots' => 1],
            'adminsite_data' => ['app_id' => 'app3', 'bytes' => self::bytes(2.5), 'snapshots' => 1],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // Matriks hak
    // ------------------------------------------------------------------

    public function testOwnerMemberCanEnableAndDisableOwnVolume(): void
    {
        $this->fund('u1', 10_000.0);
        $controller = $this->controller(self::OWNER);

        $enable = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $enable['code']);
        $this->assertTrue($enable['data']['scheduled']);
        $this->assertEqualsWithDelta(self::CHARGED_GB, (float) $enable['data']['backup_gb'], 0.0001);
        $this->assertEqualsWithDelta(self::REQUIRED, (float) $enable['data']['required'], 0.0001);
        $this->assertArrayHasKey('explicit', $enable['data'], 'kunci lama dipertahankan');
        $this->assertArrayHasKey('volume', $enable['data']);

        $selection = new BackupSelection($this->db);
        $this->assertTrue($selection->isScheduled('tonidata_data'), 'seleksi tersimpan ON');

        $disable = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '0',
        ])));

        $this->assertSame(0, $disable['code']);
        $this->assertFalse($disable['data']['scheduled']);
        $this->assertFalse((new BackupSelection($this->db))->isScheduled('tonidata_data'), 'seleksi tersimpan OFF');
    }

    public function testOperatorOfSharedAppCanEnable(): void
    {
        // Operator menyalakan fitur berbayar pada app milik member lain ⇒ saldo
        // operator **dan** pemilik app (penanggung biaya akrual) wajib cukup.
        $this->fund('u2', 10_000.0);
        $this->fund('u1', 10_000.0);
        $controller = $this->controller(self::OPERATOR);

        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);

        // Jejak audit ringan menyimpan pelaku (seam user, bukan session).
        $stored = SqliteFixture::readAll($this->db, 'backup');
        $this->assertSame('u2', $stored['volumes']['tonidata_data']['updated_by'] ?? null);
    }

    /**
     * Penolakan otorisasi = `AppAccessDenied` (handler merender **404**, bukan
     * 403) dan **tanpa** efek samping (seleksi tidak berubah).
     */
    public function testViewerIsDeniedWith404AndNoMutation(): void
    {
        $controller = $this->controller(self::VIEWER);

        try {
            $controller->setScheduled($this->post('/backups/schedule', [
                'volume' => 'tonidata_data',
                'scheduled' => '1',
            ]));
            $this->fail('viewer harus ditolak (AppAccessDenied → 404)');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('backup', $e->ability);
        }

        $this->assertFalse((new BackupSelection($this->db))->isScheduled('tonidata_data'));
    }

    public function testStrangerIsDeniedWith404(): void
    {
        $controller = $this->controller(self::STRANGER);

        $this->expectException(AppAccessDenied::class);
        $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ]));
    }

    public function testOtherAppsVolumeIsDeniedWith404(): void
    {
        // u1 owner app1, bukan siapa-siapa di app2 (waha).
        $this->fund('u1', 10_000.0);
        $controller = $this->controller(self::OWNER);

        $this->expectException(AppAccessDenied::class);
        $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'waha_data',
            'scheduled' => '1',
        ]));
    }

    public function testOrphanVolumeIsAdminOnly(): void
    {
        $member = $this->controller(self::OWNER);
        try {
            $member->setScheduled($this->post('/backups/schedule', [
                'volume' => 'ghost_data',
                'scheduled' => '1',
            ]));
            $this->fail('volume yatim harus ditolak untuk non-admin');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
        }

        // Admin tetap boleh (regresi perilaku lama) — tanpa biaya (tak ada app).
        $admin = $this->controller(self::ADMIN);
        $payload = $this->json($admin->setScheduled($this->post('/backups/schedule', [
            'volume' => 'ghost_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);
        $this->assertSame(0.0, (float) $payload['data']['backup_gb']);
        $this->assertSame(0.0, (float) $payload['data']['required']);
    }

    public function testAdminCanEnableAnyVolumeWithoutGate(): void
    {
        $controller = $this->controller(self::ADMIN);

        // Admin bersaldo 0 tapi tetap boleh (exempt).
        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'waha_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);
    }

    /**
     * Volume DB dashboard bukan target backup app (§8i.7) ⇒ 404 walau namanya
     * valid, tidak boleh muncul lewat endpoint ini.
     */
    public function testDashboardVolumeIsNotFound(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'rames',
            'scheduled' => '1',
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Gerbang kredit
    // ------------------------------------------------------------------

    public function testEnablingWithoutCreditsReturns402AndDoesNotMutate(): void
    {
        $controller = $this->controller(self::OWNER); // saldo 0

        $response = $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ]));

        $this->assertSame(402, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(402, $payload['code']);
        $this->assertNotSame('', trim((string) ($payload['error'] ?? '')), 'pesan saldo/kebutuhan terisi');
        $this->assertArrayNotHasKey('msg', $payload, 'kontrak gerbang memakai kunci `error`');

        $this->assertFalse(
            (new BackupSelection($this->db))->isScheduled('tonidata_data'),
            'gerbang gagal TIDAK boleh mengubah seleksi'
        );
    }

    public function testEnablingWithExactDepositSucceeds(): void
    {
        $this->fund('u1', self::REQUIRED);
        $controller = $this->controller(self::OWNER);

        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code'], 'saldo pas deposit minimum = boleh');
    }

    public function testDisablingNeverHitsTheGate(): void
    {
        $selection = new BackupSelection($this->db);
        $selection->setScheduled('tonidata_data', true, 'u1');

        $controller = $this->controller(self::OWNER); // saldo 0
        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '0',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertFalse($payload['data']['scheduled']);
        $this->assertFalse((new BackupSelection($this->db))->isScheduled('tonidata_data'));
    }

    /**
     * App milik admin bebas kredit **untuk siapa pun** — operator member dengan
     * saldo 0 tetap boleh menyalakan backup volume app tersebut (§7.12).
     */
    public function testAdminOwnedAppSkipsGateForMemberOperator(): void
    {
        $controller = $this->controller(self::OPERATOR);

        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'adminsite_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);
    }

    public function testBillingDisabledSkipsGate(): void
    {
        $this->useConfig(['billing_enabled' => false]);
        $controller = $this->controller(self::OWNER); // saldo 0

        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);
    }

    // ------------------------------------------------------------------
    // Gerbang penanggung biaya (owner) — §7.12
    // ------------------------------------------------------------------

    /**
     * Operator bersaldo **cukup** tidak boleh menyalakan fitur berbayar pada app
     * milik member lain yang saldonya **kurang**: biaya diakru ke owner, jadi
     * owner-lah penanggung biaya yang harus punya saldo (402 + pesan khusus +
     * seleksi tidak berubah).
     */
    public function testOperatorWithCreditsCannotEnableWhenOwnerHasNone(): void
    {
        $this->fund('u2', 10_000.0); // operator (aktor) saldo cukup
        $controller = $this->controller(self::OPERATOR); // u1 (owner app1) saldo 0

        $response = $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ]));

        $this->assertSame(402, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(402, $payload['code']);
        $error = (string) ($payload['error'] ?? '');
        $this->assertStringContainsString('pemilik app', $error, 'pesan menyebut pemilik app (penanggung biaya)');
        $this->assertStringContainsString('Kredit', $error);
        $this->assertStringContainsString(Pricing::format(0.0), $error, 'saldo owner (0,00) disebut');
        $this->assertStringContainsString(Pricing::format(self::REQUIRED), $error, 'minimum disebut');

        $this->assertFalse(
            (new BackupSelection($this->db))->isScheduled('tonidata_data'),
            'gerbang gagal TIDAK boleh mengubah seleksi'
        );
        $this->assertSame([], SqliteFixture::readAll($this->db, 'backup')['volumes'] ?? []);
    }

    /**
     * Aktor == owner (penanggung biaya) ⇒ perilaku lama dipertahankan: satu
     * gerbang, pesan default ("Saldo kredit Anda …"), bukan pesan pemilik app.
     */
    public function testOwnerActorKeepsDefaultMessage(): void
    {
        $controller = $this->controller(self::OWNER); // saldo 0

        $response = $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ]));

        $this->assertSame(402, $response->getStatusCode());
        $error = (string) (json_decode($response->rawBody(), true)['error'] ?? '');
        $this->assertStringContainsString('Saldo kredit Anda', $error);
        $this->assertStringNotContainsString('pemilik app', $error);
    }

    /**
     * Billing mati ⇒ tanpa gerbang sama sekali (termasuk untuk jalur penanggung
     * biaya owner pada app yang dibagikan).
     */
    public function testBillingDisabledSkipsPayerGateOnSharedApp(): void
    {
        $this->useConfig(['billing_enabled' => false]);
        $controller = $this->controller(self::OPERATOR); // u2 & u1 saldo 0

        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);
    }

    /**
     * Aktor admin bebas kredit ⇒ lewat sebelum penanggung biaya dinilai (owner
     * app member bersaldo 0 pun tidak menghalangi admin).
     */
    public function testAdminActorSkipsPayerGateEvenWhenOwnerHasNoCredits(): void
    {
        $controller = $this->controller(self::ADMIN);

        $payload = $this->json($controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ])));

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['scheduled']);
    }

    // ------------------------------------------------------------------
    // Validasi & kegagalan Engine
    // ------------------------------------------------------------------

    public function testInvalidScheduledValueIs422WithoutMutation(): void
    {
        $this->fund('u1', 10_000.0);
        $controller = $this->controller(self::OWNER);

        foreach (['', '2', 'true'] as $raw) {
            $response = $controller->setScheduled($this->post('/backups/schedule', [
                'volume' => 'tonidata_data',
                'scheduled' => $raw,
            ]));

            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(422, json_decode($response->rawBody(), true)['code']);
        }

        $this->assertFalse((new BackupSelection($this->db))->isScheduled('tonidata_data'));
    }

    public function testInvalidVolumeNameIs422(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'bad/../name',
            'scheduled' => '1',
        ]));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testEngineFailureIsReportedAs500(): void
    {
        $controller = $this->controller(self::ADMIN);
        $controller->engineError = new RuntimeException('socket tidak tersedia');

        $response = $controller->setScheduled($this->post('/backups/schedule', [
            'volume' => 'tonidata_data',
            'scheduled' => '1',
        ]));

        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(500, $payload['code']);
        $this->assertStringContainsString('Docker Engine', (string) $payload['msg']);
        $this->assertStringContainsString('socket tidak tersedia', (string) $payload['msg']);
    }

    // ------------------------------------------------------------------
    // Kontrak baris status()/refresh()
    // ------------------------------------------------------------------

    /**
     * `status()`/`refresh()` wajib meneruskan app + user ke `withScheduled()`
     * (tanpa itu `can_schedule` selalu false / biaya selalu 0). Audit statik:
     * panggilan `withScheduled` tidak boleh hanya berargumen satu.
     */
    public function testStatusAndRefreshEnrichRowsWithAccessAndCost(): void
    {
        foreach (['status', 'refresh'] as $method) {
            $body = $this->methodBody($method);

            self::assertStringContainsString(
                'self::withScheduled($visible, null, $apps, $user)',
                $body,
                "BackupController::{$method}() tidak meneruskan app + user ke withScheduled()"
            );
            self::assertStringContainsString('$this->currentUser()', $body);
        }
    }

    /**
     * `setScheduled()` tidak boleh lagi memakai gerbang admin-only `is_admin()`
     * — hak ditegakkan satu pintu `BackupAccess` (owner/operator).
     */
    public function testSetScheduledDoesNotUseAdminOnlyGate(): void
    {
        $body = $this->methodBody('setScheduled');

        self::assertStringNotContainsString('is_admin()', $body);
        self::assertStringNotContainsString('$this->isAdmin()', $body);
        self::assertStringContainsString("BackupAccess::require('backup'", $body);
        self::assertStringContainsString('assertCanScheduleBackup', $body);
        self::assertStringContainsString('InsufficientCredits $e', $body);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function methodBody(string $method): string
    {
        $reflection = new ReflectionMethod(BackupController::class, $method);
        $lines = file(dirname(__DIR__) . '/app/controller/BackupController.php') ?: [];
        $length = $reflection->getEndLine() - $reflection->getStartLine() + 1;

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $length));
    }

    private function controller(?array $user, bool $admin = false): FakeBackupScheduleController
    {
        $controller = new FakeBackupScheduleController();
        $controller->user = $user;
        $controller->admin = $admin || (($user['role'] ?? '') === 'admin');
        $controller->dbPath = $this->db;
        $controller->volumes = [
            ['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']],
            ['Name' => 'waha_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'waha']],
            ['Name' => 'adminsite_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'adminsite']],
            ['Name' => 'ghost_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'ghost']],
            // Volume DB dashboard: ber-label compose tapi dikecualikan (§8i.7).
            ['Name' => 'rames', 'Labels' => [
                VolumeTargetMap::LABEL_PROJECT => 'rames',
                VolumeTargetMap::DASHBOARD_VOLUME_LABEL_KEY => VolumeTargetMap::DASHBOARD_VOLUME_LABEL_VALUE,
            ]],
        ];

        return $controller;
    }

    private function fund(string $userId, float $amount): void
    {
        (new CreditAccount(new BillingStore($this->db), new UserStore($this->db)))
            ->deposit($userId, $amount, 'u9', 'saldo uji');
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

    /** Byte untuk `$gb` GB (konvensi `BackupUsage::BYTES_PER_GB`). */
    private static function bytes(float $gb): int
    {
        return (int) round($gb * 1073741824);
    }

    /**
     * @param array<string,mixed> $overrides
     */
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
 * (volume → app) di-override sehingga matriks hak & gerbang kredit teruji
 * **tanpa** Docker Engine nyata (pola `FakeCreditController`/`FakeBackupDbController`).
 */
class FakeBackupScheduleController extends BackupController
{
    public ?array $user = null;

    public bool $admin = false;

    /** @var array<int,array<string,mixed>> respons tiruan `DockerClient::listVolumes()` */
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
