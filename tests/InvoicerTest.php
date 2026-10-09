<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\Invoicer;
use app\library\Storage\AppStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test Invoicer — penagihan periode (idempoten), rincian item, pruning app
 * hilang, dan kebijakan stop. Path temp; tanpa Docker & tanpa data runtime.
 */
class InvoicerTest extends TestCase
{
    private string $tmp;
    private AppStore $apps;
    private BillingStore $billing;
    private UserStore $users;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/invoicer_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        file_put_contents($this->tmp . '/auth.json', json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]));

        $this->apps = new AppStore($this->tmp . '/apps.json');
        $this->billing = new BillingStore($this->tmp . '/billing.json');
        $this->users = new UserStore($this->tmp . '/auth.json');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function invoicer(?callable $stopApps = null): Invoicer
    {
        return new Invoicer($this->apps, $this->billing, $this->users, $stopApps);
    }

    private function account(): CreditAccount
    {
        return new CreditAccount($this->billing, $this->users);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function seedUsage(string $appId, array $row): void
    {
        $this->billing->update(function (array &$data) use ($appId, $row): void {
            $data['usage'][$appId] = $row;
        });
    }

    private function runningApp(string $name, string $owner, array $limits = []): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => $owner,
            'status' => 'running',
            'limits' => $limits,
        ]);
    }

    public function testRunDueChargesResetsAndIsIdempotent(): void
    {
        $app = $this->runningApp('myapp', 'u2', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);
        $this->account()->deposit('u2', 1000.0, 'u1', 'awal');
        $this->seedUsage($app['id'], [
            'name' => 'myapp',
            'owner_id' => 'u2',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 120.0,
            'sampled_at' => '2026-09-30T00:00:00+07:00',
        ]);

        $result = $this->invoicer()->runDue('2026-10-01T00:05:00+07:00');

        $this->assertCount(1, $result);
        $this->assertSame('u2', $result[0]['user_id']);
        $this->assertSame('2026-09', $result[0]['period']);
        $this->assertSame(120.0, $result[0]['amount']);
        $this->assertFalse($result[0]['stopped']);
        $this->assertSame(880.0, $this->account()->balance('u2'));

        $row = $this->billing->usage()[$app['id']];
        $this->assertSame('2026-10', $row['period']);
        $this->assertSame(0, $row['seconds_pending']);
        $this->assertSame(0.0, $row['credits_pending']);

        // Idempoten: run kedua tidak memotong lagi.
        $this->assertSame([], $this->invoicer()->runDue('2026-10-02T00:05:00+07:00'));
        $this->assertSame(880.0, $this->account()->balance('u2'));
    }

    public function testItemsDetailPerApp(): void
    {
        $app = $this->runningApp('detail', 'u2', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);
        $this->seedUsage($app['id'], [
            'name' => 'detail',
            'owner_id' => 'u2',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 120.0,
            'sampled_at' => '2026-09-30T00:00:00+07:00',
        ]);

        $result = $this->invoicer()->runDue('2026-10-01T00:05:00+07:00');
        $item = $result[0]['items'][0];

        $this->assertSame($app['id'], $item['app_id']);
        $this->assertSame('detail', $item['app_name']);
        $this->assertEqualsWithDelta(1.0, $item['hours'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $item['cpus'], 0.0001);
        $this->assertSame(1024, $item['memory_mb']);
        $this->assertEqualsWithDelta(120.0, $item['hourly'], 0.0001);
        $this->assertSame(120.0, $item['amount']);
    }

    public function testDeletedAppIsStillChargedThenRemoved(): void
    {
        $this->account()->deposit('u2', 500.0, 'u1', 'awal');
        $this->seedUsage('ghost1', [
            'name' => 'ghost',
            'owner_id' => 'u2',
            'period' => '2026-09',
            'seconds_pending' => 1800,
            'credits_pending' => 50.0,
            'sampled_at' => '2026-09-30T00:00:00+07:00',
        ]);

        $result = $this->invoicer()->runDue('2026-10-01T00:05:00+07:00');

        $this->assertSame(50.0, $result[0]['amount']);
        $this->assertSame(450.0, $this->account()->balance('u2'));
        $this->assertArrayNotHasKey('ghost1', $this->billing->usage());
    }

    public function testRowWithUnknownOwnerIsSkippedUntouched(): void
    {
        $this->seedUsage('orphan1', [
            'name' => 'orphan',
            'owner_id' => 'nouser',
            'period' => '2026-09',
            'seconds_pending' => 60,
            'credits_pending' => 10.0,
            'sampled_at' => '2026-09-30T00:00:00+07:00',
        ]);

        $this->assertSame([], $this->invoicer()->runDue('2026-10-01T00:05:00+07:00'));
        $this->assertArrayHasKey('orphan1', $this->billing->usage());
    }

    public function testRunNowClosesGivenPeriod(): void
    {
        $app = $this->runningApp('nowapp', 'u2');
        $this->account()->deposit('u2', 100.0, 'u1', 'awal');
        $this->seedUsage($app['id'], [
            'name' => 'nowapp',
            'owner_id' => 'u2',
            'period' => '2026-10',
            'seconds_pending' => 1800,
            'credits_pending' => 30.0,
            'sampled_at' => '2026-10-15T00:00:00+07:00',
        ]);

        $result = $this->invoicer()->runNow('2026-10', '2026-10-15T10:00:00+07:00');

        $this->assertSame(30.0, $result[0]['amount']);
        $this->assertSame('2026-10', $result[0]['period']);
        $this->assertSame(70.0, $this->account()->balance('u2'));
        $this->assertSame(0.0, $this->billing->usage()[$app['id']]['credits_pending']);

        // Periode sudah kosong: run kedua tidak memotong lagi (amount 0).
        $again = $this->invoicer()->runNow('2026-10', '2026-10-15T10:00:00+07:00');
        $this->assertSame(0.0, $again[0]['amount']);
        $this->assertSame(70.0, $this->account()->balance('u2'));
    }

    public function testRunNowRejectsInvalidPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->invoicer()->runNow('oktober-2026');
    }

    public function testStopPolicyInvokesCallbackForNegativeBalance(): void
    {
        $app = $this->runningApp('bleeding', 'u2');
        $this->runningApp('already-stopped', 'u2', []);
        $this->apps->update($this->apps->findByName('already-stopped')['id'], function (array &$a): void {
            $a['status'] = 'stopped';
        });
        $this->seedUsage($app['id'], [
            'name' => 'bleeding',
            'owner_id' => 'u2',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 100.0,
            'sampled_at' => '2026-09-30T00:00:00+07:00',
        ]);

        $called = [];
        $invoicer = $this->invoicer(function (string $userId, array $apps) use (&$called): void {
            $called[] = [$userId, array_map(static fn (array $a): string => (string) $a['name'], $apps)];
        });

        $result = $invoicer->runDue('2026-10-01T00:05:00+07:00');

        $this->assertTrue($result[0]['stopped']);
        $this->assertSame(1, $result[0]['apps_stopped']);
        $this->assertSame([['u2', ['bleeding']]], $called);
        $this->assertSame(-100.0, $this->account()->balance('u2'));
    }

    public function testWithoutCallbackStopIsNotRecorded(): void
    {
        $app = $this->runningApp('nop', 'u2');
        $this->seedUsage($app['id'], [
            'name' => 'nop',
            'owner_id' => 'u2',
            'period' => '2026-09',
            'seconds_pending' => 3600,
            'credits_pending' => 10.0,
            'sampled_at' => '2026-09-30T00:00:00+07:00',
        ]);

        $result = $this->invoicer()->runDue('2026-10-01T00:05:00+07:00');

        $this->assertFalse($result[0]['stopped']);
        $this->assertSame(0, $result[0]['apps_stopped']);
    }
}
