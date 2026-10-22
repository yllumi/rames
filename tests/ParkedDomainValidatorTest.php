<?php
declare(strict_types=1);

namespace Tests;

use app\library\Domain\ParkedDomainValidator;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

// Helper webman (`config()`, `app_subdomain_of()`) tidak dimuat di luar runtime
// webman: definisikan konstanta + fungsi yang dibutuhkan (pola SubdomainTest).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain_of')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Test validasi domain parking: normalisasi, FQDN, URL redirect (anti-injeksi
 * direktif Nginx), kode redirect, batas HTML 1 MiB, keunikan lintas app/parkir.
 *
 * Semua store memakai berkas .sqlite temp — `database/` nyata tidak disentuh.
 */
class ParkedDomainValidatorTest extends TestCase
{
    private string $file;
    private AppStore $apps;
    private ParkedDomainStore $parked;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-parked-validator-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->apps = new AppStore($this->file);
        $this->parked = new ParkedDomainStore($this->file);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    // ==================================================================
    // Normalisasi & FQDN
    // ==================================================================

    public function testNormalizeDomainTrimsLowercasesAndDropsTrailingDot(): void
    {
        $this->assertSame('example.com', ParkedDomainValidator::normalizeDomain('  Example.COM.  '));
        $this->assertSame('example.com', ParkedDomainValidator::normalizeDomain('example.com'));
        $this->assertSame('', ParkedDomainValidator::normalizeDomain("   \t "));
    }

    public function testValidPublicDomains(): void
    {
        foreach ([
            'example.com',
            'sub.example.co.id',
            'my-app.example.io',
            'a1.b2.example.dev',
            'Example.COM.',
        ] as $domain) {
            $this->assertTrue(
                ParkedDomainValidator::isValidDomain($domain),
                'harus valid: ' . $domain
            );
        }
    }

    public function testRejectsNonPublicOrMalformedDomains(): void
    {
        foreach ([
            '1.2.3.4' => 'IP literal v4',
            '::1' => 'IP literal v6',
            'localhost' => 'localhost',
            '*.example.com' => 'wildcard',
            'foo_bar.example.com' => 'underscore',
            'com' => 'satu label',
            'exa mple.com' => 'spasi',
            'http://example.com' => 'skema',
            'example.com/path' => 'path',
            'example.com:8080' => 'port',
            '-bad.example.com' => 'label diawali strip',
            'bad-.example.com' => 'label diakhiri strip',
            'example..com' => 'label kosong',
            'example.123' => 'TLD numerik',
            'example.c' => 'TLD 1 karakter',
            '' => 'kosong',
        ] as $domain => $reason) {
            $this->assertFalse(
                ParkedDomainValidator::isValidDomain($domain),
                'harus ditolak (' . $reason . '): ' . $domain
            );
        }

        $this->assertFalse(
            ParkedDomainValidator::isValidDomain('a.' . str_repeat('b', 64) . '.com'),
            'label 64 karakter harus ditolak'
        );
    }

    public function testDomainLengthBoundaryIsDnsLimit(): void
    {
        // 63 + 1 + 63 + 1 + 63 + 1 + 61 = 253 karakter.
        $max = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61);
        $this->assertSame(253, strlen($max));
        $this->assertTrue(ParkedDomainValidator::isValidDomain($max));

