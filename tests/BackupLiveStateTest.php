<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Backup\BackupCatalog;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupReport;
use app\library\Backup\BackupRunLock;
use app\library\Backup\BackupSelection;
use app\library\Backup\DumpRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeStateGuard;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

/**
 * Test `container_state` **live** pada respons `GET /api/backups/status`.
 *
 * Kontrak yang dibuktikan:
 *  - `VolumeBackupService::containerStatesLive()` memetakan container → volume
 *    lewat `Mounts[]` (`Type === 'volume'` & `Name` non-kosong) dan memakai
 *    presedensi `containerState()` yang sudah ada (`running` menang; tanpa
 *    container ⇒ `none`);
 *  - **satu** panggilan Engine untuk berapa pun barisnya; **nol** baris ⇒ nol
 *    panggilan;
 *  - Engine gagal ⇒ baris cache dipertahankan, tanpa exception yang lolos;
 *  - `BackupController::status()` (via seam `backupService()`) memakai overlay;
 *  - `DockerClient::listContainers()` meneruskan timeout per-request ke opsi
 *    Guzzle `timeout` hanya bila diberikan.
 *
 * Tanpa Docker Engine/restic nyata: seluruh state (catalog, report, registry,
 * SQLite) di direktori temp — data runtime tidak disentuh.
 */
class BackupLiveStateTest extends TestCase
{
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backuplive_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/apps', 0777, true);

        // Config temp: `status()` membuat `AppStore()` sendiri (tanpa seam), jadi
        // sqlite_file diarahkan ke berkas temp — DB nyata tidak disentuh.
        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $this->tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        SqliteFixture::apps($this->tmp . '/rames.sqlite', []);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // VolumeBackupService: pemetaan + presedensi
    // ------------------------------------------------------------------

    /**
     * Container `running` menang atas `exited` (presedensi `containerState()`),
     * volume tanpa container ⇒ `none` (bukan `unknown`/kosong).
     */
    public function testMapsMountsToVolumeStateWithExistingPrecedence(): void
    {
        $docker = new FakeLiveStateDockerClient([
            self::container('c1', 'exited', ['vol_mixed']),
            self::container('c2', 'running', ['vol_mixed']),
            self::container('c3', 'exited', ['vol_exited']),
        ]);

        $states = $this->service($docker)->containerStatesLive();

        $this->assertNotNull($states);
        $this->assertSame('running', $states['vol_mixed'], 'ada container running ⇒ running');
        $this->assertSame('exited', $states['vol_exited'], 'state non-kosong pertama dipakai bila tak ada running');

        $rows = $this->service($docker)->overlayLiveContainerStates([
            ['name' => 'vol_mixed', 'container_state' => 'exited', 'strategy' => 'snapshot', 'snapshots' => 2],
            ['name' => 'vol_absent', 'container_state' => 'exited', 'strategy' => 'dump', 'snapshots' => 0],
        ]);

        $this->assertSame('running', $rows[0]['container_state'], 'nilai cache basi ditimpa Engine');
        $this->assertSame('none', $rows[1]['container_state'], 'volume tanpa container ⇒ none');
    }

    /** Volume tanpa container sama sekali ⇒ `none` (peta kosong). */
    public function testEmptyEngineResultYieldsNoneForEveryRow(): void
    {
        $docker = new FakeLiveStateDockerClient([]);

        $rows = $this->service($docker)->overlayLiveContainerStates([
            ['name' => 'vol_a', 'container_state' => 'exited'],
        ]);

        $this->assertSame('none', $rows[0]['container_state']);
    }

