<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\Pricing;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

/**
 * Test perhitungan harga kredit (Pricing) — statik murni, tanpa I/O.
 *
 * Tarif default: CPU 100/core-jam, RAM 20/GB-jam, default 0.5 core & 512 MB
 * (dipakai karena config runtime tidak dimuat di luar webman).
 */
class BillingPricingTest extends TestCase
{
    /** @var array{cpu:float,ram:float} */
    private array $rates = ['cpu' => 100.0, 'ram' => 20.0];

    /** @var array<string,mixed> */
    private array $configState = [];

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->tmp . '/' . $entry);
                }
            }
            @rmdir($this->tmp);
        }
    }

    /** Muat config temp untuk menguji pembacaan `deploy.*` (pola BillingRunnerTest). */
    private function useConfig(array $deploy): void
    {
        $this->tmp = sys_get_temp_dir() . '/pricing_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export($deploy, true) . ';' . PHP_EOL
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

    public function testDefaultRatesFallback(): void
    {
        $this->assertSame(['cpu' => 100.0, 'ram' => 20.0], Pricing::rates());
    }

    public function testHourlyCreditsForService(): void
    {
        $this->assertEqualsWithDelta(
            120.0,
            Pricing::hourlyCreditsForService(1.0, 1024, $this->rates),
            0.0001
        );
        $this->assertEqualsWithDelta(
            60.0,
            Pricing::hourlyCreditsForService(0.5, 512, $this->rates),
            0.0001
        );
        $this->assertEqualsWithDelta(
            0.0,
            Pricing::hourlyCreditsForService(0.0, 0, $this->rates),
            0.0001
        );
    }

    public function testEmptyLimitsBillOneDefaultUnit(): void
    {
        $this->assertEqualsWithDelta(60.0, Pricing::hourlyCredits([], $this->rates), 0.0001);
    }

    public function testHourlyCreditsSumsAllServicesWithFallbacks(): void
    {
        $limits = [
            'web' => ['cpus' => 1.0, 'memory_mb' => 1024], // 120
            'db' => ['cpus' => null, 'memory_mb' => null],  // 60 (default)
            'cache' => ['cpus' => 2.0, 'memory_mb' => null], // 200 + 10 = 210
        ];

        $this->assertEqualsWithDelta(390.0, Pricing::hourlyCredits($limits, $this->rates), 0.0001);
    }

    public function testEstimateAndRequiredDeposit(): void
    {
        $this->assertEqualsWithDelta(43200.0, Pricing::estimate([], $this->rates, 30), 0.0001);
        $this->assertEqualsWithDelta(43200.0, Pricing::requiredDeposit([], $this->rates, 30), 0.0001);
        $this->assertEqualsWithDelta(0.0, Pricing::requiredDeposit([], $this->rates, 0), 0.0001);
        $this->assertEqualsWithDelta(
            100000.0,
            Pricing::requiredDeposit([], $this->rates, 30, 100000.0),
            0.0001,
            'minCredits menaikkan kebutuhan minimum'
        );
        $this->assertEqualsWithDelta(0.0, Pricing::estimate([], $this->rates, -5), 0.0001);
    }

    // ------------------------------------------------------------------
    // Tarif per server (overlay opsional; tanpa argumen = perilaku lama)
    // ------------------------------------------------------------------

    public function testServerRatesOverlayFullAndPartial(): void
    {
        $this->assertSame(
            ['cpu' => 250.0, 'ram' => 50.0],
            Pricing::rates(['id' => 's1', 'rates' => ['cpu' => 250, 'ram' => 50]])
        );

        // Hanya cpu diisi ⇒ ram tetap tarif global.
        $this->assertSame(
            ['cpu' => 250.0, 'ram' => 20.0],
            Pricing::rates(['rates' => ['cpu' => '250']])
        );

        // Key asing diabaikan.
        $this->assertSame(
            ['cpu' => 100.0, 'ram' => 20.0],
            Pricing::rates(['rates' => ['disk' => 999]])
        );
    }

    public function testServerRatesInvalidValuesFallBackToGlobal(): void
    {
        foreach ([0, -5, '0', '-1', 'abc', null, [] ] as $bad) {
            $this->assertSame(
                ['cpu' => 100.0, 'ram' => 20.0],
                Pricing::rates(['rates' => ['cpu' => $bad, 'ram' => $bad]]),
                'nilai ' . var_export($bad, true) . ' tidak boleh membuat app gratis'
            );
        }
    }

    public function testServerBackupRateOverlayAndFallback(): void
    {
        $this->assertSame(400.0, Pricing::backupMonthlyRate(['rates' => ['backup' => 400]]));
        $this->assertSame(1000.0, Pricing::backupMonthlyRate(['rates' => ['backup' => 0]]));
        $this->assertSame(1000.0, Pricing::backupMonthlyRate(['rates' => ['backup' => -2]]));
        $this->assertSame(1000.0, Pricing::backupMonthlyRate(['rates' => []]));
        $this->assertSame(1000.0, Pricing::backupMonthlyRate());
    }

    public function testServerRatesRespectGlobalConfigAsBase(): void
    {
        $this->useConfig([
            'billing_rate_cpu_per_core_hour' => 70.0,
            'billing_rate_ram_per_gb_hour' => 7.0,
            'billing_rate_backup_per_gb_month' => 300.0,
        ]);

        $this->assertSame(['cpu' => 70.0, 'ram' => 7.0], Pricing::rates());
        $this->assertSame(300.0, Pricing::backupMonthlyRate());
        $this->assertSame(
            ['cpu' => 140.0, 'ram' => 7.0],
            Pricing::rates(['rates' => ['cpu' => 140]])
        );
        $this->assertSame(600.0, Pricing::backupMonthlyRate(['rates' => ['backup' => 600]]));
    }

    public function testFormatUsesTwoDecimals(): void
    {
        $this->assertSame('1234.50', Pricing::format(1234.5));
        $this->assertSame('-3.46', Pricing::format(-3.456));
        $this->assertSame('0.00', Pricing::format(0.0));
    }

    public function testBackupMonthlyRateDefault(): void
    {
        $this->assertSame(1000.0, Pricing::backupMonthlyRate());
    }

    public function testBackupMonthlyEstimate(): void
    {
        $this->assertSame(1000.0, Pricing::backupMonthlyEstimate(1.0));
        $this->assertSame(1500.0, Pricing::backupMonthlyEstimate(1.5));
        $this->assertSame(0.0, Pricing::backupMonthlyEstimate(-2.0), 'GB negatif dianggap 0');
        $this->assertSame(500.0, Pricing::backupMonthlyEstimate(1.0, 500.0), 'tarif eksplisit');
    }

    public function testBackupCreditsFullMonthEqualsRate(): void
    {
        // 31 hari penuh (Oktober) ⇒ gb × tarif.
        $this->assertEqualsWithDelta(
            2000.0,
            Pricing::backupCredits(2.0, 31 * 86400, 31),
            0.0001
        );
        // Satu tick 300 detik dari 30 hari: 1 GB ⇒ 1000 × 300/2592000.
        $this->assertEqualsWithDelta(
            1000.0 * 300 / 2592000,
            Pricing::backupCredits(1.0, 300, 30),
            0.0001
        );
        $this->assertEqualsWithDelta(
            1500.0,
            Pricing::backupCredits(3.0, 30 * 86400, 30, 500.0),
            0.0001,
            'tarif eksplisit 500/GB/bulan'
        );
    }

    public function testBackupCreditsGuardsInvalidInput(): void
    {
        $this->assertSame(0.0, Pricing::backupCredits(-1.0, 86400, 30), 'GB negatif ⇒ 0');
        $this->assertSame(0.0, Pricing::backupCredits(1.0, 0.0, 30), 'tanpa detik ⇒ 0');
        $this->assertEqualsWithDelta(
            1000.0,
            Pricing::backupCredits(1.0, 86400, 0),
            0.0001,
            'periode 0 hari diperlakukan 1 hari (tanpa pembagian nol)'
        );
    }

    public function testBackupRateFromConfigAndFallbacks(): void
    {
        $this->useConfig(['billing_rate_backup_per_gb_month' => 250.0]);
        $this->assertSame(250.0, Pricing::backupMonthlyRate());
        $this->assertSame(500.0, Pricing::backupMonthlyEstimate(2.0));

        // Nilai tidak masuk akal (0/negatif/bukan angka) ⇒ fallback 1000.
        $this->useConfig(['billing_rate_backup_per_gb_month' => 0.0]);
        $this->assertSame(1000.0, Pricing::backupMonthlyRate());

        $this->useConfig(['billing_rate_backup_per_gb_month' => -5.0]);
        $this->assertSame(1000.0, Pricing::backupMonthlyRate());
    }

    public function testAssertWithinCapsAcceptsBoundary(): void
    {
        Pricing::assertWithinCaps(['web' => ['cpus' => 4.0, 'memory_mb' => 8192]]);
        Pricing::assertWithinCaps(['web' => ['cpus' => null, 'memory_mb' => null]]);
        Pricing::assertWithinCaps([]);
        $this->assertTrue(true);
    }

    public function testAssertWithinCapsRejectsCpuAboveCap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/melebihi plafon/');
        Pricing::assertWithinCaps(['web' => ['cpus' => 4.5, 'memory_mb' => 512]]);
    }

    public function testAssertWithinCapsRejectsMemoryAboveCap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/plafon/');
        Pricing::assertWithinCaps(['db' => ['cpus' => 1.0, 'memory_mb' => 9000]]);
    }
}
