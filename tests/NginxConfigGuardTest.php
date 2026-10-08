<?php
declare(strict_types=1);

namespace Tests;

use app\library\Nginx\NginxConfigGuard;
use app\library\Nginx\NginxReloader;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Fake deployer untuk NginxConfigGuard — merekam setiap writeNginxConfig()
 * (nama app + nilai nginx_routes) dan bisa dipaksa melempar pada pemanggilan ke-N.
 */
class RecordingNginxDeployer extends FakeCliDeployer
{
    /** @var array<int,array{name:string,routes:mixed}> */
    public array $writes = [];

    public ?int $failOnCall = null;

    public string $failMessage = 'tulis config gagal (test)';

    public function writeNginxConfig(array $app): void
    {
        $this->writes[] = [
            'name' => (string) ($app['name'] ?? ''),
            'routes' => $app['nginx_routes'] ?? null,
        ];
        if ($this->failOnCall !== null && count($this->writes) === $this->failOnCall) {
            throw new RuntimeException($this->failMessage);
        }
    }
}

/**
 * Fake NginxReloader — override reload() (kelas & metode tidak final) dengan
 * hasil yang bisa diatur, tanpa menyentuh Docker sama sekali.
 */
class FakeGuardReloader extends NginxReloader
{
    public int $calls = 0;

    /** @var array<string,mixed> */
    private array $result;

    private ?Throwable $throwable;

    /**
     * @param array<string,mixed> $result
     */
    public function __construct(array $result = ['ok' => true, 'error' => null], ?Throwable $throwable = null)
    {
        // Sengaja TIDAK memanggil parent::__construct: reload() di-override dan
        // tidak memakai state induk (ProcessRunner/config), jadi kita tak perlu
        // menyentuh konfigurasi/deploy atau file status nyata.
        $this->result = $result;
        $this->throwable = $throwable;
    }

    public function reload(): array
    {
        $this->calls++;
        if ($this->throwable !== null) {
            throw $this->throwable;
        }
        return $this->result;
    }
}

/**
 * Fake AppStore — melempar pada pemanggilan update() ke-N untuk mensimulasikan
 * kegagalan saat ROLLBACK (app terhapus konkuren / IO gagal / JSON korup).
 * AppStore tidak final, jadi subclass ini memakai implementasi nyata untuk
 * panggilan lain (path temp).
 */
class FailingRollbackAppStore extends AppStore
{
    public int $calls = 0;

    public ?int $failOnCall = null;

    public string $failMessage = 'rollback store gagal (test)';

    public function update(string $id, callable $mutator): array
    {
        $this->calls++;
        if ($this->failOnCall !== null && $this->calls === $this->failOnCall) {
            throw new RuntimeException($this->failMessage);
        }
        return parent::update($id, $mutator);
    }
}

/**
 * Test NginxConfigGuard (alur "uji dulu, rollback bila gagal").
 *
 * Semua test memakai apps.json temp — tidak menyentuh database/*.json nyata.
 */
class NginxConfigGuardTest extends TestCase
{
    private string $file;

    /** @var array<int,array{path:string,target:string}> */
    private array $newRoutes = [
        ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
        ['path' => '/ws/', 'target' => 'http://127.0.0.1:3002'],
    ];

    /** @var array<int,array{path:string,target:string}> */
    private array $oldRoutes = [
        ['path' => '/lama/', 'target' => 'http://127.0.0.1:9000'],
    ];

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-guard-apps-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '.bak'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * @param array<string,mixed> $app
     */
    private function seedApp(array $app): array
    {
        return (new AppStore($this->file))->create($app);
    }