    /** Bind mount & mount volume tanpa `Name` (anonymous) tidak dihitung. */
    public function testBindAndUnnamedMountsAreIgnored(): void
    {
        $docker = new FakeLiveStateDockerClient([
            [
                'Id' => 'c1',
                'State' => 'running',
                'Mounts' => [['Type' => 'bind', 'Name' => 'vol_bind', 'Source' => '/host', 'Destination' => '/data']],
            ],
            [
                'Id' => 'c2',
                'State' => 'running',
                'Mounts' => [['Type' => 'volume', 'Name' => '', 'Source' => '/var/lib/docker/volumes/x/_data']],
            ],
            self::container('c3', 'running', ['vol_ok']),
        ]);

        $states = $this->service($docker)->containerStatesLive();

        $this->assertNotNull($states);
        $this->assertSame(['vol_ok' => 'running'], $states, 'hanya named volume yang dipetakan');
    }

    // ------------------------------------------------------------------
    // Biaya: jumlah panggilan Engine
    // ------------------------------------------------------------------

    /** Berapa pun barisnya: SATU panggilan Engine (bukan satu per volume/baris). */
    public function testHundredsOfRowsStillUseSingleEngineCall(): void
    {
        $rows = [];
        $containers = [];
        for ($i = 0; $i < 400; $i++) {
            $name = 'vol_' . $i;
            $rows[] = ['name' => $name, 'container_state' => 'exited'];
            $containers[] = self::container('c' . $i, $i % 2 === 0 ? 'running' : 'exited', [$name]);
        }

        $docker = new FakeLiveStateDockerClient($containers);
        $out = $this->service($docker)->overlayLiveContainerStates($rows);

        $this->assertCount(400, $out);
        $this->assertSame(1, $docker->listCalls, '400 baris tetap SATU panggilan Engine');
        $this->assertSame(5, $docker->lastTimeout, 'timeout per-request default (5 dtk) diteruskan');
        $this->assertSame('running', $out[0]['container_state']);
        $this->assertSame('exited', $out[1]['container_state']);
    }

    /** Tanpa baris yang perlu di-overlay ⇒ Engine tidak disentuh sama sekali. */
    public function testNoRowsSkipsEngineEntirely(): void
    {
        $docker = new FakeLiveStateDockerClient([self::container('c1', 'running', ['vol_a'])]);

        $out = $this->service($docker)->overlayLiveContainerStates([]);

        $this->assertSame([], $out);
        $this->assertSame(0, $docker->listCalls, '0 baris ⇒ 0 panggilan Engine');
    }

    // ------------------------------------------------------------------
    // Fallback cache
    // ------------------------------------------------------------------

    /** Engine melempar ⇒ baris cache dipertahankan & tidak ada exception yang lolos. */
    public function testEngineFailureKeepsCachedState(): void
    {
        $docker = new FakeLiveStateDockerClient([], 'Gagal terhubung ke Docker Engine: socket tidak tersedia');
        $cached = [
            ['name' => 'vol_a', 'container_state' => 'exited', 'strategy' => 'snapshot', 'snapshots' => 3],
            ['name' => 'vol_b', 'container_state' => 'running', 'strategy' => 'dump', 'snapshots' => 1],
        ];

        $service = $this->service($docker);

        $this->assertNull($service->containerStatesLive(), 'Engine gagal ⇒ null (bukan exception)');

        $out = $service->overlayLiveContainerStates($cached);

        $this->assertSame($cached, $out, 'nilai cache dipertahankan apa adanya');
        $this->assertSame('exited', $out[0]['container_state']);
        $this->assertSame('running', $out[1]['container_state']);
        // Dua pemanggilan eksplisit di test ini (satu per method) — bukan satu
        // per baris: pemanggilan pertama untuk peta, kedua untuk overlay.
        $this->assertSame(2, $docker->listCalls);
    }

