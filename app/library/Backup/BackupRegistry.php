<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteStore;

/**
 * Riwayat volume yang **pernah ter-backup** (PLAN/SPECS §8h — restore volume
 * yang sudah dihapus).
 *
 * Saat sebuah app dihapus total (termasuk volumenya), snapshot restic tetap ada
 * di S3 dan hanya bisa dipulihkan bila kita masih tahu **nama volume, project,
 * app, dan strategi**-nya. `BackupSelection` menyimpan flag jadwal; kelas ini
 * menyimpan riwayat permanen tersebut.
 *
 * Berbagi store `backup` dengan seleksi (koleksi `volumes` vs `registry`) agar
 * tidak menambah skema store `apps` — tetapi **hanya** menyentuh koleksi
 * `registry`, sedangkan `BackupSelection` hanya menyentuh `volumes`. Keduanya
 * memakai `SqliteStore` (satu transaksi) sehingga mutasi keduanya tidak saling
 * merusak.
 *
 * Bentuk entri registry:
 *   {"<kunci>":{
 *        "project":string,"app_id":?string,"app_name":?string,"strategy":string,
 *        "server_id":string?,
 *        "first_backed_up_at":string,"last_backed_up_at":string,
 *        "last_snapshot":?string,"snapshots":int,"bytes":int}}
 *
 * **Dimensi server (multi-server F4, plan §4.2/§4.3)**: nama volume Docker bisa
 * sama di dua server, jadi kunci registry di-*scope* server lewat
 * {@see self::scopeKey()} — server lokal memakai nama volume apa adanya
 * (kompatibilitas penuh dengan data lama), server agent memakai
 * `"<server_id>::<volume>"`. Kolom turunan `server_id` disimpan **di dalam
 * dokumen JSON** entri (bukan kolom SQL), sehingga **tidak** ada perubahan skema
 * store `backup` yang perlu migrasi.
 *
 * `first_backed_up_at` dipertahankan sekali (lihat `upsertMany()`), `snapshots`
 * & prune dikelola `syncCounts()` (keputusan 3b: entri dihapus begitu snapshot
 * live habis — **kecuali** peta counts kosong, yang dianggap "tidak diketahui"
 * dan tidak mem-prune; lihat `syncCounts()`). `backfill()` menutup celah volume
 * yang ter-backup **sebelum** fitur ini ada (hanya tercatat saat run sukses),
 * dengan menyisipkan entri dari snapshot yang sudah ada tanpa menimpa entri
 * eksisting. Instance dengan `$path` di konstruktor agar path dapat di-override
 * saat tes (pola `BackupSelection`).
 */
final class BackupRegistry
{
    private SqliteStore $store;

    /**
     * Kunci registry untuk satu volume pada satu server.
     *
     * Server lokal (`''`/`local`) memakai nama volume apa adanya supaya seluruh
     * entri lama (dan `VolumeBackupService` jalur lokal) tetap valid tanpa
     * migrasi; server lain memakai prefiks id server.
     */
    public static function scopeKey(string $serverId, string $volume): string
    {
        $serverId = trim($serverId);
        $volume = trim($volume);

        return ($serverId === '' || $serverId === ServerStore::LOCAL_ID) ? $volume : $serverId . '::' . $volume;
    }

    /**
     * Nama volume dari kunci registry (kebalikan {@see self::scopeKey()}).
     */
    public static function volumeOfKey(string $key): string
    {
        $position = strpos($key, '::');

        return $position === false ? $key : substr($key, $position + 2);
    }

    /**
     * Id server dari kunci registry — `ServerStore::LOCAL_ID` bila tanpa prefiks.
     */
    public static function serverOfKey(string $key): string
    {
        $position = strpos($key, '::');

        return $position === false ? ServerStore::LOCAL_ID : substr($key, 0, $position);
    }

    /**
     * @param string|null $path path berkas .sqlite store `backup`; null =
     *                          {@see self::defaultPath()} (path default yang
     *                          sama dengan `BackupSelection`)
     */
    public function __construct(?string $path = null)
    {
        $this->store = new SqliteStore(
            'backup',
            SchemaMigrations::storeDefinitions()['backup'],
            $path ?? self::defaultPath()
        );
    }

    /**
     * Path default — **sama persis** dengan seleksi (satu store, dua koleksi).
     */
    public static function defaultPath(): string
    {
        return BackupSelection::defaultPath();
    }

    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * Seluruh entri registry (nama volume => entri).
     *
     * @return array<string,array<string,mixed>>
     */
    public function read(): array
    {
        $data = $this->store->read();
        $registry = is_array($data['registry'] ?? null) ? $data['registry'] : [];

        $out = [];
        foreach ($registry as $name => $entry) {
            if (is_array($entry)) {
                $out[(string) $name] = $entry;
            }
        }

        return $out;
    }

