<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Handler Guzzle tiruan: merekam **semua** permintaan (method, path, header,
 * body mentah, opsi yang benar-benar dikirim ke handler) dan mengantrikan
 * respons/exception. Dipakai untuk uji yang butuh lebih dari satu panggilan
 * (mis. nonce harus berbeda antar request) — `MockHandler` bawaan hanya
 * menyimpan permintaan terakhir.
 */
final class RecordingAgentHandler
{
    /** @var array<int,array{method:string,uri:string,path:string,headers:array<string,array<int,string>>,body:string,options:array<string,mixed>}> */
    public array $calls = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    /** @param array<string,string> $headers */
    public function reply(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    /** @return array<string,mixed> */
    public function call(int $index = -1): array
    {
        if ($this->calls === []) {
            throw new \LogicException('Tidak ada panggilan HTTP yang tercatat');
        }
        $i = $index < 0 ? count($this->calls) + $index : $index;

        return $this->calls[$i] ?? [];
    }

    public function header(int $index, string $name): string
    {
        $headers = $this->call($index)['headers'] ?? [];

        foreach ($headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return (string) ($values[0] ?? '');
            }
        }

        return '';
    }

    public function count(): int
    {
        return count($this->calls);
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new \RuntimeException('RecordingAgentHandler: tidak ada respons tersisa');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'path' => $request->getUri()->getPath(),
            'headers' => $request->getHeaders(),
            'body' => (string) $request->getBody(),
            'options' => $options,
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * Test AgentClient (kaki control plane → agent, misi F0).
 *
 * Kontrak lintas-misi yang diuji ketat di sini: string kanonik tanda tangan
 * `"{timestamp}\n{METHOD}\n{path}\n{sha256(body_hex)}"` dan
 * `X-Rames-Signature = hash_hmac('sha256', canonical, token)` — **dihitung ulang
 * mandiri di test** (bukan memakai helper kelas yang diuji), sehingga perubahan
 * pada salah satunya langsung merah. Sisi agent (`AgentAuthMiddleware`) memakai
 * bentuk yang sama.
 *
 * Uji berjalan tanpa jaringan (handler tiruan) dan tanpa menyentuh `runtime/`
 * nyata: direktori sertifikat selalu diarahkan ke `/tmp`.
 */
class AgentClientTest extends TestCase
{
    private const TOKEN = 'tok-agent-uji-9f2c4d7a';

    private static ?string $pem = null;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/agent_client_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function server(array $overrides = []): array
    {
        return array_merge([
            'id' => 'srv1',
            'base_url' => 'https://agent.test:8443',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::pem(),
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function client(
        RecordingAgentHandler|MockHandler $handler,
        array $overrides = [],
        int $timeout = 25,
    ): AgentClient {
        return new AgentClient($this->server($overrides), $handler, $timeout, new TlsPinMaterializer($this->tmp . '/tls'));
    }

    // ---------------------------------------------------------------- fail-fast

    public function testRejectsNonHttpsAndMalformedBaseUrl(): void
    {
        foreach (['http://agent.test:8080', 'agent.test:8443', '', 'ftp://agent.test', 'https://'] as $baseUrl) {
            $handler = new RecordingAgentHandler();

            try {
                $this->client($handler, ['base_url' => $baseUrl]);
                $this->fail("base_url \"{$baseUrl}\" harus ditolak fail-fast");
            } catch (AgentError $e) {
                $this->assertSame(AgentError::INVALID_CONFIG, $e->reason, "base_url: {$baseUrl}");
            }

            $this->assertSame(0, $handler->count(), 'Tidak boleh ada request yang dikirim saat konfigurasi cacat');
        }
    }

    public function testRejectsEmptyToken(): void
    {
        foreach (['', '   '] as $token) {
            try {
                $this->client(new RecordingAgentHandler(), ['token' => $token]);
                $this->fail('Token kosong harus ditolak fail-fast');
            } catch (AgentError $e) {
                $this->assertSame(AgentError::INVALID_CONFIG, $e->reason);
            }
        }
    }

    public function testAcceptsTokenRefAsTokenAlias(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');

        $client = new AgentClient(
            $this->server(['token' => null, 'token_ref' => self::TOKEN]),
            $handler,
            25,
            new TlsPinMaterializer($this->tmp . '/tls')
        );

        $this->assertSame(['ok' => true], $client->health());
        $this->assertSame('Bearer ' . self::TOKEN, $handler->header(0, 'Authorization'));
    }

    public function testRejectsMissingOrCorruptCertificate(): void
    {
        foreach ([null, '', 'bukan sertifikat'] as $pem) {
            $server = $this->server();
            if ($pem === null) {
                unset($server['tls_cert_pem']);
            } else {
                $server['tls_cert_pem'] = $pem;
            }

            try {
                new AgentClient($server, new RecordingAgentHandler(), 25, new TlsPinMaterializer($this->tmp . '/tls'));
                $this->fail('PEM hilang/sampah harus ditolak (tanpa fallback verify=false)');
            } catch (AgentError $e) {
                $this->assertSame(AgentError::INVALID_CONFIG, $e->reason);
            }
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/tls');
    }

    // ------------------------------------------------------------------ opsi HTTP

    public function testTimeoutIsCappedAtThirtySeconds(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');

        $client = $this->client($handler, [], 300);
        $client->health();

        $options = $handler->call(0)['options'];
        $this->assertSame(30, $client->timeout());
        $this->assertSame(30, $options['timeout'], 'timeout yang benar-benar dikirim harus ≤ 30 s');
        $this->assertSame(5, $options['connect_timeout']);

        $handler2 = new RecordingAgentHandler();
        $handler2->reply(200, '{"ok":true}');
        $client2 = $this->client($handler2, [], 7);
        $client2->health();

        $this->assertSame(7, $client2->timeout());
        $this->assertSame(7, $handler2->call(0)['options']['timeout']);
        $this->assertSame(5, $handler2->call(0)['options']['connect_timeout']);
    }

    public function testVerifyUsesMaterializedCertificatePath(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');

        $client = $this->client($handler);
        $client->health();

        $verify = $handler->call(0)['options']['verify'];
        $this->assertIsString($verify);
        $this->assertNotSame(false, $verify);
        $this->assertSame($client->verifyPath(), $verify);
        $this->assertSame($this->tmp . '/tls/srv1.crt', $verify);
        $this->assertFileExists($verify);
        $this->assertSame(trim(self::pem()), trim((string) file_get_contents($verify)));
    }

    public function testBaseUrlTrailingSlashIsRemoved(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');

        $client = $this->client($handler, ['base_url' => 'https://agent.test:8443/']);
        $client->health();

        $this->assertSame('https://agent.test:8443', $client->baseUrl());
        $this->assertSame('https://agent.test:8443/healthz', $handler->call(0)['uri']);
    }

    // ------------------------------------------------------- tanda tangan & header

    public function testSignsRequestWithCanonicalString(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');

        $this->client($handler)->health();

        $call = $handler->call(0);
        $this->assertSame('GET', $call['method']);
        $this->assertSame('/healthz', $call['path']);
        $this->assertSame('', $call['body']);
        $this->assertSame('Bearer ' . self::TOKEN, $handler->header(0, 'Authorization'));
        $this->assertSame('application/json', $handler->header(0, 'Accept'));
        $this->assertMatchesRegularExpression('/^\d{10}$/', $handler->header(0, 'X-Rames-Timestamp'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $handler->header(0, 'X-Rames-Nonce'));

        // Dihitung ulang mandiri: {timestamp}\n{METHOD}\n{path}\n{sha256(body)}
        $canonical = $handler->header(0, 'X-Rames-Timestamp') . "\n"
            . 'GET' . "\n"
            . '/healthz' . "\n"
            . hash('sha256', '');
        $this->assertSame(
            hash_hmac('sha256', $canonical, self::TOKEN),
            $handler->header(0, 'X-Rames-Signature')
        );
    }

    public function testPostJobSendsOpAndPayloadFlatAndReturnsJobId(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"job_id":"a1b2c3d4e5f60718"}');

        $jobId = $this->client($handler)->postJob('deploy', ['app' => 'app1', 'ref' => 'main']);

        $this->assertSame('a1b2c3d4e5f60718', $jobId);

        $call = $handler->call(0);
        $this->assertSame('POST', $call['method']);
        $this->assertSame('/jobs', $call['path']);
        $this->assertSame('application/json', $handler->header(0, 'Content-Type'));

        $body = (string) $call['body'];
        $this->assertSame(['app' => 'app1', 'ref' => 'main', 'op' => 'deploy'], json_decode($body, true));
        $this->assertStringNotContainsString('payload', $body, 'op digabung datar ({op, app, ref?}), bukan dibungkus');

        $canonical = $handler->header(0, 'X-Rames-Timestamp') . "\nPOST\n/jobs\n" . hash('sha256', $body);
        $this->assertSame(hash_hmac('sha256', $canonical, self::TOKEN), $handler->header(0, 'X-Rames-Signature'));
    }

    public function testPostJobRejectsResponseWithoutJobId(): void
    {
        foreach (['{"ok":true}', '{"job_id":""}', '{"job_id":"../../etc/passwd"}', '{"job_id":"a b"}'] as $body) {
            $handler = new RecordingAgentHandler();
            $handler->reply(200, $body);

            try {
                $this->client($handler)->postJob('deploy', ['app' => 'app1']);
                $this->fail("Respons \"{$body}\" harus ditolak (job_id tidak valid)");
            } catch (AgentError $e) {
                $this->assertSame(AgentError::BAD_RESPONSE, $e->reason);
            }
        }
    }

    public function testPostJobAcceptsJobIdInsideData(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"code":0,"data":{"job_id":"deadbeefcafe0001"}}');

        $this->assertSame('deadbeefcafe0001', $this->client($handler)->postJob('deploy', ['app' => 'app1']));
    }

    public function testNonceDiffersBetweenRequests(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');
        $handler->reply(200, '{"agent_version":"0.1.0"}');

        $client = $this->client($handler);
        $client->health();
        $client->version();

        $this->assertSame(2, $handler->count());
        $first = $handler->header(0, 'X-Rames-Nonce');
        $second = $handler->header(1, 'X-Rames-Nonce');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $second);
        $this->assertNotSame($first, $second, 'nonce wajib sekali pakai');
        $this->assertNotSame($handler->header(0, 'X-Rames-Signature'), $handler->header(1, 'X-Rames-Signature'));
    }

    public function testJobStatusAndLogUsePathWithoutQueryInSignature(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"id":"abc","status":"running"}');
        $handler->reply(200, "baris log\n");

        $client = $this->client($handler);

        $this->assertSame(['id' => 'abc', 'status' => 'running'], $client->jobStatus('abc'));
        $this->assertSame('/jobs/abc', $handler->call(0)['path']);

        $this->assertSame("baris log\n", $client->jobLog('abc', 7));

        $call = $handler->call(1);
        $this->assertSame('/jobs/abc/log', $call['path'], 'query tidak ikut ke path');
        $this->assertSame('offset=7', parse_url($call['uri'], PHP_URL_QUERY));
        $this->assertStringEndsWith('/jobs/abc/log?offset=7', $call['uri']);

        $canonical = $handler->header(1, 'X-Rames-Timestamp') . "\nGET\n/jobs/abc/log\n" . hash('sha256', '');
        $this->assertSame(hash_hmac('sha256', $canonical, self::TOKEN), $handler->header(1, 'X-Rames-Signature'));
    }

    public function testGetNormalizesLeadingSlash(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true}');

        $this->client($handler)->get('version');

        $this->assertSame('/version', $handler->call(0)['path']);
    }

    // -------------------------------------------------------------- pemetaan galat

    /** @param mixed $queue */
    private function mockClient(array $queue, int $timeout = 25): AgentClient
    {
        return $this->client(new MockHandler($queue), [], $timeout);
    }

    public static function httpErrorProvider(): array
    {
        return [
            'unauthorized 401' => [401, AgentError::UNAUTHORIZED],
            'forbidden 403' => [403, AgentError::UNAUTHORIZED],
            'not found 404' => [404, AgentError::NOT_FOUND],
            'unsupported 501' => [501, AgentError::UNSUPPORTED],
            'server error 500' => [500, AgentError::SERVER_ERROR],
            'bad gateway 502' => [502, AgentError::SERVER_ERROR],
            'redirect 302' => [302, AgentError::BAD_RESPONSE],
            'bad request 400' => [400, AgentError::BAD_RESPONSE],
        ];
    }

    /** @dataProvider httpErrorProvider */
    public function testMapsHttpStatusToReason(int $status, string $reason): void
    {
        $client = $this->mockClient([new Response($status, ['Content-Type' => 'application/json'], '{"error":"ditolak agent"}')]);

        try {
            $client->health();
            $this->fail("HTTP {$status} harus melempar AgentError");
        } catch (AgentError $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
            $this->assertStringContainsString('ditolak agent', $e->getMessage());
        }
    }

    public function testMapsInvalidJsonToBadResponse(): void
    {
        foreach (['bukan json', '<html>502</html>', 'null', ''] as $body) {
            $client = $this->mockClient([new Response(200, [], $body)]);

            try {
                $client->health();
                $this->fail('Respons bukan JSON harus ditolak (bad_response)');
            } catch (AgentError $e) {
                $this->assertSame(AgentError::BAD_RESPONSE, $e->reason);
            }
        }
    }

    public function testMapsConnectionFailureToUnreachable(): void
    {
        $client = $this->mockClient([
            new ConnectException('cURL error 7: Failed to connect to agent.test port 8443', new Request('GET', 'https://agent.test:8443/healthz')),
        ]);

        try {
            $client->health();
            $this->fail('Kegagalan koneksi harus melempar AgentError');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNREACHABLE, $e->reason);
        }
    }

    public function testMapsTlsVerificationFailureToTlsFailed(): void
    {
        $messages = [
            'cURL error 60: SSL certificate problem: self-signed certificate',
            'cURL error 35: error:0A000086:SSL routines::certificate verify failed',
            'Unable to verify the peer certificate',
        ];

        foreach ($messages as $message) {
            $client = $this->mockClient([new ConnectException($message, new Request('GET', 'https://agent.test:8443/healthz'))]);

            try {
                $client->health();
                $this->fail('Kegagalan TLS harus dibedakan dari unreachable');
            } catch (AgentError $e) {
                $this->assertSame(AgentError::TLS_FAILED, $e->reason);
            }
        }
    }

    // ----------------------------------------------------------- higienitas token

    public function testErrorMessagesNeverContainToken(): void
    {
        $token = 'tok-unik-untuk-uji-7b1f4c9d';

        // (a) body galat agent memantulkan token
        $handler = new RecordingAgentHandler();
        $handler->reply(401, '{"error":"token ' . $token . ' tidak dikenal"}');

        try {
            $this->client($handler, ['token' => $token])->health();
            $this->fail('401 harus melempar AgentError');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNAUTHORIZED, $e->reason);
            $this->assertStringNotContainsString($token, $e->getMessage());
            $this->assertStringContainsString('[redacted]', $e->getMessage());
        }

        // (b) pesan transport yang kebetulan memuat token
        $client = new AgentClient(
            $this->server(['token' => $token]),
            new MockHandler([
                new ConnectException(
                    'cURL error 7: Authorization Bearer ' . $token . ' gagal menghubungi agent',
                    new Request('GET', 'https://agent.test:8443/healthz')
                ),
            ]),
            25,
            new TlsPinMaterializer($this->tmp . '/tls')
        );

        try {
            $client->health();
            $this->fail('Kegagalan koneksi harus melempar AgentError');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNREACHABLE, $e->reason);
            $this->assertStringNotContainsString($token, $e->getMessage());
            $this->assertStringNotContainsString('Bearer ' . $token, $e->getMessage());
        }
    }

    public function testSanitizeAlsoHandlesSignatureHeaderAndLongBodies(): void
    {
        $message = 'gagal X-Rames-Signature: ' . str_repeat('a', 64) . ' dan seterusnya '
            . str_repeat('panjang ', 200);

        $sanitized = AgentError::sanitize($message, 'tok-rahasia');

        $this->assertStringNotContainsString(str_repeat('a', 64), $sanitized);
        $this->assertLessThanOrEqual(AgentError::MAX_MESSAGE_LENGTH + 3, strlen($sanitized));
    }

    // -------------------------------------------------------------- jalur sukses

    public function testHealthAndVersionReturnAgentPayload(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"ok":true,"service":"rames-agent","version":"0.1.0"}');
        $handler->reply(200, '{"agent_version":"0.1.0","capabilities":[],"min_control_plane_version":"0.1.0"}');

        $client = $this->client($handler);

        $this->assertSame('rames-agent', $client->health()['service']);
        $this->assertSame([], $client->version()['capabilities']);
    }

    public function testPostReturnsDecodedJson(): void
    {
        $handler = new RecordingAgentHandler();
        $handler->reply(200, '{"accepted":true}');

        $this->assertSame(['accepted' => true], $this->client($handler)->post('/jobs/abc/cancel', ['reason' => 'ui']));
    }

    private static function pem(): string
    {
        return self::$pem ??= self::selfSignedPem();
    }

    /** Sertifikat self-signed sekali pakai untuk uji (tanpa menyentuh jaringan). */
    private static function selfSignedPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return (string) $pem;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
