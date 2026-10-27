<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use Throwable;

/**
 * Resolusi **tarif per server** untuk meteran & penagihan.
 *
 * Satu-satunya tempat yang memetakan `server_id` → baris registry server →
 * tarif efektif (`Pricing::rates($server)` / `Pricing::backupMonthlyRate($server)`).
 * Tarif global `BILLING_RATE_*` tetap menjadi fallback: server tidak ditemukan,
 * registry tak terbaca, atau baris tanpa `rates` ⇒ perilaku lama persis.
 *
 * Cache **per instance** (bukan statik): satu instance hidup selama satu
 * operasi (satu tick meteran / satu render) dan tidak pernah bertahan lintas
 * request pada worker Webman persistent. Nilai cache hanya baris server
 * (`?array`) — **tidak pernah** token/PEM/base_url dikembalikan atau disimpan
 * ke tempat lain.
 *
 * Kelas ini tidak menyimpan state global dan tidak menulis apa pun; aman
 * dipakai sebagai dependensi opsional di `UsageMeter`, `Invoicer`,
 * `BillingGate`, dan controller.
 */
final class ServerRates
{
    /** Cache per instance: serverId => baris server (`null` = tidak ditemukan). */
    private array $servers = [];

    private ?ServerStore $store;

    /**
     * `$store` opsional aditif (seam uji path temp). `null` ⇒ `ServerStore`
     * default dibuat lazy saat tarif pertama benar-benar dibutuhkan.
     */
    public function __construct(?ServerStore $store = null)
    {
        $this->store = $store;
    }

    /**
     * Tarif CPU/RAM untuk satu server; id kosong/tidak dikenal ⇒ tarif global.
     *
     * @return array{cpu:float,ram:float}
     */
    public function ratesFor(string $serverId): array
    {
        return Pricing::rates($this->server($serverId));
    }

    /**
     * Tarif backup per GB per bulan untuk satu server; id kosong/tidak dikenal
     * ⇒ tarif global.
     */
    public function backupMonthlyFor(string $serverId): float
    {
        return Pricing::backupMonthlyRate($this->server($serverId));
    }

    /**
     * Tarif lengkap untuk app (server diturunkan dari `server_id` app):
     * `cpu`/`ram` per jam + `backup` per GB per bulan.
     *
     * @param array<string,mixed> $app dokumen app (`server_id` opsional)
     * @return array{cpu:float,ram:float,backup:float}
     */
    public function forApp(array $app): array
    {
        $serverId = AppStore::serverIdOf($app);

        return $this->ratesFor($serverId) + ['backup' => $this->backupMonthlyFor($serverId)];
    }

    /**
     * Baris server (cache per instance). Registry tak terbaca ⇒ `null` supaya
     * tarif global dipakai dan operasi uang tidak gagal karena store rusak.
     *
     * @return array<string,mixed>|null
     */
    private function server(string $serverId): ?array
    {
        $id = trim($serverId);
        if ($id === '') {
            return null;
        }

        if (array_key_exists($id, $this->servers)) {
            return $this->servers[$id];
        }

        try {
            $row = ($this->store ??= new ServerStore())->find($id);
        } catch (Throwable) {
            $row = null;
        }

        return $this->servers[$id] = is_array($row) ? $row : null;
    }
}
