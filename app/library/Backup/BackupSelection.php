<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteStore;

/**
 * Flag seleksi backup berkala **per volume** (opt-in), disimpan pada store
 * logis `backup` — koleksi `volumes` di basis data SQLite
 * (`config('deploy.sqlite_file')`, dahulu `database/backup.json`), agar skema
 * store `apps` tidak berubah (PLAN_VOLUME_BACKUP.md).
 *
 * Bentuk data (per volume):
 *   {"scheduled":bool,"updated_at":"<ISO>","updated_by":"<userId>"}
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
     */
    public function isScheduled(string $name): bool
    {
        $data = $this->data();
        $entry = $data['volumes'][$name] ?? null;
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
     * @param string $actorId userId pelaku (untuk jejak audit ringan; boleh kosong)
     */
    public function setScheduled(string $name, bool $scheduled, string $actorId = ''): void
    {
        VolumeStateGuard::assertVolumeName($name);

        $this->store->update(function (array &$data) use ($name, $scheduled, $actorId): void {
            if (!isset($data['version'])) {
                $data['version'] = 1;
            }
            if (!isset($data['volumes']) || !is_array($data['volumes'])) {
                $data['volumes'] = [];
            }
            $data['volumes'][$name] = [
                'scheduled' => $scheduled,
                'updated_at' => date('c'),
                'updated_by' => $actorId,
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
                $existing = $data['volumes'][$name] ?? null;
                if (is_array($existing) && array_key_exists('scheduled', $existing)) {
                    continue; // hormati entri eksplisit (ON/OFF) — idempotent
                }
                $data['volumes'][$name] = [
                    'scheduled' => true,
                    'updated_at' => date('c'),
                    'updated_by' => 'system',
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
     * @return array<string,bool>
     */
    public function explicit(): array
    {
        $data = $this->data();
        $out = [];
        foreach ((array) ($data['volumes'] ?? []) as $name => $entry) {
            if (is_array($entry) && array_key_exists('scheduled', $entry)) {
                $out[(string) $name] = (bool) $entry['scheduled'];
            }
        }

        return $out;
    }
}
