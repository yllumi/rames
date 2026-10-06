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
 *   {"cached_at":"<ISO>|null","volumes":[ <baris overview> ]}
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
     * @param array<int,array<string,mixed>> $rows
     */
    public function write(array $rows): void
    {
        $clean = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean[] = $this->sanitizeRow($row);
            }
        }

        $this->store->write([
            'cached_at' => date('c'),
            'volumes' => $clean,
        ]);
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
