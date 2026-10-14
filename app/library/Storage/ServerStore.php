<?php
declare(strict_types=1);

namespace app\library\Storage;

use RuntimeException;

/**
 * Registry server — store logis `servers` di basis data SQLite
 * (`config('deploy.sqlite_file')`). Fondasi multi-server (fase F0): dashboard
 * (control plane) menyimpan daftar server yang dikelolanya, termasuk dirinya
 * sendiri sebagai server lokal.
 *
 * API publik mengikuti gaya {@see AppStore}: `path()`, `all()`, `find()`,
 * `findByName()`, `nameExists()`, `create()`, `update()`, `delete()`.
 * `$filePath` di konstruktor = **berkas .sqlite** agar path dapat di-override
 * saat tes (pola sama dengan `AppStore`/`UserStore`).
 *
 * Baris server lokal (`LOCAL_ID`) dibuat oleh migrasi skema v2
 * ({@see SchemaMigrations}) dan tidak boleh dihapus/diubah identitasnya —
 * ia adalah "server ini sendiri".
 *
 * Pemeriksaan hak akses TIDAK dilakukan di sini (nanti lewat lapisan Auth).
 */
final class ServerStore
{
    /** Id server lokal (diri sendiri) — baris seed dibuat migrasi skema v2. */
    public const LOCAL_ID = 'local';

    private SqliteStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new SqliteStore('servers', SchemaMigrations::storeDefinitions()['servers'], $filePath);
    }

    /**
     * Path berkas basis data .sqlite.
     */
    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * @return array<int,array>
     */
    public function all(): array
    {
        return $this->store->read();
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $server) {
            if ((string) ($server['id'] ?? '') === $id) {
                return $server;
            }
        }
        return null;
    }

    public function findByName(string $name): ?array
    {
        foreach ($this->all() as $server) {
            if (($server['name'] ?? '') === $name) {
                return $server;
            }
        }
        return null;
    }

    public function nameExists(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    /**
     * Tambah server baru. Nama wajib unik (global).
     *
     * @param array $server minimal `name`; default: `id` acak, `kind='agent'`,
     *                      `enabled=true`, `created_at`/`updated_at` = sekarang
     */
    public function create(array $server): array
    {
        $server['id'] = (string) ($server['id'] ?? bin2hex(random_bytes(8)));
        $server['name'] = (string) ($server['name'] ?? '');
        $server['kind'] = (string) ($server['kind'] ?? 'agent');
        $server['enabled'] = array_key_exists('enabled', $server) ? (bool) $server['enabled'] : true;
        $server['created_at'] = (string) ($server['created_at'] ?? date('c'));
        $server['updated_at'] = date('c');

        $this->store->update(function (array &$data) use ($server): void {
            foreach ($data as $existing) {
                if (($existing['name'] ?? '') === $server['name']) {
                    throw new RuntimeException("Nama server \"{$server['name']}\" sudah dipakai.");
                }
            }
            $data[] = $server;
        });

        return $server;
    }

    /**
     * Update server by id; mutator(array &$server): void.
     *
     * Server lokal (`LOCAL_ID`) tidak boleh diubah identitasnya: `id` dan
     * `kind` dipaksa kembali ke `local` setelah mutator (tidak melempar).
     *
     * @throws RuntimeException bila id tidak ditemukan
     */
    public function update(string $id, callable $mutator): array
    {
        $updated = null;
        $this->store->update(function (array &$data) use ($id, $mutator, &$updated): void {
            foreach ($data as &$server) {
                if ((string) ($server['id'] ?? '') === $id) {
                    $mutator($server);
                    if ($id === self::LOCAL_ID) {
                        // Server lokal = diri sendiri: identitas dipaksa kembali.
                        $server['id'] = self::LOCAL_ID;
                        $server['kind'] = 'local';
                    }
                    $server['updated_at'] = date('c');
                    $updated = $server;
                    unset($server);
                    return;
                }
            }
            unset($server);
            throw new RuntimeException("Server tidak ditemukan: {$id}");
        });

        return $updated ?? [];
    }

    /**
     * Hapus server. Server lokal tidak bisa dihapus (dirinya sendiri).
     * Id yang tidak ada = no-op (pola `AppStore::delete()`).
     *
     * @throws RuntimeException bila `$id` adalah server lokal
     */
    public function delete(string $id): void
    {
        if ($id === self::LOCAL_ID) {
            throw new RuntimeException('Server lokal tidak bisa dihapus: ia adalah server ini sendiri.');
        }

        $this->store->update(function (array &$data) use ($id): void {
            $data = array_values(array_filter(
                $data,
                static fn (array $s): bool => (string) ($s['id'] ?? '') !== $id
            ));
        });
    }

    /**
     * Baris server lokal. Self-heal bila barisnya hilang (mis. snapshot lama):
     * nilai seed ditulis ulang, **tidak** melempar.
     */
    public function local(): array
    {
        $server = $this->find(self::LOCAL_ID);
        if ($server !== null) {
            return $server;
        }

        $seed = self::localSeed();
        $this->store->update(function (array &$data) use ($seed): void {
            foreach ($data as $existing) {
                if ((string) ($existing['id'] ?? '') === self::LOCAL_ID) {
                    return; // sudah muncul (balapan) — biarkan
                }
            }
            array_unshift($data, $seed);
        });

        return $this->find(self::LOCAL_ID) ?? $seed;
    }

    /**
     * Dokumen seed server lokal — **wajib sama** dengan seed migrasi skema v2.
     *
     * @return array<string,mixed>
     */
    private static function localSeed(): array
    {
        return [
            'id' => self::LOCAL_ID,
            'name' => 'local',
            'kind' => 'local',
            'enabled' => true,
            'domain' => '',
        ];
    }
}
