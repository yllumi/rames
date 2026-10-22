<?php
declare(strict_types=1);

namespace app\library\Storage;

use RuntimeException;

/**
 * Penyimpanan **domain parking** — store logis `parked` (tabel fisik
 * `parked_domains`) di basis data SQLite (`config('deploy.sqlite_file')`).
 *
 * Domain parking adalah entitas **global, tidak terikat app**: daftar FQDN
 * yang dilayani halaman statis (`mode='html'`) atau dialihkan
 * (`mode='redirect'`). Isi HTML-nya sendiri disimpan sebagai berkas di
 * `database/parked/{id}/index.html` (lapisan lain) — store ini hanya metadata.
 *
 * API publik mengikuti gaya {@see AppStore}/{@see ServerStore}: `path()`,
 * `all()`, `find()`, `create()`, `update()`, `delete()` + pencarian khusus
 * (`byDomain()`, `domains()`). `$filePath` di konstruktor = **berkas .sqlite**
 * agar path dapat di-override saat tes.
 *
 * Bentuk entri (minimal):
 * `{id, domain, server_id, mode, created_at, updated_at}` — `mode` = `html`
 * atau `redirect` (divalidasi `ParkedDomainValidator`, bukan di sini).
 *
 * Keunikan `domain` **case-insensitive** ditegakkan di dalam transaksi tulis
 * (`SqliteStore::update()`), sehingga dua penulis paralel tidak bisa lolos.
 *
 * Pemeriksaan hak akses TIDAK dilakukan di sini (pola `AppStore`; penjagaan
 * route/ability ada di lapisan controller).
 */
final class ParkedDomainStore
{
    /** Prefiks id entri domain parkir (`pd_` + 12 hex). */
    public const ID_PREFIX = 'pd_';

    private SqliteStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new SqliteStore('parked', SchemaMigrations::storeDefinitions()['parked'], $filePath);
    }

    /**
     * Path berkas basis data .sqlite.
     */
    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * Seluruh entri, urut `domain` menaik (alfabetis, case-insensitive).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $rows = $this->store->read();
        usort(
            $rows,
            static function (array $a, array $b): int {
                $cmp = strcmp(
                    strtolower(trim((string) ($a['domain'] ?? ''))),
                    strtolower(trim((string) ($b['domain'] ?? '')))
                );

                return $cmp !== 0 ? $cmp : strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }
        );

        return $rows;
    }

    /**
     * Entri berdasarkan id (`pd_...`), atau null bila tidak ada.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->store->read() as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Entri dengan domain tertentu — pencocokan **case-insensitive**
     * (normalisasi domain dilakukan pemanggil/validator).
     *
     * @return array<string,mixed>|null
     */
    public function byDomain(string $domain): ?array
    {
        $needle = self::normalizeKey($domain);
        if ($needle === '') {
            return null;
        }

        foreach ($this->store->read() as $row) {
            if (self::normalizeKey((string) ($row['domain'] ?? '')) === $needle) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Daftar domain (string, urut seperti {@see self::all()}); entri tanpa
     * domain dilewati.
     *
     * @return array<int,string>
     */
    public function domains(): array
    {
        $domains = [];
        foreach ($this->all() as $row) {
            $domain = self::normalizeKey((string) ($row['domain'] ?? ''));
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Tambah entri baru.
     *
     * Default yang diisi: `id` (bila kosong) = `pd_` + 12 hex,
     * `server_id` = `local` (bila kosong), `created_at`/`updated_at` = sekarang.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed> entri final (dengan default)
     *
     * @throws RuntimeException bila `domain` sudah dipakai entri lain
     *                          (case-insensitive)
     */
    public function create(array $entry): array
    {
        $id = is_scalar($entry['id'] ?? null) ? trim((string) $entry['id']) : '';
        $entry['id'] = $id !== '' ? $id : self::ID_PREFIX . bin2hex(random_bytes(6));

        $serverId = is_scalar($entry['server_id'] ?? null) ? trim((string) $entry['server_id']) : '';
        $entry['server_id'] = $serverId !== '' ? $serverId : ServerStore::LOCAL_ID;

        $createdAt = is_scalar($entry['created_at'] ?? null) ? trim((string) $entry['created_at']) : '';
        $entry['created_at'] = $createdAt !== '' ? $createdAt : date('c');
        $entry['updated_at'] = date('c');

        $domain = self::normalizeKey((string) ($entry['domain'] ?? ''));

        $this->store->update(function (array &$data) use ($entry, $domain): void {
            foreach ($data as $existing) {
                if ($domain !== '' && self::normalizeKey((string) ($existing['domain'] ?? '')) === $domain) {
                    throw new RuntimeException(
                        'Domain "' . (string) ($entry['domain'] ?? '') . '" sudah dipakai entri parkir lain'
                        . ' (id ' . (string) ($existing['id'] ?? '?') . ').'
                    );
                }
            }
            $data[] = $entry;
        });

        return $entry;
    }

    /**
     * Update entri berdasarkan id; `$mutator(array &$entry): void`.
     *
     * Setelah mutator: `id` dikunci, `updated_at` dinormalisasi, dan domain
     * hasil mutasi diperiksa duplikatnya (kecuali id sendiri) **di dalam
     * transaksi yang sama**.
     *
     * @param callable(array<string,mixed>&):void $mutator
     * @return array<string,mixed> entri setelah update
     *
     * @throws RuntimeException bila id tidak ditemukan atau domain bentrok
     */
    public function update(string $id, callable $mutator): array
    {
        $updated = null;
        $this->store->update(function (array &$data) use ($id, $mutator, &$updated): void {
            $index = null;
            foreach ($data as $i => $row) {
                if ((string) ($row['id'] ?? '') === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException('Entri domain parkir tidak ditemukan: ' . $id);
            }

            $row = $data[$index];
            $mutator($row);
            $row['id'] = $id; // identitas tidak boleh berubah lewat mutator
            $row['updated_at'] = date('c');

            $domain = self::normalizeKey((string) ($row['domain'] ?? ''));
            foreach ($data as $i => $other) {
                if ($i === $index || $domain === '') {
                    continue;
                }
                if (self::normalizeKey((string) ($other['domain'] ?? '')) === $domain) {
                    throw new RuntimeException(
                        'Domain "' . (string) ($row['domain'] ?? '') . '" sudah dipakai entri parkir lain'
                        . ' (id ' . (string) ($other['id'] ?? '?') . ').'
                    );
                }
            }

            $data[$index] = $row;
            $updated = $row;
        });

        return $updated ?? [];
    }

    /**
     * Hapus entri; no-op bila id tidak ada (idempoten).
     */
    public function delete(string $id): void
    {
        $this->store->update(function (array &$data) use ($id): void {
            $data = array_values(array_filter(
                $data,
                static fn (array $row): bool => (string) ($row['id'] ?? '') !== $id
            ));
        });
    }

    /**
     * Kunci pembanding domain: trim + lowercase (tanpa normalisasi FQDN lain —
     * itu tugas {@see \app\library\Domain\ParkedDomainValidator}).
     */
    private static function normalizeKey(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