    public function testSuccessPersistsRoutesAndWritesConfigOnce(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'sukses', 'nginx_routes' => $this->oldRoutes]);

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => true, 'error' => null]);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertSame(
            ['ok' => true, 'reloaded' => true, 'rolled_back' => false, 'error' => ''],
            $result
        );
        $this->assertSame(1, $reloader->calls);
        $this->assertCount(1, $deployer->writes);
        $this->assertSame('sukses', $deployer->writes[0]['name']);
        $this->assertSame($this->newRoutes, $deployer->writes[0]['routes']);
        $this->assertSame($this->newRoutes, $store->find($app['id'])['nginx_routes']);
    }

    public function testReloadFailureRollsBackRoutesAndRewritesOldConfig(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'gagal-reload', 'nginx_routes' => $this->oldRoutes]);

        $deployer = new RecordingNginxDeployer();
        $message = 'nginx: [emerg] host not found in upstream "tidak-resolvable"';
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => $message]);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded']);
        $this->assertTrue($result['rolled_back']);
        $this->assertSame($message, $result['error']);
        $this->assertSame(1, $reloader->calls);

        // Dua penulisan: rute baru dulu, lalu config lama saat rollback.
        $this->assertCount(2, $deployer->writes);
        $this->assertSame($this->newRoutes, $deployer->writes[0]['routes']);
        $this->assertSame($this->oldRoutes, $deployer->writes[1]['routes']);
        $this->assertSame($this->oldRoutes, $store->find($app['id'])['nginx_routes']);
    }

    public function testWriteConfigThrowRollsBackAndNeverReloads(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'gagal-tulis', 'nginx_routes' => $this->oldRoutes]);

        $deployer = new RecordingNginxDeployer();
        $deployer->failOnCall = 1;
        $deployer->failMessage = 'write gagal';
        $reloader = new FakeGuardReloader(['ok' => true, 'error' => null]);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);
        $this->assertSame('Gagal menulis config Nginx: write gagal', $result['error']);
        $this->assertSame(0, $reloader->calls, 'reload() tidak boleh dipanggil saat tulis config gagal');
        $this->assertSame($this->oldRoutes, $store->find($app['id'])['nginx_routes']);
    }

    public function testMissingAppReturnsErrorWithoutSideEffects(): void
    {
        $store = new AppStore($this->file);
        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => true, 'error' => null]);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes('tidak-ada', $this->newRoutes);

        $this->assertSame(
            ['ok' => false, 'reloaded' => false, 'rolled_back' => false, 'error' => 'App tidak ditemukan.'],
            $result
        );
        $this->assertCount(0, $deployer->writes);
        $this->assertSame(0, $reloader->calls);
    }

    public function testReloaderThrowableIsCaughtAndRolledBack(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'reloader-lempar', 'nginx_routes' => $this->oldRoutes]);

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => true], new RuntimeException('helper container mati'));
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);
        $this->assertSame('helper container mati', $result['error']);
        $this->assertSame(1, $reloader->calls);
        $this->assertCount(2, $deployer->writes);
        $this->assertSame($this->oldRoutes, $deployer->writes[1]['routes']);
        $this->assertSame($this->oldRoutes, $store->find($app['id'])['nginx_routes']);
    }

    public function testRollbackRestoresEmptyWhenAppHadNoRoutesKey(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'tanpa-key']); // tanpa nginx_routes

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => null]);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);
        $this->assertSame('nginx menolak config', $result['error'], 'fallback pesan error saat reloader tidak memberi error');
        $this->assertSame([], $store->find($app['id'])['nginx_routes']);
        $this->assertCount(2, $deployer->writes);
        $this->assertSame([], $deployer->writes[1]['routes']);
    }

    public function testRollbackStoreFailureIsReportedNotThrown(): void
    {
        $store = new FailingRollbackAppStore($this->file);
        $app = $store->create(['name' => 'rollback-store-gagal', 'nginx_routes' => $this->oldRoutes]);

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => 'nginx: [emerg] bad upstream']);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        // #1 = persist rute baru (sukses), #2 = rollback store (lempar).
        $store->failOnCall = 2;

        // Tidak boleh ada exception yang bocor keluar.
        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertSame(['ok', 'reloaded', 'rolled_back', 'error'], array_keys($result));
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded']);
        $this->assertFalse($result['rolled_back'], 'rolled_back=false karena rute lama gagal dipulihkan');
        $this->assertStringContainsString('nginx: [emerg] bad upstream', $result['error'], 'error asli tidak boleh ditelan');
        $this->assertStringContainsString('PERINGATAN: rollback gagal', $result['error']);
        $this->assertStringContainsString($store->failMessage, $result['error']);
        $this->assertCount(1, $deployer->writes, 'hanya penulisan config rute baru sebelum rollback');
    }

    // ==================================================================
    // applySubdomain(): rollback state harus membedakan "key absen" dari
    // "key bernilai null" (sentinel ABSENT) — bentuk apps.json harus stabil.
    // ==================================================================

    /**
     * Daftar key state subdomain yang disnapshot/dipulihkan guard
     * (salinan lokal dari NginxConfigGuard::SUBDOMAIN_STATE_KEYS yang privat).
     *
     * @return array<int,string>
     */
    private function subdomainKeys(): array
    {
        return [
            'subdomain',
            'ssl_status',
            'ssl_stage',
            'ssl_message',
            'ssl_error',
            'ssl_expires_at',
            'needs_ssl',
        ];
    }

    public function testSubdomainRollbackKeepsAbsentKeysAbsent(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'absen']); // tanpa key subdomain/ssl_*

        $saved = $store->find($app['id']);
        foreach ($this->subdomainKeys() as $key) {
            $this->assertArrayNotHasKey($key, $saved, "prasyarat: '$key' semula absen");
        }

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => 'nginx: [emerg] bad config']);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applySubdomain($app['id'], 'newlabel');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);

        $saved = $store->find($app['id']);
        foreach ($this->subdomainKeys() as $key) {
            $this->assertArrayNotHasKey($key, $saved, "key '$key' semula absen harus tetap absen setelah rollback");
        }
    }

    public function testSubdomainRollbackKeepsExplicitNullValues(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp([
            'name' => 'null-eksplisit',
            'subdomain' => 'oldlabel',
            'ssl_status' => 'active',
            'ssl_stage' => null,
            'ssl_message' => 'aktif',
            'ssl_error' => null,
            'ssl_expires_at' => null,
            'needs_ssl' => true,
        ]);

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => 'nginx: [emerg] bad config']);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applySubdomain($app['id'], 'newlabel');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);

        $saved = $store->find($app['id']);
        // Key yang semula bernilai null eksplisit harus TETAP null & key-nya ADA
        // (dulu ikut terhapus karena null dipakai sebagai penanda "absen").
        foreach (['ssl_stage', 'ssl_error', 'ssl_expires_at'] as $key) {
            $this->assertArrayHasKey($key, $saved, "key '$key' semula ada (null) harus tetap ada");
            $this->assertNull($saved[$key], "key '$key' semula null harus tetap null");
        }
    }

    public function testSubdomainRollbackRestoresNonNullValuesVerbatim(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp([
            'name' => 'non-null',
            'subdomain' => 'keepme',
            'ssl_status' => 'pending',
            'ssl_stage' => 'challenge',
            'ssl_message' => 'menunggu',
            'ssl_error' => 'pesan error lama',
            'ssl_expires_at' => '2030-01-01T00:00:00+00:00',
            'needs_ssl' => true,
        ]);

        $deployer = new RecordingNginxDeployer();
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => 'nginx: [emerg] bad config']);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applySubdomain($app['id'], 'newlabel');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);

        $saved = $store->find($app['id']);
        $this->assertSame('keepme', $saved['subdomain']);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('challenge', $saved['ssl_stage']);
        $this->assertSame('menunggu', $saved['ssl_message']);
        $this->assertSame('pesan error lama', $saved['ssl_error']);
        $this->assertSame('2030-01-01T00:00:00+00:00', $saved['ssl_expires_at']);
        $this->assertTrue($saved['needs_ssl']);
    }

    public function testSubdomainWriteConfigThrowRollsBackAndNeverReloads(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp([
            'name' => 'gagal-tulis-subdomain',
            'subdomain' => 'oldlabel',
            'ssl_status' => 'active',
            'ssl_error' => null,
        ]);

        $deployer = new RecordingNginxDeployer();
        $deployer->failOnCall = 1;
        $deployer->failMessage = 'write gagal';
        $reloader = new FakeGuardReloader(['ok' => true, 'error' => null]);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applySubdomain($app['id'], 'newlabel');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back']);
        $this->assertSame('Gagal menulis config Nginx: write gagal', $result['error']);
        $this->assertSame(0, $reloader->calls, 'reload() tidak boleh dipanggil saat tulis config gagal');

        $saved = $store->find($app['id']);
        $this->assertSame('oldlabel', $saved['subdomain']);
        $this->assertSame('active', $saved['ssl_status']);
        $this->assertArrayHasKey('ssl_error', $saved, 'null eksplisit harus tetap ada');
        $this->assertNull($saved['ssl_error']);
        $this->assertArrayNotHasKey('ssl_stage', $saved, 'key yang semula absen harus tetap absen');
    }

    public function testRollbackConfigRewriteFailureAddsWarning(): void
    {
        $store = new AppStore($this->file);
        $app = $this->seedApp(['name' => 'rewrite-config-gagal', 'nginx_routes' => $this->oldRoutes]);

        $deployer = new RecordingNginxDeployer();
        $deployer->failOnCall = 2; // write #1 (rute baru) sukses, write #2 (config lama) lempar
        $deployer->failMessage = 'disk penuh';
        $reloader = new FakeGuardReloader(['ok' => false, 'error' => 'nginx: [emerg] bad upstream']);
        $guard = new NginxConfigGuard($reloader, $deployer, $store);

        $result = $guard->applyRoutes($app['id'], $this->newRoutes);

        $this->assertSame(['ok', 'reloaded', 'rolled_back', 'error'], array_keys($result));
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded']);
        $this->assertTrue($result['rolled_back'], 'rute lama di apps.json berhasil dipulihkan');
        $this->assertStringContainsString('nginx: [emerg] bad upstream', $result['error'], 'error asli tidak boleh ditelan');
        $this->assertStringContainsString('PERINGATAN: config lama gagal ditulis ulang', $result['error']);
        $this->assertStringContainsString('disk penuh', $result['error']);
        $this->assertStringContainsString('(periksa/Deploy Ulang)', $result['error']);
        $this->assertSame($this->oldRoutes, $store->find($app['id'])['nginx_routes']);
        $this->assertCount(2, $deployer->writes);
    }
}
