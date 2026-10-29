<?php
declare(strict_types=1);

namespace Tests;

use app\library\Errors\ErrorInput;
use app\library\Errors\ErrorRedactor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see ErrorInput} — validasi & normalisasi laporan error klien.
 *
 * Yang dijaga: kunci hasil persis kontrak (Backend memakai `extract()`),
 * `_token` CSRF & kunci asing tidak pernah bocor ke hasil, semua batas ukuran
 * ditegakkan, redaksi benar-benar terpakai, dan pesan galat tidak menggemakan
 * nilai input. Tanpa I/O — tidak menyentuh data runtime.
 */
class ErrorInputTest extends TestCase
{
    private const SECRET = 'tok' . 'en-' . 'RAHASIA' . '987654';

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'client',
            'message' => 'TypeError: x is undefined',
            'stack' => "at foo (https://cdn.test/app.js:1:2)\nat bar (app.js:3:4)",
            'source' => 'https://cdn.test/app.js',
            'line' => 1,
            'column' => 2,
            'route' => '/apps/app-123/logs',
            'context' => ['component' => 'logs', 'password' => 'hunter2'],
            'occurred_at' => '2026-10-29T05:58:56+07:00',
            '_token' => 'csrf-' . self::SECRET,
            'foreign_key' => 'abaikan',
        ], $overrides);
    }

    public function testNormalizesValidPayloadAndDropsForeignKeys(): void
    {
        $out = ErrorInput::clientPayload(self::payload(), 'Mozilla/5.0 (Linux)');

        $this->assertSame([
            'kind',
            'message',
            'stack',
            'source',
            'line',
            'column',
            'route',
            'app_id',
            'occurred_at',
            'context',
            'user_agent',
        ], array_keys($out), 'kunci hasil persis kontrak');

        $this->assertSame('client', $out['kind']);
        $this->assertSame('/apps/app-123/logs', $out['route']);
        $this->assertSame('app-123', $out['app_id'], 'app_id diekstrak dari route');
        $this->assertSame(1, $out['line']);
        $this->assertSame(2, $out['column']);
        $this->assertSame('2026-10-29T05:58:56+07:00', $out['occurred_at']);
        $this->assertSame('Mozilla/5.0 (Linux)', $out['user_agent']);
        $this->assertSame(['component' => 'logs', 'password' => '[REDACTED]'], $out['context']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($out) ?: '', '_token tidak boleh bocor');
        $this->assertArrayNotHasKey('_token', $out);
        $this->assertArrayNotHasKey('foreign_key', $out);
    }

    public function testDropsQueryStringFromRouteAndAppIdFromRoute(): void
    {
        $out = ErrorInput::clientPayload(
            self::payload(['route' => '/apps/abc_1?token=' . self::SECRET . '#x', 'app_id' => '']),
            ''
        );

        $this->assertSame('/apps/abc_1', $out['route']);
        $this->assertSame('abc_1', $out['app_id']);
        $this->assertStringNotContainsString(self::SECRET, $out['route']);
    }

    public function testAppIdFromInputOnlyWhenPatternMatches(): void
    {
        $out = ErrorInput::clientPayload(self::payload(['app_id' => 'app-999', 'route' => '/dashboard']), '');
        $this->assertSame('app-999', $out['app_id']);

        $this->expectException(InvalidArgumentException::class);
        ErrorInput::clientPayload(self::payload(['app_id' => 'app id!']), '');
    }

    public function testRedactsMessageStackAndUserAgent(): void
    {
        $out = ErrorInput::clientPayload(
            self::payload([
                'message' => 'gagal memanggil API dengan password=hunter2',
                'stack' => 'Authorization: Bearer ' . self::SECRET,
            ]),
            'UA Bearer ' . self::SECRET
        );

        $this->assertStringNotContainsString('hunter2', $out['message']);
        $this->assertStringContainsString('[REDACTED]', $out['message']);
        $this->assertSame('Authorization: Bearer [REDACTED]', $out['stack']);
        $this->assertStringNotContainsString(self::SECRET, $out['user_agent']);
        $this->assertStringContainsString('Bearer [REDACTED]', $out['user_agent']);
    }

    public function testUserAgentIsTruncatedTo512Bytes(): void
    {
        $out = ErrorInput::clientPayload(self::payload(), str_repeat('é', 400)); // 800 byte

        $this->assertLessThanOrEqual(ErrorInput::MAX_USER_AGENT_BYTES, strlen($out['user_agent']));
        $this->assertTrue(mb_check_encoding($out['user_agent'], 'UTF-8'));
    }

    public function testRejectsUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ErrorInput::clientPayload(self::payload(['kind' => 'server']), '');
    }

    public function testRejectsMissingOrEmptyMessage(): void
    {
        foreach (['', '   '] as $message) {
            try {
                ErrorInput::clientPayload(self::payload(['message' => $message]), '');
                $this->fail('pesan kosong harus ditolak');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString($message === '' ? '###' : '   ', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        ErrorInput::clientPayload(self::payload(['message' => ['bukan string']]), '');
    }

    public function testMessageLimitIsCountedInCharacters(): void
    {
        $limit = 2000;
        $out = ErrorInput::clientPayload(self::payload(['message' => str_repeat('é', $limit)]), '');
        $this->assertSame($limit, mb_strlen($out['message']), 'tepat di batas masih diterima');

        $this->expectException(InvalidArgumentException::class);
        ErrorInput::clientPayload(self::payload(['message' => str_repeat('é', $limit + 1)]), '');
    }

    public function testRejectsOversizedPayloadStackSourceAndRoute(): void
    {
        $cases = [
            'payload' => self::payload(['blob' => str_repeat('X', 20000)]),
            'stack' => self::payload(['stack' => str_repeat('s', 8193)]),
            'source' => self::payload(['source' => str_repeat('s', 513)]),
            'route' => self::payload(['route' => '/' . str_repeat('r', 512)]),
        ];

        foreach ($cases as $label => $payload) {
            try {
                ErrorInput::clientPayload($payload, '');
                $this->fail('harus ditolak: ' . $label);
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('XXXX', $e->getMessage(), 'pesan tidak memuat nilai input');
                $this->assertStringNotContainsString('rrrr', $e->getMessage());
            }
        }
    }

    public function testRejectsRouteWithoutLeadingSlash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ErrorInput::clientPayload(self::payload(['route' => 'apps/abc']), '');
    }

    public function testPositionValidation(): void
    {
        $out = ErrorInput::clientPayload(self::payload(['line' => '42', 'column' => null]), '');
        $this->assertSame(42, $out['line']);
        $this->assertNull($out['column']);

        $out = ErrorInput::clientPayload(self::payload(['line' => 1000000, 'column' => '']), '');
        $this->assertSame(1_000_000, $out['line']);
        $this->assertNull($out['column']);

        foreach ([0, -1, 1_000_001, 'abc', 1.5, true, [3]] as $bad) {
            try {
                ErrorInput::clientPayload(self::payload(['line' => $bad]), '');
                $this->fail('posisi tidak sah harus ditolak: ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('abc', $e->getMessage());
            }
        }
    }

    public function testInvalidOccurredAtBecomesNull(): void
    {
        foreach ([
            'besok pagi',
            '2026-02-30T10:00:00Z', // tanggal mustahil (jangan dinormalkan)
            '2026-13-01T10:00:00Z',
            '2026-10-29T25:00:00Z',
            '2026-10-29T10:61:00Z',
            1719999999,
            ['2026-10-29'],
        ] as $bad) {
            $out = ErrorInput::clientPayload(self::payload(['occurred_at' => $bad]), '');
            $this->assertNull($out['occurred_at'], 'occurred_at tak sah ⇒ null');
        }

        $out = ErrorInput::clientPayload(self::payload(['occurred_at' => null]), '');
        $this->assertNull($out['occurred_at']);

        $naive = ErrorInput::clientPayload(self::payload(['occurred_at' => '2026-10-29T05:58:56']), '');
        $this->assertIsString($naive['occurred_at']);
        $this->assertStringStartsWith('2026-10-29T05:58:56', (string) $naive['occurred_at']);
    }

    public function testContextIsRedactedRecursively(): void
    {
        $out = ErrorInput::clientPayload(self::payload([
            'context' => [
                'token' => self::SECRET,
                '_token' => 'csrf-abc',
                'nested' => ['api_key' => self::SECRET, 'ok' => 'v'],
                'score' => 5,
            ],
        ]), '');

        $this->assertSame('[REDACTED]', $out['context']['token']);
        $this->assertSame('[REDACTED]', $out['context']['_token']);
        $this->assertSame(['api_key' => '[REDACTED]', 'ok' => 'v'], $out['context']['nested']);
        $this->assertArrayNotHasKey('score', $out['context'], 'nilai non-string dibuang');
        $this->assertStringNotContainsString(self::SECRET, json_encode($out['context']) ?: '');
    }

    public function testNonArrayContextIsIgnoredInsteadOfExploding(): void
    {
        $out = ErrorInput::clientPayload(self::payload(['context' => 'bukan array']), '');
        $this->assertSame([], $out['context']);
    }

    public function testErrorMessageNeverEchoesInputValues(): void
    {
        try {
            ErrorInput::clientPayload(self::payload([
                'kind' => 'client',
                'message' => 'x',
                'blob' => str_repeat('Z', 20000),
                'secret_marker' => 'nilai-rahasia-xyz',
            ]), '');
            $this->fail('payload raksasa harus ditolak');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('nilai-rahasia-xyz', $e->getMessage());
            $this->assertStringNotContainsString('ZZZZ', $e->getMessage());
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testCustomLimitsAreHonored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ErrorInput::clientPayload(self::payload(['message' => 'x']), '', 50);
    }

    public function testMaxStackBytesZeroFallsBackToDefaultBudget(): void
    {
        $out = ErrorInput::clientPayload(
            self::payload(['stack' => 'a']),
            '',
            16384,
            2000,
            0
        );
        $this->assertSame('a', $out['stack'], 'batas 0 tidak memotong stack');
    }

    public function testMessageRedactionDoesNotShortenValidMessageBelowLimit(): void
    {
        $long = str_repeat('a', 2000);
        $out = ErrorInput::clientPayload(self::payload(['message' => $long]), '');

        $this->assertSame(2000, strlen($out['message']));
        $this->assertStringNotContainsString(ErrorRedactor::TRUNCATION_SUFFIX, $out['message']);
    }
}
