<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\DuitkuSignature;
use PHPUnit\Framework\TestCase;

/**
 * Test DuitkuSignature — HMAC-SHA256 hex lowercase (MD5/SHA-256 obsolete),
 * 4 varian string-to-sign, dan verifikasi callback `hash_equals`.
 */
class DuitkuSignatureTest extends TestCase
{
    private const API_KEY = 'test-api-key-9f8e7d6c';

    public function testStringToSignComposition(): void
    {
        $this->assertSame('DS12345RM-abcdef10000', DuitkuSignature::inquiryStringToSign('DS12345', 'RM-abcdef', 10000));
        $this->assertSame('DS12345RM-abcdef', DuitkuSignature::statusStringToSign('DS12345', 'RM-abcdef'));
        $this->assertSame(
            'DS12345100002026-10-09 12:00:00',
            DuitkuSignature::methodsStringToSign('DS12345', 10000, '2026-10-09 12:00:00')
        );
        $this->assertSame('DS1234510000RM-abcdef', DuitkuSignature::callbackStringToSign('DS12345', 10000, 'RM-abcdef'));
    }

    public function testHashesAreLowercaseHexHmacSha256(): void
    {
        $expectedInquiry = hash_hmac('sha256', 'DS12345RM-abcdef10000', self::API_KEY);
        $expectedStatus = hash_hmac('sha256', 'DS12345RM-abcdef', self::API_KEY);
        $expectedMethods = hash_hmac('sha256', 'DS12345100002026-10-09 12:00:00', self::API_KEY);
        $expectedCallback = hash_hmac('sha256', 'DS1234510000RM-abcdef', self::API_KEY);

        $this->assertSame($expectedInquiry, DuitkuSignature::inquiry('DS12345', 'RM-abcdef', 10000, self::API_KEY));
        $this->assertSame($expectedStatus, DuitkuSignature::status('DS12345', 'RM-abcdef', self::API_KEY));
        $this->assertSame(
            $expectedMethods,
            DuitkuSignature::methods('DS12345', 10000, '2026-10-09 12:00:00', self::API_KEY)
        );
        $this->assertSame($expectedCallback, DuitkuSignature::callback('DS12345', 10000, 'RM-abcdef', self::API_KEY));

        foreach ([$expectedInquiry, $expectedStatus, $expectedMethods, $expectedCallback] as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
    }

    public function testCallbackAcceptsIntOrStringAmount(): void
    {
        $this->assertSame(
            DuitkuSignature::callback('DS12345', 10000, 'RM-abcdef', self::API_KEY),
            DuitkuSignature::callback('DS12345', '10000', 'RM-abcdef', self::API_KEY)
        );
    }

    public function testVerifyCallback(): void
    {
        $signature = DuitkuSignature::callback('DS12345', '10000', 'RM-abcdef', self::API_KEY);

        $this->assertTrue(DuitkuSignature::verifyCallback('DS12345', '10000', 'RM-abcdef', $signature, self::API_KEY));
        // Case-insensitive hex (tetap hash_equals).
        $this->assertTrue(
            DuitkuSignature::verifyCallback('DS12345', '10000', 'RM-abcdef', strtoupper($signature), self::API_KEY)
        );
        // Duitku mengirim amount sebagai string.
        $this->assertTrue(DuitkuSignature::verifyCallback('DS12345', 10000, 'RM-abcdef', $signature, self::API_KEY));

        $this->assertFalse(DuitkuSignature::verifyCallback('DS12345', '20000', 'RM-abcdef', $signature, self::API_KEY));
        $this->assertFalse(DuitkuSignature::verifyCallback('DS999', '10000', 'RM-abcdef', $signature, self::API_KEY));
        $this->assertFalse(DuitkuSignature::verifyCallback('DS12345', '10000', 'RM-other', $signature, self::API_KEY));
        $this->assertFalse(DuitkuSignature::verifyCallback('DS12345', '10000', 'RM-abcdef', 'deadbeef', self::API_KEY));
        // API key salah ⇒ tidak lolos.
        $this->assertFalse(DuitkuSignature::verifyCallback('DS12345', '10000', 'RM-abcdef', $signature, 'wrong-key'));
        // Signature kosong ⇒ tidak lolos.
        $this->assertFalse(DuitkuSignature::verifyCallback('DS12345', '10000', 'RM-abcdef', '', self::API_KEY));
    }

    public function testDatetime(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            DuitkuSignature::datetime()
        );
        $this->assertSame('2026-10-09 12:34:56', DuitkuSignature::datetime('2026-10-09 12:34:56'));
        $this->assertSame('2026-10-09 12:34:56', DuitkuSignature::datetime('2026-10-09T12:34:56+07:00'));
        // Input tak terparse tetap mengembalikan waktu berformat benar (tanpa melempar).
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', DuitkuSignature::datetime('bukan-tanggal'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', DuitkuSignature::datetime(''));
    }
}
