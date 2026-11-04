<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\SchemaMigrations;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Test migrasi skema v6: tabel `provision_jobs` (+ indeks) untuk antrean
 * provisioning Ansible.
 *
 * DB dibuat di path temp via PDO — `database/rames.sqlite` nyata tidak pernah
 * disentuh. Yang dikunci:
 *  - DB kosong → seluruh migrasi berjalan, tabel + indeks v6 ada, `user_version`
 *    = LATEST;
 *  - migrasi idempoten (dijalankan 2x, yang kedua 0);
 *  - tabel TIDAK punya kolom rahasia (token/private key/passphrase).
 */
class SchemaMigrationsV6Test extends TestCase
{
    private string $file = '';
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-schema-v6-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    public function testMigrateFromEmptyDatabaseCreatesProvisionJobs(): void
    {
        $this->assertSame(SchemaMigrations::LATEST, SchemaMigrations::migrate($this->pdo));
        $this->assertSame(SchemaMigrations::LATEST, (int) $this->pdo->query('PRAGMA user_version')->fetchColumn());

        $this->assertContains('provision_jobs', $this->tables());

        $columns = $this->columns('provision_jobs');
        foreach ([
            'id', 'server_id', 'name', 'playbook', 'host', 'ssh_user', 'ssh_port',
            'domain', 'port_range_start', 'port_range_end', 'agent_port',
            'control_plane_ips', 'manage_firewall', 'status', 'stage', 'message',
            'email', 'bind', 'ip', 'ssl_challenge', 'ssl_ca_server',
            'error', 'created_at', 'started_at', 'finished_at', 'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns, 'kolom wajib ada: ' . $column);
        }

        // Tanpa kolom rahasia.
        foreach (['token', 'private_key', 'ssh_private_key', 'passphrase', 'pem'] as $secret) {
            $this->assertNotContains($secret, $columns);
        }

        $indexes = $this->indexes('provision_jobs');
        $this->assertContains('idx_provision_jobs_status', $indexes);
        $this->assertContains('idx_provision_jobs_server', $indexes);
    }

    public function testMigrationIsIdempotent(): void
    {
        $this->assertSame(SchemaMigrations::LATEST, SchemaMigrations::migrate($this->pdo));
        $this->assertSame(0, SchemaMigrations::migrate($this->pdo), 'panggilan kedua tidak menerapkan apa pun');

        $versions = array_map(
            'intval',
            $this->pdo->query('SELECT "version" FROM "schema_migrations" ORDER BY "version"')->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertSame(range(1, SchemaMigrations::LATEST), $versions);

        // Tabel tetap satu setelah migrasi diulang.
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = \'provision_jobs\''
        )->fetchColumn());
    }

    public function testMigrationSixRecreatesTableWhenMissing(): void
    {
        SchemaMigrations::migrate($this->pdo);

        // Tiru DB yang kehilangan tabel & catatan versi 6.
        $this->pdo->exec('DROP TABLE "provision_jobs"');
        $this->pdo->exec('DELETE FROM "schema_migrations" WHERE "version" = 6');

        $this->assertSame(1, SchemaMigrations::migrate($this->pdo), 'hanya migrasi 6 yang tertunda');
        $this->assertContains('provision_jobs', $this->tables());
        $this->assertContains('idx_provision_jobs_status', $this->indexes('provision_jobs'));
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
}