        $tooLong = $max . 'e';
        $this->assertSame(254, strlen($tooLong));
        $this->assertFalse(ParkedDomainValidator::isValidDomain($tooLong));
    }

    // ==================================================================
    // URL redirect
    // ==================================================================

    public function testValidRedirectTargets(): void
    {
        foreach ([
            'https://example.com',
            'http://example.com',
            'http://example.com:8080/path/to/page.html',
            'https://sub.example.co.id/a/b/',
            'https://1.2.3.4/x',
            '  https://example.com/path  ',
        ] as $target) {
            $this->assertTrue(
                ParkedDomainValidator::isValidRedirectTarget($target),
                'harus valid: ' . $target
            );
        }

        $this->assertSame('https://example.com/x', ParkedDomainValidator::normalizeRedirectTarget(" https://example.com/x\n"));
    }

    public function testRejectsUnsafeRedirectTargets(): void
    {
        foreach ([
            'javascript:alert(1)' => 'skema non-http',
            'ftp://example.com' => 'skema non-http',
            '//example.com/path' => 'tanpa skema',
            'example.com' => 'tanpa skema',
            'http://user:pass@example.com/' => 'userinfo',
            'http://example.com/?a=1' => 'query',
            'http://example.com/#frag' => 'fragment',
            "http://exam\nple.com/" => 'newline',
            "http://example.com/\0x" => 'NUL',
            'http://example.com/pa th' => 'spasi',
            'http://example.com/$x' => 'dollar',
            'http://example.com/a;b' => 'titik koma',
            'http://example.com/{x}' => 'kurung kurawal',
            'http://example.com/a"b' => 'kutip ganda',
            "http://example.com/a'b" => 'kutip tunggal',
            'http://example.com/a\\b' => 'backslash',
            'http://example.com/a|b' => 'pipe',
            'http://example.com/a^b' => 'caret',
            'http://example.com/a`b' => 'backtick',
            'http://example.com/../../etc/passwd' => 'segmen ..',
            'http://example.com/%2e%2e/x' => 'segmen .. ter-encode',
            'http://example.com:99999/' => 'port di luar rentang',
            '' => 'kosong',
        ] as $target => $reason) {
            $this->assertFalse(
                ParkedDomainValidator::isValidRedirectTarget($target),
                'harus ditolak (' . $reason . '): ' . json_encode($target)
            );
        }

        $long = 'https://example.com/' . str_repeat('a', ParkedDomainValidator::MAX_REDIRECT_LENGTH);
        $this->assertGreaterThan(ParkedDomainValidator::MAX_REDIRECT_LENGTH, strlen($long));
        $this->assertFalse(ParkedDomainValidator::isValidRedirectTarget($long), 'URL > 2048 harus ditolak');
    }

    public function testRedirectTargetLengthBoundary(): void
    {
        $base = 'https://example.com/';
        $exact = $base . str_repeat('a', ParkedDomainValidator::MAX_REDIRECT_LENGTH - strlen($base));
        $this->assertSame(ParkedDomainValidator::MAX_REDIRECT_LENGTH, strlen($exact));
        $this->assertTrue(ParkedDomainValidator::isValidRedirectTarget($exact));

        $this->assertFalse(ParkedDomainValidator::isValidRedirectTarget($exact . 'a'));
    }

    // ==================================================================
    // Kode redirect
    // ==================================================================

    public function testRedirectCodeDefaultsTo301AndAccepts302(): void
    {
        $this->assertSame(301, ParkedDomainValidator::normalizeRedirectCode(null));
        $this->assertSame(301, ParkedDomainValidator::normalizeRedirectCode(''));
        $this->assertSame(301, ParkedDomainValidator::normalizeRedirectCode(301));
        $this->assertSame(301, ParkedDomainValidator::normalizeRedirectCode('301'));
        $this->assertSame(302, ParkedDomainValidator::normalizeRedirectCode(302));
        $this->assertSame(302, ParkedDomainValidator::normalizeRedirectCode('302'));
    }

    public function testRedirectCodeRejectsUnsupportedValues(): void
    {
        foreach ([307, 308, 300, 0, 'abc', true, 301.0] as $value) {
            try {
                ParkedDomainValidator::normalizeRedirectCode($value);
                $this->fail('harus melempar untuk: ' . json_encode($value));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('301', $e->getMessage());
                $this->assertStringContainsString('302', $e->getMessage());
            }
        }
    }

    // ==================================================================
    // Batas ukuran HTML
    // ==================================================================

    public function testHtmlSizeAcceptsExactlyOneMiB(): void
    {
        $html = str_repeat('x', ParkedDomainValidator::MAX_HTML_BYTES);
        ParkedDomainValidator::assertHtmlSize($html);
        $this->assertSame(1048576, strlen($html));
    }

    public function testHtmlSizeRejectsOneByteOverLimit(): void
    {
        $html = str_repeat('x', ParkedDomainValidator::MAX_HTML_BYTES + 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/1 MiB/');
        $this->expectExceptionMessageMatches('/1048577/');
        ParkedDomainValidator::assertHtmlSize($html);
    }

    // ==================================================================
    // Varian www
    // ==================================================================

    public function testSuggestedWwwVariant(): void
    {
        $this->assertSame('www.example.com', ParkedDomainValidator::suggestedWwwVariant(' Example.COM. '));
        $this->assertNull(ParkedDomainValidator::suggestedWwwVariant('www.example.com'));
        $this->assertNull(ParkedDomainValidator::suggestedWwwVariant(''));
    }

    // ==================================================================
    // Keunikan lintas app / custom domain / parkir
    // ==================================================================

    public function testConflictsWithAppEffectiveSubdomain(): void
    {
        $this->apps->create(['name' => 'blog', 'subdomain' => 'parked-x']);

        try {
            ParkedDomainValidator::assertDomainAvailable('parked-x.example.com', $this->apps, $this->parked);
            $this->fail('bentrok subdomain app harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('parked-x.example.com', $e->getMessage());
            $this->assertStringContainsString('blog', $e->getMessage(), 'pesan harus menyebut nama app');
        }

        // Case-insensitive: domain tersimpan/divalidasi dalam huruf kecil.
        $this->expectException(RuntimeException::class);
        ParkedDomainValidator::assertDomainAvailable('PARKED-X.Example.COM', $this->apps, $this->parked);
    }

    public function testConflictsWithAppFallbackNameSubdomain(): void
    {
        $this->apps->create(['name' => 'shop']); // tanpa field subdomain

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/shop\.example\.com/');
        ParkedDomainValidator::assertDomainAvailable('shop.example.com', $this->apps, $this->parked);
    }

    public function testConflictsWithLegacyFullFqdnSubdomain(): void
    {
        $this->apps->create(['name' => 'legacy', 'subdomain' => 'legacy.example.org']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/legacy\.example\.org/');
        ParkedDomainValidator::assertDomainAvailable('legacy.example.org', $this->apps, $this->parked);
    }

    public function testConflictsWithAppCustomDomain(): void
    {
        $this->apps->create(['name' => 'shop', 'custom_domain' => 'toko.example.net']);

        try {
            ParkedDomainValidator::assertDomainAvailable('toko.example.net', $this->apps, $this->parked);
            $this->fail('bentrok custom domain harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('toko.example.net', $e->getMessage());
            $this->assertStringContainsString('custom domain', $e->getMessage());
            $this->assertStringContainsString('shop', $e->getMessage());
        }
    }

    public function testConflictsWithOtherParkedEntryAndExcludeId(): void
    {
        $entry = $this->parked->create(['domain' => 'idle.test', 'mode' => 'html']);

        try {
            ParkedDomainValidator::assertDomainAvailable('IDLE.test', $this->apps, $this->parked);
            $this->fail('domain parkir duplikat harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('idle.test', $e->getMessage());
            $this->assertStringContainsString($entry['id'], $e->getMessage(), 'pesan harus menyebut id entri');
        }

        // Entri sendiri dikecualikan saat edit → tidak melempar.
        ParkedDomainValidator::assertDomainAvailable('idle.test', $this->apps, $this->parked, $entry['id']);
        $this->addToAssertionCount(1);

        // Domain bebas juga tidak melempar.
        ParkedDomainValidator::assertDomainAvailable('fresh.test', $this->apps, $this->parked);
        $this->addToAssertionCount(1);
    }
}
