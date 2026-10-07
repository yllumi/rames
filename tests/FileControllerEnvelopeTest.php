<?php
declare(strict_types=1);

namespace Tests;

use app\controller\FileController;
use app\library\Files\FileError;
use InvalidArgumentException;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use support\Log;
use Webman\Http\Response;

/**
 * Penjaga regresi envelope error `FileController::run()`.
 *
 * Menguji urutan `catch` + bentuk payload TANPA Docker/HTTP/sesi: method privat
 * `run()` dipanggil lewat reflection, callback-nya melempar exception, lalu
 * respons JSON (`Webman\Http\Response`) diperiksa.
 */
class FileControllerEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        // Isolasi: jadikan Log::error no-op (tanpa handler, tanpa baca config('log'))
        // agar jalur 500 dapat diuji hermetis tanpa menulis runtime/logs/webman.log.
        $instance = new ReflectionProperty(Log::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, ['default' => new Logger('default', [])]);
    }

    /**
     * Panggil `FileController::run()` (privat) dengan callback yang melempar.
     */
    private function invokeRun(callable $callback): Response
    {
        $controller = new FileController();
        $method = new ReflectionMethod(FileController::class, 'run');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $callback);
        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int,array{0:int,1:string}>
     */
    public static function fileErrorStatuses(): array
    {
        return [
            [400, 'Path harus absolut (diawali "/").'],
            [404, 'Berkas tidak ditemukan.'],
            [409, 'Container sedang tidak berjalan — jalankan app dulu.'],
            [413, 'Berkas melebihi batas 2 MiB untuk diedit sebagai teks.'],
            [415, 'Berkas biner tidak dapat diedit sebagai teks.'],
        ];
    }

    /**
     * @dataProvider fileErrorStatuses
     */
    public function testFileErrorKeepsStatusAndMessage(int $status, string $message): void
    {
        $response = $this->invokeRun(static function () use ($status, $message): void {
            throw new FileError($message, $status);
        });

        $body = $this->payload($response);
        $this->assertSame($status, $body['code']);
        $this->assertSame($message, $body['msg']);
    }

    public function testInvalidArgumentBecomes400WithOriginalMessage(): void
    {
        $response = $this->invokeRun(static function (): void {
            throw new InvalidArgumentException('Path harus absolut (diawali "/").');
        });

        $body = $this->payload($response);
        $this->assertSame(400, $body['code']);
        $this->assertSame('Path harus absolut (diawali "/").', $body['msg']);
    }

    public function testUnexpectedThrowableBecomesGeneric500WithoutLeakingDetails(): void
    {
        $secret = 'SECRET-MARKER-' . bin2hex(random_bytes(4)) . '-/var/run/docker.sock';
        $response = $this->invokeRun(static function () use ($secret): void {
            throw new RuntimeException($secret);
        });

        $body = $this->payload($response);
        $this->assertSame(500, $body['code']);
        $this->assertSame('Gagal memproses operasi berkas.', $body['msg']);
        // Detail internal TIDAK boleh bocor ke klien.
        $this->assertStringNotContainsString($secret, $response->rawBody());
        $this->assertStringNotContainsString('SECRET-MARKER', $response->rawBody());
        $this->assertStringNotContainsString('docker.sock', $response->rawBody());
    }

    public function testFileErrorWith500StatusKeepsItsOwnMessage(): void
    {
        // Pengunci urutan catch TANPA subclass: FileError berstatus 500 tetap
        // memakai pesan aslinya, bukan pesan generik jalur `catch (Throwable)` —
        // membuktikan `catch (FileError)` dievaluasi lebih dulu.
        $response = $this->invokeRun(static function (): void {
            throw new FileError('pesan asli 500', 500);
        });

        $body = $this->payload($response);
        $this->assertSame(500, $body['code']);
        $this->assertSame('pesan asli 500', $body['msg']);
        $this->assertNotSame('Gagal memproses operasi berkas.', $body['msg']);
    }
}
