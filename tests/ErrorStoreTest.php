<?php
declare(strict_types=1);

namespace Tests;

use app\library\Errors\ErrorStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see ErrorStore} — grup per fingerprint, sampel append-only, cap, prune.
 *
 * Seluruh uji memakai berkas SQLite di direktori temp; `database/rames.sqlite`
 * tidak pernah disentuh. Waktu kejadian dikendalikan lewat `occurred_at`
 * (tidak ada `sleep()`).
 */
class ErrorStoreTest extends TestCase
{
    private string $tmp;

    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/errstore_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        foreach (glob($this->tmp . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // record: buat & upsert
    // ------------------------------------------------------------------

    public function testRecordCreatesGroupAndStoresEventAndContext(): void
    {
        $store = $this->store();

        $group = $store->record([
            'kind' => 'client',
            'message' => 'Boom 42',
            'stack' => "at run (/js/app.js:12:3)",
            'location' => '/js/app.js:12:3',
            'route' => '/apps/demo',
            'app_id' => 'app-1',
            'server_id' => 'local',
            'user_id' => 'u1',
            'username' => 'budi',
            'occurred_at' => '2026-10-29T05:00:00Z',
            'context' => ['fitur' => 'monitor', 'n' => 7],
            'user_agent' => 'UA',
        ], hash('sha256', 'fp-1'));

        self::assertMatchesRegularExpression(ErrorStore::ID_PATTERN, (string) $group['id']);
        self::assertSame('open', $group['status']);
        self::assertSame('client', $group['kind']);
        self::assertSame(1, (int) $group['count']);
        self::assertSame(1, (int) $group['samples']);
        self::assertSame('Boom 42', $group['message']);
        self::assertSame('2026-10-29T05:00:00Z', $group['first_seen']);
        self::assertSame('2026-10-29T05:00:00Z', $group['last_seen']);

        $events = $store->eventsForGroup((string) $group['id']);
        self::assertCount(1, $events);
        self::assertSame(['fitur' => 'monitor', 'n' => 7], $events[0]['context']);
        self::assertSame('/js/app.js:12:3', $group['location']);
    }

    public function testSameFingerprintUpsertsCountAndLatestFields(): void
    {
        $store = $this->store();
        $fingerprint = hash('sha256', 'fp-same');

        $first = $store->record([
            'kind' => 'client',
            'message' => 'Gagal A',
            'route' => '/apps/demo',
            'occurred_at' => '2026-10-29T05:00:00Z',
        ], $fingerprint);

        $second = $store->record([
            'kind' => 'client',
            'message' => 'Gagal B',
            'route' => '/apps/demo',
            'location' => 'app.js:9',
            'occurred_at' => '2026-10-29T05:10:00Z',
        ], $fingerprint);

        self::assertSame((string) $first['id'], (string) $second['id'], 'fingerprint sama = grup sama');
        self::assertSame(2, (int) $second['count']);
        self::assertSame(2, (int) $second['samples']);
        self::assertSame('Gagal B', $second['message'], 'pesan mengikuti event terakhir');
        self::assertSame('app.js:9', $second['location']);
        self::assertSame('2026-10-29T05:00:00Z', $second['first_seen']);
        self::assertSame('2026-10-29T05:10:00Z', $second['last_seen']);
    }

    public function testResolvedReopensOnNewEventButIgnoredStaysIgnored(): void
    {
        $store = $this->store();
        $fingerprint = hash('sha256', 'fp-status');
        $group = $store->record([
            'kind' => 'server',
            'message' => 'TypeError',
            'occurred_at' => '2026-10-29T05:00:00Z',
        ], $fingerprint);
        $id = (string) $group['id'];

        self::assertTrue($store->setStatus($id, 'resolved', 'admin'));
        $resolved = $store->findGroup($id);
        self::assertSame('resolved', $resolved['status']);
        self::assertNotNull($resolved['resolved_at']);
        self::assertSame('admin', $resolved['resolved_by']);

        $reopened = $store->record([
            'kind' => 'server',
            'message' => 'TypeError',
            'occurred_at' => '2026-10-29T05:05:00Z',
        ], $fingerprint);
        self::assertSame('open', $reopened['status'], 'error muncul lagi ⇒ buka kembali');
        self::assertNull($reopened['resolved_at']);
        self::assertNull($reopened['resolved_by']);

        self::assertTrue($store->setStatus($id, 'ignored', 'admin'));
        $ignored = $store->record([
            'kind' => 'server',
            'message' => 'TypeError',
            'occurred_at' => '2026-10-29T05:06:00Z',
        ], $fingerprint);
        self::assertSame('ignored', $ignored['status'], 'status ignored dipertahankan');
        self::assertNull($ignored['resolved_at']);
        self::assertSame('admin', $ignored['resolved_by']);
    }

    // ------------------------------------------------------------------
    // batas sampel & total event
    // ------------------------------------------------------------------

    public function testSampleCapKeepsNewestEventsPerGroup(): void
    {
        $store = $this->store();
        $fingerprint = hash('sha256', 'fp-samples');

        for ($i = 1; $i <= 5; $i++) {
            $group = $store->record([
                'kind' => 'client',
                'message' => 'Error ' . $i,
                'occurred_at' => '2026-10-29T05:0' . $i . ':00Z',
            ], $fingerprint, 2);

            self::assertSame($i, (int) $group['count'], 'count menghitung semua kejadian');
            self::assertSame(min($i, 2), (int) $group['samples'], 'sampel dibatasi 2');
        }

        $events = $store->eventsForGroup((string) $group['id']);
        self::assertCount(2, $events);
        self::assertSame('Error 5', $events[0]['message']);
        self::assertSame('Error 4', $events[1]['message']);
    }

    public function testTotalEventCapDropsOldestGlobally(): void
    {
        $store = $this->store();

        $store->record([
            'kind' => 'client',
            'message' => 'A1',
            'occurred_at' => '2026-10-29T05:00:00Z',
        ], hash('sha256', 'fp-a'), 20, 3650, 3);

        $store->record([
            'kind' => 'client',
            'message' => 'A2',
            'occurred_at' => '2026-10-29T05:01:00Z',
        ], hash('sha256', 'fp-a'), 20, 3650, 3);

        $store->record([
            'kind' => 'client',
            'message' => 'B1',
            'occurred_at' => '2026-10-29T05:02:00Z',
        ], hash('sha256', 'fp-b'), 20, 3650, 3);

        $store->record([
            'kind' => 'client',
            'message' => 'B2',
            'occurred_at' => '2026-10-29T05:03:00Z',
        ], hash('sha256', 'fp-b'), 20, 3650, 3);

        $total = (int) $this->pdo()->query('SELECT COUNT(*) FROM "error_events"')->fetchColumn();
        self::assertSame(3, $total, 'event tertua global dibuang');

        $oldest = $this->pdo()
            ->query('SELECT "message" FROM "error_events" ORDER BY "occurred_at" ASC, "id" ASC LIMIT 1')
            ->fetchColumn();
        self::assertSame('A2', $oldest, 'A1 (tertua) yang dibuang');

        // `samples` grup ikut menyesuaikan isi nyata.
        $groups = $store->listGroups(['status' => 'all'], 1, 50)['groups'];
        $byMessage = [];
        foreach ($groups as $group) {
            $byMessage[$group['message']] = (int) $group['samples'];
        }
        self::assertSame(1, $byMessage['A2']);
        self::assertSame(2, $byMessage['B2']);
    }

    // ------------------------------------------------------------------
    // prune
    // ------------------------------------------------------------------

    public function testPruneZeroRemovesEverything(): void
    {
        $store = $this->store();
        $store->record([
            'kind' => 'client',
            'message' => 'Akan dihapus',
            'occurred_at' => date('c'),
        ], hash('sha256', 'fp-prune'), 20, 3650);

        $store->prune(0, 5000);

        self::assertSame(0, $store->openCount());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM "error_events"')->fetchColumn());
    }

    public function testPruneRemovesEventsOlderThanRetentionButKeepsRecentGroup(): void
    {
        $store = $this->store();
        $fingerprint = hash('sha256', 'fp-old');

        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400);
        $store->record([
            'kind' => 'client',
            'message' => 'lama',
            'occurred_at' => $old,
        ], hash('sha256', 'fp-only-old'), 20, 3650);
        $group = $store->record([
            'kind' => 'client',
            'message' => 'baru',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $fingerprint, 20, 3650);

        // Event lama ditambahkan manual ke grup yang masih aktif.
        $this->pdo()->prepare(
            'INSERT INTO "error_events" ("group_id","occurred_at","kind","message")'
            . ' VALUES (:gid,:at,\'client\',\'lama\')'
        )->execute([':gid' => $group['id'], ':at' => $old]);

        $store->prune(30, 5000);

        // Grup aktif tetap ada, event lamanya dibuang.
        $surviving = $store->findGroup((string) $group['id']);
        self::assertNotNull($surviving);
        $messages = array_column($store->eventsForGroup((string) $group['id']), 'message');
        self::assertNotContains('lama', $messages);
        self::assertContains('baru', $messages);
    }

    // ------------------------------------------------------------------
    // listGroups: filter, q escaping, pagination
    // ------------------------------------------------------------------

    public function testListGroupsFiltersAndPagination(): void
    {
        $store = $this->store();

        for ($i = 1; $i <= 5; $i++) {
            $store->record([
                'kind' => $i % 2 === 0 ? 'server' : 'client',
                'message' => 'Gagal ' . $i,
                'route' => '/apps/app-' . $i,
                'app_id' => 'app-' . $i,
                'username' => 'user' . $i,
                'occurred_at' => '2026-10-29T05:0' . $i . ':00Z',
            ], hash('sha256', 'fp-list-' . $i), 20, 3650);
        }

        $open = $store->listGroups([], 1, 2);
        self::assertSame(5, $open['total']);
        self::assertSame(3, $open['pages']);
        self::assertCount(2, $open['groups']);

        $last = $store->listGroups([], 3, 2);
        self::assertCount(1, $last['groups']);

        $server = $store->listGroups(['kind' => 'server'], 1, 50);
        self::assertSame(2, $server['total'], 'hanya kind=server');

        $byApp = $store->listGroups(['app_id' => 'app-3'], 1, 50);
        self::assertSame(1, $byApp['total']);
        self::assertSame('app-3', $byApp['groups'][0]['app_id']);

        // Status lain: selesaikan satu grup.
        $store->setStatus((string) $store->listGroups(['app_id' => 'app-3'], 1, 1)['groups'][0]['id'], 'resolved', 'admin');
        self::assertSame(4, $store->listGroups(['status' => 'open'], 1, 50)['total']);
        self::assertSame(1, $store->listGroups(['status' => 'resolved'], 1, 50)['total']);
        self::assertSame(5, $store->listGroups(['status' => 'all'], 1, 50)['total']);
    }

    public function testListGroupsSearchEscapesLikeWildcards(): void
    {
        $store = $this->store();

        $store->record([
            'kind' => 'client',
            'message' => 'Disk penuh 100%',
            'occurred_at' => '2026-10-29T05:00:00Z',
        ], hash('sha256', 'fp-q1'), 20, 3650);

        $store->record([
            'kind' => 'client',
            'message' => 'Disk penuh total',
            'occurred_at' => '2026-10-29T05:01:00Z',
        ], hash('sha256', 'fp-q2'), 20, 3650);

        $store->record([
            'kind' => 'client',
            'message' => 'Pola a_b',
            'occurred_at' => '2026-10-29T05:02:00Z',
        ], hash('sha256', 'fp-q3'), 20, 3650);

        $percent = $store->listGroups(['q' => '%'], 1, 50);
        self::assertSame(1, $percent['total'], '`%` dicari literal, bukan wildcard');
        self::assertSame('Disk penuh 100%', $percent['groups'][0]['message']);

        $underscore = $store->listGroups(['q' => 'a_b'], 1, 50);
        self::assertSame(1, $underscore['total']);

        // Backslash juga literal (tidak merusak sintaks ESCAPE).
        self::assertSame(0, $store->listGroups(['q' => '\\'], 1, 50)['total']);
    }

    public function testInvalidAppIdFilterIsFailClosed(): void
    {
        $store = $this->store();
        $store->record([
            'kind' => 'client',
            'message' => 'Ada',
            'app_id' => 'app-1',
        ], hash('sha256', 'fp-invalid-app'), 20, 3650);

        self::assertSame(0, $store->listGroups(['app_id' => "x' OR 1=1 --"], 1, 50)['total']);
    }

    public function testSearchQueryIsTruncatedToHundredCharacters(): void
    {
        $filters = ErrorStore::normalizeFilters(['q' => str_repeat('a', 250)]);

        self::assertSame(100, strlen($filters['q']));
    }

    // ------------------------------------------------------------------
    // status & delete
    // ------------------------------------------------------------------

    public function testSetStatusValidatesAllowlistAndMissingGroup(): void
    {
        $store = $this->store();
        $group = $store->record(['kind' => 'server', 'message' => 'X'], hash('sha256', 'fp-set'));

        self::assertFalse($store->setStatus('tidak-ada', 'resolved', 'admin'));
        self::assertFalse($store->setStatus((string) $group['id'], 'hapus', 'admin'));
        self::assertFalse($store->setStatus("bad id!", 'open', 'admin'));

        self::assertTrue($store->setStatus((string) $group['id'], 'resolved', 'admin'));
        $row = $store->findGroup((string) $group['id']);
        self::assertSame('resolved', $row['status']);
        self::assertNotNull($row['resolved_at']);
        self::assertSame('admin', $row['resolved_by']);

        self::assertTrue($store->setStatus((string) $group['id'], 'open', 'admin'));
        $row = $store->findGroup((string) $group['id']);
        self::assertNull($row['resolved_at']);
        self::assertNull($row['resolved_by']);

        self::assertTrue($store->setStatus((string) $group['id'], 'ignored', 'admin'));
        $row = $store->findGroup((string) $group['id']);
        self::assertSame('ignored', $row['status']);
        self::assertNull($row['resolved_at']);
        self::assertSame('admin', $row['resolved_by']);
    }

    public function testDeleteGroupRemovesEventsAndReturnsFalseWhenMissing(): void
    {
        $store = $this->store();
        $group = $store->record(['kind' => 'client', 'message' => 'Hapus'], hash('sha256', 'fp-del'));
        $id = (string) $group['id'];

        self::assertFalse($store->deleteGroup('tidak-ada'));
        self::assertTrue($store->deleteGroup($id));
        self::assertNull($store->findGroup($id));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM "error_events"')->fetchColumn());
        self::assertFalse($store->deleteGroup($id));
    }

    public function testCountsAndOpenCount(): void
    {
        $store = $this->store();

        $a = $store->record(['kind' => 'client', 'message' => 'A'], hash('sha256', 'fp-count-a'));
        $store->record(['kind' => 'client', 'message' => 'B'], hash('sha256', 'fp-count-b'));
        $c = $store->record(['kind' => 'server', 'message' => 'C'], hash('sha256', 'fp-count-c'));

        $store->setStatus((string) $a['id'], 'resolved', 'admin');
        $store->setStatus((string) $c['id'], 'ignored', 'admin');

        self::assertSame(['open' => 1, 'resolved' => 1, 'ignored' => 1], $store->counts());
        self::assertSame(1, $store->openCount());
    }

    public function testRecordRejectsInvalidFingerprint(): void
    {
        $store = $this->store();

        $this->expectException(\InvalidArgumentException::class);
        $store->record(['kind' => 'client', 'message' => 'x'], "bad fingerprint!'");
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function store(): ErrorStore
    {
        return new ErrorStore(new SqliteDatabase($this->file));
    }

    private function pdo(): \PDO
    {
        return (new SqliteDatabase($this->file))->pdo();
    }
}
