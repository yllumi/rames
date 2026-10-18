<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\NonceStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Cache anti-replay nonce agent (plan §4.1).
 *
 * Semua uji memakai berkas di direktori temp (**tidak menyentuh**
 * `runtime/agent-nonces.json` nyata) dan jam tiruan — tanpa `sleep()`, jadi
 * perilaku TTL & prunning bisa diuji deterministik.
 */
class NonceStoreTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_nonce_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testFirstUseAcceptedAndSecondRejectedWithinTtl(): void
    {
        $store = $this->store(1000);

        $this->assertTrue($store->consume('nonce-aaaaaaaaaaaaaa'), 'pemakaian pertama harus diterima');
        $this->assertFalse($store->consume('nonce-aaaaaaaaaaaaaa'), 'pemakaian kedua (replay) harus ditolak');
    }

    public function testDifferentNoncesAreIndependent(): void
    {
        $store = $this->store(1000);

        $this->assertTrue($store->consume('nonce-aaaaaaaaaaaaaa'));
        $this->assertTrue($store->consume('nonce-bbbbbbbbbbbbbb'));
        $this->assertFalse($store->consume('nonce-aaaaaaaaaaaaaa'));
        $this->assertFalse($store->consume('nonce-bbbbbbbbbbbbbb'));
    }

    public function testNonceIsAcceptedAgainAfterTtlExpires(): void
    {
        $now = 1000;
        $store = new NonceStore($this->path(), static function () use (&$now): int {
            return $now;
        });

        $this->assertTrue($store->consume('nonce-aaaaaaaaaaaaaa'), 'pemakaian pertama diterima');
        $now = 1000 + NonceStore::DEFAULT_TTL; // tepat di batas TTL → masih tertahan
        $this->assertFalse($store->consume('nonce-aaaaaaaaaaaaaa'), 'tepat di TTL masih dianggap replay');

        $now = 1000 + NonceStore::DEFAULT_TTL + 1; // lewat TTL → entri dibuang, nonce diterima lagi
        $this->assertTrue($store->consume('nonce-aaaaaaaaaaaaaa'), 'setelah lewat TTL nonce boleh dipakai lagi');
    }

    public function testExpiredEntriesArePrunedSoFileStaysBounded(): void
    {
        $store = new NonceStore($this->path());

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($store->consume('nonce-fill-' . str_pad((string) $i, 6, '0'), 1000));
        }
        $this->assertSame(5, $store->count());

        // Prune oportunistik per shard (pada consume) + purge berkala untuk shard dingin.
        $later = 1000 + NonceStore::DEFAULT_TTL + 1;
        $this->assertTrue($store->consume('nonce-later-00000000', $later));
        $store->purge($later);
        $this->assertSame(1, $store->count(), 'entri kedaluwarsa dibuang, bukan menumpuk selamanya');
    }

    public function testRawNonceIsNeverStoredInPlaintext(): void
    {
        $store = new NonceStore($this->path());
        $nonce = 'nonce-rahasia-1234567890';
        $this->assertTrue($store->consume($nonce, 1000));

        $raw = (string) file_get_contents($store->shardPath($nonce));
        $this->assertStringNotContainsString($nonce, $raw, 'berkas runtime tidak boleh memuat nonce mentah');
        $this->assertStringContainsString(NonceStore::key($nonce), $raw, 'yang disimpan adalah hash nonce');
    }

    public function testPurgeRemovesExpiredEntriesAndKeepsFreshOnes(): void
    {
        $store = new NonceStore($this->path());
        $store->consume('nonce-old-0000000000', 1000);
        $store->consume('nonce-new-0000000000', 1000 + NonceStore::DEFAULT_TTL);

        $removed = $store->purge(1000 + NonceStore::DEFAULT_TTL + 5);
        $this->assertSame(1, $removed);
        $this->assertSame(1, $store->count());
    }

    public function testCorruptFileDoesNotBreakConsume(): void
    {
        $store = new NonceStore($this->path());
        file_put_contents($store->shardPath('nonce-aaaaaaaaaaaaaa'), "bukan json\n");

        $this->assertTrue($store->consume('nonce-aaaaaaaaaaaaaa', 1000));
        $this->assertSame(1, $store->count());
    }

    public function testUnsupportedEntryShapeIsDiscarded(): void
    {
        $store = new NonceStore($this->path());
        file_put_contents(
            $store->shardPath('nonce-aaaaaaaaaaaaaa'),
            json_encode(['abc' => 'bukan-angka', 'def' => 0], JSON_PRETTY_PRINT) . PHP_EOL
        );

        $at = NonceStore::DEFAULT_TTL + 1; // `def` sudah kedaluwarsa, `abc` berbentuk asing
        $this->assertTrue($store->consume('nonce-aaaaaaaaaaaaaa', $at));
        $this->assertSame(1, $store->count(), 'entri berbentuk asing & kedaluwarsa dibuang saat prune');
    }

    public function testReplayIsRejectedAcrossShardsAndNonceLandsInOneShard(): void
    {
        $store = $this->store(1000);
        for ($i = 0; $i < 40; $i++) {
            $nonce = sprintf('nonce-shard-%010d', $i);
            $this->assertTrue($store->consume($nonce, 1000));
            $this->assertFalse($store->consume($nonce, 1001), 'replay harus ditolak di shard yang sama');
        }
        $this->assertSame(40, $store->count());
        $this->assertGreaterThan(1, count(glob($this->tmp . '/agent-nonces.*.json') ?: []), 'nonce tersebar ke beberapa shard');
    }

    private function path(): string
    {
        return $this->tmp . '/agent-nonces.json';
    }

    private function store(int $now): NonceStore
    {
        return new NonceStore($this->path(), static fn (): int => $now);
    }
}
