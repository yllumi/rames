<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Backup\BackupRegistry;
use app\library\Billing\BackupUsage;
use app\library\Billing\BillingGate;
use app\library\Billing\BillingStore;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\CreditAccount;
use app\library\Billing\Invoicer;
use app\library\Billing\ServerRates;
use app\library\Billing\UsageMeter;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Wiring **tarif per server** ke meteran & penagihan: dua app identik di dua
 * server berbeda harus ditagih tarif server masing-masing (CPU/RAM & backup),
 * dan baris usage tetap menyimpan `server_id`.
 *
 * Store SQLite temp; tanpa probe Engine/agent (summary & invoicing saja).
 */
class BillingServerRateWiringTest extends TestCase
{
    private const NOW = '2026-10-01T00:05:00+07:00';

    private string $tmp;
    private string $db;
    private AppStore $apps;
    private BillingStore $billing;
    private UserStore $users;
    private ServerStore $servers;
    private ServerRates $rates;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/rames-rate-wiring-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->apps = new AppStore($this->db);
        $this->billing = new BillingStore($this->db);
        $this->users = new UserStore($this->db);
        $this->servers = new ServerStore($this->db);
        $this->servers->create([
            'id' => 'srv-a',
            'name' => 'srv-a',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://10.0.0.1:8443',
            'token' => 'tok-a',
            'rates' => ['cpu' => 200, 'ram' => 40, 'backup' => 500],
        ]);
        $this->servers->create([
            'id' => 'srv-b',
            'name' => 'srv-b',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://10.0.0.2:8443',
            'token' => 'tok-b',
            'rates' => ['cpu' => 300, 'ram' => 60, 'backup' => 900],
        ]);

