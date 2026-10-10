<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\PaymentMethodCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Test katalog pengelompokan kanal pembayaran Duitku
 * (`app\library\Billing\PaymentMethodCatalog`) — statik murni, tanpa I/O.
 *
 * Membuktikan: tabel kode → grup (VA/E-Wallet/QRIS/Retail), petunjuk nama untuk
 * kode tak dikenal, fail-safe `Lainnya`, `decorate()` menambah `group` tanpa
 * menghilangkan key lain, serta urutan kanonik + dedupe di `grouped()` /
 * `groupedCodes()`.
 */
class PaymentMethodCatalogTest extends TestCase
{
    /**
     * Allowlist default `BILLING_DUITKU_METHODS` (urut config) — sumber bersama
     * untuk uji urutan kanonik & uji pembanding jalur statis vs payload.
     *
     * @var array<int,string>
     */
    private const DEFAULT_ALLOWLIST = [
        'BC', 'BT', 'I1', 'M2', 'VA', 'B1', 'DM', 'BV', 'BR', 'NC', 'A1', 'AG', 'S1',
        'FT', 'IR', 'OV', 'DA', 'SA', 'LF', 'LA', 'SP', 'NQ', 'SQ',
    ];

    public function testVirtualAccountCodes(): void
    {
        foreach (['BC', 'M2', 'I1', 'VA', 'BT', 'A1', 'AG', 'S1', 'B1', 'DM', 'BV', 'BR', 'NC'] as $code) {
            $this->assertSame(
                'Virtual Account',
                PaymentMethodCatalog::group($code),
                "kode {$code} harus Virtual Account"
            );
        }
    }

    public function testEWalletCodes(): void
    {
        // SA = "SHOPEEPAY APP" → tetap E-Wallet (bukti nama live gateway).
        foreach (['OV', 'DA', 'SA', 'LF', 'LA'] as $code) {
            $this->assertSame('E-Wallet', PaymentMethodCatalog::group($code), "kode {$code} harus E-Wallet");
        }
    }

    public function testQrisCodes(): void
    {
        // SP = "SHOPEEPAY QRIS" → QRIS, bukan E-Wallet murni (bukti nama live).
        foreach (['NQ', 'SQ', 'QR', 'SP'] as $code) {
            $this->assertSame('QRIS', PaymentMethodCatalog::group($code), "kode {$code} harus QRIS");
        }
    }

    public function testRetailCodes(): void
    {
        // FT = "RETAIL" → Retail, bukan Lainnya (bukti nama live gateway).
        foreach (['IR', 'AL', 'FT'] as $code) {
            $this->assertSame('Retail', PaymentMethodCatalog::group($code), "kode {$code} harus Retail");
        }
    }

    public function testShopeePayCodesSplitByLiveGatewayName(): void
    {
        // Kode tetap benar walau tanpa nama (jalur statis `groupedCodes`).
        $this->assertSame('QRIS', PaymentMethodCatalog::group('SP'));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('SA'));

