<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvManager;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\NetworkManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Nginx\NginxConfigGenerator;
use PHPUnit\Framework\TestCase;

// Helper webman (`base_path()`, `config()`, `app_subdomain*()`) tidak dimuat di
// luar runtime webman: definisikan konstanta + fungsi yang dibutuhkan.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Generator fake yang hanya merekam write/remove — dipakai untuk memastikan app
 * tanpa host port tidak diberi vhost (dan vhost lama dibuang).
 */
class AliasRecordingNginxGenerator extends NginxConfigGenerator
{
    /** @var array<int,string> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('/tmp/rames-nginx-alias-tidak-ada', '/tmp/rames-nginx-alias-tidak-ada');
    }

    public function write(string $name, string $content): void
    {
        $this->calls[] = 'write:' . $name;
    }

    public function remove(string $name): void
    {
        $this->calls[] = 'remove:' . $name;
    }
}

/**
 * Render config Nginx untuk **domain tambahan** (`apps.json.custom_domains[]`):
 *
 *  - alias **melayani** app lewat `proxy_pass` ke host port primary app yang
 *    sama — bukan redirect;
 *  - keberadaan alias tidak mengubah blok subdomain/domain utama (redirect
 *    subdomain tetap menunjuk domain utama);
 *  - SSL alias independen: blok `listen 443 ssl` + cert
 *    `/etc/letsencrypt/live/<alias>/` hanya bila status slot alias `active`;
 *  - app tanpa host port tetap tanpa vhost sama sekali.
 *
 * Semua test murni render — tidak menyentuh Docker/Nginx nyata.
 */
class LocalDeployerAliasNginxTest extends TestCase
{
    /** @var string */
    private string $tmp;

    protected function setUp(): void
    {
        // EnvManager butuh path yang bisa ditulis; tidak menyentuh data runtime.
        $this->tmp = sys_get_temp_dir() . '/rames-alias-nginx-' . bin2hex(random_bytes(4));
    }

    private function renderer(?NginxConfigGenerator $nginx = null): LocalDeployer
    {
        return new LocalDeployer(
            new DockerComposeRunner(),
            new DockerClient($this->tmp . '/no.sock'),
            $nginx ?? new NginxConfigGenerator($this->tmp . '/nginx', ''),
            $this->tmp,
            new EnvManager($this->tmp . '/env'),
            new NetworkManager()
        );
    }

    /**
     * App dengan host port terpublish (syarat vhost berlaku).
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function appWithPort(string $name, array $extra = []): array
    {
        return $extra + [
            'name' => $name,
            'containers' => [
                ['service_name' => 'web', 'container_name' => $name, 'ports' => [['host' => 30000, 'container' => 80]]],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $app
     */
    private function letsEncryptPath(): string
    {
        return (string) config('deploy.letsencrypt_path', '/etc/letsencrypt');
    }

    /**
     * (a) Domain utama + 2 alias: subdomain redirect ke domain utama, domain
     * utama serve, kedua alias serve, tidak ada redirect untuk alias.
     */
    public function testAliasDilayaniTanpaMengubahRedirectSubdomain(): void
    {
        $app = $this->appWithPort('multi', [
            'custom_domain' => 'utama.example.com',
            'custom_domains' => [
                ['domain' => 'satu.example.org', 'ssl_status' => 'disabled'],
                ['domain' => 'dua.example.org', 'ssl_status' => 'disabled'],
            ],
        ]);
        $sub = app_subdomain_of($app);
        $config = $this->renderer()->renderNginxConfig($app);

        // Subdomain: satu blok redirect ke domain utama (bukan proxy).
        $this->assertSame(1, substr_count($config, 'server_name ' . $sub . ';'));
        $this->assertStringContainsString('return 301 http://utama.example.com$request_uri;', $config);

        // Domain utama: satu serve block.
        $this->assertSame(1, substr_count($config, 'server_name utama.example.com;'));

        // Alias: masing-masing satu serve block, proxy ke port primary yang sama.
        $this->assertSame(1, substr_count($config, 'server_name satu.example.org;'));
        $this->assertSame(1, substr_count($config, 'server_name dua.example.org;'));
        $this->assertSame(
            3,
            substr_count($config, 'proxy_pass http://127.0.0.1:30000;'),
            'domain utama + 2 alias di-proxy ke host port primary'
        );

        // Tidak ada redirect tambahan: hanya redirect subdomain.
        $this->assertSame(1, substr_count($config, 'return 301'), 'alias tidak boleh redirect');
    }

