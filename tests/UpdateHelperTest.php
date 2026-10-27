<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test mesin self-update (docs/SPECS.md §7.8):
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
    // Network helper (egress + health-check) & konteks container dashboard
    // ==================================================================

    public function testHelperNetworkPrefersComposeDefaultOverInternalHelperNetwork(): void
    {
        // Dashboard juga ter-attach ke network helper Adminer `rames-helpers`
        // (`internal: true`) dan ke network default compose. Urutan kunci
        // `NetworkSettings.Networks` terurut abjad → "rames-helpers" lebih dulu;
        // pemilihan TIDAK boleh mengikuti urutan itu (network internal tidak punya
        // egress → `git fetch` gagal "Could not resolve host").
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames-helpers', 'rames_default'],
            ['rames-helpers' => true, 'rames_default' => false],
            'rames'
        );

        $this->assertSame('rames_default', $chosen);
    }

    public function testHelperNetworkIgnoresAppNetworksAttachedToDashboard(): void
    {
        // Network DB app (di-attach dashboard saat dump/restore) tidak dipakai
        // walau non-internal: container dashboard hasil recreate berada di network
        // compose, dan /healthz diakses lewat nama container di network yang sama.
        $chosen = UpdateService::chooseHelperNetwork(
            ['aaa_default', 'rames-helpers', 'rames_default'],
            ['aaa_default' => false, 'rames-helpers' => true, 'rames_default' => false],
            'rames'
        );

        $this->assertSame('rames_default', $chosen);
    }

    public function testHelperNetworkSkipsInternalComposeNetwork(): void
    {
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames_default', 'rames_shared'],
            ['rames_default' => true, 'rames_shared' => false],
            'rames'
        );

        $this->assertSame('rames_shared', $chosen);
    }

    public function testHelperNetworkIsEmptyWhenEveryNetworkIsInternal(): void
    {
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames-helpers', 'rames_default'],
            ['rames-helpers' => true, 'rames_default' => true],
            'rames'
        );

        $this->assertSame('', $chosen);
    }

    public function testHelperNetworkRefusesForeignProjectNetworkWhenInternalFlagsAreKnown(): void
    {
        // Semua network project compose internal, tersisa network milik project
        // lain (app). Network itu punya egress, tetapi container dashboard hasil
        // recreate tidak ada di sana → /healthz gagal & update berakhir rollback.
        // Lebih baik ditolak di preflight.
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames_default', 'rames-helpers', 'sibimo_default'],
            ['rames_default' => true, 'rames-helpers' => true, 'sibimo_default' => false],
            'rames'
        );

        $this->assertSame('', $chosen);
    }

    public function testHelperNetworkToleratesUnknownInternalFlagAndMissingProject(): void
    {
        // Engine tidak menjawab saat inspect → status internal tidak diketahui:
        // update tetap boleh dicoba, jangan memblokir karena tidak bisa memastikan.
        $this->assertSame('rames_default', UpdateService::chooseHelperNetwork(['rames_default'], [], 'rames'));
        $this->assertSame('app_net', UpdateService::chooseHelperNetwork(['app_net'], [], 'rames'));
        $this->assertSame('dash_default', UpdateService::chooseHelperNetwork(['dash_default'], [], ''));
        $this->assertSame('', UpdateService::chooseHelperNetwork([], [], 'rames'));
    }

    public function testSelfContextPicksNonInternalNetworkAndKeepsDns(): void
    {
        $context = $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => [
                    'Image' => 'rames-webman',
                    'Labels' => [
                        'com.docker.compose.project' => 'rames',
                        'com.docker.compose.service' => 'webman',
                    ],
                ],
                'HostConfig' => ['Dns' => ['8.8.8.8', '1.1.1.1']],
                'NetworkSettings' => [
                    'Networks' => [
                        'rames-helpers' => ['IPAddress' => '172.20.0.2'],
                        'rames_default' => ['IPAddress' => '172.18.0.2'],
                    ],
                ],
            ],
            ['rames-helpers' => true, 'rames_default' => false]
        ))->selfContext();

        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame('rames_default', $context['network']);
        $this->assertSame(['8.8.8.8', '1.1.1.1'], $context['dns']);
        $this->assertSame('rames', $context['project']);
        $this->assertSame('webman', $context['service']);
        $this->assertSame('http://rames-webman:8787/healthz', $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => ['Image' => 'rames-webman', 'Labels' => [
                    'com.docker.compose.project' => 'rames',
                    'com.docker.compose.service' => 'webman',
                ]],
                'HostConfig' => ['Dns' => []],
                'NetworkSettings' => ['Networks' => ['rames_default' => ['IPAddress' => '172.18.0.2']]],
            ],
            ['rames_default' => false]
        ))->healthUrl($context));
    }

    public function testSelfContextFailsFastWhenOnlyInternalNetworksAreAttached(): void
    {
        // Tanpa network non-internal, helper tidak akan bisa `git fetch` — berhenti
        // SEBELUM repo disentuh, dengan pesan yang menyebut sebabnya.
        $context = $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => ['Image' => 'rames-webman', 'Labels' => [
                    'com.docker.compose.project' => 'rames',
                    'com.docker.compose.service' => 'webman',
                ]],
                'HostConfig' => ['Dns' => []],
                'NetworkSettings' => ['Networks' => ['rames-helpers' => ['IPAddress' => '172.20.0.2']]],
            ],
            ['rames-helpers' => true]
        ))->selfContext();

        $this->assertFalse($context['ok']);
        $this->assertSame('', $context['network']);
        $this->assertStringContainsString('internal', (string) $context['error']);
        $this->assertStringContainsString('rames-helpers', (string) $context['error']);
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
     * `UpdateService` dengan path temp + Engine palsu (selfContext tidak menyentuh
     * repo/network nyata).
     */
    private function service(DockerClient $docker): UpdateService
    {
        return new UpdateService(
            new RepoInfo($this->tmp),
            new UpdateState($this->tmp . '/check.json', $this->tmp . '/runs'),
            $docker
        );
    }

    /**
     * DockerClient palsu: hanya `inspectContainer()`/`inspectNetwork()` yang
     * dipakai `selfContext()`. `inspectNetwork()` yang tidak dikenal melempar
     * (meniru network yang tidak bisa di-inspect → status internal tidak diketahui).
     *
     * @param array<string,mixed> $inspectContainer
     * @param array<string,bool> $internal
     */
    private function fakeDocker(array $inspectContainer, array $internal): DockerClient
    {
        return new class ($inspectContainer, $internal) extends DockerClient {
            /**
             * @param array<string,mixed> $inspectContainer
             * @param array<string,bool> $internal
             */
            public function __construct(
                private readonly array $inspectContainer,
                private readonly array $internal,
            ) {
            }

            public function inspectContainer(string $id): array
            {
                return $this->inspectContainer;
            }

            public function inspectNetwork(string $id): array
            {
                if (!array_key_exists($id, $this->internal)) {
                    throw new RuntimeException('network tidak ditemukan: ' . $id);
                }

                return ['Name' => $id, 'Internal' => $this->internal[$id]];
            }
        };
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
