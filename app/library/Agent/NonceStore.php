<?php
declare(strict_types=1);

namespace app\library\Agent;

use Closure;
use app\library\Storage\JsonStore;

/**
 * Cache anti-replay nonce untuk autentikasi mesin-ke-mesin agent (plan §4.1).
 *
 * Request agent wajib membawa `X-Rames-Nonce` yang belum pernah dipakai dalam
 * jendela toleransi waktu. Karena Webman berjalan **multi-worker persistent**,
 * cache tidak boleh hidup di memori proses (worker berbeda tidak saling
 * melihat) — karenanya disimpan di berkas JSON lewat `JsonStore` yang menulis
 * atomik di bawah `flock`. Pemeriksaan "sudah pernah dipakai?" dan penyisipan
 * nonce terjadi di dalam **satu** `update()`, sehingga dua request paralel
 * dengan nonce sama tidak bisa sama-sama lolos (baca-tulis tidak terpisah).
 *
 * Nilai nonce disimpan **ter-hash** (`sha256`), bukan mentah: berkas runtime ini
 * bukan daftar nilai yang bisa dipakai ulang kalau bocor (mis. lewat backup
 * volume atau pemeriksaan isi berkas oleh user lain di host yang sama).
 *
 * TTL = 2× jendela timestamp (120 s untuk jendela ±60 s) — replay yang masih
 * mungkin lolos pemeriksaan timestamp (karena skew jam) tetap tertahan oleh
 * cache. Entri kedaluwarsa dibuang **oportunistik** pada setiap `consume()`
 * supaya berkas tidak tumbuh tanpa batas; ukurannya proporsional dengan jumlah
 * request agent dalam 2 menit terakhir, bukan umur instalasi.
 *
 * Jam diterima lewat konstruktor (`$now`) agar uji tidak bergantung `sleep()`
 * (pola sama dengan `EnvManager`/`TemplateCatalog`: path & dependency
 * di-inject, kelas tanpa state lintas-request).
 */
final class NonceStore
{
    /** TTL default (detik) = 2× jendela timestamp agent (`±60 s`). */
    public const DEFAULT_TTL = 120;

    private readonly string $path;

    private readonly int $ttl;

    /** @var Closure():int */
    private readonly Closure $clock;

    /**
     * @param string        $path berkas cache nonce (mis. `runtime/agent-nonces.json`)
     * @param callable|null $now  pengganti `time()` untuk uji: `fn (): int`
     * @param int           $ttl  masa simpan nonce (detik)
     */
    public function __construct(string $path, ?callable $now = null, int $ttl = self::DEFAULT_TTL)
    {
        $this->path = $path;
        $this->ttl = max(1, $ttl);
        $this->clock = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Pakai nonce sekali. `true` = nonce diterima (belum pernah dipakai atau
     * sudah kedaluwarsa), `false` = replay di dalam TTL.
     *
     * @param int|null $now waktu uji; default jam internal
     */
    public function consume(string $nonce, ?int $now = null): bool
    {
        $at = $now ?? $this->now();
        $key = self::key($nonce);
        $ttl = $this->ttl;
        $accepted = false;

        $this->store()->update(
            static function (array &$data) use ($key, $at, $ttl, &$accepted): void {
                $data = self::prune($data, $at, $ttl);
                if (array_key_exists($key, $data)) {
                    $accepted = false; // replay: entri masih hidup
                    return;
                }
                $data[$key] = $at;
                $accepted = true;
            }
        );

        return $accepted;
    }

    /**
     * Buang entri kedaluwarsa tanpa memakai nonce (dipakai cron/uji).
     *
     * @return int jumlah entri yang dibuang
     */
    public function purge(?int $now = null): int
    {
        $at = $now ?? $this->now();
        $ttl = $this->ttl;
        $removed = 0;

        $this->store()->update(
            static function (array &$data) use ($at, $ttl, &$removed): void {
                $before = count($data);
                $data = self::prune($data, $at, $ttl);
                $removed = $before - count($data);
            }
        );

        return $removed;
    }

    /** Jumlah nonce hidup saat ini (untuk uji prunning — tidak menyentuh store lain). */
    public function count(): int
    {
        return count($this->store()->read());
    }

    /**
     * Kunci penyimpanan = hash nonce mentah. Nonce mentah tidak pernah ditulis
     * ke berkas (maupun ke log) supaya kebocoran berkas runtime tidak
     * menghasilkan nilai yang bisa dipakai ulang.
     */
    public static function key(string $nonce): string
    {
        return hash('sha256', $nonce);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function prune(array $data, int $now, int $ttl): array
    {
        $kept = [];
        foreach ($data as $key => $timestamp) {
            if (!is_string($key) || !is_int($timestamp)) {
                continue; // bentuk tak dikenal (mis. berkas hasil tulis lama) → jangan disimpan
            }
            if (abs($now - $timestamp) <= $ttl) {
                $kept[$key] = $timestamp;
            }
        }

        return $kept;
    }

    private function store(): JsonStore
    {
        return new JsonStore($this->path);
    }
}
