<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AppNginxReloader;
use app\library\Deploy\AppSslRevoker;
use app\library\Deploy\ServerResolver;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

// Helper webman (`config()`, `app_subdomain*()`) tidak dimuat di luar runtime:
// pastikan `app/functions.php` ikut dimuat (pola `SubdomainTest`).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Handler Guzzle tiruan (agent palsu): merekam method/path/body tiap panggilan.
 * Dideklarasikan di berkas ini supaya tetap dapat di-autoload saat `--filter`.
 */
final class AppNginxOpsFakeAgent
{
    /** @var array<int,array{method:string,path:string,body:string}> */
    public array $calls = [];

    /** @var list<Response> */
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

    /** @return array<int,string> op job yang diterima agent, berurutan */
    public function ops(): array
    {
        $ops = [];
        foreach ($this->calls as $call) {
            if ($call['path'] !== '/jobs') {
                continue;
            }
            $decoded = json_decode($call['body'], true);
            if (is_array($decoded) && is_string($decoded['op'] ?? null)) {
                $ops[] = $decoded['op'];
            }
        }

        return $ops;
    }

    /** @return array<string,mixed> body JSON panggilan ke-`$index` */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->calls[$index]['body'] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AppNginxOpsFakeAgent: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'body' => (string) $request->getBody(),
        ];

        return Create::promiseFor(array_shift($this->queue));
    }
}

/** Reloader lokal tiruan — menghitung panggilan, tidak menyentuh Docker. */
final class AppNginxOpsLocalReloader extends NginxReloader
{
    public int $calls = 0;

    /** @param array<string,mixed> $result */
    public function __construct(private array $result = ['ok' => true, 'error' => null])
    {
        // Sengaja tidak memanggil parent::__construct(): reload() di-override.
    }

    public function reload(): array
    {
        $this->calls++;

        return ['ok' => true, 'message' => '', 'error' => null, 'output' => ''] + $this->result;
    }
}

/** Penerbit sertifikat lokal tiruan — menghitung revoke, tidak menjalankan certbot. */
final class AppNginxOpsLocalIssuer extends SslIssuer
{
    /** @var array<int,string> */
    public array $revoked = [];

    public function __construct()
    {
        // Sengaja tidak memanggil parent::__construct() (tidak ada proses/tool).
    }

    public function revoke(string $domain): void
    {
        $this->revoked[] = $domain;
    }
}

/**
 * Reload/revoke **server-aware** (lanjutan F2): `AppNginxReloader` &
 * `AppSslRevoker` — satu pintu untuk app lokal maupun server agent.
 *
 * Yang dikunci:
 *  1. app lokal ⇒ reloader/issuer lokal dipakai (perilaku lama), tanpa HTTP;
 *  2. app server agent ⇒ job `nginx_reload`/`ssl_revoke` **di server itu**;
 *     reloader/issuer lokal **tidak pernah** dipanggil (0 panggilan);
 *  3. kegagalan/op tak didukung di agent ⇒ terlihat (reload: `ok=false` + pesan;
 *     revoke: exception) — tidak ada "sukses palsu", tidak ada fallback lokal;
 *  4. `server_id` tak dikenal ⇒ melempar (fail-fast), tanpa panggilan lokal.
 *
 * Semua tanpa jaringan (handler tiruan), tanpa Docker/certbot, dan store/config
 * di `/tmp` — `database/`, `apps/`, `nginx-status/` tidak tersentuh.
 */
class AppNginxServerOpsTest extends TestCase
{
    private const APP_ID = 'app-ops-1';
    private const AGENT_ID = 'vps-agung';
    private const TOKEN = 'tok-uji-ops-51bd';

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-nginx-ops-' . bin2hex(random_bytes(4));
        foreach (['nginx/conf', 'nginx-status', 'tls', 'apps'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0777, true);
        }

        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // AppNginxReloader
    // ==================================================================

