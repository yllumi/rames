<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\AppStore;
use app\library\Storage\SchemaMigrations;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Migrasi skema v2 (fondasi multi-server F0): kolom turunan `server_id` pada
 * `apps_apps` + backfill `local`, store `servers` (tabel fisik `servers_servers`
 * mengikuti invarian `{store}_{table}`), dan seed baris server lokal.
 *
 * Fixture skema v1 dibuat **manual via PDO** di path temp — `database/rames.sqlite`
 * nyata tidak pernah disentuh.
 */
class SchemaMigrationsV2Test extends TestCase
{
    private string $file;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-schema-v2-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    /**
     * Skema v1 tiruan: tabel meta `schema_migrations` (versi 1 tercatat) + `kv`
     * + `apps_apps` versi LAMA (tanpa `server_id`) berisi satu baris app.
     */
    private function createV1Fixture(?string $docData = null): void
    {
        $this->pdo->exec(
            'CREATE TABLE "schema_migrations" ("version" INTEGER PRIMARY KEY, "applied_at" TEXT NOT NULL)'
        );
        $stmt = $this->pdo->prepare('INSERT INTO "schema_migrations" ("version", "applied_at") VALUES (1, ?)');
        $stmt->execute([date('c')]);

        $this->pdo->exec('CREATE TABLE "kv" ("key" TEXT PRIMARY KEY, "value" TEXT NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE "apps_apps" ('
            . '"id" TEXT PRIMARY KEY, "ord" INTEGER, "data" TEXT NOT NULL, "updated_at" TEXT NOT NULL, '
            . '"name" TEXT, "owner_id" TEXT, "status" TEXT, "source" TEXT, "subdomain" TEXT)'
        );
        $this->pdo->exec('CREATE INDEX "idx_apps_apps_name" ON "apps_apps" ("name")');

        $doc = $docData ?? '{"id":"app1","name":"app1","owner_id":"u1","status":"running","source":"git"}';
        $stmt = $this->pdo->prepare(
            'INSERT INTO "apps_apps" ("id", "ord", "data", "updated_at", "name", "owner_id", "status") '
            . 'VALUES (?, 0, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['app1', $doc, date('c'), 'app1', 'u1', 'running']);
    }

    /**
     * @return array<int,string>
     */
    private function columns(string $table): array
    {
        $rows = $this->pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * @return array<int,string>
     */
    private function indexes(string $table): array
    {
        $rows = $this->pdo->query('PRAGMA index_list("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * @return array<int,string>
     */
    private function tables(): array
    {
        $rows = $this->pdo->query(
            'SELECT "name" FROM "sqlite_master" WHERE "type" = \'table\' ORDER BY "name"'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function testMigrateFromV1AddsServerIdBackfillsAndSeedsLocalServer(): void
    {
        $this->createV1Fixture();

        $this->assertSame(
            SchemaMigrations::LATEST - 1,
            SchemaMigrations::migrate($this->pdo),
            'semua migrasi setelah v1 (v2..LATEST) tertunda'
        );
        $this->assertSame(SchemaMigrations::LATEST, (int) $this->pdo->query('PRAGMA user_version')->fetchColumn());

        // (a) kolom turunan `server_id` ditambahkan ke tabel apps yang sudah ada.
        $this->assertContains('server_id', $this->columns('apps_apps'));

        // (b) backfill kolom turunan ke server lokal.
        $this->assertSame(
            'local',
            $this->pdo->query('SELECT "server_id" FROM "apps_apps" WHERE "id" = \'app1\'')->fetchColumn()
        );

        // (c) backfill juga masuk ke dokumen JSON (JSON = sumber kebenaran).
        $raw = (string) $this->pdo->query('SELECT "data" FROM "apps_apps" WHERE "id" = \'app1\'')->fetchColumn();
        $doc = json_decode($raw, true);
        $this->assertSame('local', $doc['server_id']);
        $this->assertSame('app1', $doc['name']);
        $this->assertSame('running', $doc['status']);
        $this->assertSame('git', $doc['source']);

        // (d) indeks untuk kolom baru terbentuk.
        $this->assertContains('idx_apps_apps_server_id', $this->indexes('apps_apps'));

        // (e) store `servers` + seed baris lokal (data JSON DAN kolom turunan).
        $serverTable = SchemaMigrations::tableName('servers', 'servers');
        $this->assertSame('servers_servers', $serverTable, 'nama tabel fisik mengikuti {store}_{table}');
        $this->assertContains($serverTable, $this->tables());
        $local = $this->pdo->query('SELECT * FROM "' . $serverTable . '" WHERE "id" = \'local\'')->fetch();
        $this->assertIsArray($local);
        $this->assertSame(0, (int) $local['ord']);
        $this->assertSame('local', $local['name']);
        $this->assertSame('local', $local['kind']);
        $this->assertSame(1, (int) $local['enabled']);
        $this->assertNotEmpty($local['updated_at']);
        $this->assertSame(
            ['id' => 'local', 'name' => 'local', 'kind' => 'local', 'enabled' => true, 'domain' => ''],
            json_decode((string) $local['data'], true)
        );

        // (f) versi skema tercatat lengkap.
        $versions = array_map(
            'intval',
            $this->pdo->query('SELECT "version" FROM "schema_migrations" ORDER BY "version"')->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame(range(1, SchemaMigrations::LATEST), $versions);
    }

    public function testMigrateIsIdempotentOnV1Database(): void
    {
        $this->createV1Fixture();

        $this->assertSame(SchemaMigrations::LATEST - 1, SchemaMigrations::migrate($this->pdo));
        $this->assertSame(0, SchemaMigrations::migrate($this->pdo), 'panggilan kedua tidak menerapkan apa pun');

        $serverTable = SchemaMigrations::tableName('servers', 'servers');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM "' . $serverTable . '"')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM "apps_apps"')->fetchColumn());
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM "apps_apps" WHERE "server_id" IS NULL OR "server_id" = \'\''
            )->fetchColumn()
        );
    }

    public function testExistingServerIdInJsonIsPreservedAndSyncedToColumn(): void
    {
        $this->createV1Fixture('{"id":"app1","name":"app1","server_id":"agent-7"}');

        $this->assertSame(SchemaMigrations::LATEST - 1, SchemaMigrations::migrate($this->pdo));

        $row = $this->pdo->query('SELECT "server_id", "data" FROM "apps_apps" WHERE "id" = \'app1\'')->fetch();
        $this->assertSame('agent-7', $row['server_id'], 'kolom disinkronkan dari JSON');
        $this->assertSame('agent-7', json_decode((string) $row['data'], true)['server_id']);
    }

    public function testNonObjectDocumentIsNotDamaged(): void
    {
        $this->createV1Fixture('"bukan-objek"');

        $this->assertSame(SchemaMigrations::LATEST - 1, SchemaMigrations::migrate($this->pdo));

        $row = $this->pdo->query('SELECT "server_id", "data" FROM "apps_apps" WHERE "id" = \'app1\'')->fetch();
        $this->assertNull($row['server_id'], 'dokumen non-objek dilewati');
        $this->assertSame('"bukan-objek"', $row['data'], 'dokumen asli tidak diubah');
    }

    public function testFreshDatabaseEndsWithSameSchemaAsUpgradedV1(): void
    {
        $this->createV1Fixture();
        $this->assertSame(SchemaMigrations::LATEST - 1, SchemaMigrations::migrate($this->pdo));
        $upgradedAppsColumns = $this->columns('apps_apps');
        $upgradedServersColumns = $this->columns(SchemaMigrations::tableName('servers', 'servers'));

        // DB baru dari nol (migrasi 1 + 2 dalam satu jalan).
        $freshFile = sys_get_temp_dir() . '/rames-schema-v2-fresh-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $fresh = new PDO('sqlite:' . $freshFile);
            $fresh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $fresh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->assertSame(
                count(SchemaMigrations::migrations()),
                SchemaMigrations::migrate($fresh),
                'DB baru menerapkan semua migrasi'
            );

            $versions = array_map(
                'intval',
                $fresh->query('SELECT "version" FROM "schema_migrations" ORDER BY "version"')->fetchAll(PDO::FETCH_COLUMN)
            );
            $this->assertSame(range(1, SchemaMigrations::LATEST), $versions);
            $this->assertSame(
                SchemaMigrations::LATEST,
                (int) $fresh->query('PRAGMA user_version')->fetchColumn()
            );

            $freshAppsColumns = array_map(
                static fn (array $row): string => (string) $row['name'],
                $fresh->query('PRAGMA table_info("apps_apps")')->fetchAll(PDO::FETCH_ASSOC)
            );
            $freshServersColumns = array_map(
                static fn (array $row): string => (string) $row['name'],
                $fresh->query('PRAGMA table_info("' . SchemaMigrations::tableName('servers', 'servers') . '")')->fetchAll(PDO::FETCH_ASSOC)
            );
            $this->assertSame($upgradedAppsColumns, $freshAppsColumns);
            $this->assertSame($upgradedServersColumns, $freshServersColumns);

            // Seed server lokal ada di DB baru (dari migrasi 2, idempoten).
            $local = $fresh->query(
                'SELECT "data" FROM "' . SchemaMigrations::tableName('servers', 'servers') . '" WHERE "id" = \'local\''
            )->fetchColumn();
            $this->assertIsString($local);
            $this->assertSame('local', json_decode($local, true)['id']);
        } finally {
            StoreDbFiles::remove($freshFile);
        }
    }

    public function testProductionPathUpgradesV1Database(): void
    {
        $this->createV1Fixture();

        // Jalur produksi: `SqliteDatabase::pdo()` (WAL + PRAGMA) →
        // `SchemaMigrations::migrate()`, lalu store dibuka seperti biasa.
        SqliteDatabase::reset();
        $servers = new ServerStore($this->file);
        $local = $servers->local();
        $this->assertSame(ServerStore::LOCAL_ID, $local['id']);
        $this->assertSame('local', $local['kind']);

        $apps = new AppStore($this->file);
        $app = $apps->find('app1');
        $this->assertIsArray($app);
        $this->assertSame('local', $app['server_id']);
        $this->assertSame('app1', $app['name']);

        $check = new PDO('sqlite:' . $this->file);
        $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertSame(
            range(1, SchemaMigrations::LATEST),
            array_map(
                'intval',
                $check->query('SELECT "version" FROM "schema_migrations" ORDER BY "version"')->fetchAll(PDO::FETCH_COLUMN)
            )
        );
    }

    public function testStoreDefinitionsExposeServerDimension(): void    {
        $definitions = SchemaMigrations::storeDefinitions();

        $this->assertArrayHasKey('servers', $definitions);
        $this->assertSame('servers', $definitions['servers'][0]['table']);
        $this->assertSame('id', $definitions['servers'][0]['idField']);
        $this->assertSame(['name', 'kind', 'enabled'], $definitions['servers'][0]['columns']);

        $this->assertContains('server_id', $definitions['apps'][0]['columns']);
    }
}
