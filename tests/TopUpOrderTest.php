<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\BillingStore;
use app\library\Billing\TopUpOrder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test TopUpOrder — pembuatan order, kuota pending, expiry lokal, transisi
 * status, dan idempotensi settle (+ kredit atomik lewat callback transisi).
 * Semua di path temp; tidak menyentuh `database/billing.json` nyata.
 */
class TopUpOrderTest extends TestCase
{
    private string $tmp;
    private string $path;
    private BillingStore $store;
    private TopUpOrder $orders;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/topuporder_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/rames.sqlite';
        $this->store = new BillingStore($this->path);
        $this->orders = new TopUpOrder($this->store);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function minIdr(): int
    {
        return (int) config('deploy.billing_topup_min_idr', 10000);
    }

    private function maxIdr(): int
    {
        return (int) config('deploy.billing_topup_max_idr', 5000000);
    }

    public function testCreateBuildsPendingOrder(): void
    {
        $order = $this->orders->create('u2', 50000, 'bc');

        $this->assertMatchesRegularExpression('/^RM-[0-9a-f]{16}$/', $order['id']);
        $this->assertSame('u2', $order['user_id']);
        $this->assertSame(50000, $order['amount_idr']);
        $this->assertSame(round(50000 / (float) config('deploy.billing_topup_idr_per_credit', 1.0), 2), $order['credits']);
        $this->assertSame('BC', $order['method']);
        $this->assertSame('pending', $order['status']);
        $this->assertNotSame('', $order['created_at']);
        $this->assertArrayNotHasKey('expires_at', $order, 'expiry 0 = tanpa expires_at');
        $this->assertLessThanOrEqual(50, strlen($order['id']));
    }

    public function testDefaultRateIsOneToOne(): void
    {
        // Config uji tidak memuat kunci kurs → produksi memakai fallback 1.0,
        // jadi Rp1 = 1 kredit (kurs 1:1 — default SPECS §7.12).
        $order = $this->orders->create('u2', 25000, 'BC');

        $this->assertSame(25000.0, $order['credits']);
    }

    public function testCreateRejectsAmountOutsideBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->orders->create('u2', $this->minIdr() - 1, 'BC');
    }

    public function testCreateRejectsAmountAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->orders->create('u2', $this->maxIdr() + 1, 'BC');
    }

    public function testCreateRejectsInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->orders->create('u2', 50000, 'TOOLONG');
    }

    public function testCreateEnforcesMaxPending(): void
    {
        $max = max(1, (int) config('deploy.billing_topup_max_pending', 3));
        for ($i = 0; $i < $max; $i++) {
            $this->orders->create('u2', 20000, 'BC');
        }

        try {
            $this->orders->create('u2', 20000, 'BC');
            $this->fail('Semestinya menolak order pending berlebih');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }

        // User lain tidak terpengaruh.
        $this->assertNotNull($this->orders->create('u3', 20000, 'BC'));
    }

    public function testCreateExpiresStaleBeforeCounting(): void
    {
        $max = max(1, (int) config('deploy.billing_topup_max_pending', 3));
        for ($i = 0; $i < $max; $i++) {
            $id = 'RM-' . str_pad((string) $i, 16, 'a');
            $this->store->update(function (array &$data) use ($id): void {
                $data['orders'][$id] = [
                    'id' => $id,
                    'user_id' => 'u2',
                    'amount_idr' => 20000,
                    'credits' => 2000.0,
                    'method' => 'BC',
                    'status' => 'pending',
                    'created_at' => date('c', time() - 7200),
                    'expires_at' => date('c', time() - 3600),
                ];
            });
        }

        $order = $this->orders->create('u2', 20000, 'BC');
        $this->assertSame('pending', $order['status']);
        // Order lama kini expired.
        $this->assertSame(1, count($this->orders->pendingForUser('u2')));
    }

    public function testAttachPaymentStoresProvidedFields(): void
    {
        $order = $this->orders->create('u2', 50000, 'VA');
        $updated = $this->orders->attachPayment($order['id'], [
            'statusCode' => '00',
            'reference' => 'REF-9',
            'paymentUrl' => 'https://duitku.test/pay/9',
            'vaNumber' => '88081234',
            'qrString' => '',
        ]);

        $this->assertSame('REF-9', $updated['reference']);
        $this->assertSame('https://duitku.test/pay/9', $updated['payment_url']);
        $this->assertSame('88081234', $updated['va_number']);
        $this->assertArrayNotHasKey('qr_string', $updated, 'field kosong tidak disimpan');
    }

    public function testFindForUserGuardsOwnership(): void
    {
        $order = $this->orders->create('u2', 50000, 'BC');

        $this->assertNotNull($this->orders->find($order['id']));
        $this->assertNotNull($this->orders->findForUser($order['id'], 'u2'));
        $this->assertNull($this->orders->findForUser($order['id'], 'u3'));
        $this->assertNull($this->orders->find('RM-tidak-ada'));
    }

    public function testPendingAndAllPending(): void
    {
        $a = $this->orders->create('u2', 20000, 'BC');
        $this->orders->create('u3', 20000, 'BC');
        $this->orders->settle($a['id']);

        $this->assertSame([], $this->orders->pendingForUser('u2'));
        $this->assertCount(1, $this->orders->pendingForUser('u3'));
        $this->assertCount(1, $this->orders->allPending());
    }

    public function testSettleIsIdempotentAndAtomic(): void
    {
        $order = $this->orders->create('u2', 50000, 'BC');
        $calls = 0;

        $settled = $this->orders->settle($order['id'], 'REF-1', null, function (array $o, array &$data) use (&$calls): void {
            $calls++;
            $data['users'][$o['user_id']] = ['balance' => 5000.0, 'updated_at' => date('c'), 'ledger' => [['type' => 'topup']]];
        });

        $this->assertSame('paid', $settled['status']);
        $this->assertSame('REF-1', $settled['reference']);
        $this->assertSame(1, $calls);

        // Panggilan kedua = no-op: status tetap paid, callback tidak dipanggil lagi.
        $again = $this->orders->settle($order['id'], 'REF-1', null, function () use (&$calls): void {
            $calls++;
        });
        $this->assertSame('paid', $again['status']);
        $this->assertSame(1, $calls, 'transisi kedua tidak memicu kredit');
        $this->assertSame(5000.0, $this->store->users()['u2']['balance']);
    }

    public function testSettleLateCallbackAfterLocalExpiryStillSettles(): void
    {
        $order = $this->orders->create('u2', 50000, 'BC');
        $this->orders->markExpired($order['id']);

        $settled = $this->orders->settle($order['id'], 'REF-LATE');

        $this->assertSame('paid', $settled['status'], 'callback valid yang telat tetap disettle (uang nyata)');
    }

    public function testMarkFailedDoesNotDowngradePaid(): void
    {
        $order = $this->orders->create('u2', 50000, 'BC');
        $this->orders->settle($order['id']);

        $this->assertSame('paid', $this->orders->markFailed($order['id'], 'stale callback')['status']);
    }

    public function testExpireStaleAndMarkExpired(): void
    {
        $order = $this->orders->create('u2', 50000, 'BC');

        $this->assertSame(0, $this->orders->expireStale(), 'order tanpa expires_at tidak kedaluwarsa');

        // Paksa expires_at ke masa lalu.
        $this->store->update(function (array &$data) use ($order): void {
            $data['orders'][$order['id']]['expires_at'] = date('c', time() - 60);
        });
        $this->assertSame(1, $this->orders->expireStale());
        $this->assertSame('expired', $this->orders->find($order['id'])['status']);

        // markExpired hanya dari pending.
        $other = $this->orders->create('u2', 20000, 'BC');
        $this->assertSame('expired', $this->orders->markExpired($other['id'])['status']);
    }

    public function testTouchCheckedStoresTimestamp(): void
    {
        $order = $this->orders->create('u2', 50000, 'BC');
        $this->orders->touchChecked($order['id'], '2026-10-09T12:00:00+07:00');

        $this->assertSame('2026-10-09T12:00:00+07:00', $this->orders->find($order['id'])['last_check_at']);
        // Order tak dikenal = no-op, tanpa melempar.
        $this->orders->touchChecked('RM-tidak-ada');
        $this->assertTrue(true);
    }
}
