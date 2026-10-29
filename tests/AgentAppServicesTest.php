<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentJobController;
use app\library\Agent\AgentAppProjection;
use app\library\Agent\AgentJobStore;
use app\library\Deploy\ResourceLimits;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;

/**
 * Endpoint agent `GET /apps/{id}/services` — sumber daftar service & limit base
 * compose untuk form "Batas resource" app yang artefaknya hanya ada di server
 * agent (control plane tidak punya salinan compose).
 *
 * Yang dikunci:
 *  1. app dikenal + base compose terbaca ⇒ `{code:0,data:{services,detected}}`
 *     berisi nama service + nilai `cpus`/`memory_mb` dari base;
 *  2. app tidak dikenal ⇒ **404** envelope `{code:1,…}` (agent lama tanpa
 *     endpoint ini juga 404, sehingga control plane menampilkan "perbarui
 *     agent");
 *  3. base compose tidak terbaca ⇒ tetap **200** `{code:0,data:{services:[],error}}`
 *     supaya UI menampilkan peringatan, bukan 500;
 *  4. tanpa secret: `env` (dan field sensitif lain) di proyeksi app tidak
 *     pernah ikut respons.
 *
 * Semua I/O di direktori temp (`sys_get_temp_dir()`): tidak ada `apps/`,
 * `database/rames.sqlite`, atau proses/Docker nyata yang tersentuh. Konfigurasi
 * `Config` dipulihkan di `tearDown()`.
 */
class AgentAppServicesTest extends TestCase
{
    private const APP_ID = 'app-svc-1';

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    private AgentJobController $controller;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_agentservices_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/apps', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        $sqlite = $this->tmp . '/rames.sqlite';
        $this->controller = new AgentJobController(
            new AgentJobStore($this->tmp . '/jobs'),
            null,
            new AgentAppProjection($sqlite)
        );
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
    }

    public function testServicesReadBaseComposeOfThisServer(): void
    {
        $this->seedApp([
            'env' => ['APP_KEY' => 'rahasia-jangan-bocor'],
            'compose_files' => ['docker-compose.yml'],
        ]);
        mkdir($this->tmp . '/apps/svc-demo', 0777, true);
        file_put_contents($this->tmp . '/apps/svc-demo/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx:alpine
            cpus: 1.5
            mem_limit: 256m
          db:
            image: mariadb:11.4
        YAML);

        $response = $this->controller->services($this->get('/apps/' . self::APP_ID . '/services'), self::APP_ID);

        $this->assertSame(200, $response->getStatusCode());
        $raw = $response->rawBody();
        $body = json_decode($raw, true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code']);
        $this->assertSame(['web', 'db'], $body['data']['services']);
        $this->assertSame(['cpus' => 1.5, 'memory_mb' => 256], $body['data']['detected']['web']);
        $this->assertSame(['cpus' => null, 'memory_mb' => null], $body['data']['detected']['db']);

        // Tanpa secret: env proyeksi tidak pernah ikut respons daftar service.
        $this->assertStringNotContainsString('rahasia-jangan-bocor', $raw);
        $this->assertStringNotContainsString('APP_KEY', $raw);
    }

    public function testUnknownAppIs404(): void
    {
        $response = $this->controller->services($this->get('/apps/tidak-ada/services'), 'tidak-ada');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(1, $body['code']);
        $this->assertSame('not_found', $body['reason']);
    }

    public function testUnreadableComposeReturnsWarningInsteadOfServerError(): void
    {
        $this->seedApp(['compose_files' => ['docker-compose.yml']]);
        // Direktori app/base compose sengaja tidak dibuat (checkout belum ada).

        $response = $this->controller->services($this->get('/apps/' . self::APP_ID . '/services'), self::APP_ID);

        $this->assertSame(200, $response->getStatusCode(), 'galat baca compose bukan kegagalan server');
        $body = json_decode($response->rawBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code']);
        $this->assertSame([], $body['data']['services']);
        $this->assertSame([], $body['data']['detected']);
        $error = (string) ($body['data']['error'] ?? '');
        $this->assertNotSame('', $error);

        // Path host agent tidak pernah keluar endpoint: direktori app direlatifkan.
        $this->assertStringNotContainsString($this->tmp, $error, 'path absolut apps_path bocor');
        $this->assertDoesNotMatchRegularExpression(
            '~(?<![\w.:-])/(?:[A-Za-z0-9._-]+/)+~',
            $error,
            'masih ada path absolut di pesan galat'
        );
        $this->assertStringContainsString('apps/svc-demo', $error, 'path relatif tetap informatif');
    }

    /**
     * `detect()` gagal (base compose tidak konsisten antar keluarga field) tetapi
     * daftar service terbaca ⇒ service tetap dikirim + `error` prefill; form tetap
     * bisa menyimpan batas (paritas dengan jalur lokal).
     */
    public function testDetectFailureKeepsServicesAndReportsPrefillError(): void
    {
        $this->seedApp(['compose_files' => ['docker-compose.yml']]);
        mkdir($this->tmp . '/apps/svc-demo', 0777, true);
        file_put_contents($this->tmp . '/apps/svc-demo/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
            cpus: 1
            deploy:
              resources:
                limits:
                  cpus: 2
        YAML);

        $response = $this->controller->services($this->get('/apps/' . self::APP_ID . '/services'), self::APP_ID);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code']);
        $this->assertSame(['web'], $body['data']['services'], 'daftar service tetap terkirim');
        $this->assertSame([], $body['data']['detected']);
        $this->assertNotSame('', (string) ($body['data']['error'] ?? ''), 'prefill gagal dilaporkan sebagai peringatan');
        $this->assertStringNotContainsString($this->tmp, (string) $body['data']['error']);
    }

    /**
     * Regresi #2: `NAN`/`INF` adalah kesalahan **validasi** (bukan
     * `JsonException` saat store menulis) dan tidak meninggalkan mutasi.
     */
    public function testNanAndInfiniteLimitsAreRejectedAsValidation(): void
    {
        $this->seedApp();
        $store = new AppStore($this->tmp . '/rames.sqlite');

        foreach ([NAN, INF] as $value) {
            $before = (string) json_encode($store->find(self::APP_ID));
            try {
                ResourceLimits::persistMetadata(
                    $store,
                    (array) $store->find(self::APP_ID),
                    ['web' => ['cpus' => $value]],
                    ['web']
                );
                $this->fail('NAN/INF harus ditolak sebagai kesalahan validasi');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('NAN/INF', $e->getMessage());
            }

            $this->assertSame($before, (string) json_encode($store->find(self::APP_ID)), 'store tidak boleh berubah');
        }
    }

    /**
     * Proyeksi app agent (id control plane) disimpan di store agent; endpoint
     * membaca dari store **itu**, bukan store control plane.
     *
     * @param array<string,mixed> $extra
     */
    private function seedApp(array $extra = []): void
    {
        $store = new AppStore($this->tmp . '/rames.sqlite');
        $store->create(array_merge([
            'id' => self::APP_ID,
            'name' => 'svc-demo',
            'status' => 'running',
        ], $extra));
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: agent.example.test\r\n\r\n");
    }

    private function useConfig(): void
    {
        Config::clear();
        // `Config::load()` hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
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

    private function removeTree(string $path): void
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
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
