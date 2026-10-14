<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentFilesController;
use app\library\Files\ContainerFiles;
use app\library\Files\FileError;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

// Helper webman (`json()`, `response()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `ContainerFiles` tiruan (tanpa Docker, tanpa container): merekam panggilan dan
 * mengembalikan hasil yang bisa diatur test. Kelas induk tidak dipanggil
 * (konstruktor induk hanya menyimpan seam Docker) — tidak ada Engine yang
 * tersentuh.
 */
final class AgentFilesFakeContainerFiles extends ContainerFiles
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array<string,mixed>> */
    public array $args = [];

    public bool $running = true;

    public int $downloadBytes = 4;

    /** @var array<string,mixed> */
    public array $listResult = ['path' => '/', 'parent' => null, 'truncated' => false, 'entries' => []];

    /** @var array<string,mixed> */
    public array $readResult = ['path' => '/a.txt', 'size' => 2, 'text' => 'hi', 'truncated' => false];

    /** @var array<string,mixed> */
    public array $moveResult = ['from' => '/src/a.txt', 'to' => '/dst/a.txt'];

    public int $extractCount = 2;

    public ?string $uploadedBody = null;

    /** @var list<string> */
    public array $cleaned = [];

    /** @var list<string> */
    public array $scheduled = [];

    public function assertRunning(string $container): void
    {
        $this->calls[] = 'assertRunning';
        $this->args[] = ['container' => $container];
        if (!$this->running) {
            throw new FileError('Container sedang tidak berjalan — jalankan app dulu.', 409);
        }
    }

    public function list(string $container, string $path): array
    {
        $this->calls[] = 'list';
        $this->args[] = ['container' => $container, 'path' => $path];

        return $this->listResult;
    }

    public function read(string $container, string $path): array
    {
        $this->calls[] = 'read';
        $this->args[] = ['container' => $container, 'path' => $path];

        return $this->readResult;
    }

    public function write(string $container, string $path, string $content): void
    {
        $this->calls[] = 'write';
        $this->args[] = ['container' => $container, 'path' => $path, 'text' => $content];
    }

    public function mkdir(string $container, string $path): void
    {
        $this->calls[] = 'mkdir';
        $this->args[] = ['container' => $container, 'path' => $path];
    }

    public function rename(string $container, string $from, string $to): void
    {
        $this->calls[] = 'rename';
        $this->args[] = ['container' => $container, 'from' => $from, 'to' => $to];
    }

    public function delete(string $container, string $path): void
    {
        $this->calls[] = 'delete';
        $this->args[] = ['container' => $container, 'path' => $path];
    }

    public function move(string $container, string $from, string $toDir, ?string $name = null): array
    {
        $this->calls[] = 'move';
        $this->args[] = ['container' => $container, 'from' => $from, 'dest' => $toDir, 'name' => $name];

        return $this->moveResult;
    }

    public function extract(string $container, string $dir, string $name, ?string $dest = null): int
    {
        $this->calls[] = 'extract';
        $this->args[] = ['container' => $container, 'dir' => $dir, 'name' => $name, 'dest' => $dest];

        return $this->extractCount;
    }

    public function downloadToTemp(string $container, string $path): array
    {
        $this->calls[] = 'downloadToTemp';
        $this->args[] = ['container' => $container, 'path' => $path];

        $dir = sys_get_temp_dir() . '/rames-agent-files-' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        $name = basename($path);
        $file = $dir . '/' . $name;
        file_put_contents($file, str_repeat('x', $this->downloadBytes));

        return ['dir' => $dir, 'path' => $file, 'name' => $name];
    }

    public function uploadOne(string $container, string $dir, string $tmpPath, string $name): array
    {
        $this->calls[] = 'upload';
        $this->uploadedBody = is_file($tmpPath) ? (string) file_get_contents($tmpPath) : null;
        $this->args[] = ['container' => $container, 'dir' => $dir, 'tmp' => $tmpPath, 'name' => $name];

        return ['name' => $name, 'size' => strlen((string) $this->uploadedBody)];
    }

    public function cleanup(string $dir): void
    {
        $this->cleaned[] = $dir;
    }

    public function scheduleCleanup(string $dir, int $delaySeconds = 300): void
    {
        $this->scheduled[] = $dir;
    }
}

/** `AgentFilesController` dengan seam uji (store temp, plafon & dir unggah uji). */
final class AgentFilesTestController extends AgentFilesController
{
    public function __construct(
        AppStore $store,
        ContainerFiles $files,
        private int $limit = AgentFilesController::MAX_TRANSFER_BYTES,
        private ?string $uploadDir = null,
    ) {
        parent::__construct($store, $files);
    }

