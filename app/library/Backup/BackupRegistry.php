<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Storage\JsonStore;

/**
 * Riwayat volume yang **pernah ter-backup** (PLAN/SPECS §8h — restore volume
 * yang sudah dihapus).
 *
 * Saat sebuah app dihapus total (termasuk volumenya), snapshot restic tetap ada
 * di S3 dan hanya bisa dipulihkan bila kita masih tahu **nama volume, project,
 * app, dan strategi**-nya. `BackupSelection` menyimpan flag jadwal; kelas ini
 * menyimpan riwayat permanen tersebut.
 *
 * Berbagi berkas dengan seleksi (`database/backup.json`, gitignored) agar tidak
 * menambah skema `apps.json` — tetapi **hanya** menyentuh kunci `registry`,
 * sedangkan `BackupSelection` hanya menyentuh `volumes`. Keduanya memakai
 * `JsonStore` (atomic + `flock` + backup `.bak`) sehingga mutasi keduanya tidak
 * saling merusak.
 *
 * Bentuk berkas:
 *   {"version":1,
 *    "volumes":{…milik BackupSelection…},
 *    "registry":{"<nama>":{
 *        "project":string,"app_id":?string,"app_name":?string,"strategy":string,
 *        "first_backed_up_at":string,"last_backed_up_at":string,
 *        "last_snapshot":?string,"snapshots":int,"bytes":int}}}
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
    private JsonStore $store;

    /**
     * @param string|null $path path berkas registry; null = `database/backup.json`
     *                          (path default yang sama dengan `BackupSelection`)
     */
    public function __construct(?string $path = null)
    {
        $this->store = new JsonStore($path ?? self::defaultPath());
    }

    /**
     * Path default — **sama persis** dengan seleksi (satu berkas, dua kunci).
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
     * Catat/segarkan sekumpulan volume dalam **satu** `JsonStore::update`.
     *
     * `first_backed_up_at` dipertahankan bila entri sudah ada; `last_backed_up_at`
     * selalu disegarkan (kecuali pemanggil menyuplai nilai eksplisit). Nilai yang
     * tidak disuplai mempertahankan nilai lama (agar `snapshots`/`bytes` tidak
     * ter-reset ke 0 oleh upsert).
     *
     * Nama volume divalidasi fail-fast **sebelum** menulis apa pun.
     *
     * @param array<string,array<string,mixed>> $entries kunci = nama volume
     *        (entri boleh memuat `project`,`app_id`,`app_name`,`strategy`,
     *        `last_snapshot`,`bytes`,`snapshots`,`last_backed_up_at`)
     */
    public function upsertMany(array $entries): void
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
            VolumeStateGuard::assertVolumeName($name);
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
                $existing = is_array($registry[$name] ?? null) ? $registry[$name] : [];
                $registry[$name] = [
                    'project' => (string) ($entry['project'] ?? ($existing['project'] ?? '')),
                    'app_id' => $entry['app_id'] ?? ($existing['app_id'] ?? null),
                    'app_name' => $entry['app_name'] ?? ($existing['app_name'] ?? null),
                    'strategy' => (string) ($entry['strategy'] ?? ($existing['strategy'] ?? '')),
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
     * `JsonStore::update`; hanya kunci `registry` yang disentuh (kunci `volumes`
     * milik seleksi dibiarkan utuh). Nama invalid **dibuang** (bukan fail-fast)
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
     * (keputusan 3b) dalam **satu** `JsonStore::update`.
     *
     * Entri yang namanya tidak ada di `$countsByName` dianggap snapshot-nya `0`
     * → dibuang. Karena itu **hanya** prune bila jumlah snapshot benar-benar
     * diketahui.
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
     */
    public function remove(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $this->store->update(function (array &$data) use ($name): void {
            if (!is_array($data['registry'] ?? null)) {
                return;
            }
            unset($data['registry'][$name]);
        });
    }
}
