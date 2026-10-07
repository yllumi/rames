<?php
declare(strict_types=1);

namespace Tests;

use app\library\Nginx\NginxRoutes;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test NginxRoutes — struktur data rute proxy tambahan per app
 * (apps.json: `nginx_routes`).
 *
 * Menjaga: validasi ketat (path/target), batas MAX, toleransi data rusak di
 * all(), serta round-trip textarea parse()/toText().
 */
class NginxRoutesTest extends TestCase
{
    public function testNormalizeValidRoutesKeepsPathAndTarget(): void
    {
        $routes = NginxRoutes::normalize([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/gateway/v1', 'target' => 'https://upstream.internal:8443/base'],
        ]);

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/gateway/v1', 'target' => 'https://upstream.internal:8443/base'],
        ], $routes);
    }

    public function testNormalizeStripsUnknownFields(): void
    {
        $routes = NginxRoutes::normalize([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001', 'note' => 'abaikan'],
        ]);

        $this->assertSame([['path' => '/api/', 'target' => 'http://127.0.0.1:3001']], $routes);
    }

    public function testNormalizeRejectsRootPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Path rute "\/" tidak diizinkan/');

        NginxRoutes::normalize([['path' => '/', 'target' => 'http://127.0.0.1:3001']]);
    }

    public function testNormalizeRejectsWellKnownPrefix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\.well-known/');

        NginxRoutes::normalize([
            ['path' => '/.well-known/acme-challenge/', 'target' => 'http://127.0.0.1:3001'],
        ]);
    }

    public function testNormalizeRejectsDuplicatePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\/api\/.*dipakai lebih dari satu kali/');

        NginxRoutes::normalize([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3002'],
        ]);
    }

    public function testNormalizeRejectsRelativePathSegments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Path rute #1 tidak boleh mengandung "\.\."/');

        NginxRoutes::normalize([['path' => '/../etc/', 'target' => 'http://127.0.0.1:3001']]);
    }

    public function testNormalizeRejectsRelativePathSegmentInTheMiddle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak boleh mengandung "\.\."/');

        NginxRoutes::normalize([['path' => '/a/../b', 'target' => 'http://127.0.0.1:3001']]);
    }

    public function testNormalizeAcceptsDotInsidePathSegment(): void
    {
        $routes = NginxRoutes::normalize([['path' => '/a.b/', 'target' => 'http://127.0.0.1:3001']]);

        $this->assertSame([['path' => '/a.b/', 'target' => 'http://127.0.0.1:3001']], $routes);
    }

    public function testNormalizeRejectsPathWithUnsupportedCharacters(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Path rute #1 tidak valid/');

        NginxRoutes::normalize([['path' => '/api v1/', 'target' => 'http://127.0.0.1:3001']]);
    }

    public function testNormalizeRejectsTooLongPath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/terlalu panjang/');

        NginxRoutes::normalize([
            ['path' => '/' . str_repeat('a', 200), 'target' => 'http://127.0.0.1:3001'],
        ]);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function invalidTargets(): array
    {
        return [
            'skema ftp' => ['ftp://127.0.0.1:3001'],
            'unix socket' => ['unix:/var/run/app.sock'],
            'tanpa skema' => ['127.0.0.1:3001'],
            'spasi' => ['http://127.0.0.1:3001/a b'],
            'userinfo' => ['http://user:pass@127.0.0.1:3001'],
            'query' => ['http://127.0.0.1:3001?a=1'],
            'fragment' => ['http://127.0.0.1:3001#x'],
            'port 0' => ['http://127.0.0.1:0'],
            'port terlalu besar' => ['http://127.0.0.1:70000'],
        ];
    }

    /**
     * @dataProvider invalidTargets
     */
    public function testNormalizeRejectsInvalidTargets(string $target): void
    {
        $this->expectException(RuntimeException::class);

        NginxRoutes::normalize([['path' => '/api/', 'target' => $target]]);
    }

    public function testNormalizeRejectsTargetWithParentTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\.\./');

        NginxRoutes::normalize([['path' => '/api/', 'target' => 'http://127.0.0.1:3001/a/../b']]);
    }

    public function testNormalizeRejectsMoreThanMax(): void
    {
        $routes = [];
        for ($i = 0; $i <= NginxRoutes::MAX; $i++) {
            $routes[] = ['path' => "/r{$i}/", 'target' => 'http://127.0.0.1:3001'];
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maksimal 20 rute proxy per app.');

        NginxRoutes::normalize($routes);
    }

    public function testNormalizeRejectsNonList(): void
    {
        $this->expectException(RuntimeException::class);

        NginxRoutes::normalize('bukan array');
    }

    /**
     * @return array<string,array{0:array}>
     */
    public static function damagedApps(): array
    {
        return [
            'absen' => [[]],
            'non-array' => [['nginx_routes' => 'oops']],
            'entri non-array' => [['nginx_routes' => ['/api/ http://127.0.0.1:3001']]],
            'path non-string' => [['nginx_routes' => [['path' => 1, 'target' => 'http://x']]]],
            'target non-string' => [['nginx_routes' => [['path' => '/api/', 'target' => null]]]],
            'isi tidak valid' => [['nginx_routes' => [['path' => '/api v1/', 'target' => 'ftp://x']]]],
        ];
    }

    /**
     * Data rusak yang harus menghasilkan daftar kosong (tetap seperti semula).
     *
     * @dataProvider damagedApps
     */
    public function testAllReturnsEmptyForAbsentOrNonArrayData(array $app): void
    {
        $this->assertSame([], NginxRoutes::all($app));
    }

    public function testAllKeepsValidRoutesWhenOneEntryHasInvalidContent(): void
    {
        $app = ['nginx_routes' => [
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/rusak/', 'target' => 'ftp://127.0.0.1:3002'],
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3003'],
        ]];

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3003'],
        ], NginxRoutes::all($app));
    }

    public function testAllSkipsNonArrayEntryInTheMiddle(): void
    {
        $app = ['nginx_routes' => [
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            'bukan array',
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3002'],
        ]];

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3002'],
        ], NginxRoutes::all($app));
    }

    public function testAllSkipsEntryWithNonStringFieldButKeepsOthers(): void
    {
        $app = ['nginx_routes' => [
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/assets/', 'target' => null],
            ['path' => 7, 'target' => 'http://127.0.0.1:3002'],
            ['path' => '/ws/', 'target' => 'http://127.0.0.1:3003'],
        ]];

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/ws/', 'target' => 'http://127.0.0.1:3003'],
        ], NginxRoutes::all($app));
    }

    public function testAllKeepsFirstOccurrenceOfDuplicatePath(): void
    {
        $app = ['nginx_routes' => [
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/api/', 'target' => 'http://127.0.0.1:9999'],
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3002'],
        ]];

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3002'],
        ], NginxRoutes::all($app));
    }

    public function testAllCapsAtMaxRoutes(): void
    {
        $raw = [];
        for ($i = 0; $i <= NginxRoutes::MAX; $i++) {
            $raw[] = ['path' => "/r{$i}/", 'target' => 'http://127.0.0.1:3001'];
        }

        $routes = NginxRoutes::all(['nginx_routes' => $raw]);

        $this->assertCount(NginxRoutes::MAX, $routes);
        $this->assertSame('/r0/', $routes[0]['path']);
        $this->assertSame('/r19/', $routes[NginxRoutes::MAX - 1]['path']);
    }

    public function testAllReturnsNormalizedRoutes(): void
    {
        $app = ['nginx_routes' => [['path' => '/api/', 'target' => 'http://127.0.0.1:3001', 'extra' => 1]]];

        $this->assertSame(
            [['path' => '/api/', 'target' => 'http://127.0.0.1:3001']],
            NginxRoutes::all($app)
        );
    }

    public function testParseAndToTextRoundTripWithCommentsAndBlankLines(): void
    {
        $text = "# rute tambahan\n/api/ http://127.0.0.1:3001\n\n/assets/\thttp://127.0.0.1:3002   \n";

        $routes = NginxRoutes::parse($text);

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/assets/', 'target' => 'http://127.0.0.1:3002'],
        ], $routes);

        $rendered = NginxRoutes::toText($routes);
        $this->assertSame("/api/ http://127.0.0.1:3001\n/assets/ http://127.0.0.1:3002", $rendered);
        $this->assertSame($routes, NginxRoutes::parse($rendered));
    }

    public function testParseEmptyTextYieldsNoRoutes(): void
    {
        $this->assertSame([], NginxRoutes::parse("\n  \n# hanya komentar\n"));
    }

    public function testParseErrorMentionsLineNumber(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Baris 2/');

        NginxRoutes::parse("/api/ http://127.0.0.1:3001\nhanya-satu-token\n");
    }

    public function testParseErrorLineNumberCountsCommentsAndBlankLines(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Baris 4/');

        NginxRoutes::parse("# komentar\n\n/api/ http://127.0.0.1:3001\n/cdn/ http://a http://b\n");
    }

    public function testParseContentErrorMentionsRealTextareaLineNumber(): void
    {
        // Baris 1 komentar, baris 2 kosong, baris 3 isi invalid → pesan "Baris 3",
        // bukan indeks entri "#1".
        try {
            NginxRoutes::parse("# rute tambahan\n\n/api v1/ http://127.0.0.1:3001\n");
            $this->fail('parse() seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Baris 3:', $e->getMessage());
            $this->assertStringNotContainsString('Baris 1', $e->getMessage());
        }
    }

    public function testParseTargetErrorMentionsRealTextareaLineNumber(): void
    {
        try {
            NginxRoutes::parse("\n\n/api/ ftp://127.0.0.1:3001\n");
            $this->fail('parse() seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Baris 3:', $e->getMessage());
            $this->assertStringContainsString('Target rute', $e->getMessage());
        }
    }

    public function testParseRelativePathErrorMentionsLineNumberAndDotDot(): void
    {
        try {
            NginxRoutes::parse("# rute\n/api/ http://127.0.0.1:3001\n/../etc/ http://127.0.0.1:3002\n");
            $this->fail('parse() seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Baris 3:', $e->getMessage());
            $this->assertStringContainsString('..', $e->getMessage());
        }
    }

    public function testParseDuplicatePathErrorMentionsPathWithoutLineNumber(): void
    {
        try {
            NginxRoutes::parse("/api/ http://127.0.0.1:3001\n/api/ http://127.0.0.1:3002\n");
            $this->fail('parse() seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('/api/', $e->getMessage());
            $this->assertStringContainsString('lebih dari satu kali', $e->getMessage());
        }
    }

    public function testParseStillEnforcesMaxAcrossLines(): void
    {
        $lines = [];
        for ($i = 0; $i <= NginxRoutes::MAX; $i++) {
            $lines[] = "/r{$i}/ http://127.0.0.1:3001";
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maksimal 20 rute proxy per app.');

        NginxRoutes::parse(implode("\n", $lines));
    }

    public function testParseValidMultiLineStillWorksAfterPerLineValidation(): void
    {
        $routes = NginxRoutes::parse("# a\n/api/ http://127.0.0.1:3001\n\n/gateway/ http://127.0.0.1:3002\n");

        $this->assertSame([
            ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
            ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3002'],
        ], $routes);
    }

    public function testParseDelegatesContentValidationWithLineNumber(): void
    {
        try {
            NginxRoutes::parse("/api/ ftp://127.0.0.1:3001\n");
            $this->fail('parse() seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Baris 1:', $e->getMessage());
            $this->assertStringContainsString('Target rute', $e->getMessage());
        }
    }
}
