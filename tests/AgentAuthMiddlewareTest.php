<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\AgentAuthMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

// Helper webman (`config()`, `json()`) memakai konstanta BASE_PATH — definisikan
// seperti test lain agar berkas ini bisa dijalankan di luar runtime webman.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Penegakan autentikasi mesin-ke-mesin di server agent (plan §4.1) — menutup
 * blocker misi F0 sebelumnya: tanpa middleware ini, seluruh route agent
 * (termasuk `/healthz`) terbuka bagi siapa pun yang bisa menjangkau port agent.
 *
 * Kontrak yang diuji:
 *  1. mode `dashboard` ⇒ **no-op mutlak** (dashboard punya skema auth sendiri:
 *     sesi + CSRF; middleware ini tidak boleh menyentuhnya);
 *  2. agent: bearer token (`hash_equals`), timestamp ±60 s, nonce ≥16 char
 *     sekali pakai (cache lintas worker), signature opsional tapi wajib valid;
 *  3. token kosong / tidak dikonfigurasi ⇒ selalu tolak (fail-closed);
 *  4. 401 tanpa detail — body identik untuk semua penyebab dan tidak memuat
 *     token/nonce/path/isi body request;
 *  5. jam di-inject (`fn (): int`) sehingga uji tidak memakai `sleep()`, dan
 *     cache nonce memakai berkas di direktori temp (tidak menyentuh `runtime/`
 *     maupun data runtime nyata).
 */
