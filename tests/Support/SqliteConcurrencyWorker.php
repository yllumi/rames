<?php
declare(strict_types=1);

/**
 * Worker subproses untuk tests/SqliteConcurrencyTest.php — dijalankan lewat
 * `proc_open` (array, tanpa shell). Bukan TestCase; tidak dikumpulkan PHPUnit.
 *
 *   php SqliteConcurrencyWorker.php counter <dbFile> <id> <iterations>
 *   php SqliteConcurrencyWorker.php hold    <dbFile> <holdMs>
 *
 * `counter`: menaikkan nilai pada kunci MILIK SENDIRI (`u{id}`) dan kunci
 * BERSAMA (`shared`) sebanyak <iterations> dalam store logis `conc`
 * (koleksi `items`, map id→dokumen).
 *
 * `hold`: memulai satu transaksi tulis panjang (BEGIN IMMEDIATE) selama
 * <holdMs> ms lalu commit — untuk menguji penulis lain menunggu busy_timeout.
 *
 * Keluar 0 bila sukses, 1 bila ada exception (pesan ke STDERR).
 */

use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;

require __DIR__ . '/../../vendor/autoload.php';

/** @var array<int,array{table:string,path:string,idField:?string,columns:array<int,string>}> */
$defs = [
    ['table' => 'items', 'path' => 'items', 'idField' => null, 'columns' => ['kind']],
];

$mode = (string) ($argv[1] ?? '');
$dbFile = (string) ($argv[2] ?? '');

try {
    switch ($mode) {
        case 'counter':
            $id = (string) ($argv[3] ?? '');
            $iterations = (int) ($argv[4] ?? 25);
            $store = new SqliteStore('conc', $defs, $dbFile);
            for ($i = 0; $i < $iterations; $i++) {
                $store->update(static function (array &$data) use ($id): void {
                    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                    $own = 'u' . $id;
                    $items[$own] = ['kind' => 'own', 'n' => (int) (($items[$own]['n'] ?? 0)) + 1];
                    $items['shared'] = ['kind' => 'shared', 'n' => (int) (($items['shared']['n'] ?? 0)) + 1];
                    $data['items'] = $items;
                });
            }
            fwrite(STDOUT, "counter {$id} ok\n");
            break;

        case 'hold':
            $holdMs = (int) ($argv[3] ?? 1500);
            $db = new SqliteDatabase($dbFile);
            $db->transaction(static function () use ($holdMs): void {
                fwrite(STDOUT, "held\n");
                usleep($holdMs * 1000);
            });
            fwrite(STDOUT, "released\n");
            break;

        default:
            fwrite(STDERR, "unknown mode\n");
            exit(2);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
