<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentArtifactMaterializer;
use app\library\Deploy\ComposeSource;
use app\library\Git\GitService;
use app\library\Support\ProcessRunner;
use app\library\Template\TemplateCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `GitService` tiruan: merekam panggilan `clone()` tanpa menjalankan git nyata,
 * dan dapat dibuat gagal (`$fail`) untuk menguji jalur clone gagal.
 */
final class MaterializerFakeGit extends GitService
{
    /** @var array<int,array{repo:string,branch:string,dest:string,sshKey:?string}> */
    public array $clones = [];

    public bool $fail = false;
    public string $failMessage = 'Gagal clone repo: fatal: repository not found';

    /** Sengaja tidak memanggil konstruktor induk: tidak ada proses yang dijalankan. */
    public function __construct()
    {
    }

    public function clone(string $repoUrl, string $branch, string $dest, ?string $sshKeyPath = null): void
    {
        $this->clones[] = [
            'repo' => $repoUrl,
            'branch' => $branch,
            'dest' => $dest,
            'sshKey' => $sshKeyPath,
        ];

        if ($this->fail) {
            throw new RuntimeException($this->failMessage);
        }

        if (!is_dir($dest) && !mkdir($dest, 0777, true) && !is_dir($dest)) {
            throw new RuntimeException('gagal membuat direktori clone tiruan');
        }
        file_put_contents($dest . '/clone.txt', "clone tiruan\n");
    }
}

/**
 * `ProcessRunner` tiruan untuk probe `git remote get-url origin` (tanpa proses):
 * remote per direktori diatur lewat {@see self::$remotes}.
 */
final class MaterializerFakeRunner extends ProcessRunner
{
    /** @var array<string,string> direktori ⇒ URL remote `origin` */
    public array $remotes = [];

    /** @var array<int,array<int,string>> */
    public array $commands = [];

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = $command;
        $dir = (string) ($command[2] ?? '');
        $remote = $this->remotes[$dir] ?? null;