class AgentAuthMiddlewareTest extends TestCase
{
    private const TOKEN = 'agt_token_rahasia_0123456789';
    private const NONCE = 'nonce-abcdefghijklmnop';
    private const NOW = 2000;

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_agentauth_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        $this->configState = $this->snapshotConfigState();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        foreach (glob($this->tmp . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp . '/config');
        @unlink($this->noncePath());
        @unlink($this->noncePath() . '.bak');
        @rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // (i) token bearer
    // ------------------------------------------------------------------

    public function testRequestWithoutAuthorizationHeaderIsRejected(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders(withToken: false)));

        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertFalse($result['handled'], 'handler tidak boleh dipanggil');
    }

    public function testWrongTokenIsRejected(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders(token: 'token-yang-salah')));

        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertFalse($result['handled']);
    }

    public function testTokenWithoutBearerSchemeIsRejected(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders(token: null, rawAuth: self::TOKEN)));

        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertFalse($result['handled']);
    }

    public function testValidTokenPassesThroughToHandler(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders()));

        $this->assertTrue($result['handled'], 'request sah harus diteruskan ke handler');
        $this->assertSame(200, $result['response']->getStatusCode());
        $this->assertSame('ok', $result['response']->rawBody());
    }

    public function testEmptyTokenInConfigRejectsEveryRequest(): void
    {
        foreach (['', '   ', "\n\t"] as $token) {
            $this->configureDeploy('agent', $token);
            $result = $this->handle(
                new AgentAuthMiddleware($this->noncePath(), static fn (): int => self::NOW),
                $this->request($this->agentHeaders())
            );

            $this->assertSame(401, $result['response']->getStatusCode(), 'token kosong harus fail-closed');
            $this->assertFalse($result['handled']);
        }
    }

    // ------------------------------------------------------------------
    // (ii) timestamp
    // ------------------------------------------------------------------

    public function testStaleTimestampIsRejected(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders(timestamp: self::NOW - 61, nonce: 'nonce-stale-aaaaaaaa')));

        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertFalse($result['handled']);
    }

    public function testFutureTimestampIsRejected(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders(timestamp: self::NOW + 61, nonce: 'nonce-future-aaaaaaa')));

        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertFalse($result['handled']);
    }

    public function testTimestampAtWindowEdgeIsAccepted(): void
    {
        $past = $this->runAgent($this->request($this->agentHeaders(
            timestamp: self::NOW - AgentAuthMiddleware::TIMESTAMP_WINDOW,
            nonce: 'nonce-edge-past-aaaaa'
        )));
        $future = $this->runAgent($this->request($this->agentHeaders(
            timestamp: self::NOW + AgentAuthMiddleware::TIMESTAMP_WINDOW,
            nonce: 'nonce-edge-future-aaa'
        )));

        $this->assertTrue($past['handled'], 'tepat -60 s masih dalam toleransi');
        $this->assertTrue($future['handled'], 'tepat +60 s masih dalam toleransi');
    }

    public function testMissingOrNonNumericTimestampIsRejected(): void
    {
        $missing = $this->runAgent($this->request([
            'Authorization' => 'Bearer ' . self::TOKEN,
            'X-Rames-Nonce' => self::NONCE,
        ]));
        $garbage = $this->runAgent($this->request($this->agentHeaders(timestamp: 'kemarin')));

        $this->assertSame(401, $missing['response']->getStatusCode());
        $this->assertSame(401, $garbage['response']->getStatusCode());
    }

    // ------------------------------------------------------------------
    // (iii)/(iv) nonce anti-replay
    // ------------------------------------------------------------------

    public function testReplayedNonceIsRejected(): void
    {
        $first = $this->runAgent($this->request($this->agentHeaders()));
        $second = $this->runAgent($this->request($this->agentHeaders()));

        $this->assertTrue($first['handled'], 'pemakaian pertama lolos');
        $this->assertSame(401, $second['response']->getStatusCode(), 'nonce sama kedua kali = replay');
        $this->assertFalse($second['handled']);
    }

    public function testNonceIsAcceptedAgainAfterTtlExpires(): void
    {
        $this->configureDeploy('agent', self::TOKEN);
        $now = self::NOW;
        $middleware = new AgentAuthMiddleware(
            $this->noncePath(),
            static function () use (&$now): int {
                return $now;
            }
        );

        $this->assertTrue($this->handle($middleware, $this->request($this->agentHeaders()))['handled']);

        // Lewat TTL cache (120 s) → entri dibuang, nonce boleh dipakai lagi.
        $now = self::NOW + 121;
        $later = $this->handle($middleware, $this->request($this->agentHeaders(timestamp: $now)));

        $this->assertTrue($later['handled'], 'setelah lewat TTL nonce tidak lagi dianggap replay');
    }

    public function testShortOrMissingNonceIsRejected(): void
    {
        $short = $this->runAgent($this->request($this->agentHeaders(
            nonce: str_repeat('a', AgentAuthMiddleware::NONCE_MIN_LENGTH - 1)
        )));
        $missing = $this->runAgent($this->request($this->agentHeaders(nonce: null)));

        $this->assertSame(401, $short['response']->getStatusCode());
        $this->assertSame(401, $missing['response']->getStatusCode());
    }

    public function testNonceCacheIsSharedBetweenMiddlewareInstances(): void
    {
        // Dua instans berbeda = dua worker webman berbeda; cache harus tetap satu
        // sumber (berkas), bukan memori proses.
        $first = $this->runAgent($this->request($this->agentHeaders(timestamp: self::NOW, nonce: 'nonce-shared-aaaaaaa')));
        $this->configureDeploy('agent', self::TOKEN);
        $second = $this->handle(
            new AgentAuthMiddleware($this->noncePath(), static fn (): int => self::NOW),
            $this->request($this->agentHeaders(timestamp: self::NOW, nonce: 'nonce-shared-aaaaaaa'))
        );

        $this->assertTrue($first['handled']);
        $this->assertSame(401, $second['response']->getStatusCode(), 'replay lintas worker harus tertahan');
    }

    // ------------------------------------------------------------------
    // (v) signature opsional, tapi wajib valid bila dikirim
    // ------------------------------------------------------------------

    public function testSignatureAbsentIsAccepted(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders()));

        $this->assertTrue($result['handled'], 'tanpa header signature, baseline token+timestamp+nonce berlaku');
    }

    public function testValidSignatureIsAccepted(): void
    {
        $body = '{"op":"deploy"}';
        $request = $this->signedRequest('POST', '/jobs', $body);
        $result = $this->runAgent($request);

        $this->assertTrue($result['handled'], 'signature benar harus diterima');
    }

    public function testWrongSignatureIsRejected(): void
    {
        $result = $this->runAgent($this->request(
            $this->agentHeaders(signature: str_repeat('a', 64)),
            'POST',
            '/jobs',
            '{"op":"deploy"}'
        ));

        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertFalse($result['handled']);
    }

    public function testEmptySignatureHeaderIsRejected(): void
    {
        $result = $this->runAgent($this->request($this->agentHeaders(signature: '')));

        $this->assertSame(401, $result['response']->getStatusCode(), 'header ada tapi kosong = tidak valid');
    }

    public function testSignatureOverTamperedBodyIsRejected(): void
    {
        $signed = $this->request($this->agentHeaders(), 'POST', '/jobs', '{"op":"deploy"}');
        $signature = $this->sign($signed, self::NOW);
        $tampered = $this->request($this->agentHeaders(signature: $signature), 'POST', '/jobs', '{"op":"teardown"}');
        $result = $this->runAgent($tampered);

        $this->assertSame(401, $result['response']->getStatusCode(), 'body yang diubah membatalkan signature');
        $this->assertFalse($result['handled']);
    }

    public function testFailedSignatureDoesNotConsumeTheNonce(): void
    {
        $bad = $this->runAgent($this->request($this->agentHeaders(
            nonce: 'nonce-not-burned-aa',
            signature: str_repeat('b', 64)
        )));
        $this->assertFalse($bad['handled']);

        $retry = $this->runAgent($this->request($this->agentHeaders(nonce: 'nonce-not-burned-aa')));

        $this->assertTrue(
            $retry['handled'],
            'request yang gagal sebelum pemeriksaan nonce tidak boleh membakar nonce-nya'
        );
    }

    // ------------------------------------------------------------------
    // (vi) mode dashboard ⇒ no-op mutlak
    // ------------------------------------------------------------------

    public function testDashboardModeIsAbsoluteNoOp(): void
    {
        $this->configureDeploy('dashboard', self::TOKEN);
        $middleware = new AgentAuthMiddleware($this->noncePath(), static fn (): int => self::NOW);

        // Request dashboard biasa: tanpa bearer/timestamp/nonce sama sekali.
        $result = $this->handle($middleware, new Request("GET /apps HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        $this->assertTrue($result['handled'], 'mode dashboard: middleware tidak boleh menghalangi apa pun');
        $this->assertSame(200, $result['response']->getStatusCode());
        $this->assertFileDoesNotExist($this->noncePath(), 'mode dashboard tidak boleh menulis cache nonce');
    }

    public function testUnknownModeIsTreatedAsDashboard(): void
    {
        $this->configureDeploy('agnt', self::TOKEN);
        $middleware = new AgentAuthMiddleware($this->noncePath(), static fn (): int => self::NOW);

        $result = $this->handle($middleware, new Request("GET /apps HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        $this->assertTrue($result['handled'], 'mode tak dikenal = dashboard (peran paling lengkap)');
    }

    // ------------------------------------------------------------------
    // (vii) 401 tidak membocorkan apa pun
    // ------------------------------------------------------------------

    public function testDenialBodyIsGenericAndCarriesNoRequestOrSecretData(): void
    {
        $secretBody = '{"rahasia":"jangan-bocor"}';
        $result = $this->runAgent($this->request(
            $this->agentHeaders(token: 'token-yang-salah'),
            'POST',
            '/app-rahasia-kustomer',
            $secretBody
        ));

        $response = $result['response'];
        $payload = (string) $response->rawBody();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame(['code' => 401, 'msg' => 'Unauthorized'], json_decode($payload, true));
        $this->assertStringNotContainsString(self::TOKEN, $payload, 'token tidak boleh muncul di body');
        $this->assertStringNotContainsString(self::NONCE, $payload, 'nonce tidak boleh muncul di body');
        $this->assertStringNotContainsString('token-yang-salah', $payload);
        $this->assertStringNotContainsString('/app-rahasia-kustomer', $payload, 'path tidak boleh muncul di body');
        $this->assertStringNotContainsString('jangan-bocor', $payload, 'body request tidak boleh dipantulkan');
        $this->assertStringNotContainsString('signature', strtolower($payload), 'tanpa alasan spesifik');
    }

    public function testAllFailureModesReturnIdenticalResponse(): void
    {
        $bodies = [];
        foreach ($this->failureRequests() as $label => $request) {
            $result = $this->runAgent($request);

            $this->assertSame(401, $result['response']->getStatusCode(), $label);
            $this->assertFalse($result['handled'], $label);
            $bodies[$label] = (string) $result['response']->rawBody();
        }

        $this->assertCount(1, array_unique($bodies), 'semua kegagalan memakai body 401 yang identik');
        $this->assertSame('{"code":401,"msg":"Unauthorized"}', reset($bodies));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @return array<string,Request> */
    private function failureRequests(): array
    {
        return [
            'no-auth' => $this->request($this->agentHeaders(withToken: false)),
            'bad-token' => $this->request($this->agentHeaders(token: 'salah')),
            'stale-timestamp' => $this->request($this->agentHeaders(timestamp: self::NOW - 500)),
            'short-nonce' => $this->request($this->agentHeaders(nonce: 'pendek')),
            'bad-signature' => $this->request($this->agentHeaders(signature: str_repeat('b', 64))),
            'no-timestamp' => $this->request(['Authorization' => 'Bearer ' . self::TOKEN, 'X-Rames-Nonce' => self::NONCE]),
        ];
    }

    /**
     * Jalankan middleware dalam mode agent dengan token uji.
     *
     * @return array{response:Response,handled:bool}
     */
    private function runAgent(Request $request): array
    {
        $this->configureDeploy('agent', self::TOKEN);

        return $this->handle(new AgentAuthMiddleware($this->noncePath(), static fn (): int => self::NOW), $request);
    }

    /**
     * @return array{response:Response,handled:bool}
     */
    private function handle(AgentAuthMiddleware $middleware, Request $request): array
    {
        $handled = false;
        $response = $middleware->process(
            $request,
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'ok');
            }
        );

        return ['response' => $response, 'handled' => $handled];
    }

    /**
     * Header protokol agent (nama seperti dikirim klien; pemetaan ke huruf kecil
     * dilakukan parser webman, jadi uji ini juga menguji jalur header nyata).
     *
     * @param  int|string|null $timestamp
     * @param  string|null     $signature null = header signature tidak dikirim
     * @param  bool            $withToken false = tanpa header Authorization
     * @param  string|null     $rawAuth   nilai header Authorization mentah (mis. tanpa skema Bearer)
     * @return array<string,string>
     */
    private function agentHeaders(
        int|string|null $timestamp = self::NOW,
        ?string $nonce = self::NONCE,
        ?string $token = self::TOKEN,
        ?string $signature = null,
        bool $withToken = true,
        ?string $rawAuth = null,
    ): array {
        $headers = [];
        if ($rawAuth !== null) {
            $headers['Authorization'] = $rawAuth;
        } elseif ($withToken) {
            $headers['Authorization'] = 'Bearer ' . (string) $token;
        }
        if ($timestamp !== null) {
            $headers['X-Rames-Timestamp'] = (string) $timestamp;
        }
        if ($nonce !== null) {
            $headers['X-Rames-Nonce'] = $nonce;
        }
        if ($signature !== null) {
            $headers['X-Rames-Signature'] = $signature;
        }

        return $headers;
    }

    /**
     * Request mentah dengan header/di path tertentu.
     *
     * @param array<string,string> $headers
     */
    private function request(array $headers, string $method = 'GET', string $path = '/healthz', string $body = ''): Request
    {
        $lines = ["{$method} {$path} HTTP/1.1", 'Host: agent.example.test'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $lines[] = 'Content-Length: ' . strlen($body);

        return new Request(implode("\r\n", $lines) . "\r\n\r\n" . $body);
    }

    /** Request ber-signature valid (kanonik sesuai kontrak). */
    private function signedRequest(string $method, string $path, string $body): Request
    {
        $unsigned = $this->request($this->agentHeaders(), $method, $path, $body);

        return $this->request($this->agentHeaders(signature: $this->sign($unsigned, self::NOW)), $method, $path, $body);
    }

    /** `"{ts}\n{METHOD}\n{path}\n{sha256(body)}"` — kanonik kontrak §4.1. */
    private function sign(Request $request, int $timestamp): string
    {
        $canonical = implode("\n", [
            (string) $timestamp,
            strtoupper($request->method()),
            $request->path(),
            hash('sha256', $request->rawBody()),
        ]);

        return hash_hmac('sha256', $canonical, self::TOKEN);
    }

    private function noncePath(): string
    {
        return $this->tmp . '/agent-nonces.json';
    }

    /** Muat `deploy.mode`/`deploy.agent_token` ke registry Config webman. */
    private function configureDeploy(string $mode, string $token): void
    {
        $dir = $this->tmp . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents(
            $dir . '/deploy.php',
            '<?php return ' . var_export(['mode' => $mode, 'agent_token' => $token], true) . ';' . PHP_EOL
        );
        Config::clear();
        Config::load($dir);
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }
}