    /** Kunci & urutan baris lain tidak berubah oleh overlay. */
    public function testOverlayOnlyReplacesContainerStateKey(): void
    {
        $docker = new FakeLiveStateDockerClient([self::container('c1', 'running', ['vol_a'])]);
        $row = [
            'name' => 'vol_a',
            'project' => 'tonidata',
            'app_id' => 'app1',
            'app_name' => 'Toni',
            'orphaned' => false,
            'strategy' => 'snapshot',
            'container_state' => 'exited',
            'last_run_at' => '2026-10-13T21:41:43+07:00',
            'last_ok' => true,
            'last_message' => null,
            'snapshots' => 4,
        ];

        $out = $this->service($docker)->overlayLiveContainerStates([$row]);

        $this->assertSame('running', $out[0]['container_state']);
        $this->assertSame(array_keys($row), array_keys($out[0]), 'urutan & kunci baris tidak berubah');
        $this->assertSame('4', (string) $out[0]['snapshots'], 'kolom lain tetap dari cache');
        $this->assertSame('2026-10-13T21:41:43+07:00', $out[0]['last_run_at']);
        $this->assertSame('snapshot', $out[0]['strategy']);
    }

    // ------------------------------------------------------------------
    // Level controller (seam `backupService()`)
    // ------------------------------------------------------------------

    /** Baris cache `exited` menjadi `running` saat Engine melaporkan running. */
    public function testStatusOverlaysLiveStateOverCatalogCache(): void
    {
        $docker = new FakeLiveStateDockerClient([self::container('c1', 'running', ['vol_a'])]);
        $payload = $this->statusPayload($docker, [
            ['name' => 'vol_a', 'project' => 'tonidata', 'app_id' => null, 'orphaned' => true,
                'strategy' => 'snapshot', 'container_state' => 'exited', 'snapshots' => 2, 'last_ok' => true],
        ]);

        $this->assertSame(0, $payload['code']);
        $row = $payload['data']['volumes'][0];
        $this->assertSame('vol_a', $row['name']);
        $this->assertSame('running', $row['container_state'], 'container_state live menimpa cache');
        $this->assertSame('snapshot', $row['strategy'], 'kolom lain tetap dari cache');
        $this->assertSame(2, $row['snapshots']);
        $this->assertSame(1, $docker->listCalls, 'satu respons = satu panggilan Engine');
        $this->assertSame('2026-10-13T21:41:43+07:00', $payload['data']['cached_at']);
    }

    /** Engine mati ⇒ respons tetap 200 dengan `container_state` dari cache. */
    public function testStatusFallsBackToCacheWhenEngineUnreachable(): void
    {
        $docker = new FakeLiveStateDockerClient([], 'Gagal terhubung ke Docker Engine: socket tidak tersedia');
        $payload = $this->statusPayload($docker, [
            ['name' => 'vol_a', 'project' => 'tonidata', 'app_id' => null, 'orphaned' => true,
                'strategy' => 'snapshot', 'container_state' => 'exited', 'snapshots' => 0],
        ]);

        $this->assertSame(0, $payload['code']);
        $this->assertSame('exited', $payload['data']['volumes'][0]['container_state']);
        $this->assertSame(1, $docker->listCalls);
    }

    // ------------------------------------------------------------------
    // DockerClient: timeout per-request
    // ------------------------------------------------------------------

    /** Argumen posisional ke-2 diteruskan sebagai opsi Guzzle `timeout`. */
    public function testListContainersForwardsOptionalTimeoutAsGuzzleOption(): void
    {
        $captured = new \stdClass();
        $captured->options = [];
        $captured->query = '';
        $docker = $this->dockerCapturingOptions($captured);

        $rows = $docker->listContainers(['label' => ['a=b']], 7);

        $this->assertSame([], $rows);
        $this->assertSame(7, $captured->options['timeout'] ?? null);
        parse_str($captured->query, $parsed);
        $this->assertSame(['all' => '1', 'filters' => '{"label":["a=b"]}'], $parsed);
    }