        // Jalur payload (nama live gateway) harus sepakat dengan jalur statis.
        $this->assertSame('QRIS', PaymentMethodCatalog::group('SP', 'SHOPEEPAY QRIS'));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('SA', 'SHOPEEPAY APP'));

        // Kode tak dikenal → petunjuk nama menentukan (QRIS dicek sebelum E-Wallet).
        $this->assertSame('QRIS', PaymentMethodCatalog::group('ZZ', 'SHOPEEPAY QRIS'));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('ZZ', 'SHOPEEPAY APP'));
    }

    public function testFastPayAndRetailNameHints(): void
    {
        // FT selalu Retail — baik tanpa nama maupun dengan nama apa pun.
        $this->assertSame('Retail', PaymentMethodCatalog::group('FT'));
        $this->assertSame('Retail', PaymentMethodCatalog::group('FT', 'RETAIL'));
        $this->assertSame('Retail', PaymentMethodCatalog::group('FT', 'FastPay'));
    }

    public function testUnknownCodeWithoutNameFallsBackToOther(): void
    {
        $this->assertSame('Lainnya', PaymentMethodCatalog::group('ZZ'));
        $this->assertSame('Lainnya', PaymentMethodCatalog::group(''));
        $this->assertSame(PaymentMethodCatalog::OTHER, PaymentMethodCatalog::group('XX', 'Metode Misterius'));
    }

    public function testUnknownCodeUsesNameHint(): void
    {
        $this->assertSame('Virtual Account', PaymentMethodCatalog::group('ZZ', 'Bank Mandiri Virtual Account'));
        $this->assertSame('QRIS', PaymentMethodCatalog::group('ZZ', 'QRIS All Payment'));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('ZZ', 'DANA Wallet'));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('ZZ', 'OVO'));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('ZZ', 'ShopeePay'));
        $this->assertSame('Retail', PaymentMethodCatalog::group('ZZ', 'Indomaret'));
        $this->assertSame('Retail', PaymentMethodCatalog::group('ZZ', 'Alfamart'));
    }

    public function testGroupNormalizesCaseAndWhitespace(): void
    {
        $this->assertSame('Virtual Account', PaymentMethodCatalog::group('  bc '));
        $this->assertSame('QRIS', PaymentMethodCatalog::group(' nq '));
        $this->assertSame('E-Wallet', PaymentMethodCatalog::group('ov'));
        $this->assertSame('Virtual Account', PaymentMethodCatalog::group('zz', 'BCA VIRTUAL ACCOUNT'));
    }

    public function testDecorateAddsGroupAndKeepsOtherKeys(): void
    {
        $methods = [
            ['code' => 'BC', 'name' => 'BCA Virtual Account', 'image' => 'bc.png', 'fee' => 4000],
            ['code' => 'ov', 'name' => 'OVO', 'image' => 'ovo.png', 'fee' => 0],
            ['code' => 'ZZ', 'name' => 'Misteri', 'image' => '', 'fee' => 0],
            'bukan-array',
            ['name' => 'Tanpa Kode', 'image' => 'x.png', 'fee' => 1],
        ];

        $out = PaymentMethodCatalog::decorate($methods);

        $this->assertCount(4, $out, 'item non-array dilewati');
        $this->assertSame('Virtual Account', $out[0]['group']);
        $this->assertSame('BCA Virtual Account', $out[0]['name'], 'name dipertahankan');
        $this->assertSame('bc.png', $out[0]['image'], 'image dipertahankan');
        $this->assertSame(4000, $out[0]['fee'], 'fee dipertahankan');
        $this->assertSame('E-Wallet', $out[1]['group']);
        $this->assertSame('Lainnya', $out[2]['group']);
        $this->assertSame('Lainnya', $out[3]['group'], 'item tanpa code → Lainnya');
    }

    public function testGroupedUsesCanonicalOrderSkipsEmptyAndIsStable(): void
    {
        $methods = [
            ['code' => 'ZZ', 'name' => 'Misteri'],          // Lainnya
            ['code' => 'NQ', 'name' => 'QRIS'],             // QRIS
            ['code' => 'BC', 'name' => 'BCA VA'],           // Virtual Account
            ['code' => 'VA', 'name' => 'VA Umum'],          // Virtual Account
            ['code' => 'OV', 'name' => 'OVO'],              // E-Wallet
        ];

        $grouped = PaymentMethodCatalog::grouped(PaymentMethodCatalog::decorate($methods));

        $this->assertSame(
            ['Virtual Account', 'E-Wallet', 'QRIS', 'Lainnya'],
            array_column($grouped, 'label'),
            'urut kanonik & grup Retail kosong dilewati'
        );
        $this->assertSame(['BC', 'VA'], array_column($grouped[0]['items'], 'code'), 'stabil: urutan masukan');
        $this->assertSame(['OV'], array_column($grouped[1]['items'], 'code'));
        $this->assertSame(['NQ'], array_column($grouped[2]['items'], 'code'));
        $this->assertSame(['ZZ'], array_column($grouped[3]['items'], 'code'));
    }

    public function testGroupedCodesDedupesUppercasesAndDropsInvalid(): void
    {
        $grouped = PaymentMethodCatalog::groupedCodes([
            'bc', ' BC ', 'M2', 'bc',
            'OV', 'ov',
            'NQ',
            'IR',
            'x', 'XXX', '', '1', 'ZZ',
            'VC',
        ]);

        $this->assertSame(
            ['Virtual Account', 'E-Wallet', 'QRIS', 'Retail', 'Lainnya'],
            array_column($grouped, 'label')
        );
        $this->assertSame(['BC', 'M2'], $grouped[0]['codes'], 'dedupe + uppercase');
        $this->assertSame(['OV'], $grouped[1]['codes']);
        $this->assertSame(['NQ'], $grouped[2]['codes']);
        $this->assertSame(['IR'], $grouped[3]['codes']);
        $this->assertSame(['ZZ', 'VC'], $grouped[4]['codes'], 'kode tak dikenal tetap masuk Lainnya');
    }

    public function testGroupedCodesSkipsEmptyGroups(): void
    {
        $grouped = PaymentMethodCatalog::groupedCodes(['BC']);

        $this->assertSame([['label' => 'Virtual Account', 'codes' => ['BC']]], $grouped);
    }

    public function testDefaultAllowlistOrderMatchesCanonicalGroups(): void
    {
        // Allowlist default (urut config) — setelah grouping hasilnya kanonik.
        $grouped = PaymentMethodCatalog::groupedCodes(self::DEFAULT_ALLOWLIST);

        $this->assertSame(
            ['Virtual Account', 'E-Wallet', 'QRIS', 'Retail'],
            array_column($grouped, 'label'),
            'tanpa grup Lainnya: semua kode allowlist default terpetakan'
        );
        $this->assertSame(
            ['BC', 'BT', 'I1', 'M2', 'VA', 'B1', 'DM', 'BV', 'BR', 'NC', 'A1', 'AG', 'S1'],
            $grouped[0]['codes']
        );
        $this->assertSame(['OV', 'DA', 'SA', 'LF', 'LA'], $grouped[1]['codes'], 'SP bukan lagi E-Wallet');
        $this->assertSame(['SP', 'NQ', 'SQ'], $grouped[2]['codes'], 'SP masuk QRIS (bukan E-Wallet)');
        $this->assertSame(['FT', 'IR'], $grouped[3]['codes'], 'FT kini Retail (bukan Lainnya)');
    }

    /**
     * MEDIUM #1 (penutup): jalur statis (`groupedCodes`, tanpa nama) dan jalur
     * payload (`grouped(decorate(...))`, dengan nama live gateway) **tidak boleh**
     * pernah menghasilkan grup berbeda — UI pra-JS dan pasca-JS harus identik.
     *
     * Nama kanal = bukti nyata `GET /api/credits/methods` dari instalasi produksi.
     *
     * @return array<string,string>
     */
    public static function liveGatewayNames(): array
    {
        return [
            // Virtual Account
            'VA' => 'MAYBANK VA',
            'BT' => 'PERMATA VA',
            'B1' => 'CIMB NIAGA VA',
            'A1' => 'ATM BERSAMA VA',
            'I1' => 'BNI VA',
            'M2' => 'MANDIRI VA H2H',
            'AG' => 'ARTHA GRAHA VA',
            'S1' => 'SAMPOERNA VA',
            'BC' => 'BCA VA',
            'BR' => 'BRI VA',
            'NC' => 'BNC VA',
            'BV' => 'BSI VA',
            // (kode di bawah ini ada di allowlist default tanpa nama live yang
            //  dilaporkan — pakai nama konsisten dengan grup kanoniknya.)
            'DM' => 'MANDIRI VA',
            // E-Wallet
            'OV' => 'OVO',
            'DA' => 'DANA',
            'SA' => 'SHOPEEPAY APP',
            'LA' => 'LINKAJA APP PCT',
            'LF' => 'LINKAJA',
            // QRIS
            'SP' => 'SHOPEEPAY QRIS',
            'NQ' => 'NOBU QRIS',
            'SQ' => 'QRIS',
            // Retail
            'FT' => 'RETAIL',
            'IR' => 'INDOMARET',
        ];
    }

    public function testStaticAndPayloadPathsAgreeForEveryDefaultAllowlistCode(): void
    {
        $names = self::liveGatewayNames();

        $differences = 0;
        foreach (self::DEFAULT_ALLOWLIST as $code) {
            $name = $names[$code] ?? $code;
            // 1) statis (tanpa nama) vs payload (dengan nama live gateway)
            if (PaymentMethodCatalog::group($code) !== PaymentMethodCatalog::group($code, $name)) {
                $differences++;
            }
        }
        $this->assertSame(0, $differences, 'group(code) harus sama dengan group(code, nama_live)');

        // 2) hasil agregat: groupedCodes([...]) vs grouped(decorate([...]))
        $payload = [];
        foreach (self::DEFAULT_ALLOWLIST as $code) {
            $payload[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'image' => '', 'fee' => 0];
        }

        $fromCodes = [];
        foreach (PaymentMethodCatalog::groupedCodes(self::DEFAULT_ALLOWLIST) as $group) {
            $fromCodes[$group['label']] = $group['codes'];
        }

        $fromPayload = [];
        foreach (PaymentMethodCatalog::grouped(PaymentMethodCatalog::decorate($payload)) as $group) {
            $fromPayload[$group['label']] = array_column($group['items'], 'code');
        }

        $this->assertSame($fromCodes, $fromPayload, 'pra-JS (groupedCodes) == pasca-JS (grouped) — tidak pernah beda grup');
        $this->assertSame([], array_diff_key($fromCodes, $fromPayload));
    }
}
