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
        $id = is_scalar($app['id'] ?? null) ? trim((string) $app['id']) : '';
        if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
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
