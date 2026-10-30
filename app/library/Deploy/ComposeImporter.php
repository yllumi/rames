<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\AppContainers;
use app\library\Docker\ComposeDiscovery;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;

/**
 * Impor project `docker compose` yang **sudah berjalan** menjadi App Rames.
 *
 * Keputusan produk yang dikunci:
 *  - informasi direktori asli (`working_dir` label Engine) hanya METADATA —
 *    Rames tidak memindahkan/menyalin berkas project;
 *  - app hasil impor **tidak** dikelola `docker compose` oleh Rames (tanpa
 *    override/env, tanpa up/down/build) — lihat {@see ImportedApp};
 *  - hanya project berlabel compose yang bisa diimpor;
 *  - tidak ada akses filesystem & tidak ada mutasi Engine sama sekali.
 *
 * Seam uji: `DockerClient` + `AppStore` disuntikkan lewat konstruktor.
 */
final class ComposeImporter
{
    private const IMPORT_MESSAGE = 'Diimpor dari project compose yang sudah berjalan (dikelola di luar Rames).';

    private DockerClient $docker;

    private AppStore $store;

    private ComposeDiscovery $discovery;

    public function __construct(DockerClient $docker, AppStore $store, ?ComposeDiscovery $discovery = null)
    {
        $this->docker = $docker;
        $this->store = $store;
        $this->discovery = $discovery ?? new ComposeDiscovery($docker);
    }

    /**
     * Impor satu project compose menjadi record app (tanpa deploy).
     *
     * Urutan wajib:
     *  1. discovery dijalankan ULANG di server (POST tidak dipercaya);
     *  2. `primary` divalidasi terhadap pilihan yang tersedia (fallback pilihan
     *     pertama; tanpa port sama sekali ⇒ service/port kosong);
     *  3. fail-fast nama app belum dipakai di server lokal;
     *  4. buat record sesuai kontrak field app impor;
     *  5. NOL efek samping lain (tidak memanggil deployer/worker/Engine).
     *
     * @return array<string,mixed> app hasil `AppStore::create()`
     * @throws RuntimeException project tidak ada/tidak importable, atau nama bentrok
     */
    public function import(string $project, string $primaryKey, string $ownerId): array
    {
        $project = trim($project);
        if ($project === '') {
            throw new RuntimeException('Project compose tidak dipilih.');
        }

        $row = $this->discovery->projectNamed($project, $this->store->all());
        if ($row === null) {
            if (in_array($project, ComposeDiscovery::EXCLUDED_PROJECTS, true)) {
                throw new RuntimeException(
                    'Project compose "' . $project . '" adalah project dashboard Rames dan tidak bisa diimpor.'
                );
            }

            throw new RuntimeException('Project compose "' . $project . '" tidak ditemukan di Docker Engine.');
        }

        if (!$row['name_valid']) {
            throw new RuntimeException('Nama project tidak bisa dipakai sebagai nama app.');
        }
        if ($row['conflict']) {
            throw new RuntimeException('Nama app "' . $project . '" sudah dipakai.');
        }
        if (!$row['importable']) {
            throw new RuntimeException(
                'Project compose "' . $project . '" tidak punya container yang bisa diimpor.'
            );
        }

        // Defense-in-depth: cek ulang keunikan per server tepat sebelum menulis.
        if ($this->store->nameExistsOnServer($project, ServerStore::LOCAL_ID)) {
            throw new RuntimeException('Nama app "' . $project . '" sudah dipakai.');
        }

        $choice = $this->resolvePrimary($primaryKey, $this->discovery->primaryChoices($row));
        $containers = AppContainers::fromEngineRows($this->docker->listContainersForProject($project));
        $status = 'stopped';
        foreach ($containers as $container) {
            if ((string) ($container['status'] ?? '') === 'running') {
                $status = 'running';
                break;
            }
        }

        $now = date('c');
        $composePaths = array_values(array_map('strval', (array) ($row['config_files'] ?? [])));
        $composeDir = (string) ($row['working_dir'] ?? '');

        return $this->store->create([
            'name' => $project,
            'server_id' => ServerStore::LOCAL_ID,
            'owner_id' => $ownerId,
            'members' => [],
            'origin' => ComposeSource::ORIGIN_IMPORTED,
            'imported_at' => $now,
            'imported_by' => $ownerId,
            'source' => ComposeSource::SOURCE_COMPOSE,
            'repo_url' => null,
            'branch' => null,
            'local_path' => $composeDir,
            'compose_dir' => $composeDir,
            'compose_paths' => $composePaths,
            // Nama berkas (basename) hanya metadata — `LocalDeployer` tidak boleh
            // dipakai untuk app impor.
            'compose_files' => array_values(array_map('strval', (array) ($row['compose_files'] ?? []))),
            'container_prefix' => null,
            'auth_method' => 'none',
            'ssh_key' => null,
            'env' => [],
            'limits' => null,
            'template' => null,
            'needs_ssl' => false,
            'ssl_status' => null,
            'primary_service' => $choice['service'],
            'primary_port' => $choice['port'],
            'status' => $status,
            'stage' => null,
            'message' => self::IMPORT_MESSAGE,
            'containers' => $containers,
            'deploy_history' => [[
                'sha' => '',
                'short' => '',
                'action' => 'import',
                'status' => 'success',
                'message' => self::IMPORT_MESSAGE,
                'created_at' => $now,
            ]],
        ]);
    }

    /**
     * Baris discovery tunggal untuk satu project (dipakai halaman/test).
     *
     * @return array<string,mixed>
     * @throws RuntimeException bila project tidak terdeteksi/dikecualikan
     */
    public function inspect(string $project): array
    {
        $row = $this->discovery->projectNamed(trim($project), $this->store->all());
        if ($row === null) {
            throw new RuntimeException('Project compose "' . trim($project) . '" tidak ditemukan di Docker Engine.');
        }

        return $row;
    }

    /**
     * Pilih `primary_service` + `primary_port` dari pilihan valid; kunci kosong
     * atau tidak dikenal ⇒ pilihan pertama; tanpa port ⇒ kosong/0.
     *
     * @param array<int,array{key:string,service:string,port:int,host:int}> $choices
     * @return array{service:string,port:int}
     */
    private function resolvePrimary(string $primaryKey, array $choices): array
    {
        $primaryKey = trim($primaryKey);
        if ($primaryKey !== '') {
            foreach ($choices as $choice) {
                if ($choice['key'] === $primaryKey) {
                    return ['service' => $choice['service'], 'port' => $choice['port']];
                }
            }
        }

        $first = $choices[0] ?? null;
        if ($first === null) {
            return ['service' => '', 'port' => 0];
        }

        return ['service' => $first['service'], 'port' => $first['port']];
    }
}
