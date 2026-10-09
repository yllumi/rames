<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

/**
 * Handler Guzzle tiruan untuk menguji `DuitkuClient` tanpa jaringan.
 *
 * Seluruh permintaan dicatat (metode, URI, body JSON) dan respons/exception
 * diantrikan sehingga jalur sukses, error HTTP, dan kegagalan jaringan bisa
 * diuji deterministik.
 */
final class FakeDuitkuHandler
{
    /** @var array<int,array{method:string,uri:string,body:array<string,mixed>,headers:array<string,array<int,string>>}> */
    public array $calls = [];

    /** @var array<int,array{status:int,body:string,headers:array<string,string>,throw:?\Throwable}> */
    private array $queue = [];

    /**
     * Antrikan respons HTTP.
     *
     * @param array<string,mixed>|string $body
     * @param array<string,string>       $headers
     */
    public function reply(int $status, array|string $body = '', array $headers = []): self
    {
        $encoded = is_array($body) ? (string) json_encode($body) : $body;
        $this->queue[] = [
            'status' => $status,
            'body' => $encoded,
            'headers' => $headers + ['Content-Type' => 'application/json'],
            'throw' => null,
        ];

        return $this;
    }

    /**
     * Antrikan kegagalan jaringan (mis. timeout / koneksi ditolak).
     */
    public function fail(\Throwable $error): self
    {
        $this->queue[] = ['status' => 0, 'body' => '', 'headers' => [], 'throw' => $error];

        return $this;
    }

    /**
     * @return array<int,array{method:string,uri:string,body:array<string,mixed>,headers:array<string,array<int,string>>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Body JSON panggilan ke-`$index` (default terakhir).
     *
     * @return array<string,mixed>
     */
    public function body(int $index = -1): array
    {
        if ($this->calls === []) {
            return [];
        }
        $i = $index < 0 ? count($this->calls) + $index : $index;

        return $this->calls[$i]['body'] ?? [];
    }

    /**
     * URI panggilan ke-`$index` (default terakhir).
     */
    public function uri(int $index = -1): string
    {
        if ($this->calls === []) {
            return '';
        }
        $i = $index < 0 ? count($this->calls) + $index : $index;

        return $this->calls[$i]['uri'] ?? '';
    }

    public function count(): int
    {
        return count($this->calls);
    }

    public function __invoke($request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('FakeDuitkuHandler: tidak ada respons tersisa untuk permintaan berikutnya');
        }

        $rawBody = (string) $request->getBody();
        $decoded = json_decode($rawBody, true);

        $this->calls[] = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'body' => is_array($decoded) ? $decoded : [],
            'headers' => $request->getHeaders(),
        ];

        $next = array_shift($this->queue);
        if ($next['throw'] !== null) {
            throw $next['throw'];
        }

        return Create::promiseFor(new Response($next['status'], $next['headers'], $next['body']));
    }
}
