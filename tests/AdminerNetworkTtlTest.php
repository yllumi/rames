<?php
declare(strict_types=1);

namespace Tests;

use app\library\Adminer\AdminerHelper;
use app\library\Adminer\AdminerTempDir;
use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fake DockerClient untuk menguji lifecycle network helper Adminer tanpa Docker
 * Engine (tanpa socket, tanpa ext-curl): merekam connect/disconnect, menyimpan
 * attachment per container, dan bisa dipaksa melempar error per network.
 */
class AdminerNetworkFakeDockerClient extends DockerClient
{
    /** @var array<int,array{0:string,1:string}> pasangan [networkId, containerId] */
    public array $disconnects = [];

    /** @var array<int,array{0:string,1:string}> */
    public array $connects = [];

    /** @var array<string,string> nama network => id */
    public array $networks = [
        'rames-helpers' => 'net-helpers',
        'appjadul_default' => 'net-appjadul',
    ];

    /** @var array<string,string> nama container => id */
    public array $containers = [
        'rames-adminer' => 'helper-id',
        'rames-webman' => 'dash-id',
    ];

    /** @var array<string,array<string,string>> containerId => (nama network => id) */
    public array $attachments = [
        'helper-id' => ['rames-helpers' => 'net-helpers', 'appjadul_default' => 'net-appjadul'],
        'dash-id' => ['rames-helpers' => 'net-helpers'],
    ];

    /** @var array<string,string> networkId => pesan error disconnect */
    public array $disconnectErrors = [];

    private string $tmp;

    public function __construct(string $tmp = '')
    {
        // sengaja tidak memanggil parent (tanpa Guzzle/socket)
        $this->tmp = $tmp;
    }

    public function ping(): bool
    {
        return true;
    }

    public function listContainers(array $filters = []): array
    {
        $out = [];
        foreach ($this->containers as $name => $id) {
            $out[] = ['Id' => $id, 'Names' => ['/' . $name], 'Image' => 'adminer:6'];
        }

        return $out;
    }

    public function inspectContainer(string $id): array
    {
        $name = (string) (array_search($id, $this->containers, true) ?: $id);
        $networks = [];
        foreach ($this->attachments[$id] ?? [] as $networkName => $networkId) {
            $networks[$networkName] = ['NetworkID' => $networkId, 'IPAddress' => '172.18.0.9'];
        }

        return [
            'Id' => $id,
            'Name' => '/' . $name,
            'State' => ['Running' => true],
            'NetworkSettings' => ['Networks' => $networks],
        ];
    }

    public function listNetworks(array $filters = []): array
    {
        $out = [];
        foreach ($this->networks as $name => $id) {
            $out[] = ['Name' => $name, 'Id' => $id];
        }

        return $out;
    }

    public function connectContainerToNetwork(string $networkId, string $containerId, array $endpointConfig = []): void
    {
        $this->connects[] = [$networkId, $containerId];
        $name = array_search($networkId, $this->networks, true);
        if ($name !== false) {
            $this->attachments[$containerId][(string) $name] = $networkId;
        }
    }

    public function disconnectContainerFromNetwork(string $networkId, string $containerId, bool $force = false): void
    {
        $this->disconnects[] = [$networkId, $containerId];
        if (isset($this->disconnectErrors[$networkId])) {
            throw new RuntimeException($this->disconnectErrors[$networkId]);
        }
        $name = array_search($networkId, $this->networks, true);
        if ($name !== false) {
            unset($this->attachments[$containerId][(string) $name]);
        }
    }
}

/**
 * Prune TTL attach helper Adminer ke network app (temuan Fase 5a SEDANG).
 *
 * Menutup dua invarian: detach **idempoten** (tidak memanggil Engine bila memang
 * belum ter-attach, toleran 403/404) dan prune **oportunistik** berbasis catatan
 * waktu (`runtime/adminer-helper/networks.json`) yang tidak pernah melempar.
 */
class AdminerNetworkTtlTest extends TestCase
{
    private string $tmp;

    private AdminerNetworkFakeDockerClient $docker;