    /**
     * Catat/segarkan sekumpulan volume dalam **satu** `SqliteStore::update`.
     *
     * `first_backed_up_at` dipertahankan bila entri sudah ada; `last_backed_up_at`
     * selalu disegarkan (kecuali pemanggil menyuplai nilai eksplisit). Nilai yang
     * tidak disuplai mempertahankan nilai lama (agar `snapshots`/`bytes` tidak
     * ter-reset ke 0 oleh upsert).
     *
     * Nama volume divalidasi fail-fast **sebelum** menulis apa pun; kunci registry
     * di-scope ke `$serverId` ({@see self::scopeKey()}) dan `server_id` disimpan di
     * dokumen entri supaya laporan/arsip dapat dipisah per server.
     *
     * @param array<string,array<string,mixed>> $entries kunci = nama volume
     *        (entri boleh memuat `project`,`app_id`,`app_name`,`strategy`,
     *        `last_snapshot`,`bytes`,`snapshots`,`last_backed_up_at`)
     * @param string $serverId id server pemilik volume (`''`/`local` = server ini)
     */
    public function upsertMany(array $entries, string $serverId = ''): void
    {
        $serverId = trim($serverId) === '' ? ServerStore::LOCAL_ID : trim($serverId);

        $normalized = [];
        foreach ($entries as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = is_string($key) && $key !== '' ? $key : (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            VolumeStateGuard::assertVolumeName($name);
            $normalized[self::scopeKey($serverId, $name)] = $entry;
        }
        if ($normalized === []) {
            return;
        }

        $this->store->update(function (array &$data) use ($normalized, $serverId): void {
            if (!isset($data['version'])) {
                $data['version'] = 1;
            }
            $registry = is_array($data['registry'] ?? null) ? $data['registry'] : [];
            $now = date('c');

            foreach ($normalized as $key => $entry) {
                $existing = is_array($registry[$key] ?? null) ? $registry[$key] : [];
                $registry[$key] = [
                    'project' => (string) ($entry['project'] ?? ($existing['project'] ?? '')),
                    'app_id' => $entry['app_id'] ?? ($existing['app_id'] ?? null),
                    'app_name' => $entry['app_name'] ?? ($existing['app_name'] ?? null),
                    'strategy' => (string) ($entry['strategy'] ?? ($existing['strategy'] ?? '')),
                    'server_id' => (string) ($existing['server_id'] ?? $serverId),
                    'first_backed_up_at' => (string) ($existing['first_backed_up_at'] ?? $now),
                    'last_backed_up_at' => (string) ($entry['last_backed_up_at'] ?? $now),
                    'last_snapshot' => $entry['last_snapshot'] ?? ($existing['last_snapshot'] ?? null),
                    'snapshots' => (int) ($entry['snapshots'] ?? ($existing['snapshots'] ?? 0)),
                    'bytes' => (int) ($entry['bytes'] ?? ($existing['bytes'] ?? 0)),
                ];
            }

            $data['registry'] = $registry;
        });
    }

    /**
     * Backfill riwayat dari volume yang **sudah punya snapshot** (mis. snapshot
     * lama sebelum fitur registry ada) — pola sama dengan
     * `BackupSelection::backfill()`.
     *
     * Untuk setiap nama yang **belum ada** di `registry`, sisipkan entri awal
     * `{project, app_id, app_name, strategy, snapshots, first_backed_up_at,
     * last_backed_up_at, last_snapshot: null, bytes: 0}`. Entri yang sudah ada
     * **tidak** ditimpa ⇒ idempotent. Semua nama ditulis dalam **satu**
     * `SqliteStore::update`; hanya koleksi `registry` yang disentuh (koleksi
     * `volumes` milik seleksi dibiarkan utuh). Nama invalid **dibuang** (bukan fail-fast)
     * agar satu baris rusak tidak membatalkan backfill baris lain.
     *
     * @param array<string,array<string,mixed>> $entries kunci = nama volume;
     *        entri memuat `project`,`app_id`,`app_name`,`strategy`,`snapshots`,
     *        dan opsional `at` (timestamp ISO untuk `first_`/`last_backed_up_at`;
     *        null/kosong → waktu sekarang)
     */
    public function backfill(array $entries): void
    {
        $normalized = [];
        foreach ($entries as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = is_string($key) && $key !== '' ? $key : (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            try {
                VolumeStateGuard::assertVolumeName($name);
            } catch (\Throwable $e) {
                continue; // buang nama invalid; jangan gagalkan seluruh backfill
            }
            $normalized[$name] = $entry;
        }
        if ($normalized === []) {
            return;
        }

        $this->store->update(function (array &$data) use ($normalized): void {
            if (!isset($data['version'])) {
                $data['version'] = 1;
            }
            $registry = is_array($data['registry'] ?? null) ? $data['registry'] : [];
            $now = date('c');

            foreach ($normalized as $name => $entry) {
                if (is_array($registry[$name] ?? null)) {
                    continue; // idempotent: hormati entri yang sudah ada
                }
                $at = $entry['at'] ?? null;
                $at = is_string($at) && $at !== '' ? $at : $now;
                $registry[$name] = [
                    'project' => (string) ($entry['project'] ?? ''),
                    'app_id' => $entry['app_id'] ?? null,
                    'app_name' => $entry['app_name'] ?? null,
                    'strategy' => (string) ($entry['strategy'] ?? ''),
                    'server_id' => ServerStore::LOCAL_ID,
                    'first_backed_up_at' => $at,
                    'last_backed_up_at' => $at,
                    'last_snapshot' => null,
                    'snapshots' => (int) ($entry['snapshots'] ?? 0),
                    'bytes' => 0,
                ];
            }

            $data['registry'] = $registry;
        });
    }

    /**
     * Sinkronkan jumlah snapshot live + **prune** entri yang snapshot-nya habis
     * (keputusan 3b) dalam **satu** `SqliteStore::update`.
     *
     * Entri yang namanya tidak ada di `$countsByName` dianggap snapshot-nya `0`
     * → dibuang. Karena itu **hanya** prune bila jumlah snapshot benar-benar
     * diketahui — dan **hanya** entri milik server lokal: `$countsByName` dihitung
     * dari repo restic control plane, sehingga entri server agent (kunci
     * ber-`server_id`) tidak boleh dipangkas olehnya.
     *
     * **Peta kosong (`[]`) TIDAK memicu prune** (guard): repo restic yang
     * terjangkau tetapi kosong / salah bucket mengembalikan `[]`, yang tidak
     * dapat dibedakan dari "seluruh snapshot habis". Mem-prune berdasarkan itu
     * akan menghapus **seluruh** riwayat secara keliru, jadi `[]` diperlakukan
     * sebagai "tak ada data / tidak diketahui" → mutasi **tidak** dijalankan
     * sama sekali. Konsekuensi yang disengaja: bila repo benar-benar kosong
     * seluruhnya, entri tertinggal dengan `snapshots: 0` dan tanpa tombol
     * Restore — lebih aman daripada kehilangan riwayat; pembersihan dilakukan
     * manual lewat `remove()`.
     *
     * @param array<string,int> $countsByName
     */
    public function syncCounts(array $countsByName): void
    {
        $counts = [];
        foreach ($countsByName as $name => $count) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $counts[$name] = max(0, (int) $count);
        }

        // Guard riwayat (lihat docblock): peta kosong = "tidak diketahui" →
        // jangan sentuh registry sama sekali (bukan hanya jangan prune).
        if ($counts === []) {
            return;
        }

        $this->store->update(function (array &$data) use ($counts): void {
            if (!isset($data['version'])) {
                $data['version'] = 1;
            }
            $registry = is_array($data['registry'] ?? null) ? $data['registry'] : [];

            foreach ($registry as $name => $entry) {
                if (!is_array($entry)) {
                    unset($registry[$name]);
                    continue;
                }
                // Entri milik server lain (agent) tidak dipangkas: counts di sini
                // berasal dari repo restic server lokal.
                if (self::serverOfKey((string) $name) !== ServerStore::LOCAL_ID) {
                    continue;
                }
                $live = $counts[(string) $name] ?? 0;
                if ($live <= 0) {
                    unset($registry[$name]);
                    continue;
                }
                $entry['snapshots'] = $live;
                $registry[$name] = $entry;
            }

            $data['registry'] = $registry;
        });
    }

    /**
     * Hapus satu entri registry (mis. setelah restore arsip yang tidak lagi
     * relevan). Idempotent.
     *
     * @param string $name     nama volume
     * @param string $serverId server pemilik (`''`/`local` = server ini)
     */
    public function remove(string $name, string $serverId = ''): void
    {
        $key = self::scopeKey($serverId, trim($name));
        if (trim($name) === '') {
            return;
        }

        $this->store->update(function (array &$data) use ($key): void {
            if (!is_array($data['registry'] ?? null)) {
                return;
            }
            unset($data['registry'][$key]);
        });
    }
}
