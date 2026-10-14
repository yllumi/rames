<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteStore;

/**
 * Flag seleksi backup berkala **per volume** (opt-in), disimpan pada store
 * logis `backup` — koleksi `volumes` di basis data SQLite
 * (`config('deploy.sqlite_file')`, dahulu `database/backup.json`), agar skema
 * store `apps` tidak berubah (PLAN_VOLUME_BACKUP.md).
 *
 * Bentuk data (per volume):
 *   {"scheduled":bool,"updated_at":"<ISO>","updated_by":"<userId>","server_id":"<id>"?}
 *
 * **Dimensi server (multi-server F4, plan §4.2/§4.3)**: nama volume bisa sama di
 * dua server ⇒ kunci seleksi di-scope server lewat
 * {@see BackupRegistry::scopeKey()} (server lokal tanpa prefiks — data lama tetap
 * valid), dan `server_id` disimpan di dokumen entri (tanpa perubahan skema).
 *
 * Default **OFF** (opt-in): volume tanpa entri / tanpa kunci `scheduled`
 * dianggap **tidak** terjadwal, sehingga volume baru harus dinyalakan secara
 * eksplisit. Pengecualian: volume yang **sudah pernah punya snapshot restic**
 * di-backfill otomatis ke ON (lihat `backfill()`) agar backup yang sudah ada
 * tidak berhenti terjadwal tanpa disengaja.
 *
 * Tanpa cache lintas-request (worker Webman persistent): isi store dimemoize
 * **per instance** saja (instance dibuat per request/run), bukan di properti
 * global/controller. Mutasi lewat `SqliteStore::update()` (satu transaksi).
 * Instance dengan `$path` di konstruktor agar path dapat di-override saat tes
 * (pola `UserStore`).
 */
final class BackupSelection
{
    private SqliteStore $store;

    /**
     * Memo isi store — dibaca sekali per instance lalu dipakai ulang.
     *
     * `isScheduled()` dipanggil **per baris volume** (mis. `withScheduled()`
     * atau run berkala), sehingga tanpa memo store dibaca N kali per request.
     * Instance dibuat per request/run (`new BackupSelection()`), jadi memo tidak
     * pernah bertahan lintas-request pada worker Webman persistent.
     *
     * `null` = belum dibaca; `[]` (store kosong) tetap memo valid.
     *
     * @var array<string,mixed>|null
     */
    private ?array $memo = null;

    /**
     * @param string|null $path path berkas .sqlite store `backup`;
     *                          null = {@see self::defaultPath()}
     *                          (`config('deploy.sqlite_file')`)
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
     * Path default berkas basis data .sqlite: `config('deploy.sqlite_file')`.
     *
     * Nama dipertahankan agar pemakai lama (`VolumeBackupService`, tes) tidak
     * berubah; nilainya kini berkas DB, bukan `database/backup.json`.
     */
    public static function defaultPath(): string
    {
        return (string) config('deploy.sqlite_file', base_path() . '/database/rames.sqlite');
    }

    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * Apakah volume ikut run backup berkala.
     *
     * Entri absen / kunci `scheduled` absen ⇒ **false** (default OFF/opt-in).
     *
     * @param string $serverId server pemilik volume (`''`/`local` = server ini)
     */
    public function isScheduled(string $name, string $serverId = ''): bool
    {
        $data = $this->data();
        $entry = $data['volumes'][BackupRegistry::scopeKey($serverId, $name)] ?? null;
        if (!is_array($entry) || !array_key_exists('scheduled', $entry)) {
            return false;
        }

        return (bool) $entry['scheduled'];
    }

    /**
     * Isi store seleksi (koleksi `volumes`) — dibaca sekali lalu dimemoize per
     * instance.
     *
     * @return array<string,mixed>
     */
    private function data(): array
    {
        return $this->memo ??= $this->store->read();
    }

    /**
     * Set flag jadwal sebuah volume (nama divalidasi fail-fast).
     *
     * @param string $actorId  userId pelaku (untuk jejak audit ringan; boleh kosong)
     * @param string $serverId server pemilik volume (`''`/`local` = server ini)
     */
    public function setScheduled(string $name, bool $scheduled, string $actorId = '', string $serverId = ''): void
    {
        VolumeStateGuard::assertVolumeName($name);
        $serverId = trim($serverId) === '' ? ServerStore::LOCAL_ID : trim($serverId);
        $key = BackupRegistry::scopeKey($serverId, $name);

        $this->store->update(function (array &$data) use ($key, $scheduled, $actorId, $serverId): void {
            if (!isset($data['version'])) {
                $data['version'] = 1;
            }
            if (!isset($data['volumes']) || !is_array($data['volumes'])) {
                $data['volumes'] = [];
            }
            $data['volumes'][$key] = [
                'scheduled' => $scheduled,
                'updated_at' => date('c'),
                'updated_by' => $actorId,
                'server_id' => $serverId,
            ];
        });

        // Segarkan memo agar `isScheduled()`/`explicit()` pasca-mutasi membaca
        // keadaan terbaru tanpa perlu instance baru (bukan state lintas-request).
        $this->memo = $this->store->read();
    }

