<?php
declare(strict_types=1);

namespace Tests;

use app\library\Domain\DomainFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit test DomainFilter — filter `?domain=` pada halaman `/domains`
 * (SPECS §8j): substring case-insensitive pada key `domain`, input
 * dinormalisasi (trim + lowercase), tanpa I/O & tanpa otorisasi (penyaringan
 * hak akses tetap milik `DomainController`/`AppAccess`).
 */
class DomainFilterTest extends TestCase
{
    public function testNormalizeMemangkasDanLowercase(): void
    {
        $this->assertSame('example.org', DomainFilter::normalize('  EXAMPLE.org '));
    }

    public function testNormalizeMenolakNilaiNonSkalar(): void
    {
        $this->assertSame('', DomainFilter::normalize(['x']), 'array (?domain[]=x) bukan filter');
        $this->assertSame('', DomainFilter::normalize(null));
        $this->assertSame('123', DomainFilter::normalize(123), 'skalar tetap dipakai sebagai kata kunci');
    }

    public function testFilterNonAktifSaatKosong(): void
    {
        $this->assertFalse(DomainFilter::isActive(''));
        $this->assertFalse(DomainFilter::isActive('   '));
        $this->assertTrue(DomainFilter::isActive('contoh'));
    }

    public function testApplyMencocokkanSubstringCaseInsensitive(): void
    {
        $rows = [
            ['domain' => 'disbuk.example.org'],
            ['domain' => 'LAIN.example.com'],
            ['domain' => 'parkir.example.org'],
            ['domain' => 'unrelated.test'],
        ];

        $this->assertSame(
            ['disbuk.example.org', 'parkir.example.org'],
            array_column(DomainFilter::apply($rows, 'EXAMPLE.ORG'), 'domain')
        );
    }

    public function testApplyTanpaKataKunciMengembalikanSemuaDanReindex(): void
    {
        $rows = [
            'a' => ['domain' => 'a.test'],
            'b' => ['domain' => 'b.test'],
        ];

        $result = DomainFilter::apply($rows, '');

        $this->assertSame(array_keys($result), [0, 1], 'hasil selalu list (reindex)');
        $this->assertCount(2, $result);
        $this->assertSame('a.test', $result[0]['domain']);
    }

    public function testApplyMembuangEntriBukanArray(): void
    {
        $rows = [['domain' => 'a.test'], 'rusak', null, ['domain' => 'b.test']];

        $this->assertSame(
            ['a.test', 'b.test'],
            array_column(DomainFilter::apply($rows, ''), 'domain')
        );
    }

    public function testEntriTanpaKeyDomainAmanDanTidakCocok(): void
    {
        $rows = [['kind' => 'app'], ['domain' => 'a.test']];

        $result = DomainFilter::apply($rows, 'a.test');

        $this->assertCount(1, $result, 'entri tanpa key domain hanya lolos saat filter kosong');
        $this->assertSame('a.test', $result[0]['domain']);
        $this->assertCount(2, DomainFilter::apply($rows, ''));
    }

    public function testFilterUnicodeAman(): void
    {
        $rows = [['domain' => 'BÜKU.example.org'], ['domain' => 'lain.test']];

        $this->assertCount(1, DomainFilter::apply($rows, 'büku'));
    }
}
