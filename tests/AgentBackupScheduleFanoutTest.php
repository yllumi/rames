<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupSelection;
use app\library\Storage\ServerStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Pemicu berkala backup volume **multi-server** (rencana multi-server §4.3/§5,
 * F4 sisa #1) — kontrak yang dikunci:
 *
 *  1. `cli/backup.php run` tanpa daftar volume (bentuk yang dipanggil timer host)
 *     mendistribusikan volume yang **terjadwal di server agent** ke server
 *     pemiliknya (`BackupDispatcher` ⇒ job `backup_run`) — bukan diam-diam
 *     dikerjakan di host control plane;
 *  2. satu clock tetap di control plane: seleksi jadwal dibaca dari store
 *     control plane (`BackupSelection::scheduledByServer()`, ter-scope
 *     `server_id`), dan volume server lokal tetap diproses jalur lokal;
 *  3. dua volume bernama sama di dua server tidak bertabrakan (kunci ter-scope);
 *  4. **tidak menyentuh restic lokal untuk volume agent**: socket Docker diarahkan
 *     ke path mati — apa pun yang mencoba menjalankan restic lokal pasti gagal
 *     keras, sementara job agent tetap terkirim lebih dulu.
 *
 * Seluruh uji: direktori `/tmp`, subproses CLI dengan dispatcher tiruan, tanpa
 * jaringan/Docker/restic nyata.
 */
class AgentBackupScheduleFanoutTest extends TestCase
{
    private const AGENT = 'srv-backup-a';

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-sched-fanout-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) seleksi jadwal ter-scope server
    // ==================================================================

    public function testScheduledVolumesAreGroupedPerServer(): void
    {
        $selection = new BackupSelection($this->tmp . '/rames.sqlite');
        $selection->setScheduled('demo_data', true, 'u1', self::AGENT);
        $selection->setScheduled('demo_data', true, 'u1');           // nama sama, server lokal
        $selection->setScheduled('lain_data', false, 'u1', self::AGENT); // OFF

        $grouped = $selection->scheduledByServer();

        $this->assertSame(['demo_data'], $grouped[ServerStore::LOCAL_ID] ?? [], 'volume lokal tetap terjadwal');
        $this->assertSame(['demo_data'], $grouped[self::AGENT] ?? [], 'volume agent terjadwal di server itu');
        $this->assertArrayNotHasKey('lain_data', array_flip($grouped[self::AGENT] ?? []), 'entri OFF tidak ikut');

        // Dua server boleh punya volume bernama sama tanpa bertabrakan.
        $this->assertSame('demo_data', BackupRegistry::volumeOfKey(BackupRegistry::scopeKey(self::AGENT, 'demo_data')));
        $this->assertSame(self::AGENT, BackupRegistry::serverOfKey(BackupRegistry::scopeKey(self::AGENT, 'demo_data')));
    }

    // ==================================================================
    // (2) pemicu berkala mendispatch job ke server yang benar
    // ==================================================================

    public function testScheduledRunDispatchesAgentVolumeToItsOwnServer(): void
    {
        $this->seedSelection();

        $dispatchLog = $this->tmp . '/dispatch.log';
        $result = $this->runCli(['run'], [
            'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
            'FAKE_BACKUP_DISPATCH_LOG' => $dispatchLog,
        ]);

        $this->assertSame(
            'runBackup:' . self::AGENT . ':demo_data:schedule' . "\n",
            (string) @file_get_contents($dispatchLog),
            'volume terjadwal server agent wajib dikirim sebagai job ke server itu (trigger schedule)'
        );

        // Socket Docker sengaja mati: jalur lokal gagal jujur (tanpa restic lokal,
        // tanpa sukses palsu) — itulah buktinya tidak ada pekerjaan agent yang
        // "jatuh" ke host control plane.
        $this->assertSame(1, $result['code'], 'jalur lokal tanpa Engine harus gagal jujur');
        $this->assertStringContainsString('run GAGAL', (string) $result['stderr']);
    }

    public function testManualRunOfOneVolumeNeverFansOutToOtherServers(): void
    {
        $this->seedSelection();

        $dispatchLog = $this->tmp . '/dispatch-manual.log';
        $result = $this->runCli(['run', 'demo_data', 'manual'], [
            'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
            'FAKE_BACKUP_DISPATCH_LOG' => $dispatchLog,
        ]);

        // Jalur lokal yang diminta tetap jalur lokal (gagal jujur karena Engine
        // mati) — dispatcher agent TIDAK boleh dipanggil.
        $this->assertSame(1, $result['code']);
        $this->assertFileDoesNotExist($dispatchLog, 'run manual satu volume tidak boleh memicu job server agent');
    }

    public function testExplicitAgentRunStillDispatchesWithTheRequestedServer(): void
    {
        $dispatchLog = $this->tmp . '/dispatch-explicit.log';
        $result = $this->runCli(['run', 'demo_data', 'schedule', self::AGENT], [
            'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
            'FAKE_BACKUP_DISPATCH_LOG' => $dispatchLog,
        ]);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(
            'runBackup:' . self::AGENT . ':demo_data:schedule' . "\n",
            (string) @file_get_contents($dispatchLog)
        );
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** Seed seleksi jadwal control plane: satu volume agent + satu volume lokal. */
    private function seedSelection(): void
    {
        $selection = new BackupSelection($this->tmp . '/rames.sqlite');
        $selection->setScheduled('demo_data', true, 'u1', self::AGENT);
        $selection->setScheduled('localhost_data', true, 'u1');
    }

    /**
     * Jalankan `cli/backup.php` sebagai subproses dengan store temp & socket mati.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $extra
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCli(array $args, array $extra = []): array
    {
        $env = array_merge([
            'DATABASE_PATH' => $this->tmp,
            'RAMES_DB_DIR' => $this->tmp,
            'RAMES_DB_FILE' => $this->tmp . '/rames.sqlite',
            'APPS_PATH' => $this->tmp . '/apps',
            // Path mati: restic lokal (lewat Engine) tidak mungkin berjalan.
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
        ], $extra);

        return (new ProcessRunner())->run(
            array_merge([PHP_BINARY, dirname(__DIR__) . '/cli/backup.php'], $args),
            dirname(__DIR__),
            120,
            $env
        );
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}
