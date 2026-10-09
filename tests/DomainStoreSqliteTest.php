<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Backup\BackupSelection;
use app\library\Billing\BillingStore;
use app\library\Storage\AppStore;
use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Bukti bahwa store domain produksi benar-benar berbasis SQLite (bukan lagi
 * `database/*.json`): konstruktor menerima **berkas .sqlite**, `path()`
 * mengembalikannya, mode jurnal WAL, dan dokumen mendarat di tabel store yang
 * benar dengan kolom turunan terisi.
 *
 * Semua I/O memakai berkas temp unik — data runtime tidak disentuh.
 */
class DomainStoreSqliteTest extends TestCase
{
    private string $dir;
    private string $db;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->dir = sys_get_temp_dir() . '/rames-domainstore-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
        $this->db = $this->dir . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->db);
        @rmdir($this->dir);
    }

    /**
     * @return array<int,array>
     */
    private function rows(string $store, string $table): array
    {
        $pdo = (new SqliteDatabase($this->db))->pdo();

        return $pdo->query('SELECT * FROM "' . SqliteStore::tableName($store, $table) . '" ORDER BY "ord" ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testJournalModeIsWal(): void
    {
        new AppStore($this->db);

        $pdo = (new SqliteDatabase($this->db))->pdo();
        $this->assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function testAppStorePersistsIntoAppsTable(): void
    {
        $store = new AppStore($this->db);
        $this->assertSame($this->db, $store->path(), 'path() = berkas basis data .sqlite');

        $app = $store->create([
            'name' => 'tonidata',
            'owner_id' => 'u1',
            'status' => 'running',
            'source' => 'git',
            'subdomain' => 'tonidata.example.com',
        ]);

        $rows = $this->rows('apps', 'apps');
        $this->assertCount(1, $rows);
        $this->assertSame($app['id'], $rows[0]['id']);
        $this->assertSame('tonidata', $rows[0]['name']);
        $this->assertSame('u1', $rows[0]['owner_id'], 'kolom turunan owner_id terisi untuk kueri');
        $this->assertSame('running', $rows[0]['status']);
        $this->assertSame('tonidata', $store->find($app['id'])['name'], 'baca ulang dari SQLite');
    }

    public function testUserStorePersistsIntoAuthTable(): void
    {
        $store = new UserStore($this->db);
        $this->assertSame($this->db, $store->path());

        $admin = $store->create('admin', 'rahasia123');

        $rows = $this->rows('auth', 'users');
        $this->assertCount(1, $rows);
        $this->assertSame($admin['id'], $rows[0]['id']);
        $this->assertSame('admin', $rows[0]['username']);
        $this->assertSame('admin', $rows[0]['role']);
        $this->assertSame(UserStore::ROLE_ADMIN, $store->findPublicById((string) $admin['id'])['role']);
    }

    public function testBillingStorePersistsCollectionsIntoTables(): void
    {
        $store = new BillingStore($this->db);
        $this->assertSame($this->db, $store->path());

        $store->update(function (array &$data): void {
            $data['users']['u2'] = ['balance' => 12.5, 'updated_at' => '', 'ledger' => []];
            $data['usage']['app1'] = ['period' => '2026-10', 'seconds_pending' => 60, 'credits_pending' => 1.5];
            $data['orders']['ord1'] = ['user_id' => 'u2', 'status' => 'pending'];
        });

        $users = $this->rows('billing', 'users');
        $this->assertCount(1, $users);
        $this->assertSame('u2', $users[0]['id']);

        $usage = $this->rows('billing', 'usage');
        $this->assertSame('app1', $usage[0]['id']);
        $this->assertSame('2026-10', $usage[0]['period'], 'kolom turunan period terisi');

        $orders = $this->rows('billing', 'orders');
        $this->assertSame('pending', $orders[0]['status'], 'kolom turunan status terisi');

        // Bentuk baca tetap {version, users, usage, orders}.
        $read = $store->read();
        $this->assertSame(1, $read['version']);
        $this->assertSame(12.5, $read['users']['u2']['balance']);
    }

    public function testBackupSelectionPersistsIntoBackupVolumesTable(): void
    {
        $selection = new BackupSelection($this->db);
        $this->assertSame($this->db, $selection->path());

        $selection->setScheduled('tonidata_data', true, 'u1');

        $rows = $this->rows('backup', 'volumes');
        $this->assertCount(1, $rows);
        $this->assertSame('tonidata_data', $rows[0]['id']);
        $this->assertSame(1, (int) $rows[0]['scheduled'], 'kolom turunan scheduled terisi (bool → 0/1)');
        $this->assertSame('u1', json_decode((string) $rows[0]['data'], true)['updated_by']);
        $this->assertTrue((new BackupSelection($this->db))->isScheduled('tonidata_data'));
    }

    public function testAllStoresShareOneFileWithoutClobberingEachOther(): void
    {
        $apps = new AppStore($this->db);
        $users = new UserStore($this->db);
        $billing = new BillingStore($this->db);
        $selection = new BackupSelection($this->db);

        $app = $apps->create(['name' => 'tonidata', 'owner_id' => 'u1']);
        $admin = $users->create('admin', 'rahasia123');
        $billing->update(function (array &$data) use ($admin): void {
            $data['users'][(string) $admin['id']] = ['balance' => 5.0, 'updated_at' => '', 'ledger' => []];
        });
        $selection->setScheduled('tonidata_data', true);

        // Setiap store membaca koleksinya sendiri — tidak ada yang saling menimpa.
        $this->assertSame($app['id'], $apps->find($app['id'])['id']);
        $this->assertSame('admin', $users->findById((string) $admin['id'])['username']);
        $this->assertSame(5.0, $billing->users()[(string) $admin['id']]['balance']);
        $this->assertTrue($selection->isScheduled('tonidata_data'));
    }
}