    /**
     * Backfill: tandai volume yang **sudah pernah punya snapshot restic** agar
     * tetap terjadwal meski default kini OFF (opt-in). Dipanggil setelah
     * enumerasi snapshot (refresh catalog / akhir run backup).
     *
     * Untuk setiap nama yang **belum punya entri eksplisit**, tulis
     * `{"scheduled":true,"updated_at":"<ISO>","updated_by":"system"}`.
     * Entri eksplisit (ON maupun OFF) **tidak** ditimpa ⇒ idempotent. Semua nama
     * ditulis dalam **satu** `SqliteStore::update`; nama divalidasi fail-fast
     * SEBELUM menulis apa pun.
     *
     * @param array<int,string> $volumeNames
     */
    public function backfill(array $volumeNames): void
    {
        $names = [];
        foreach ($volumeNames as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            VolumeStateGuard::assertVolumeName($name);
            $names[$name] = true;
        }
        if ($names === []) {
            return;
        }

        $this->store->update(function (array &$data) use ($names): void {
            if (!isset($data['version'])) {
                $data['version'] = 1;
            }
            if (!isset($data['volumes']) || !is_array($data['volumes'])) {
                $data['volumes'] = [];
            }
            foreach (array_keys($names) as $name) {
                // Backfill jalur lokal: kunci = nama volume apa adanya (server ini).
                $existing = $data['volumes'][$name] ?? null;
                if (is_array($existing) && array_key_exists('scheduled', $existing)) {
                    continue; // hormati entri eksplisit (ON/OFF) — idempotent
                }
                $data['volumes'][$name] = [
                    'scheduled' => true,
                    'updated_at' => date('c'),
                    'updated_by' => 'system',
                    'server_id' => ServerStore::LOCAL_ID,
                ];
            }
        });

        // Segarkan memo agar pembacaan pasca-backfill pada instance yang sama
        // sudah benar (bukan state lintas-request).
        $this->memo = $this->store->read();
    }

    /**
     * Entri **eksplisit** saja (yang pernah ditulis lewat UI), untuk memulihkan
     * status toggle di frontend — volume default (tanpa entri) tidak muncul.
     *
     * Kunci hasil = **nama volume** (bukan kunci ber-`server_id`), sesuai kontrak
     * JSON UI lama. `$serverId` membatasi ke satu server (`''`/`local` = server
     * ini, perilaku lama); entri server lain tidak ikut agar toggle satu server
     * tidak mengubah baris server lain.
     *
     * @return array<string,bool>
     */
    public function explicit(string $serverId = ''): array
    {
        $wanted = trim($serverId) === '' ? ServerStore::LOCAL_ID : trim($serverId);

        $data = $this->data();
        $out = [];
        foreach ((array) ($data['volumes'] ?? []) as $key => $entry) {
            if (!is_array($entry) || !array_key_exists('scheduled', $entry)) {
                continue;
            }
            $key = (string) $key;
            if (BackupRegistry::serverOfKey($key) !== $wanted) {
                continue;
            }
            $out[BackupRegistry::volumeOfKey($key)] = (bool) $entry['scheduled'];
        }

        return $out;
    }

    /**
     * Nama volume yang **terjadwal (ON)** dikelompokkan per server — dipakai
     * pemicu berkala (`cli/backup.php run` tanpa `serverId`) supaya volume milik
     * server agent juga ter-backup terjadwal, masing-masing dikirim ke **server
     * pemiliknya** (plan §4.3: satu clock di control plane, pekerjaan di server).
     *
     * Kunci hasil = id server (`local` untuk server ini); nilainya nama volume
     * unik (terurut sesuai penyimpanan). Entri tanpa kunci `scheduled` atau
     * bernilai OFF **tidak** ikut (default OFF/opt-in, sama dengan
     * {@see self::isScheduled()}).
     *
     * @return array<string,array<int,string>>
     */
    public function scheduledByServer(): array
    {
        $data = $this->data();

        /** @var array<string,array<string,true>> $grouped */
        $grouped = [];
        foreach ((array) ($data['volumes'] ?? []) as $key => $entry) {
            if (!is_array($entry) || !array_key_exists('scheduled', $entry) || (bool) $entry['scheduled'] !== true) {
                continue;
            }
            $key = (string) $key;
            $name = BackupRegistry::volumeOfKey($key);
            if ($name === '') {
                continue;
            }
            $grouped[BackupRegistry::serverOfKey($key)][$name] = true;
        }

        $out = [];
        foreach ($grouped as $serverId => $names) {
            $out[$serverId] = array_keys($names);
        }

        return $out;
    }

    /**
     * Entri seleksi **semua server** (kunci apa adanya) — dipakai `backfill()`.
     *
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return (array) ($this->data()['volumes'] ?? []);
    }
}
