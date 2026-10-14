<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentJobController;
use app\library\Agent\AgentAppProjection;
use app\library\Agent\AgentArtifactMaterializer;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\AgentJobStore;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployWorkerLauncher;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Webman\Config;

/**
 * Handler Guzzle tiruan untuk uji agent palsu Nginx/SSL (tanpa jaringan):
 * merekam method/path/body setiap panggilan lalu melayani respons dari antrean.
 *
 * Dideklarasikan di berkas ini (bukan dipakai ulang dari berkas uji lain)
 * supaya kelas ini tetap dapat di-autoload saat dijalankan dengan `--filter`.
 */
final class AgentDeployerNginxFakeHandler
{
    /** @var array<int,array{method:string,path:string,body:string}> */
    public array $calls = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    public function reply(int $status, string $body = ''): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return array{method:string,path:string,body:string} */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total {$this->count()})");
        }

        return $this->calls[$index];
    }

    /** @return array<string,mixed> */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->call($index)['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AgentDeployerNginxFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * Nginx & SSL **di server agent** (multi-server F2, plan §4.1/§5).
 *
 * Kontrak yang dikunci:
 *  1. `writeNginxConfig()`/`removeNginxConfig()`/`reloadNginx()` ⇒ job op agent
 *     (`nginx_write`/`nginx_remove`/`nginx_reload`) dengan dokumen app, dan
 *     **tidak ada** berkas Nginx lokal yang ditulis (direktori config temp tetap
 *     kosong — regresi "jatuh ke library lokal" langsung terlihat);
 *  2. `renderNginxConfig()` membaca hasil perakitan dari `GET /apps/{id}/nginx`
 *     (dirakit di server agent), bukan dirender di sini;
 *  3. `ensureWritable()` memakai preflight `POST /nginx/check` server agent;
 *  4. `issueSsl()`/`revokeSsl()` ⇒ job `ssl_issue`/`ssl_revoke` dengan `domain`
 *     di payload; certbot & `/etc/letsencrypt` **server agent** (direktori LE
 *     temp tetap kosong), bentuk domain divalidasi **sebelum** HTTP apa pun;
 *  5. job Nginx/SSL yang gagal di agent ⇒ exception **tersanitasi** (tanpa token);
 *  6. server agent tanpa `domain` (APP_DOMAIN server itu) ⇒ ditolak dengan pesan
 *     jelas **sebelum** panggilan HTTP (kontrak F2 §5);
 *  7. op baru muncul di capabilities lewat **satu** daftar
 *     (`DeployWorkerLauncher::MODES` = `AgentJobController::OPS` = mode
 *     `cli/deploy.php`);
 *  8. kredensial DNS (mis. `cloudflare_creds`) **tidak pernah** masuk rekaman
 *     job, respons, atau log — payload job dibatasi whitelist.
 *
 * Seluruh uji tanpa jaringan (handler tiruan), tanpa certbot/nginx nyata, dan
 * tanpa data runtime (`apps/`, `database/`, `nginx-status/`): path config
 * diarahkan ke direktori temp.
 */
class AgentDeployerNginxTest extends TestCase
{
    private const APP_ID = 'app-nginx-1';
    private const TOKEN = 'tok-uji-nginx-88ac41';
    private const SERVER_DOMAIN = 'agent.example.test';

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var list<array{0:string,1:string}> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agentnginx-' . bin2hex(random_bytes(4));
        foreach (['config', 'nginx/conf', 'letsencrypt', 'webroot', 'tls'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0777, true);
        }

        $this->lines = [];
        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1)/(2) tulis, hapus, reload — job agent & tanpa efek lokal
    // ==================================================================

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function nginxJobCases(): array
    {
        return [
            'tulis' => ['write', 'nginx_write'],
            'hapus' => ['remove', 'nginx_remove'],
            'reload' => ['reload', 'nginx_reload'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nginxJobCases')]
    public function testNginxOperationsRunAtTheAgentAndWriteNothingLocally(string $method, string $expectedOp): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $this->queueSuccessfulJob($handler);
        $deployer = $this->deployer($handler);

        match ($method) {
            'write' => $deployer->writeNginxConfig($this->app()),
            'remove' => $deployer->removeNginxConfig($this->app()),
            'reload' => $deployer->reloadNginx($this->app(), $this->logger()),
            default => throw new \LogicException("method tak dikenal: {$method}"),
        };

        $first = $handler->call(0);
        $this->assertSame('POST', $first['method']);
        $this->assertSame('/jobs', $first['path']);

        $body = $handler->jsonBody(0);
        $this->assertSame($expectedOp, $body['op'] ?? null);
        $this->assertSame(self::APP_ID, $body['app']['id'] ?? null, 'dokumen app ikut (proyeksi di agent)');
        $this->assertArrayNotHasKey('artifacts', $body, 'op Nginx tidak memaketkan artefak app');

        // Bukti "tidak menyentuh host ini": direktori config Nginx lokal kosong.
        $this->assertSame([], glob($this->nginxDir() . '/*') ?: [], 'config Nginx lokal tidak boleh ditulis');
    }

    public function testRejectedNginxJobIsSanitizedAndLeavesNoLocalFiles(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        // Pesan agent sengaja memuat token (higienitas: harus disanitasi).
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-fail', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => [
                'status' => 'error',
                'error' => 'nginx -t gagal: emergent ' . self::TOKEN . ' (uji)',
            ]]))
            ->reply(200, '')
            ->reply(200, '');

        try {
            $this->deployer($handler)->writeNginxConfig($this->app());
            $this->fail('job nginx error harus melempar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nginx_write', $e->getMessage());
            $this->assertStringContainsString('nginx -t gagal', $e->getMessage(), 'pesan agent diteruskan');
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'token server tidak boleh bocor');
        }

        $this->assertSame([], glob($this->nginxDir() . '/*') ?: []);
    }

    public function testRenderNginxConfigAsksTheAgentInsteadOfRenderingLocally(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $handler->reply(200, json_encode([
            'code' => 0,
            'op' => 'nginx_render',
            'config' => "server {\n  server_name demo.agent.example.test;\n}\n",
        ]));

        $config = $this->deployer($handler)->renderNginxConfig($this->app());

        $call = $handler->call(0);
        $this->assertSame('GET', $call['method']);
        $this->assertSame('/apps/' . self::APP_ID . '/nginx', $call['path']);
        $this->assertStringContainsString('server_name demo.agent.example.test;', $config);
        $this->assertSame([], glob($this->nginxDir() . '/*') ?: []);
    }

    public function testRenderNginxConfigRejectsAgentResponseWithoutConfig(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $handler->reply(200, json_encode(['code' => 0, 'op' => 'nginx_render']));

        try {
            $this->deployer($handler)->renderNginxConfig($this->app());
            $this->fail('respons tanpa `config` harus ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::BAD_RESPONSE, $e->reason);
            $this->assertStringContainsString('config Nginx', $e->getMessage());
        }
    }

    /**
     * `ensureWritable()` memakai preflight server agent: direktori Nginx host ini
     * tidak pernah diperiksa (di sini malah tidak ada berkas apa pun yang ditulis
     * walau preflight sukses).
     */
    public function testEnsureWritableChecksTheAgentNotThisHost(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $handler->reply(200, json_encode(['code' => 0, 'op' => 'nginx_check', 'ok' => true]));

        $this->deployer($handler)->ensureWritable();

        $this->assertSame('POST', $handler->call(0)['method']);
        $this->assertSame('/nginx/check', $handler->call(0)['path']);

        // Preflight yang gagal di agent ⇒ exception tersanitasi.
        $failing = new AgentDeployerNginxFakeHandler();
        $failing->reply(500, json_encode([
            'code' => 1,
            'error' => 'Dashboard tidak punya izin tulis ke /etc/nginx/sites-available.',
            'reason' => 'nginx_unwritable',
        ]));

        try {
            $this->deployer($failing)->ensureWritable();
            $this->fail('preflight gagal harus melempar');
        } catch (AgentError $e) {
            $this->assertStringContainsString('prasyarat direktori Nginx', $e->getMessage());
            $this->assertStringContainsString('izin tulis', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    // ==================================================================
    // (4) SSL — job di server agent, tanpa certbot lokal
    // ==================================================================

    public function testIssueSslSendsTheDomainAndRunsCertbotOnTheAgentSide(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $this->queueSuccessfulJob($handler, 'job-ssl');

        $this->deployer($handler)->issueSsl($this->app(), 'Demo.Agent.Example.Test.', $this->logger());

        $body = $handler->jsonBody(0);
        $this->assertSame('ssl_issue', $body['op'] ?? null);
        $this->assertSame('demo.agent.example.test', $body['domain'] ?? null, 'domain dinormalisasi & ikut payload');
        $this->assertSame(self::APP_ID, $body['app']['id'] ?? null);

        // Certbot/`/etc/letsencrypt` & direktori Nginx host ini tidak disentuh.
        $this->assertSame([], glob($this->letsencryptDir() . '/*') ?: []);
        $this->assertSame([], glob($this->nginxDir() . '/*') ?: []);
        $this->assertStringContainsString('Job `ssl_issue` dikirim', implode(' ', array_column($this->lines, 1)));
    }

    public function testRevokeSslSendsTheDomainToTheAgent(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $this->queueSuccessfulJob($handler, 'job-revoke');

        $this->deployer($handler)->revokeSsl($this->app(), 'demo.agent.example.test');

        $body = $handler->jsonBody(0);
        $this->assertSame('ssl_revoke', $body['op'] ?? null);
        $this->assertSame('demo.agent.example.test', $body['domain'] ?? null);
        $this->assertSame([], glob($this->letsencryptDir() . '/*') ?: []);
    }

    /**
     * Bentuk domain divalidasi **sebelum** HTTP apa pun — domain aneh tidak
     * pernah sampai ke certbot server lain.
     *
     * @return array<string,array{0:string}>
     */
    public static function invalidDomainCases(): array
    {
        return [
            'kosong' => [''],
            'path traversal' => ['../../etc/passwd'],
            'berskema' => ['https://demo.example.test'],
            'ber-port' => ['demo.example.test:8443'],
            'berspasi' => ['demo example.test'],
            'tanpa titik' => ['localhost'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDomainCases')]
    public function testInvalidSslDomainIsRejectedBeforeAnyHttpCall(string $domain): void
    {
        $handler = new AgentDeployerNginxFakeHandler();

        try {
            $this->deployer($handler)->issueSsl($this->app(), $domain, $this->logger());
            $this->fail("domain `{$domain}` harus ditolak");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Domain SSL', $e->getMessage());
        }

        $this->assertSame(0, $handler->count(), 'tidak boleh ada panggilan HTTP');
    }

    // ==================================================================
    // (5)/(6) kontrak F2 §5 — server agent tanpa domain sendiri
    // ==================================================================

    /**
     * @return array<string,array{0:\Closure(AgentDeployer):mixed}>
     */
    public static function domainBaseRequiredCases(): array
    {
        $app = ['id' => self::APP_ID, 'name' => 'demo'];

        return [
            'ensureWritable' => [static fn (AgentDeployer $d) => $d->ensureWritable()],
            'renderNginxConfig' => [static fn (AgentDeployer $d) => $d->renderNginxConfig($app)],
            'writeNginxConfig' => [static fn (AgentDeployer $d) => $d->writeNginxConfig($app)],
            'reloadNginx' => [static fn (AgentDeployer $d) => $d->reloadNginx($app)],
            'issueSsl' => [static fn (AgentDeployer $d) => $d->issueSsl($app, 'demo.example.test')],
            'revokeSsl' => [static fn (AgentDeployer $d) => $d->revokeSsl($app, 'demo.example.test')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('domainBaseRequiredCases')]
    public function testAgentServerWithoutDomainIsRejectedWithClearMessageBeforeAnyCall(\Closure $call): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $deployer = $this->deployer($handler, ['domain' => '']);

        try {
            $call($deployer);
            $this->fail('server agent tanpa APP_DOMAIN harus ditolak (kontrak F2 §5)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DOMAIN', $e->getMessage());
            $this->assertStringContainsString('halaman Servers', $e->getMessage(), 'pesan harus actionable');
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage(), 'sebut server yang bermasalah');
        }

        $this->assertSame(0, $handler->count(), 'tidak boleh ada panggilan HTTP dengan domain yang salah');
    }

    /**
     * Penghapusan vhost **tetap boleh** tanpa `domain` server: kalau tidak,
     * vhost basi di server agent akan tertinggal selamanya.
     */
    public function testRemoveNginxConfigStillWorksWithoutServerDomain(): void
    {
        $handler = new AgentDeployerNginxFakeHandler();
        $this->queueSuccessfulJob($handler);

        $this->deployer($handler, ['domain' => ''])->removeNginxConfig($this->app());

        $this->assertSame('nginx_remove', $handler->jsonBody(0)['op'] ?? null);
    }

    /**
     * Deploy & turunannya **juga** menulis vhost di agent (lewat `LocalDeployer`
     * di sana) ⇒ aturan F2 §5 berlaku: app ber-subdomain bawaan di server agent
     * tanpa `domain` ditolak sebelum job dikirim.
     */
    public function testDeployFamilyIsRejectedOnDomainLessAgentBeforeAnyCall(): void
    {
        foreach (['deploy', 'rebuild', 'apply'] as $method) {
            $handler = new AgentDeployerNginxFakeHandler();
            $deployer = $this->deployer($handler, ['domain' => '']);

            try {
                $deployer->{$method}($this->app(), $this->logger());
                $this->fail("{$method}() harus ditolak: server agent tanpa APP_DOMAIN");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('APP_DOMAIN', $e->getMessage(), $method);
            }

            $this->assertSame(0, $handler->count(), "{$method}() tidak boleh mengirim job");
        }
    }

    /**
     * App ber-`custom_domain` **tidak** butuh domain dasar server: vhost-nya
     * domain custom itu, jadi operasi tetap boleh dijalankan.
     */
    public function testCustomDomainAppIsAllowedOnDomainLessAgent(): void
    {
        $app = ['custom_domain' => 'app.custom.example.com'] + $this->app();

        $plain = new AgentDeployerNginxFakeHandler();
        $this->queueSuccessfulJob($plain);
        $this->deployer($plain, ['domain' => ''])->writeNginxConfig($app);
        $this->assertSame('nginx_write', $plain->jsonBody(0)['op'] ?? null);

        $deploy = new AgentDeployerNginxFakeHandler();
        $this->queueSuccessfulJob($deploy);
        $deploy->reply(200, json_encode(['code' => 0, 'app' => []]));
        $this->deployer($deploy, ['domain' => ''])->deploy($app, $this->logger());
        $this->assertSame('deploy', $deploy->jsonBody(0)['op'] ?? null);
    }

    // ==================================================================
    // (7) capabilities satu sumber
    // ==================================================================

    public function testNewNginxAndSslOpsAreDeclaredInTheSingleModeList(): void
    {
        foreach (['nginx_write', 'nginx_remove', 'nginx_reload', 'ssl_issue', 'ssl_revoke'] as $op) {
            $this->assertContains($op, DeployWorkerLauncher::MODES, "op {$op} wajib ada di daftar mode");
        }

        $this->assertSame(DeployWorkerLauncher::MODES, AgentJobController::OPS, 'allowlist op = daftar mode');

        $source = (string) file_get_contents(dirname(__DIR__) . '/cli/deploy.php');
        preg_match('/in_array\(\$mode,\s*\[(?<modes>[^\]]*)\]/', $source, $match);
        preg_match_all("/'([a-z_]+)'/", (string) ($match['modes'] ?? ''), $modes);
        $this->assertSame(DeployWorkerLauncher::MODES, $modes[1] ?? [], 'cli/deploy.php wajib memakai daftar mode yang sama');
    }

    // ==================================================================
    // (8) kredensial DNS tidak pernah masuk rekaman job/respons/log
    // ==================================================================

    public function testDnsCredentialsInPayloadNeverReachJobRecordResponseOrLog(): void
    {
        $jobs = new AgentJobStore($this->tmp . '/jobs');
        $logDir = $this->tmp . '/logs/deploy';
        $spawned = [];

        $controller = new AgentJobController(
            $jobs,
            new DeployWorkerLauncher(
                function (array $argv, ?int &$pid = null, array $env = []) use (&$spawned): bool {
                    $spawned[] = $argv;
                    $pid = 4242;

                    return true;
                },
                $this->tmp . '/cli/deploy.php',
                $logDir
            ),
            new AgentAppProjection($this->tmp . '/rames.sqlite'),
            new AgentArtifactMaterializer($this->tmp . '/apps', $this->tmp . '/database', $this->tmp . '/database/keys')
        );

        $secret = 'cf-api-token-rahasia-9f3c';
        $payload = [
            'op' => 'ssl_issue',
            'app' => ['id' => self::APP_ID, 'name' => 'demo', 'container_prefix' => 'demo'],
            'domain' => 'demo.agent.example.test',
            // Klien yang menyelipkan kredensial: harus diabaikan, bukan disimpan.
            'cloudflare_creds' => '/root/.secrets/cf.ini',
            'dns_api_token' => $secret,
        ];

        $response = $controller->create($this->request('POST', '/jobs', (string) json_encode($payload)));
        $jobId = (string) (json_decode($response->rawBody(), true)['job_id'] ?? '');

        $raws = [$response->rawBody()];
        if ($jobId !== '') {
            $raws[] = $controller->show($this->request('GET', '/jobs/' . $jobId), $jobId)->rawBody();
            $raws[] = $controller->log($this->request('GET', '/jobs/' . $jobId . '/log'), $jobId)->rawBody();
        }
        foreach (glob($this->tmp . '/jobs/*') ?: [] as $file) {
            $raws[] = (string) file_get_contents($file);
        }
        foreach (glob($logDir . '/*') ?: [] as $file) {
            $raws[] = (string) file_get_contents($file);
        }

        $this->assertNotSame('', $jobId, 'job SSL harus tercatat');
        $this->assertCount(1, $spawned, 'worker tetap diluncurkan (mode ssl_issue)');

        $record = (array) $jobs->find($jobId);
        $recordPayload = (array) ($record['payload'] ?? []);
        $this->assertSame('demo.agent.example.test', $recordPayload['domain'] ?? null, 'domain tetap ikut payload');
        $this->assertArrayNotHasKey('cloudflare_creds', $recordPayload, 'kunci asing tidak boleh disimpan');
        $this->assertArrayNotHasKey('dns_api_token', $recordPayload, 'kunci asing tidak boleh disimpan');

        foreach ($raws as $raw) {
            $this->assertStringNotContainsString($secret, $raw, 'kredensial DNS bocor ke respons/rekaman/log');
            $this->assertStringNotContainsString('cf.ini', $raw, 'path kredensial DNS bocor ke respons/rekaman/log');
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @return array<string,mixed> */
    private function app(): array
    {
        return [
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'srv-2',
            'subdomain' => 'demo',
            'container_prefix' => 'demo',
        ];
    }

    /** @return callable(string,string):void */
    private function logger(): callable
    {
        return function (string $stage, string $message): void {
            $this->lines[] = [$stage, $message];
        };
    }

    /** Antrean satu job sukses: POST /jobs → status → drain log → tail log. */
    private function queueSuccessfulJob(AgentDeployerNginxFakeHandler $handler, string $jobId = 'job-ok'): void
    {
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => $jobId, 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done', 'message' => 'Selesai']]))
            ->reply(200, '')
            ->reply(200, '');
    }

    /**
     * @param array<string,mixed> $overrides override dokumen server (mis. `domain` kosong)
     */
    private function deployer(AgentDeployerNginxFakeHandler $handler, array $overrides = []): AgentDeployer
    {
        $server = array_merge([
            'id' => 'srv-2',
            'name' => 'vps-jakarta-2',
            'kind' => 'agent',
            'base_url' => 'https://agent.test:8443',
            'token' => self::TOKEN,
            'domain' => self::SERVER_DOMAIN,
            'tls_cert_pem' => self::selfSignedPem(),
        ], $overrides);

        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'));

        return new AgentDeployer(
            $server,
            $client,
            30,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
                // tanpa tidur nyata di uji
            }
        );
    }

    /** Request mesin (pola `AgentJobControllerTest::request()`). */
    private function request(string $method, string $path, string $body = ''): Request
    {
        $lines = ["{$method} {$path} HTTP/1.1", 'Host: agent.example.test'];
        if ($body !== '') {
            $lines[] = 'Content-Type: application/json';
        }
        $lines[] = 'Content-Length: ' . strlen($body);

        return new Request(implode("\r\n", $lines) . "\r\n\r\n" . $body);
    }

    private function nginxDir(): string
    {
        return $this->tmp . '/nginx/conf';
    }

    private function letsencryptDir(): string
    {
        return $this->tmp . '/letsencrypt';
    }

    /**
     * Config temp: seluruh path Nginx/SSL diarahkan ke direktori uji supaya
     * regresi "jatuh ke library lokal" langsung terlihat (berkas muncul di sini).
     */
    private function useConfig(): void
    {
        Config::clear();
        // `Config::load()` hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'nginx_conf_path' => $this->nginxDir(),
            'nginx_enabled_path' => '',
            'letsencrypt_path' => $this->letsencryptDir(),
            'ssl_webroot' => $this->tmp . '/webroot',
            'app_domain' => self::SERVER_DOMAIN,
            'port_range' => ['start' => 30000, 'end' => 30999],
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    /** Sertifikat self-signed sekali pakai (pola `AgentClientTest`). */
    private static function selfSignedPem(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $exported);

        return $pem = (string) $exported;
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
