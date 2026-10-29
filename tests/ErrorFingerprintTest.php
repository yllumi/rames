<?php
declare(strict_types=1);

namespace Tests;

use app\library\Errors\ErrorFingerprint;
use app\library\Errors\ErrorInput;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test {@see ErrorFingerprint} — normalisasi pesan/angka/UUID/path dan
 * sensitivitas terhadap kelas exception + frame aplikasi.
 */
class ErrorFingerprintTest extends TestCase
{
    // ------------------------------------------------------------------
    // normalisasi variabel acak
    // ------------------------------------------------------------------

    public function testNumbersUuidAndQuotesNormalizeToSameFingerprint(): void
    {
        $groups = [
            'angka' => ['Gagal memuat 42 item', 'Gagal memuat 7 item', 'Gagal memuat 999 item'],
            'uuid' => [
                'Gagal memuat 550e8400-e29b-41d4-a716-446655440000 item',
                'Gagal memuat 123e4567-e89b-12d3-a456-426614174000 item',
            ],
            'kutip' => ['Gagal memuat "abc" item', "Gagal memuat 'xyz' item"],
            'hex' => ['Gagal memuat 0xDEADBEEF item', 'Gagal memuat 0x1a2b item'],
        ];

        foreach ($groups as $label => $messages) {
            $fingerprints = [];
            foreach ($messages as $message) {
                $fingerprints[] = ErrorFingerprint::client([
                    'kind' => 'client',
                    'message' => $message,
                    'route' => '/apps/demo',
                ]);
            }

            self::assertCount(1, array_unique($fingerprints), 'variasi ' . $label . ' harus satu grup');
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprints[0]);
        }
    }

    public function testAbsolutePathsNormalize(): void
    {
        $a = ErrorFingerprint::client([
            'kind' => 'client',
            'message' => 'Gagal baca /home/budi/project/file.php baris 9',
            'route' => '/apps/demo',
        ]);
        $b = ErrorFingerprint::client([
            'kind' => 'client',
            'message' => 'Gagal baca /var/www/other/app.php baris 200',
            'route' => '/apps/demo',
        ]);

        self::assertSame($a, $b);
    }

    public function testDifferentMessageProducesDifferentFingerprint(): void
    {
        $a = ErrorFingerprint::client(['kind' => 'client', 'message' => 'Gagal memuat data', 'route' => '/apps/a']);
        $b = ErrorFingerprint::client(['kind' => 'client', 'message' => 'Gagal menyimpan data', 'route' => '/apps/a']);

        self::assertNotSame($a, $b);
    }

    public function testKindIsPartOfFingerprint(): void
    {
        $a = ErrorFingerprint::client(['kind' => 'client', 'message' => 'Sama', 'route' => '/x']);
        $b = ErrorFingerprint::client(['kind' => 'server', 'message' => 'Sama', 'route' => '/x']);

        self::assertNotSame($a, $b);
    }

    // ------------------------------------------------------------------
    // top frame
    // ------------------------------------------------------------------

    public function testTopAppFrameSeparatesStackOrigins(): void
    {
        $a = ErrorFingerprint::client([
            'kind' => 'client',
            'message' => 'Boom',
            'stack' => "Error: Boom\n    at run (/app/public/js/app-terminal.js:12:3)\n    at main (/app/public/js/app.js:1:1)",
            'route' => '/apps/demo',
        ]);
        $b = ErrorFingerprint::client([
            'kind' => 'client',
            'message' => 'Boom',
            'stack' => "Error: Boom\n    at run (/app/public/js/app-files.js:99:3)",
            'route' => '/apps/demo',
        ]);

        self::assertNotSame($a, $b, 'frame stack berbeda = grup berbeda');
    }

    public function testRouteIsFallbackWhenNoAppFrame(): void
    {
        $a = ErrorFingerprint::client(['kind' => 'client', 'message' => 'Boom', 'stack' => 'at <anonymous>', 'route' => '/apps/a']);
        $b = ErrorFingerprint::client(['kind' => 'client', 'message' => 'Boom', 'stack' => 'at <anonymous>', 'route' => '/apps/b']);

        self::assertNotSame($a, $b);
    }

    // ------------------------------------------------------------------
    // server
    // ------------------------------------------------------------------

    public function testServerFingerprintUsesClassMessageAndAppFrame(): void
    {
        try {
            ErrorInput::clientPayload([], '');
            self::fail('ErrorInput harus melempar untuk payload kosong');
        } catch (Throwable $e) {
            $first = ErrorFingerprint::server($e, '/apps/a');
            $second = ErrorFingerprint::server($e, '/apps/b');
        }

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first);
        self::assertSame($first, $second, 'frame aplikasi menang atas route');
        self::assertStringStartsWith('app/library/Errors/ErrorInput.php:', ErrorFingerprint::serverLocation($e));
    }

    public function testServerFingerprintDiffersByClass(): void
    {
        try {
            ErrorInput::clientPayload([], '');
            self::fail('harus melempar');
        } catch (Throwable $appException) {
            $inputFingerprint = ErrorFingerprint::server($appException, '/apps/a');
        }

        $plain = new RuntimeException('Pesan yang sama');
        $plainFingerprint = ErrorFingerprint::server($plain, '/apps/a');

        self::assertNotSame($inputFingerprint, $plainFingerprint);
    }

    public function testServerFingerprintFallsBackToRouteWithoutAppFrame(): void
    {
        $e = new RuntimeException('Tidak ada frame aplikasi');

        self::assertSame('', ErrorFingerprint::serverLocation($e));
        self::assertNotSame(
            ErrorFingerprint::server($e, '/apps/a'),
            ErrorFingerprint::server($e, '/apps/b')
        );
    }

    // ------------------------------------------------------------------
    // tidak pernah melempar
    // ------------------------------------------------------------------

    public function testNeverThrowsOnHostileInput(): void
    {
        $event = [
            'kind' => ['client'],
            'message' => new \stdClass(),
            'stack' => ['bukan string'],
            'route' => null,
        ];

        $fingerprint = ErrorFingerprint::client($event);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);

        $invalidUtf8 = ErrorFingerprint::client([
            'kind' => 'client',
            'message' => "rusak \xB1\x31",
            'route' => "/apps/\xFF",
        ]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $invalidUtf8);

        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            ErrorFingerprint::server(new RuntimeException(''), str_repeat('x', 5000))
        );

        self::assertSame('', ErrorFingerprint::normalize(''));
        self::assertSame('?', ErrorFingerprint::normalize('""'), 'string kutip kosong dinormalisasi');
    }
}
