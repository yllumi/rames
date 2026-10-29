<?php
declare(strict_types=1);

namespace Tests;

use app\library\Errors\ErrorQuery;
use app\library\Errors\ErrorStore;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see ErrorQuery} — nama app/server harus dibaca dari **DB yang sama**
 * dengan `ErrorStore` (path store), bukan `config('deploy.sqlite_file')`.
 *
 * Store & seluruh data memakai berkas SQLite temp; tidak ada config global dan
 * `database/rames.sqlite` tidak pernah disentuh.
 */
class ErrorQueryTest extends TestCase
{
    private string $tmp;

    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/errquery_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        foreach (glob($this->tmp . '/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->tmp);
    }

    public function testStorePathPointsToInjectedDatabaseFile(): void
    {
        $store = new ErrorStore(new SqliteDatabase($this->file));

        self::assertSame($this->file, $store->path());
    }

    public function testIndexResolvesAppAndServerNamesFromSameDatabase(): void
    {
        (new AppStore($this->file))->create([
            'id' => 'app-1',
            'name' => 'Demo App',
            'owner_id' => 'u1',
        ]);
        (new ServerStore($this->file))->create([
            'id' => 'srv-1',
            'name' => 'VPS Satu',
            'kind' => 'agent',
        ]);

        $store = new ErrorStore(new SqliteDatabase($this->file));
        $group = $store->record([
            'kind' => 'client',
            'message' => 'Boom',
            'app_id' => 'app-1',
            'server_id' => 'srv-1',
            'occurred_at' => date('c'),
        ], hash('sha256', 'fp-query-names'));

        // Tanpa config global: bila ErrorQuery memakai DB default, AppStore akan
        // gagal dibuka dan nama menjadi kosong — inilah yang dicegah.
        $query = new ErrorQuery($store);

        $data = $query->index(['status' => 'all'], 1, 50);

        self::assertCount(1, $data['groups']);
        self::assertSame('Demo App', $data['groups'][0]['app_name']);
        self::assertSame('VPS Satu', $data['groups'][0]['server_name']);

        $detail = $query->detail((string) $group['id']);
        self::assertNotNull($detail);
        self::assertSame('Demo App', $detail['group']['app_name']);
        self::assertSame('VPS Satu', $detail['group']['server_name']);
    }

    public function testUnknownAppAndServerHaveEmptyNames(): void
    {
        $store = new ErrorStore(new SqliteDatabase($this->file));
        $store->record([
            'kind' => 'server',
            'message' => 'Tanpa referensi',
            'occurred_at' => date('c'),
        ], hash('sha256', 'fp-query-empty'));

        $query = new ErrorQuery($store);
        $groups = $query->index(['status' => 'all'], 1, 50)['groups'];

        self::assertSame('', $groups[0]['app_name']);
        self::assertSame('', $groups[0]['server_name']);
    }

    public function testInjectedStoresOverrideDerivedPath(): void
    {
        $other = $this->tmp . '/other.sqlite';
        (new AppStore($other))->create([
            'id' => 'app-1',
            'name' => 'Nama Override',
            'owner_id' => 'u1',
        ]);
        (new ServerStore($other))->create([
            'id' => 'srv-1',
            'name' => 'Server Override',
            'kind' => 'agent',
        ]);

        $store = new ErrorStore(new SqliteDatabase($this->file));
        $store->record([
            'kind' => 'client',
            'message' => 'Boom',
            'app_id' => 'app-1',
            'server_id' => 'srv-1',
            'occurred_at' => date('c'),
        ], hash('sha256', 'fp-query-override'));

        $query = new ErrorQuery($store, new AppStore($other), new ServerStore($other));
        $group = $query->index(['status' => 'all'], 1, 50)['groups'][0];

        self::assertSame('Nama Override', $group['app_name']);
        self::assertSame('Server Override', $group['server_name']);
    }
}
