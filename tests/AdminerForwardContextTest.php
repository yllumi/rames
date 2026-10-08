<?php
declare(strict_types=1);

namespace Tests;

use app\controller\DatabaseController;
use PHPUnit\Framework\TestCase;

/**
 * Konteks `X-Forwarded-*` yang dibentuk database controller untuk `AdminerProxy`
 * (statik murni, tanpa HTTP/Docker).
 *
 * Menutup temuan "controller belum mengisi forwarded_proto/forwarded_for":
 *  - `proto` hanya `https` bila listener Webman ber-TLS **atau** header skema
 *    datang dari peer internal (Nginx host/dashboard). Klien luar yang menembus
 *    port dashboard publik tidak bisa memalsukan skema.
 *  - `for` = REMOTE_ADDR tunggal yang valid; unspecified/tidak valid → kosong
 *    (proxy tidak mengirim `X-Forwarded-For`).
 */
class AdminerForwardContextTest extends TestCase
{
    // ==================================================================
    // proto
    // ==================================================================

    public function testDashboardOverPlainHttpStaysHttp(): void
    {
        // Nginx host meneruskan permintaan HTTP biasa (skema http).
        $ctx = DatabaseController::adminerProxyContext('172.18.0.1', 'http');

        $this->assertSame('http', $ctx['proto']);
        $this->assertSame('172.18.0.1', $ctx['for']);
    }

    public function testNginxForwardingHttpsMakesProtoHttps(): void
    {
        // Peer internal (Nginx host) + `X-Forwarded-Proto: https` ⇒ https.
        $this->assertSame('https', DatabaseController::adminerProxyContext('172.18.0.1', 'https')['proto']);
        $this->assertSame('https', DatabaseController::adminerProxyContext('127.0.0.1', 'https')['proto']);
        $this->assertSame('https', DatabaseController::adminerProxyContext('::1', 'https')['proto']);
        $this->assertSame('https', DatabaseController::adminerProxyContext('[::1]', 'https')['proto']);
        // Nilai env Nginx bisa datang dengan huruf besar/spasi.
        $this->assertSame('https', DatabaseController::adminerProxyContext('10.0.0.5', ' HTTPS ')['proto']);
    }

    public function testPublicClientCannotSpoofProto(): void
    {
        // Klien internet menembus port dashboard dan mengirim header palsu.
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34'] as $publicIp) {
            $this->assertSame(
                'http',
                DatabaseController::adminerProxyContext($publicIp, 'https')['proto'],
                'skema header dari klien publik tidak dipercaya'
            );
        }
    }

    public function testUnknownSchemeFallsBackToHttp(): void
    {
        foreach (['', 'ftp', 'https://x', 'on', '1', 'HTTP'] as $scheme) {
            $this->assertSame('http', DatabaseController::adminerProxyContext('172.18.0.1', $scheme)['proto'], $scheme);
        }
    }

    public function testTlsListenerIsHttpsEvenWithoutProxyHeader(): void
    {
        $this->assertSame('https', DatabaseController::adminerProxyContext('8.8.8.8', '', true)['proto']);
    }

    // ==================================================================
    // for
    // ==================================================================

    public function testForwardedForIsTheDashboardRemoteAddressOnly(): void
    {
        $this->assertSame('203.0.113.9', DatabaseController::adminerProxyContext('203.0.113.9', '')[ 'for']);
        $this->assertSame('2001:db8::1', DatabaseController::adminerProxyContext('2001:db8::1', '')['for']);
    }

    public function testForwardedForIsEmptyWhenRemoteAddressIsUnusable(): void
    {
        foreach (['', '0.0.0.0', '::', 'bukan-ip', '8.8.8.8, 1.1.1.1'] as $remoteIp) {
            $this->assertSame('', DatabaseController::adminerProxyContext($remoteIp, '')['for'], '"' . $remoteIp . '"');
        }
    }
}