        $this->rates = new ServerRates($this->servers);
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function app(string $name, string $serverId): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => 'u2',
            'status' => 'running',
            'server_id' => $serverId,
            'limits' => ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]],
        ]);
    }

    private function seedUsage(string $appId, array $row): void
    {
        $this->billing->update(function (array &$data) use ($appId, $row): void {
            $data['usage'][$appId] = $row;
        });
    }

    public function testUsageSummaryUsesEachAppsServerRate(): void
    {
        $appA = $this->app('app-a', 'srv-a');
        $appB = $this->app('app-b', 'srv-b');

        $this->seedUsage($appA['id'], [
            'name' => 'app-a',
            'owner_id' => 'u2',
            'server_id' => 'srv-a',
            'period' => '2026-10',
            'seconds_pending' => 3600,
            'credits_pending' => 240.0,
            'backup_gb' => 2.0,
        ]);
        $this->seedUsage($appB['id'], [
            'name' => 'app-b',
            'owner_id' => 'u2',
            'server_id' => 'srv-b',
            'period' => '2026-10',
            'seconds_pending' => 3600,
            'credits_pending' => 360.0,
            'backup_gb' => 2.0,
        ]);

        $meter = new UsageMeter(
            $this->apps,
            $this->billing,
            $this->users,
            null,
            null,
            null,
            $this->rates
        );
        $summary = $meter->summary();

        // 1 core × 200 + 1 GB × 40 = 240/jam; server B 300 + 60 = 360/jam.
        $this->assertSame(240.0, $summary[$appA['id']]['hourly']);
        $this->assertSame(360.0, $summary[$appB['id']]['hourly']);
        $this->assertSame('srv-a', $summary[$appA['id']]['server_id']);
        $this->assertSame('srv-b', $summary[$appB['id']]['server_id']);

        // Backup: 2 GB × 500 vs 2 GB × 900.
        $this->assertSame(1000.0, $summary[$appA['id']]['backup_monthly']);
        $this->assertSame(1800.0, $summary[$appB['id']]['backup_monthly']);

        // Estimasi bulan berjalan = hourly × 24 × 31 + backup (Oktober).
        $this->assertSame(
            round(240.0 * 24 * 31 + 1000.0, 2),
            $summary[$appA['id']]['estimate_month']
        );
        $this->assertSame(
            round(360.0 * 24 * 31 + 1800.0, 2),
            $summary[$appB['id']]['estimate_month']
        );
    }

    public function testInvoicerItemsUseServerSnapshotRateAndKeepServerId(): void
    {
        $appA = $this->app('app-a', 'srv-a');
        $appB = $this->app('app-b', 'srv-b');

        $this->seedUsage($appA['id'], [
            'name' => 'app-a',
            'owner_id' => 'u2',
            'server_id' => 'srv-a',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 240.0,
            'backup_gb' => 1.5,
            'backup_credits_pending' => 25.0,
        ]);
        $this->seedUsage($appB['id'], [
            'name' => 'app-b',
            'owner_id' => 'u2',
            'server_id' => 'srv-b',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 360.0,
            'backup_gb' => 1.5,
            'backup_credits_pending' => 45.0,
        ]);

        (new CreditAccount($this->billing, $this->users))->deposit('u2', 5000.0, 'u1', 'seed');

        $invoicer = new Invoicer($this->apps, $this->billing, $this->users, null, $this->rates);
        $result = $invoicer->runNow('2026-09', self::NOW);

        $this->assertCount(1, $result, 'satu owner ⇒ satu entri ledger');
        $items = $result[0]['items'];

        $compute = [];
        $backup = [];
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'compute') {
                $compute[(string) $item['app_id']] = $item;
            }
            if (($item['kind'] ?? '') === 'backup') {
                $backup[(string) $item['app_id']] = $item;
            }
        }

        $this->assertSame(240.0, $compute[$appA['id']]['hourly']);
        $this->assertSame(360.0, $compute[$appB['id']]['hourly']);
        $this->assertSame('srv-a', $compute[$appA['id']]['server_id']);
        $this->assertSame('srv-b', $compute[$appB['id']]['server_id']);

        $this->assertSame(750.0, $backup[$appA['id']]['monthly'], '1.5 GB × 500');
        $this->assertSame(1350.0, $backup[$appB['id']]['monthly'], '1.5 GB × 900');
        $this->assertSame('srv-a', $backup[$appA['id']]['server_id']);
    }


    public function testBackupGateRequiresDepositAtTheTargetServerRate(): void
    {
        $account = new CreditAccount($this->billing, $this->users);
        $account->deposit('u2', 1500.0, 'u1', 'seed');
        $gate = new BillingGate(
            $account,
            null,
            $this->users,
            new BackupUsage(new BackupRegistry($this->db)),
            $this->rates
        );

        // srv-a: 2 GB × 500 = 1000 (saldo 1500 cukup).
        $gate->assertCanBackup(2.0, ['id' => 'u2', 'role' => 'member'], 'srv-a');
        $this->addToAssertionCount(1);

        // srv-b: 2 GB × 900 = 1800 ⇒ saldo kurang.
        try {
            $gate->assertCanBackup(2.0, ['id' => 'u2', 'role' => 'member'], 'srv-b');
            $this->fail('tarif backup server srv-b seharusnya memblokir');
        } catch (InsufficientCredits $e) {
            $this->assertSame(1800.0, $e->required);
        }
    }

    public function testRowsWithoutServerSnapshotFallBackToTheAppsCurrentServer(): void
    {
        $app = $this->app('app-a', 'srv-a');
        $this->seedUsage($app['id'], [
            'name' => 'app-a',
            'owner_id' => 'u2',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 240.0,
        ]);
        (new CreditAccount($this->billing, $this->users))->deposit('u2', 5000.0, 'u1', 'seed');

        $result = (new Invoicer($this->apps, $this->billing, $this->users, null, $this->rates))
            ->runNow('2026-09', self::NOW);

        $item = $result[0]['items'][0];
        $this->assertSame('srv-a', $item['server_id']);
        $this->assertSame(240.0, $item['hourly'], 'fallback server app saat ini');
    }
}
