<?php
declare(strict_types=1);

namespace Tests;

use app\library\SSL\ParkedSslEnabler;
use app\library\Storage\ParkedDomainStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Test mediator pengaktifan SSL domain parkir ({@see ParkedSslEnabler}).
 *
 * Tanpa HTTP, certbot, maupun Docker: seam `$spawn` berupa closure
 * `fn(string $id): bool` menggantikan peluncuran worker detached, dan store
 * disuntikkan lewat konstruktor sehingga seluruh data tinggal di berkas
 * .sqlite temp (`database/` nyata tidak disentuh).
 *
 * Yang dibuktikan: sukses spawn ⇒ `pending`/`queued`/`ssl_message` terisi,
 * gagal spawn ⇒ `failed` + `ssl_error`, status `active`/`pending` ditolak
 * sebagai info tanpa spawn, domain non-publik ditolak, dan entri tak ada
 * ditolak — semuanya tanpa mengubah state atau memanggil spawn.
 */
class ParkedSslEnablerTest extends TestCase
{
    private string $file;
    private ParkedDomainStore $store;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-parked-ssl-enabler-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->store = new ParkedDomainStore($this->file);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    /**
     * Enabler dengan seam spawn yang mencatat id dan mengembalikan hasil tetap.
     *
     * @param array<int,string> $calls
     */
    private function enabler(bool $spawnOk, array &$calls): ParkedSslEnabler
    {
        return new ParkedSslEnabler(
            static function (string $id) use ($spawnOk, &$calls): bool {
                $calls[] = $id;

                return $spawnOk;
            },
            $this->store
        );
    }

    public function testSuccessMarksEntryPendingAndReportsSuccess(): void
    {
        $entry = $this->store->create(['domain' => 'ssl-parkir.example.com', 'mode' => 'html']);

        $calls = [];
        $result = $this->enabler(true, $calls)->enable($entry['id']);

        $this->assertSame([$entry['id']], $calls, 'worker SSL diploncat tepat sekali untuk id entri');
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('success', $result['flash']);
        $this->assertSame('ssl-parkir.example.com', $result['domain']);
        $this->assertStringContainsString('ssl-parkir.example.com', $result['message']);

        $saved = $this->store->find($entry['id']);
        $this->assertNotNull($saved);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertIsString($saved['ssl_message']);
        $this->assertNotSame('', trim((string) $saved['ssl_message']), 'ssl_message harus terisi sebelum spawn');
        $this->assertNull($saved['ssl_error']);
    }

    public function testSpawnFailureMarksEntryFailedAndReportsError(): void
    {
        $entry = $this->store->create(['domain' => 'ssl-gagal.example.com', 'mode' => 'html']);

        $calls = [];
        $result = $this->enabler(false, $calls)->enable($entry['id']);

        $this->assertSame([$entry['id']], $calls, 'spawn tetap dipanggil sekali walau gagal');
        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('error', $result['flash']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Gagal menjalankan worker SSL', (string) $result['error']);

        $saved = $this->store->find($entry['id']);
        $this->assertNotNull($saved);
        $this->assertSame('failed', $saved['ssl_status']);
        $this->assertNull($saved['ssl_stage']);
        $this->assertNull($saved['ssl_message']);
        $this->assertStringContainsString('Gagal spawn worker SSL', (string) $saved['ssl_error']);
    }

    public function testActiveEntryIsRejectedAsInfoWithoutSpawn(): void
    {
        $entry = $this->store->create([
            'domain' => 'ssl-aktif.example.com',
            'mode' => 'html',
            'ssl_status' => 'active',
        ]);
        $before = $this->store->find($entry['id']);

        $calls = [];
        $result = $this->enabler(true, $calls)->enable($entry['id']);

        $this->assertSame([], $calls, 'SSL yang sudah active tidak boleh spawn ulang');
        $this->assertFalse($result['ok']);
        $this->assertSame('already', $result['status']);
        $this->assertSame('info', $result['flash']);
        $this->assertStringContainsString('ssl-aktif.example.com', (string) $result['error']);
        $this->assertSame($before, $this->store->find($entry['id']), 'state tidak boleh berubah');
    }

    public function testPendingEntryIsRejectedAsInfoWithoutSpawn(): void
    {
        $entry = $this->store->create([
            'domain' => 'ssl-menunggu.example.com',
            'mode' => 'html',
            'ssl_status' => 'pending',
            'ssl_stage' => 'dns',
        ]);
        $before = $this->store->find($entry['id']);

        $calls = [];
        $result = $this->enabler(true, $calls)->enable($entry['id']);

        $this->assertSame([], $calls, 'SSL yang sedang pending tidak boleh spawn ulang');
        $this->assertFalse($result['ok']);
        $this->assertSame('already', $result['status']);
        $this->assertSame('info', $result['flash']);
        $this->assertSame($before, $this->store->find($entry['id']), 'state tidak boleh berubah');
    }

    public function testNonPublicDomainIsRejectedWithoutSpawn(): void
    {
        $entry = $this->store->create(['domain' => 'parkir.local', 'mode' => 'html']);
        $before = $this->store->find($entry['id']);

        $calls = [];
        $result = $this->enabler(true, $calls)->enable($entry['id']);

        $this->assertSame([], $calls, 'domain .local tidak boleh memicu certbot');
        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('error', $result['flash']);
        $this->assertStringContainsString('bukan domain publik', (string) $result['error']);
        $this->assertSame($before, $this->store->find($entry['id']), 'state tidak boleh berubah');
    }

    public function testMissingEntryIsRejectedWithoutSpawn(): void
    {
        $calls = [];
        $result = $this->enabler(true, $calls)->enable('pd_000000000000');

        $this->assertSame([], $calls, 'entri tak ada tidak boleh spawn worker');
        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('error', $result['flash']);
        $this->assertStringContainsString('Domain parkir tidak ditemukan', (string) $result['error']);
        $this->assertSame([], $this->store->all(), 'store tetap kosong');
    }
}