    /**
     * Tanpa timeout per-request, opsi `timeout` **tidak** dipasang (klien uji
     * sengaja tanpa `timeout` bawaan agar penerusan ini terlihat) — pemanggil
     * lama (1 argumen) berperilaku sama seperti sebelumnya.
     */
    public function testListContainersWithoutTimeoutLeavesOptionUnset(): void
    {
        $captured = new \stdClass();
        $captured->options = [];
        $captured->query = '';
        $docker = $this->dockerCapturingOptions($captured);

        $docker->listContainers();

        $this->assertArrayNotHasKey('timeout', $captured->options);
        parse_str($captured->query, $parsed);
        $this->assertSame(['all' => '1'], $parsed);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @param array<int,string> $volumes
     * @return array<string,mixed>
     */
    private static function container(string $id, string $state, array $volumes): array
    {
        $mounts = [];
        foreach ($volumes as $name) {
            $mounts[] = ['Type' => 'volume', 'Name' => $name, 'Source' => '/var/lib/docker/volumes/' . $name . '/_data'];
        }

        return ['Id' => $id, 'State' => $state, 'Mounts' => $mounts];
    }

    /**
     * Service dengan seluruh dependensi temp (`DockerClient` palsu disuntik).
     */
    private function service(DockerClient $docker, ?BackupCatalog $catalog = null): VolumeBackupService
    {
        $apps = new AppStore($this->tmp . '/rames.sqlite');
        $selection = new BackupSelection($this->tmp . '/rames.sqlite');

        return new VolumeBackupService(
            $docker,
            $apps,
            new VolumeStateGuard($docker, null, $apps, $this->tmp . '/apps'),
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            new ProcessRunner(),
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->tmp . '/report'),
            [],
            null,
            null,
            'skip',
            $this->tmp . '/env-creds',
            3600,
            [],
            $selection,
            $catalog ?? new BackupCatalog($this->tmp . '/catalog.json'),
            new BackupRegistry($this->tmp . '/rames.sqlite'),
        );
    }

    /**
     * Panggil `BackupController::status()` dengan service palsu + cache terisi.
     *
     * @param array<int,array<string,mixed>> $cachedRows
     * @return array<string,mixed> payload JSON
     */
    private function statusPayload(FakeLiveStateDockerClient $docker, array $cachedRows): array
    {
        $catalog = new BackupCatalog($this->tmp . '/catalog.json');
        // `cached_at` deterministic agar asersi stabil (write() menyegel date('c')).
        $catalog->write($cachedRows);
        $this->setCachedAt('2026-10-13T21:41:43+07:00');

        $controller = new FakeLiveStateBackupController($this->service($docker, $catalog), self::ADMIN);
        $response = $controller->status(new Request("GET /api/backups/status HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Timpa `cached_at` di berkas cache temp (agar asersi tidak bergantung waktu). */
    private function setCachedAt(string $cachedAt): void
    {
        $data = json_decode((string) file_get_contents($this->tmp . '/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['cached_at'] = $cachedAt;
        file_put_contents($this->tmp . '/catalog.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * `DockerClient` nyata dengan klien Guzzle tiruan (tanpa socket) yang
     * merekam opsi request + query terakhir.
     */
    private function dockerCapturingOptions(\stdClass $captured): DockerClient
    {
        $handler = static function ($request, array $options) use ($captured) {
            $captured->options = $options;
            // `query` sudah diterapkan ke URI oleh Guzzle (tidak tersisa di opsi).
            $captured->query = $request->getUri()->getQuery();

            return Create::promiseFor(new Response(200, [], '[]'));
        };

        $docker = new DockerClient($this->tmp . '/docker.sock', 10);
        $client = new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false]);

        $prop = new ReflectionProperty(DockerClient::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($docker, $client);

        return $docker;
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

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
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
}

/**
 * Controller uji: `VolumeBackupService` disuntik lewat seam `backupService()` dan
 * user lewat `currentUser()` sehingga `status()` teruji tanpa session/Engine nyata
 * (pola `FakeBackupScheduleController`).
 */
class FakeLiveStateBackupController extends BackupController
{
    public function __construct(private VolumeBackupService $service, private ?array $user)
    {
    }

    protected function backupService(): VolumeBackupService
    {
        return $this->service;
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function isAdmin(): bool
    {
        return (string) ($this->user['role'] ?? '') === 'admin';
    }
}
