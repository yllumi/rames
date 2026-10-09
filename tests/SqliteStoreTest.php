<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test {@see SqliteStore} — kompatibel JsonStore (path/read/write/update),
 * urutan list, kunci map, round-trip tipe JSON, dan atomicity update().
 */
class SqliteStoreTest extends TestCase
{
    private string $tmp;
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/sqlitestore_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        self::removeTree($this->tmp);
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

    private function store(string $name): SqliteStore
    {
        return new SqliteStore($name, SchemaMigrations::storeDefinitions()[$name], $this->file);
    }

    public function testPathIsSqliteFile(): void
    {
        $store = $this->store('apps');
        $this->assertSame($this->file, $store->path());
        $this->assertFileExists($this->file);
    }

    public function testListStorePreservesOrder(): void
    {
        $store = $this->store('apps');
        $store->write([
            ['id' => 'a', 'name' => 'alfa', 'owner_id' => 'u1'],
            ['id' => 'b', 'name' => 'beta', 'owner_id' => 'u1'],
            ['id' => 'c', 'name' => 'gama', 'owner_id' => 'u2'],
        ]);

        $this->assertSame(['a', 'b', 'c'], array_column($store->read(), 'id'), 'urutan dipertahankan');

        // tulis ulang dengan urutan terbalik
        $store->write([
            ['id' => 'c', 'name' => 'gama', 'owner_id' => 'u2'],
            ['id' => 'b', 'name' => 'beta', 'owner_id' => 'u1'],
            ['id' => 'a', 'name' => 'alfa', 'owner_id' => 'u1'],
        ]);
        $this->assertSame(['c', 'b', 'a'], array_column($store->read(), 'id'));
    }

    public function testMapStorePreservesKeysAndOrder(): void
    {
        $store = $this->store('billing');
        $store->write([
            'users' => ['u2' => ['balance' => 10], 'u1' => ['balance' => 5]],
            'usage' => ['app1' => ['period' => '2026-10', 'seconds_pending' => 60]],
            'orders' => [],
        ]);

        $data = $store->read();
        $this->assertSame(['u2', 'u1'], array_keys($data['users']), 'urutan kunci map dipertahankan');
        $this->assertSame(10, $data['users']['u2']['balance']);
        $this->assertSame('2026-10', $data['usage']['app1']['period']);
        $this->assertSame([], $data['orders']);
    }

    public function testJsonTypeRoundTrip(): void
    {
        $doc = [
            'id' => 'x1',
            'int' => 105000,
            'float' => 100000.5,
            'whole_float' => 1.0,
            'str' => 'halo "dunia"',
            'bool_true' => true,
            'bool_false' => false,
            'null' => null,
            'nested' => ['a' => [1, 2, ['b' => 3]], 'c' => ['d' => null]],
            'list' => [1, 'dua', false],
        ];
        $store = $this->store('apps');
        $store->write([$doc]);

        $read = $store->read()[0];
        $this->assertSame($doc, $read);
        $this->assertIsInt($read['int']);
        $this->assertIsFloat($read['float']);
        $this->assertIsFloat($read['whole_float']);
        $this->assertTrue($read['bool_true']);
        $this->assertFalse($read['bool_false']);
        $this->assertNull($read['null']);
    }

    public function testUpdateIsAtomicOnException(): void
    {
        $store = $this->store('apps');
        $store->write([['id' => 'a', 'name' => 'alfa']]);

        try {
            $store->update(function (array &$data): void {
                $data[] = ['id' => 'b', 'name' => 'beta'];
                $data[0]['name'] = 'berubah';
                throw new RuntimeException('batal');
            });
            $this->fail('exception harus diteruskan');
        } catch (RuntimeException) {
            // diharapkan
        }

        $this->assertSame([['id' => 'a', 'name' => 'alfa']], $store->read(), 'tidak ada perubahan setelah rollback');
    }

    public function testUpdatePersistsChanges(): void
    {
        $store = $this->store('apps');
        $store->write([['id' => 'a', 'name' => 'alfa']]);

        $store->update(function (array &$data): void {
            $data[0]['name'] = 'alfa-baru';
            $data[] = ['id' => 'b', 'name' => 'beta'];
        });

        $read = $store->read();
        $this->assertSame('alfa-baru', $read[0]['name']);
        $this->assertSame(['a', 'b'], array_column($read, 'id'));
    }

