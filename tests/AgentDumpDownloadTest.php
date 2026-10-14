<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentJobController;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Backup\BackupJobRunner;
use app\library\Backup\DumpRunner;
use app\library\Backup\DumpStaging;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/** Handler Guzzle tiruan yang melayani respons **mentah** (bukan JSON). */
final class AgentDumpDownloadFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string}> */
    public array $calls = [];

    /** @var list<Psr7Response|\Throwable> */
    private array $queue = [];

    /**
     * @param array<string,string> $headers
     */
    public function reply(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new Psr7Response($status, $headers, $body);

        return $this;
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AgentDumpDownloadFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/** `DumpRunner` tiruan: menulis berkas ke staging temp & melewati Engine. */
final class AgentDumpDownloadFakeDumper extends DumpRunner
{
    public function __construct(private readonly string $root)
    {
    }

    public function stagingRoot(): string
    {
        return $this->root;
    }

    public function cleanup(?string $dir = null): void
    {
    }

    public function dump(string $project, array $app, string $container, string $timestamp): array
    {
        $dir = rtrim($this->root, '/') . '/' . $project . '/' . $timestamp;
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('gagal membuat staging tiruan');
        }
        $file = $dir . '/demo.sql';
        file_put_contents($file, 'DUMP-BARU');

        return [
            'dir' => $dir,
            'file' => $file,
            'files' => [$file],
            'database' => 'demo',
            'bytes' => 9,
            'container' => $container,
        ];
    }
}

/** `DockerClient` tiruan: satu volume compose + satu container DB mysql. */
final class AgentDumpDownloadFakeDocker extends DockerClient
{
    public function __construct()
    {
    }

    public function listVolumes(array $filters = []): array
    {
        return [['Name' => 'demo_data', 'Labels' => ['com.docker.compose.project' => 'demo']]];
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return [[
            'Id' => 'c1',
            'Names' => ['/demo-db-1'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'demo'],
            'Mounts' => [['Type' => 'volume', 'Name' => 'demo_data']],
        ]];
    }

    public function inspectContainer(string $name): array
    {
        return ['Config' => ['Image' => 'mysql:8', 'Env' => []]];
    }
}

/**
 * Hasil `db_dump` di server agent (rencana multi-server F4 sisa #2) — kontrak:
 *  1. staging di server yang menjalankan dump **tidak menumpuk tanpa batas**:
 *     retensi dipanggil sesudah dump sukses dan selalu mempertahankan yang
 *     terbaru;
 *  2. hasil dump dapat **diunduh bertahap** dari server itu (`GET /backups/dump`)
 *     dengan offset + batas ukuran per panggilan, kontrol plane tidak pernah
 *     membaca staging server agent secara langsung;
 *  3. pembacaan berkas selalu di dalam staging & berekstensi `.sql` (path dari
 *     klien tidak dipercaya), dan satu unduhan dibatasi plafon ukuran;
 *  4. payload/jalur tidak pernah memuat kredensial.
 *
 * Semua uji: `/tmp`, Docker/HTTP tiruan, tanpa restic/jaringan nyata.
 */
class AgentDumpDownloadTest extends TestCase
{
    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-dump-dl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/staging', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $this->tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) retensi staging
    // ==================================================================

    public function testRetentionKeepsNewestDumpsAndRemovesOldOnes(): void
    {
        $staging = new DumpStaging($this->tmp . '/staging');
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $ts) {
            $dir = $this->tmp . '/staging/demo/' . $ts;
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/demo.sql', str_repeat('x', 10));
        }

        $result = $staging->prune(1, 0);

        $this->assertSame(2, $result['removed'], 'semua dump lama di luar `keep` wajib dibersihkan');
        $this->assertSame(1, $result['kept']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertDirectoryDoesNotExist($this->tmp . '/staging/demo/20260101-000000');
        $this->assertDirectoryDoesNotExist($this->tmp . '/staging/demo/20260102-000000');
        $this->assertDirectoryExists($this->tmp . '/staging/demo/20260103-000000', 'dump terbaru selalu dipertahankan');
    }

