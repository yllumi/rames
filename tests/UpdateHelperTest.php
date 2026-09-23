<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use app\library\Update\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Test mesin self-update (SPECS.md §7.8):
 *
 *  1. `UpdateService::buildHelperCommand()` — perintah `docker run` helper
 *     disusun sebagai ARRAY (tanpa shell) dan seluruh nilai tervalidasi masuk
 *     sebagai satu elemen argv, sehingga tidak ada command injection. Skrip
 *     helper disalin ke /tmp dulu supaya `git pull` tidak menimpa skrip yang
 *     sedang dieksekusi.
 *
 *  2. `cli/update-report.php` — penulis status mandiri yang dipakai helper
 *     (subproses, seperti cli/deploy.php diuji): hanya stage/result dari daftar
 *     tertutup yang diterima, SHA divalidasi, dan run terdahulu digabung (merge).
 */
class UpdateHelperTest extends TestCase
{
    private string $tmp;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->tmp = sys_get_temp_dir() . '/rames-helper-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================
    // Perintah helper
    // ==================================================================

    public function testBuildHelperCommandIsArrayAndCarriesAllContext(): void
    {
        $command = UpdateService::buildHelperCommand($this->spec());

        $this->assertSame('docker', $command[0]);
        $this->assertContains('run', $command);
        $this->assertContains('-d', $command);
        $this->assertContains('--rm', $command);

        // User = pemilik repo (agar berkas hasil pull tidak jadi milik root).
        $this->assertContains('--user', $command);
        $this->assertContains('1000:1000', $command);
        // Grup socket Docker (agar `docker compose` bisa dipakai user non-root).
        $this->assertContains('--group-add', $command);
        $this->assertContains('999', $command);
        // Network yang sama dengan dashboard → /healthz lewat nama container.
        $this->assertContains('--network', $command);
        $this->assertContains('rames_default', $command);
        $this->assertContains('--dns', $command);
        $this->assertContains('8.8.8.8', $command);
        $this->assertContains('1.1.1.1', $command);

        $this->assertContains('/var/run/docker.sock:/var/run/docker.sock', $command);
        $this->assertContains('/srv/rames:/srv/rames', $command);
        $this->assertContains('-w', $command);
        $this->assertContains('PWD=/srv/rames', $command);
        $this->assertContains('rames-webman', $command);

        // Ekor perintah: [image, sh, -c, <shell konstan>, sh, script, report, plan]
        $tail = array_slice($command, -8);
        $this->assertSame('rames-webman', $tail[0]);
        $this->assertSame('sh', $tail[1]);
        $this->assertSame('-c', $tail[2]);
        $this->assertSame(
            'cp "$1" /tmp/rames-self-update.sh && cp "$2" /tmp/rames-update-report.php && exec sh /tmp/rames-self-update.sh "$3"',
            $tail[3]
        );
        $this->assertSame('sh', $tail[4]);
        // Skrip dijalankan dari SALINAN di /tmp (stabil walau repo berubah oleh pull).
        $this->assertStringContainsString('/tmp/rames-self-update.sh', (string) $tail[3]);
        $this->assertStringContainsString('/tmp/rames-update-report.php', (string) $tail[3]);
        $this->assertSame(
            ['/srv/rames/cli/self-update.sh', '/srv/rames/cli/update-report.php', '/tmp/runs/x.plan.json'],
            array_slice($tail, 5)
        );
    }

    public function testOptionalFlagsAreOmittedWhenAbsent(): void
    {
        $spec = $this->spec();
        $spec['group_add'] = 0;
        $spec['dns'] = [];

        $command = UpdateService::buildHelperCommand($spec);

        $this->assertNotContains('--group-add', $command);
        $this->assertNotContains('--dns', $command);
    }

    public function testValuesAreNeverInterpolatedIntoTheShellString(): void
    {
        // Nilai "jahat" pada path & nama: harus tetap jadi SATU elemen argv dan
        // string `sh -c` tidak boleh ikut berubah (konstan).
        $spec = $this->spec();
        $spec['root'] = '/srv/evil; rm -rf / #';
        $spec['script'] = '/srv/evil; rm -rf / #/cli/self-update.sh';
        $spec['plan'] = '/tmp/x$(id).plan.json';

        $command = UpdateService::buildHelperCommand($spec);

        $expectedShell = 'cp "$1" /tmp/rames-self-update.sh && cp "$2" /tmp/rames-update-report.php && exec sh /tmp/rames-self-update.sh "$3"';
        $this->assertContains($expectedShell, $command);
        $this->assertContains($spec['script'], $command);
        $this->assertContains($spec['plan'], $command);
        // Path jahat tetap utuh sebagai SATU elemen argv (bukan dipecah shell).
        $this->assertContains($spec['root'] . ':' . $spec['root'], $command);
        $this->assertSame(1, count(array_filter($command, static fn (string $arg): bool => $arg === $spec['script'])));
        $this->assertSame(1, count(array_filter($command, static fn (string $arg): bool => $arg === $spec['plan'])));
    }

    // ==================================================================
    // cli/update-report.php
    // ==================================================================

    public function testReportScriptGetsPlanValues(): void
    {
        $plan = $this->writePlan(['branch' => 'main', 'health_timeout' => 180]);

        $this->assertSame('main', trim($this->report(['get', $plan, 'branch'])['stdout']));
        $this->assertSame('180', trim($this->report(['get', $plan, 'health_timeout'])['stdout']));
        $this->assertSame('fallback', trim($this->report(['get', $plan, 'tidak_ada', 'fallback'])['stdout']));
    }

