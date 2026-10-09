<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;
use PHPUnit\Framework\TestCase;

/**
 * Uji konkurensi lintas-proses: ≥3 proses PHP paralel melakukan banyak
 * `SqliteStore::update()` pada berkas DB yang sama (WAL + busy_timeout).
 *
 * Membuktikan: tidak ada update hilang, tidak ada `database is locked`/
 * `SQLITE_BUSY`, dan penulis yang menunggu kunci tulis tetap berhasil.
 * Semua path di temp (`sys_get_temp_dir()`), tidak menyentuh data nyata.
 */
class SqliteConcurrencyTest extends TestCase
{
    private const WORKERS = 3;
    private const ITERATIONS = 25;

    private string $tmp;
    private string $file;

    /** @var array<int,array{table:string,path:string,idField:?string,columns:array<int,string>}> */
    private array $defs = [
        ['table' => 'items', 'path' => 'items', 'idField' => null, 'columns' => ['kind']],
    ];

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/sqliteconc_' . bin2hex(random_bytes(4));
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

    /**
     * @param array<int,string> $args
     * @return array{0:resource,1:array<int,resource>}
     */
    private function spawn(string $mode, array $args): array
    {
        $command = array_merge(
            [PHP_BINARY, __DIR__ . '/Support/SqliteConcurrencyWorker.php', $mode],
            $args
        );
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'proc_open gagal');

        return [$proc, $pipes];
    }

    /**
     * @param array{0:resource,1:array<int,resource>} $handle
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function finish(array $handle): array
    {
        [$proc, $pipes] = $handle;
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function store(): SqliteStore
    {
        return new SqliteStore('conc', $this->defs, $this->file);
    }

    public function testParallelWritersDoNotLoseUpdates(): void
    {
        // Mulai semua worker dulu agar benar-benar tumpang tindih.
        $handles = [];
        for ($id = 1; $id <= self::WORKERS; $id++) {
            $handles[$id] = $this->spawn('counter', [$this->file, (string) $id, (string) self::ITERATIONS]);
        }

        foreach ($handles as $id => $handle) {
            $result = $this->finish($handle);
            $this->assertSame(0, $result['code'], "worker {$id} gagal: {$result['stderr']}");
            $this->assertStringNotContainsStringIgnoringCase('database is locked', $result['stderr']);
            $this->assertStringNotContainsString('SQLITE_BUSY', $result['stderr']);
            $this->assertSame('', trim($result['stderr']), "worker {$id} stderr kosong");
        }

        $items = $this->store()->read()['items'] ?? [];

        // Tidak ada update hilang: tiap worker tepat ITERATIONS pada kuncinya.
        for ($id = 1; $id <= self::WORKERS; $id++) {
            $this->assertArrayHasKey('u' . $id, $items, "kunci u{$id} ada");
            $this->assertSame(self::ITERATIONS, $items['u' . $id]['n'], "u{$id} kehilangan update");
        }
        // Koleksi bersama menerima SELURUH tulisan.
        $this->assertSame(
            self::WORKERS * self::ITERATIONS,
            $items['shared']['n'],
            'koleksi bersama kehilangan update'
        );

        $this->assertTrue((new SqliteDatabase($this->file))->integrityCheck(), 'integrity_check ok');
    }

    public function testWriterWaitsForLongHeldLockInsteadOfFailing(): void
    {
        // A: menahan kunci tulis ~1.5 s (di dalam BEGIN IMMEDIATE).
        $a = $this->spawn('hold', [$this->file, '1500']);
        $held = fgets($a[1][1]);
        $this->assertSame("held\n", $held, 'worker penahan kunci mulai');

        // B: menulis saat kunci ditahan → harus MENUNGGU (busy_timeout), bukan gagal.
        $start = microtime(true);
        $b = $this->spawn('counter', [$this->file, 'b', '1']);
        $resultB = $this->finish($b);
        $waited = microtime(true) - $start;

        $this->assertSame(0, $resultB['code'], "B harus sukses menunggu kunci: {$resultB['stderr']}");
        $this->assertSame('', trim($resultB['stderr']));
        $this->assertGreaterThan(0.8, $waited, 'B benar-benar menunggu kunci tulis');
        $this->assertLessThan(4.9, $waited, 'B tidak melebihi busy_timeout');

        // Selesaikan A.
        $resultA = $this->finish($a);
        $this->assertSame(0, $resultA['code'], "A selesai tanpa error: {$resultA['stderr']}");

        $items = $this->store()->read()['items'] ?? [];
        $this->assertSame(1, $items['ub']['n'] ?? null, 'tulisan B tersimpan');
        $this->assertSame(1, $items['shared']['n'] ?? null);
        $this->assertTrue((new SqliteDatabase($this->file))->integrityCheck(), 'integrity_check ok');
    }
}
