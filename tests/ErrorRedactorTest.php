<?php
declare(strict_types=1);

namespace Tests;

use app\library\Errors\ErrorRedactor;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Test {@see ErrorRedactor} — satu-satunya pintu redaksi laporan error.
 *
 * Fokus: kredensial yang lazim muncul di pesan/stack tidak pernah tersimpan
 * mentah, kata biasa tidak ikut teredaksi, truncation tidak merusak UTF-8,
 * dan tidak ada input yang membuat kelas ini melempar.
 */
class ErrorRedactorTest extends TestCase
{
    /** Token contoh dibangun dari potongan agar tidak tampil utuh di log CI. */
    private static function secret(): string
    {
        return 'tok' . 'en-' . 'RAHASIA' . '123456';
    }

    public function testRedactsBearerHeaderAndKeepsScheme(): void
    {
        $clean = ErrorRedactor::redact('Authorization: Bearer ' . self::secret());

        $this->assertSame('Authorization: Bearer [REDACTED]', $clean);
        $this->assertStringNotContainsString(self::secret(), $clean);
    }

    public function testRedactsBareBearerToken(): void
    {
        $clean = ErrorRedactor::redact('gagal: Bearer ' . self::secret() . ' di header');

        $this->assertSame('gagal: Bearer [REDACTED] di header', $clean);
        $this->assertSame($clean, ErrorRedactor::redact($clean), 'redaksi idempoten');
    }

    public function testRedactsPemBlocksIncludingUnterminated(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAKj34GkxFhD90vcNLYLG\n-----END RSA PRIVATE KEY-----";
        $this->assertSame('[REDACTED PEM]', ErrorRedactor::redact($pem));

        $partial = "pesan\n-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAB3NzaC1yc2E";
        $clean = ErrorRedactor::redact($partial);
        $this->assertStringContainsString('[REDACTED PEM]', $clean);
        $this->assertStringNotContainsString('AAAAB3NzaC1yc2E', $clean);
    }

    public function testRedactsUrlUserInfo(): void
    {
        $clean = ErrorRedactor::redact('gagal konek https://admin:s3cr3t@db.internal:5432/rames');

        $this->assertSame('gagal konek https://[REDACTED]@db.internal:5432/rames', $clean);
        $this->assertStringNotContainsString('s3cr3t', $clean);
    }

    public function testRedactsSensitivePairsInFormQueryJsonAndEnv(): void
    {
        $form = ErrorRedactor::redact('password=hunter2&user=bob');
        $this->assertSame('password=[REDACTED]&user=bob', $form);
        $this->assertStringContainsString('user=bob', $form, 'kunci biasa tetap utuh');

        $query = ErrorRedactor::redact('/login?access_token=abc123&x=1');
        $this->assertSame('/login?access_token=[REDACTED]&x=1', $query);

        $json = ErrorRedactor::redact('{"client_secret":"shh","note":"ok"}');
        $this->assertSame('{"client_secret":"[REDACTED]","note":"ok"}', $json);

        $env = ErrorRedactor::redact('DB_PASSWORD=s3cret');
        $this->assertSame('DB_PASSWORD=[REDACTED]', $env);

        $header = ErrorRedactor::redact("X-Api-Key: zzz\nnext line");
        $this->assertSame("X-Api-Key: [REDACTED]\nnext line", $header);
    }

    public function testRedactsWholeCookieHeader(): void
    {
        $clean = ErrorRedactor::redact('Cookie: session=abc; other=def');

        $this->assertSame('Cookie: [REDACTED]', $clean);
        $this->assertSame($clean, ErrorRedactor::redact($clean), 'redaksi idempoten');
    }

    public function testOrdinaryWordTokenIsNotRedacted(): void
    {
        foreach ([
            'invalid token while parsing header',
            'unexpected token at position 3',
            'design=1 signal=2',
            'risk-management tools',
            'token_count=3',
        ] as $text) {
            $this->assertSame($text, ErrorRedactor::redact($text), 'kata biasa tidak boleh teredaksi: ' . $text);
        }
    }

    public function testRedactsWellKnownTokenPatterns(): void
    {
        $cases = [
            'sk-' . str_repeat('a', 32),
            'ghp_' . str_repeat('B', 36),
            'xoxb-' . '1234567890-abcdefghij',
            'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.'
                . 'dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
        ];

        foreach ($cases as $token) {
            $clean = ErrorRedactor::redact('nilai: ' . $token . ' selesai');
            $this->assertStringNotContainsString($token, $clean, 'pola token harus di-redaksi');
            $this->assertStringContainsString('[REDACTED]', $clean);
            $this->assertSame($clean, ErrorRedactor::redact($clean), 'idempoten');
        }
    }

