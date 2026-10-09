<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\JsonImporter;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test {@see JsonImporter} — impor sekali dari JSON lama, idempoten, tidak
 * menimpa data baru, dan ekspor balik (round-trip). Semua di path temp.
 */
class JsonImporterTest extends TestCase
{
    private string $tmp;
    private string $legacyDir;
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/jsonimporter_' . bin2hex(random_bytes(4));
        $this->legacyDir = $this->tmp . '/legacy';
        mkdir($this->legacyDir, 0777, true);
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

    /** @param array<string,mixed> $data */
    private function putLegacy(string $name, array $data): void
    {
        file_put_contents($this->legacyDir . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int,array> */
    private function sampleApps(): array
    {
        return [
            [
                'id' => 'a1',
                'name' => 'alfa',
                'owner_id' => 'u1',
                'status' => 'running',
                'subdomain' => 'alfa.example.com',
                'containers' => [['service_name' => 'webman', 'ports' => [['host' => 30000, 'container' => 8787]]]],
                'needs_ssl' => false,
                'idle_seconds' => null,
            ],
            ['id' => 'a2', 'name' => 'beta', 'owner_id' => 'u2', 'status' => 'stopped', 'subdomain' => 'beta.example.com'],
        ];
    }

    /** @return array<int,array> */
    private function sampleUsers(): array
    {
        return [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => '2026-01-01T00:00:00+07:00'],
            ['id' => 'u2', 'username' => 'bob', 'password_hash' => 'y', 'role' => 'member', 'email' => 'bob@example.com'],
        ];
    }

    /** @return array<string,mixed> */
    private function sampleBilling(): array
    {
        return [
            'version' => 1,
            'users' => ['u2' => ['balance' => 100000.5, 'updated_at' => '2026-10-01T00:00:00+07:00', 'ledger' => []]],
            'usage' => ['a2' => ['owner_id' => 'u2', 'period' => '2026-10', 'seconds_pending' => 4200, 'credits_pending' => 55.25]],
            'orders' => ['o1' => ['user_id' => 'u2', 'status' => 'pending', 'amount' => 50000]],
        ];
    }

    /** @return array<string,mixed> */
    private function sampleBackup(): array
    {
        return [
            'version' => 1,
            'volumes' => ['vol_a' => ['scheduled' => true, 'updated_at' => '2026-10-01T00:00:00+07:00']],
            'registry' => ['vol_a' => ['project' => 'alfa', 'app_id' => 'a1', 'snapshots' => 3]],
        ];
    }

    private function importer(): JsonImporter
    {
        return new JsonImporter($this->legacyDir, $this->file);
    }

    public function testImportFromLegacyJson(): void
    {
        $this->putLegacy('apps.json', $this->sampleApps());
        $this->putLegacy('auth.json', $this->sampleUsers());
        $this->putLegacy('billing.json', $this->sampleBilling());
        $this->putLegacy('backup.json', $this->sampleBackup());

        $summary = $this->importer()->importIfNeeded();

        $this->assertFalse($summary['skipped']);
        $this->assertSame(2, $summary['apps']);
        $this->assertSame(2, $summary['users']);
        $this->assertTrue($summary['billing']);
        $this->assertTrue($summary['backup']);

        $this->assertSame($this->sampleApps(), SqliteFixture::readAll($this->file, 'apps'));
        $this->assertSame($this->sampleUsers(), SqliteFixture::readAll($this->file, 'auth'));

        $billing = SqliteFixture::readAll($this->file, 'billing');
        $this->assertSame($this->sampleBilling()['users'], $billing['users']);
        $this->assertSame($this->sampleBilling()['usage'], $billing['usage']);
        $this->assertSame($this->sampleBilling()['orders'], $billing['orders']);

        $backup = SqliteFixture::readAll($this->file, 'backup');
        $this->assertSame($this->sampleBackup()['volumes'], $backup['volumes']);
        $this->assertSame($this->sampleBackup()['registry'], $backup['registry']);

        // berkas JSON lama tidak diubah
        $this->assertSame($this->sampleApps(), json_decode((string) file_get_contents($this->legacyDir . '/apps.json'), true));
    }

    public function testImportIsIdempotent(): void
    {
        $this->putLegacy('apps.json', $this->sampleApps());
        $this->putLegacy('auth.json', $this->sampleUsers());

        $first = $this->importer()->importIfNeeded();
        $this->assertFalse($first['skipped']);

        $second = $this->importer()->importIfNeeded();
        $this->assertTrue($second['skipped'], 'panggilan kedua dilewati (flag legacy_imported_at)');
        $this->assertSame(2, $second['apps']);
        $this->assertSame(2, $second['users']);
    }

    public function testImportDoesNotOverwriteNonEmptyStore(): void
    {
        // store `apps` sudah berisi data baru (bukan dari impor)
        SqliteFixture::apps($this->file, [['id' => 'baru', 'name' => 'baru', 'owner_id' => 'u9']]);
        $this->putLegacy('apps.json', $this->sampleApps());
        $this->putLegacy('auth.json', $this->sampleUsers());

        $summary = $this->importer()->importIfNeeded();

        $this->assertFalse($summary['skipped']);
        $apps = SqliteFixture::readAll($this->file, 'apps');
        $this->assertCount(1, $apps, 'store apps yang sudah berisi tidak ditimpa');
        $this->assertSame('baru', $apps[0]['id']);
        // store lain tetap diimpor
        $this->assertSame(2, $summary['users']);
        $this->assertCount(2, SqliteFixture::readAll($this->file, 'auth'));
    }

    public function testImportSkipsMissingFiles(): void
    {
        $summary = $this->importer()->importIfNeeded();
        $this->assertFalse($summary['skipped']);
        $this->assertSame(0, $summary['apps']);
        $this->assertSame(0, $summary['users']);
        $this->assertFalse($summary['billing']);
        $this->assertFalse($summary['backup']);

        $this->assertTrue($this->importer()->importIfNeeded()['skipped']);
    }

    public function testForceBypassesFlagButKeepsPopulatedStores(): void
    {
        $this->putLegacy('apps.json', $this->sampleApps());

        $importer = $this->importer();
        $importer->importIfNeeded();
        $this->assertTrue($importer->importIfNeeded()['skipped']);

        // --force: flag diabaikan, tapi store yang sudah berisi tetap aman
        $forced = $importer->importIfNeeded(null, true);
        $this->assertFalse($forced['skipped']);
        $this->assertCount(2, SqliteFixture::readAll($this->file, 'apps'));
    }

    public function testExportRoundTrip(): void
    {
        $this->putLegacy('apps.json', $this->sampleApps());
        $this->putLegacy('auth.json', $this->sampleUsers());
        $this->putLegacy('billing.json', $this->sampleBilling());
        $this->putLegacy('backup.json', $this->sampleBackup());
        $this->importer()->importIfNeeded();

        $outDir = $this->tmp . '/export';
        $files = $this->importer()->exportToJson($outDir);

        $this->assertCount(4, $files);
        $this->assertDirectoryExists($outDir);
        foreach (['apps.json', 'auth.json', 'billing.json', 'backup.json'] as $name) {
            $this->assertFileExists($outDir . '/' . $name);
        }

        $this->assertSame($this->sampleApps(), json_decode((string) file_get_contents($outDir . '/apps.json'), true));
        $this->assertSame($this->sampleUsers(), json_decode((string) file_get_contents($outDir . '/auth.json'), true));

        $billing = json_decode((string) file_get_contents($outDir . '/billing.json'), true);
        $this->assertSame(1, $billing['version']);
        $this->assertSame($this->sampleBilling()['users'], $billing['users']);
        $this->assertSame($this->sampleBilling()['usage'], $billing['usage']);
        $this->assertSame($this->sampleBilling()['orders'], $billing['orders']);

        $backup = json_decode((string) file_get_contents($outDir . '/backup.json'), true);
        $this->assertSame($this->sampleBackup()['volumes'], $backup['volumes']);
        $this->assertSame($this->sampleBackup()['registry'], $backup['registry']);
    }
}
