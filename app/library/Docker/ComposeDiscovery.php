<?php
declare(strict_types=1);

namespace app\library\Docker;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;

/**
 * Penemuan project `docker compose` yang **sudah berjalan** di Docker Engine
 * host ini (dibuat manual dari terminal, bukan oleh Rames).
 *
 * Sumber data: label yang ditanam Docker Compose pada tiap container
 * (`com.docker.compose.project`, `…service`, `…project.working_dir`,
 * `…project.config_files`). Container `docker run` tanpa label compose TIDAK
 * terdeteksi — sesuai keputusan produk: yang bisa diimpor hanya project compose.
 *
 * Read-only: tidak pernah memanggil CLI `docker compose` maupun memutasi Engine.
 *
 * Aturan eksklusi:
 *  - project kosong;
 *  - project dashboard sendiri (`rames`, `rames-agent`);
 *  - **project yang namanya sudah dipakai app lokal** TIDAK dibuang dari daftar,
 *    melainkan ditandai `conflict: true` + `importable: false` — UI perlu
 *    menjelaskan sebabnya, dan importer menolak dengan pesan "sudah dipakai".
 */
final class ComposeDiscovery
{
    /** Project milik dashboard itu sendiri — tidak pernah bisa diimpor. */
    public const EXCLUDED_PROJECTS = ['rames', 'rames-agent'];