    /**
     * (b) SSL alias independen: alias `active` mendapat blok 443 + cert milik
     * alias itu, alias `disabled` hanya listen 80.
     */
    public function testSslAliasIndependenPerAlias(): void
    {
        $app = $this->appWithPort('multi', [
            'custom_domains' => [
                ['domain' => 'aman.example.org', 'ssl_status' => 'active'],
                ['domain' => 'polos.example.org', 'ssl_status' => 'disabled'],
            ],
        ]);
        $config = $this->renderer()->renderNginxConfig($app);
        $le = $this->letsEncryptPath();

        // Hanya alias `active` yang punya blok 443 (subdomain & alias polos: 80 saja).
        $this->assertSame(1, substr_count($config, 'listen 443 ssl;'));
        $this->assertStringContainsString(
            'ssl_certificate ' . $le . '/live/aman.example.org/fullchain.pem;',
            $config,
            'cert alias mengikuti server_name alias'
        );
        $this->assertStringContainsString(
            'ssl_certificate_key ' . $le . '/live/aman.example.org/privkey.pem;',
            $config
        );
        $this->assertStringNotContainsString('live/polos.example.org/', $config, 'alias disabled tanpa cert');
        $this->assertStringNotContainsString('live/' . app_subdomain_of($app) . '/', $config, 'subdomain tidak ikut SSL');

        // Alias disabled tetap dilayani di 80 (subdomain + 2 alias = 3 proxy).
        $this->assertStringContainsString('server_name polos.example.org;', $config);
        $this->assertSame(3, substr_count($config, 'proxy_pass http://127.0.0.1:30000;'));
    }

    /**
     * (c) Tanpa domain utama: subdomain + alias sama-sama serve app.
     */
    public function testTanpaDomainUtamaSubdomainDanAliasServe(): void
    {
        $app = $this->appWithPort('multi', [
            'custom_domains' => [
                ['domain' => 'satu.example.org', 'ssl_status' => 'disabled'],
            ],
        ]);
        $config = $this->renderer()->renderNginxConfig($app);

        $this->assertStringContainsString('server_name ' . app_subdomain_of($app) . ';', $config);
        $this->assertStringContainsString('server_name satu.example.org;', $config);
        $this->assertSame(2, substr_count($config, 'proxy_pass http://127.0.0.1:30000;'));
        $this->assertStringNotContainsString('return 301', $config);
    }

    /**
     * App multi-service: web (host 30000) + api (host 30002).
     *
     * @param array<int,array<string,mixed>> $aliases
     * @return array<string,mixed>
     */
    private function multiServiceApp(array $aliases): array
    {
        return [
            'name' => 'multi',
            'primary_service' => 'web',
            'primary_port' => 80,
            'custom_domains' => $aliases,
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'multi-web', 'ports' => [['host' => 30000, 'container' => 80]]],
                ['service_name' => 'api', 'container_name' => 'multi-api', 'ports' => [['host' => 30002, 'container' => 8000]]],
            ],
        ];
    }

    /**
     * (e) Tiap domain tambahan bisa diarahkan ke host port lain milik app yang
     * sama (`target_host_port`); alias tanpa target tetap ke port primary.
     */
    public function testAliasTargetPortPerDomain(): void
    {
        $app = $this->multiServiceApp([
            ['domain' => 'api.example.org', 'target_host_port' => 30002, 'ssl_status' => 'disabled'],
            ['domain' => 'web.example.org', 'ssl_status' => 'disabled'],
        ]);
        $config = $this->renderer()->renderNginxConfig($app);

        // Subdomain (serve, tanpa domain utama) + alias tanpa target ⇒ primary.
        $this->assertSame(2, substr_count($config, 'proxy_pass http://127.0.0.1:30000;'));
        $this->assertSame(1, substr_count($config, 'proxy_pass http://127.0.0.1:30002;'));
    }

    /**
     * (f) Target yang sudah tidak dipublikasikan app (port dihapus setelah
     * alias dibuat) jatuh kembali ke port primary — bukan vhost mati.
     */
    public function testAliasTargetPortYangTidakLagiDipublikasikanJatuhKePrimary(): void
    {
        $app = $this->multiServiceApp([
            ['domain' => 'api.example.org', 'target_host_port' => 39999, 'ssl_status' => 'disabled'],
        ]);
        $config = $this->renderer()->renderNginxConfig($app);

        $this->assertStringNotContainsString('127.0.0.1:39999', $config);
        $this->assertSame(2, substr_count($config, 'proxy_pass http://127.0.0.1:30000;'));
    }

    /**
     * (d) App tanpa host port: tidak ada vhost (config lama dibuang), dan
     * alias tidak mengubah keputusan itu.
     */
    public function testTanpaHostPortTidakMerenderDomainAlias(): void
    {
        $nginx = new AliasRecordingNginxGenerator();
        $app = [
            'name' => 'dbsaja',
            'custom_domain' => 'utama.example.com',
            'custom_domains' => [['domain' => 'satu.example.org', 'ssl_status' => 'active']],
            'containers' => [
                ['service_name' => 'db', 'container_name' => 'dbsaja-db', 'ports' => []],
            ],
        ];

        $this->renderer($nginx)->writeNginxConfig($app);

        $this->assertSame(['remove:dbsaja'], $nginx->calls, 'tanpa host port: vhost dibuang, tidak ditulis');
    }
}
