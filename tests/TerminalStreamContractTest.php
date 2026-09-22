<?php
declare(strict_types=1);

namespace Tests;

use app\controller\TerminalController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Kontrak respons SSE terminal (regresi produksi — gejalanya hanya terlihat di browser).
 *
 *  1. Kelas respons WAJIB `Webman\Http\Response`. Pipeline middleware Webman
 *     (`App::collectCallbacks`) dan `App::send()` memakai
 *     `$response instanceof Webman\Http\Response`; memakai INDUKnya
 *     (`Workerman\Protocols\Http\Response`) membuat respons di-stringify lalu
 *     dibungkus ulang sebagai `Content-Type: text/html;charset=utf-8` — sehingga
 *     `EventSource` menolak dengan "MIME type ... is not text/event-stream".
 *  2. `Transfer-Encoding: chunked` wajib ada: hanya respons chunked yang dikirim
 *     Webman lewat jalur streaming (`$connection->send()`) tanpa `close()`.
 *     Untuk request `Connection: close` / HTTP/1.0 (default `proxy_http_version
 *     1.0` nginx) respons non-chunked ditutup seketika → header SSE terkirim
 *     tanpa satu pun event.
 *
 * Verifikasi perilaku (bukan sekadar kontrak) sudah dilakukan manual lewat nginx
 * dengan konfigurasi proxy bawaan; test ini menjaga kontraknya tetap utuh.
 */
class TerminalStreamContractTest extends TestCase
{
    public function testSseHeadersUseEventStreamAndChunked(): void
    {
        $headers = $this->sseHeaders();

        $this->assertSame('text/event-stream', $headers['Content-Type'] ?? null);
        $this->assertSame('chunked', $headers['Transfer-Encoding'] ?? null);
        $this->assertSame('no', $headers['X-Accel-Buffering'] ?? null);
    }

    public function testControllerUsesWebmanResponseClass(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/controller/TerminalController.php');

        $this->assertStringContainsString(
            'use Webman\Http\Response;',
            $source,
            'Respons SSE harus memakai Webman\Http\Response agar dikenali middleware & App::send().'
        );
        $this->assertStringNotContainsString(
            'use Workerman\Protocols\Http\Response;',
            $source,
            'Workerman\Protocols\Http\Response adalah induk dari Webman\Http\Response: '
            . 'cek instanceof gagal → respons dibungkus jadi text/html dan stream ditutup.'
        );
    }

    /**
     * @return array<string,string>
     */
    private function sseHeaders(): array
    {
        /** @var array<string,mixed> $constants */
        $constants = (new ReflectionClass(TerminalController::class))->getConstants();
        $this->assertArrayHasKey('SSE_HEADERS', $constants, 'Kontrak header SSE hilang.');

        /** @var array<string,string> $headers */
        $headers = $constants['SSE_HEADERS'];
        $this->assertIsArray($headers);

        return $headers;
    }
}
