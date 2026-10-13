<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Test end-to-end worker `cli/pull.php` (§7.13) dengan runner palsu — TANPA
 * daemon/registry Docker.
 *
 * Diuji: urutan & isi laporan `run.json` (stage, results[], result), log mentah,
 * argv per image (array + binary dari config + timeout dari config), jalur gagal
 * sebagian (image lain tetap ditarik, hasil akhir `error`), dan pemanggilan tanpa
 * slug valid. Semua berkas diarahkan ke direktori temp lewat `TEMPLATE_PULL_RUN_DIR`
 * supaya `runtime/` produksi tidak tersentuh.
 */
class CliTemplatePullTest extends TestCase
{
    private string $root;
    private string $workDir;
    private array $env;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->workDir = sys_get_temp_dir() . '/rames-cli-pull-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0777, true);

        // Override defensif: seluruh data runtime diarahkan ke temp meskipun
        // worker ini (sengaja) tidak menyentuh database/apps/nginx.
        $this->env = [
            'DATABASE_PATH' => $this->workDir . '/database',
            'RAMES_DB_DIR' => $this->workDir . '/database',
            'APPS_PATH' => $this->workDir . '/apps',
            'NGINX_CONF_PATH' => $this->workDir . '/nginx',
            'NGINX_ENABLED_PATH' => $this->workDir . '/nginx',
            'TEMPLATES_PATH' => $this->root . '/templates',
            'TEMPLATE_PULL_RUN_DIR' => $this->workDir . '/run',
            'TEMPLATE_PULL_TIMEOUT' => '7',
            'TEMPLATE_PULL_RUNNER_CLASS' => FakeTemplatePullRunner::class,
            'FAKE_TEMPLATE_PULL_CALLS' => $this->workDir . '/calls.json',
            'DOCKER_BINARY' => 'docker-test',
        ];
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->workDir);
    }

    public function testPullsEveryImageAndWritesFinalReport(): void
    {
        $result = $this->runWorker('outline', 'tester');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);

        $run = $this->runJson();
        $this->assertSame('outline', $run['slug']);
        $this->assertSame('Outline', $run['title']);
        $this->assertSame('tester', $run['actor']);
        $this->assertSame('finished', $run['stage']);
        $this->assertSame('success', $run['result']);
        $this->assertNull($run['error']);
        $this->assertNotNull($run['finished_at']);
        $this->assertSame(0, $run['pid']);

        $images = [
            'docker.getoutline.com/outlinewiki/outline:latest',
            'postgres:18',
            'redis:8-alpine',
        ];
        $this->assertSame($images, $run['images']);
        $this->assertSame($images, array_column($run['results'], 'image'));
        $this->assertSame([true, true, true], array_column($run['results'], 'ok'));

        // Argv persis: binary dari config + `pull` + ref (array, tanpa shell),
        // dengan timeout per image dari config.
        $calls = $this->callbackCalls();
        $this->assertCount(3, $calls);
        foreach ($images as $index => $ref) {
            $this->assertSame(['docker-test', 'pull', $ref], $calls[$index]['command']);
            $this->assertSame(7, $calls[$index]['timeout']);
            $this->assertNull($calls[$index]['cwd']);
        }

        // Log mentah: header per image + keluaran runner + ringkasan akhir.
        $log = (string) file_get_contents($this->runDir() . '/' . $run['log']);
        $this->assertStringContainsString('docker pull ' . $images[0], $log);
        $this->assertStringContainsString('Pull complete: redis:8-alpine', $log);
        $this->assertStringContainsString('selesai: sukses', $log);
    }

    public function testPartialFailureStillPullsRemainingImagesAndFailsRun(): void
    {
        $result = $this->runWorker('outline', 'tester', ['FAKE_TEMPLATE_PULL_FAIL' => 'redis']);

        $this->assertSame(1, $result['code']);

        $run = $this->runJson();
        $this->assertSame('finished', $run['stage']);
        $this->assertSame('error', $run['result']);
        $this->assertStringContainsString('Gagal menarik image: redis:8-alpine', (string) $run['error']);
        $this->assertSame([true, true, false], array_column($run['results'], 'ok'));
        $this->assertStringContainsString('Gagal (exit 1)', (string) $run['results'][2]['message']);
        $this->assertSame('OK', $run['results'][0]['message']);

        // Image ketiga tetap dicoba walau yang sebelumnya gagal? Di sini yang
        // gagal justru image terakhir — pastikan ketiganya dipanggil berurutan.
        $this->assertCount(3, $this->callbackCalls());
    }

    public function testFirstImageFailureDoesNotStopLaterImages(): void
    {
        $result = $this->runWorker('outline', 'tester', ['FAKE_TEMPLATE_PULL_FAIL' => 'outline:latest']);

        $this->assertSame(1, $result['code']);

        $run = $this->runJson();
        $this->assertSame([false, true, true], array_column($run['results'], 'ok'));
        $this->assertCount(3, $this->callbackCalls(), 'satu image gagal tidak boleh menghentikan sisanya');
        $this->assertStringContainsString('Periksa koneksi registry', (string) $run['error']);
    }

    public function testTimeoutIsReportedInResultMessage(): void
    {
        $result = $this->runWorker('outline', 'tester', ['FAKE_TEMPLATE_PULL_TIMEOUT' => 'postgres']);

        $this->assertSame(1, $result['code']);

        $run = $this->runJson();
        $this->assertFalse($run['results'][1]['ok']);
        $this->assertStringContainsString('timeout 7s', (string) $run['results'][1]['message']);
    }

    public function testUnknownSlugFailsFastWithoutCallingRunner(): void
    {
        $result = $this->runWorker('tidak-ada', 'tester');

        $this->assertSame(1, $result['code']);

        $run = $this->runJson();
        $this->assertSame('error', $run['result']);
        $this->assertStringContainsString('tidak ditemukan', (string) $run['error']);
        $this->assertSame([], $run['results']);
        $this->assertFileDoesNotExist($this->workDir . '/calls.json');
    }

    public function testWorkerNeverWritesIntoRealRuntimeDirectory(): void
    {
        // Isi direktori runtime produksi TIDAK boleh berubah (env override
        // TEMPLATE_PULL_RUN_DIR wajib dihormati).
        $realDir = $this->root . '/runtime/logs/template-pull';
        $before = is_dir($realDir) ? (array) scandir($realDir) : [];

        $this->runWorker('mysql', 'tester');

        $after = is_dir($realDir) ? (array) scandir($realDir) : [];
        $this->assertSame($before, $after, 'worker menulis ke runtime produksi — override TEMPLATE_PULL_RUN_DIR gagal');

        $run = $this->runJson();
        $this->assertNotSame('', (string) $run['log']);
        $this->assertFileExists($this->runDir() . '/' . $run['log']);
    }

    /**
     * Jalankan worker sebagai subproses (pola `CliDeployRollbackTest`).
     *
     * @param array<string,string> $extraEnv
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function runWorker(string $slug, string $actor, array $extraEnv = []): array
    {
        return (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/pull.php', $slug, $actor],
            $this->root,
            60,
            array_merge($this->env, $extraEnv)
        );
    }

    private function runDir(): string
    {
        return $this->workDir . '/run';
    }

    /**
     * @return array<string,mixed>
     */
    private function runJson(): array
    {
        $path = $this->runDir() . '/run.json';
        $this->assertFileExists($path, 'worker wajib menulis laporan akhir di semua jalur');
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @return array<int,array{command:array<int,string>,cwd:?string,timeout:int}>
     */
    private function callbackCalls(): array
    {
        $path = $this->workDir . '/calls.json';
        $this->assertFileExists($path, 'runner palsu tidak pernah dipanggil');
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);

        return $data;
    }
}
