<?php
declare(strict_types=1);

namespace Tests;

use app\controller\LogController;
use app\library\Deploy\DeployLog;
use app\library\Support\LogChunkReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/**
 * Pembaca log deploy + endpoint `GET /api/apps/{id}/deploy-log`.
 *
 * Semua berkas di direktori temp — tidak menyentuh `runtime/logs/deploy` nyata.
 */
class DeployLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-deploy-log-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testReadReturnsIncrementalChunksFromOffset(): void
    {
        $log = new DeployLog($this->dir);
        file_put_contents($log->path('app-1'), "line-1\nline-2\n");

        $first = $log->read('app-1', 0);
        $this->assertSame("line-1\nline-2\n", $first['text']);
        $this->assertSame(strlen("line-1\nline-2\n"), $first['offset']);

        file_put_contents($log->path('app-1'), "line-3\n", FILE_APPEND);

        $second = $log->read('app-1', $first['offset']);
        $this->assertSame("line-3\n", $second['text'], 'baris lama tidak boleh terulang');
        $this->assertSame(strlen("line-1\nline-2\nline-3\n"), $second['offset']);

        $idle = $log->read('app-1', $second['offset']);
        $this->assertSame('', $idle['text']);
        $this->assertSame($second['offset'], $idle['offset']);
    }

    public function testPartialLineAtEofIsDeliveredAndContinuedFromOffset(): void
    {
        $log = new DeployLog($this->dir);
        file_put_contents($log->path('app-1'), 'Building image');

        // EOF tepat di tengah baris: potongan dikirim apa adanya (tanpa newline);
        // sisa baris menyusul pada poll berikutnya sehingga teks akhirnya utuh.
        $first = $log->read('app-1', 0);
        $this->assertSame('Building image', $first['text']);

        file_put_contents($log->path('app-1'), " ... done\n", FILE_APPEND);
        $this->assertSame(" ... done\n", $log->read('app-1', $first['offset'])['text']);
    }

    public function testTailStartsOnLineBoundaryAndStaysWithinReadCap(): void
    {
        $log = new DeployLog($this->dir);
        $line = str_repeat('a', 79) . "\n";
        file_put_contents($log->path('app-1'), str_repeat($line, 4000));

        $tail = $log->read('app-1', 0, true);
        $this->assertLessThanOrEqual(LogChunkReader::MAX_READ, strlen($tail['text']));
        $this->assertSame(0, strlen($tail['text']) % 80, 'potongan harus dimulai di awal baris');
        $this->assertStringStartsWith('a', $tail['text']);
        $this->assertGreaterThan(0, $tail['offset']);
    }

    public function testMissingFileReadsAsEmpty(): void
    {
        $log = new DeployLog($this->dir);
        $chunk = $log->read('tidak-ada', 0);

        $this->assertSame('', $chunk['text']);
        $this->assertSame(0, $chunk['offset']);
    }

    public function testUnsafeIdIsRejected(): void
    {
        $log = new DeployLog($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $log->path('../etc/passwd');
    }

    public function testControllerServesTailTextWithContinuationOffset(): void
    {
        $log = new DeployLog($this->dir);
        file_put_contents($log->path('app-1'), "[t] [build] docker compose up -d --build ...\n#1 [internal] load build definition\n");

        $controller = new DeployLogTestableController(['id' => 'app-1', 'name' => 'demo'], $log);

        $response = $controller->deployLog($this->request('/api/apps/app-1/deploy-log?tail=1'), 'app-1');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame("[t] [build] docker compose up -d --build ...\n#1 [internal] load build definition\n", $response->rawBody());
        $this->assertSame(
            (string) strlen("[t] [build] docker compose up -d --build ...\n#1 [internal] load build definition\n"),
            (string) $response->getHeader('X-Rames-Offset')
        );
        $this->assertSame('no-store', (string) $response->getHeader('Cache-Control'));

        file_put_contents($log->path('app-1'), "#2 [web 1/2] RUN echo hi\n", FILE_APPEND);
        $next = $controller->deployLog(
            $this->request('/api/apps/app-1/deploy-log?offset=' . (int) $response->getHeader('X-Rames-Offset')),
            'app-1'
        );
        $this->assertSame("#2 [web 1/2] RUN echo hi\n", $next->rawBody());
    }

    private function request(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: text/plain\r\n\r\n");
    }

    public function testModalPartialRendersOnlyForUsersAllowedToReadLogs(): void
    {
        if (!function_exists('e')) {
            $stub = sys_get_temp_dir() . '/rames-deploy-log-view-stub-' . getmypid() . '.php';
            file_put_contents($stub, "<?php\nfunction e(\$value): string { return htmlspecialchars((string) \$value, ENT_QUOTES, 'UTF-8'); }\n");
            require $stub;
            @unlink($stub);
        }

        $file = dirname(__DIR__) . '/app/view/app/partials/detail-modal-deploy-log.php';
        $app = ['id' => 'app-1', 'name' => 'demo'];

        $canLogs = true;
        ob_start();
        include $file;
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('id="deploy-log-modal"', $html);
        $this->assertStringContainsString('/api/apps/app-1/deploy-log', $html);
        $this->assertStringContainsString('X-Rames-Offset', $html);

        $detail = (string) file_get_contents(dirname(__DIR__) . '/app/view/app/detail.php');
        $this->assertStringContainsString('data-bs-toggle="modal"', $detail);
        $this->assertStringContainsString('/deploy/cancel', $detail, 'tombol batalkan build tersedia saat deploy berjalan');

        $canLogs = false;
        ob_start();
        include $file;
        $this->assertSame('', (string) ob_get_clean(), 'modal tidak dirender tanpa ability logs');
    }
}

/**
 * Controller dengan seam otorisasi/pembaca diganti — fokus pada kontrak respons
 * endpoint (tanpa AppStore/AppAccess/session nyata).
 */
final class DeployLogTestableController extends LogController
{
    /**
     * @param array<string,mixed> $app
     */
    public function __construct(private readonly array $app, private readonly DeployLog $reader)
    {
    }

    protected function requireApp(string $id): array
    {
        return $this->app;
    }

    protected function deployLogReader(): DeployLog
    {
        return $this->reader;
    }
}
