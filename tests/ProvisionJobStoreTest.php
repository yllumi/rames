<?php
declare(strict_types=1);

namespace Tests;

use app\library\Provision\ProvisionJobStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Test {@see ProvisionJobStore} (antrean provisioning Ansible, skema v6
 * `provision_jobs`).
 *
 * Seluruh data di berkas .sqlite temp (`database/rames.sqlite` **tidak**
 * disentuh). Yang dikunci:
 *  - `create()` mengisi id/timestamp/status `queued` dan mengabaikan rahasia;
 *  - `find()` menolak id berbentuk asing tanpa menyentuh DB;
 *  - `update()` hanya kolom whitelist dan selalu memperbarui `updated_at`;
 *  - transisi status: running → done/error/cancelled;
 *  - `latestForServer()`/`activeForServer()`;
 *  - `failStaleRunning()` menandai job macet, bukan job baru.
 */
class ProvisionJobStoreTest extends TestCase
{
    private string $file = '';
    private ProvisionJobStore $store;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->file = sys_get_temp_dir() . '/rames-provision-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->store = new ProvisionJobStore(new SqliteDatabase($this->file));
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function job(array $overrides = []): array
    {
        return array_merge([
            'server_id' => 'srv-1',
            'name' => 'agent-01',
            'playbook' => 'provision-agent',
            'host' => '10.0.0.5',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'domain' => 'agent-01.example.com',
            'port_range_start' => 30000,
            'port_range_end' => 30999,
            'agent_port' => 8443,
            'control_plane_ips' => ['10.0.0.1', '10.0.0.2'],
            'manage_firewall' => true,
        ], $overrides);
    }

    private function pdo(): \PDO
    {
        return (new SqliteDatabase($this->file))->pdo();
    }

    public function testCreateReturnsPersistedQueuedJob(): void
    {
        $created = $this->store->create($this->job([
            // Rahasia/field asing TIDAK boleh tersimpan.
            'token' => 'rahasia',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        ]));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $created['id']);
        $this->assertSame('srv-1', $created['server_id']);
        $this->assertSame('agent-01', $created['name']);
        $this->assertSame('provision-agent', $created['playbook']);
        $this->assertSame('10.0.0.5', $created['host']);
        $this->assertSame('root', $created['ssh_user']);
        $this->assertSame(22, $created['ssh_port']);
        $this->assertSame('agent-01.example.com', $created['domain']);
        $this->assertSame(30000, $created['port_range_start']);
        $this->assertSame(30999, $created['port_range_end']);
        $this->assertSame(8443, $created['agent_port']);
        $this->assertSame('10.0.0.1,10.0.0.2', $created['control_plane_ips']);
        $this->assertSame(1, $created['manage_firewall']);
        $this->assertSame(ProvisionJobStore::STATUS_QUEUED, $created['status']);
        $this->assertSame(ProvisionJobStore::STATUS_QUEUED, $created['stage']);
        $this->assertSame('', $created['message']);
        $this->assertNull($created['error']);
        $this->assertNull($created['started_at']);
        $this->assertNull($created['finished_at']);
        $this->assertNotSame('', (string) $created['created_at']);
        $this->assertSame($created['created_at'], $created['updated_at']);

        $found = $this->store->find((string) $created['id']);
        $this->assertIsArray($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame(22, $found['ssh_port']);

        // Kolom rahasia tidak ada di tabel (migrasi 6 tanpa kolom rahasia).
        $columns = array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->pdo()->query('PRAGMA table_info("provision_jobs")')->fetchAll(\PDO::FETCH_ASSOC)
        );
        $this->assertNotContains('token', $columns);
        $this->assertNotContains('private_key', $columns);
        $this->assertNotContains('passphrase', $columns);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"name"');

