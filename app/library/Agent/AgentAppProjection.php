<?php
declare(strict_types=1);

namespace app\library\Agent;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use InvalidArgumentException;

/**
 * Proyeksi app **di sisi agent** (plan §4.3, misi F1: `POST /jobs`).
 *
 * Agent (server #2..N) memegang basis data sendiri yang nyaris kosong: yang
 * terisi hanya app yang ditugaskan kepadanya. Payload job dari control plane
 * membawa dokumen app; kelas ini menuliskannya ke `AppStore` **milik agent**
 * dengan aturan kepemilikan data §4.3 — himpunan fieldnya **disjoint**, jadi
 * tidak pernah ada field yang punya dua penulis bernilai berbeda:
 *
 *  - **metadata** ({@see self::METADATA}) → control plane menang: ditimpa dari
 *    payload setiap kali job datang (`owner_id`, `members`, `name`, `subdomain`,
 *    `custom_domain`, `repo_url`, `branch`, `env`, `compose_files`, `limits`,
 *    `nginx_routes`, `container_prefix`, `primary_service`, `primary_port`,
 *    `external_networks`, `template`, `auth_method`, `ssh_key`, `source`);
 *    `custom_domain` ada di sini — **bukan** di {@see self::EXECUTION} — karena
 *    ia metadata control plane (dipakai agent untuk merender blok vhost &
 *    redirect subdomain, misi F2). Satu daftar field saja: jangan menyalin
 *    field metadata secara manual di controller (`AgentJobController`).
 *  - **state eksekusi** ({@see self::EXECUTION}) → agent menang: `status`,
 *    `stage`, `message`, `error`, `containers`, `deploy_history`, `ssl_status`
 *    **tidak pernah** ditimpa bila app sudah ada, karena hanya agent yang bisa
 *    mengamatinya (worker lokal `cli/deploy.php` yang menulisnya). Pada
 *    proyeksi **pertama** (app belum ada) nilai dari payload dipakai sebagai
 *    benih — ia satu-satunya informasi awal yang ada; sesudah itu milik agent.
 *
 * **Pemetaan `server_id` → `ServerStore::LOCAL_ID`** (penting): `server_id`
 * app di payload berasal dari registry **control plane** (mis. `abc123`), dan
 * registry itu tidak ada di agent (agent tidak mengenal server lain). Karena
 * agent selalu mengeksekusi **di dirinya sendiri**, `server_id` wajib
 * dipetakan ke `'local'`. Tanpa pemetaan ini `DeployerFactory::createForApp()`
 * di agent akan memanggil `ServerResolver::resolve('abc123')` → **melempar**
 * `RuntimeException` (id server tak dikenal) → seluruh deploy di agent gagal.
 *
 * Idempoten: proyeksi di-`upsert` berdasarkan `app.id`, jadi job berulang tidak
 * menggandakan app. Kelas ini **tidak** menyentuh store user, billing, backup,
 * registry server, atau file apa pun di luar `AppStore` agent.
 *
 * **Nama app & baris basi** (jebakan yang harus dijaga): keunikan nama di store
 * agent ditegakkan `AppStore::create()` (per `server_id`, dan di agent semuanya
 * `local`), sedangkan proyeksi masuk lewat `create()` **dan** `update()`. Bila
 * sebuah nama masih dipegang baris dengan **id app berbeda** (sisa app yang
 * sudah dihapus di control plane), `create()` melempar dan **seluruh** job untuk
 * nama itu — termasuk `teardown` saat app dihapus — dijawab 500, sehingga app
 * tidak bisa dibuat **maupun** dihapus. Karena store agent adalah data turunan
 * (SPECS §7.14) dan identitas app yang sah hanya yang datang dari payload job,
 * {@see self::upsert()} membuang baris basi itu lebih dulu
 * ({@see self::dropStaleName()}); {@see self::forget()} menutup sisi lainnya
 * dengan menghapus proyeksi saat app dihapus di control plane.
 *
 * Instance (bukan statik) semata karena path store di-override saat tes
 * (`new AgentAppProjection('/tmp/rames.sqlite')`), pola sama `AppStore`/
 * `UserStore`. Tanpa state lintas-request.
 */
final class AgentAppProjection
{
    /** Field metadata — control plane menang (ditimpa dari payload job). */
    public const METADATA = [
        'name',
        'source',
        'repo_url',
        'branch',
        'compose_files',
        'env',
        'limits',
        'container_prefix',
        'primary_service',
        'primary_port',
        'external_networks',
        'subdomain',
        'custom_domain',
        'nginx_routes',
        'owner_id',
        'members',
        'template',
        'auth_method',
        'ssh_key',
    ];

    /** Field state eksekusi — milik agent, tidak pernah ditimpa payload. */
    public const EXECUTION = [
        'status',
        'stage',
        'message',
        'error',
        'containers',
        'deploy_history',
        'ssl_status',
    ];

    /** Batas id app yang boleh ditulis (id berkas/kolom, bukan teks bebas). */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    private readonly AppStore $apps;

    /**
     * @param string|null $filePath berkas `.sqlite` agent; null = store bawaan
     *                              (`config('deploy.sqlite_file')`)
     */
    public function __construct(?string $filePath = null)
    {
        $this->apps = new AppStore($filePath);
    }

