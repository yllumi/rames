<?php
declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use app\controller\DatabaseController;
use app\library\Auth\AppAccess;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

// Helper webman (`config()`, `json()`, `view()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Handler Guzzle tiruan untuk jalur **control plane → agent** (proxy Adminer per
 * server, F3d): merekam method/path/query/body/header setiap panggilan dan
 * menulis body respons ke `sink` seperti handler nyata — sehingga
 * `AgentClient::forwardToFile()` (berkas sementara + batas ukuran) benar-benar
 * teruji tanpa jaringan.
 */
final class DatabaseIndexFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string,headers:array<string,string>}> */
    public array $calls = [];

    /** @var list<array{status:int,headers:array<string,string>,body:string}> */
    private array $queue = [];

    /** @param array<string,string> $headers */
    public function reply(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => $body];

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], $this->calls);
    }

    /** @return array{method:string,path:string,query:string,body:string,headers:array<string,string>} */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total {$this->count()})");
        }

        return $this->calls[$index];
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('DatabaseIndexFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', array_map('strval', (array) $values));
        }
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
            'headers' => $headers,
        ];

        $next = array_shift($this->queue);

        $sink = $options['sink'] ?? null;
        if ($next['body'] !== '' && $sink instanceof StreamInterface) {
            $sink->write($next['body']);
        }

        $responseHeaders = $next['headers'];
        if ($next['body'] !== '' && !isset($responseHeaders['Content-Length'])) {
            $responseHeaders['Content-Length'] = (string) strlen($next['body']);
        }

        // Body ikut di respons (jalur JSON `send()`) DAN ditulis ke `sink`
        // (jalur berkas `forwardToFile()`), seperti handler nyata.
        $response = new Psr7Response($next['status'], $responseHeaders, $next['body']);
        // Handler Guzzle nyata (cURL/stream/mock) memanggil `on_headers` sebelum
        // body dialirkan — guard batas ukuran produksi bergantung pada ini.
        $onHeaders = $options['on_headers'] ?? null;
        if (is_callable($onHeaders)) {
            $onHeaders($response, $request);
        }

        return Create::promiseFor($response);
    }
}

/**
 * Engine Docker **host control plane** tiruan: merekam sentuhan, dan bisa
 * "meledak" bila tersentuh (bukti app server agent tidak pernah dibaca dari
 * daemon lokal).
 */
final class DatabaseIndexLocalDocker extends DockerClient
{
    /** @var list<string> */
    public array $inspected = [];

    /** @var list<string> */
    public array $listed = [];

    public int $pingCalls = 0;

    /** true ⇒ sentuhan apa pun = kegagalan uji (jalur lokal tidak boleh aktif). */
    public bool $explode = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function ping(): bool
    {
        $this->pingCalls++;
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH (uji gagal).');
        }

        return false; // helper Adminer lokal tidak bisa siap → 502 (jalur lokal)
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH (uji gagal).');
        }
        $this->listed[] = 'list';

        return [];
    }

    public function inspectContainer(string $id): array
    {
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH (uji gagal).');
        }
        $this->inspected[] = $id;

        return [
            'Id' => $id,
            'Name' => '/' . $id,
            'Config' => ['Image' => 'mysql:8'],
            'State' => ['Status' => 'running'],
            'NetworkSettings' => ['Networks' => ['demo_default' => ['IPAddress' => '172.20.0.5']]],
        ];
    }
}

/**
 * `DatabaseController` dengan seluruh seam uji untuk halaman `/database`
 * (store app, registry server, detektor, engine lokal, deployer agent, view).
 */
final class DatabaseIndexScopeController extends DatabaseController
{
    /** @var list<array{template:string,vars:array<string,mixed>}> */
    public array $rendered = [];

    public function __construct(
        private AppStore $apps,
        private ServerResolver $resolver,
        private DockerClient $docker,
        private ?AgentDeployer $agent,
    ) {
    }

    protected function appStore(): AppStore
    {
        return $this->apps;
    }

    protected function serverResolver(): ServerResolver
    {
        return $this->resolver;
    }

    protected function dockerClient(): DockerClient
    {
        return $this->docker;
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return $this->agent;
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->agent;
    }

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered[] = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered');
    }

    protected function audit(?array $app, string $container, string $action): void
    {
    }
}

/**
 * Halaman `/database` **per server** (F3d): baris container DB app di server
 * agent diambil dari agent itu, kepemilikan dipetakan di control plane, dan
 * daemon host control plane tidak pernah dibaca untuk app remote.
 *
 * Stub global (`current_user`/`is_admin`) + proses terpisah, pola
 * `AppControllerAgentHostIsolationTest`.
 */