    /** Pola nama project yang aman dipakai sebagai nama app (slug). */
    private const PROJECT_NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,62}$/';

    /** @var array<int,string> */
    private array $excludedProjects;

    private DockerClient $docker;

    /**
     * @param array<int,string> $excludedProjects default: project dashboard
     */
    public function __construct(DockerClient $docker, array $excludedProjects = self::EXCLUDED_PROJECTS)
    {
        $this->docker = $docker;
        $this->excludedProjects = array_values(array_map('strval', $excludedProjects));
    }

    /**
     * Daftar project compose terdeteksi, urut nama project.
     *
     * @param array<int,array> $apps dokumen app (untuk deteksi nama bentrok di server lokal)
     * @return array<int,array{project:string,name_valid:bool,conflict:bool,containers:int,running:int,state:string,services:array<int,string>,images:array<int,string>,ports:array<int,array{host:int,container:int,service:string}>,working_dir:string,config_files:array<int,string>,compose_files:array<int,string>,importable:bool}>
     */
    public function projects(array $apps): array
    {
        $raw = $this->engineContainers();

        /** @var array<string,array<int,array>> $grouped */
        $grouped = [];
        foreach ($raw as $container) {
            if (!is_array($container)) {
                continue;
            }
            $project = $this->label($container, 'com.docker.compose.project');
            if ($project === '') {
                continue;
            }
            $grouped[$project][] = $container;
        }

        $localAppNames = $this->localAppNames($apps);
        $rows = [];
        foreach ($grouped as $project => $containers) {
            if (in_array($project, $this->excludedProjects, true)) {
                continue;
            }

            $rows[] = $this->row((string) $project, $containers, $localAppNames);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['project'], $b['project']));

        return $rows;
    }

    /**
     * Pilihan `primary_service` + `primary_port` untuk radio/select form impor.
     *
     * @param array{ports?:array<int,array{host:int,container:int,service:string}>} $projectRow baris hasil {@see self::projects()}
     * @return array<int,array{key:string,service:string,port:int,host:int}>
     */
    public function primaryChoices(array $projectRow): array
    {
        $choices = [];
        $seen = [];
        foreach ((array) ($projectRow['ports'] ?? []) as $port) {
            if (!is_array($port)) {
                continue;
            }
            $service = (string) ($port['service'] ?? '');
            $containerPort = (int) ($port['container'] ?? 0);
            $host = (int) ($port['host'] ?? 0);
            if ($containerPort <= 0 || $host <= 0) {
                continue;
            }

            $key = $service . ':' . $containerPort;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $choices[] = [
                'key' => $key,
                'service' => $service,
                'port' => $containerPort,
                'host' => $host,
            ];
        }

        return $choices;
    }

    /**
     * Baris discovery satu project (null bila tidak terdeteksi/dikecualikan).
     *
     * @param array<int,array> $apps
     * @return array|null
     */
    public function projectNamed(string $project, array $apps): ?array
    {
        foreach ($this->projects($apps) as $row) {
            if ($row['project'] === $project) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Nama project valid sebagai nama app.
     */
    public static function validName(string $project): bool
    {
        if ($project === '' || $project === '.' || $project === '..' || str_contains($project, '/')) {
            return false;
        }

        return preg_match(self::PROJECT_NAME_PATTERN, $project) === 1;
    }

    /**
     * @return array<int,array>
     */
    private function engineContainers(): array
    {
        try {
            return $this->docker->listContainers(['label' => ['com.docker.compose.project']]);
        } catch (\Throwable $e) {
            // Jangan ditelan diam-diam: halaman impor harus bisa menampilkan
            // sebabnya ("Engine tidak tersedia").
            throw new RuntimeException(
                'Tidak bisa membaca project compose dari Docker Engine: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @param array<int,array> $containers
     * @param array<string,true> $localAppNames
     * @return array<string,mixed>
     */
    private function row(string $project, array $containers, array $localAppNames): array
    {
        $services = [];
        $images = [];
        $ports = [];
        $seenPorts = [];
        $running = 0;
        $workingDir = '';
        $configFiles = [];
        $seenConfig = [];

        foreach ($containers as $container) {
            if ((string) ($container['State'] ?? '') === 'running') {
                $running++;
            }

            $service = $this->label($container, 'com.docker.compose.service');
            if ($service !== '' && !in_array($service, $services, true)) {
                $services[] = $service;
            }

            $image = trim((string) ($container['Image'] ?? ''));
            if ($image !== '' && !in_array($image, $images, true)) {
                $images[] = $image;
            }

            if ($workingDir === '') {
                $workingDir = $this->label($container, 'com.docker.compose.project.working_dir');
            }

            foreach ($this->configFilesFrom($container) as $path) {
                if (isset($seenConfig[$path])) {
                    continue;
                }
                $seenConfig[$path] = true;
                $configFiles[] = $path;
            }

            foreach ((array) ($container['Ports'] ?? []) as $port) {
                if (!is_array($port) || !isset($port['PublicPort'], $port['PrivatePort'])) {
                    continue;
                }
                $host = (int) $port['PublicPort'];
                $containerPort = (int) $port['PrivatePort'];
                if ($host <= 0 || $containerPort <= 0) {
                    continue;
                }
                $key = $service . ':' . $containerPort . ':' . $host;
                if (isset($seenPorts[$key])) {
                    continue;
                }
                $seenPorts[$key] = true;
                $ports[] = [
                    'host' => $host,
                    'container' => $containerPort,
                    'service' => $service,
                ];
            }
        }

        usort($ports, static function (array $a, array $b): int {
            return $a['host'] <=> $b['host']
                ?: $a['container'] <=> $b['container']
                ?: strcmp($a['service'], $b['service']);
        });

        $containersCount = count($containers);
        $state = $running === 0
            ? 'stopped'
            : ($running >= $containersCount ? 'running' : 'partial');

        $nameValid = self::validName($project);
        $conflict = isset($localAppNames[$project]);

        $composeFiles = [];
        foreach ($configFiles as $path) {
            $base = basename($path);
            if ($base !== '' && !in_array($base, $composeFiles, true)) {
                $composeFiles[] = $base;
            }
        }

        return [
            'project' => $project,
            'name_valid' => $nameValid,
            'conflict' => $conflict,
            'containers' => $containersCount,
            'running' => $running,
            'state' => $state,
            'services' => $services,
            'images' => $images,
            'ports' => $ports,
            'working_dir' => $workingDir,
            'config_files' => $configFiles,
            'compose_files' => $composeFiles,
            'importable' => $nameValid && !$conflict && $containersCount > 0,
        ];
    }

    /**
     * Nama app pada **server lokal** (bukan server agent) — kandidat bentrok.
     *
     * @param array<int,array> $apps
     * @return array<string,true>
     */
    private function localAppNames(array $apps): array
    {
        $names = [];
        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            if (AppStore::serverIdOf($app) !== ServerStore::LOCAL_ID) {
                continue;
            }
            $name = trim((string) ($app['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @param array $container baris Engine
     */
    private function label(array $container, string $key): string
    {
        $labels = $container['Labels'] ?? [];
        if (!is_array($labels)) {
            return '';
        }

        return trim((string) ($labels[$key] ?? ''));
    }

    /**
     * Label `…project.config_files` berisi daftar path dipisah koma.
     *
     * @param array $container
     * @return array<int,string>
     */
    private function configFilesFrom(array $container): array
    {
        $raw = $this->label($container, 'com.docker.compose.project.config_files');
        if ($raw === '') {
            return [];
        }

        $files = [];
        foreach (explode(',', $raw) as $path) {
            $path = trim($path);
            if ($path !== '') {
                $files[] = $path;
            }
        }

        return $files;
    }
}
