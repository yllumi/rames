<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\BillingPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test util periode `YYYY-MM` (BillingPeriod) — tanpa I/O & tanpa data runtime.
 */
class BillingPeriodTest extends TestCase
{
    public function testCurrentFromIsoAndDateOnly(): void
    {
        $this->assertSame('2026-10', BillingPeriod::current('2026-10-14T09:30:00+07:00'));
        $this->assertSame('2026-11', BillingPeriod::current('2026-11-01'));
        $this->assertSame('2026-10', BillingPeriod::current('2026-10-31T23:59:59+07:00'));
    }

    public function testCurrentWithoutNowUsesToday(): void
    {
        $this->assertSame(date('Y-m'), BillingPeriod::current());
        $this->assertSame(date('Y-m'), BillingPeriod::current(''));
    }

    public function testLabelUsesIndonesianMonthNames(): void
    {
        $this->assertSame('Oktober 2026', BillingPeriod::label('2026-10'));
        $this->assertSame('Januari 2025', BillingPeriod::label('2025-01'));
        $this->assertSame('Desember 2026', BillingPeriod::label('2026-12'));
    }

    public function testIsBefore(): void
    {
        $this->assertTrue(BillingPeriod::isBefore('2026-09', '2026-10'));
        $this->assertFalse(BillingPeriod::isBefore('2026-10', '2026-10'));
        $this->assertFalse(BillingPeriod::isBefore('2026-11', '2026-10'));
        $this->assertTrue(BillingPeriod::isBefore('2025-12', '2026-01'));
    }

    public function testPreviousHandlesYearBoundary(): void
    {
        $this->assertSame('2026-09', BillingPeriod::previous('2026-10'));
        $this->assertSame('2025-12', BillingPeriod::previous('2026-01'));
    }

    public function testNextPeriodStart(): void
    {
        $this->assertSame('2026-11-01T00:00:00+07:00', BillingPeriod::nextPeriodStart('2026-10'));
        $this->assertSame('2027-01-01T00:00:00+07:00', BillingPeriod::nextPeriodStart('2026-12'));
    }

    public function testDaysIn(): void
    {
        $this->assertSame(31, BillingPeriod::daysIn('2026-10'));
        $this->assertSame(28, BillingPeriod::daysIn('2026-02'));
        $this->assertSame(29, BillingPeriod::daysIn('2024-02'));
        $this->assertSame(30, BillingPeriod::daysIn('2026-04'));
    }

    public function testInvalidPeriodIsRejected(): void
    {
        foreach (['2026/10', '26-10', '2026-13', '2026-00', '', 'oktober'] as $bad) {
            try {
                BillingPeriod::label($bad);
                $this->fail('Periode tidak valid seharusnya ditolak: ' . $bad);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