final class DatabaseIndexServerScopeTest extends TestCase
{
    private const CONTAINER = 'demo-db-1';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-dbindex-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException('gagal membuat temp dir');
        }

        $GLOBALS['RAMES_TEST_USER'] = ['id' => 'u-admin', 'username' => 'admin', 'role' => AppAccess::ROLE_ADMIN];
        $GLOBALS['RAMES_FLASH'] = [];
        if (!function_exists('current_user')) {
            $file = $this->tmp . '/stubs.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
        }
        if (!function_exists('is_admin')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        $this->useConfig();
    }

    /**
     * Config temp (tanpa `database/`/`apps/` nyata): store app temp + engine yang
     * tidak ada — deteksi lokal tidak pernah dijalankan untuk server agent.
     */
    private function useConfig(): void
    {
        Config::clear();
        if (!is_dir($this->tmp . '/config')) {
            mkdir($this->tmp . '/config', 0777, true);
        }
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
            'app_domain' => 'lokal.example.org',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentContainersAreListedFromTheAgentAndOwnershipIsMappedHere(): void
    {
        $handler = new DatabaseIndexFakeHandler();
        $handler->reply(200, (string) json_encode(['code' => 0, 'data' => ['rows' => [
            ['container_name' => self::CONTAINER, 'container_id' => 'c-db', 'image' => 'mysql:8', 'state' => 'running', 'status' => 'Up'],
            ['container_name' => 'asing-db-1', 'container_id' => 'c-x', 'image' => 'mariadb:11', 'state' => 'running', 'status' => 'Up'],
        ]]]));

        $docker = new DatabaseIndexLocalDocker();
        $docker->explode = true; // daemon lokal TIDAK boleh tersentuh

        $controller = $this->controller($handler, $docker);
        $controller->index($this->get('/database'));

        $rows = $controller->rendered[0]['vars']['rows'];
        $byName = array_column($rows, null, 'container_name');
        $this->assertSame(['asing-db-1', self::CONTAINER], array_keys($byName));
        // Kepemilikan dipetakan di control plane (agent tidak tahu user dashboard).
        $this->assertTrue($byName[self::CONTAINER]['owned']);
        $this->assertSame('demo', $byName[self::CONTAINER]['app_name']);
        $this->assertSame('srv-agent', $byName[self::CONTAINER]['server_id']);
        $this->assertSame('vps-jakarta-2', $byName[self::CONTAINER]['server_name']);
        // Container milik app lain tetap tampil untuk admin (seperti jalur lokal).
        $this->assertFalse($byName['asing-db-1']['owned']);
        $this->assertSame(['GET /database/containers'], $handler->paths());
        $this->assertSame([], $docker->inspected, 'deteksi lokal tidak boleh dijalankan untuk server agent');
    }

    // ==================================================================
    // Higienitas pesan galat jalur agent
    // ==================================================================

    /**
     * Galat transport `AgentClient` memuat `base_url` server; controller wajib
     * meredaksinya lewat satu pintu (`AgentError::sanitizeForServer()`) sehingga
     * halaman tidak pernah memuat endpoint internal/token.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentErrorMessagesNeverLeakTokenOrBaseUrl(): void
    {
        $handler = new DatabaseIndexFakeHandler(); // antrean kosong ⇒ galat transport
        $docker = new DatabaseIndexLocalDocker();
        $docker->explode = true;

        $controller = $this->controller($handler, $docker);
        $controller->index($this->get('/database'));

        $engineError = (string) ($controller->rendered[0]['vars']['engineError'] ?? '');
        $this->assertStringContainsString('vps-jakarta-2', $engineError, 'nama server tetap membantu diagnosis');
        foreach (['tok-agent-uji-f3d-999', 'agent.test', 'https://'] as $secret) {
            $this->assertStringNotContainsString($secret, $engineError, 'rahasia/endpoint internal bocor: ' . $secret);
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(DatabaseIndexFakeHandler $handler, DockerClient $docker): DatabaseIndexScopeController
    {
        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $apps->create([
            'id' => 'app-demo',
            'name' => 'demo',
            'server_id' => 'srv-agent',
            'owner_id' => 'u-admin',
            'containers' => [['service_name' => 'db', 'container_name' => self::CONTAINER]],
        ]);

        $store = new ServerStore($this->tmp . '/servers.sqlite');
        $store->create([
            'id' => 'srv-agent',
            'name' => 'vps-jakarta-2',
            'kind' => 'agent',
            'base_url' => 'https://agent.test:8443',
            'token' => 'tok-agent-uji-f3d-999',
            'tls_cert_pem' => self::selfSignedPem(),
            'enabled' => true,
        ]);

        $resolver = new ServerResolver($store);
        $server = $resolver->resolve('srv-agent');
        $agent = new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'))
        );

        return new DatabaseIndexScopeController($apps, $resolver, $docker, $agent);
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1
Host: localhost
Accept: text/html

");
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path => $info) {
            $path = (string) $path;
            if ($info->isDir() && !is_link($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function selfSignedPem(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $out = '';
        openssl_x509_export($cert, $out);
        $pem = $out;

        return $pem;
    }

    private const STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return $GLOBALS['RAMES_TEST_USER'] ?? null;
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                    $GLOBALS['RAMES_FLASH'][] = ['type' => $type, 'message' => $message];
                }
            }
        }
        PHP;
}
