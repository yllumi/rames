<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test CreditAccount — saldo, deposit/adjust, potongan, dan ledger.
 * Semua store memakai path temp (tanpa menyentuh data runtime nyata).
 */
class CreditAccountTest extends TestCase
{
    private string $tmp;
    private BillingStore $billing;
    private CreditAccount $account;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/creditacct_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        file_put_contents($this->tmp . '/auth.json', json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]));

        $this->billing = new BillingStore($this->tmp . '/billing.json');
        $this->account = new CreditAccount($this->billing, new UserStore($this->tmp . '/auth.json'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testInitialBalanceIsZero(): void
    {
        $this->assertSame(0.0, $this->account->balance('u2'));
        $this->assertSame(0.0, $this->account->balance('tidak-ada'));
        $this->assertSame([], $this->account->ledger('u2'));
    }

    public function testDepositAddsBalanceAndLedgerEntry(): void
    {
        $entry = $this->account->deposit('u2', 100.5, 'u1', 'Deposit awal');

        $this->assertSame('deposit', $entry['type']);
        $this->assertSame(100.5, $entry['amount']);
        $this->assertSame(100.5, $entry['balance_after']);
        $this->assertSame('u1', $entry['by']);
        $this->assertNotEmpty($entry['id']);
        $this->assertSame(100.5, $this->account->balance('u2'));

        $ledger = $this->account->ledger('u2');
        $this->assertCount(1, $ledger);
        $this->assertSame($entry['id'], $ledger[0]['id']);
    }

    public function testTopUpDepositCarriesReference(): void
    {
        $entry = $this->account->deposit('u2', 50.0, 'u2', 'Top up Duitku', 'topup', 'INV-123');

        $this->assertSame('topup', $entry['type']);
        $this->assertSame('INV-123', $entry['reference']);
    }

    public function testAdjustMayBeNegative(): void
    {
        $this->account->deposit('u2', 100.0, 'u1', 'awal');
        $entry = $this->account->deposit('u2', -25.0, 'u1', 'koreksi', 'adjust');

        $this->assertSame('adjust', $entry['type']);
        $this->assertSame(-25.0, $entry['amount']);
        $this->assertSame(75.0, $this->account->balance('u2'));
    }

    public function testChargeDeductsAndRecordsNegativeAmount(): void
    {
        $this->account->deposit('u2', 100.0, 'u1', 'awal');
        $items = [['app_id' => 'a1', 'app_name' => 'myapp', 'hours' => 1.0, 'amount' => 30.25]];

        $entry = $this->account->charge('u2', 30.25, $items, '2026-10', 'tagihan');

        $this->assertSame('charge', $entry['type']);
        $this->assertSame(-30.25, $entry['amount']);
        $this->assertSame(69.75, $entry['balance_after']);
        $this->assertSame('2026-10', $entry['period']);
        $this->assertSame($items, $entry['items']);
        $this->assertSame(69.75, $this->account->balance('u2'));
    }

    public function testChargeMayDriveBalanceNegative(): void
    {
        $entry = $this->account->charge('u2', 12.5, [], '2026-10');

        $this->assertSame(-12.5, $entry['balance_after']);
        $this->assertSame(-12.5, $this->account->balance('u2'));
    }

    public function testLedgerNewestFirstRespectsLimit(): void
    {
        foreach ([1.0, 2.0, 3.0] as $amount) {
            $this->account->deposit('u2', $amount, 'u1', 'n' . $amount);
        }

        $all = $this->account->ledger('u2', 0);
        $this->assertCount(3, $all);
        $this->assertSame(3.0, $all[0]['amount'], 'terbaru dulu');
        $this->assertSame(1.0, $all[2]['amount']);

        $limited = $this->account->ledger('u2', 2);
        $this->assertCount(2, $limited);
        $this->assertSame(3.0, $limited[0]['amount']);
    }

    public function testAllBalances(): void
    {
        $this->account->deposit('u2', 10.0, 'u1', 'x');
        $this->account->charge('u1', 4.0, [], '2026-10');

        $this->assertSame(['u2' => 10.0, 'u1' => -4.0], $this->account->allBalances());
    }

    public function testDepositRejectsInvalidAmountsAndUsers(): void
    {
        $invalid = [
            fn () => $this->account->deposit('u2', 0.0, 'u1', 'nol'),
            fn () => $this->account->deposit('u2', -5.0, 'u1', 'negatif'),
            fn () => $this->account->deposit('u2', 5.0, '', 'tanpa by'),
            fn () => $this->account->deposit('u2', 5.0, 'u1', 'x', 'tak-dikenal'),
            fn () => $this->account->deposit('u2', 10000001.0, 'u1', 'melebihi cap'),
        ];
        foreach ($invalid as $i => $call) {
            try {
                $call();
                $this->fail('Input tidak valid seharusnya ditolak (index ' . $i . ')');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testDepositUnknownUserThrowsRuntime(): void
    {
        $this->expectException(RuntimeException::class);
        $this->account->deposit('ghost', 5.0, 'u1', 'x');
    }

    public function testChargeRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->account->charge('u2', -1.0, [], '2026-10');
    }

    public function testChargeUnknownUserThrowsRuntime(): void
    {
        $this->expectException(RuntimeException::class);
        $this->account->charge('ghost', 1.0, [], '2026-10');
    }
}