    public function testDerivedColumnsArePopulated(): void
    {
        $store = $this->store('apps');
        $store->write([[
            'id' => 'app1',
            'name' => 'cpjunior',
            'owner_id' => 'u1',
            'status' => 'running',
            'source' => 'git',
            'subdomain' => 'cpjunior.example.com',
            'title' => 'bukan-kolom',
        ]]);

        $pdo = (new SqliteDatabase($this->file))->pdo();
        $table = SqliteStore::tableName('apps', 'apps');
        $row = $pdo->query('SELECT "owner_id", "name", "status", "source", "subdomain" FROM "' . $table . '"')->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('u1', $row['owner_id']);
        $this->assertSame('cpjunior', $row['name']);
        $this->assertSame('running', $row['status']);
        $this->assertSame('git', $row['source']);
        $this->assertSame('cpjunior.example.com', $row['subdomain']);
    }

    public function testWriteRemovesMissingRows(): void
    {
        $store = $this->store('billing');
        $store->write(['users' => ['u1' => ['balance' => 1], 'u2' => ['balance' => 2]]]);
        $store->write(['users' => ['u1' => ['balance' => 9]]]);

        $data = $store->read();
        $this->assertSame(['u1'], array_keys($data['users']));
        $this->assertSame(9, $data['users']['u1']['balance']);
    }

    public function testTwoHandlesSequentialWrites(): void
    {
        $this->store('apps')->write([['id' => 'a', 'name' => 'alfa']]);

        SqliteDatabase::reset();
        $second = new SqliteStore('apps', SchemaMigrations::storeDefinitions()['apps'], $this->file);
        $second->update(function (array &$data): void {
            $data[] = ['id' => 'b', 'name' => 'beta'];
        });

        $this->assertSame(['a', 'b'], array_column($second->read(), 'id'));
    }

    public function testListDocWithoutIdUsesStableSyntheticKey(): void
    {
        $store = $this->store('auth');
        $store->write([
            ['username' => 'tanpa-id', 'role' => 'member'],
            ['id' => 'z1', 'username' => 'punya-id', 'role' => 'admin'],
        ]);

        $read = $store->read();
        $this->assertCount(2, $read);
        $this->assertArrayNotHasKey('id', $read[0]);
        $this->assertSame('tanpa-id', $read[0]['username']);
        $this->assertSame('z1', $read[1]['id']);
    }

    public function testSchemaMemoSetAfterFirstConstructionAndClearedByReset(): void
    {
        $this->assertFalse(SqliteStore::isSchemaReady($this->file, 'apps'), 'belum dibangun');

        $this->store('apps');
        $this->assertTrue(SqliteStore::isSchemaReady($this->file, 'apps'), 'store apps dipastikan');
        $this->assertFalse(SqliteStore::isSchemaReady($this->file, 'auth'), 'store lain tetap belum');

        SqliteDatabase::reset();
        $this->assertFalse(SqliteStore::isSchemaReady($this->file, 'apps'), 'reset() mengosongkan memo');

        // Setelah reset, konstruksi ulang tetap bekerja (memastikan skema lagi).
        $this->store('apps')->write([['id' => 'a', 'name' => 'alfa']]);
        $this->assertTrue(SqliteStore::isSchemaReady($this->file, 'apps'));
        $this->assertSame(1, count($this->store('apps')->read()));
    }

    public function testSchemaMemoIsIndependentPerFile(): void
    {
        $other = $this->tmp . '/other.sqlite';

        $this->store('apps');
        $this->assertTrue(SqliteStore::isSchemaReady($this->file, 'apps'));
        $this->assertFalse(SqliteStore::isSchemaReady($other, 'apps'), 'berkas lain belum dipastikan');
    }

    public function testSchemaMemoInvalidatedWhenFileIsReplaced(): void
    {
        $this->store('apps')->write([['id' => 'a', 'name' => 'alfa']]);
        $this->assertTrue(SqliteStore::isSchemaReady($this->file, 'apps'));

        // Ganti berkas dengan inode baru (restore/rename atomik).
        @unlink($this->file);
        @unlink($this->file . '-wal');
        @unlink($this->file . '-shm');
        touch($this->file);

        $this->assertFalse(
            SqliteStore::isSchemaReady($this->file, 'apps'),
            'inode berubah ⇒ memo lama tidak dipakai'
        );

        // Skema dibangun ulang untuk berkas baru; store tetap berfungsi.
        $store = $this->store('apps');
        $store->write([['id' => 'b', 'name' => 'beta']]);
        $this->assertSame(['b'], array_column($store->read(), 'id'));
        $this->assertTrue(SqliteStore::isSchemaReady($this->file, 'apps'));
    }
}
