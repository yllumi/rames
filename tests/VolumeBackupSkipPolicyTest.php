<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupReport;
use app\library\Backup\BackupRunLock;
use app\library\Backup\BackupSelection;
use app\library\Backup\DumpRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Db\DbContainerDetector;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Fake DockerClient — volume ber-label compose + container non-DB `running`.
 */
class VolumeSkipFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array> $volumes
     * @param array<int,array> $containers
     */
    public function __construct(private array $volumes = [], private array $containers = [])
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function listVolumes(array $filters = []): array
    {
        return $this->volumes;
    }

    public function listContainers(array $filters = []): array
    {
        return $this->containers;
    }

    public function inspectContainer(string $id): array
    {
        return ['Config' => ['Image' => 'nginx:alpine']];
    }
}

/**
 * Fake detektor DB — volume uji bukan DB (strategi = `snapshot`).
 */
class VolumeSkipFakeDbDetector extends DbContainerDetector
{
    public function __construct()
    {
        // sengaja tidak memanggil parent.
    }

    public function isDbContainer(array $inspect): bool
    {
        return false;
    }
}

/**
 * Fake compose runner — merekam stop/start (seharusnya TIDAK dipanggil saat policy `skip`).
 */
class VolumeSkipFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,array> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 5);
    }

    public function stop(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = ['stop', $project];
    }

    public function start(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = ['start', $project];
    }
}

/**
 * Test `VolumeBackupService` — policy `skip`: volume dengan container `running`
 * ditandai **`skipped`** (bukan `failed`), tanpa stop/start dan tanpa restic
 * (temuan #4; PLAN_VOLUME_BACKUP.md §2, §7).
 *
 * Tanpa Engine/Docker/restic nyata; seluruh state di direktori temp
 * (larangan #15: jangan sentuh data runtime nyata).
 */
class VolumeBackupSkipPolicyTest extends TestCase
{
    private string $tmp;
    private VolumeSkipFakeComposeRunner $compose;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volskip_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        SqliteFixture::apps($this->tmp . '/apps.sqlite', [[
            'id' => 'app1',
            'name' => 'tonidata',
            'compose_files' => ['docker-compose.yml'],
        ]]);
        $this->compose = new VolumeSkipFakeComposeRunner();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    public function testRunningContainerIsSkippedNotFailedUnderSkipPolicy(): void
    {
        $docker = new VolumeSkipFakeDockerClient(
            [['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']]],
            [[
                'Id' => 'c1',
                'Names' => ['/tonidata-web-1'],
                'State' => 'running',
                'Labels' => ['com.docker.compose.project' => 'tonidata'],
            ]]
        );

        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $guard = new VolumeStateGuard(
            $docker,
            $this->compose,
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env'),
            new VolumeSkipFakeDbDetector(),
        );

        $service = new VolumeBackupService(
            $docker,
            $apps,
            $guard,
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            new ProcessRunner(),
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->tmp . '/report'),
            [],
            null,
            null,
            'skip',
            // Isolasi larangan #15: seleksi (backfill) tidak boleh menyentuh
            // `database/backup.json` nyata — arahkan ke path temp tes.
            selection: new BackupSelection($this->tmp . '/backup.sqlite'),
        );

        $run = $service->run(['trigger' => 'manual', 'volumes' => ['tonidata_data']]);

        $this->assertSame(1, $run['totals']['skipped']);
        $this->assertSame(0, $run['totals']['failed'], 'container hidup + policy skip bukan kegagalan');
        $this->assertSame('skipped', $run['volumes'][0]['status']);
        $this->assertStringContainsString('skip', (string) $run['volumes'][0]['error']);
        $this->assertSame('manual', $run['trigger']);
        $this->assertSame([], $this->compose->calls, 'policy skip tidak boleh menghentikan container');
    }
}
