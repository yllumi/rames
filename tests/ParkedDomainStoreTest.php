<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\ParkedDomainStore;
use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Test store domain parking (`parked` → tabel fisik `parked_domains`):
 * create/find/byDomain/update/delete, keunikan domain case-insensitive,
 * persistensi antar-instance, dan ketersediaan tabel hasil migrasi.
 *
 * Semua store memakai berkas .sqlite temp — `database/` nyata tidak disentuh.
 */
class ParkedDomainStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-parked-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    public function testCreateFindByDomainAndDomains(): void
    {
        $store = new ParkedDomainStore($this->file);

        $zulu = $store->create(['domain' => 'zulu.test', 'mode' => 'html', 'target' => '']);
        $alpha = $store->create(['domain' => 'Alpha.Test', 'mode' => 'redirect', 'server_id' => '']);

        // Default: id `pd_` + 12 hex, server lokal, timestamp ISO.
        $this->assertMatchesRegularExpression('/^pd_[0-9a-f]{12}$/', $zulu['id']);
        $this->assertSame('local', $zulu['server_id'], 'server_id kosong ⇒ server lokal');
        $this->assertSame('local', $alpha['server_id']);
        $this->assertNotEmpty($zulu['created_at']);
        $this->assertNotEmpty($zulu['updated_at']);
        $this->assertSame('html', $zulu['mode']);

        $this->assertNull($store->find('pd_tidakada'));
        $this->assertSame($zulu['id'], $store->find($zulu['id'])['id']);

        // byDomain case-insensitive.
        $this->assertSame($alpha['id'], $store->byDomain('alpha.TEST')['id']);
        $this->assertSame($zulu['id'], $store->byDomain('  ZULU.test  ')['id']);
        $this->assertNull($store->byDomain(''));

        // all() urut domain menaik; domains() = daftar string.
        $this->assertSame(['Alpha.Test', 'zulu.test'], array_map(
            static fn (array $row): string => (string) $row['domain'],
            $store->all()
        ));
        $this->assertSame(['alpha.test', 'zulu.test'], $store->domains());
    }

    public function testDuplicateDomainRejectedCaseInsensitiveOnCreate(): void
    {
        $store = new ParkedDomainStore($this->file);
        $store->create(['domain' => 'dupe.test', 'mode' => 'html']);

        try {
            $store->create(['domain' => 'DUPE.Test', 'mode' => 'redirect']);
            $this->fail('domain duplikat (beda kapital) harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dipakai', $e->getMessage());
            $this->assertStringContainsString('DUPE.Test', $e->getMessage());
        }

        $this->assertCount(1, $store->all(), 'tidak ada baris parsial yang tertulis');
    }

    public function testDuplicateDomainRejectedOnUpdateAndRolledBack(): void
    {
        $store = new ParkedDomainStore($this->file);
        $store->create(['domain' => 'one.test', 'mode' => 'html']);
        $second = $store->create(['domain' => 'two.test', 'mode' => 'html']);

        try {
            $store->update($second['id'], static function (array &$entry): void {
                $entry['domain'] = 'ONE.test';
                $entry['mode'] = 'redirect';
            });
            $this->fail('update ke domain duplikat harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dipakai', $e->getMessage());
        }

        // Transaksi rollback penuh: domain & mode lama dipertahankan.
        $fresh = $store->find($second['id']);
        $this->assertSame('two.test', $fresh['domain']);
        $this->assertSame('html', $fresh['mode']);

        // Mengubah domain ke nilai unik tetap boleh.
        $updated = $store->update($second['id'], static function (array &$entry): void {
            $entry['domain'] = 'three.test';
        });
        $this->assertSame('three.test', $updated['domain']);
    }

    public function testUpdateRefreshesTimestampKeepsIdAndPersists(): void
    {
        $store = new ParkedDomainStore($this->file);
        $entry = $store->create(['domain' => 'time.test', 'mode' => 'html']);

        $updated = $store->update($entry['id'], static function (array &$row): void {
            $row['id'] = 'pd_dipalsukan';                // identitas tidak boleh berubah
            $row['updated_at'] = '2000-01-01T00:00:00+00:00'; // akan dinormalisasi
            $row['mode'] = 'redirect';
            $row['target'] = 'https://example.com/x';
        });

        $this->assertSame($entry['id'], $updated['id']);
        $this->assertNotSame('2000-01-01T00:00:00+00:00', $updated['updated_at']);
        $this->assertSame('redirect', $updated['mode']);

        // Persist antar-instance (instance baru membaca dari berkas yang sama).
        $fresh = new ParkedDomainStore($this->file);
        $reloaded = $fresh->find($entry['id']);
        $this->assertSame('redirect', $reloaded['mode']);
        $this->assertSame('https://example.com/x', $reloaded['target']);
        $this->assertSame($updated['updated_at'], $reloaded['updated_at']);
    }

    public function testUpdateMissingIdThrowsAndDeleteIsNoop(): void
    {
        $store = new ParkedDomainStore($this->file);

        try {
            $store->update('pd_hantu', static function (array &$row): void {
                $row['mode'] = 'html';
            });
            $this->fail('update id tak dikenal harus melempar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pd_hantu', $e->getMessage());
        }

        $store->delete('pd_hantu'); // no-op, tidak melempar
        $entry = $store->create(['domain' => 'gone.test', 'mode' => 'html']);
        $store->delete($entry['id']);
        $this->assertNull($store->find($entry['id']));
        $this->assertNull($store->byDomain('gone.test'));
    }

    public function testMigratedDatabaseHasParkedDomainsTable(): void
    {
        $db = new SqliteDatabase($this->file);
        $pdo = $db->pdo();

        $table = SchemaMigrations::tableName('parked', 'domains');
        $this->assertSame('parked_domains', $table);

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'' . $table . '\''
        )->fetchColumn();
        $this->assertSame(1, $exists, 'migrasi harus membuat tabel parked_domains');

        $columns = array_map(
            static fn (array $row): string => (string) $row['name'],
            $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(\PDO::FETCH_ASSOC)
        );
        foreach (['id', 'ord', 'data', 'updated_at', 'domain', 'server_id', 'mode'] as $column) {
            $this->assertContains($column, $columns);
        }

        $versions = array_map(
            'intval',
            $pdo->query('SELECT "version" FROM "schema_migrations" ORDER BY "version"')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $this->assertContains(3, $versions);
        $this->assertSame(SchemaMigrations::LATEST, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testMigrationThreeRecreatesParkedStoreForOldDatabase(): void
    {
        // Tiru DB lama yang baru sampai v2: tabel parkir belum ada & versi 3
        // belum tercatat. `migrate()` harus menerapkan migrasi 3 saja.
        $pdo = (new SqliteDatabase($this->file))->pdo();
        $pdo->exec('DROP TABLE "' . SchemaMigrations::tableName('parked', 'domains') . '"');
        $pdo->exec('DELETE FROM "schema_migrations" WHERE "version" = 3');

        $this->assertSame(1, SchemaMigrations::migrate($pdo), 'hanya migrasi 3 yang tertunda');

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM "sqlite_master" WHERE "type" = \'table\' '
            . 'AND "name" = \'' . SchemaMigrations::tableName('parked', 'domains') . '\''
        )->fetchColumn();
        $this->assertSame(1, $exists, 'migrasi 3 idempoten membuat ulang tabel parkir');

        // Store tetap bisa dipakai setelah migrasi ulang (tanpa data hilang di store lain).
        $store = new ParkedDomainStore($this->file);
        $entry = $store->create(['domain' => 'after-migrate.test', 'mode' => 'html']);
        $this->assertSame('after-migrate.test', $store->find($entry['id'])['domain']);
    }
}