    public function testLocalAppReloadUsesTheLocalReloaderAndNeverTheAgent(): void
    {
        $app = $this->store()->create(['name' => 'lokal', 'status' => 'running']);
        $spy = new AppNginxOpsLocalReloader();
        $handler = new AppNginxOpsFakeAgent();

        $result = (new AppNginxReloader($app, null, $this->client($handler), $spy))->reload();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $spy->calls, 'app lokal tetap memakai reloader lokal');
        $this->assertSame(0, $handler->count(), 'app lokal tidak boleh memanggil agent');
    }

    public function testAgentAppReloadIsSentToThatAgentAndLocalReloaderIsUntouched(): void
    {
        $app = $this->agentApp();
        $spy = new AppNginxOpsLocalReloader();
        $handler = $this->successfulJob('job-reload', 'nginx_reload');

        $result = (new AppNginxReloader($app, null, $this->client($handler), $spy))->reload();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $spy->calls, 'F2: NginxReloader lokal TIDAK boleh dipanggil untuk app agent');
        $this->assertSame(['nginx_reload'], $handler->ops());
        $this->assertSame(self::APP_ID, $handler->jsonBody(0)['app']['id'] ?? null);
    }

    public function testAgentReloadFailureIsReportedWithoutLocalFallback(): void
    {
        $app = $this->agentApp();
        $spy = new AppNginxOpsLocalReloader();
        $handler = new AppNginxOpsFakeAgent();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-gagal', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'error', 'error' => 'nginx -t gagal: versi ' . self::TOKEN]]))
            ->reply(200, '')
            ->reply(200, '');

        $result = (new AppNginxReloader($app, null, $this->client($handler), $spy))->reload();

        $this->assertFalse($result['ok'], 'kegagalan agent tidak boleh dianggap sukses');
        $this->assertStringContainsString('nginx -t gagal', (string) $result['error']);
        $this->assertStringNotContainsString(self::TOKEN, (string) $result['error'], 'token agent tidak boleh bocor');
        $this->assertSame(0, $spy->calls, 'tidak ada fallback reload lokal');
    }

    public function testAgentWithoutTheReloadOpFailsFastWithAClearMessage(): void
    {
        $app = $this->agentApp();
        $spy = new AppNginxOpsLocalReloader();
        $handler = new AppNginxOpsFakeAgent();
        // Agent lama: op belum ada ⇒ 501 `unsupported` (fail-closed).
        $handler->reply(501, json_encode(['code' => 1, 'error' => 'Op tidak didukung agent ini.', 'reason' => 'unsupported']));

        $result = (new AppNginxReloader($app, null, $this->client($handler), $spy))->reload();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('tidak didukung', (string) $result['error']);
        $this->assertSame(0, $spy->calls);
    }

    public function testUnknownServerIsRejectedBeforeAnyReload(): void
    {
        $app = $this->store()->create(['name' => 'hantu', 'server_id' => 'server-hilang', 'status' => 'running']);
        $spy = new AppNginxOpsLocalReloader();

        try {
            (new AppNginxReloader($app, null, null, $spy))->reload();
            $this->fail('server_id tak dikenal harus ditolak (fail-fast, bukan reload host ini)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Server tidak dikenal', $e->getMessage());
        }

        $this->assertSame(0, $spy->calls);
    }

    // ==================================================================
    // AppSslRevoker
    // ==================================================================

    public function testLocalAppRevokeUsesTheLocalIssuer(): void
    {
        $app = $this->store()->create(['name' => 'lokal', 'status' => 'running']);
        $issuer = new AppNginxOpsLocalIssuer();
        $handler = new AppNginxOpsFakeAgent();

        (new AppSslRevoker(null, $this->client($handler), $issuer))->revoke($app, 'lama.example.com');

        $this->assertSame(['lama.example.com'], $issuer->revoked);
        $this->assertSame(0, $handler->count());
    }

    public function testAgentAppRevokeIsSentToThatAgentAndLocalIssuerIsUntouched(): void
    {
        $app = $this->agentApp(['custom_domain' => 'lama.example.com']);
        $issuer = new AppNginxOpsLocalIssuer();
        $handler = $this->successfulJob('job-revoke', 'ssl_revoke');

        (new AppSslRevoker(null, $this->client($handler), $issuer))->revoke($app, 'lama.example.com');

        $this->assertSame([], $issuer->revoked, 'SslIssuer lokal TIDAK boleh dipakai untuk app agent');
        $this->assertSame(['ssl_revoke'], $handler->ops());
        $this->assertSame('lama.example.com', $handler->jsonBody(0)['domain'] ?? null);
    }

    public function testAgentWithoutTheRevokeOpFailsFastInsteadOfPretendingSuccess(): void
    {
        $app = $this->agentApp(['custom_domain' => 'lama.example.com']);
        $issuer = new AppNginxOpsLocalIssuer();
        $handler = new AppNginxOpsFakeAgent();
        $handler->reply(501, json_encode(['code' => 1, 'error' => 'Op tidak didukung agent ini.', 'reason' => 'unsupported']));

        try {
            (new AppSslRevoker(null, $this->client($handler), $issuer))->revoke($app, 'lama.example.com');
            $this->fail('agent tanpa op ssl_revoke harus membuat pemanggil tahu (bukan sukses palsu)');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNSUPPORTED, $e->reason);
        }

        $this->assertSame([], $issuer->revoked, 'tidak ada revoke di host yang salah');
    }

    public function testUnknownServerIsRejectedBeforeAnyRevoke(): void
    {
        $app = $this->store()->create(['name' => 'hantu', 'server_id' => 'server-hilang', 'status' => 'running']);
        $issuer = new AppNginxOpsLocalIssuer();

        try {
            (new AppSslRevoker(null, null, $issuer))->revoke($app, 'lama.example.com');
            $this->fail('server_id tak dikenal harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Server tidak dikenal', $e->getMessage());
        }

        $this->assertSame([], $issuer->revoked);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function store(): AppStore
    {
        return new AppStore($this->tmp . '/rames.sqlite');
    }

    /** @param array<string,mixed> $overrides */
    private function agentApp(array $overrides = []): array
    {
        $servers = new ServerStore($this->tmp . '/rames.sqlite');
        $servers->create([
            'id' => self::AGENT_ID,
            'name' => self::AGENT_ID,
            'kind' => 'agent',
            'enabled' => true,
            'domain' => 'agung.example.test',
            'base_url' => 'https://agung.internal.test:8443',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::selfSignedPem(),
            'status' => 'online',
        ]);
        ServerResolver::forgetMemo();

        return $this->store()->create(array_merge([
            'id' => self::APP_ID,
            'name' => 'agen-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
        ], $overrides));
    }

    /** Antrekan respons satu job sukses (POST → status → drain → tail). */
    private function successfulJob(string $jobId, string $op): AppNginxOpsFakeAgent
    {
        $handler = new AppNginxOpsFakeAgent();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => $jobId, 'op' => $op, 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]))
            ->reply(200, '')
            ->reply(200, '');

        return $handler;
    }

    private function client(AppNginxOpsFakeAgent $handler): AgentClient
    {
        $server = $this->serverDoc();

        return new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'));
    }

    /** @return array<string,mixed> */
    private function serverDoc(): array
    {
        return [
            'id' => self::AGENT_ID,
            'name' => self::AGENT_ID,
            'kind' => 'agent',
            'base_url' => 'https://agung.internal.test:8443',
            'token' => self::TOKEN,
            'domain' => 'agung.example.test',
            'tls_cert_pem' => self::selfSignedPem(),
        ];
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'nginx_conf_path' => $this->tmp . '/nginx/conf',
            'nginx_enabled_path' => '',
            'nginx_reload_image' => 'rames-test.invalid/no-such-image:0',
            'nginx_bin' => '/nonexistent/nginx',
            'nginx_reload_status_file' => $this->tmp . '/nginx-status/last-reload.json',
            'letsencrypt_path' => $this->tmp . '/letsencrypt',
            'ssl_webroot' => $this->tmp . '/webroot',
            'app_domain' => 'lokal.example.org',
            'port_range' => ['start' => 30000, 'end' => 30999],
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp);
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

    /** @param array<string,mixed> $state */
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
            if (is_file($path)) {
                @unlink($path);
            }

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
