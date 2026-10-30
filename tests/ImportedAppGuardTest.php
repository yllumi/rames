<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Auth\AppAccessDenied;
use app\library\Backup\VolumeStateGuard;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\EnvManager;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Storage\AppStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\Attributes\DataProvider;use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

/**
 * Runner `docker compose` tiruan: setiap panggilan dicatat lalu melempar.
 *
 * Dipakai untuk membuktikan app impor **tidak pernah** menyentuh compose:
 * stop/start harus lewat Engine API, dan aksi yang butuh compose ditolak
 * sebelum runner dipanggil.
 */
final class ImportedGuardFakeCompose extends DockerComposeRunner
{
    /** @var array<int,string> */
    public array $calls = [];

    /** Sengaja tidak memanggil konstruktor induk: tidak ada proses nyata. */
    public function __construct()
    {
    }

    public function up(
        string $project,
        string $dir,
        array $files,
        bool $build = true,
        ?string $envFile = null,
        ?callable $onOutput = null
    ): void {
        $this->called('up');
    }

    public function down(string $project, string $dir, array $files, bool $volumes = true, ?string $envFile = null): void
    {
        $this->called('down');
    }

    public function stop(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->called('stop');
    }

    public function start(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->called('start');
    }

    private function called(string $method): void
    {
        $this->calls[] = $method;

        throw new RuntimeException('compose runner tidak boleh dipanggil untuk app impor (' . $method . ')');
    }
}

/** Engine tiruan: daftar container per project + catatan stop/start. */final class ImportedGuardFakeDocker extends DockerClient
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $byProject = [];

    /** @var array<int,string> */
    public array $stopped = [];

    /** @var array<int,string> */
    public array $started = [];

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket/curl. */
    public function __construct()
    {
    }

    public function listContainersForProject(string $project): array
    {
        return $this->byProject[$project] ?? [];
    }

    public function stopContainer(string $id): void
    {
        $this->stopped[] = $id;
    }

    public function startContainer(string $id): void
    {
        $this->started[] = $id;
    }
}

/**
 * Compose runner tiruan yang hanya mencatat (jalur app **non-impor**):
 * membuktikan regresi "stop/start project biasa tetap lewat compose CLI".
 */
final class ImportedGuardRecordingCompose extends DockerComposeRunner
{
    /** @var array<int,string> */
    public array $calls = [];

    /** Sengaja tidak memanggil konstruktor induk: tidak ada proses nyata. */
    public function __construct()
    {
    }

    public function stop(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = 'stop';
    }

    public function start(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = 'start';
    }
}

/**
 * Guard app impor project compose eksternal.
 *
 * Locked behaviour:
 *  1. `ImportedApp` mendeteksi origin & menyusun konteks/pesan penolakan.
 *  2. `LocalDeployer::stop()/start()` app impor memakai Engine API dan **tidak**
 *     memanggil `docker compose`.
 *  3. Aksi yang butuh orkestrasi compose (deploy/rebuild/rollback/apply/
 *     applyEnv/applyLimits/teardown) ditolak sebelum runner dipanggil.
 *  4. `AppController::delete()` app impor hanya melepas record dashboard —
 *     teardown/cleanupDir tidak dijalankan, container dibiarkan hidup.
 *
 * Tidak menyentuh Docker, `database/`, `apps/`, atau `nginx-status/` nyata:
 * jalur Engine memakai tiruan dan jalur controller memakai config/store temp.
 */
class ImportedAppGuardTest extends TestCase
{
    private const ADMIN = ['id' => 'u-admin', 'username' => 'admin', 'role' => 'admin'];