        $this->store->create($this->job(['name' => '   ']));
    }

    public function testCreateRejectsBadPortRange(): void
    {
        $this->expectException(RuntimeException::class);

        $this->store->create($this->job(['port_range_start' => 31000, 'port_range_end' => 30999]));
    }

    public function testFindRejectsUnknownIdShape(): void
    {
        $this->assertNull($this->store->find(''));
        $this->assertNull($this->store->find('../../etc/passwd'));
        $this->assertNull($this->store->find(str_repeat('a', 65)));
        $this->assertNull($this->store->find('tidak-ada'));
    }

    public function testUpdateOnlyWhitelistedColumnsAndRefreshesUpdatedAt(): void
    {
        $created = $this->store->create($this->job());

        $this->pdo()
            ->prepare('UPDATE "provision_jobs" SET "updated_at" = ? WHERE "id" = ?')
            ->execute(['2000-01-01T00:00:00Z', (string) $created['id']]);

        $this->store->update((string) $created['id'], [
            'status' => ProvisionJobStore::STATUS_RUNNING,
            'stage' => 'preflight',
            'message' => 'menyiapkan',
            // Field asing diabaikan (bukan error dan bukan tersimpan).
            'token' => 'rahasia',
            'host' => '10.9.9.9',
        ]);

        $row = $this->store->find((string) $created['id']);
        $this->assertIsArray($row);
        $this->assertSame('running', $row['status']);
        $this->assertSame('preflight', $row['stage']);
        $this->assertSame('menyiapkan', $row['message']);
        $this->assertSame('10.0.0.5', $row['host'], 'identitas job tidak bisa diubah');
        $this->assertNotSame('2000-01-01T00:00:00Z', $row['updated_at'], 'updated_at selalu diset ulang');
    }

    public function testUpdateRejectsUnknownStatus(): void
    {
        $created = $this->store->create($this->job());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Status');

        $this->store->update((string) $created['id'], ['status' => 'selesai-banget']);
    }

    public function testStatusTransitions(): void
    {
        $created = $this->store->create($this->job());
        $id = (string) $created['id'];

        $this->store->markRunning($id);
        $running = $this->store->find($id);
        $this->assertIsArray($running);
        $this->assertSame('running', $running['status']);
        $this->assertSame('running', $running['stage']);
        $this->assertNotSame('', (string) $running['started_at']);

        $this->store->markDone($id, 'agent terpasang & terverifikasi');
        $done = $this->store->find($id);
        $this->assertIsArray($done);
        $this->assertSame('done', $done['status']);
        $this->assertSame('agent terpasang & terverifikasi', $done['message']);
        $this->assertNull($done['error']);
        $this->assertNotSame('', (string) $done['finished_at']);

        $this->store->markError($id, str_repeat('g', 2500));
        $error = $this->store->find($id);
        $this->assertIsArray($error);
        $this->assertSame('error', $error['status']);
        $this->assertSame(2000, strlen((string) $error['error']), 'error dipotong ≤ 2000 karakter');

        $this->store->markCancelled($id);
        $cancelled = $this->store->find($id);
        $this->assertIsArray($cancelled);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertNotSame('', (string) $cancelled['finished_at']);
    }

    public function testLatestAndActiveForServer(): void
    {
        $old = $this->store->create($this->job());
        $new = $this->store->create($this->job(['name' => 'agent-02']));

        $this->pdo()
            ->prepare('UPDATE "provision_jobs" SET "created_at" = ? WHERE "id" = ?')
            ->execute(['2020-01-01T00:00:00Z', (string) $old['id']]);

        $latest = $this->store->latestForServer('srv-1');
        $this->assertIsArray($latest);
        $this->assertSame($new['id'], $latest['id']);

        $active = $this->store->activeForServer('srv-1');
        $this->assertIsArray($active);
        $this->assertSame($new['id'], $active['id'], 'job queued = aktif');

        $this->store->markDone((string) $new['id'], 'selesai');
        $stillActive = $this->store->activeForServer('srv-1');
        $this->assertIsArray($stillActive);
        $this->assertSame($old['id'], $stillActive['id'], 'job queued lain tetap aktif');

        $this->store->markDone((string) $old['id'], 'selesai');
        $this->assertNull($this->store->activeForServer('srv-1'));
        $this->assertIsArray($this->store->latestForServer('srv-1'), 'job selesai tetap jadi latest');

        $this->assertNull($this->store->latestForServer('srv-tidak-ada'));
        $this->assertNull($this->store->activeForServer('srv-tidak-ada'));
        $this->assertNull($this->store->latestForServer(''));
    }

    public function testRecentOrdersNewestFirstAndCapsLimit(): void
    {
        $first = $this->store->create($this->job(['name' => 'agent-01']));
        $second = $this->store->create($this->job(['name' => 'agent-02']));
        $third = $this->store->create($this->job(['name' => 'agent-03']));

        $this->pdo()->prepare('UPDATE "provision_jobs" SET "created_at" = ? WHERE "id" = ?')
            ->execute(['2020-01-01T00:00:00Z', (string) $first['id']]);

        $recent = $this->store->recent(2);

        $this->assertCount(2, $recent, 'limit dipatuhi');
        $this->assertSame($third['id'], $recent[0]['id'], 'terbaru lebih dulu');
        $this->assertSame($second['id'], $recent[1]['id']);
        $this->assertArrayNotHasKey('token', $recent[0], 'tidak ada kolom rahasia');

        // limit di luar rentang tetap aman (dipatok 1..100).
        $this->assertCount(1, $this->store->recent(0), 'limit < 1 dipatok ke 1');
        $this->assertCount(3, $this->store->recent(1000), 'limit besar tidak melebihi jumlah baris');
    }

    public function testLatestActiveReturnsOnlyQueuedOrRunning(): void
    {
        $done = $this->store->create($this->job(['name' => 'agent-selesai']));
        $this->store->markDone((string) $done['id'], 'selesai');

        $this->assertNull($this->store->latestActive(), 'job selesai bukan job aktif');

        $running = $this->store->create($this->job(['name' => 'agent-berjalan']));
        $this->store->markRunning((string) $running['id']);

        $active = $this->store->latestActive();
        $this->assertIsArray($active);
        $this->assertSame($running['id'], $active['id']);
        $this->assertSame('running', $active['status']);
    }

    public function testFailStaleRunningMarksOnlyOldRunningJobs(): void
    {
        $stale = $this->store->create($this->job(['name' => 'agent-lama']));
        $fresh = $this->store->create($this->job(['name' => 'agent-baru']));

        $this->store->markRunning((string) $stale['id']);
        $this->store->markRunning((string) $fresh['id']);

        $this->pdo()
            ->prepare('UPDATE "provision_jobs" SET "started_at" = ? WHERE "id" = ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 3600), (string) $stale['id']]);

        $this->assertSame(1, $this->store->failStaleRunning(300));

        $staleRow = $this->store->find((string) $stale['id']);
        $this->assertIsArray($staleRow);
        $this->assertSame('error', $staleRow['status']);
        $this->assertStringContainsString('worker berhenti', (string) $staleRow['error']);
        $this->assertNotSame('', (string) $staleRow['finished_at']);

        $freshRow = $this->store->find((string) $fresh['id']);
        $this->assertIsArray($freshRow);
        $this->assertSame('running', $freshRow['status'], 'job baru tidak tersentuh');

        $this->assertSame(0, $this->store->failStaleRunning(0), 'tanpa ambang = tidak ada yang ditandai');
    }
}