        return $remote === null
            ? ['code' => 128, 'stdout' => '', 'stderr' => 'fatal: not a git repository', 'timedOut' => false]
            : ['code' => 0, 'stdout' => $remote . "\n", 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Test `AgentArtifactMaterializer` — sisi **agent** misi `ms-f1-artifacts`.
 *
 * Yang dikunci (plan §4.3 + §11 catatan 4 & 6):
 *  1. file compose ditulis ke `apps_path/<name>/<compose_files[0]>` (lokasi yang
 *     dibaca `LocalDeployer`/`ComposeSource`, bukan tebakan);
 *  2. deploy key ditulis ke path yang dibaca `LocalDeployer::appSshKeyPath()`
 *     (`{database_path}/{ssh_key}`) dengan berkas `0600` di direktori `0700`;
 *  3. app ber-template di-materialisasi dari katalog template **agent**
 *     (`templates/<slug>/`) lewat jalur yang sama dengan control plane;
 *  4. **fail-fast, tidak menimpa diam-diam**: direktori milik app lain / bukan
 *     milik siapa pun ⇒ melempar tanpa mengubah isi; template tidak ada ⇒
 *     melempar; app mode compose tanpa file compose ⇒ melempar **sebelum**
 *     menulis apa pun;
 *  5. idempoten: job ulang tidak menghapus artefak yang sudah ada;
 *  6. bentuk/tipe `artifacts` divalidasi (kunci asing, nama berbahaya) dan
 *     rahasia disanitasi dari pesan galat.
 *
 * Seluruh uji memakai direktori **temp** — tidak menyentuh `apps/`, `database/`,
 * atau `runtime/` nyata, tanpa docker/git.
 */
class AgentArtifactMaterializerTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';
    private const KEY_PEM = "-----BEGIN OPENSSH PRIVATE KEY-----\nZml4dHVyZS1rZXktaXNpLXJhaGFzaWE=\n-----END OPENSSH PRIVATE KEY-----\n";
    private const TEMPLATE_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n    environment:\n      APP_SECRET: \${APP_SECRET}\n    ports:\n      - \"80\"\n";

    private string $tmp = '';
    private AgentArtifactMaterializer $materializer;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-materializer-' . bin2hex(random_bytes(4));
        foreach (['/apps', '/database/keys', '/templates/uji/files/conf.d'] as $dir) {
            if (!mkdir($this->tmp . $dir, 0777, true) && !is_dir($this->tmp . $dir)) {
                throw new RuntimeException('gagal menyiapkan direktori temp');
            }
        }

        // Template fixture (bentuk sama dengan templates/ di repo).
        file_put_contents($this->tmp . '/templates/uji/template.yml', implode("\n", [
            'title: Template Uji',
            'description: Fixture uji materialisasi.',
            'primary:',
            '  service: web',
            '  port: 80',
            'env:',
            '  - key: APP_SECRET',
            '    label: Secret',
            '    generate: secret',
            'files:',
            '  - conf.d/custom.conf',
            '',
        ]));
        file_put_contents($this->tmp . '/templates/uji/docker-compose.yml', self::TEMPLATE_COMPOSE);
        file_put_contents($this->tmp . '/templates/uji/files/conf.d/custom.conf', "server {\n    listen 80;\n}\n");

        $this->materializer = $this->materializer();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Mode compose
    // ==================================================================

    /**
     * Override port pilihan user (dua lapis) ditulis materializer ke direktori app
     * agent — satu-satunya jalur yang membuat pilihan host port user berlaku di
     * server agent (misi F1 celah B), karena control plane tidak menulis berkas
     * itu di host-nya untuk app agent.
     */
    public function testPortOverrideFilesAreWrittenToTheAgentAppDir(): void
    {
        $reset = "services:\n  web:\n    ports: !reset\n      {  }\n";
        $ports = "services:\n  web:\n    ports:\n      - '30500:80'\n";

        $this->materializer->materialize(
            $this->app(['source' => 'compose', 'compose_files' => ['docker-compose.yml']]),
            [
                'compose' => "services:\n  web:\n    image: nginx:alpine\n",
                'compose_name' => 'docker-compose.yml',
                'files' => [
                    ComposeSource::RESET_OVERRIDE_FILE => $reset,
                    ComposeSource::PORTS_OVERRIDE_FILE => $ports,
                ],
            ]
        );

        $this->assertSame($reset, file_get_contents($this->tmp . '/apps/demo/' . ComposeSource::RESET_OVERRIDE_FILE));
        $this->assertSame($ports, file_get_contents($this->tmp . '/apps/demo/' . ComposeSource::PORTS_OVERRIDE_FILE));

        // Validasi bentuk mengembalikan berkas apa adanya (kontrak lintas-sisi).
        $validated = AgentArtifactMaterializer::validate([
            'files' => [
                ComposeSource::RESET_OVERRIDE_FILE => $reset,
                ComposeSource::PORTS_OVERRIDE_FILE => $ports,
            ],
        ]);
        $this->assertSame(
            [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            array_keys($validated['files'])
        );
    }

    public function testComposeIsWrittenToTheDeclaredMainFileName(): void
    {
        $content = "services:\n  web:\n    image: nginx:alpine\n";

        $this->materializer->materialize(
            $this->app(['source' => 'compose', 'compose_files' => ['compose.yaml']]),
            ['compose' => $content, 'compose_name' => 'compose.yaml']
        );

        $this->assertSame($content, file_get_contents($this->tmp . '/apps/demo/compose.yaml'));
        $this->assertFileExists($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE);
    }

    public function testComposeWithoutNameFallsBackToDockerComposeYml(): void
    {
        $content = "services:\n  web:\n    image: nginx:alpine\n";

        $this->materializer->materialize(
            $this->app(['source' => 'compose', 'compose_files' => null]),
            ['compose' => $content]
        );

        $this->assertSame($content, file_get_contents($this->tmp . '/apps/demo/docker-compose.yml'));
    }

    /** Compose tak sah (tanpa `services`) ditolak — bukan ditulis lalu gagal saat deploy. */
    public function testInvalidComposeIsRejectedBeforeWriting(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('services');
        $this->materializer->materialize(
            $this->app(['source' => 'compose']),
            ['compose' => "version: '3'\n", 'compose_name' => 'docker-compose.yml']
        );
    }

    /**
     * Compose tak sah + artefak lain ⇒ semuanya ditolak **sebelum** kunci
     * ditulis / clone dijalankan (tidak ada artefak setengah jadi).
     */
    public function testInvalidComposeIsRejectedBeforeKeyWriteAndClone(): void
    {
        $git = new MaterializerFakeGit();
        $materializer = $this->materializer($git, new MaterializerFakeRunner());
        $app = $this->app([
            'source' => 'compose',
            'repo_url' => 'https://example.test/app.git',
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
        ]);

        try {
            $materializer->materialize($app, ['compose' => "version: '3'\n", 'ssh_key' => self::KEY_PEM]);
            $this->fail('compose tanpa service harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('services', $e->getMessage());
        }

        $this->assertSame([], $git->clones, 'clone tidak boleh dijalankan');
        $this->assertFileDoesNotExist($this->tmp . '/database/keys/demo', 'deploy key tidak boleh ditulis');
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
    }

    // ==================================================================
    // Deploy key SSH
    // ==================================================================

    public function testSshKeyIsWrittenAtTheReaderPathWith0600Inside0700(): void
    {
        $this->materializer->materialize(
            $this->app(['source' => 'git', 'auth_method' => 'ssh', 'ssh_key' => 'keys/demo']),
            ['ssh_key' => self::KEY_PEM]
        );

        $keyPath = $this->tmp . '/database/keys/demo';
        $this->assertFileExists($keyPath);
        $this->assertSame(self::KEY_PEM, file_get_contents($keyPath));
        $this->assertSame(0600, fileperms($keyPath) & 0777, 'private key wajib 0600');
        $this->assertSame(0700, fileperms(dirname($keyPath)) & 0777, 'direktori kunci wajib 0700');
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo', 'app repo tanpa artefak lain tidak menyentuh apps/');
    }

    /** App tanpa `ssh_key` ⇒ lokasi kanonik `{keys_dir}/{name}` (pola SshKeyManager). */
    public function testSshKeyWithoutRelativePathUsesCanonicalKeysDir(): void
    {
        $this->materializer->materialize(
            $this->app(['source' => 'git', 'auth_method' => 'ssh', 'ssh_key' => null]),
            ['ssh_key' => self::KEY_PEM]
        );

        $this->assertFileExists($this->tmp . '/database/keys/demo');
    }

    /** Artefak kunci saja: `deploy.apps_path` tidak dipakai sama sekali. */
    public function testKeyOnlyJobDoesNotNeedAppsPath(): void
    {
        $materializer = new AgentArtifactMaterializer(
            '',
            $this->tmp . '/database',
            $this->tmp . '/database/keys',
            new TemplateCatalog($this->tmp . '/templates')
        );

        $materializer->materialize(
            $this->app(['source' => 'git', 'auth_method' => 'ssh', 'ssh_key' => 'keys/demo']),
            ['ssh_key' => self::KEY_PEM]
        );

        $this->assertFileExists($this->tmp . '/database/keys/demo');
    }

    public function testKeyIsReplacedAtomicallyAndKeepsMode0600OnSecondJob(): void
    {
        $app = $this->app(['source' => 'git', 'auth_method' => 'ssh', 'ssh_key' => 'keys/demo']);
        $this->materializer->materialize($app, ['ssh_key' => self::KEY_PEM]);
        $this->materializer->materialize($app, ['ssh_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nYmFydQ==\n-----END OPENSSH PRIVATE KEY-----\n"]);

        $keyPath = $this->tmp . '/database/keys/demo';
        $this->assertStringContainsString('YmFydQ==', (string) file_get_contents($keyPath));
        $this->assertSame(0600, fileperms($keyPath) & 0777);
        $this->assertSame([], glob($this->tmp . '/database/keys/*.tmp*') ?: [], 'tidak ada sisa berkas sementara');
    }

    // ==================================================================
    // Template
    // ==================================================================

    public function testTemplateAppIsMaterializedFromAgentsOwnCatalog(): void
    {
        $this->materializer->materialize(
            $this->app(['source' => 'compose', 'template' => ['slug' => 'uji']]),
            ['template' => 'uji']
        );

        $this->assertSame(self::TEMPLATE_COMPOSE, file_get_contents($this->tmp . '/apps/demo/docker-compose.yml'));
        $this->assertSame(
            "server {\n    listen 80;\n}\n",
            file_get_contents($this->tmp . '/apps/demo/conf.d/custom.conf')
        );
    }

    public function testMissingTemplateFailsFastWithoutWritingAnything(): void
    {
        try {
            $this->materializer->materialize(
                $this->app(['source' => 'compose', 'template' => ['slug' => 'tidak-ada']]),
                ['template' => 'tidak-ada']
            );
            $this->fail('template yang tidak ada di checkout agent harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak-ada', $e->getMessage());
            $this->assertStringContainsString('tidak bisa dipakai di server agent ini', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
    }

    // ==================================================================
    // Fail-fast: direktori milik siapa
    // ==================================================================

    public function testForeignAppDirectoryIsNeverOverwritten(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE, '{"app_id":"app-lain","name":"demo"}');
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  lain:\n    image: nginx:alpine\n");

        try {
            $this->materializer->materialize(
                $this->app(['source' => 'compose']),
                ['compose' => "services:\n  web:\n    image: nginx:alpine\n", 'compose_name' => 'docker-compose.yml']
            );
            $this->fail('direktori milik app lain harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dipakai app lain', $e->getMessage());
        }

        $this->assertStringContainsString('lain', (string) file_get_contents($this->tmp . '/apps/demo/docker-compose.yml'));
    }

    public function testDirectoryWithoutMarkerIsNotSilentlyReused(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents($this->tmp . '/apps/demo/penting.yml', 'jangan hilang');

        try {
            $this->materializer->materialize(
                $this->app(['source' => 'compose']),
                ['compose' => "services:\n  web:\n    image: nginx:alpine\n", 'compose_name' => 'docker-compose.yml']
            );
            $this->fail('direktori non-kosong tanpa penanda harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bukan milik app ini', $e->getMessage());
        }

        $this->assertFileExists($this->tmp . '/apps/demo/penting.yml');
        $this->assertFileDoesNotExist($this->tmp . '/apps/demo/docker-compose.yml');
    }

    /** Direktori kosong (sisa job gagal) boleh dipakai ulang. */
    public function testEmptyDirectoryIsReused(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);

        $this->materializer->materialize(
            $this->app(['source' => 'compose']),
            ['compose' => "services:\n  web:\n    image: nginx:alpine\n", 'compose_name' => 'docker-compose.yml']
        );

        $this->assertFileExists($this->tmp . '/apps/demo/docker-compose.yml');
    }

    // ==================================================================
    // Siklus hidup artefak: direktori sisa app yang sudah dihapus & forget()
    // ==================================================================

    /**
     * Direktori sisa app lain (penanda id berbeda) dibuang lebih dulu oleh
     * pemanggil — `AgentJobController` memanggilnya sesudah proyeksi menjamin
     * nama itu milik app ini. Tanpa ini materialisasi app dengan nama sama
     * selalu ditolak penanda kepemilikan.
     */
    public function testDropStaleDirRemovesDirectoryOwnedByAnotherAppId(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents(
            $this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE,
            '{"app_id":"app-lama","name":"demo"}'
        );
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  lama:\n    image: nginx:alpine\n");

        $app = $this->app(['source' => 'compose']);

        $this->assertTrue($this->materializer->dropStaleDir($app));
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');

        // Materialisasi app ini sesudahnya berjalan normal & penandanya milik app ini.
        $this->materializer->materialize($app, [
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_name' => 'docker-compose.yml',
        ]);

        $marker = json_decode(
            (string) file_get_contents($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE),
            true
        );
        $this->assertSame(self::APP_ID, $marker['app_id'] ?? null);
        $this->assertStringContainsString('web:', (string) file_get_contents($this->tmp . '/apps/demo/docker-compose.yml'));
    }

    /** Direktori milik app ini, tanpa penanda, atau belum ada: tidak disentuh. */
    public function testDropStaleDirNeverTouchesOwnMarkerlessOrMissingDirectories(): void
    {
        $app = $this->app(['source' => 'compose']);

        $this->assertFalse($this->materializer->dropStaleDir($app), 'direktori belum ada');

        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents($this->tmp . '/apps/demo/penting.yml', 'jangan hilang');
        $this->assertFalse($this->materializer->dropStaleDir($app), 'tanpa penanda = data tak dikenal');
        $this->assertFileExists($this->tmp . '/apps/demo/penting.yml');

        file_put_contents(
            $this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE,
            json_encode(['app_id' => self::APP_ID, 'name' => 'demo'])
        );
        $this->assertFalse($this->materializer->dropStaleDir($app), 'sudah milik app ini');
        $this->assertFileExists($this->tmp . '/apps/demo/penting.yml');
    }

    // ==================================================================
    // Preflight ketersediaan nama app (appDirState) — read-only
    // ==================================================================

    /**
     * Direktori belum ada / kosong = `free`: nama app masih bebas. Direktori
     * kosong sengaja ikut "bebas" karena itu sisa job yang gagal (perilaku yang
     * sama dengan `ensureOwnedDir()`).
     */
    public function testAppDirStateReportsFreeForMissingAndEmptyDirectory(): void
    {
        $app = $this->app(['source' => 'compose']);

        $state = $this->materializer->appDirState($app);
        $this->assertSame('free', $state['state']);
        $this->assertTrue($state['available']);
        $this->assertSame('', $state['message']);

        mkdir($this->tmp . '/apps/demo', 0755, true);
        $state = $this->materializer->appDirState($app);
        $this->assertSame('free', $state['state'], 'direktori kosong = sisa job gagal, boleh dipakai');
        $this->assertTrue($state['available']);
        $this->assertSame('', $state['message']);
    }

    /** Penanda milik app **ini** ⇒ `owned` (deploy ulang app yang sama = aman). */
    public function testAppDirStateReportsOwnedWhenMarkerBelongsToThisApp(): void
    {
        $app = $this->app(['source' => 'compose']);
        $this->materializer->materialize($app, [
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_name' => 'docker-compose.yml',
        ]);

        $state = $this->materializer->appDirState($app);

        $this->assertSame('owned', $state['state']);
        $this->assertTrue($state['available']);
        $this->assertSame('', $state['message']);
    }

    /**
     * Penanda milik id app **lain** ⇒ `stale`: sisa app yang sudah dihapus di
     * control plane. Preflight menganggapnya bebas karena `dropStaleDir()` (jalur
     * job) yang membuangnya — bukan preflight (tanpa mutasi).
     */
    public function testAppDirStateReportsStaleForAnotherAppsMarker(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents(
            $this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE,
            '{"app_id":"app-lama","name":"demo"}'
        );
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  lama:\n    image: nginx:alpine\n");

        $state = $this->materializer->appDirState($this->app(['source' => 'compose']));

        $this->assertSame('stale', $state['state']);
        $this->assertTrue($state['available']);
        $this->assertSame('', $state['message']);

        // Tanpa mutasi: direktori sisa tetap apa adanya (dibuang nanti oleh jalur job).
        $this->assertDirectoryExists($this->tmp . '/apps/demo');
        $this->assertStringContainsString(
            'lama:',
            (string) file_get_contents($this->tmp . '/apps/demo/docker-compose.yml')
        );
    }

    /**
     * Direktori non-kosong **tanpa** penanda = data tak dikenal ⇒ `blocked`:
     * tidak tersedia, pesannya actionable (sebut `apps/<name>` + tindakan manual),
     * dan **tidak ada** yang dihapus/ditulis.
     */
    public function testAppDirStateBlocksMarkerlessNonEmptyDirectoryWithActionableMessageAndNoMutation(): void
    {
        mkdir($this->tmp . '/apps/demo/conf.d', 0755, true);
        file_put_contents($this->tmp . '/apps/demo/penting.yml', 'jangan hilang');
        file_put_contents($this->tmp . '/apps/demo/conf.d/site.conf', "server { listen 80; }\n");

        $before = $this->snapshotTree($this->tmp . '/apps/demo');

        $state = $this->materializer->appDirState($this->app(['source' => 'compose']));

        $this->assertSame('blocked', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertStringContainsString('apps/demo', $state['message'], 'pesannya menyebut direktori yang bentrok');
        $this->assertStringContainsString('pindahkan atau hapus', $state['message'], 'tindakan manual harus jelas');
        $this->assertStringContainsString('deploy ulang', $state['message']);
        $this->assertStringContainsString('tidak pernah menghapus', $state['message'], 'data tak dikenal tidak dihapus otomatis');
        $this->assertFileDoesNotExist($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE);

        // Bukti read-only: seluruh isi (termasuk subdirektori) identik sebelum/sesudah.
        $this->assertSame($before, $this->snapshotTree($this->tmp . '/apps/demo'));
    }

    /**
     * Direktori berisi penanda **tanpa** `app_id` yang terbaca juga diperlakukan
     * `blocked` (data tak dikenal) — bukan `owned`/`stale` yang menebak pemiliknya.
     */
    public function testAppDirStateTreatsUnreadableMarkerOwnershipAsBlocked(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE, '{"name":"demo"}');

        $state = $this->materializer->appDirState($this->app(['source' => 'compose']));

        $this->assertSame('blocked', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertNotSame('', $state['message']);
    }

    /** Nama app tak sah dibiarkan melempar (pemanggil menerjemahkannya jadi 400). */
    public function testAppDirStateRejectsUnsafeAppName(): void
    {
        foreach (['', '  ', '../demo', 'a/b'] as $name) {
            try {
                $this->materializer->appDirState(['id' => self::APP_ID, 'name' => $name]);
                $this->fail("nama app \"{$name}\" harus ditolak");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('name', $e->getMessage());
            }
        }
    }

    /**
     * Fix R-2: direktori **tanpa penanda** yang berisi checkout git dengan remote
     * origin **sama** dengan `repo_url` payload ⇒ `owned`/`available` — cermin
     * `ensureRepoClone()` yang menerima remote sama (tidak clone ulang) sehingga
     * jalur job akan lanjut. Probe remote read-only (isi & mtime tak berubah).
     */
    public function testAppDirStateTreatsMarkerlessCheckoutWithTheSameRemoteAsOwned(): void
    {
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer(new MaterializerFakeGit(), $runner);

        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0755, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        $runner->remotes[$dir] = 'https://example.test/app.git';
        $before = $this->snapshotTree($dir);

        // Bentuk URL berbeda (akhiran `.git`/garis miring) ⇒ dinormalisasi sama.
        $state = $materializer->appDirState([
            'id' => self::APP_ID,
            'name' => 'demo',
            'repo_url' => 'https://example.test/app',
        ]);

        $this->assertSame('owned', $state['state']);
        $this->assertTrue($state['available']);
        $this->assertSame('', $state['message']);
        $this->assertSame(['git', '-C', $dir, 'remote', 'get-url', 'origin'], $runner->commands[0] ?? null);
        $this->assertSame($before, $this->snapshotTree($dir), 'probe remote tidak mengubah apa pun');
    }

    /**
     * Fix R-2: checkout git tanpa penanda tapi remote origin **berbeda** ⇒
     * `blocked` dengan pesan yang menyebut repo lain (cermin `ensureRepoClone()`)
     * + tindakan manual. Data tidak disentuh.
     */
    public function testAppDirStateBlocksMarkerlessCheckoutWithADifferentRemote(): void
    {
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer(new MaterializerFakeGit(), $runner);

        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0755, true);
        file_put_contents($dir . '/penting.yml', 'jangan hilang');
        $runner->remotes[$dir] = 'https://example.test/repo-lain.git';
        $before = $this->snapshotTree($dir);

        $state = $materializer->appDirState([
            'id' => self::APP_ID,
            'name' => 'demo',
            'repo_url' => 'https://example.test/app.git',
        ]);

        $this->assertSame('blocked', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertStringContainsString('repo-lain', $state['message'], 'repo lain wajib disebut (cermin pesan ensureRepoClone)');
        $this->assertStringContainsString('apps/demo', $state['message']);
        $this->assertStringContainsString('deploy ulang', $state['message']);
        $this->assertSame($before, $this->snapshotTree($dir), 'read-only');
    }

    /**
     * Fix R-2: tanpa `repo_url` di payload, perilaku lama dipertahankan (tanpa
     * probe git sama sekali) — pemanggil lama tidak berubah, dan preflight tidak
     * menebak-nebak remote.
     */
    public function testAppDirStateWithoutRepoUrlNeverProbesGitAndStaysBlocked(): void
    {
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer(new MaterializerFakeGit(), $runner);

        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0755, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        $runner->remotes[$dir] = 'https://example.test/app.git';

        $state = $materializer->appDirState(['id' => self::APP_ID, 'name' => 'demo']);

        $this->assertSame('blocked', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertSame([], $runner->commands, 'tanpa repo_url tidak ada proses git yang dijalankan');
    }

    /**
     * Pesan `blocked` untuk repo lain **tidak** menggemakan kredensial yang
     * tertanam di URL repo (`https://user:token@host/…`) — pesan itu tampil di
     * wizard, dan URL private sering ditulis dengan kredensial di dalamnya.
     */
    public function testAppDirStateRepoMessageNeverEchoesUrlCredentials(): void
    {
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer(new MaterializerFakeGit(), $runner);

        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0755, true);
        file_put_contents($dir . '/penting.yml', 'jangan hilang');
        $runner->remotes[$dir] = 'https://user:rahasia@example.test/repo-lain.git';

        $state = $materializer->appDirState([
            'id' => self::APP_ID,
            'name' => 'demo',
            'repo_url' => 'https://user:rahasia-lain@example.test/app.git',
        ]);

        $this->assertSame('blocked', $state['state']);
        $this->assertStringContainsString('example.test/repo-lain', $state['message'], 'alamat repo tetap bisa didiagnosis');
        $this->assertStringNotContainsString('rahasia', $state['message'], 'kredensial URL tidak boleh ikut');
        $this->assertStringNotContainsString('user:', $state['message']);
    }

    /**
     * N-1: pesan `blocked` membuang **seluruh** userinfo URL repo — termasuk
     * password yang memuat `@` (`https://user:p@ss@host/x.git`) — karena pesan ini
     * tampil di wizard. Regex lama (`[^/@]*@`, berhenti di `@` pertama) menyisakan
     * ekor password (`https://ss@host/x.git`); dibuktikan mutation check di
     * `/tmp` (bukan di repo) sebelum uji ini ditulis.
     *
     * @return array<string,array{0:string,1:string,2:array<int,string>}> repo_url, remote, potongan terlarang
     */
    public static function repoUrlUserinfoCases(): array
    {
        return [
            'password biasa' => [
                'https://user:secret@host/x.git',
                'https://other.test/y.git',
                ['secret', 'user:'],
            ],
            'password memuat @' => [
                'https://user:p@ss@host/x.git',
                'https://other.test/y.git',
                ['ss@', 'p@ss', 'user:'],
            ],
            'userinfo tanpa password' => [
                'http://user@host/x.git',
                'https://other.test/y.git',
                ['user@'],
            ],
        ];
    }

    /**
     * @param array<int,string> $forbidden
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repoUrlUserinfoCases')]
    public function testBlockedRepoMessageStripsAllUrlCredentials(string $repoUrl, string $remote, array $forbidden): void
    {
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer(new MaterializerFakeGit(), $runner);

        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0755, true);
        file_put_contents($dir . '/penting.yml', 'jangan hilang');
        $runner->remotes[$dir] = $remote;

        $state = $materializer->appDirState([
            'id' => self::APP_ID,
            'name' => 'demo',
            'repo_url' => $repoUrl,
        ]);

        $this->assertSame('blocked', $state['state']);
        $this->assertStringContainsString('other.test/y.git', $state['message'], 'alamat repo tetap bisa didiagnosis');
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $state['message'], "kredensial `{$needle}` tidak boleh ikut");
        }
    }

    /**
     * N-1: URL scp-like (`git@host:org/repo.git`) **tidak** punya skema `://`
     * sehingga tidak punya userinfo berpassword — bentuknya dibiarkan utuh supaya
     * pesannya tetap informatif.
     */
    public function testBlockedRepoMessageKeepsScpLikeRemoteIntact(): void
    {
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer(new MaterializerFakeGit(), $runner);

        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0755, true);
        file_put_contents($dir . '/penting.yml', 'jangan hilang');
        $runner->remotes[$dir] = 'git@github.com:org/repo.git';

        $state = $materializer->appDirState([
            'id' => self::APP_ID,
            'name' => 'demo',
            'repo_url' => 'https://example.test/app.git',
        ]);

        $this->assertSame('blocked', $state['state']);
        $this->assertStringContainsString('git@github.com:org/repo.git', $state['message']);
        $this->assertStringContainsString('https://example.test/app.git', $state['message']);
    }

    /**
     * Fix R-4: path ada tetapi **bukan direktori** (berkas biasa) ⇒ `blocked`
     * (bukan `free`) — `mkdir()` di jalur job akan gagal. Berkasnya dibiarkan utuh.
     */
    public function testAppDirStateBlocksAPlainFileWithTheSameName(): void
    {
        $dir = $this->tmp . '/apps/demo';
        file_put_contents($dir, 'bukan direktori');

        $state = $this->materializer->appDirState(['id' => self::APP_ID, 'name' => 'demo']);

        $this->assertSame('blocked', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertStringContainsString('apps/demo', $state['message']);
        $this->assertStringContainsString('bukan direktori', $state['message']);
        $this->assertStringContainsString('deploy ulang', $state['message']);
        $this->assertFileExists($dir, 'berkas tidak boleh dihapus/ditimpa');
        $this->assertSame('bukan direktori', (string) file_get_contents($dir));
    }

    /** Fix R-4: symlink dangling bernama sama juga `blocked` (tidak bisa di-`mkdir`). */
    public function testAppDirStateBlocksADanglingSymlinkWithTheSameName(): void
    {
        $dir = $this->tmp . '/apps/demo';
        symlink($this->tmp . '/tidak-ada', $dir);

        $state = $this->materializer->appDirState(['id' => self::APP_ID, 'name' => 'demo']);

        $this->assertSame('blocked', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertStringContainsString('bukan direktori', $state['message']);
        $this->assertTrue(is_link($dir), 'symlink dibiarkan apa adanya');
    }

    /** `forget()` = artefak app dibuang: direktori, deploy key (+ `.pub`), managed env. */
    public function testForgetRemovesAppDirectoryDeployKeyAndManagedEnv(): void
    {
        $app = $this->app(['source' => 'compose', 'auth_method' => 'ssh', 'ssh_key' => 'keys/demo']);

        $this->materializer->materialize($app, [
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_name' => 'docker-compose.yml',
            'ssh_key' => self::KEY_PEM,
        ]);
        mkdir($this->tmp . '/database/env', 0755, true);
        file_put_contents($this->tmp . '/database/env/demo.env', "APP_ENV=prod\n");
        file_put_contents($this->tmp . '/database/keys/demo.pub', 'ssh-ed25519 AAAAfixture');

        $this->assertFileExists($this->tmp . '/apps/demo/docker-compose.yml');
        $this->assertFileExists($this->tmp . '/database/keys/demo');

        $this->materializer->forget($app);

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
        $this->assertFileDoesNotExist($this->tmp . '/database/keys/demo');
        $this->assertFileDoesNotExist($this->tmp . '/database/keys/demo.pub');
        $this->assertFileDoesNotExist($this->tmp . '/database/env/demo.env');

        // Idempoten: memanggilnya lagi tidak melempar.
        $this->materializer->forget($app);
    }

    /** App tanpa `ssh_key` sah: `forget()` tetap membersihkan direktori & env. */
    public function testForgetToleratesUnsafeKeyMetadata(): void
    {
        $app = $this->app([
            'source' => 'compose',
            'auth_method' => 'ssh',
            'ssh_key' => '../../etc/passwd',
            'name' => 'demo',
        ]);

        $this->materializer->materialize($app, [
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_name' => 'docker-compose.yml',
        ]);

        $this->materializer->forget($app);

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
    }

    // ==================================================================
    // App compose tanpa file compose (celah yang ditutup misi ini)
    // ==================================================================

    public function testComposeAppWithoutComposeFileFailsFastBeforeCreatingAnything(): void
    {
        try {
            $this->materializer->materialize($this->app(['source' => 'compose']), []);
            $this->fail('app mode compose tanpa file compose harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak punya file compose', $e->getMessage());
            $this->assertStringContainsString('docker-compose.yml', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo', 'fail-fast tidak boleh menyentuh disk');
    }

    /** File compose yang sudah ada dari job sebelumnya ⇒ job tanpa artefak tetap sah. */
    public function testExistingComposeFileSatisfiesTheRequirement(): void
    {
        mkdir($this->tmp . '/apps/demo', 0755, true);
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");

        $this->materializer->materialize($this->app(['source' => 'compose']), []);

        $this->assertFileExists($this->tmp . '/apps/demo/docker-compose.yml');
    }

    /** App repo (git) tanpa artefak: tidak menyentuh disk sama sekali. */
    public function testGitAppWithoutArtifactsTouchesNothing(): void
    {
        $this->materializer->materialize($this->app(['source' => 'git']), []);

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
    }

    // ==================================================================
    // App repo (`source=repo`/`git`) — clone DI SERVER AGENT
    // ==================================================================

    /**
     * Direktori app belum ada ⇒ clone **di agent**, dengan `repo_url`, `branch`,
     * dan path deploy key yang sudah ditulis materializer (path yang dibaca
     * `LocalDeployer::appSshKeyPath()`).
     */
    public function testCloneUsesRepoUrlBranchAndTheWrittenDeployKeyPath(): void
    {
        $git = new MaterializerFakeGit();
        $materializer = $this->materializer($git, new MaterializerFakeRunner());
        $app = $this->app([
            'source' => 'git',
            'repo_url' => 'git@github.com:uji/app.git',
            'branch' => 'produksi',
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
        ]);

        $materializer->materialize($app, ['ssh_key' => self::KEY_PEM]);

        $this->assertCount(1, $git->clones, 'repo app tanpa checkout wajib di-clone di agent');
        $this->assertSame('git@github.com:uji/app.git', $git->clones[0]['repo']);
        $this->assertSame('produksi', $git->clones[0]['branch']);
        $this->assertSame($this->tmp . '/database/keys/demo', $git->clones[0]['sshKey'], 'clone memakai kunci yang baru ditulis');
        $this->assertDirectoryExists($this->tmp . '/apps/demo');
        $this->assertSame(
            [],
            glob($this->tmp . '/apps/' . AgentArtifactMaterializer::CLONE_TMP_PREFIX . '*') ?: [],
            'direktori clone sementara tidak boleh tertinggal'
        );
    }

    /** Tanpa `ssh_key` app (repo publik) ⇒ clone tanpa `GIT_SSH_COMMAND`. */
    public function testPublicRepoIsClonedWithoutDeployKey(): void
    {
        $git = new MaterializerFakeGit();
        $materializer = $this->materializer($git, new MaterializerFakeRunner());

        $materializer->materialize(
            $this->app(['source' => 'git', 'repo_url' => 'https://example.test/app.git', 'branch' => null]),
            []
        );

        $this->assertCount(1, $git->clones);
        $this->assertNull($git->clones[0]['sshKey']);
        $this->assertSame('main', $git->clones[0]['branch'], 'branch kosong ⇒ `main` (default LocalDeployer)');
    }

    /** Clone sungguhan (repo fixture lokal, tanpa jaringan) dari `GitService` agent. */
    public function testRepoIsReallyClonedOnTheAgentWithGitService(): void
    {
        $fx = GitTestFixture::create();
        try {
            $materializer = $this->materializer();
            $app = $this->app(['source' => 'git', 'repo_url' => $fx->origin, 'branch' => 'main']);

            $materializer->materialize($app, []);

            $dir = $this->tmp . '/apps/demo';
            $this->assertFileExists($dir . '/v2.txt', 'checkout repo benar-benar ada di server agent');
            $this->assertDirectoryExists($dir . '/.git');
            $this->assertSame($fx->v2, (new GitService())->revParse($dir));

            $remote = (new ProcessRunner())->run(['git', '-C', $dir, 'remote', 'get-url', 'origin']);
            $this->assertSame($fx->origin, trim($remote['stdout']));
        } finally {
            $fx->cleanup();
        }
    }

    /** Direktori sudah repo dengan remote sama ⇒ **tidak** clone ulang; kunci tetap disegarkan. */
    public function testExistingRepoWithSameRemoteIsNotClonedAgainButKeyIsRefreshed(): void
    {
        $git = new MaterializerFakeGit();
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer($git, $runner);
        $app = $this->app([
            'source' => 'git',
            'repo_url' => 'https://example.test/app.git',
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
        ]);

        $materializer->materialize($app, ['ssh_key' => self::KEY_PEM]);
        $this->assertCount(1, $git->clones);

        // Job berikutnya: direktori app sudah repo dari remote yang sama.
        $runner->remotes[$this->tmp . '/apps/demo'] = 'https://example.test/app.git';
        $refreshed = "-----BEGIN OPENSSH PRIVATE KEY-----\nYmFydQ==\n-----END OPENSSH PRIVATE KEY-----\n";
        $materializer->materialize($app, ['ssh_key' => $refreshed]);

        $this->assertCount(1, $git->clones, 'repo yang sama tidak boleh di-clone ulang');
        $this->assertSame($refreshed, file_get_contents($this->tmp . '/database/keys/demo'), 'deploy key wajib tersegarkan');
        $this->assertSame("clone tiruan\n", file_get_contents($this->tmp . '/apps/demo/clone.txt'), 'isi repo tidak diusik');
    }

    /** URL repo sama tapi penulisan berbeda (`…/app` vs `…/app.git`) tetap dianggap sama. */
    public function testRepoUrlComparisonIgnoresSuffixAndTrailingSlash(): void
    {
        $git = new MaterializerFakeGit();
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer($git, $runner);
        $runner->remotes[$this->tmp . '/apps/demo'] = 'https://Example.test/app/';
        mkdir($this->tmp . '/apps/demo', 0777, true);

        $materializer->materialize(
            $this->app(['source' => 'git', 'repo_url' => 'https://example.test/app.git']),
            []
        );

        $this->assertSame([], $git->clones, 'remote yang sama (beda bentuk) tidak di-clone ulang');
    }

    /**
     * Clone gagal ⇒ fail-fast dengan pesan jelas, direktori setengah jadi
     * dibersihkan, dan **isi deploy key tidak pernah masuk pesan galat**.
     */
    public function testCloneFailureFailsFastCleansUpAndNeverLeaksKeyMaterial(): void
    {
        $git = new MaterializerFakeGit();
        $git->fail = true;
        $git->failMessage = 'Gagal clone repo: fatal: authentication failed for ' . self::KEY_PEM;
        $materializer = $this->materializer($git, new MaterializerFakeRunner());
        $app = $this->app([
            'source' => 'git',
            'repo_url' => 'https://example.test/privat.git',
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
        ]);

        try {
            $materializer->materialize($app, ['ssh_key' => self::KEY_PEM]);
            $this->fail('clone yang gagal tidak boleh dianggap sukses');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('https://example.test/privat.git', $e->getMessage());
            $this->assertStringContainsString('gagal di server agent ini', $e->getMessage());
            $this->assertStringNotContainsString('Zml4dHVyZS1rZXktaXNpLXJhaGFzaWE=', $e->getMessage(), 'isi kunci bocor');
            $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $e->getMessage(), 'blok PEM bocor');
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo', 'direktori app tidak boleh setengah jadi');
        $this->assertSame(
            [],
            glob($this->tmp . '/apps/' . AgentArtifactMaterializer::CLONE_TMP_PREFIX . '*') ?: [],
            'direktori clone sementara wajib dibersihkan'
        );
    }

    /** N-1b: pesan "Clone repo … gagal" tidak memuat userinfo URL repo. */
    public function testCloneFailureMessageStripsUrlUserinfo(): void
    {
        $git = new MaterializerFakeGit();
        $git->fail = true;
        $git->failMessage = 'fatal: repository not found';
        $materializer = $this->materializer($git, new MaterializerFakeRunner());

        try {
            $materializer->materialize($this->app([
                'source' => 'git',
                'repo_url' => 'https://user:secret@example.test/privat.git',
            ]), []);
            $this->fail('clone yang gagal tidak boleh dianggap sukses');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('https://example.test/privat.git', $message, 'host/path tetap terbaca');
            $this->assertStringContainsString('gagal di server agent ini', $message);
            $this->assertStringNotContainsString('secret', $message, 'password URL bocor ke pesan clone gagal');
            $this->assertStringNotContainsString('user:', $message);
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo', 'direktori app tidak boleh setengah jadi');
    }

    /**
     * N-1b: pesan jalur job `ensureRepoClone()` ("sudah berisi repo lain …") juga
     * membuang **seluruh** userinfo URL repo — kelas kebocoran yang sama dengan
     * pesan preflight (`appDirState()`), kini memakai helper yang sama
     * (`redactRepoUrl()`). Host/path tetap terbaca supaya bisa didiagnosis.
     */
    public function testForeignRepoMessageStripsUrlUserinfoOnTheJobPath(): void
    {
        $git = new MaterializerFakeGit();
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer($git, $runner);
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents($this->tmp . '/apps/demo/penting.yml', 'jangan hilang');
        $runner->remotes[$this->tmp . '/apps/demo'] = 'https://user:secret@example.test/repo-lain.git';

        try {
            $materializer->materialize($this->app([
                'source' => 'git',
                'repo_url' => 'https://user:p@ss@example.test/app.git',
            ]), []);
            $this->fail('direktori repo milik repo lain harus ditolak');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('repo lain', $message);
            $this->assertStringContainsString('example.test/repo-lain.git', $message, 'host/path remote tetap terbaca');
            $this->assertStringContainsString('example.test/app.git', $message, 'host/path repo_url tetap terbaca');
            foreach (['secret', 'p@ss', 'ss@', 'user:', 'user@'] as $needle) {
                $this->assertStringNotContainsString($needle, $message, "userinfo `{$needle}` bocor ke pesan jalur job");
            }
        }

        $this->assertSame([], $git->clones, 'tidak boleh clone di atas repo lain');
        $this->assertFileExists($this->tmp . '/apps/demo/penting.yml');
    }

    /** Direktori kosong (sisa job gagal) ⇒ boleh dipakai untuk clone. */
    public function testEmptyAppDirIsClonedInto(): void
    {
        $git = new MaterializerFakeGit();
        $materializer = $this->materializer($git, new MaterializerFakeRunner());
        mkdir($this->tmp . '/apps/demo', 0777, true);

        $materializer->materialize($this->app(['source' => 'git', 'repo_url' => 'https://example.test/app.git']), []);

        $this->assertCount(1, $git->clones);
        $this->assertFileExists($this->tmp . '/apps/demo/clone.txt');
    }

    /** Direktori berisi repo **lain** ⇒ ditolak, tidak pernah ditimpa/di-clone ulang. */
    public function testForeignRepoDirectoryIsNeverOverwritten(): void
    {
        $git = new MaterializerFakeGit();
        $runner = new MaterializerFakeRunner();
        $materializer = $this->materializer($git, $runner);
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents($this->tmp . '/apps/demo/penting.yml', 'jangan hilang');
        $runner->remotes[$this->tmp . '/apps/demo'] = 'https://example.test/repo-lain.git';

        try {
            $materializer->materialize($this->app(['source' => 'git', 'repo_url' => 'https://example.test/app.git']), []);
            $this->fail('direktori repo milik repo lain harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('repo lain', $e->getMessage());
            $this->assertStringContainsString('https://example.test/repo-lain.git', $e->getMessage());
        }

        $this->assertSame([], $git->clones, 'tidak boleh clone di atas repo lain');
        $this->assertFileExists($this->tmp . '/apps/demo/penting.yml');
    }

    /** Direktori non-kosong tanpa `.git` ⇒ ditolak (bukan di-clone di atasnya). */
    public function testNonRepoDirectoryIsNotClonedOver(): void
    {
        $git = new MaterializerFakeGit();
        $materializer = $this->materializer($git, new MaterializerFakeRunner());
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents($this->tmp . '/apps/demo/penting.yml', 'jangan hilang');

        try {
            $materializer->materialize($this->app(['source' => 'git', 'repo_url' => 'https://example.test/app.git']), []);
            $this->fail('direktori non-repo harus ditolak, bukan di-clone di atasnya');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bukan clone git', $e->getMessage());
        }

        $this->assertSame([], $git->clones);
        $this->assertFileExists($this->tmp . '/apps/demo/penting.yml');
    }

    /** App compose/template **tidak** pernah di-clone (bukan app repo). */
    public function testComposeAndTemplateAppsAreNeverCloned(): void
    {
        $git = new MaterializerFakeGit();
        $materializer = $this->materializer($git, new MaterializerFakeRunner());

        $materializer->materialize(
            $this->app(['source' => 'compose']),
            ['compose' => "services:\n  web:\n    image: nginx:alpine\n", 'compose_name' => 'docker-compose.yml']
        );
        $materializer->materialize(
            $this->app(['source' => 'compose', 'template' => ['slug' => 'uji'], 'repo_url' => 'https://example.test/app.git']),
            ['template' => 'uji']
        );
        // `repo_url` yang tertinggal di dokumen app compose (data lama) tidak boleh
        // membuat agent meng-clone: penentu mode adalah `source` app.
        $materializer->materialize(
            $this->app(['source' => 'compose', 'repo_url' => 'https://example.test/app.git']),
            ['compose' => "services:\n  web:\n    image: nginx:alpine\n", 'compose_name' => 'docker-compose.yml']
        );

        $this->assertSame([], $git->clones);
    }

    // ==================================================================
    // Idempoten
    // ==================================================================

    public function testRepeatedJobKeepsAllArtifactsIntact(): void
    {
        $app = $this->app([
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
            'template' => ['slug' => 'uji'],
        ]);
        $artifacts = ['template' => 'uji', 'ssh_key' => self::KEY_PEM];

        $this->materializer->materialize($app, $artifacts);
        $this->materializer->materialize($app, $artifacts);

        $this->assertFileExists($this->tmp . '/apps/demo/docker-compose.yml');
        $this->assertFileExists($this->tmp . '/apps/demo/conf.d/custom.conf');
        $this->assertFileExists($this->tmp . '/database/keys/demo');
        $this->assertFileExists($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE);
        $this->assertSame(self::KEY_PEM, file_get_contents($this->tmp . '/database/keys/demo'));
    }

    // ==================================================================
    // Berkas pendukung (`files`)
    // ==================================================================

    public function testSupportingFilesAreWrittenIncludingSubdirectories(): void
    {
        $this->materializer->materialize($this->app(['source' => 'compose']), [
            'compose' => "services:\n  web:\n    image: nginx:alpine\n",
            'compose_name' => 'docker-compose.yml',
            'files' => ['conf.d/app.conf' => "server {\n}\n"],
        ]);

        $this->assertSame("server {\n}\n", file_get_contents($this->tmp . '/apps/demo/conf.d/app.conf'));
    }

    // ==================================================================
    // Validasi bentuk
    // ==================================================================

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function invalidArtifacts(): array
    {
        return [
            'kunci asing' => [['token' => 'x']],
            'compose bukan string' => [['compose' => ['a']]],
            'compose_name tanpa compose' => [['compose_name' => 'docker-compose.yml']],
            'compose_name tak dikenal' => [['compose' => "services:\n  a:\n    image: x\n", 'compose_name' => 'evil.yml']],
            'compose_name traversal' => [['compose' => "services:\n  a:\n    image: x\n", 'compose_name' => '../docker-compose.yml']],
            'ssh_key bukan string' => [['ssh_key' => ['k']]],
            'template tak sah' => [['template' => '../etc']],
            'files bukan map' => [['files' => ['x']]],
            'files nama generated' => [['files' => ['docker-compose.override.env.yml' => 'x']]],
            'files override port tanpa pasangan' => [['files' => [ComposeSource::RESET_OVERRIDE_FILE => "services: {}\n"]]],
            'files nama compose utama' => [['files' => ['docker-compose.yml' => 'x']]],
            'files nama traversal' => [['files' => ['../evil' => 'x']]],
        ];
    }

    /**
     * @param array<string,mixed> $artifacts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidArtifacts')]
    public function testValidateRejectsMalformedArtifacts(array $artifacts): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentArtifactMaterializer::validate($artifacts);
    }

    public function testValidateNormalizesEmptyArtifactsAndEmptyKey(): void
    {
        $this->assertSame([
            'compose' => null,
            'compose_name' => null,
            'template' => null,
            'ssh_key' => null,
            'files' => [],
        ], AgentArtifactMaterializer::validate([]));

        $this->assertNull(AgentArtifactMaterializer::validate(['ssh_key' => ''])['ssh_key']);
    }

    // ==================================================================
    // Sanitasi pesan galat
    // ==================================================================

    public function testSanitizeErrorRemovesKeyMaterialAndPemBlocks(): void
    {
        $message = 'Gagal menulis kunci: ' . self::KEY_PEM . ' (path /tmp/x)';
        $clean = AgentArtifactMaterializer::sanitizeError($message, ['ssh_key' => self::KEY_PEM]);

        $this->assertStringNotContainsString('Zml4dHVyZS1rZXktaXNpLXJhaGFzaWE=', $clean);
        $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $clean);
        $this->assertStringContainsString('/tmp/x', $clean);
    }

    public function testSanitizeErrorAlwaysReturnsSomething(): void
    {
        $this->assertNotSame('', AgentArtifactMaterializer::sanitizeError('   ', []));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function materializer(?GitService $git = null, ?ProcessRunner $runner = null): AgentArtifactMaterializer
    {
        return new AgentArtifactMaterializer(
            $this->tmp . '/apps',
            $this->tmp . '/database',
            $this->tmp . '/database/keys',
            new TemplateCatalog($this->tmp . '/templates'),
            $git,
            $runner
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function app(array $overrides = []): array
    {
        return array_merge([
            'id' => self::APP_ID,
            'name' => 'demo',
            'source' => 'git',
            'compose_files' => ['docker-compose.yml'],
            'auth_method' => 'none',
            'ssh_key' => null,
        ], $overrides);
    }

    /**
     * Sidik isi direktori (nama relatif ⇒ isi/mode/**mtime**) untuk membuktikan
     * sebuah operasi read-only: urutan & isi harus identik sebelum/sesudah.
     *
     * `mtime` ikut disidik supaya "menulis ulang berkas dengan isi yang sama" juga
     * tertangkap sebagai mutasi (bukan lolos hanya karena `sha1` isinya sama).
     *
     * @return array<string,string>
     */
    private function snapshotTree(string $dir): array
    {
        $out = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            $rel = substr($path, strlen($dir) + 1);
            $out[$rel] = $item->isDir()
                ? 'dir:' . substr(sprintf('%o', $item->getPerms()), -4) . ':' . $item->getMTime()
                : 'file:' . sha1((string) file_get_contents($path)) . ':' . $item->getMTime() . ':' . $item->getSize();
        }
        ksort($out);

        return $out;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
