<?php
declare(strict_types=1);

namespace Tests;

use app\controller\api\AppApiController;
use app\library\Api\ApiKeyAuth;
use app\library\Auth\UserStore;
use app\library\Domain\AppDomainService;
use app\library\Deploy\ServerResolver;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslActivationService;
use app\library\SSL\SslQueue;
use app\library\Storage\AppStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/** Reloader lokal tiruan: mencatat panggilan, tidak menyentuh Docker/nginx nyata. */
final class AppDomainApiReloader extends NginxReloader
{
    public int $calls = 0;

    public function __construct()
    {
        // reload() di-override ⇒ konstruktor induk (config/Docker) tidak dipakai.
    }

    public function reload(): array
    {
        $this->calls++;

        return ['ok' => true, 'message' => '', 'error' => null, 'output' => ''];
    }
}

/**
 * Endpoint domain pada API eksternal (`/api/v1/apps/{slug}/domains|domain`):
 *
 *  - `GET` status domain yang sudah diset (subdomain, utama, alias + SSL);
 *  - `PUT/DELETE` custom domain utama;
 *  - `POST/PATCH/DELETE` domain tambahan (alias) & target proxy-nya.
 *
 * Yang dikunci: envelope respons, otorisasi `view`/`domain` (404 seragam),
 * validasi 422 tanpa efek samping, idempotensi, dan `warnings` saat config
 * Nginx gagal ditulis (data tetap tersimpan). Seluruh store/config memakai
 * berkas temp — `database/`, `apps/`, Docker, dan certbot nyata tidak disentuh.
 */
class AppDomainApiControllerTest extends TestCase
{
    private string $tmp = '';

    private string $db = '';

    private UserStore $users;

    private AppStore $apps;

    private AppDomainApiReloader $reloader;

    private SslQueue $queue;