    private const MEMBER = ['id' => 'u-member', 'username' => 'member', 'role' => 'member'];

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
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('session')) {
                function session(): object
                {
                    return new class {
                        public function get(?string $key = null): mixed
                        {
                            return $key === 'user' ? ($GLOBALS['RAMES_TEST_USER'] ?? null) : null;
                        }

                        public function set(string $key, mixed $value): void
                        {
                        }

                        public function delete(string $key): void
                        {
                        }

                        public function pull(string $key): mixed
                        {
                            return null;
                        }
                    };
                }
            }
        }
        PHP;

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            SqliteDatabase::reset();
            ServerResolver::forgetMemo();
            $this->restoreConfigState($this->configState);
            self::removeTree($this->tmp);
        }
        unset($GLOBALS['RAMES_FLASH'], $GLOBALS['RAMES_TEST_USER']);
    }

    // ==================================================================
    // ImportedApp (statik murni)
    // ==================================================================

    public function testDetectsImportedOriginAndBuildsContext(): void
    {
        $app = [
            'name' => 'blog',
            'origin' => ComposeSource::ORIGIN_IMPORTED,
            'compose_dir' => '/srv/blog',
            'compose_paths' => ['/srv/blog/docker-compose.yml', ''],
            'imported_at' => '2026-10-30T10:00:00+00:00',
        ];

        $this->assertTrue(ImportedApp::isImported($app));
        $this->assertFalse(ImportedApp::isImported(['name' => 'biasa']));

        $this->assertSame([
            'imported' => true,
            'compose_dir' => '/srv/blog',
            'compose_paths' => ['/srv/blog/docker-compose.yml'],
            'imported_at' => '2026-10-30T10:00:00+00:00',
        ], ImportedApp::context($app));

        ImportedApp::assertManageable(['name' => 'biasa'], 'Deploy');
        $this->addToAssertionCount(1);
    }

    public function testAssertManageableExplainsWhyComposeIsNotAvailable(): void
    {
        $app = ['origin' => ComposeSource::ORIGIN_IMPORTED, 'compose_dir' => '/srv/blog'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Rebuild tidak tersedia untuk app yang diimpor dari project compose eksternal (direktori: /srv/blog).'
        );

        ImportedApp::assertManageable($app, 'Rebuild');
    }

    // ==================================================================
    // LocalDeployer: stop/start lewat Engine, aksi compose ditolak
    // ==================================================================

    public function testStopAndStartImportedAppUseEngineAndNeverCompose(): void
    {
        [$deployer, $compose, $docker] = $this->deployer();

        $docker->byProject['blog'] = [
            ['Id' => 'c-web', 'State' => 'running'],
            ['Id' => 'c-db', 'State' => 'exited'],
            ['Names' => ['/blog-worker-1'], 'State' => 'running'],
        ];

        $app = ['name' => 'blog', 'origin' => ComposeSource::ORIGIN_IMPORTED];

        $deployer->stop($app);
        $this->assertSame(['c-web', 'blog-worker-1'], $docker->stopped, 'container non-running dilewati');
        $this->assertSame([], $compose->calls, 'stop app impor tidak boleh memakai compose');

        $deployer->start($app);
        $this->assertSame(['c-web', 'c-db', 'blog-worker-1'], $docker->started, 'start menyalakan semua container');
        $this->assertSame([], $compose->calls, 'start app impor tidak boleh memakai compose');
    }

    /**
     * One-off (`docker compose run`) bukan bagian service project: jangan
     * dihentikan/dinyalakan saat stop/start app impor.
     */
    public function testImportedStopAndStartSkipOneOffContainers(): void
    {
        [$deployer, $compose, $docker] = $this->deployer();

        $docker->byProject['blog'] = [
            ['Id' => 'c-web', 'State' => 'running', 'Labels' => []],
            ['Id' => 'c-migrate', 'State' => 'running', 'Labels' => ['com.docker.compose.oneoff' => 'True']],
            ['Id' => 'c-seed', 'State' => 'exited', 'Labels' => ['com.docker.compose.oneoff' => 'true']],
            ['Id' => 'c-db', 'State' => 'exited', 'Labels' => []],
        ];

        $app = ['name' => 'blog', 'origin' => ComposeSource::ORIGIN_IMPORTED];

        $deployer->stop($app);
        $this->assertSame(['c-web'], $docker->stopped, 'container once-off tidak dihentikan');

        $deployer->start($app);
        $this->assertSame(['c-web', 'c-db'], $docker->started, 'container once-off tidak dinyalakan');
        $this->assertSame([], $compose->calls, 'app impor tidak menyentuh compose');
    }

    // ==================================================================
    // VolumeStateGuard: stop/start project impor lewat Engine (backup/restore)
    // ==================================================================

    /**
     * Bukti invarian backup/restore volume: app impor **tidak** pernah
     * menjalankan `docker compose stop|start` (runner tiruan melempar), dan
     * container once-off tidak ikut dihentikan/dinyalakan.
     */
    public function testVolumeStateGuardUsesEngineForImportedProjectAndSkipsOneOff(): void
    {
        [$guard, $compose, $docker, $tmp] = $this->volumeGuard([
            ['id' => 'app-imported', 'name' => 'blog', 'origin' => ComposeSource::ORIGIN_IMPORTED, 'compose_dir' => '/srv/blog', 'compose_files' => ['docker-compose.yml']],
            ['id' => 'app-local', 'name' => 'tonidata', 'compose_files' => ['docker-compose.yml']],
        ]);

        try {
            $docker->byProject['blog'] = [
                ['Id' => 'c-web', 'State' => 'running', 'Labels' => []],
                ['Id' => 'c-migrate', 'State' => 'running', 'Labels' => ['com.docker.compose.oneoff' => 'True']],
                ['Id' => 'c-db', 'State' => 'exited', 'Labels' => []],
                ['Id' => 'c-created', 'State' => 'created', 'Labels' => []],
                ['Id' => 'c-dead', 'State' => 'dead', 'Labels' => []],
                ['Id' => 'c-seed', 'State' => 'created', 'Labels' => ['com.docker.compose.oneoff' => 'true']],
            ];

            $guard->stopProject('blog');
            $this->assertSame(['c-web'], $docker->stopped, 'hanya container running non-one-off yang dihentikan');
            $this->assertSame([], $compose->calls, 'stop app impor tidak boleh memakai compose');

            $guard->startProject('blog');
            $this->assertSame(['c-db', 'c-created'], $docker->started, 'hanya container exited/created non-one-off yang dinyalakan');
            $this->assertSame([], $compose->calls, 'start app impor tidak boleh memakai compose');
        } finally {
            self::removeTree($tmp);
        }
    }

    /**
     * Regresi terkunci: app non-impor tetap memakai `docker compose stop|start`
     * (argumen tidak berubah — project + dir + compose_files).
     */
    public function testVolumeStateGuardKeepsComposeForNonImportedProject(): void
    {
        [$guard, $compose, $docker, $tmp] = $this->volumeGuard(
            [['id' => 'app-local', 'name' => 'tonidata', 'compose_files' => ['docker-compose.yml']]],
            new ImportedGuardRecordingCompose()
        );

        try {
            $docker->byProject['tonidata'] = [
                ['Id' => 'c-web', 'State' => 'running', 'Labels' => []],
            ];

            $guard->stopProject('tonidata');
            $guard->startProject('tonidata');

            $this->assertSame(['stop', 'start'], $compose->calls, 'app non-impor tetap lewat compose CLI');
            $this->assertSame([], $docker->stopped, 'jalur non-impor tidak memanggil stopContainer');
            $this->assertSame([], $docker->started, 'jalur non-impor tidak memanggil startContainer');
        } finally {
            self::removeTree($tmp);
        }
    }

    /**
     * @param array<string,array{0:string,1:string}> $cases
     */
    #[DataProvider('composeActionProvider')]
    public function testComposeActionsAreRejectedBeforeRunnerIsCalled(string $method, string $feature): void
    {
        [$deployer, $compose] = $this->deployer();
        $app = ['name' => 'blog', 'origin' => ComposeSource::ORIGIN_IMPORTED, 'compose_dir' => '/srv/blog'];

        try {
            if ($method === 'teardown') {
                $deployer->teardown($app);
            } else {
                $deployer->{$method}($app, static function (string $stage, string $message): void {
                });
            }
            $this->fail($method . ' harus ditolak untuk app impor');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($feature, $e->getMessage());
            $this->assertStringContainsString('project compose eksternal', $e->getMessage());
        }

        $this->assertSame([], $compose->calls, $method . ' tidak boleh memanggil compose');
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function composeActionProvider(): array
    {
        return [
            'deploy' => ['deploy', 'Deploy'],
            'rebuild' => ['rebuild', 'Rebuild'],
            'apply' => ['apply', 'Apply compose'],
            'applyEnv' => ['applyEnv', 'Terapkan environment'],
            'applyLimits' => ['applyLimits', 'Terapkan batas resource'],
            'teardown' => ['teardown', 'Hapus app lewat compose'],
        ];
    }

    public function testRollbackIsRejectedForImportedApp(): void
    {
        [$deployer, $compose] = $this->deployer();
        $app = ['name' => 'blog', 'origin' => ComposeSource::ORIGIN_IMPORTED];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rollback tidak tersedia/');

        try {
            $deployer->rollback($app, 'abcdef1', static function (string $stage, string $message): void {
            });
        } finally {
            $this->assertSame([], $compose->calls);
        }
    }

    // ==================================================================
    // Otorisasi /apps/import — non-admin selalu 404, tanpa Engine
    // ==================================================================

    /**
     * GET /apps/import oleh non-admin: `AppAccessDenied` (404) **sebelum**
     * `DockerClient`/discovery dibuat — socket Engine di config sengaja tidak ada.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportFormRejectsNonAdminWith404(): void
    {
        $this->bootstrapController();
        $this->installStubs();
        $GLOBALS['RAMES_TEST_USER'] = self::MEMBER;

        $request = new Request("GET /apps/import HTTP/1.1\r\nHost: localhost\r\n\r\n");

        try {
            (new AppController())->importForm($request);
            $this->fail('halaman impor harus 404 untuk non-admin');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode(), 'penolakan memakai 404, bukan 403');
        }

        $this->assertSame([], (new AppStore())->all(), 'tidak ada app yang ditulis');
        $this->assertFileDoesNotExist(
            $this->tmp . '/tidak-ada.sock',
            'Docker Engine tidak boleh disentuh (socket tidak pernah dipakai)'
        );
    }

    /**
     * POST /apps/import oleh non-admin: `AppAccessDenied` (404) — bukan redirect
     * berisi pesan Engine (yang terjadi bila cek otorisasi tertinggal di belakang).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportStoreRejectsNonAdminWith404(): void
    {
        $this->bootstrapController();
        $this->installStubs();
        $GLOBALS['RAMES_TEST_USER'] = self::MEMBER;

        $request = new Request(
            "POST /apps/import HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\n\r\n"
            . 'project=blog&primary=web'
        );

        try {
            (new AppController())->importStore($request);
            $this->fail('impor harus 404 untuk non-admin');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode(), 'penolakan memakai 404, bukan 403');
        }

        $this->assertSame([], (new AppStore())->all(), 'tidak ada record app yang dibuat');
        $this->assertSame([], $GLOBALS['RAMES_FLASH'], 'tidak ada flash error Engine (Engine tidak dipanggil)');
    }

    // ==================================================================
    // AppController::delete — lepas record, container dibiarkan
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteOfImportedAppOnlyRemovesDashboardRecord(): void
    {
        $this->bootstrapController();
        $this->installStubs();

        $app = (new AppStore())->create([
            'name' => 'blog',
            'origin' => ComposeSource::ORIGIN_IMPORTED,
            'compose_dir' => '/srv/blog',
            'compose_paths' => ['/srv/blog/docker-compose.yml'],
            'status' => 'running',
        ]);

        // Artefak yang diuji: direktori proyek user (TIDAK boleh disentuh),
        // vhost app (dihapus), dan managed env (dihapus).
        mkdir($this->tmp . '/apps/blog', 0777, true);
        file_put_contents($this->tmp . '/apps/blog/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        $nginxConf = $this->tmp . '/nginx/conf/blog.conf';
        file_put_contents($nginxConf, "# vhost\n");
        file_put_contents($this->tmp . '/database/env/blog.env', "FOO=bar\n");

        $request = new Request("POST /apps/{$app['id']}/delete HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = (new AppController())->delete($request, $app['id']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/apps', $response->getHeader('Location'));
        $this->assertNull((new AppStore())->find($app['id']), 'record app terhapus');

        $this->assertFileExists(
            $this->tmp . '/apps/blog/docker-compose.yml',
            'direktori proyek user tidak boleh dihapus (tanpa teardown/cleanupDir)'
        );
        $this->assertFileDoesNotExist($nginxConf, 'vhost app dibuang');
        $this->assertFileDoesNotExist($this->tmp . '/database/env/blog.env', 'managed env dibuang');

        $flash = $GLOBALS['RAMES_FLASH'];
        $this->assertCount(1, $flash);
        $this->assertSame('success', $flash[0]['type']);
        $this->assertStringContainsString('dilepas dari dashboard', $flash[0]['message']);
        $this->assertStringContainsString('TIDAK dihentikan/dihapus', $flash[0]['message']);
        $this->assertStringContainsString('terminal proyek', $flash[0]['message']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @return array{0:LocalDeployer,1:ImportedGuardFakeCompose,2:ImportedGuardFakeDocker}
     */
    private function deployer(): array
    {
        $compose = new ImportedGuardFakeCompose();
        $docker = new ImportedGuardFakeDocker();

        $deployer = new LocalDeployer(
            $compose,
            $docker,
            new NginxConfigGenerator(sys_get_temp_dir() . '/rames-imported-nginx-' . bin2hex(random_bytes(4))),
            sys_get_temp_dir() . '/rames-imported-apps-' . bin2hex(random_bytes(4)),
            new EnvManager(sys_get_temp_dir() . '/rames-imported-env-' . bin2hex(random_bytes(4))),
            new NetworkManager()
        );

        return [$deployer, $compose, $docker];
    }

    /**
     * Guard volume dengan store + Engine tiruan (path store TEMP, bukan data
     * runtime nyata). Runner compose default **melempar** bila dipanggil
     * (bukti app impor tidak menyentuh compose).
     *
     * @param array<int,array> $apps
     * @return array{0:VolumeStateGuard,1:ImportedGuardFakeCompose|ImportedGuardRecordingCompose,2:ImportedGuardFakeDocker,3:string}
     */
    private function volumeGuard(array $apps, ?DockerComposeRunner $compose = null): array
    {
        $tmp = sys_get_temp_dir() . '/rames-imported-vsg-' . bin2hex(random_bytes(5));
        mkdir($tmp . '/apps', 0777, true);
        SqliteFixture::apps($tmp . '/apps.sqlite', $apps);

        $docker = new ImportedGuardFakeDocker();
        $compose ??= new ImportedGuardFakeCompose();

        $guard = new VolumeStateGuard(
            $docker,
            $compose,
            new AppStore($tmp . '/apps.sqlite'),
            $tmp . '/apps',
            new EnvManager($tmp . '/env'),
        );

        return [$guard, $compose, $docker, $tmp];
    }

    private function bootstrapController(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-imported-guard-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'database/env', 'sessions', 'logs/deploy'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0777, true);
        }

        $GLOBALS['RAMES_FLASH'] = [];
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    private function installStubs(): void
    {
        $GLOBALS['RAMES_TEST_USER'] = self::ADMIN;
        $GLOBALS['RAMES_FLASH'] = [];

        if (!function_exists('flash_set')) {
            $file = $this->tmp . '/stubs.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
        }

        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }
        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        ini_set('session.save_path', $this->tmp . '/sessions');
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'database_path' => $this->tmp . '/database',
            'nginx_conf_path' => $this->tmp . '/nginx/conf',
            'nginx_enabled_path' => '',
            'app_domain' => 'example.test',
            'port_range' => ['start' => 30000, 'end' => 30999],
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
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

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
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
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