    protected function transferLimit(): int
    {
        return $this->limit;
    }

    protected function uploadDir(): string
    {
        return $this->uploadDir ?? sys_get_temp_dir() . '/rames-agent-upload';
    }
}

/**
 * Endpoint file manager **sisi agent** (F3b, plan §4.1/§5): seluruh operasi
 * dijalankan di server agent memakai library yang sama dengan jalur lokal.
 *
 * Kontrak yang dikunci:
 *  1. app dibaca dari **proyeksi agent** (`AppStore` agent) dan container
 *     divalidasi `AppContainers::resolve()`; app/container tak ada ⇒ **404**
 *     (bukan 403; app user lain tidak terbocor);
 *  2. galat **domain** memakai envelope dashboard (HTTP 200 + `code`) supaya
 *     control plane meneruskan kode yang sama dengan jalur lokal: container
 *     berhenti ⇒ 409, terlalu besar ⇒ 413, path/nama tak aman ⇒ 400;
 *  3. path & nama divalidasi `PathGuard`/`FilesInput` **sebelum** menyentuh
 *     container (…, absolut wajib, entri ber-`/`, diawali `-`, akar `/`);
 *  4. unduhan menegakkan plafon transfer & membersihkan temp; unggahan menulis
 *     body ke berkas temp lalu membersihkannya (isi berkas tidak ditinggal);
 *  5. proteksi zip-slip tetap milik library (`ArchiveGuard`) — diuji
 *     end-to-end dengan `ContainerFiles` **nyata** + `docker cp` tiruan.
 *
 * Semua uji tanpa jaringan, tanpa Docker, tanpa `apps/`/`runtime/` nyata.
 */
class AgentFilesControllerTest extends TestCase
{
    private const APP_ID = 'app-a1';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agent-files-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    // ==================================================================
    // (1)/(2) List, read & proyeksi app
    // ==================================================================

    public function testListReadsFromTheAgentProjectionAndEnvelopeMatchesLocalShape(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $files->listResult = [
            'path' => '/srv',
            'parent' => '/',
            'truncated' => false,
            'entries' => [['name' => 'a.txt', 'type' => 'file', 'size' => 3]],
        ];
        $controller = $this->controller($files);

        $response = $controller->list($this->get('/files/list?' . http_build_query([
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'path' => '/srv',
        ])));

        $body = $this->payload($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('/srv', $body['data']['path']);
        $this->assertSame('a.txt', $body['data']['entries'][0]['name']);
        $this->assertSame(['assertRunning', 'list'], $files->calls);
        $this->assertSame('demo-web-1', $files->args[0]['container']);
        $this->assertSame('/srv', $files->args[1]['path']);
    }

    public function testReadUsesDefaultContainerWhenRequestedIsEmpty(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files);

        $body = $this->payload($controller->read($this->get('/files/read?' . http_build_query([
            'app_id' => self::APP_ID,
            'path' => '/etc/hosts',
        ]))));

        $this->assertSame(0, $body['code']);
        $this->assertSame('demo-web-1', $files->args[0]['container'], 'container default app dipakai');
    }

    public function testAppOutsideTheAgentProjectionIsRejectedWithHttp404(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files);