    /** @var array<string,string> API key plaintext per username (hanya di tes) */
    private array $keys = [];

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-domain-api-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'nginx-status', 'sessions', 'letsencrypt'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0777, true);
        }
        $this->db = $this->tmp . '/rames.sqlite';

        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        $this->users = new UserStore($this->db);
        $this->users->create('admin', 'rahasia-admin-1');
        $this->users->create('member', 'rahasia-member-1');
        $this->users->create('stranger', 'rahasia-stranger-1');
        foreach (['admin', 'member', 'stranger'] as $username) {
            $user = $this->users->findByUsername($username);
            $this->keys[$username] = $this->users->issueApiKey((string) $user['id']);
        }

        $this->apps = new AppStore($this->db);
        $this->reloader = new AppDomainApiReloader();
        $this->queue = new SslQueue(new SqliteDatabase($this->db));
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
    }

    public function testStatusListsPrimaryAliasAndSubdomain(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domain'] = 'app.example.com';
            $s['ssl_status'] = 'active';
            $s['custom_ssl_status'] = 'active';
            $s['custom_ssl_expires_at'] = '2027-02-01T00:00:00+00:00';
            $s['custom_domains'] = [[
                'domain' => 'alias.example.com',
                'target_host_port' => 30002,
                'ssl_status' => 'pending',
                'ssl_stage' => 'queued',
                'ssl_message' => 'Menunggu antrean SSL.',
            ]];
        });

        $response = $this->controller()->domains($this->get('/api/v1/apps/demo/domains'), 'demo');
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['served']);
        $this->assertSame('demo.example.org', $payload['data']['subdomain']['domain']);
        $this->assertSame('redirect', $payload['data']['subdomain']['role']);
        $this->assertSame('active', $payload['data']['subdomain']['ssl']['status']);
        $this->assertSame('app.example.com', $payload['data']['primary']['domain']);
        $this->assertSame('serve', $payload['data']['primary']['role']);
        $this->assertSame(30002, $payload['data']['aliases'][0]['target_host_port']);
        $this->assertSame('pending', $payload['data']['aliases'][0]['ssl']['status']);
        $this->assertSame('queued', $payload['data']['aliases'][0]['ssl']['stage']);
        $this->assertSame(0, $this->reloader->calls, 'status read-only');
    }

    public function testSetPrimaryDomainSavesWritesConfigAndReloads(): void
    {
        $app = $this->createApp('demo');

        $response = $this->controller()->setPrimaryDomain(
            $this->json('PUT', '/api/v1/apps/demo/domain', ['domain' => 'App.Example.COM']),
            'demo'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['data']['changed']);
        $this->assertTrue($payload['data']['applied']);
        $this->assertTrue($payload['data']['reloaded'], 'reload Nginx bagian dari proses API');
        $this->assertSame('app.example.com', $payload['data']['domain']);
        $this->assertSame('app.example.com', $payload['data']['domains']['primary']['domain']);
        $this->assertSame('redirect', $payload['data']['domains']['subdomain']['role']);
        $this->assertSame([], $payload['data']['warnings']);
        $this->assertSame(1, $this->reloader->calls);

        $saved = $this->apps->find($app['id']);
        $this->assertSame('app.example.com', $saved['custom_domain']);
        $this->assertSame('disabled', $saved['custom_ssl_status']);
        $this->assertFileExists($this->tmp . '/nginx/conf/demo.conf');
    }

    public function testSetPrimaryDomainSameValueIsIdempotent(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domain'] = 'app.example.com';
        });

        $response = $this->controller()->setPrimaryDomain(
            $this->json('PUT', '/api/v1/apps/demo/domain', ['domain' => 'app.example.com']),
            'demo'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($payload['data']['changed']);
        $this->assertFalse($payload['data']['applied']);
        $this->assertSame(0, $this->reloader->calls, 'tanpa efek samping');
    }

    public function testSetPrimaryDomainRejectsNonPublicDomain(): void
    {
        $app = $this->createApp('demo');

        $response = $this->controller()->setPrimaryDomain(
            $this->json('PUT', '/api/v1/apps/demo/domain', ['domain' => 'localhost']),
            'demo'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $payload['reason']);
        $this->assertSame(0, $this->reloader->calls);
        $this->assertArrayNotHasKey('custom_domain', $this->apps->find($app['id']));
    }

    public function testSetPrimaryDomainRequiresDomainAbility(): void
    {
        $app = $this->createApp('demo');
        $member = $this->users->findByUsername('member');
        $this->apps->update($app['id'], static function (array &$s) use ($member): void {
            $s['members'] = [(string) $member['id'] => 'viewer'];
        });

        $denied = $this->controller()->setPrimaryDomain(
            $this->json('PUT', '/api/v1/apps/demo/domain', ['domain' => 'app.example.com'], $this->keys['member']),
            'demo'
        );
        $missing = $this->controller()->setPrimaryDomain(
            $this->json('PUT', '/api/v1/apps/hantu/domain', ['domain' => 'app.example.com'], $this->keys['stranger']),
            'hantu'
        );

        $this->assertSame(404, $denied->getStatusCode(), 'viewer tidak punya ability domain');
        $this->assertSame(404, $missing->getStatusCode(), 'app tak terlihat ⇒ 404 seragam');
        $this->assertSame(0, $this->reloader->calls);

        // Ability `view` tetap boleh membaca status domain.
        $status = $this->controller()->domains($this->get('/api/v1/apps/demo/domains', $this->keys['member']), 'demo');
        $this->assertSame(200, $status->getStatusCode());
    }

    public function testRemovePrimaryDomainRevokesAndReturnsChanged(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domain'] = 'app.example.com';
            $s['custom_ssl_status'] = 'active';
        });

        $response = $this->controller()->removePrimaryDomain(
            $this->request('DELETE', '/api/v1/apps/demo/domain'),
            'demo'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['data']['changed']);
        $this->assertNull($payload['data']['domains']['primary']);
        $this->assertSame('serve', $payload['data']['domains']['subdomain']['role']);
        $this->assertSame(1, $this->reloader->calls);

        $saved = $this->apps->find($app['id']);
        $this->assertNull($saved['custom_domain']);
        $this->assertSame('disabled', $saved['custom_ssl_status']);
    }

    public function testAddAliasPatchTargetAndRemove(): void
    {
        $app = $this->createApp('demo');

        $added = $this->controller()->addDomainAlias(
            $this->json('POST', '/api/v1/apps/demo/domains', [
                'domain' => 'Alias.Example.com',
                'target_host_port' => 30002,
            ]),
            'demo'
        );
        $payload = json_decode($added->rawBody(), true);

        $this->assertSame(200, $added->getStatusCode());
        $this->assertSame('alias.example.com', $payload['data']['domain']);
        $this->assertSame(30002, $payload['data']['domains']['aliases'][0]['target_host_port']);
        $this->assertSame('serve', $payload['data']['domains']['aliases'][0]['role']);

        $patched = $this->controller()->updateDomainAlias(
            $this->json('PATCH', '/api/v1/apps/demo/domains/alias.example.com', ['target_host_port' => 30000]),
            'demo',
            'alias.example.com'
        );
        $patchedPayload = json_decode($patched->rawBody(), true);

        $this->assertSame(200, $patched->getStatusCode());
        $this->assertSame(30000, $patchedPayload['data']['domains']['aliases'][0]['target_host_port']);
        $this->assertSame(30000, $this->apps->find($app['id'])['custom_domains'][0]['target_host_port']);

        $removed = $this->controller()->removeDomainAlias(
            $this->request('DELETE', '/api/v1/apps/demo/domains/alias.example.com'),
            'demo',
            'alias.example.com'
        );
        $removedPayload = json_decode($removed->rawBody(), true);

        $this->assertSame(200, $removed->getStatusCode());
        $this->assertTrue($removedPayload['data']['changed']);
        $this->assertSame([], $removedPayload['data']['domains']['aliases']);
        $this->assertSame([], $this->apps->find($app['id'])['custom_domains']);
        $this->assertSame(3, $this->reloader->calls);
    }

    public function testAddAliasRejectsDuplicate(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'alias.example.com', 'ssl_status' => 'disabled']];
        });

        $response = $this->controller()->addDomainAlias(
            $this->json('POST', '/api/v1/apps/demo/domains', ['domain' => 'ALIAS.example.com']),
            'demo'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $payload['reason']);
        $this->assertStringContainsString('sudah terdaftar sebagai domain tambahan', (string) $payload['error']);
        $this->assertSame(0, $this->reloader->calls);
        $this->assertCount(1, $this->apps->find($app['id'])['custom_domains']);
    }

    public function testPatchUnknownAliasReturns404(): void
    {
        $this->createApp('demo');

        $response = $this->controller()->updateDomainAlias(
            $this->json('PATCH', '/api/v1/apps/demo/domains/hantu.example.com', ['target_host_port' => 30000]),
            'demo',
            'hantu.example.com'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('alias_not_found', $payload['reason']);
        $this->assertSame(0, $this->reloader->calls);
    }

    public function testRemoveUnknownAliasReturns404(): void
    {
        $this->createApp('demo');

        $response = $this->controller()->removeDomainAlias(
            $this->request('DELETE', '/api/v1/apps/demo/domains/hantu.example.com'),
            'demo',
            'hantu.example.com'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('alias_not_found', $payload['reason']);
    }

    public function testMalformedJsonReturns422(): void
    {
        $this->createApp('demo');

        $request = new Request(
            "PUT /api/v1/apps/demo/domain HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/json\r\nAuthorization: Bearer " . $this->keys['admin'] . "\r\n"
            . "Content-Length: 7\r\n\r\n{\"a\":]"
        );

        $response = $this->controller()->setPrimaryDomain($request, 'demo');
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('invalid_json', $payload['reason']);
        $this->assertSame(0, $this->reloader->calls);
    }

    public function testAmbiguousSlugReturns409(): void
    {
        $this->createApp('demo', 'srv-a');
        $this->createApp('demo', 'srv-b');

        $response = $this->controller()->domains($this->get('/api/v1/apps/demo/domains'), 'demo');

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testNginxWriteFailureIsReportedAsWarningWithoutRollback(): void
    {
        $app = $this->createApp('demo');
        file_put_contents($this->tmp . '/blocker', 'x');
        $this->useConfig(['nginx_conf_path' => $this->tmp . '/blocker/conf']);

        $response = $this->controller()->setPrimaryDomain(
            $this->json('PUT', '/api/v1/apps/demo/domain', ['domain' => 'app.example.com']),
            'demo'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['data']['changed']);
        $this->assertFalse($payload['data']['applied']);
        $this->assertFalse($payload['data']['reloaded']);
        $this->assertNotSame([], $payload['data']['warnings']);
        $this->assertStringContainsString('Nginx', (string) $payload['data']['warnings'][0]);
        $this->assertSame('app.example.com', $this->apps->find($app['id'])['custom_domain']);
    }

    public function testEnableSslQueuesJobAndMarksSlotPending(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domain'] = 'app.example.com';
        });

        $response = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/app.example.com/ssl'),
            'demo',
            'app.example.com'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pending', $payload['data']['status']);
        $this->assertSame('app.example.com', $payload['data']['domain']);
        $this->assertSame('custom_ssl', $payload['data']['slot']);
        $this->assertSame(1, $payload['data']['queue_position']);
        $this->assertSame(1, $payload['data']['queue_size']);
        $this->assertStringContainsString('masuk antrean', (string) $payload['data']['message']);
        $this->assertSame('/api/v1/apps/demo/domains', $payload['data']['poll_url']);

        $saved = $this->apps->find($app['id']);
        $this->assertSame('pending', $saved['custom_ssl_status']);
        $this->assertSame('queued', $saved['custom_ssl_stage']);
        $this->assertNotNull($this->queue->findActive('app:' . $app['id'] . ':app.example.com:custom_ssl'));
    }

    public function testEnableSslTwiceIsIdempotent(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domain'] = 'app.example.com';
        });

        $first = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/app.example.com/ssl'),
            'demo',
            'app.example.com'
        );
        $second = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/app.example.com/ssl'),
            'demo',
            'app.example.com'
        );
        $payload = json_decode($second->rawBody(), true);

        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertTrue($payload['data']['already']);
        $this->assertSame('pending', $payload['data']['status']);
        $this->assertSame('SSL untuk app.example.com sudah pending.', $payload['data']['message']);
    }

    public function testEnableSslForAliasQueuesAliasSlot(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'alias.example.com', 'ssl_status' => 'disabled']];
        });

        $response = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/alias.example.com/ssl'),
            'demo',
            'alias.example.com'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('alias:alias.example.com', $payload['data']['slot']);
        $this->assertSame(
            'pending',
            $this->apps->find($app['id'])['custom_domains'][0]['ssl_status']
        );
    }

    public function testEnableSslRejectsDomainNotOwnedByApp(): void
    {
        $this->createApp('demo');

        $response = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/lain.example.com/ssl'),
            'demo',
            'lain.example.com'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('domain_not_found', $payload['reason']);
    }

    public function testEnableSslRequiresSslAbility(): void
    {
        $app = $this->createApp('demo');
        $member = $this->users->findByUsername('member');
        $this->apps->update($app['id'], static function (array &$s) use ($member): void {
            $s['custom_domain'] = 'app.example.com';
            $s['members'] = [(string) $member['id'] => 'viewer'];
        });

        $response = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/app.example.com/ssl', '', $this->keys['member']),
            'demo',
            'app.example.com'
        );

        $this->assertSame(404, $response->getStatusCode(), 'viewer tidak punya ability ssl');
        $this->assertNull($this->queue->findActive('app:' . $app['id'] . ':app.example.com:custom_ssl'));
    }

    public function testEnableSslRejectsNonPublicDomain(): void
    {
        $app = $this->createApp('demo');
        $this->apps->update($app['id'], static function (array &$s): void {
            $s['subdomain'] = 'app.local';
        });

        $response = $this->controller()->enableDomainSsl(
            $this->request('POST', '/api/v1/apps/demo/domains/app.local/ssl'),
            'demo',
            'app.local'
        );
        $payload = json_decode($response->rawBody(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $payload['reason']);
        $this->assertStringContainsString('bukan domain publik', (string) $payload['error']);
    }

    private function controller(): AppApiController
    {
        return new AppApiController(
            $this->apps,
            null,
            new ApiKeyAuth($this->users),
            new AppDomainService($this->apps, $this->reloader),
            new SslActivationService($this->apps, $this->queue)
        );
    }

    /**
     * App dua service/port (web host 30000, api host 30002) — syarat menguji
     * `target_host_port` domain tambahan.
     *
     * @return array<string,mixed>
     */
    private function createApp(string $name, string $serverId = 'local'): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => 'owner-other',
            'server_id' => $serverId,
            'status' => 'running',
            'primary_service' => 'web',
            'primary_port' => 80,
            'containers' => [
                [
                    'service_name' => 'web',
                    'internal_port' => 80,
                    'host_port' => 30000,
                    'ports' => [['host' => 30000, 'container' => 80]],
                ],
                [
                    'service_name' => 'api',
                    'internal_port' => 8000,
                    'host_port' => 30002,
                    'ports' => [['host' => 30002, 'container' => 8000]],
                ],
            ],
        ]);
    }

    private function get(string $path, ?string $key = null): Request
    {
        return $this->request('GET', $path, '', $key);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(string $method, string $path, array $body, ?string $key = null): Request
    {
        return $this->request($method, $path, json_encode($body, JSON_THROW_ON_ERROR), $key, true);
    }

    private function request(string $method, string $path, string $body = '', ?string $key = null, bool $json = false): Request
    {
        $key ??= $this->keys['admin'];
        $headers = "Host: localhost\r\nAccept: application/json\r\n";
        if ($json) {
            $headers .= "Content-Type: application/json\r\n";
        }
        $headers .= 'Authorization: Bearer ' . $key . "\r\n";

        return new Request(
            $method . ' ' . $path . " HTTP/1.1\r\n" . $headers
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * Config minimal untuk jalur domain: SQLite temp + direktori Nginx temp.
     *
     * @param array<string,mixed> $overrides
     */
    private function useConfig(array $overrides = []): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        $defaults = [
            'sqlite_file' => $this->db,
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'nginx_conf_path' => $this->tmp . '/nginx/conf',
            'nginx_enabled_path' => '',
            'nginx_reload_image' => 'rames-test.invalid/no-such-image:0',
            'nginx_bin' => '/nonexistent/nginx',
            'nginx_reload_status_file' => $this->tmp . '/nginx-status/last-reload.json',
            'letsencrypt_path' => $this->tmp . '/letsencrypt',
            'ssl_webroot' => $this->tmp . '/webroot',
            'app_domain' => 'example.org',
            'port_range' => ['start' => 30000, 'end' => 30999],
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
            'deploy_timeout' => 5,
        ];
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(array_merge($defaults, $overrides), true) . ';' . PHP_EOL
        );
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

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