    public function testTruncatesUtf8SafelyAndAddsSuffixOnlyWhenCut(): void
    {
        $text = str_repeat('é', 300); // 600 byte
        $clean = ErrorRedactor::redact($text, 101);

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'), 'hasil tetap UTF-8 valid');
        $this->assertStringEndsWith('[truncated]', $clean);
        $this->assertLessThanOrEqual(101 + strlen(ErrorRedactor::TRUNCATION_SUFFIX), strlen($clean));

        $body = substr($clean, 0, -strlen(ErrorRedactor::TRUNCATION_SUFFIX));
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'), 'badan hasil tetap UTF-8 valid');
        $this->assertSame(0, strlen($body) % 2, 'setiap é utuh dua byte (tidak terpotong di tengah)');

        $this->assertSame('pendek', ErrorRedactor::redact('pendek', 100), 'tanpa sufiks bila muat');
        $this->assertSame('', ErrorRedactor::redact('abc', 0));
    }

    public function testRedactContextMasksSensitiveKeysAndDropsNonStrings(): void
    {
        $out = ErrorRedactor::redactContext([
            'password' => 'hunter2',
            'apiKey' => 'zzz',
            'note' => 'ok',
            'count' => 5,
            'flag' => true,
            'nothing' => null,
            'object' => new stdClass(),
            'nested' => ['token' => 'abc', 'keep' => 'v'],
        ]);

        $this->assertSame([
            'password' => '[REDACTED]',
            'apiKey' => '[REDACTED]',
            'note' => 'ok',
            'nested' => ['token' => '[REDACTED]', 'keep' => 'v'],
        ], $out);
    }

    public function testRedactContextRespectsDepthAndKeyBudget(): void
    {
        // root = level 1; lima level kontainer dipertahankan.
        $deep = ['k5' => 'v5'];
        for ($i = 4; $i >= 1; $i--) {
            $deep = ['k' . $i => $deep];
        }
        $out = ErrorRedactor::redactContext($deep);
        $cursor = $out;
        for ($i = 1; $i <= 4; $i++) {
            $cursor = $cursor['k' . $i] ?? null;
        }
        $this->assertSame('v5', $cursor['k5'] ?? null, 'lima level tetap utuh');

        $tooDeep = ErrorRedactor::redactContext(['x' => $deep]);
        $this->assertArrayNotHasKey(
            'k5',
            $tooDeep['x']['k1']['k2']['k3']['k4'] ?? [],
            'level ke-6 dibuang'
        );

        $many = [];
        for ($i = 0; $i < 60; $i++) {
            $many['k' . $i] = 'v' . $i;
        }
        $this->assertCount(50, ErrorRedactor::redactContext($many));
    }

    public function testRedactContextTruncatesStringsAndDropsKeysToFitByteBudget(): void
    {
        $out = ErrorRedactor::redactContext(['long' => str_repeat('a', 5000), 'b' => 'x']);
        $this->assertStringEndsWith('[truncated]', $out['long']);
        $this->assertLessThanOrEqual(1024 + strlen(ErrorRedactor::TRUNCATION_SUFFIX), strlen($out['long']));

        $sized = ErrorRedactor::redactContext([
            'a' => str_repeat('x', 900),
            'b' => str_repeat('y', 900),
            'c' => str_repeat('z', 900),
        ], 1200);

        $this->assertSame(['a'], array_keys($sized), 'kunci dibuang dari belakang');
        $this->assertLessThanOrEqual(1200, strlen((string) json_encode($sized)));
    }

    public function testRedactContextNeverThrows(): void
    {
        $self = ['a' => 'x'];
        $self['self'] = &$self;
        $this->assertIsArray(ErrorRedactor::redactContext($self));

        $this->assertIsArray(ErrorRedactor::redactContext([], -5));
        $this->assertIsArray(ErrorRedactor::redactContext(['bad' => "\xB1\x31"]));
    }

    public function testRoutePathDropsQueryAndFragmentAndRequiresLeadingSlash(): void
    {
        $this->assertSame('/apps/abc/logs', ErrorRedactor::routePath('/apps/abc/logs?token=1#frag'));
        $this->assertSame('/apps/abc', ErrorRedactor::routePath('/apps/abc'));
        $this->assertSame('', ErrorRedactor::routePath('apps/abc'), 'tanpa awalan / ditolak');
        $this->assertSame('', ErrorRedactor::routePath(''));
        $this->assertSame('', ErrorRedactor::routePath('   '));
    }

    public function testRoutePathTruncatesTo512BytesUtf8Safely(): void
    {
        $path = '/' . str_repeat('é', 400);
        $clean = ErrorRedactor::routePath($path);

        $this->assertLessThanOrEqual(ErrorRedactor::ROUTE_MAX_BYTES, strlen($clean));
        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertSame('/', $clean[0]);
    }

    public function testRedactNeverThrowsOnHostileInput(): void
    {
        $this->assertSame('', ErrorRedactor::redact(''));
        $this->assertSame('', ErrorRedactor::redact('abc', -1));
        $this->assertIsString(ErrorRedactor::redact("\xB1\x31\xFF gagal"));
        $this->assertIsString(ErrorRedactor::redact(str_repeat('-----BEGIN ', 100), 64));
    }
}