    public function testLatestAndReadAreBoundedAndStayInsideStaging(): void
    {
        $dir = $this->tmp . '/staging/demo/20260101-000000';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/demo.sql', 'ABCDEFGHIJ');
        // Direktori lebih lama dengan berkas lain: `latest()` wajib memilih terbaru.
        $older = $this->tmp . '/staging/demo/20251231-235959';
        mkdir($older, 0777, true);
        file_put_contents($older . '/demo.sql', 'LAMA');

        $staging = new DumpStaging($this->tmp . '/staging');
        $latest = $staging->latest('demo');

        $this->assertNotNull($latest);
        $this->assertSame('demo.sql', $latest['name']);
        $this->assertSame(10, $latest['bytes']);
        $this->assertStringContainsString('20260101-000000', $latest['file']);

        $this->assertSame('ABCDE', $staging->read($latest['file'], 0, 5));
        $this->assertSame('FGHIJ', $staging->read($latest['file'], 5, 5));
        $this->assertSame('', $staging->read($latest['file'], 50, 5), 'offset di luar berkas ⇒ kosong');
        $this->assertSame('ABCDEFGHIJ', $staging->read($latest['file'], 0, DumpStaging::MAX_READ_BYTES * 4), 'limit dibatasi plafon');

        // Path di luar staging ditolak keras (path klien tidak pernah dipercaya).
        $outside = $this->tmp . '/luar.sql';
        file_put_contents($outside, 'RAHASIA');
        $this->expectException(RuntimeException::class);
        $staging->read($outside, 0, 5);
    }

    public function testDbDumpPrunesOldStagingDirectories(): void
    {
        Config::clear();
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'volume_backup_dump_keep' => 1,
                'volume_backup_dump_max_age_days' => 0,
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        SqliteFixture::apps($this->tmp . '/rames.sqlite', [[
            'id' => 'a1b2c3d4e5f60718',
            'name' => 'demo',
            'status' => 'running',
            'compose_files' => ['docker-compose.yml'],
            'containers' => [],
            'deploy_history' => [],
        ]]);

        $oldDir = $this->tmp . '/staging/demo/20250101-000000';
        mkdir($oldDir, 0777, true);
        file_put_contents($oldDir . '/demo.sql', 'LAMA');

        $docker = new AgentDumpDownloadFakeDocker();
        $runner = new BackupJobRunner(
            null,
            null,
            new AgentDumpDownloadFakeDumper($this->tmp . '/staging'),
            new AppStore($this->tmp . '/rames.sqlite'),
            $docker
        );

        $result = $runner->dbDump('demo_data', 'a1b2c3d4e5f60718');

        $this->assertSame(1, $result['pruned'], 'dump lama wajib dibersihkan setelah dump sukses');
        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertNotFalse(strpos((string) $result['message'], 'Retensi staging'));
    }

    // ==================================================================
    // (2) endpoint agent: unduhan bertahap
    // ==================================================================

    public function testAgentDumpEndpointServesChunksWithOffsetHeaders(): void
    {
        $dir = $this->tmp . '/staging/demo/20260101-000000';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/demo.sql', 'ABCDEFGHIJ');

        $controller = $this->controller();

        [$status, $headers, $raw] = $this->response($controller->backupDump(
            $this->request('GET', '/backups/dump?volume=demo_data&offset=0&limit=4')
        ));
        $this->assertSame(200, $status);
        $this->assertSame('ABCD', $raw);
        $this->assertSame('demo.sql', $headers['X-Rames-File']);
        $this->assertSame('4', $headers['X-Rames-Offset']);
        $this->assertSame('10', $headers['X-Rames-Size']);
        $this->assertSame('false', $headers['X-Rames-Eof']);

        [$status2, $headers2, $raw2] = $this->response($controller->backupDump(
            $this->request('GET', '/backups/dump?volume=demo_data&offset=8&limit=100')
        ));
        $this->assertSame(200, $status2);
        $this->assertSame('IJ', $raw2, 'potongan terakhir berhenti di akhir berkas');
        $this->assertSame('10', $headers2['X-Rames-Offset']);
        $this->assertSame('true', $headers2['X-Rames-Eof']);
    }

    public function testAgentDumpEndpointRejectsBadVolumeAndMissingDump(): void
    {
        $controller = $this->controller();

        $bad = $controller->backupDump($this->request('GET', '/backups/dump?volume=../etc'));
        $this->assertSame(400, $bad->getStatusCode());
        $this->assertSame('invalid_request', json_decode($bad->rawBody(), true)['reason']);

        $missing = $controller->backupDump($this->request('GET', '/backups/dump?volume=demo_data'));
        $this->assertSame(404, $missing->getStatusCode(), 'belum ada dump ⇒ 404 (bukan 500/sukses palsu)');
        $this->assertSame('backup_dump_failed', json_decode($missing->rawBody(), true)['reason']);
    }