        $response = $controller->list($this->get('/files/list?' . http_build_query([
            'app_id' => 'app-hantu',
            'container' => 'demo-web-1',
        ])));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $this->payload($response)['reason']);
        $this->assertSame([], $files->calls, 'app tak dikenal tidak boleh menyentuh container');
    }

    public function testUnknownOrMalformedContainerIsRejectedWith404(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files);

        foreach (['container-lain', '../../etc', 'nama container dengan spasi'] as $name) {
            $response = $controller->list($this->get('/files/list?' . http_build_query([
                'app_id' => self::APP_ID,
                'container' => $name,
            ])));

            $this->assertSame(404, $response->getStatusCode(), "container harus ditolak: {$name}");
        }

        $this->assertSame([], $files->calls);
    }

    // ==================================================================
    // (3) Validasi path/nama
    // ==================================================================

    public function testUnsafePathsAreRejectedBeforeTouchingTheContainer(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files);

        foreach (['relatif.txt', '/../../etc/passwd', "/a\0b", "/a\nb"] as $path) {
            $response = $controller->list($this->get('/files/list?' . http_build_query([
                'app_id' => self::APP_ID,
                'container' => 'demo-web-1',
                'path' => $path,
            ])));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(400, $this->payload($response)['code'], "path harus ditolak: {$path}");
        }

        $this->assertSame([], $files->calls, 'validasi path mendahului efek samping');
    }

    public function testDeleteRootAndUnsafeNamesAreRejected(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files);
        $base = ['app_id' => self::APP_ID, 'container' => 'demo-web-1'];

        $this->assertSame(400, $this->payload($controller->delete(
            $this->post($base + ['path' => '/'], '/files/delete')
        ))['code']);

        $this->assertSame(400, $this->payload($controller->mkdir(
            $this->post($base + ['target' => 'relatif'], '/files/mkdir')
        ))['code']);

        $this->assertSame(400, $this->payload($controller->upload(
            $this->rawPost('/files/upload?' . http_build_query($base + ['dir' => '/srv', 'name' => '../evil']), 'isi')
        ))['code']);

        $this->assertSame(400, $this->payload($controller->upload(
            $this->rawPost('/files/upload?' . http_build_query($base + ['dir' => '/srv', 'name' => '-flag']), 'isi')
        ))['code']);

        $this->assertSame([], $files->calls);
    }

    // ==================================================================
    // (5) Container berhenti ⇒ 409
    // ==================================================================

    public function testStoppedContainerIsReportedAs409ForEveryOperation(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $files->running = false;
        $controller = $this->controller($files);
        $base = ['app_id' => self::APP_ID, 'container' => 'demo-web-1'];

        $this->assertSame(409, $this->payload($controller->list(
            $this->get('/files/list?' . http_build_query($base))
        ))['code']);
        $this->assertSame(409, $this->payload($controller->delete(
            $this->post($base + ['path' => '/srv/a.txt'], '/files/delete')
        ))['code']);
        $this->assertSame(409, $this->payload($controller->download(
            $this->get('/files/download?' . http_build_query($base + ['path' => '/srv/a.txt']))
        ))['code']);

        $this->assertSame(
            ['assertRunning', 'assertRunning', 'assertRunning'],
            $files->calls,
            'operasi tidak dilanjutkan saat container berhenti'
        );
    }

    // ==================================================================
    // (4)/(7) Batas ukuran & pembersihan temp
    // ==================================================================

    public function testDownloadReturnsAFileResponseAndSchedulesCleanup(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files);

        $response = $controller->download($this->get('/files/download?' . http_build_query([
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'path' => '/srv/report.txt',
        ])));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($response->file, 'respons unduhan memakai berkas temp (streaming)');
        $this->assertStringContainsString('attachment', (string) $response->getHeader('Content-Disposition'));
        $this->assertStringContainsString('report.txt', (string) $response->getHeader('Content-Disposition'));
        $this->assertCount(1, $files->scheduled, 'temp dibersihkan SETELAH respons dikirim');
        $this->assertSame([], $files->cleaned);
    }

    public function testDownloadOverTheTransferLimitIsRejectedWith413AndTempIsCleaned(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $files->downloadBytes = 32;
        $controller = $this->controller($files, 16);

        $response = $controller->download($this->get('/files/download?' . http_build_query([
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'path' => '/srv/big.bin',
        ])));
        $body = $this->payload($response);

        $this->assertSame(413, $response->getStatusCode(), 'jalur unduhan memakai status HTTP nyata');
        $this->assertSame(413, $body['code']);
        $this->assertStringContainsString('melebihi batas', (string) $body['msg']);
        $this->assertCount(1, $files->cleaned, 'berkas temp dibersihkan saat ditolak');
        $this->assertSame([], $files->scheduled);
    }

    public function testUploadWritesTheBodyToATempFileAndRemovesItAfterwards(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $uploadDir = $this->tmp . '/upload';
        $controller = $this->controller($files, null, $uploadDir);

        $body = $this->payload($controller->upload($this->rawPost(
            '/files/upload?' . http_build_query([
                'app_id' => self::APP_ID,
                'container' => 'demo-web-1',
                'dir' => '/srv',
                'name' => 'a.txt',
            ]),
            'isi-berkas'
        )));

        $this->assertSame(0, $body['code']);
        $this->assertSame('a.txt', $body['data']['name']);
        $this->assertSame('isi-berkas', $files->uploadedBody);
        $this->assertSame('/srv', $files->args[1]['dir']);
        $this->assertSame([], glob($uploadDir . '/*'), 'berkas temp unggahan dibersihkan');
    }

    public function testUploadOverTheTransferLimitIsRejectedWith413(): void
    {
        $files = new AgentFilesFakeContainerFiles();
        $controller = $this->controller($files, 4, $this->tmp . '/upload');

        $body = $this->payload($controller->upload($this->rawPost(
            '/files/upload?' . http_build_query([
                'app_id' => self::APP_ID,
                'container' => 'demo-web-1',
                'dir' => '/srv',
                'name' => 'a.txt',
            ]),
            str_repeat('x', 5)
        )));

        $this->assertSame(413, $body['code']);
        $this->assertSame([], $files->calls, 'berkas besar ditolak sebelum menyentuh container');
    }

    // ==================================================================
    // (4) zip-slip ditolak DI SISI AGENT (end-to-end, library nyata)
    // ==================================================================

    public function testZipSlipArchiveIsRejectedByTheAgentLibrary(): void
    {
        // Arsip nyata berisi entri `../evil.txt` (zip-slip), "container" tiruan di
        // disk, dan `docker cp` tiruan berupa skrip (tanpa Docker).
        $root = $this->tmp . '/container';
        mkdir($root . '/srv', 0700, true);
        $this->makeZipSlipArchive($root . '/srv/evil.zip');
        file_put_contents($this->tmp . '/cp.log', '');

        $docker = $this->fakeDockerBinary($root);
        $exec = new FakeScriptedDockerExec(static function (string $command): array {
            // `containerType()` di dalam container tiruan.
            return str_contains($command, '/srv/evil.zip')
                ? ['code' => 0, 'stdout' => "file\n"]
                : ['code' => 0, 'stdout' => "dir\n"];
        });

        $files = new ContainerFiles(new FakeInspectDockerClient(''), $exec, $this->tmp . '/xfer', $docker);
        $controller = new AgentFilesTestController($this->store(), $files);

        $body = $this->payload($controller->extract($this->post([
            'app_id' => self::APP_ID,
            'container' => 'demo-web-1',
            'dir' => '/srv',
            'name' => 'evil.zip',
        ], '/files/extract')));

        $this->assertSame(400, $body['code'], 'zip-slip harus ditolak di agent');
        $this->assertStringContainsString('tidak aman', (string) $body['msg']);

        // Tidak boleh ada hasil ekstraksi yang disalin ke container (hanya
        // penyalinan arsip KELUAR dari container yang boleh terjadi).
        $log = (string) file_get_contents($this->tmp . '/cp.log');
        $this->assertStringContainsString('demo-web-1:/srv/evil.zip', $log);
        $this->assertStringNotContainsString('demo-web-1:/srv', str_replace('demo-web-1:/srv/evil.zip', '', $log));
        $this->assertFileDoesNotExist($root . '/../evil.txt');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(
        AgentFilesFakeContainerFiles $files,
        ?int $limit = null,
        ?string $uploadDir = null
    ): AgentFilesTestController {
        return new AgentFilesTestController(
            $this->store(),
            $files,
            $limit ?? AgentFilesController::MAX_TRANSFER_BYTES,
            $uploadDir
        );
    }

    /** Store proyeksi agent (path temp) berisi satu app + dua container. */
    private function store(): AppStore
    {
        $store = new AppStore($this->tmp . '/rames.sqlite');
        $store->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'local',
            'primary_service' => 'web',
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'demo-web-1'],
                ['service_name' => 'db', 'container_name' => 'demo-db-1'],
            ],
        ]);

        return $store;
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

    /** POST dengan body biner mentah (jalur unggah). */
    private function rawPost(string $path, string $body): Request
    {
        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/octet-stream\r\n"
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

    /** Arsip zip nyata dengan entri `../evil.txt` (zip-slip). */
    private function makeZipSlipArchive(string $path): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('../evil.txt', 'pwned');
        $zip->close();
    }

    /**
     * Skrip `docker` tiruan: meniru `docker cp <container>:<path> <host>` dan
     * `docker cp <host> <container>:<dest>` terhadap direktori container tiruan,
     * sambil mencatat setiap pemanggilan ke `<tmp>/cp.log`.
     */
    private function fakeDockerBinary(string $root): string
    {
        $script = $this->tmp . '/docker-fake';
        $log = $this->tmp . '/cp.log';
        $content = "#!/bin/sh\n"
            . 'echo "$@" >> ' . escapeshellarg($log) . "\n"
            . '[ "$1" = "cp" ] || exit 0' . "\n"
            . 'src="$2"; dst="$3"' . "\n"
            . 'case "$src" in *:*) src="' . $root . '${src#*:}";; esac' . "\n"
            . 'case "$dst" in *:*) dst="' . $root . '${dst#*:}";; esac' . "\n"
            . 'mkdir -p "$(dirname "$dst")" 2>/dev/null' . "\n"
            . 'cp -r "$src" "$dst" 2>/dev/null' . "\n"
            . "exit 0\n";
        file_put_contents($script, $content);
        chmod($script, 0755);

        return $script;
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
