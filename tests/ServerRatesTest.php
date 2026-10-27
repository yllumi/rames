<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\Pricing;
use app\library\Billing\ServerRates;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Test `ServerRates` — resolusi tarif per server, fallback tarif global,
 * `forApp()` berdasarkan `server_id`, dan cache **per instance** (bukan statik).
 *
 * Store server memakai berkas SQLite temp; tidak menyentuh registry runtime.
 */
class ServerRatesTest extends TestCase
{
    private string $db;
    private ServerStore $servers;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->db = sys_get_temp_dir() . '/rames-server-rates-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->servers = new ServerStore($this->db);
        $this->servers->create([
            'id' => 's1',
            'name' => 's1',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://10.0.0.1:8443',
            'token' => 'tok-rahasia-s1',
            'rates' => ['cpu' => 250, 'ram' => 50, 'backup' => 400],
        ]);
        $this->servers->create([
            'id' => 's2',
            'name' => 's2',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://10.0.0.2:8443',
            'token' => 'tok-rahasia-s2',
            // Tanpa rates ⇒ tarif global.
        ]);
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        foreach (glob($this->db . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function rates(?ServerStore $store = null): ServerRates
    {
        return new ServerRates($store ?? $this->servers);
    }

    public function testRatesForServerWithOverrides(): void
    {
        $this->assertSame(['cpu' => 250.0, 'ram' => 50.0], $this->rates()->ratesFor('s1'));
        $this->assertSame(400.0, $this->rates()->backupMonthlyFor('s1'));
    }

    public function testServerWithoutRatesFallsBackToGlobal(): void
    {
        $this->assertSame(Pricing::rates(), $this->rates()->ratesFor('s2'));
        $this->assertSame(Pricing::backupMonthlyRate(), $this->rates()->backupMonthlyFor('s2'));
    }

    public function testUnknownOrEmptyServerFallsBackToGlobal(): void
    {
        foreach (['', '   ', 'ghost'] as $id) {
            $this->assertSame(Pricing::rates(), $this->rates()->ratesFor($id));
            $this->assertSame(Pricing::backupMonthlyRate(), $this->rates()->backupMonthlyFor($id));
        }
    }

    public function testForAppUsesAppServerIdAndNeverExposesSecrets(): void
    {
        $rates = $this->rates();
        $full = $rates->forApp(['id' => 'a1', 'server_id' => 's1']);

        $this->assertSame(
            ['cpu' => 250.0, 'ram' => 50.0, 'backup' => 400.0],
            $full
        );
        $this->assertArrayNotHasKey('token', $full);
        $this->assertStringNotContainsString('tok-rahasia', implode('|', array_map('strval', $full)));

        // App lama tanpa `server_id` = server lokal (baris seed tanpa rates).
        $this->assertSame(
            ['cpu' => 100.0, 'ram' => 20.0, 'backup' => 1000.0],
            $rates->forApp(['id' => 'a2'])
        );
    }

    public function testCacheIsPerInstanceNotStatic(): void
    {
        $first = $this->rates();
        $this->assertSame(['cpu' => 250.0, 'ram' => 50.0], $first->ratesFor('s1'));

        // Ubah tarif di store setelah cache terisi.
        $this->servers->update('s1', static function (array &$row): void {
            $row['rates'] = ['cpu' => 7, 'ram' => 8, 'backup' => 9];
        });

        $this->assertSame(
            ['cpu' => 250.0, 'ram' => 50.0],
            $first->ratesFor('s1'),
            'instance lama memakai cache-nya sendiri'
        );

        $second = $this->rates();
        $this->assertSame(['cpu' => 7.0, 'ram' => 8.0], $second->ratesFor('s1'));
        $this->assertSame(9.0, $second->backupMonthlyFor('s1'));
    }

    public function testBrokenRegistryFallsBackToGlobalRates(): void
    {
        $broken = new ServerRates(new ServerStore('/tidak/ada/direktori/rames.sqlite'));

        $this->assertSame(Pricing::rates(), $broken->ratesFor('s1'));
        $this->assertSame(Pricing::backupMonthlyRate(), $broken->backupMonthlyFor('s1'));
    }
}
