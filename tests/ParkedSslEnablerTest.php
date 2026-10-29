<?php
declare(strict_types=1);

namespace Tests;

use app\library\SSL\ParkedSslEnabler;
use app\library\SSL\SslQueue;
use app\library\Storage\ParkedDomainStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Test mediator pengaktifan SSL domain parkir ({@see ParkedSslEnabler}).
 *
 * Tanpa HTTP, certbot, maupun Docker: seam `?SslQueue $queue` berupa antrean
 * ber-basis data temp menggantikan worker detached, dan store disuntikkan lewat
 * konstruktor sehingga seluruh data tinggal di berkas .sqlite temp
 * (`database/` nyata tidak disentuh).
 *
 * Yang dibuktikan: sukses enqueue ⇒ `pending`/`queued`/`ssl_message` terisi +
 * job `ssl_jobs` dibuat, gagal enqueue ⇒ `failed` + `ssl_error`, status
 * `active`/`pending` ditolak sebagai info tanpa job, domain non-publik ditolak,
 * dan entri tak ada ditolak — semuanya tanpa menjalankan certbot.
 */
class ParkedSslEnablerTest extends TestCase
{
    private string $file;
    private ParkedDomainStore $store;
    private SslQueue $queue;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-parked-ssl-enabler-' . bin2hex(random_bytes(5)) . '.sqlite';
        SqliteDatabase::reset();
        $this->store = new ParkedDomainStore($this->file);
        $this->queue = new SslQueue(new SqliteDatabase($this->file));
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
    }

    /**
     * Enabler dengan antrean temp; `$queueOk = false` memakai antrean yang
     * tidak bisa membuka basis data (`/dev/null/...`) untuk jalur gagal-enqueue.
     */
    private function enabler(bool $queueOk = true): ParkedSslEnabler
    {
        $queue = $queueOk
            ? $this->queue
            : new SslQueue(new SqliteDatabase('/dev/null/rames-parked/rames.sqlite'));

        return new ParkedSslEnabler($queue, $this->store);
    }

    public function testSuccessMarksEntryPendingAndReportsSuccess(): void
    {
        $entry = $this->store->create(['domain' => 'ssl-parkir.example.com', 'mode' => 'html']);

        $result = $this->enabler()->enable($entry['id']);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('success', $result['flash']);
        $this->assertSame('ssl-parkir.example.com', $result['domain']);
        $this->assertSame(
            'SSL untuk ssl-parkir.example.com masuk antrean (posisi 1 dari 1).',
            $result['message']
        );

        $saved = $this->store->find($entry['id']);
        $this->assertNotNull($saved);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertSame('Menunggu antrean SSL ...', $saved['ssl_message']);
        $this->assertNull($saved['ssl_error']);

        $job = $this->queue->findActive('parked:' . $entry['id'] . ':ssl-parkir.example.com');
        $this->assertIsArray($job, 'entri harus menjadi job antrean');
        $this->assertSame('parked', $job['target_type']);
        $this->assertSame(1, $this->queue->queueSize());
    }

    public function testEnqueueFailureMarksEntryFailedAndReportsError(): void
    {
        $entry = $this->store->create(['domain' => 'ssl-gagal.example.com', 'mode' => 'html']);

        $result = $this->enabler(false)->enable($entry['id']);

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('error', $result['flash']);
        $this->assertSame('Gagal memasukkan permintaan SSL ke antrean.', (string) $result['error']);

        $saved = $this->store->find($entry['id']);
        $this->assertNotNull($saved);
        $this->assertSame('failed', $saved['ssl_status']);
        $this->assertNull($saved['ssl_stage']);
        $this->assertNull($saved['ssl_message']);
        $this->assertStringContainsString('Gagal memasukkan permintaan SSL ke antrean', (string) $saved['ssl_error']);
    }

    public function testActiveEntryIsRejectedAsInfoWithoutEnqueue(): void
    {
        $entry = $this->store->create([
            'domain' => 'ssl-aktif.example.com',
            'mode' => 'html',
            'ssl_status' => 'active',
        ]);
        $before = $this->store->find($entry['id']);

        $result = $this->enabler()->enable($entry['id']);

        $this->assertSame(0, $this->queue->queueSize(), 'SSL yang sudah active tidak boleh masuk antrean');
        $this->assertFalse($result['ok']);
        $this->assertSame('already', $result['status']);
        $this->assertSame('info', $result['flash']);
        $this->assertStringContainsString('ssl-aktif.example.com', (string) $result['error']);
        $this->assertSame($before, $this->store->find($entry['id']), 'state tidak boleh berubah');
    }

    public function testPendingEntryIsRejectedAsInfoWithoutEnqueue(): void
    {
        $entry = $this->store->create([
            'domain' => 'ssl-menunggu.example.com',
            'mode' => 'html',
            'ssl_status' => 'pending',
            'ssl_stage' => 'dns',
        ]);
        $before = $this->store->find($entry['id']);

        $result = $this->enabler()->enable($entry['id']);

        $this->assertSame(0, $this->queue->queueSize(), 'SSL yang sedang pending tidak boleh masuk antrean');
        $this->assertFalse($result['ok']);
        $this->assertSame('already', $result['status']);
        $this->assertSame('info', $result['flash']);
        $this->assertSame($before, $this->store->find($entry['id']), 'state tidak boleh berubah');
    }

    public function testNonPublicDomainIsRejectedWithoutEnqueue(): void
    {
        $entry = $this->store->create(['domain' => 'parkir.local', 'mode' => 'html']);
        $before = $this->store->find($entry['id']);

        $result = $this->enabler()->enable($entry['id']);

        $this->assertSame(0, $this->queue->queueSize(), 'domain .local tidak boleh memicu certbot');
        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('error', $result['flash']);
        $this->assertStringContainsString('bukan domain publik', (string) $result['error']);
        $this->assertSame($before, $this->store->find($entry['id']), 'state tidak boleh berubah');
    }

    public function testMissingEntryIsRejectedWithoutEnqueue(): void
    {
        $result = $this->enabler()->enable('pd_000000000000');

        $this->assertSame(0, $this->queue->queueSize(), 'entri tak ada tidak boleh masuk antrean');
        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('error', $result['flash']);
        $this->assertStringContainsString('Domain parkir tidak ditemukan', (string) $result['error']);
        $this->assertSame([], $this->store->all(), 'store tetap kosong');
    }
}
