<?php
declare(strict_types=1);

namespace Tests;

use app\library\SSL\SslErrorSummary;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see SslErrorSummary} — ringkasan pendek galat SSL untuk kolom **Aksi**
 * tabel `/domains` (teks utuh tetap dibuka lewat tombol Log ⇒ modal).
 *
 * Kontrak yang dikunci:
 *  - kelas kegagalan HTTP-01 yang dikenal dipetakan ke label pendek yang stabil
 *    (DNS, timeout, koneksi ditolak, 403, 404, batas laju, CAA, ADMIN_EMAIL);
 *  - teks tanpa pola dikenal tetap **terbaca**: prefix panjang
 *    "Gagal menerbitkan sertifikat SSL untuk <domain>:" dibuang, baris baru
 *    dipadatkan, lalu dipotong {@see SslErrorSummary::MAX_LENGTH};
 *  - galat kosong/tanpa isi ⇒ string kosong (bukan label palsu).
 */
class SslErrorSummaryTest extends TestCase
{
    /** Pesan certbot nyata (dipotong) untuk kelas-kelas yang sering muncul. */
    private const CERTBOT_PREFIX = 'Gagal menerbitkan sertifikat SSL untuk app.example.com: '
        . 'Requesting a certificate for app.example.com Certbot failed to authenticate some domains '
        . '(authenticator: webroot). The Certificate Authority reported these problems: '
        . 'Identifier: app.example.com ';

    /** @return array<string,array{0:string,1:string}> */
    public static function labelProvider(): array
    {
        return [
            'DNS tanpa A/AAAA' => [
                self::CERTBOT_PREFIX . 'Type: dns Detail: no valid A records found for app.example.com; '
                    . 'no valid AAAA records found for app.example.com',
                'DNS domain belum mengarah ke server ini',
            ],
            'DNS NXDOMAIN' => [
                self::CERTBOT_PREFIX . 'Type: dns Detail: DNS problem: NXDOMAIN looking up A for app.example.com',
                'DNS domain belum mengarah ke server ini',
            ],
            'timeout/firewall' => [
                self::CERTBOT_PREFIX . 'Type: connection Detail: Fetching http://app.example.com/x: '
                    . 'Timeout during connect (likely firewall problem)',
                'Port 80 tidak menjawab (firewall?)',
            ],
            'connection refused' => [
                self::CERTBOT_PREFIX . 'Type: connection Detail: Fetching http://app.example.com/x: Connection refused',
                'Koneksi ke port 80 ditolak',
            ],
            'nginx 403' => [
                self::CERTBOT_PREFIX . 'Type: unauthorized Detail: Invalid response from '
                    . 'http://app.example.com/.well-known/acme-challenge/AbC: "<html>403 Forbidden</html>"',
                'Nginx menolak berkas challenge (403)',
            ],
            'challenge 404' => [
                self::CERTBOT_PREFIX . 'Type: unauthorized Detail: Invalid response from '
                    . 'http://app.example.com/.well-known/acme-challenge/AbC: 404',
                'Berkas challenge tidak ditemukan (404)',
            ],
            'batas laju' => [
                self::CERTBOT_PREFIX . 'Error creating new order :: too many failed authorizations recently',
                "Batas laju Let's Encrypt tercapai",
            ],
            'CAA' => [
                self::CERTBOT_PREFIX . 'Type: caa Detail: CAA record for app.example.com prevents issuance',
                'CAA domain melarang penerbitan',
            ],
            'ADMIN_EMAIL' => [
                'ADMIN_EMAIL belum diatur — wajib untuk penerbitan sertifikat Let\'s Encrypt.',
                'ADMIN_EMAIL belum diatur',
            ],
        ];
    }

    /**
     * @dataProvider labelProvider
     */
    public function testKelasGalatDikenalDipetakanKeLabelPendek(string $error, string $expected): void
    {
        $this->assertSame($expected, SslErrorSummary::short($error));
    }

    public function testRingkasanSelaluPendekDanSatuBaris(): void
    {
        foreach (self::labelProvider() as [$error, $expected]) {
            $short = SslErrorSummary::short($error);
            $this->assertLessThanOrEqual(SslErrorSummary::MAX_LENGTH, mb_strlen($short), $expected);
            $this->assertStringNotContainsString("\n", $short);
        }
    }

    public function testTanpaPolaDikenalPrefixDibuangDanDipotong(): void
    {
        $error = "Gagal menerbitkan sertifikat SSL untuk app.example.com:   Kegagalan tak dikenal\n"
            . "pada certbot yang penjelasannya sangat panjang sekali sehingga harus dipotong "
            . str_repeat('dan terus memanjang ', 20);

        $short = SslErrorSummary::short($error);

        $this->assertStringStartsWith('Kegagalan tak dikenal', $short, 'prefix "Gagal menerbitkan …" dibuang');
        $this->assertStringEndsWith('…', $short, 'teks panjang ditandai terpotong');
        $this->assertLessThanOrEqual(SslErrorSummary::MAX_LENGTH, mb_strlen($short));
        $this->assertStringNotContainsString("\n", $short, 'baris baru dipadatkan');
    }

    public function testKosongMenghasilkanStringKosong(): void
    {
        $this->assertSame('', SslErrorSummary::short(null));
        $this->assertSame('', SslErrorSummary::short(''));
        $this->assertSame('', SslErrorSummary::short("  \n\t "));
    }
}