    /** Path basis data app yang dipakai (diagnostik/uji). */
    public function path(): string
    {
        return $this->apps->path();
    }

    /**
     * Tulis/segarkan proyeksi app dari payload job.
     *
     * @param array<string,mixed> $app dokumen app dari control plane
     * @return array<string,mixed> dokumen app setelah diproyeksikan
     * @throws InvalidArgumentException `id`/`name` kosong, atau `id` tak aman
     */
    public function upsert(array $app): array
    {
        $id = self::normalizeId($app['id'] ?? null);
        if ($id === '') {
            throw new InvalidArgumentException('Proyeksi app: `id` wajib dan hanya boleh [A-Za-z0-9_-].');
        }

        $name = is_scalar($app['name'] ?? null) ? trim((string) $app['name']) : '';
        if ($name === '') {
            throw new InvalidArgumentException('Proyeksi app: `name` wajib.');
        }

        // Agent selalu mengeksekusi di dirinya sendiri — lihat catatan kelas.
        $app['server_id'] = ServerStore::LOCAL_ID;

        $existing = $this->apps->find($id);
        if ($existing === null) {
            $this->dropStaleName($name, $id);

            return $this->apps->create($this->metadataOf($app) + $this->executionSeedOf($app));
        }

        return $this->apps->update($id, function (array &$current) use ($app): void {
            foreach (self::METADATA as $field) {
                if (array_key_exists($field, $app)) {
                    $current[$field] = $app[$field];
                }
            }
            // State eksekusi sengaja TIDAK disalin dari payload (agent menang).
            $current['server_id'] = ServerStore::LOCAL_ID;
        });
    }

    /**
     * Buang proyeksi app dari store agent (`forget`).
     *
     * Dipakai saat app **dihapus di control plane**: worker agent memanggilnya
     * setelah job `teardown` sukses (penanda `forget` di payload job, lihat
     * {@see \app\library\Deploy\AgentDeployer::teardown()}). Tanpa ini nama app
     * tetap "terpakai" di agent — store agent tidak punya jalur hapus lain —
     * sehingga create berikutnya dengan nama sama ditolak (lihat catatan kelas).
     *
     * Idempoten: id yang tidak ada di store tidak melakukan apa pun (jadi
     * `teardown` untuk app yang tidak dikenal tidak pernah gagal karena ini).
     *
     * @throws InvalidArgumentException `id` tak aman (pola id berkas/kolom)
     */
    public function forget(string $id): void
    {
        $safe = self::normalizeId($id);
        if ($safe === '') {
            throw new InvalidArgumentException('Proyeksi app: `id` wajib dan hanya boleh [A-Za-z0-9_-].');
        }

        $this->apps->delete($safe);
    }

    /**
     * Buang baris proyeksi **basi** yang masih memegang `$name` dengan id app
     * berbeda, sebelum proyeksi baru dibuat.
     *
     * Baris seperti itu adalah sisa app yang sudah dihapus di control plane
     * (mis. instalasi yang proyeksinya belum dibebaskan saat delete) — bukan
     * app lain yang sah: keunikan nama di control plane juga per server, jadi
     * dua app bernama sama tidak bisa hidup di agent yang sama. App dengan **id
     * yang sama** tidak pernah dibuang di sini — jalur itu `update()` (state
     * eksekusi milik agent tetap utuh).
     *
     * Baris **tanpa id** (store agent rusak, tidak mungkin dibuat proyeksi yang
     * sehat) sengaja tidak disentuh: ia tidak bisa ditarget lewat id, jadi
     * pilihannya hanya "biarkan terlihat" (job ditolak 500 `projection_failed`,
     * operator membereskan store) atau menebak-nebak baris mana yang dibuang.
     */
    private function dropStaleName(string $name, string $id): void
    {
        $stale = $this->apps->findByNameOnServer($name, ServerStore::LOCAL_ID);
        $staleId = is_array($stale) ? trim((string) ($stale['id'] ?? '')) : '';
        if ($staleId === '' || $staleId === $id) {
            return;
        }

        $this->apps->delete($staleId);
    }

    /**
     * Id app yang aman dipakai sebagai id berkas/kolom — `''` bila kosong atau
     * tidak sesuai pola.
     */
    private static function normalizeId(mixed $raw): string
    {
        $id = is_scalar($raw) ? trim((string) $raw) : '';

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
    }

    /**
     * Metadata yang benar-benar dikirim payload (field absen tidak mengosongkan
     * nilai lama — control plane boleh mengirim dokumen parsial).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function metadataOf(array $app): array
    {
        $doc = ['id' => (string) $app['id'], 'name' => (string) $app['name']];
        foreach (self::METADATA as $field) {
            if (array_key_exists($field, $app)) {
                $doc[$field] = $app[$field];
            }
        }
        $doc['server_id'] = ServerStore::LOCAL_ID;

        return $doc;
    }

    /**
     * Benih state eksekusi untuk proyeksi **pertama**: salinan dari control
     * plane (plan §4.3: CP memegang "salinan hasil sinkron"). Sesudah ini
     * nilainya milik agent dan tidak pernah ditimpa payload.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function executionSeedOf(array $app): array
    {
        $seed = [];
        foreach (self::EXECUTION as $field) {
            if (array_key_exists($field, $app)) {
                $seed[$field] = $app[$field];
            }
        }

        return $seed;
    }
}
