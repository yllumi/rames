<?php
declare(strict_types=1);

namespace app\library\Agent;

use Closure;
use app\library\Storage\JsonStore;

/**
 * Pemutus sirkuit ringan untuk pemanggilan **baca-berkala** ke server agent
 * (monitoring). Tanpanya, satu agent mati menahan worker Webman selama connect
 * timeout (5 s) per panggilan, di setiap polling, untuk setiap user.
 *
 * Setelah satu kegagalan, server dianggap "dingin" selama `cooldown` detik:
 * pemanggil langsung memakai pesan galat terakhir tanpa menyentuh jaringan.
 * Status disimpan di berkas JSON (`JsonStore`, atomik + `flock`) karena worker
 * Webman banyak dan persistent — memori proses tidak dibagi. Hanya id server,
 * waktu, dan pesan yang **sudah disanitasi pemanggil** yang disimpan (tanpa
 * token/PEM).
 *
 * Jangan dipakai untuk operasi mutasi (deploy/job): di sana kegagalan harus
 * langsung terlihat, bukan ditahan cooldown.
 */
final class ServerHealthGate
{
    public const DEFAULT_COOLDOWN = 20;
    private const MAX_MESSAGE = 300;

    private readonly int $cooldown;

    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly string $path,
        int $cooldown = self::DEFAULT_COOLDOWN,
        ?callable $now = null,
    ) {
        $this->cooldown = max(1, $cooldown);
        $this->clock = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
    }

    public static function default(): self
    {
        return new self(runtime_path('agent-health.json'));
    }

    /**
     * Pesan galat terakhir bila server masih dalam masa dingin, selain itu `null`.
     */
    public function coolingDown(string $serverId): ?string
    {
        $entry = (new JsonStore($this->path))->read()[$serverId] ?? null;
        if (!is_array($entry) || !is_int($entry['at'] ?? null)) {
            return null;
        }
        if (abs(($this->clock)() - $entry['at']) >= $this->cooldown) {
            return null;
        }
        $message = is_string($entry['error'] ?? null) ? $entry['error'] : '';

        return $message === '' ? 'Server agent tidak dapat dihubungi.' : $message;
    }

    /** @param string $message pesan yang SUDAH disanitasi pemanggil */
    public function recordFailure(string $serverId, string $message): void
    {
        $now = ($this->clock)();
        $ttl = $this->cooldown;
        $message = substr($message, 0, self::MAX_MESSAGE);

        (new JsonStore($this->path))->update(
            static function (array &$data) use ($serverId, $now, $ttl, $message): void {
                foreach ($data as $id => $entry) {
                    if (!is_array($entry) || !is_int($entry['at'] ?? null) || abs($now - $entry['at']) >= $ttl * 10) {
                        unset($data[$id]);
                    }
                }
                $data[$serverId] = ['at' => $now, 'error' => $message];
            }
        );
    }

    public function recordSuccess(string $serverId): void
    {
        $store = new JsonStore($this->path);
        if (!array_key_exists($serverId, $store->read())) {
            return; // jalur normal: tanpa tulis cakram
        }
        $store->update(static function (array &$data) use ($serverId): void {
            unset($data[$serverId]);
        });
    }
}
