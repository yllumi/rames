<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentAppProjection;
use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proyeksi app di sisi agent (`AgentAppProjection`, plan §4.3 — misi F1).
 *
 * Yang dikunci:
 *  1. **metadata menang control plane** (ditimpa dari payload), **state
 *     eksekusi milik agent** (tidak pernah hilang saat app sudah ada) — dua
 *     himpunan field yang disjoint, jadi tidak ada dua penulis satu field;
 *  2. **`server_id` dipetakan ke `'local'`**: registry control plane tidak ada
 *     di agent, sehingga tanpa pemetaan ini `ServerResolver`/`DeployerFactory`
 *     di agent melempar "server tak dikenal" dan seluruh deploy gagal;
 *  3. idempoten (job berulang tidak menggandakan app);
 *  4. tidak menyentuh store user/billing/registry server.
 *
 * Plus penjaga **nama app** (jebakan "app tidak bisa dibuat maupun dihapus"):
 * baris basi bernama sama dengan id berbeda dibuang sebelum proyeksi baru, dan
 * `forget()` menghapus proyeksi saat app dihapus di control plane.
 *
 * Semua uji memakai basis data SQLite **temp** — `database/rames.sqlite`,
 * `apps/`, dan `runtime/` nyata tidak pernah disentuh.
 */
class AgentAppProjectionTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';

    private string $tmp = '';
    private string $db = '';
    private AgentAppProjection $projection;
    private AppStore $apps;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_projection_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        $this->db = $this->tmp . '/rames.sqlite';
        $this->projection = new AgentAppProjection($this->db);
        $this->apps = new AppStore($this->db);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            is_dir($file) || @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testFirstProjectionWritesMetadataAndMapsServerIdToLocal(): void
    {
        $stored = $this->projection->upsert($this->payload());

        $this->assertSame(self::APP_ID, $stored['id']);
        // server_id dari registry control plane ("abc123") TIDAK boleh masuk
        // apa adanya ke store agent.
        $this->assertSame(ServerStore::LOCAL_ID, $stored['server_id']);
        $this->assertSame('demo', $stored['name']);
        $this->assertSame('https://example.test/a.git', $stored['repo_url']);
        $this->assertSame(['APP_ENV' => 'prod'], $stored['env']);
        $this->assertSame('demo@demo.example.test', $stored['nginx_routes'][0]['server_name']);

        // Benih state eksekusi dari control plane (salinan hasil sinkron §4.3).
        $this->assertSame('running', $stored['status']);
        $this->assertSame([['name' => 'demo-web-1']], $stored['containers']);
        $this->assertSame('issued', $stored['ssl_status']);

        $persisted = $this->apps->find(self::APP_ID);
        $this->assertNotNull($persisted);
        $this->assertSame(ServerStore::LOCAL_ID, $persisted['server_id']);
    }

    /**
     * Alasan pemetaan `server_id` → `local`: app hasil proyeksi harus bisa
     * di-resolve `ServerResolver`, sementara payload mentah (id server control
     * plane) memang tak dikenal di agent.
     */
    public function testProjectedAppResolvesToLocalServerWhileRawPayloadDoesNot(): void
    {
        $raw = $this->payload();
        $resolver = new ServerResolver(new ServerStore($this->db));

        try {
            $resolver->resolveForApp($raw);
            $this->fail('payload mentah (server_id=abc123) harus ditolak — registry control plane tidak ada di agent');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('abc123', $e->getMessage());
        }

        $stored = $this->projection->upsert($raw);
        $resolved = $resolver->resolveForApp($stored);

        $this->assertSame(ServerStore::LOCAL_ID, $resolved['id']);
        $this->assertSame('local', $resolved['kind']);
    }

    public function testMetadataIsOverwrittenFromPayloadButExecutionStateIsKept(): void
    {
        $this->projection->upsert($this->payload());

        // Job kedua: metadata baru (control plane menang) + state eksekusi
        // "salinan basi" dari control plane — yang terakhir TIDAK boleh dipakai.
        $updated = $this->projection->upsert($this->payload([
            'name' => 'demo',
            'branch' => 'release',
            'env' => ['APP_ENV' => 'staging'],
            'owner_id' => 'u2',
            'members' => ['u3' => ['role' => 'viewer']],
            'status' => 'error',
            'stage' => 'failed',
            'message' => 'salinan basi control plane',
            'error' => 'salinan basi',
            'containers' => [],
            'deploy_history' => [],
            'ssl_status' => 'pending',
        ]));

        $this->assertSame('release', $updated['branch']);
        $this->assertSame(['APP_ENV' => 'staging'], $updated['env']);
        $this->assertSame('u2', $updated['owner_id']);
        $this->assertSame(['u3' => ['role' => 'viewer']], $updated['members']);

        $this->assertSame('running', $updated['status'], 'status eksekusi milik agent');
        $this->assertSame('issued', $updated['ssl_status'], 'ssl_status milik agent');
        $this->assertSame([['name' => 'demo-web-1']], $updated['containers'], 'containers milik agent');
        $this->assertSame(
            [['sha' => 'deadbeef', 'status' => 'success']],
            $updated['deploy_history'],
            'deploy_history milik agent'
        );
    }

    /**
     * Baris app yang sudah ada tetapi `server_id`-nya masih menunjuk server
     * control plane (baris lama/masukan manual — plan §4.3: "edit manual di DB
     * agent ditimpa pada job berikutnya") **wajib** dikembalikan ke `local` oleh
     * proyeksi berikutnya; tanpa ini `ServerResolver` di agent melempar dan app
     * itu tidak bisa di-deploy lagi.
     */
    public function testUpsertRepairsExistingRowPointingAtControlPlaneServer(): void
    {
        $this->apps->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'abc123',
            'status' => 'running',
        ]);

        $stored = $this->projection->upsert($this->payload(['branch' => 'main4']));

        $this->assertSame(ServerStore::LOCAL_ID, $stored['server_id']);
        $this->assertSame(ServerStore::LOCAL_ID, $this->apps->find(self::APP_ID)['server_id']);
        $this->assertSame('main4', $stored['branch']);
        $this->assertSame('running', $stored['status'], 'state eksekusi baris lama dipertahankan');
    }

    public function testUpsertIsIdempotent(): void
    {
        $first = $this->projection->upsert($this->payload());
        $second = $this->projection->upsert($this->payload(['branch' => 'main2']));
        $third = $this->projection->upsert($this->payload(['branch' => 'main3']));

        $this->assertCount(1, $this->apps->all(), 'job berulang tidak boleh menggandakan app');
        $this->assertSame(self::APP_ID, $second['id']);
        $this->assertSame(self::APP_ID, $third['id']);
        $this->assertSame($first['created_at'], $third['created_at'], 'created_at tidak berubah oleh proyeksi ulang');
        $this->assertSame('main3', $this->apps->find(self::APP_ID)['branch']);
    }

    /**
     * Baris basi dengan **nama sama** tapi id app lain (sisa app yang sudah
     * dihapus di control plane) dibuang sebelum proyeksi baru — kalau tidak,
     * `AppStore::create()` menolak dan app tidak bisa dibuat maupun dihapus.
     */
    public function testUpsertReplacesStaleRowHoldingTheSameName(): void
    {
        $this->apps->create([
            'id' => 'basi1234567890ab',
            'name' => 'demo',
            'status' => 'stopped',
            'deploy_history' => [['sha' => 'old', 'status' => 'success']],
        ]);

        $stored = $this->projection->upsert($this->payload());

        $this->assertSame(self::APP_ID, $stored['id']);
        $this->assertNull($this->apps->find('basi1234567890ab'), 'baris basi dibuang');
        $this->assertSame(self::APP_ID, $this->apps->findByNameOnServer('demo', ServerStore::LOCAL_ID)['id']);
        $this->assertCount(1, $this->apps->all());
    }

    /** Nama sama di server scope lain (bukan `local`) tidak diganggu. */
    public function testUpsertKeepsSameNameRowOfAnotherServerScope(): void
    {
        $this->apps->create(['id' => 'serverlain123456', 'name' => 'demo', 'server_id' => 'abc123']);

        $this->projection->upsert($this->payload());

        $this->assertNotNull($this->apps->find('serverlain123456'));
        $this->assertNotNull($this->apps->find(self::APP_ID));
    }

    /** Proyeksi ulang app yang sama (id sama) tidak pernah menghapus barisnya sendiri. */
    public function testUpsertNeverDropsItsOwnRow(): void
    {
        $this->projection->upsert($this->payload(['status' => 'running']));
        $this->projection->upsert($this->payload(['branch' => 'main5']));

        $this->assertSame('running', $this->apps->find(self::APP_ID)['status'], 'state eksekusi milik agent tetap utuh');
        $this->assertSame('main5', $this->apps->find(self::APP_ID)['branch']);
        $this->assertCount(1, $this->apps->all());
    }

    public function testForgetRemovesProjectionAndIsIdempotent(): void
    {
        $this->projection->upsert($this->payload());

        $this->projection->forget(self::APP_ID);
        $this->assertNull($this->apps->find(self::APP_ID), 'proyeksi app dihapus (nama dibebaskan)');

        // Idempoten: app yang tidak ada = bukan kegagalan (teardown app yang
        // belum pernah diproyeksikan juga memanggil ini).
        $this->projection->forget(self::APP_ID);
        $this->projection->forget('tidakada12345678');
        $this->assertSame([], $this->apps->all());

        // Sesudah forget, nama yang sama bisa diproyeksikan lagi (create, bukan
        // ditolak).
        $again = $this->projection->upsert($this->payload());
        $this->assertSame(self::APP_ID, $again['id']);
    }

    public function testForgetRejectsUnsafeId(): void
    {
        foreach (['', '   ', '../../database/auth.json', 'app/../x'] as $bad) {
            try {
                $this->projection->forget($bad);
                $this->fail('id tak aman harus ditolak');
            } catch (InvalidArgumentException) {
                // diharapkan
            }
        }

        $this->assertSame([], $this->apps->all());
    }

    public function testUpsertTouchesOnlyAppsStore(): void
    {
        $this->projection->upsert($this->payload());

        $this->assertSame([], (new UserStore($this->db))->all(), 'user/role tidak boleh ikut diproyeksikan');

        $billing = (new BillingStore($this->db))->read();
        $this->assertSame([], $billing['users'], 'billing tidak boleh disentuh');
        $this->assertSame([], $billing['usage'], 'usage billing tidak boleh disentuh');
        $this->assertSame([], $billing['orders'], 'order billing tidak boleh disentuh');

        $this->assertSame(
            [ServerStore::LOCAL_ID],
            array_map(static fn (array $s): string => (string) $s['id'], (new ServerStore($this->db))->all()),
            'registry server tetap hanya berisi server lokal'
        );
    }

    public function testUpsertRejectsMissingOrUnsafeIdentity(): void
    {
        foreach ([
            $this->payload(['id' => '']),
            $this->payload(['id' => '../../database/auth.json']),
            $this->payload(['id' => 'app/../x']),
            $this->payload(['name' => '   ']),
        ] as $bad) {
            try {
                $this->projection->upsert($bad);
                $this->fail('identitas app tak sah harus ditolak');
            } catch (InvalidArgumentException) {
                // diharapkan
            }
        }

        $this->assertSame([], $this->apps->all(), 'tidak ada app yang tertulis dari payload tak sah');
    }

    public function testMetadataAndExecutionFieldSetsAreDisjoint(): void
    {
        $this->assertSame(
            [],
            array_intersect(AgentAppProjection::METADATA, AgentAppProjection::EXECUTION),
            'satu field tidak boleh punya dua penulis (§4.3)'
        );
        $this->assertNotContains('server_id', AgentAppProjection::METADATA);
        $this->assertNotContains('server_id', AgentAppProjection::EXECUTION);
        $this->assertContains('name', AgentAppProjection::METADATA);
        $this->assertContains('deploy_history', AgentAppProjection::EXECUTION);
        $this->assertSame($this->db, $this->projection->path());
    }

    /**
     * Dokumen app seperti yang dikirim control plane pada `POST /jobs`
     * (`server_id` = id server di registry control plane).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'abc123',
            'source' => 'git',
            'repo_url' => 'https://example.test/a.git',
            'branch' => 'main',
            'compose_files' => [],
            'env' => ['APP_ENV' => 'prod'],
            'limits' => ['cpus' => '1'],
            'container_prefix' => 'demo',
            'primary_service' => 'web',
            'primary_port' => 8080,
            'external_networks' => [],
            'subdomain' => 'demo',
            'nginx_routes' => [['server_name' => 'demo@demo.example.test']],
            'owner_id' => 'u1',
            'members' => [],
            'template' => 'laravel',
            'auth_method' => 'deploy_key',
            'ssh_key' => '-----BEGIN OPENSSH PRIVATE KEY-----fixture-----END',
            'status' => 'running',
            'stage' => null,
            'message' => 'Running',
            'error' => null,
            'containers' => [['name' => 'demo-web-1']],
            'deploy_history' => [['sha' => 'deadbeef', 'status' => 'success']],
            'ssl_status' => 'issued',
        ], $overrides);
    }
}
