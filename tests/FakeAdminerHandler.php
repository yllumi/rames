<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Handler Guzzle tiruan untuk menguji `AdminerProxy::forward()` tanpa jaringan,
 * daemon Docker, atau server lokal.
 *
 * Berbeda dari `MockHandler` bawaan Guzzle, handler ini **menulis body ke
 * `sink`** seperti handler nyata — sehingga jalur produksi (berkas temp +
 * `LimitedTempSink` + deteksi halaman login + retry) benar-benar teruji, dan
 * jumlah panggilan HTTP bisa dihitung (bukti "retry paling banyak sekali").
 *
 * @param mixed $request
 * @param array<string,mixed> $options
 */
final class FakeAdminerHandler
{
    /** @var array<int,array{method:string,uri:string,body:string,cookie:string,headers:array<string,array<int,string>>}> */
    public array $calls = [];

    /** @var array<int,array{status:int,headers:array<string,string>,body:string}> */
    private array $queue = [];

    /**
     * Antrikan satu respons (urut sesuai pemakaian).
     *
     * @param array<string,string|array<int,string>> $headers
     */
    public function reply(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ];

        return $this;
    }

    /**
     * Panggilan HTTP yang tercatat (metode + URI + body + cookie + header).
     *
     * @return array<int,array{method:string,uri:string,body:string,cookie:string,headers:array<string,array<int,string>>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Nilai header (case-insensitive) pada panggilan ke-`$index`; '' bila tidak ada.
     */
    public function header(int $index, string $name): string
    {
        $headers = $this->calls[$index]['headers'] ?? [];
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) === strtolower($name)) {
                return implode(', ', array_map('strval', (array) $values));
            }
        }

        return '';
    }

    /**
     * Apakah panggilan ke-`$index` membawa header bernama `$name`.
     */
    public function hasHeader(int $index, string $name): bool
    {
        foreach (array_keys($this->calls[$index]['headers'] ?? []) as $key) {
            if (strtolower((string) $key) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jumlah panggilan dengan metode tertentu.
     */
    public function countMethod(string $method): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * Jumlah panggilan yang body-nya memuat `$needle`.
     */
    public function countBodyContains(string $needle): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => str_contains($call['body'], $needle)));
    }

    public function __invoke($request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('FakeAdminerHandler: tidak ada respons tersisa untuk permintaan berikutnya');
        }

        $this->calls[] = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'body' => (string) $request->getBody(),
            'cookie' => (string) $request->getHeaderLine('Cookie'),
            'headers' => $request->getHeaders(),
        ];

        $next = array_shift($this->queue);

        $sink = $options['sink'] ?? null;
        if ($next['body'] !== '' && $sink instanceof StreamInterface) {
            $sink->write($next['body']);
        }

        $headers = $next['headers'];
        if ($next['body'] !== '' && !isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) strlen($next['body']);
        }
        // Adminer (php -S) selalu mengirim HTML untuk halaman; default ini membuat
        // fixture realistis tanpa harus menyetel Content-Type di tiap respons.
        if ($next['body'] !== '' && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }

        return Create::promiseFor(new Response($next['status'], $headers));
    }
}
