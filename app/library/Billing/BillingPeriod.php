<?php
declare(strict_types=1);

namespace app\library\Billing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Util periode tagihan `YYYY-MM` — satu sumber kebenaran nama periode.
 *
 * Statik murni (tanpa state & tanpa I/O selain pembacaan `config()`): timezone
 * diambil dari `config('app.default_timezone')` (Asia/Jakarta) supaya batas
 * bulan konsisten dengan zona tampilan dashboard, bukan timezone proses PHP.
 */
final class BillingPeriod
{
    /** @var array<int,string> nama bulan Bahasa Indonesia (1 = Januari). */
    private const MONTHS = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * Periode berjalan `YYYY-MM` untuk `$now` (ISO 8601 / tanggal), atau
     * waktu sekarang bila `$now` null/kosong.
     */
    public static function current(?string $now = null): string
    {
        return self::parse($now)->format('Y-m');
    }

    /**
     * Tanggal (1–31) pada bulan berjalan untuk `$now` — dipakai penjadwal untuk
     * menghormati `BILLING_INVOICE_DAY`.
     */
    public static function dayOfMonth(?string $now = null): int
    {
        return (int) self::parse($now)->format('j');
    }

    /**
     * Label periode yang ramah dibaca, mis. "Oktober 2026".
     */
    public static function label(string $period): string
    {
        [$year, $month] = self::split($period);

        return self::MONTHS[$month] . ' ' . $year;
    }

    /**
     * Apakah periode `$a` lebih awal dari `$b`. Format `YYYY-MM` terurut
     * leksikografis, jadi perbandingan string aman (tanpa parsing tambahan).
     */
    public static function isBefore(string $a, string $b): bool
    {
        self::split($a);
        self::split($b);

        return strcmp($a, $b) < 0;
    }

    /**
     * Periode sebelumnya (`2026-01` → `2025-12`).
     */
    public static function previous(string $period): string
    {
        [$year, $month] = self::split($period);

        return self::at($year, $month)->modify('-1 month')->format('Y-m');
    }

    /**
     * Awal periode berikutnya sebagai ISO 8601, mis. "2026-11-01T00:00:00+07:00".
     */
    public static function nextPeriodStart(string $period): string
    {
        [$year, $month] = self::split($period);

        return self::at($year, $month)->modify('+1 month')->format(DATE_ATOM);
    }

    /**
     * Jumlah hari dalam periode (`2026-02` → 28/29).
     */
    public static function daysIn(string $period): int
    {
        [$year, $month] = self::split($period);

        return (int) self::at($year, $month)->format('t');
    }

    /**
     * Parse `YYYY-MM` → [tahun, bulan] dengan validasi ketat.
     *
     * @return array{0:int,1:int}
     */
    private static function split(string $period): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($period), $matches) !== 1) {
            throw new InvalidArgumentException('Format periode tidak valid: "' . $period . '" (harus YYYY-MM).');
        }
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Bulan tidak valid pada periode "' . $period . '".');
        }

        return [$year, $month];
    }

    private static function parse(?string $now): DateTimeImmutable
    {
        $timezone = self::timezone();
        if ($now === null || trim($now) === '') {
            return new DateTimeImmutable('now', $timezone);
        }

        return new DateTimeImmutable($now, $timezone);
    }

    private static function at(int $year, int $month): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            self::timezone()
        );
    }

    private static function timezone(): DateTimeZone
    {
        $name = trim((string) config('app.default_timezone', 'Asia/Jakarta'));
        if ($name === '') {
            $name = 'Asia/Jakarta';
        }
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone('Asia/Jakarta');
        }
    }
}
