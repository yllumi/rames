<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Storage\JsonStore;

/**
 * Cache baris ringkasan backup volume (PLAN_VOLUME_BACKUP.md).
 *
 * Ditulis HANYA saat run backup selesai atau saat tombol "Segarkan"
 * (`POST /backups/refresh`) ditekan — bukan saat polling `GET /api/backups/status`.
 * Dengan begitu halaman tidak memanggil Docker Engine/restic tiap poll.
 *
 * Bentuk berkas (`runtime/backup/catalog.json`, gitignored):
 *   {"cached_at":"<ISO>|null","volumes":[ <baris overview> ],
 *    "agents_at":{"<serverId>":"<unix>"}}
 *
 * `agents_at` = waktu sinkron terakhir baris volume **server agent** per server
 * (dipakai `BackupStatusController::status()` untuk TTL; bukan data user). Baris
 * server agent hanya ditulis lewat {@see self::writeAgentRows()} — penulisan
 * jalur lokal (`VolumeBackupService`) **mempertahankan** baris agent yang sudah
 * ada agar volume agent tidak hilang dari `/backups` setiap run lokal.
 *
 * Baris = **sama persis** dengan keluaran `VolumeBackupService::overview()`:
 * `name, project, app_id, app_name, orphaned, strategy, container_state,
 * last_run_at, last_ok, last_message, snapshots`. Sebagai pertahanan berlapis,
 * baris disanitasi ke daftar-putih kunci ini — kunci di luar daftar dibuang
 * (baris ini memang metadata, bukan data sensitif; `BackupReport` tetap pola
 * redaksi untuk status/riwayat).
 *
 * Stateless; mutasi lewat `JsonStore`. Instance dengan `$path` di konstruktor
 * agar path dapat di-override saat tes.
 */
final class BackupCatalog
{
    /** Kunci baris yang dipertahankan (selaras `VolumeBackupService::overview()`). */
    private const ROW_KEYS = [
        'name', 'project', 'app_id', 'app_name', 'orphaned', 'strategy',
        'container_state', 'last_run_at', 'last_ok', 'last_message', 'snapshots',
        // Dimensi server (F4): baris volume server agent ikut cache control plane;
        // tanpa kunci ini dua volume bernama sama di dua server tak bisa dibedakan.
        'server_id',
    ];

    private JsonStore $store;

    /**
     * @param string|null $path path berkas cache; null = `runtime/backup/catalog.json`
     */
    public function __construct(?string $path = null)
    {
        $this->store = new JsonStore($path ?? runtime_path('backup/catalog.json'));
    }

    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * Baca cache; berkas belum ada ⇒ `volumes = []`, `cached_at = null`.
     *
     * @return array{volumes:array<int,array<string,mixed>>,cached_at:?string}
     */
    public function read(): array
    {
        $data = $this->store->read();

        $volumes = [];
        foreach ((array) ($data['volumes'] ?? []) as $row) {
            if (is_array($row)) {
                $volumes[] = $this->sanitizeRow($row);
            }
        }

        $cachedAt = $data['cached_at'] ?? null;

        return [
            'volumes' => $volumes,
            'cached_at' => is_string($cachedAt) && $cachedAt !== '' ? $cachedAt : null,
        ];
    }

    /**
     * Tulis cache dari baris live (menyegel `cached_at = date('c')`).
     *
     * `$keepCachedAgentRows = true` dipakai penulisan **jalur lokal** (Refresh &
     * akhir run): baris milik server agent yang sudah ada di cache
     * dipertahankan bila tidak ikut dikirim, karena Engine host ini memang tidak
     * bisa menghasilkannya. Baris dari server yang sama yang ikut dikirim
     * (mis. hasil "Segarkan" yang menyertakan baris agent) **menang**.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function write(array $rows, bool $keepCachedAgentRows = false): void
    {
        $clean = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean[] = $this->sanitizeRow($row);
            }
        }

        $this->mutate(function (array &$data) use ($clean, $keepCachedAgentRows): void {
            $kept = [];
            if ($keepCachedAgentRows) {
                $incoming = [];
                foreach ($clean as $row) {
                    $incoming[self::rowKey($row)] = true;
                }

                foreach ((array) ($data['volumes'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $serverId = self::rowServerId($row);
                    if ($serverId === '' || $serverId === 'local') {
                        continue; // baris lokal digantikan data live di atas
                    }
                    $row = $this->sanitizeRow($row);
                    if (isset($incoming[self::rowKey($row)])) {
                        continue; // data live untuk server+volume itu menang
                    }
                    $kept[] = $row;
                }
            }

            if (!$keepCachedAgentRows) {
                // Penggantian penuh (tanpa baris agent) membuang penanda sinkron
                // agar baris agent tidak dianggap "baru disinkron" padahal hilang.
                unset($data['agents_at']);
            }
            $data['cached_at'] = date('c');
            $data['volumes'] = array_merge($clean, $kept);
        });
    }

    /**
     * Waktu sinkron terakhir baris agent per server (`unix`), dari cache.
     *
     * @return array<string,int>
     */
    public function agentSyncTimes(): array
    {
        $data = $this->readState();

        $out = [];
        foreach ((array) ($data['agents_at'] ?? []) as $serverId => $at) {
            $serverId = trim((string) $serverId);
            if ($serverId === '' || !is_numeric($at) || (int) $at <= 0) {
                continue;
            }
            $out[$serverId] = (int) $at;
        }

        return $out;
    }

