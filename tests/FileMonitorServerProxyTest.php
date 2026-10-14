<?php
declare(strict_types=1);

namespace Tests;

use app\controller\FileController;
use app\controller\MonitorController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Files\ContainerFiles;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

// Helper webman (`json()`/`response()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Handler Guzzle tiruan (tanpa jaringan): merekam method/path/query/header/body
 * setiap panggilan lalu melayani respons dari antrean.
 */
final class FileMonitorProxyFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string,headers:array<string,string>}> */
    public array $calls = [];

    /** @var list<Psr7Response|\Throwable> */
    private array $queue = [];

    public function replyJson(array $payload, int $status = 200): self
    {
        return $this->reply($status, (string) json_encode($payload));
    }

    public function reply(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new Psr7Response($status, $headers + ['Content-Type' => 'application/json'], $body);

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

    /** @return array<string,mixed> */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->call($index)['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,string> */
    public function query(int $index): array
    {
        parse_str($this->call($index)['query'], $parsed);

        return array_map('strval', $parsed);
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('FileMonitorProxyFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
            'headers' => $headers,
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * Koneksi TCP tiruan (tanpa socket/event loop): merekam potongan `Chunk` yang
 * dikirim controller sehingga jalur streaming unduhan dapat diuji apa adanya.
 */
final class FileMonitorProxyFakeConnection extends TcpConnection
{
    /** @var list<mixed> */
    public array $sent = [];

    public int $closeCalls = 0;

    private bool $established = true;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function getStatus(bool $rawOutput = true): int|string
    {
        return $this->established ? self::STATUS_ESTABLISHED : 4;
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        $this->sent[] = $sendBuffer;

        return true;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closeCalls++;
        $this->established = false;
    }

    /** Tirukan klien yang memutus koneksi (onClose dipanggil Workerman). */
    public function dropClient(): void
    {
        $this->established = false;
        $callback = $this->onClose;
        if ($callback !== null) {
            $callback($this);
        }
    }

    /** Byte yang diterima klien (framing chunked dibuang). */
    public function payload(): string
    {
        $out = '';
        foreach ($this->sent as $item) {
            $raw = (string) $item;
            $pos = strpos($raw, "\r\n");
            if ($pos === false) {
                continue;
            }
            $out .= substr($raw, $pos + 2, -2);
        }

        return $out;
    }
}

/**
 * `DockerClient` tiruan host **control plane**: merekam panggilan Engine lokal
 * (regresi jalur lokal) dan gagal keras bila app server agent menyentuhnya.
 */
final class FileMonitorProxyDocker extends DockerClient
{
    public int $listCalls = 0;

    public int $overviewCalls = 0;

    /** true ⇒ sentuhan apa pun ke daemon lokal adalah kegagalan uji. */
    public bool $explode = false;

    /** @var array<int,array> */
    public array $containers = [];

    /** @var array<string,array> */
    public array $overview = [];

    /** Sengaja tidak memanggil konstruktor induk (tanpa Guzzle/socket). */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        $this->listCalls++;
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }

        return $this->containers;
    }

    public function containersOverview(array $ids, int $timeout = 20): array
    {
        $this->overviewCalls++;
        if ($this->explode) {
            throw new RuntimeException('DAEMON LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }

        return $this->overview;
    }
}

/**
 * `ContainerFiles` tiruan host control plane: merekam panggilan dan gagal keras
 * bila jalur agent menyentuhnya.
 */
final class FileMonitorProxyLocalFiles extends ContainerFiles
{
    /** @var list<string> */
    public array $calls = [];

    public bool $explode = false;

    public bool $running = true;

    /** @var array<string,mixed> */
    public array $listResult = ['path' => '/', 'parent' => null, 'truncated' => false, 'entries' => []];

    /** @var list<string> */
    public array $scheduled = [];

    /** Sengaja tidak memanggil konstruktor induk (tanpa Docker). */
    public function __construct()
    {
    }

    public function assertRunning(string $container): void
    {
        $this->guard();
        $this->calls[] = 'assertRunning';
        if (!$this->running) {
            throw new \app\library\Files\FileError('Container sedang tidak berjalan — jalankan app dulu.', 409);
        }
    }

    public function list(string $container, string $path): array
    {
        $this->guard();
        $this->calls[] = 'list';

        return $this->listResult;
    }

    public function downloadToTemp(string $container, string $path): array
    {
        $this->guard();
        $this->calls[] = 'downloadToTemp';

        $dir = sys_get_temp_dir() . '/rames-local-dl-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $file = $dir . '/' . basename($path);
        file_put_contents($file, 'isi-lokal');

        return ['dir' => $dir, 'path' => $file, 'name' => basename($path)];
    }

    public function scheduleCleanup(string $dir, int $delaySeconds = 300): void
    {
        $this->scheduled[] = $dir;
    }

    private function guard(): void
    {
        if ($this->explode) {
            throw new RuntimeException('FILE MANAGER LOKAL TERSENTUH untuk app server agent (uji gagal).');
        }
    }
}

/** `FileController` dengan seam uji (tanpa `apps/`, tanpa daemon lokal nyata). */
final class FileMonitorProxyFileController extends FileController
{
    /** @var list<array{container:string,action:string}> */
    public array $audits = [];

    public function __construct(
        private ?AgentDeployer $deployer,
        private ContainerFiles $files,
        private int $limit = AgentDeployer::MAX_TRANSFER_BYTES,
    ) {
    }

    protected function requireApp(string $id): array
    {
        return self::app();
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->deployer;
    }

    protected function containerFiles(): ContainerFiles
    {
        return $this->files;
    }

    protected function transferLimit(): int
    {
        return $this->limit;
    }

    protected function audit(array $app, string $container, string $action): void
    {
        $this->audits[] = ['container' => $container, 'action' => $action];
    }

    /** @return array<string,mixed> */
    public static function app(): array
    {
        return [
            'id' => 'app-a1',
            'name' => 'demo',
            'server_id' => 'srv-agent',
            'primary_service' => 'web',
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'demo-web-1'],
                ['service_name' => 'db', 'container_name' => 'demo-db-1'],
            ],
        ];
    }
}

/** `MonitorController` dengan seam uji (tanpa session, `apps/`, `/proc` nyata). */
final class FileMonitorProxyMonitorController extends MonitorController
{
    /**
     * @param array<int,array>          $apps     app yang "boleh dilihat" user
     * @param array<string,AgentDeployer> $agents  deployer agent per server id
     */
    public function __construct(
        private array $apps,
        private ServerResolver $resolver,
        private DockerClient $docker,
        private array $agents,
        private bool $admin = true,
    ) {
    }

    protected function isAdmin(): bool
    {
        return $this->admin;
    }

    protected function visibleApps(): array
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

    protected function agentDeployerFor(array $server): ?AgentDeployer
    {
        return $this->agents[(string) ($server['id'] ?? '')] ?? null;
    }
}

/**
 * Multi-server F3b/F3c (plan §5): **file manager** & **monitoring** app di server
 * `kind=agent` diambil dari agent, sedangkan app server lokal berperilaku persis
 * seperti sebelumnya.
 *
 * Kontrak yang dikunci:
 *  1. app server agent ⇒ seluruh operasi berkas & metrik berasal dari agent;
 *     daemon/`/proc` lokal **tidak** tersentuh (fake-nya gagal keras bila
 *     dipanggil) — plan §3 butir 4;
 *  2. app server lokal ⇒ perilaku lama (uji regresi eksplisit);
 *  3. path/nama tak aman & container tak dikenal ditolak **sebelum** panggilan
 *     apa pun (control plane), dan tetap divalidasi ulang di agent;
 *  4. envelope galat domain agent diteruskan apa adanya (409/413/415/404) — UI
 *     tidak pernah menerima unduhan kosong atau pesan generik;
 *  5. batas transfer ditegakkan di kedua sisi (agent menolak sebelum streaming,
 *     control plane memotong aliran sebagai jaring pengaman kedua);
 *  6. `/api/monitor/overview` mengumpulkan semua server; server agent yang mati
 *     ⇒ entri `offline` dan halaman tetap 200 dengan data server lain;
 *  7. penyaringan hak akses (`AppAccess::visible()`) tetap di control plane.
 *
 * Semua uji tanpa jaringan, tanpa Docker, tanpa `apps/`/`runtime/` nyata.
 */
class FileMonitorServerProxyTest extends TestCase
{
    private const APP_ID = 'app-a1';

    private const TOKEN = 'tok-uji-file-monitor-1a2b3c';

    private string $tmp;

    /** Loop event workerman untuk uji unduhan streaming. */
    private Select $loop;

    /** @var list<FileMonitorProxyFakeConnection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-file-monitor-proxy-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/tls', 0777, true);

        $this->loop = new Select();
        Timer::init($this->loop);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->dropClient();
        }
        $this->connections = [];
        $this->removeTree($this->tmp);
    }

    // ==================================================================
    // (1)/(3) File manager — app server agent
    // ==================================================================

    public function testAgentAppFileListIsProxiedAndTheLocalDaemonIsNeverTouched(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => [
            'path' => '/srv',
            'parent' => '/',
            'truncated' => false,
            'entries' => [['name' => 'a.txt', 'type' => 'file', 'size' => 3]],
        ]]);

        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local);

        $response = $controller->list(
            $this->get('/api/apps/' . self::APP_ID . '/files?container=demo-web-1&path=/srv'),
            self::APP_ID
        );
        $body = $this->payload($response);

        $this->assertSame(0, $body['code']);
        $this->assertSame('/srv', $body['data']['path']);
        $this->assertSame('a.txt', $body['data']['entries'][0]['name']);

        $this->assertSame('GET /files/list', $handler->call(0)['method'] . ' ' . $handler->call(0)['path']);
        $query = $handler->query(0);
        $this->assertSame(self::APP_ID, $query['app_id']);
        $this->assertSame('demo-web-1', $query['container']);
        $this->assertSame('/srv', $query['path']);
        $this->assertStringStartsWith('Bearer ', $handler->call(0)['headers']['authorization']);
    }

    public function testAgentAppMutationsAreForwardedWithNormalizedFields(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $handler
            ->replyJson(['code' => 0])
            ->replyJson(['code' => 0])
            ->replyJson(['code' => 0])
            ->replyJson(['code' => 0, 'data' => ['from' => '/a.txt', 'to' => '/dst/a.txt']])
            ->replyJson(['code' => 0, 'data' => ['extracted' => 3]])
            ->replyJson(['code' => 0]);

        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local);
        $base = '/api/apps/' . self::APP_ID . '/files/';

        $this->assertSame(0, $this->payload($controller->write(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv/a.txt', 'text' => 'halo'], $base . 'write'),
            self::APP_ID
        ))['code']);
        $this->assertSame('/files/write', $handler->call(0)['path']);
        $this->assertSame(['app_id' => self::APP_ID, 'container' => 'demo-web-1', 'path' => '/srv/a.txt', 'text' => 'halo'], $handler->jsonBody(0));

        $this->assertSame(0, $this->payload($controller->mkdir(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv', 'name' => 'baru'], $base . 'mkdir'),
            self::APP_ID
        ))['code']);
        $this->assertSame('/srv/baru', $handler->jsonBody(1)['target'], 'mkdir memakai kontrak UI (path + name)');

        $this->assertSame(0, $this->payload($controller->rename(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv/a.txt', 'name' => 'b.txt'], $base . 'rename'),
            self::APP_ID
        ))['code']);
        $this->assertSame(['app_id' => self::APP_ID, 'container' => 'demo-web-1', 'from' => '/srv/a.txt', 'to' => '/srv/b.txt'], $handler->jsonBody(2));

        $moved = $this->payload($controller->move(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv/a.txt', 'dest' => '/dst'], $base . 'move'),
            self::APP_ID
        ));
        $this->assertSame(0, $moved['code']);
        $this->assertSame('/dst/a.txt', $moved['data']['to']);
        $this->assertSame(
            ['app_id' => self::APP_ID, 'container' => 'demo-web-1', 'path' => '/srv/a.txt', 'dest' => '/dst', 'name' => 'a.txt'],
            $handler->jsonBody(3),
            'rencana pindah divalidasi & dinormalkan di control plane'
        );

        $this->assertSame(0, $this->payload($controller->extract(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv', 'name' => 'a.zip'], $base . 'extract'),
            self::APP_ID
        ))['code']);
        $this->assertSame(['app_id' => self::APP_ID, 'container' => 'demo-web-1', 'dir' => '/srv', 'name' => 'a.zip'], $handler->jsonBody(4));

        $this->assertSame(0, $this->payload($controller->delete(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv/a.txt'], $base . 'delete'),
            self::APP_ID
        ))['code']);
        $this->assertSame('/files/delete', $handler->call(5)['path']);

        $this->assertCount(6, $controller->audits, 'audit ditulis di control plane (identitas pelaku di sini)');
        $this->assertSame('demo-web-1', $controller->audits[0]['container']);
        $this->assertStringContainsString('di server agent', $controller->audits[0]['action']);
    }

    public function testAgentUploadStreamsTheFileAndSignsTheBodyHash(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => ['name' => 'a.txt', 'size' => 9]]);

        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local);

        $content = 'isi-biner';
        $response = $controller->upload(
            $this->multipart(['container' => 'demo-web-1', 'path' => '/srv'], 'files[]', 'a.txt', $content),
            self::APP_ID
        );
        $body = $this->payload($response);

        $this->assertSame(0, $body['code']);
        $this->assertSame('a.txt', $body['data']['uploaded'][0]['name']);

        $call = $handler->call(0);
        $this->assertSame('POST /files/upload', $call['method'] . ' ' . $call['path']);
        $this->assertSame($content, $call['body'], 'isi berkas di-stream apa adanya sebagai body');
        $this->assertSame('application/octet-stream', $call['headers']['content-type']);
        $query = $handler->query(0);
        $this->assertSame(self::APP_ID, $query['app_id']);
        $this->assertSame('/srv', $query['dir']);
        $this->assertSame('a.txt', $query['name']);

        // Tanda tangan harus cocok dengan `sha256(body)` — middleware agent
        // memverifikasi kanonik yang sama (token tidak pernah ikut ke UI).
        $expected = hash_hmac('sha256', AgentClient::canonical(
            $call['headers']['x-rames-timestamp'],
            'POST',
            '/files/upload',
            $content
        ), self::TOKEN);
        $this->assertSame($expected, $call['headers']['x-rames-signature']);
    }

    // ==================================================================
    // (4)/(6) Galat domain & batas transfer
    // ==================================================================

    public function testAgentDomainErrorsArePassedThroughWithTheSameCode(): void
    {
        $cases = [
            [409, 'Container sedang tidak berjalan — jalankan app dulu.'],
            [413, 'Berkas melebihi batas 2 MiB untuk diedit sebagai teks.'],
            [415, 'Berkas biner tidak dapat diedit sebagai teks.'],
            [400, 'Arsip memuat entri tidak aman: ../evil.txt'],
        ];

        foreach ($cases as [$code, $message]) {
            $handler = new FileMonitorProxyFakeHandler();
            $handler->replyJson(['code' => $code, 'msg' => $message]);

            $local = new FileMonitorProxyLocalFiles();
            $local->explode = true;
            $controller = $this->fileController($handler, $local);

            $response = $controller->extract(
                $this->post(['container' => 'demo-web-1', 'path' => '/srv', 'name' => 'evil.zip'], '/apps/' . self::APP_ID . '/files/extract'),
                self::APP_ID
            );
            $body = $this->payload($response);

            $this->assertSame(200, $response->getStatusCode(), 'envelope UI selalu HTTP 200 seperti jalur lokal');
            $this->assertSame($code, $body['code'], 'kode domain agent diteruskan: ' . $code);
            $this->assertSame($message, $body['msg']);
        }
    }

    public function testAgentDownloadStreamsBytesToTheClient(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $handler->reply(200, 'isi-berkas-agent', ['Content-Type' => 'application/octet-stream']);

        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local);

        $request = $this->get('/apps/' . self::APP_ID . '/files/download?container=demo-web-1&path=/srv/a.txt');
        $connection = $this->connection($request);

        $response = $controller->download($request, self::APP_ID);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('chunked', $response->getHeader('Transfer-Encoding'));
        $this->assertStringContainsString('a.txt', (string) $response->getHeader('Content-Disposition'));
        $this->assertSame([], $connection->sent, 'byte tidak dikirim sebelum respons kembali');

        $this->pumpLoop();
        $this->assertSame('isi-berkas-agent', $connection->payload());
        $this->assertSame(1, $connection->closeCalls, 'aliran chunked ditutup di EOF');
        $this->assertSame(['GET /files/download'], $handler->paths());
        $this->assertCount(1, $controller->audits);
    }

    public function testAgentDownloadDomainErrorNeverStreamsBytes(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $handler->replyJson(['code' => 409, 'msg' => 'Container sedang tidak berjalan — jalankan app dulu.'], 409);

        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local);

        $request = $this->get('/apps/' . self::APP_ID . '/files/download?container=demo-web-1&path=/srv/a.txt');
        $connection = $this->connection($request);

        $body = $this->payload($controller->download($request, self::APP_ID));

        $this->assertSame(409, $body['code']);
        $this->assertSame([], $connection->sent, 'tidak ada byte berkas/JSON yang mengalir sebagai unduhan');
        $this->assertSame(0, $connection->closeCalls);
        $this->assertSame([], $controller->audits);
    }

    public function testControlPlaneCapsTheStreamedDownloadAsASecondSafetyNet(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $handler->reply(200, str_repeat('x', 4096), ['Content-Type' => 'application/octet-stream']);

        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local, 1024);

        $request = $this->get('/apps/' . self::APP_ID . '/files/download?container=demo-web-1&path=/srv/big.bin');
        $connection = $this->connection($request);

        $this->assertInstanceOf(Response::class, $controller->download($request, self::APP_ID));
        $this->pumpLoop(0.6);

        $this->assertLessThanOrEqual(1024, strlen($connection->payload()), 'aliran dipotong di plafon control plane');
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testListingEntriesAreCappedOnTheControlPlane(): void
    {
        $entries = [];
        for ($i = 0; $i < ContainerFiles::MAX_LIST_ENTRIES + 10; $i++) {
            $entries[] = ['name' => 'f' . $i, 'type' => 'file', 'size' => 1];
        }

        $handler = new FileMonitorProxyFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => ['path' => '/big', 'parent' => '/', 'truncated' => false, 'entries' => $entries]]);

        $controller = $this->fileController($handler, new FileMonitorProxyLocalFiles());
        $body = $this->payload($controller->list(
            $this->get('/api/apps/' . self::APP_ID . '/files?container=demo-web-1&path=/big'),
            self::APP_ID
        ));

        $this->assertCount(ContainerFiles::MAX_LIST_ENTRIES, $body['data']['entries']);
        $this->assertTrue($body['data']['truncated']);
    }

    // ==================================================================
    // (3) Validasi di control plane
    // ==================================================================

    public function testUnsafePathsNamesAndContainersAreRejectedBeforeAnyAgentCall(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $local = new FileMonitorProxyLocalFiles();
        $local->explode = true;
        $controller = $this->fileController($handler, $local);

        // path relatif / keluar akar
        foreach (['relatif.txt', '/../../etc/passwd', "/a\0b"] as $path) {
            $body = $this->payload($controller->list(
                $this->get('/api/apps/' . self::APP_ID . '/files?container=demo-web-1&path=' . rawurlencode($path)),
                self::APP_ID
            ));
            $this->assertSame(400, $body['code'], 'path harus ditolak: ' . $path);
        }

        // hapus akar filesystem
        $this->assertSame(400, $this->payload($controller->delete(
            $this->post(['container' => 'demo-web-1', 'path' => '/'], '/apps/' . self::APP_ID . '/files/delete'),
            self::APP_ID
        ))['code']);

        // nama folder diawali "-"
        $this->assertSame(400, $this->payload($controller->mkdir(
            $this->post(['container' => 'demo-web-1', 'path' => '/srv', 'name' => '-flag'], '/apps/' . self::APP_ID . '/files/mkdir'),
            self::APP_ID
        ))['code']);

        // container di luar app (dan bentuk nama tak sah)
        foreach (['container-lain', '../../etc', 'nama container dengan spasi'] as $container) {
            $body = $this->payload($controller->list(
                $this->get('/api/apps/' . self::APP_ID . '/files?container=' . rawurlencode($container)),
                self::APP_ID
            ));
            $this->assertSame(400, $body['code'], 'container harus ditolak: ' . $container);
        }

        $this->assertSame(0, $handler->count(), 'validasi control plane mendahului HTTP ke agent');
        $this->assertSame([], $local->calls);
    }

    // ==================================================================
    // (2) File manager — app server lokal (regresi)
    // ==================================================================

    public function testLocalAppFileListKeepsTheOldBehaviour(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $local = new FileMonitorProxyLocalFiles();
        $local->listResult = [
            'path' => '/srv',
            'parent' => '/',
            'truncated' => false,
            'entries' => [['name' => 'lokal.txt', 'type' => 'file', 'size' => 7]],
        ];
        $controller = new FileMonitorProxyFileController(null, $local);

        $body = $this->payload($controller->list(
            $this->get('/api/apps/' . self::APP_ID . '/files?container=demo-db-1&path=/srv'),
            self::APP_ID
        ));

        $this->assertSame(0, $body['code']);
        $this->assertSame('lokal.txt', $body['data']['entries'][0]['name']);
        $this->assertSame(['assertRunning', 'list'], $local->calls);
        $this->assertSame(0, $handler->count(), 'app server lokal tidak boleh memanggil agent');
    }

    public function testLocalAppDownloadStillUsesTheLocalLibrary(): void
    {
        $handler = new FileMonitorProxyFakeHandler();
        $local = new FileMonitorProxyLocalFiles();
        $controller = new FileMonitorProxyFileController(null, $local);

        $response = $controller->download(
            $this->get('/apps/' . self::APP_ID . '/files/download?container=demo-web-1&path=/srv/a.txt'),
            self::APP_ID
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNotNull($response->file, 'jalur lokal tetap response()->download()');
        $this->assertSame(['assertRunning', 'downloadToTemp'], $local->calls);
        $this->assertCount(1, $local->scheduled);
        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // (6)/(7) Monitoring per server
    // ==================================================================

    public function testOverviewAggregatesAllServersAndKeepsTheLocalShape(): void
    {
        [$agentOk, $handlerOk] = $this->agent('srv-a', 'agent-a');
        $handlerOk
            ->replyJson(['code' => 0, 'data' => ['host' => ['available' => true, 'proc_path' => '/proc-agent-a', 'cpu_count' => 4]]])
            ->replyJson(['code' => 0, 'data' => [
                'containers' => [
                    $this->row('demo-web-1', 'demo', self::APP_ID, true),
                    $this->row('lain-web-1', 'lain', 'app-lain', true),
                    $this->row('redis', '', null, false),
                ],
                'totals' => ['cpu_percent' => 1.0],
                'error' => null,
            ]]);

        $handlerDown = new FileMonitorProxyFakeHandler();
        $agentDown = $this->deployer('srv-b', 'agent-b', $handlerDown);

        $docker = new FileMonitorProxyDocker();
        $docker->containers = [
            $this->container('local-demo-1', 'demo-web-1', ['com.docker.compose.project' => 'demo', 'com.docker.compose.service' => 'web']),
        ];

        $controller = new FileMonitorProxyMonitorController(
            [['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'local']],
            $this->resolver([
                ['id' => 'srv-a', 'name' => 'agent-a', 'kind' => 'agent', 'base_url' => 'https://agent-a.test', 'token' => self::TOKEN, 'tls_cert_pem' => self::selfSignedPem()],
                ['id' => 'srv-b', 'name' => 'agent-b', 'kind' => 'agent', 'base_url' => 'https://agent-b.test', 'token' => self::TOKEN, 'tls_cert_pem' => self::selfSignedPem()],
            ]),
            $docker,
            ['srv-a' => $agentOk, 'srv-b' => $agentDown],
        );

        $response = $controller->overview($this->get('/api/monitor/overview'));
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);

        // Bentuk lama tidak berubah: kunci atas tetap milik server lokal.
        foreach (['host', 'containers', 'totals', 'error', 'scope', 'at'] as $key) {
            $this->assertArrayHasKey($key, $body['data']);
        }
        $this->assertSame('all', $body['data']['scope']);
        $this->assertSame('demo-web-1', $body['data']['containers'][0]['name'], 'container lokal tetap muncul di kunci lama');

        $servers = $body['data']['servers'];
        $this->assertCount(3, $servers);
        $this->assertSame('local', $servers[0]['kind']);
        $this->assertSame('online', $servers[0]['status']);

        $agent = $servers[1];
        $this->assertSame('srv-a', $agent['id']);
        $this->assertSame('online', $agent['status']);
        $this->assertSame('/proc-agent-a', $agent['host']['proc_path'], 'metrik host dari server agent, bukan /proc lokal');
        // Penyaringan sisi server: app "lain" tidak boleh dilihat user ini;
        // container eksternal (tanpa project) boleh untuk admin.
        $this->assertSame(['demo-web-1', 'redis'], array_column($agent['containers'], 'name'));
        $this->assertIsArray($agent['totals']);

        $down = $servers[2];
        $this->assertSame('srv-b', $down['id']);
        $this->assertSame('offline', $down['status']);
        $this->assertNull($down['host']);
        $this->assertSame([], $down['containers']);
        $this->assertNotSame('', (string) $down['error'], 'status offline disertai alasan');

        // Engine lokal hanya dipakai untuk server lokal.
        $this->assertSame(1, $docker->listCalls);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($body));
    }

    public function testRemoteContainersAreFilteredServerSideForNonAdmin(): void
    {
        [$agent, $handler] = $this->agent('srv-a', 'agent-a');
        $handler
            ->replyJson(['code' => 0, 'data' => ['host' => ['available' => true]]])
            ->replyJson(['code' => 0, 'data' => [
                'containers' => [
                    $this->row('demo-web-1', 'demo', self::APP_ID, true),
                    $this->row('lain-web-1', 'lain', 'app-lain', true),
                    $this->row('redis', '', null, false),
                ],
                'totals' => [],
                'error' => null,
            ]]);

        $docker = new FileMonitorProxyDocker();
        $controller = new FileMonitorProxyMonitorController(
            [['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'local']],
            $this->resolver([
                ['id' => 'srv-a', 'name' => 'agent-a', 'kind' => 'agent', 'base_url' => 'https://agent-a.test', 'token' => self::TOKEN, 'tls_cert_pem' => self::selfSignedPem()],
            ]),
            $docker,
            ['srv-a' => $agent],
            false
        );

        $body = $this->payload($controller->overview($this->get('/api/monitor/overview')));

        $this->assertSame('accessible', $body['data']['scope']);
        $agentEntry = $body['data']['servers'][1];
        $this->assertSame(['demo-web-1'], array_column($agentEntry['containers'], 'name'), 'member hanya melihat app yang boleh diakses');
        $this->assertSame(1, $agentEntry['totals']['containers'], 'total dihitung ulang dari baris yang lolos penyaringan');
    }

    public function testLocalOnlyOverviewIsUnchanged(): void
    {
        $docker = new FileMonitorProxyDocker();
        $docker->containers = [
            $this->container('local-demo-1', 'demo-web-1', ['com.docker.compose.project' => 'demo']),
            $this->container('local-ext-1', 'redis', []),
        ];

        $controller = new FileMonitorProxyMonitorController(
            [['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'local']],
            $this->resolver([]),
            $docker,
            []
        );

        $body = $this->payload($controller->overview($this->get('/api/monitor/overview')));

        $this->assertSame(0, $body['code']);
        $this->assertSame(1, $docker->listCalls);
        $this->assertSame(['demo-web-1', 'redis'], array_column($body['data']['containers'], 'name'));
        $this->assertCount(1, $body['data']['servers'], 'tanpa server agent hanya entri lokal');
        $this->assertSame('local', $body['data']['servers'][0]['kind']);
    }

    public function testHostPollingEndpointStaysLocalOnly(): void
    {
        $docker = new FileMonitorProxyDocker();
        $controller = new FileMonitorProxyMonitorController([], $this->resolver([]), $docker, []);

        $body = $this->payload($controller->host($this->get('/api/monitor/host')));

        $this->assertSame(0, $body['code']);
        $this->assertSame(['host', 'at'], array_keys($body['data']), 'polling host tetap ringan (tanpa agent)');
        $this->assertSame(0, $docker->listCalls);
    }

    // ==================================================================
    // Helper
    // ==================================================================



    private function fileController(
        FileMonitorProxyFakeHandler $handler,
        ContainerFiles $local,
        int $limit = AgentDeployer::MAX_TRANSFER_BYTES
    ): FileMonitorProxyFileController {
        return new FileMonitorProxyFileController($this->agentDeployer($handler), $local, $limit);
    }

    private function agentDeployer(FileMonitorProxyFakeHandler $handler): AgentDeployer
    {
        return $this->deployer('srv-agent', 'agent-1', $handler);
    }

    private function deployer(string $id, string $name, FileMonitorProxyFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => $id,
            'name' => $name,
            'kind' => 'agent',
            'base_url' => 'https://' . $id . '.test',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::selfSignedPem(),
        ];

        return new AgentDeployer($server, new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls')));
    }

    /**
     * Deployer agent + handler yang siap menerima dua panggilan monitoring.
     *
     * @return array{0:AgentDeployer,1:FileMonitorProxyFakeHandler}
     */
    private function agent(string $id, string $name): array
    {
        $handler = new FileMonitorProxyFakeHandler();

        return [$this->deployer($id, $name, $handler), $handler];
    }

    /**
     * @param array<int,array<string,mixed>> $servers
     */
    private function resolver(array $servers): ServerResolver
    {
        $store = new ServerStore($this->tmp . '/servers-' . bin2hex(random_bytes(4)) . '.sqlite');
        foreach ($servers as $server) {
            $store->create($server + ['enabled' => true]);
        }

        return new ServerResolver($store);
    }

    /** @return array<string,mixed> */
    private function row(string $name, string $project, ?string $appId, bool $managed): array
    {
        return [
            'id' => substr(md5($name), 0, 12),
            'name' => $name,
            'image' => 'nginx:alpine',
            'state' => 'running',
            'project' => $project,
            'service' => 'web',
            'managed' => $managed,
            'app_id' => $appId,
            'app_name' => $appId === null ? null : $project,
            'cpu_percent' => 1.5,
            'mem_used' => 1024,
            'mem_limit' => 2048,
            'mem_percent' => 50.0,
            'error' => null,
        ];
    }

    /** @param array<string,string> $labels */
    private function container(string $id, string $name, array $labels): array
    {
        return [
            'Id' => $id,
            'Names' => ['/' . $name],
            'Image' => 'nginx:alpine',
            'State' => 'running',
            'Labels' => $labels,
        ];
    }

    private function connection(Request $request): FileMonitorProxyFakeConnection
    {
        $connection = new FileMonitorProxyFakeConnection();
        $request->connection = $connection;
        $this->connections[] = $connection;

        return $connection;
    }

    /** Jalankan event loop sebentar supaya tick `Timer` (pemompa aliran) jalan. */
    private function pumpLoop(float $seconds = 0.4): void
    {
        $stop = Timer::add($seconds, function (): void {
            $this->loop->stop();
        }, [], false);
        $this->loop->run();
        Timer::del($stop);
    }

    /** @param array<string,string> $fields */
    private function post(array $fields, string $path): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * Permintaan multipart dengan satu berkas (`files[]`) — pola unggahan UI.
     *
     * @param array<string,string> $fields
     */
    private function multipart(array $fields, string $field, string $filename, string $content): Request
    {
        $boundary = '----RamesFileMonitorBoundary' . bin2hex(random_bytes(4));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$content}\r\n--{$boundary}--\r\n";

        return new Request(
            "POST /apps/" . self::APP_ID . "/files/upload HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n");
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
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
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $out = '';
        openssl_x509_export($cert, $out);
        $pem = $out;

        return $pem;
    }

    private function removeTree(string $dir): void
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
}
