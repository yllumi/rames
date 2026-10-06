<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Backup\BackupSelection;
use PHPUnit\Framework\TestCase;

/**
 * Test pemetaan `scheduled` pada baris volume respons `/backups` (status &
 * refresh) — `BackupController::withScheduled()`.
 *
 * Murni data (tanpa Docker/restic/berkas runtime nyata): `BackupSelection`
 * diarahkan ke berkas temp agar data runtime tidak tersentuh.
 */
class BackupControllerScheduledTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupctl_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/backup.json';
    }

    protected function tearDown(): void
    {
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(): array
    {
        return [
            ['name' => 'tonidata_data', 'project' => 'tonidata', 'app_id' => 'app1'],
            ['name' => 'waha_data', 'project' => 'waha', 'app_id' => 'app2'],
            ['name' => 'ghost_data', 'project' => 'ghost', 'app_id' => null, 'orphaned' => true],
        ];
    }

    public function testDefaultsOffWhenNoExplicitEntry(): void
    {
        $selection = new BackupSelection($this->path);

        $out = BackupController::withScheduled($this->rows(), $selection);

        $this->assertCount(3, $out);
        foreach ($out as $row) {
            $this->assertFalse($row['scheduled'], 'default OFF bila tak ada entri eksplisit');
        }
    }

    public function testMergesFreshFlagAndPreservesKeysAndOrder(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', false, 'u1');
        $selection->setScheduled('waha_data', true, 'u1');

        $out = BackupController::withScheduled($this->rows(), $selection);

        $this->assertSame('tonidata_data', $out[0]['name']);
        $this->assertSame('waha_data', $out[1]['name']);
        $this->assertSame('ghost_data', $out[2]['name']);

        $this->assertFalse($out[0]['scheduled'], 'flag OFF eksplisit terbaca');
        $this->assertTrue($out[1]['scheduled'], 'flag ON eksplisit terbaca');
        $this->assertFalse($out[2]['scheduled'], 'volume yatim tanpa entri → default OFF');

        // Kunci asli tidak hilang/berubah.
        $this->assertSame('tonidata', $out[0]['project']);
        $this->assertSame('app1', $out[0]['app_id']);
        $this->assertTrue($out[2]['orphaned']);
    }

    public function testReflectsLatestSelectionWithoutCaching(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', true, 'u1');

        $first = BackupController::withScheduled($this->rows(), $selection);
        $this->assertTrue($first[0]['scheduled']);

        // Mutasi setelah pemetaan pertama harus terbaca pada pemetaan berikutnya
        // (tidak ada salinan basi di controller).
        $selection->setScheduled('tonidata_data', false, 'u1');
        $second = BackupController::withScheduled($this->rows(), $selection);
        $this->assertFalse($second[0]['scheduled']);

        // Instance baru (reload dari berkas) juga konsisten.
        $reloaded = new BackupSelection($this->path);
        $third = BackupController::withScheduled($this->rows(), $reloaded);
        $this->assertFalse($third[0]['scheduled']);
    }

    public function testEmptyRowsYieldEmptyArray(): void
    {
        $selection = new BackupSelection($this->path);

        $this->assertSame([], BackupController::withScheduled([], $selection));
    }
}