    /**
     * Ganti **seluruh** baris satu server agent di cache (baris server lain
     * dipertahankan) dan segel waktu sinkronnya. Dipakai sinkronisasi TTL
     * `BackupStatusController::status()`: satu server diambil live, sisanya memakai
     * data cache.
     *
     * @param array<int,array<string,mixed>> $rows baris milik server itu
     */
    public function writeAgentRows(string $serverId, array $rows, int $at = 0): void
    {
        $serverId = trim($serverId);
        if ($serverId === '') {
            return;
        }
        $at = $at > 0 ? $at : time();

        $incoming = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['server_id'] = $serverId;
            $incoming[] = $this->sanitizeRow($row);
        }

        $this->mutate(function (array &$data) use ($serverId, $incoming, $at): void {
            $kept = [];
            foreach ((array) ($data['volumes'] ?? []) as $row) {
                if (!is_array($row) || self::rowServerId($row) === $serverId) {
                    continue;
                }
                $kept[] = $this->sanitizeRow($row);
            }

            $data['volumes'] = array_merge($kept, $incoming);
            $agentsAt = is_array($data['agents_at'] ?? null) ? $data['agents_at'] : [];
            $agentsAt[$serverId] = $at;
            $data['agents_at'] = $agentsAt;
        });
    }

    /**
     * Catat percobaan sinkron satu server **tanpa** mengubah barisnya (mis.
     * server tak terjangkau) supaya polling tidak menghajar server itu terus.
     */
    public function touchAgentSync(string $serverId, int $at = 0): void
    {
        $serverId = trim($serverId);
        if ($serverId === '') {
            return;
        }
        $at = $at > 0 ? $at : time();

        $this->mutate(function (array &$data) use ($serverId, $at): void {
            $agentsAt = is_array($data['agents_at'] ?? null) ? $data['agents_at'] : [];
            $agentsAt[$serverId] = $at;
            $data['agents_at'] = $agentsAt;
        });
    }

    /**
     * Baca–ubah–tulis **terserialisasi** (lock file terpisah) lalu tulis via
     * `JsonStore::write()` (tmp + rename). Dengan begitu dua poll paralel tidak
     * saling menimpa baris server yang baru disinkronkan, dan pembaca tidak
     * pernah melihat JSON separuh jadi.
     *
     * Kegagalan lock (mis. filesystem read-only) jatuh ke tulisan tanpa lock —
     * cache bukan sumber kebenaran.
     *
     * @param callable(array<string,mixed>&):void $mutator
     */
    private function mutate(callable $mutator): void
    {
        $path = $this->store->path() . '.lock';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fp = @fopen($path, 'c');
        if ($fp === false) {
            $data = $this->readState();
            $mutator($data);
            $this->store->write($data);
            return;
        }

        try {
            @flock($fp, LOCK_EX);
            $data = $this->readState();
            $mutator($data);
            $this->store->write($data);
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Isi mentah cache (termasuk `agents_at`) — toleran terhadap berkas
     * rusak/absen (cache bukan sumber kebenaran).
     *
     * @return array<string,mixed>
     */
    private function readState(): array
    {
        try {
            return $this->store->read();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $row */
    private static function rowServerId(array $row): string
    {
        return isset($row['server_id']) && is_scalar($row['server_id'])
            ? trim((string) $row['server_id'])
            : '';
    }

    /** @param array<string,mixed> $row */
    private static function rowKey(array $row): string
    {
        return self::rowServerId($row) . "\0" . (string) ($row['name'] ?? '');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function sanitizeRow(array $row): array
    {
        $clean = [];
        foreach (self::ROW_KEYS as $key) {
            if (array_key_exists($key, $row)) {
                $clean[$key] = $row[$key];
            }
        }

        return $clean;
    }
}
