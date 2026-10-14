<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentError;
use PHPUnit\Framework\TestCase;

/**
 * Higienitas pesan galat agent — **satu pintu** `AgentError::sanitizeForServer()`.
 *
 * Yang dijaga: token server, `base_url`/host internal (URL penuh, `//host:port`,
 * `host:port`, atau host saja seperti yang muncul di galat curl/DNS), blok PEM,
 * dan header `Authorization` **tidak pernah** ikut ke pesan yang ditampilkan
 * controller. `AgentClient` sendiri menyisipkan `base_url` pada galat transport
 * ("Agent tidak bisa dihubungi (https://…): …"), jadi redaksi di batas UI ini
 * wajib — jangan disalin ke controller.
 */
class AgentErrorTest extends TestCase
{
    private const SERVER = [
        'id' => 'srv-jakarta',
        'name' => 'vps-jakarta',
        'kind' => 'agent',
        'base_url' => 'https://srv-jakarta.internal.test:8443',
        'token' => 'tok-rahasia-srv-jakarta',
    ];

    public function testRedactsTokenAndBaseUrlFromTransportMessages(): void
    {
        $message = 'Agent tidak bisa dihubungi (https://srv-jakarta.internal.test:8443): '
            . 'Gagal menghubungi srv-jakarta.internal.test dengan token tok-rahasia-srv-jakarta: '
            . 'Connection refused';

        $clean = AgentError::sanitizeForServer($message, self::SERVER);

        $this->assertStringNotContainsString('tok-rahasia-srv-jakarta', $clean);
        $this->assertStringNotContainsString('https://srv-jakarta.internal.test:8443', $clean);
        $this->assertStringNotContainsString('srv-jakarta.internal.test', $clean, 'host internal tidak boleh bocor');
        $this->assertStringContainsString('server agent', $clean, 'pengganti tetap informatif');
        $this->assertStringContainsString('Connection refused', $clean, 'sebab galat tetap terbaca');
    }

    public function testRedactsUrlEncodedTokenAndAuthorizationHeader(): void
    {
        $token = 'tok dengan spasi/+';
        $server = ['token' => $token, 'base_url' => 'https://agent.internal.test'];

        $clean = AgentError::sanitizeForServer(
            'token=' . rawurlencode($token) . ' Authorization: Bearer abc.def.ghi https://agent.internal.test/healthz',
            $server
        );

        $this->assertStringNotContainsString(rawurlencode($token), $clean);
        $this->assertStringNotContainsString($token, $clean);
        $this->assertStringNotContainsString('abc.def.ghi', $clean);
        $this->assertStringNotContainsString('agent.internal.test', $clean);
    }

    public function testRedactsPemBlocks(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
        $clean = AgentError::sanitizeForServer('Sertifikat cacat: ' . $pem, self::SERVER);

        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $clean);
        $this->assertStringNotContainsString('MIIB', $clean);
        $this->assertStringContainsString('[redacted]', $clean);
    }

    public function testServerWithoutIdentityFallsBackToPlainSanitize(): void
    {
        $message = 'Agent tidak menyertakan pesan ' . "\n" . '  ganda';

        $this->assertSame(
            AgentError::sanitize($message),
            AgentError::sanitizeForServer($message, [])
        );
        $this->assertSame('', AgentError::sanitizeForServer(''));
    }

    public function testMessageLengthIsStillCapped(): void
    {
        $clean = AgentError::sanitizeForServer(str_repeat('x ', 2000), self::SERVER);

        // `sanitize()` memotong ke `MAX_MESSAGE_LENGTH` karakter lalu menambah
        // penanda elipsis (perilaku lama) — toleransi 4 byte untuk penanda itu.
        $this->assertLessThanOrEqual(AgentError::MAX_MESSAGE_LENGTH + 4, strlen($clean));
        $this->assertStringEndsWith('…', $clean);
    }
}
