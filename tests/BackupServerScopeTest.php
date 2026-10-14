<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Auth\AppAccessDenied;
use app\library\Backup\BackupAccess;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupJobRunner;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupSelection;
use app\library\Backup\VolumeTargetMap;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Dispatcher backup/restore/dump **antar server** (multi-server F4, plan
 * §4.2/§4.3/§5) — kontrak yang dikunci:
 *  1. volume app di server agent dipicu sebagai **job agent** (`backup_run`/
 *     `restore`/`db_dump`) dan **tidak** menyentuh daemon/restic host control
 *     plane (socket diarahkan ke path mati: apa pun yang menyentuh lokal akan
 *     gagal);
 *  2. volume server lokal **tidak** pernah dikirim ke agent (perilaku lama jalur
 *     `cli/backup.php` dipertahankan: dispatcher tidak dipanggil sama sekali);
 *  3. riwayat (`BackupRegistry`) & seleksi (`BackupSelection`) ter-scope
 *     `server_id` ⇒ dua volume bernama sama di dua server tidak bertabrakan;
 *  4. kredensial tidak pernah masuk payload job/rekaman/log (payload hanya
 *     `volume`/`snapshot`/`trigger`; agent memakai env-nya sendiri);
 *  5. hak akses tidak berubah: `backup` = operator+, `restore` = owner;
 *     penolakan = `AppAccessDenied` (404);
 *  6. op yang belum didukung untuk server agent ⇒ **fail-fast** (tanpa fallback
 *     lokal, tanpa sukses palsu).
 *
 * Seluruh uji: `/tmp`, subproses CLI dengan dispatcher tiruan, tanpa jaringan &
 * tanpa Docker/restic nyata.
 */
class BackupServerScopeTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';

    private const OTHER_APP_ID = 'b1c2d3e4f5061728';

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_backupscope_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $this->tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        SqliteFixture::apps($this->tmp . '/rames.sqlite', [
            ['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'srv-a', 'status' => 'running', 'owner_id' => 'u1', 'members' => []],
            ['id' => self::OTHER_APP_ID, 'name' => 'demo', 'server_id' => 'srv-b', 'status' => 'running', 'owner_id' => 'u1', 'members' => []],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) app agent ⇒ job agent, bukan daemon lokal
    // ==================================================================

    public function testAgentVolumeIsBackedUpAtTheAgentAndNeverLocally(): void
    {
        $handler = new BackupServerScopeFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'data' => ['volumes' => [
                ['name' => 'demo_data', 'project' => 'demo', 'app_id' => self::APP_ID, 'orphaned' => false, 'strategy' => 'snapshot'],
            ], 'status' => []]]))
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-1', 'op' => 'backup_run', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done', 'message' => 'ok di agent']]))
            ->reply(200, '')
            ->reply(200, '')
            ->reply(200, json_encode(['code' => 0, 'data' => ['volume' => 'demo_data', 'snapshots' => [
                ['id' => 'aaaaaaaa', 'time' => '2026-10-14T00:00:00Z'],
            ]]]));

        $dispatcher = $this->dispatcher($handler);
        $result = $dispatcher->runBackup('srv-a', 'demo_data', 'manual');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('srv-a', $result['server_id']);

        // Satu-satunya panggilan HTTP = ke server itu (POST /jobs), tidak ada
        // eksekusi lokal: socket Docker di path mati tidak pernah dipakai.
        $this->assertSame('POST', $handler->calls[1]['method']);
        $this->assertSame('/jobs', $handler->calls[1]['path']);
        $body = $handler->jsonBody(1);
        $this->assertSame('backup_run', $body['op']);
        $this->assertSame('demo_data', $body['volume']);
        $this->assertSame('manual', $body['trigger']);
        $this->assertSame(self::APP_ID, $body['app']['id']);

        // Riwayat dicatat **di control plane** dengan kunci ter-scope server.
        $registry = (new BackupRegistry($this->tmp . '/rames.sqlite'))->read();
        $this->assertArrayHasKey('srv-a::demo_data', $registry);
        $this->assertSame('srv-a', $registry['srv-a::demo_data']['server_id']);
        $this->assertSame(1, $registry['srv-a::demo_data']['snapshots']);
    }

    public function testEveryDataOpDispatchesToTheAgentThroughTheCliWorker(): void
    {
        $cases = [
            ['run', ['demo_data', 'manual', 'srv-a'], 'runBackup:srv-a:demo_data:manual'],
            ['restore', [self::APP_ID, 'demo_data', 'abcdef12', 'srv-a'], 'restore:srv-a:' . self::APP_ID . ':demo_data:abcdef12'],
            ['db-dump', [self::APP_ID, 'demo_data', 'srv-a'], 'dbDump:srv-a:' . self::APP_ID . ':demo_data'],
        ];

        $log = $this->tmp . '/dispatch.log';

        foreach ($cases as [$mode, $args, $expectedNote]) {
            @unlink($log);
            $result = $this->runCli(
                array_merge([$mode], $args),
                [
                    'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
                    'FAKE_BACKUP_DISPATCH_LOG' => $log,
                ]
            );

            $this->assertSame(0, $result['code'], "{$mode} harus sukses lewat dispatcher; stderr: " . $result['stderr']);
            $this->assertSame($expectedNote . "\n", (string) @file_get_contents($log), "{$mode} wajib dipicu sebagai job server agent");
        }
    }

    public function testAgentDispatchFailuresAreReportedNotSwallowed(): void
    {
        $result = $this->runCli(
            ['run', 'demo_data', 'manual', 'srv-a'],
            [
                'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
                'FAKE_BACKUP_DISPATCH_LOG' => $this->tmp . '/dispatch.log',
                'FAKE_BACKUP_DISPATCH_FAIL' => '1',
            ]
        );

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('fake dispatcher gagal', $result['stderr']);
    }

    // ==================================================================
    // (2) jalur lokal — regresi eksplisit
    // ==================================================================

    public function testLocalPathNeverUsesTheAgentDispatcher(): void
    {
        $log = $this->tmp . '/dispatch-local.log';
        @unlink($log);

        // Tanpa `server_id`: jalur lokal (perilaku lama). Socket Docker diarahkan
        // ke path mati ⇒ jalur lokal memang gagal (itu benar), tetapi dispatcher
        // agent **tidak** boleh dipanggil sama sekali.
        $result = $this->runCli(
            ['run', 'demo_data', 'manual'],
            [
                'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
                'FAKE_BACKUP_DISPATCH_LOG' => $log,
            ]
        );

        $this->assertSame(1, $result['code'], 'jalur lokal tanpa Engine harus gagal jujur');
        $this->assertFileDoesNotExist($log, 'dispatcher agent tidak boleh dipanggil untuk volume lokal');
    }

    public function testDispatcherClassificationAndFailFast(): void
    {
        $this->assertTrue(BackupDispatcher::isLocalServer(''));
        $this->assertTrue(BackupDispatcher::isLocalServer(ServerStore::LOCAL_ID));
        $this->assertFalse(BackupDispatcher::isLocalServer('srv-a'));

        // Op arsip kini **didukung** untuk server agent (job tingkat server
        // `restore_archived`: volume target dibuat & restic dijalankan di server
        // itu) — tetapi op yang tidak dikenal tetap ditolak sebelum efek samping.
        BackupDispatcher::assertSupported('restore-archived', 'srv-a');
        try {
            BackupDispatcher::assertSupported('op-hantu', 'srv-a');
            $this->fail('op tak dikenal di server agent harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('op-hantu', $e->getMessage());
        }

        BackupDispatcher::assertSupported('restore-archived', '');
        BackupDispatcher::assertSupported('run', 'srv-a');
    }

    public function testDispatcherRefusesUnknownServerAndForeignOwner(): void
    {
        $handler = new BackupServerScopeFakeHandler();
        $dispatcher = $this->dispatcher($handler, $this->resolver([[
            'id' => 'srv-a', 'name' => 'agent-a', 'kind' => 'agent', 'enabled' => true,
            'base_url' => 'https://agent-a.test', 'token' => 'tok',
            'tls_cert_pem' => BackupServerScopePem::selfSigned(),
        ]]));

        try {
            $dispatcher->runBackup('srv-tidak-ada', 'demo_data');
            $this->fail('server tak dikenal harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('srv-tidak-ada', $e->getMessage());
        }

        // Volume di server A tetapi app terdaftar di server B ⇒ tolak (mencegah
        // backup/restore di server yang salah).
        $handler->reply(200, json_encode(['code' => 0, 'data' => ['volumes' => [
            ['name' => 'demo_data', 'project' => 'demo', 'app_id' => self::OTHER_APP_ID, 'orphaned' => false],
        ], 'status' => []]]));
        try {
            $dispatcher->runBackup('srv-a', 'demo_data');
            $this->fail('app dengan server_id berbeda harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('srv-b', $e->getMessage());
        }

        // Volume yatim (tanpa app) belum didukung ⇒ tolak dengan pesan jelas.
        $handler->reply(200, json_encode(['code' => 0, 'data' => ['volumes' => [], 'status' => []]]));
        try {
            $dispatcher->runBackup('srv-a', 'demo_data');
            $this->fail('volume yatim harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('yatim', $e->getMessage());
        }
    }

    // ==================================================================
    // (3) registry & seleksi ter-scope server
    // ==================================================================

    public function testRegistryAndSelectionAreScopedByServer(): void
    {
        $registry = new BackupRegistry($this->tmp . '/frames.sqlite');
        $registry->upsertMany(['demo_data' => ['app_id' => self::APP_ID, 'project' => 'demo', 'snapshots' => 2, 'bytes' => 100]], 'srv-a');
        $registry->upsertMany(['demo_data' => ['app_id' => self::OTHER_APP_ID, 'project' => 'demo', 'snapshots' => 5, 'bytes' => 900]], 'srv-b');
        // Jalur lokal (tanpa server_id) tetap memakai kunci nama volume apa adanya.
        $registry->upsertMany(['demo_data' => ['app_id' => 'local-app', 'project' => 'demo', 'snapshots' => 1, 'bytes' => 10]]);

        $entries = $registry->read();
        $this->assertArrayHasKey('demo_data', $entries, 'kunci server lokal tidak berubah (data lama tetap sah)');
        $this->assertArrayHasKey('srv-a::demo_data', $entries);
        $this->assertArrayHasKey('srv-b::demo_data', $entries);
        $this->assertSame(1, $entries['demo_data']['snapshots']);
        $this->assertSame(2, $entries['srv-a::demo_data']['snapshots']);
        $this->assertSame(5, $entries['srv-b::demo_data']['snapshots']);
        $this->assertSame('srv-a', $entries['srv-a::demo_data']['server_id']);
        $this->assertSame(ServerStore::LOCAL_ID, $entries['demo_data']['server_id']);

        // Helper kunci: kublik & idempotent.
        $this->assertSame('demo_data', BackupRegistry::scopeKey(ServerStore::LOCAL_ID, 'demo_data'));
        $this->assertSame('demo_data', BackupRegistry::scopeKey('', 'demo_data'));
        $this->assertSame('srv-a::demo_data', BackupRegistry::scopeKey('srv-a', 'demo_data'));
        $this->assertSame('demo_data', BackupRegistry::volumeOfKey('srv-a::demo_data'));
        $this->assertSame('srv-a', BackupRegistry::serverOfKey('srv-a::demo_data'));
        $this->assertSame(ServerStore::LOCAL_ID, BackupRegistry::serverOfKey('demo_data'));

        // Prune counts (repo restic server LOKAL) tidak boleh menyentuh entri
        // server agent meski namanya sama.
        $registry->syncCounts(['demo_data' => 3]);
        $after = $registry->read();
        $this->assertSame(3, $after['demo_data']['snapshots']);
        $this->assertArrayHasKey('srv-a::demo_data', $after, 'entri server agent tidak dipangkas oleh counts lokal');
        $this->assertSame(2, $after['srv-a::demo_data']['snapshots']);
        $this->assertArrayHasKey('srv-b::demo_data', $after);

        // Seleksi jadwal juga ter-scope.
        $selection = new BackupSelection($this->tmp . '/frames.sqlite');
        $selection->setScheduled('demo_data', true, 'u1', 'srv-a');
        $selection->setScheduled('demo_data', false, 'u1', 'srv-b');
        $selection->setScheduled('demo_data', true, 'u1');

        $this->assertTrue($selection->isScheduled('demo_data'));
        $this->assertTrue($selection->isScheduled('demo_data', 'srv-a'));
        $this->assertFalse($selection->isScheduled('demo_data', 'srv-b'));
        $this->assertSame(['demo_data' => true], $selection->explicit('srv-a'));
        $this->assertSame(['demo_data' => false], $selection->explicit('srv-b'));
    }

    // ==================================================================
    // (4) higienitas kredensial
    // ==================================================================

    public function testCredentialsNeverTravelInTheAgentPayload(): void
    {
        $handler = new BackupServerScopeFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'data' => ['volumes' => [
                ['name' => 'demo_data', 'project' => 'demo', 'app_id' => self::APP_ID, 'orphaned' => false],
            ], 'status' => []]]))
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-9', 'op' => 'backup_run', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done', 'message' => 'ok']]))
            ->reply(200, '')
            ->reply(200, '')
            ->reply(200, json_encode(['code' => 0, 'data' => ['volume' => 'demo_data', 'snapshots' => []]]));

        $logs = [];
        $this->dispatcher($handler)->runBackup('srv-a', 'demo_data', 'manual', function (string $stage, string $message) use (&$logs): void {
            $logs[] = $stage . '|' . $message;
        });

        // Payload job: hanya op/app/penanda — tidak ada kunci kredensial.
        $body = $handler->jsonBody(1);
        $this->assertSame(['volume', 'trigger', 'op', 'app'], array_keys($body));
        $raw = $handler->calls[1]['body'];
        foreach (['secret', 'password', 'passphrase', 'token', 'AKIA', 'MYSQL_PWD'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, 'payload tidak boleh memuat kredensial');
        }

        // Log tidak memuat kredensial (pesan hanya identitas volume/server).
        foreach ($logs as $line) {
            foreach (['secret', 'password', 'passphrase', 'AKIA'] as $needle) {
                $this->assertStringNotContainsString($needle, $line);
            }
        }
    }

    // ==================================================================
    // (5) hak akses tidak berubah (404, bukan 403)
    // ==================================================================

    public function testAgentVolumeKeepsOperatorOwnerAndStrangerRules(): void
    {
        $target = ['name' => 'demo_data', 'project' => 'demo', 'app_id' => self::APP_ID, 'app_name' => 'demo', 'orphaned' => false, 'server_id' => 'srv-a'];
        $app = [
            'id' => self::APP_ID,
            'name' => 'demo',
            'owner_id' => 'u1',
            'members' => ['u2' => ['role' => 'operator'], 'u3' => ['role' => 'viewer']],
        ];

        $owner = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
        $operator = ['id' => 'u2', 'username' => 'op', 'role' => 'member'];
        $viewer = ['id' => 'u3', 'username' => 'viewer', 'role' => 'member'];
        $stranger = ['id' => 'u8', 'username' => 'stranger', 'role' => 'member'];

        $this->assertTrue(BackupAccess::can('backup', $target, $app, $owner));
        $this->assertTrue(BackupAccess::can('backup', $target, $app, $operator));
        $this->assertFalse(BackupAccess::can('backup', $target, $app, $viewer));
        $this->assertFalse(BackupAccess::can('backup', $target, $app, $stranger));

        // `restore` destruktif ⇒ owner-only.
        $this->assertTrue(BackupAccess::can('restore', $target, $app, $owner));
        $this->assertFalse(BackupAccess::can('restore', $target, $app, $operator));

        try {
            BackupAccess::require('restore', $target, $app, $operator);
            $this->fail('operator tidak boleh restore');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode(), 'penolakan tetap 404 (bukan 403)');
            $this->assertSame(self::APP_ID, $e->appId);
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function dispatcher(BackupServerScopeFakeHandler $handler, ?ServerResolver $resolver = null): BackupDispatcher
    {
        $server = [
            'id' => 'srv-a',
            'name' => 'agent-a',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://agent-a.test',
            'token' => 'tok-uji-f4',
            'tls_cert_pem' => BackupServerScopePem::selfSigned(),
        ];

        $deployer = new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls')),
            30,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
            }
        );

        return new BackupDispatcher(
            static fn (array $s): ?AgentDeployer => $deployer,
            $resolver ?? $this->resolver([$server]),
            new AppStore($this->tmp . '/rames.sqlite')
        );
    }

    /**
     * @param array<int,array<string,mixed>> $servers
     */
    private function resolver(array $servers): ServerResolver
    {
        $path = $this->tmp . '/servers-' . bin2hex(random_bytes(3)) . '.sqlite';
        $store = new ServerStore($path);
        foreach ($servers as $server) {
            $existing = $store->find((string) $server['id']);
            if ($existing === null) {
                try {
                    $store->create($server);
                    continue;
                } catch (\Throwable) {
                    // slug/validasi tidak cocok: perbarui bila sudah ada.
                }
            }
            $store->update((string) $server['id'], static function (array &$row) use ($server): void {
                $row = array_merge($row, $server);
            });
        }

        ServerResolver::forgetMemo();

        return new ServerResolver($store);
    }

    /**
     * Jalankan `cli/backup.php` sebagai subproses nyata dengan path temp & socket
     * mati (tanpa Docker/restic/jaringan).
     *
     * @param array<int,string>    $args
     * @param array<string,string> $extra
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCli(array $args, array $extra = []): array
    {
        $env = array_merge([
            'DATABASE_PATH' => $this->tmp,
            'RAMES_DB_DIR' => $this->tmp,
            'APPS_PATH' => $this->tmp . '/apps',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
        ], $extra);

        return (new ProcessRunner())->run(
            array_merge([PHP_BINARY, dirname(__DIR__) . '/cli/backup.php'], $args),
            dirname(__DIR__),
            120,
            $env
        );
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
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

/** Handler Guzzle tiruan (tanpa jaringan) untuk `AgentClient`/`AgentDeployer`. */
final class BackupServerScopeFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string}> */
    public array $calls = [];

    /** @var list<\GuzzleHttp\Psr7\Response|\Throwable> */
    private array $queue = [];

    public function reply(int $status, string $body = ''): self
    {
        $this->queue[] = new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('BackupServerScopeFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return \GuzzleHttp\Promise\Create::promiseFor($next);
    }

    /** @return array<string,mixed> */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->calls[$index]['body'] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }
}

/** Sertifikat self-signed kecil untuk pinning `AgentClient` di uji. */
final class BackupServerScopePem
{
    public static function selfSigned(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            throw new RuntimeException('gagal membuat kunci uji');
        }
        $csr = openssl_csr_new(['commonName' => 'agent-a.test'], $resource, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $resource, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
