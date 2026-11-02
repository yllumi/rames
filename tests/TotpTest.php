<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test vektor RFC 6238 (SHA1) + base32 RFC 4648 untuk {@see Totp}.
 *
 * Tanpa I/O & tanpa jaringan; membuktikan kecocokan algoritma dengan
 * implementasi authenticator standar — prasyarat agar 2FA tidak terkunci.
 */
class TotpTest extends TestCase
{
    /** Base32 dari ASCII "12345678901234567890" (secret uji RFC 6238). */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    // ------------------------------------------------------------------
    // Vektor RFC 6238 (dipotong ke 6 digit)
    // ------------------------------------------------------------------

    public function testRfc6238Sha1VectorsProduceExpectedSixDigitCodes(): void
    {
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            self::assertSame(
                $expected,
                Totp::code(self::RFC_SECRET, $timestamp),
                "Kode TOTP untuk timestamp {$timestamp} tidak sesuai RFC 6238"
            );
        }
    }

    public function testBase32EncodeMatchesRfcSecretForAsciiSeed(): void
    {
        self::assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
    }

    public function testBase32RoundTripPreservesBinaryData(): void
    {
        foreach ([1, 2, 5, 20, 32] as $length) {
            $raw = random_bytes($length);
            self::assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        }
    }

    public function testBase32DecodeToleratesLowercaseSpacesHyphensAndPadding(): void
    {
        $grouped = strtolower(implode(' ', str_split(self::RFC_SECRET, 4))) . '==';

        self::assertSame('12345678901234567890', Totp::base32Decode($grouped));
    }

    public function testBase32DecodeRejectsInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Totp::base32Decode('GEZDG!NV');
    }

    // ------------------------------------------------------------------
    // generateSecret
    // ------------------------------------------------------------------

    public function testGenerateSecretProducesUsableBase32(): void
    {
        $secret = Totp::generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertSame(20, strlen(Totp::base32Decode($secret)));

        // Secret hasil generate harus bisa dipakai membentuk kode 6 digit.
        self::assertMatchesRegularExpression('/^\d{6}$/', Totp::code($secret, 59));
    }

    // ------------------------------------------------------------------
    // verify(): window ±1 dan anti-replay (step dikembalikan)
    // ------------------------------------------------------------------

    public function testVerifyAcceptsAdjacentStepWithinWindow(): void
    {
        $secret = Totp::generateSecret();
        $previousStepCode = Totp::code($secret, 0); // step 0

        // ts=30 → step 1, window 1 mencakup step 0..2.
        self::assertSame(0, Totp::verify($secret, $previousStepCode, 30, 1));
    }

    public function testVerifyRejectsCodeOutsideWindow(): void
    {
        $secret = Totp::generateSecret();

        // step 0 dibandingkan pada ts=60 (step 2): di luar window 1..3.
        self::assertNull(Totp::verify($secret, Totp::code($secret, 0), 60, 1));
        // step 3 dibandingkan pada ts=59 (step 1): di luar window 0..2.
        self::assertNull(Totp::verify($secret, Totp::code($secret, 90), 59, 1));
    }

    public function testVerifyReturnsMatchingStepForCurrentCode(): void
    {
        $secret = Totp::generateSecret();
        $timestamp = 1234567890;
        $code = Totp::code($secret, $timestamp);

        self::assertSame(intdiv($timestamp, Totp::PERIOD), Totp::verify($secret, $code, $timestamp, 0));
    }

    public function testVerifyNormalizesSpacesAndHyphens(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::code($secret, 59); // 287082 untuk secret RFC, 6 digit acak di sini

        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        self::assertSame(1, Totp::verify($secret, $spaced, 59, 0));
    }

    public function testVerifyRejectsNonDigitAndWrongLengthCodes(): void
    {
        $secret = Totp::generateSecret();

        self::assertNull(Totp::verify($secret, 'abcdef', 59));
        self::assertNull(Totp::verify($secret, '12345', 59));
        self::assertNull(Totp::verify($secret, '1234567', 59));
        self::assertNull(Totp::verify($secret, '', 59));
    }

    public function testVerifyWithEmptySecretReturnsNullWithoutThrowing(): void
    {
        self::assertNull(Totp::verify('', '123456', 59));
    }

    // ------------------------------------------------------------------
    // provisioningUri
    // ------------------------------------------------------------------

    public function testProvisioningUriContainsEncodedSecretIssuerAndParameters(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = Totp::provisioningUri($secret, 'admin', 'Rames');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString(rawurlencode('Rames:admin'), $uri);
        self::assertStringContainsString('secret=' . $secret, $uri);
        self::assertStringContainsString('issuer=' . rawurlencode('Rames'), $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testProvisioningUriEncodesIssuerWithSpaces(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'budi', 'Rames Cloud');

        self::assertStringContainsString('issuer=Rames%20Cloud', $uri);
        self::assertStringNotContainsString('issuer=Rames Cloud', $uri);
    }
}