    /** @var array<int,string> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-adminer-ttl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->docker = new AdminerNetworkFakeDockerClient($this->tmp);
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach (['', '/adminer-helper', '/adminer-proxy'] as $sub) {
            $dir = $this->tmp . $sub;
            foreach (glob($dir . '/*') ?: [] as $entry) {
                @unlink((string) $entry);
            }
            if ($sub !== '') {
                @rmdir($dir);
            }
        }
        @rmdir($this->tmp);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function helper(array $overrides = []): AdminerHelper
    {
        return new AdminerHelper($this->docker, new ProcessRunner(), array_replace([
            'docker' => 'docker',
            'image' => 'adminer:6',
            'container' => 'rames-adminer',
            'network' => 'rames-helpers',
            'dashboard' => 'rames-webman',
            'network_ttl' => 1800,
            'network_state_file' => $this->tmp . '/adminer-helper/networks.json',
            'proxy_temp_dir' => $this->tmp . '/adminer-proxy',
            'logger' => function (string $message): void {
                $this->logs[] = $message;
            },
        ], $overrides));
    }

    /**
     * @param array<string,int> $networks nama network => waktu pakai terakhir
     */
    private function seedRecords(array $networks, string $helperId = 'helper-id'): void
    {
        $dir = $this->tmp . '/adminer-helper';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $dir . '/networks.json',
            (string) json_encode(['helper' => $helperId, 'networks' => $networks], JSON_PRETTY_PRINT)
        );
    }

    // ==================================================================
    // ensureOffNetwork — detach idempoten
    // ==================================================================

    public function testEnsureOffNetworkDisconnectsExactlyOnceWhenAttached(): void
    {
        $this->helper()->ensureOffNetwork('appjadul_default');

        $this->assertSame([['net-appjadul', 'helper-id']], $this->docker->disconnects);
        $this->assertArrayNotHasKey('appjadul_default', $this->docker->attachments['helper-id']);
    }

    public function testEnsureOffNetworkIsIdempotentWhenNotAttached(): void
    {
        // Putuskan sekali, lalu panggil lagi → TIDAK ada panggilan Engine kedua.
        $this->helper()->ensureOffNetwork('appjadul_default');
        $this->helper()->ensureOffNetwork('appjadul_default');

        $this->assertSame(1, count($this->docker->disconnects), 'tanpa guard NetworkSettings.Networks akan 403 berulang');
    }

    public function testEnsureOffNetworkNeverTouchesHelperNetwork(): void
    {
        $this->helper()->ensureOffNetwork('rames-helpers');

        $this->assertSame([], $this->docker->disconnects);
        $this->assertArrayHasKey('rames-helpers', $this->docker->attachments['helper-id']);
    }

    public function testEnsureOffNetworkTolerates403AlreadyDisconnected(): void
    {
        $this->docker->disconnectErrors['net-appjadul'] =
            'Operasi Docker gagal: HTTP 403 {"message":"container helper-id is not connected to network appjadul_default"}';

        $this->helper()->ensureOffNetwork('appjadul_default');

        $this->assertSame(1, count($this->docker->disconnects));
        $this->assertSame([], $this->logs, '403 bukan kegagalan yang perlu dilaporkan');
    }

    public function testEnsureOffNetworkForgetsRecordWhenNetworkGone(): void
    {
        // Network app dibongkar: tidak ada attachment, catatan harus dibersihkan
        // tanpa memanggil disconnect.
        unset($this->docker->networks['appjadul_default'], $this->docker->attachments['helper-id']['appjadul_default']);
        $this->seedRecords(['appjadul_default' => time() - 9999]);

        $this->helper()->ensureOffNetwork('appjadul_default');

        $this->assertSame([], $this->docker->disconnects);
        $this->assertSame([], $this->helper()->networkRecords()['networks']);
    }

    // ==================================================================
    // pruneIdleNetworks — TTL
    // ==================================================================

    public function testPruneDetachesNetworkIdleBeyondTtl(): void
    {
        $this->seedRecords(['appjadul_default' => time() - 3600]);

        $this->helper(['network_ttl' => 1800])->pruneIdleNetworks();

        $this->assertSame([['net-appjadul', 'helper-id']], $this->docker->disconnects);
        $this->assertArrayNotHasKey('appjadul_default', $this->docker->attachments['helper-id']);
        $this->assertSame([], $this->helper()->networkRecords()['networks'], 'catatan ikut dibersihkan');
    }

    public function testPruneKeepsFreshNetworkAttached(): void
    {
        $this->seedRecords(['appjadul_default' => time() - 30]);

        $helper = $this->helper(['network_ttl' => 1800]);
        $helper->pruneIdleNetworks();

        $this->assertSame([], $this->docker->disconnects);
        $this->assertArrayHasKey('appjadul_default', $this->docker->attachments['helper-id']);
        $this->assertArrayHasKey('appjadul_default', $helper->networkRecords()['networks']);
    }

    public function testPruneDisabledByZeroTtlStillDropsGoneNetworks(): void
    {
        $this->seedRecords([
            'appjadul_default' => time() - 999999,
            'myblog_default' => time() - 999999,
        ]);
        // `myblog_default` sudah tidak ada di Engine (app dihapus).
        unset($this->docker->networks['myblog_default']);

        $helper = $this->helper(['network_ttl' => 0]);
        $helper->pruneIdleNetworks();

        $this->assertSame([], $this->docker->disconnects, 'TTL 0 = nonaktif');
        $this->assertSame(['appjadul_default'], array_keys($helper->networkRecords()['networks']), 'catatan network hilang tetap dibuang');
    }

    public function testPruneNeverDetachesHelperNetworkAndKeepsGoingOnErrors(): void
    {
        $this->seedRecords([
            'rames-helpers' => time() - 99999,
            'appjadul_default' => time() - 99999,
        ]);
        $this->docker->disconnectErrors['net-appjadul'] = 'Gagal terhubung ke Docker Engine: socket mati';

        $helper = $this->helper(['network_ttl' => 1]);
        $helper->pruneIdleNetworks();

        // Network helper tidak pernah dilepas.
        $this->assertArrayHasKey('rames-helpers', $this->docker->attachments['helper-id']);
        // Kegagalan satu network tidak melempar & dilaporkan sebagai log.
        $this->assertNotEmpty($this->logs);
        $this->assertStringContainsString('appjadul_default', implode("\n", $this->logs));
        // Catatan yang gagal dilepas dipertahankan untuk percobaan berikutnya.
        $this->assertArrayHasKey('appjadul_default', $helper->networkRecords()['networks']);
    }

    public function testPruneDropsAllRecordsWhenHelperContainerWasRecreated(): void
    {
        $this->seedRecords(['appjadul_default' => time() - 30], 'helper-lama');

        $helper = $this->helper();
        $helper->pruneIdleNetworks();

        $this->assertSame([], $this->docker->disconnects);
        $this->assertSame([], $helper->networkRecords()['networks']);
    }

    // ==================================================================
    // ensureRunning — prune oportunistik + izin direktori temp
    // ==================================================================

    public function testEnsureRunningPrunesIdleNetworkAndTempFiles(): void
    {
        $this->seedRecords(['appjadul_default' => time() - 3600]);

        // Berkas respons basi harus ikut dipangkas oleh ensureRunning().
        $tempDir = $this->tmp . '/adminer-proxy';
        mkdir($tempDir, 0700, true);
        $stale = $tempDir . '/' . bin2hex(random_bytes(8)) . AdminerTempDir::SUFFIX;
        file_put_contents($stale, 'lama');
        touch($stale, time() - AdminerTempDir::TTL - 60);

        $this->helper(['network_ttl' => 1800])->ensureRunning();

        $this->assertSame([['net-appjadul', 'helper-id']], $this->docker->disconnects);
        $this->assertFileDoesNotExist($stale);
        $this->assertArrayHasKey('rames-helpers', $this->docker->attachments['helper-id'], 'helper tetap di network helper');
    }

    public function testEnsureOnNetworkRecordsUsageTime(): void
    {
        $helper = $this->helper();
        $helper->ensureOnNetwork('appjadul_default');

        // Sudah ter-attach → tidak ada connect baru, tetapi waktu pakai tercatat.
        $this->assertSame([], $this->docker->connects);
        $records = $helper->networkRecords();
        $this->assertSame('helper-id', $records['helper']);
        $this->assertArrayHasKey('appjadul_default', $records['networks']);
        $this->assertGreaterThan(time() - 60, $records['networks']['appjadul_default']);
    }

    // ==================================================================
    // Izin berkas temp (temuan Fase 5a RENDAH)
    // ==================================================================

    public function testTempDirAndFilesAreOwnerOnly(): void
    {
        $dir = $this->tmp . '/adminer-proxy';

        $created = AdminerTempDir::create($dir);
        $this->assertNotNull($created);

        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms((string) $created[0])), -4));

        fclose($created[1]);
        @unlink((string) $created[0]);
    }

    public function testTempDirTightensLegacyLooseDirectory(): void
    {
        $dir = $this->tmp . '/adminer-proxy';
        mkdir($dir, 0777, true);
        @chmod($dir, 0777);

        $created = AdminerTempDir::create($dir);
        $this->assertNotNull($created);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));

        fclose($created[1]);
        @unlink((string) $created[0]);
    }

    public function testTempDirPruneRemovesOnlyStaleFiles(): void
    {
        $dir = $this->tmp . '/adminer-proxy';
        mkdir($dir, 0700, true);
        $old = $dir . '/old' . AdminerTempDir::SUFFIX;
        $new = $dir . '/new' . AdminerTempDir::SUFFIX;
        file_put_contents($old, 'x');
        file_put_contents($new, 'y');
        touch($old, time() - AdminerTempDir::TTL - 60);

        $removed = AdminerTempDir::prune($dir);

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function testTempDirPruneNeverThrowsOnMissingDirectory(): void
    {
        $this->assertSame(0, AdminerTempDir::prune($this->tmp . '/tidak-ada'));
    }

    /**
     * Catatan waktu pakai network juga tidak boleh terbaca pengguna lain
     * (tidak memuat kredensial, tetapi memuat topologi).
     */
    public function testNetworkStateFileIsOwnerOnly(): void
    {
        $this->helper()->ensureOnNetwork('appjadul_default');

        $path = $this->tmp . '/adminer-helper/networks.json';
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($path))), -4));

        // Tanpa kredensial apa pun di berkas state.
        $raw = (string) file_get_contents($path);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('f5a', $raw);
    }
}
