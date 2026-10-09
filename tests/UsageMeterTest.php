<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\UsageMeter;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test UsageMeter — akrual tick per app (app running non-admin saja),
 * memakai berkas basis data SQLite temp (tanpa data runtime nyata).
 */
class UsageMeterTest extends TestCase
{
    private const NOW = '2026-10-14T09:30:00+07:00';

    private string $tmp;
    private AppStore $apps;
    private BillingStore $billing;
    private UsageMeter $meter;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/usagemeter_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        $db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->apps = new AppStore($db);
        $this->billing = new BillingStore($db);
        $this->meter = new UsageMeter(
            $this->apps,
            $this->billing,
            new UserStore($db)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function app(string $name, string $owner, string $status = 'running', array $limits = []): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => $owner,
            'status' => $status,
            'limits' => $limits,
        ]);
    }

    public function testAccruesOnlyRunningMemberApps(): void
    {
        $member = $this->app('memberapp', 'u2');
        $this->app('adminapp', 'u1');
        $this->app('stoppedapp', 'u2', 'stopped');

        $accrued = $this->meter->sample(300, self::NOW);

        $this->assertSame([$member['id']], $accrued);
        $usage = $this->billing->usage();
        $this->assertArrayHasKey($member['id'], $usage);
        $this->assertCount(1, $usage, 'app admin & stopped tidak diakru');
    }

    public function testAccrualUsesDefaultUnitForEmptyLimits(): void
    {
        $app = $this->app('no-limits', 'u2');

        $this->meter->sample(300, self::NOW);
        $row = $this->billing->usage()[$app['id']];

        $this->assertSame('2026-10', $row['period']);
        $this->assertSame(300, $row['seconds_pending']);
        $this->assertEqualsWithDelta(5.0, $row['credits_pending'], 0.0001, '300s × 60/jam');
        $this->assertSame(self::NOW, $row['sampled_at']);
        $this->assertSame('u2', $row['owner_id']);
        $this->assertSame('no-limits', $row['name']);
    }

    public function testAccrualUsesAppLimits(): void
    {
        $app = $this->app('big', 'u2', 'running', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);

        $this->meter->sample(300, self::NOW);

        // 1 core + 1 GB → 120/jam → 300s = 10.0
        $this->assertEqualsWithDelta(10.0, $this->billing->usage()[$app['id']]['credits_pending'], 0.0001);
    }

    public function testSamplesAccumulate(): void
    {
        $app = $this->app('acc', 'u2');

        $this->meter->sample(300, self::NOW);
        $this->meter->sample(300, self::NOW);
        $row = $this->billing->usage()[$app['id']];

        $this->assertSame(600, $row['seconds_pending']);
        $this->assertEqualsWithDelta(10.0, $row['credits_pending'], 0.0001);
    }

    public function testNonPositiveSecondsIsNoOp(): void
    {
        $this->app('app', 'u2');

        $this->assertSame([], $this->meter->sample(0, self::NOW));
        $this->assertSame([], $this->meter->sample(-5, self::NOW));
        $this->assertSame([], $this->billing->usage());
    }

    public function testOwnerMissingFromAuthIsSkipped(): void
    {
        $this->app('orphan', 'ghost');

        $this->assertSame([], $this->meter->sample(300, self::NOW));
        $this->assertSame([], $this->billing->usage());
    }

    public function testSummaryShape(): void
    {
        $app = $this->app('sum', 'u2');
        $this->meter->sample(300, self::NOW);

        $summary = $this->meter->summary();
        $this->assertArrayHasKey($app['id'], $summary);
        $row = $summary[$app['id']];

        $this->assertSame('u2', $row['owner_id']);
        $this->assertSame('sum', $row['name']);
        $this->assertSame(300, $row['seconds_pending']);
        $this->assertEqualsWithDelta(5.0, $row['credits_pending'], 0.0001);
        $this->assertEqualsWithDelta(60.0, $row['hourly'], 0.0001);
        // Oktober = 31 hari → 60 × 24 × 31
        $this->assertEqualsWithDelta(44640.0, $row['estimate_month'], 0.0001);
    }
}