    // ==================================================================
    // (3) control plane: unduh dari server agent (potongan, batas ukuran)
    // ==================================================================

    public function testDeployerDownloadsTheWholeDumpInBoundedChunks(): void
    {
        $handler = new AgentDumpDownloadFakeHandler();
        $handler
            ->reply(200, 'ABCD', ['X-Rames-File' => 'demo.sql', 'X-Rames-Offset' => '4', 'X-Rames-Size' => '10', 'X-Rames-Eof' => 'false'])
            ->reply(200, 'EFGHIJ', ['X-Rames-File' => 'demo.sql', 'X-Rames-Offset' => '10', 'X-Rames-Size' => '10', 'X-Rames-Eof' => 'true']);

        $dump = $this->deployer($handler)->downloadDump('demo_data');

        $this->assertSame('ABCDEFGHIJ', $dump['content']);
        $this->assertSame(10, $dump['bytes']);
        $this->assertSame('demo.sql', $dump['name']);
        $this->assertCount(2, $handler->calls, 'isi berkas dibaca bertahap, bukan satu respons raksasa');
        $this->assertSame('GET', $handler->calls[0]['method']);
        $this->assertSame('/backups/dump', $handler->calls[0]['path']);
        $this->assertStringContainsString('volume=demo_data', $handler->calls[0]['query']);
        $this->assertStringContainsString('offset=4', $handler->calls[1]['query'], 'offset lanjutan dari header agent');
    }

    public function testDeployerRefusesDumpsLargerThanTheDownloadLimit(): void
    {
        // Plafon dari konfigurasi server (`volume_backup_dump_download_max_bytes`)
        // — satu knob dengan jalur lokal; di sini dibuat kecil supaya batasnya
        // benar-benar teruji tanpa data raksasa.
        Config::clear();
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'volume_backup_dump_download_max_bytes' => 8,
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        $handler = new AgentDumpDownloadFakeHandler();
        $handler->reply(200, 'ABCDEF', ['X-Rames-File' => 'demo.sql', 'X-Rames-Offset' => '6', 'X-Rames-Size' => '99', 'X-Rames-Eof' => 'false']);
        $handler->reply(200, 'GHI', ['X-Rames-File' => 'demo.sql', 'X-Rames-Offset' => '9', 'X-Rames-Size' => '99', 'X-Rames-Eof' => 'false']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi batas unduh');
        $this->deployer($handler)->downloadDump('demo_data');
    }

    public function testDeployerSurfacesAgentNotFoundForMissingDump(): void
    {
        $handler = new AgentDumpDownloadFakeHandler();
        $handler->reply(404, json_encode(['code' => 1, 'error' => 'Belum ada berkas dump.', 'reason' => 'backup_dump_failed']));

        try {
            $this->deployer($handler)->downloadDump('demo_data');
            $this->fail('404 dari agent harus melempar AgentError');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::NOT_FOUND, $e->reason);
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(): AgentJobController
    {
        $runner = new BackupJobRunner(
            null,
            null,
            new AgentDumpDownloadFakeDumper($this->tmp . '/staging'),
            new AppStore($this->tmp . '/rames.sqlite'),
            new AgentDumpDownloadFakeDocker()
        );

        return new AgentJobController(null, null, null, null, $runner);
    }

    private function deployer(AgentDumpDownloadFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => 'srv-dump',
            'name' => 'agent-dump',
            'kind' => 'agent',
            'base_url' => 'https://agent-dump.test',
            'token' => 'tok-uji-dump-1234',
            'tls_cert_pem' => AgentDumpDownloadPem::selfSigned(),
        ];

        return new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'))
        );
    }

    private function request(string $method, string $path): Request
    {
        return new Request("{$method} {$path} HTTP/1.1\r\nHost: agent.test\r\n\r\n");
    }

    /**
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private function response(Response $response): array
    {
        $raw = $response->rawBody();
        $decoded = json_decode($raw, true);

        return [$response->getStatusCode(), $response->getHeaders(), $raw];
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}

/** Sertifikat self-signed kecil untuk pinning `AgentClient` di uji. */
final class AgentDumpDownloadPem
{
    public static function selfSigned(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            throw new RuntimeException('gagal membuat kunci uji');
        }
        $csr = openssl_csr_new(['commonName' => 'agent-dump.test'], $resource, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $resource, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
