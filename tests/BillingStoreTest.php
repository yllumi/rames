<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\BillingStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test BillingStore — persistensi store `billing` di basis data SQLite
 * (path temp, tanpa menyentuh data runtime nyata).
 */
class BillingStoreTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/billingstore_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
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

    public function testEmptyStoreReturnsDefaultStructure(): void
    {
        $store = new BillingStore($this->path);

        $this->assertSame($this->path, $store->path());
        $this->assertSame(['version' => 1, 'users' => [], 'usage' => [], 'orders' => []], $store->read());
        $this->assertSame([], $store->users());
        $this->assertSame([], $store->usage());
        $this->assertSame([], $store->orders());
    }

    public function testUpdatePersistsCanonicalStructure(): void
    {
        $store = new BillingStore($this->path);
        $store->update(function (array &$data): void {
            $data['users']['u2'] = ['balance' => 0.125, 'updated_at' => '', 'ledger' => []];
        });

        $raw = $store->read();
        $this->assertSame(1, $raw['version']);
        $this->assertSame(['version', 'users', 'usage', 'orders'], array_keys($raw));

        // Saldo dinormalisasi ke 2 desimal saat baca (0.125 → 0.13).
        $this->assertSame(0.13, $store->users()['u2']['balance']);
    }

    public function testMissingCollectionsAreNormalizedToDefaults(): void
    {
        // Store hanya berisi sebagian koleksi (mis. hasil impor yang tidak lengkap).
        SqliteFixture::billing($this->path, ['usage' => ['app1' => ['period' => '2026-10']]]);

        $store = new BillingStore($this->path);
        $store->update(function (array &$data): void {
            $data['orders']['ord1'] = ['status' => 'pending'];
        });

        $raw = $store->read();
        $this->assertSame(1, $raw['version']);
        $this->assertSame([], $raw['users']);
        $this->assertSame('app1', array_key_first($raw['usage']));
        $this->assertSame('pending', $raw['orders']['ord1']['status']);
    }

    public function testLedgerIsPrunedToKeepLimit(): void
    {
        // BILLING_LEDGER_KEEP default 200 (config tidak dimuat di luar webman).
        $ledger = [];
        for ($i = 1; $i <= 205; $i++) {
            $ledger[] = ['id' => (string) $i, 'amount' => $i];
        }
        SqliteFixture::billing($this->path, [
            'users' => ['u1' => ['balance' => 5, 'updated_at' => '', 'ledger' => $ledger]],
        ]);

        $store = new BillingStore($this->path);
        $kept = $store->users()['u1']['ledger'];

        $this->assertCount(200, $kept);
        $this->assertSame('6', $kept[0]['id'], 'entri terlama terbuang, sisakan terakhir');
        $this->assertSame('205', $kept[199]['id']);
    }

    public function testVersionIsAlwaysOne(): void
    {
        $store = new BillingStore($this->path);
        $store->update(function (array &$data): void {
            $data['version'] = 99; // diabaikan: versi dikelola BillingStore
            $data['orders']['ord1'] = ['status' => 'pending'];
        });

        $raw = $store->read();
        $this->assertSame(1, $raw['version']);
        $this->assertSame('pending', $raw['orders']['ord1']['status']);
    }

    /**
     * Akumulasi `backup_credits_pending` dinormalisasi **4 desimal** (bias
     * pembulatan per tick tidak boleh terakumulasi jadi drift), sedangkan
     * akumulasi CPU/RAM `credits_pending` tetap 2 desimal.
     */
    public function testBackupCreditsPendingKeepsFourDecimals(): void
    {
        SqliteFixture::billing($this->path, ['usage' => ['app1' => [
            'period' => '2026-11',
            'credits_pending' => 5.006,
            'backup_credits_pending' => 0.2893518518,
        ]]]);

        $store = new BillingStore($this->path);
        $row = $store->usage()['app1'];

        $this->assertSame(0.2894, $row['backup_credits_pending'], 'akumulasi backup 4 desimal (bukan 0,29)');
        $this->assertSame(5.01, $row['credits_pending'], 'akumulasi CPU/RAM tetap 2 desimal');

        // Tetap 4 desimal setelah tulis-baca ulang (normalisasi saat tulis).
        $store->update(function (array &$data): void {
            $data['usage']['app1']['name'] = 'app1';
        });

        $this->assertSame(0.2894, $store->usage()['app1']['backup_credits_pending']);
    }

    /**
     * Baris lama tanpa field backup tetap aman: normalisasi hanya men-cast
     * kunci yang **ada**, tidak pernah menyuntik kunci baru (kompatibilitas
     * data hasil impor versi sebelumnya).
     */
    public function testLegacyUsageRowWithoutBackupFieldsIsUntouched(): void
    {
        SqliteFixture::billing($this->path, ['usage' => ['app1' => [
            'period' => '2026-10',
            'seconds_pending' => 3600,
            'credits_pending' => 60,
        ]]]);

        $store = new BillingStore($this->path);
        $store->update(function (array &$data): void {
            $data['usage']['app1']['name'] = 'legacy';
        });

        $row = $store->usage()['app1'];
        $this->assertArrayNotHasKey('backup_gb', $row);
        $this->assertArrayNotHasKey('backup_credits_pending', $row);
        $this->assertSame(3600, $row['seconds_pending']);
        $this->assertSame(60.0, $row['credits_pending']);
        $this->assertSame('legacy', $row['name']);
    }
}
