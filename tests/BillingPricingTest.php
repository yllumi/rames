<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\Pricing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testFormatUsesTwoDecimals(): void
    {
        $this->assertSame('1234.50', Pricing::format(1234.5));
        $this->assertSame('-3.46', Pricing::format(-3.456));
        $this->assertSame('0.00', Pricing::format(0.0));
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