    public function testReportScriptWritesRunProgress(): void
    {
        $plan = $this->writePlan();

        $this->report(['set', $plan, 'fetch', 'running', 'Mengambil pembaruan…']);
        $run = $this->readRun();
        $this->assertSame('fetch', $run['stage']);
        $this->assertNull($run['result']);
        $this->assertArrayNotHasKey('finished_at', $run);
        $this->assertSame('20260923-120000-abc123', $run['id']);
        $this->assertSame('update', $run['mode']);
        $this->assertSame('aaaa', $run['old_sha']);

        // Tahap berikutnya MENGGABUNG (bukan menimpa) status sebelumnya.
        $this->report(['set', $plan, 'build', 'running', 'Build…']);
        $run = $this->readRun();
        $this->assertSame('build', $run['stage']);
        $this->assertSame('20260923-120000-abc123', $run['id']);

        $this->report(['set', $plan, 'finished', 'success', 'Selesai.']);
        $run = $this->readRun();
        $this->assertSame('success', $run['result']);
        $this->assertNotNull($run['finished_at']);
        $this->assertNotSame('', (string) $run['finished_at']);
    }

    public function testReportScriptRecordsFailure(): void
    {
        $plan = $this->writePlan();

        $this->report(['set', $plan, 'finished', 'error', 'composer install gagal']);
        $run = $this->readRun();

        $this->assertSame('error', $run['result']);
        $this->assertSame('composer install gagal', $run['error']);
    }

    public function testReportScriptUpdatesMutableState(): void
    {
        $plan = $this->writePlan();

        $sha = 'b' . str_repeat('0', 39);
        $this->report(['state', $plan, 'target_sha', $sha]);
        $this->assertSame($sha, $this->readRun()['target_sha']);

        $this->report(['state', $plan, 'rollback_from', $sha]);
        $this->assertSame($sha, $this->readRun()['rollback_from']);
    }

    public function testReportScriptRejectsUnknownStageResultAndKey(): void
    {
        $plan = $this->writePlan();

        $stage = $this->report(['set', $plan, 'rm -rf /', 'running', 'x']);
        $this->assertSame(1, $stage['code']);
        $this->assertStringContainsString('Tahap tidak dikenali', $stage['stderr']);

        $result = $this->report(['set', $plan, 'build', 'hacked', 'x']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Hasil tidak dikenali', $result['stderr']);

        $key = $this->report(['state', $plan, 'root', '/etc']);
        $this->assertSame(1, $key['code']);
        $this->assertStringContainsString('Kunci yang boleh diubah', $key['stderr']);

        // Nilai bukan SHA ditolak (tidak boleh masuk berkas status).
        $bad = $this->report(['state', $plan, 'target_sha', 'main; rm -rf /']);
        $this->assertSame(1, $bad['code']);
        $this->assertStringContainsString('harus SHA', $bad['stderr']);
    }

    public function testReportScriptRejectsMissingOrBrokenPlan(): void
    {
        $missing = $this->report(['get', $this->tmp . '/tidak-ada.json', 'root']);
        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('Plan update tidak ditemukan', $missing['stderr']);

        file_put_contents($this->tmp . '/broken.json', '{bukan json');
        $broken = $this->report(['get', $this->tmp . '/broken.json', 'root']);
        $this->assertSame(1, $broken['code']);
        $this->assertStringContainsString('bukan JSON yang valid', $broken['stderr']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    private function spec(): array
    {
        return [
            'image' => 'rames-webman',
            'container' => 'rames-self-update-20260923-120000-abc123',
            'network' => 'rames_default',
            'dns' => ['8.8.8.8', '1.1.1.1'],
            'uid' => 1000,
            'gid' => 1000,
            'group_add' => 999,
            'root' => '/srv/rames',
            'script' => '/srv/rames/cli/self-update.sh',
            'report' => '/srv/rames/cli/update-report.php',
            'plan' => '/tmp/runs/x.plan.json',
            'socket' => '/var/run/docker.sock',
            'docker_binary' => 'docker',
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function writePlan(array $overrides = []): string
    {
        $plan = array_merge([
            'id' => '20260923-120000-abc123',
            'mode' => 'update',
            'root' => '/srv/rames',
            'branch' => 'main',
            'project' => 'rames',
            'service' => 'webman',
            'old_sha' => 'aaaa',
            'target_sha' => '',
            'health_url' => 'http://rames-webman:8787/healthz',
            'health_timeout' => 180,
            'rollback_timeout' => 180,
            'run_dir' => $this->tmp . '/runs',
            'log_file' => $this->tmp . '/runs/20260923-120000-abc123.log',
            'actor' => 'admin',
        ], $overrides);

        $path = $this->tmp . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function readRun(): array
    {
        $raw = (string) file_get_contents($this->tmp . '/runs/run.json');
        $data = json_decode($raw, true);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * Jalankan cli/update-report.php sebagai subproses.
     *
     * @param array<int,string> $args
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function report(array $args): array
    {
        return (new ProcessRunner())->run(
            array_merge([PHP_BINARY, $this->root . '/cli/update-report.php'], $args),
            $this->root,
            30
        );
    }
}
