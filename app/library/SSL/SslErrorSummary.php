<?php
declare(strict_types=1);

namespace app\library\SSL;

/**
 * Ringkasan **satu baris** untuk galat SSL (`ssl_error`) — tabel `/domains`
 * hanya menampilkan versi pendek; teks lengkap certbot tetap disimpan di baris
 * dan dibuka lewat tombol **Log** (modal).
 *
 * Statik murni (tanpa I/O, tanpa state) supaya satu sumber ringkasan dipakai
 * render awal view (`DomainController::decorateRows()`) **dan** payload polling
 * (`/api/domains/status`, `/api/ssl/status`) — JS tidak perlu memotong teks
 * sendiri, sehingga ringkasan tidak pernah berbeda antara render awal dan
 * sesudah polling.
 *
 * Urutan aturan penting: kegagalan paling spesifik lebih dulu (batas laju,
 * CAA, konfigurasi), baru kelas tantangan HTTP-01 (DNS, timeout/koneksi, 403,
 * 404). Bila tidak ada pola yang cocok, teks dirapikan (prefix
 * "Gagal menerbitkan …" dibuang, spasi/baris baru dipadatkan) lalu dipotong
 * {@see self::MAX_LENGTH} karakter dengan penanda `…`.
 */
final class SslErrorSummary
{
    /** Batas panjang ringkasan (karakter, bukan byte) — sisanya dipotong `…`. */
    public const MAX_LENGTH = 80;

    /**
     * Pola (case-insensitive) → label ringkas. Label **tanpa** nama domain:
     * kolom Domain sudah menampilkannya, dan menjaga ringkasan tetap pendek.
     *
     * @var array<int,array{0:string,1:string}>
     */
    private const RULES = [
        ['/too many (?:failed authorizations|certificates|duplicate certificates)/i', "Batas laju Let's Encrypt tercapai"],
        ['/CAA record .* prevents/i', 'CAA domain melarang penerbitan'],
        ['/ADMIN_EMAIL belum diatur/i', 'ADMIN_EMAIL belum diatur'],
        ['/no valid (?:A|AAAA) records|NXDOMAIN|DNS problem/i', 'DNS domain belum mengarah ke server ini'],
        ['/Timeout during connect/i', 'Port 80 tidak menjawab (firewall?)'],
        ['/Connection refused/i', 'Koneksi ke port 80 ditolak'],
        ['/\b403\b/', 'Nginx menolak berkas challenge (403)'],
        ['/\b404\b/', 'Berkas challenge tidak ditemukan (404)'],
    ];

    /**
     * Ringkasan pendek galat SSL. Teks kosong/whitespace ⇒ string kosong
     * (view/JS memperlakukan itu sebagai "tidak ada galat").
     */
    public static function short(?string $error): string
    {
        $text = trim((string) $error);
        if ($text === '') {
            return '';
        }

        foreach (self::RULES as [$pattern, $label]) {
            if (preg_match($pattern, $text) === 1) {
                return $label;
            }
        }

        // Fallback: buang prefix yang selalu sama (tidak informatif di tabel),
        // padatkan baris baru/spasi ganda, lalu potong.
        $flat = (string) preg_replace(
            '/^(?:Gagal (?:menerbitkan|revoke) sertifikat (?:SSL )?untuk)\s+[^:]+:\s*/u',
            '',
            $text
        );
        $flat = trim((string) preg_replace('/\s+/u', ' ', $flat));
        if ($flat === '') {
            return '';
        }

        return mb_strimwidth($flat, 0, self::MAX_LENGTH, '…', 'UTF-8');
    }
}
