<?php
declare(strict_types=1);

namespace app\library\Monitor;

use app\library\Agent\AgentError;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerInterface;
use app\library\Docker\ContainerStats;
use app\library\Docker\DockerClient;

/**
 * Snapshot metrik **satu app** untuk tab Info detail app (SPECS §8d).
 *
 * Kelas ini hanya menyediakan data **saat ini**; grafik 5 menit dirakit browser
 * (rolling window) sehingga tidak ada sampler/tabel metrik baru di server —
 * penting pada worker Webman persistent yang tidak boleh menyimpan state
 * lintas-request.
 *
 * Sumber data:
 *  - **lokal**  → `ResourceCollector::collect()` (Engine host ini) lalu disaring
 *                 ke app; `DockerClient::getDiskUsage()` untuk storage;
 *  - **agent**  → `AgentDeployer::containerStats()` / `diskUsage()` (metrik
 *                 diambil **di** server itu, bukan dari daemon control plane).
 *
 * Semua nilai yang tidak terbaca bernilai `null` — UI menampilkan "—" alih-alih
 * 0 yang menyesatkan. Pesan galat agent **tidak** disanitasi di sini (kelas ini
 * tidak memiliki dokumen server); controller memanggil
 * `AgentError::sanitizeForServer()` sebelum data dikirim ke UI.
 */
class AppMetrics
{
    private ?DockerClient $docker;

    /**
     * @param DockerClient|null $docker Engine API host ini (seam uji; null ⇒
     *                                   dibangun dari `config('deploy.docker_socket')`)
     */
    public function __construct(?DockerClient $docker = null)
    {
        $this->docker = $docker;
    }

    /**
     * Snapshot CPU/RAM container milik app (bukan seluruh host).
     *
     * @param array<string,mixed> $app
     * @return array{
     *   cpu_percent:?float, mem_used:?int, mem_limit:?int, mem_percent:?float,
     *   containers:int, running:int, containers_detail:array<int,array>, error:?string
     * }
     */
    public function usage(array $app, DeployerInterface $deployer): array
    {
        try {
            if ($deployer instanceof AgentDeployer) {
                $result = $deployer->containerStats();
                $rows = $this->rowsForApp((array) ($result['containers'] ?? []), $app);

                return $this->assemble($rows, $this->textOrNull($result['error'] ?? null));
            }

            $result = ResourceCollector::collect([$app], false, $this->docker());
            $rows = $this->rowsForApp((array) ($result['containers'] ?? []), $app);

            return $this->assemble($rows, $this->textOrNull($result['error'] ?? null));
        } catch (\Throwable $e) {
            return $this->unavailableUsage($e->getMessage());
        }
    }

    /**
     * Total storage app (volume project + writable layer container).
     *
     * Agent lama yang belum punya endpoint `/system/disk-usage` menjawab 404 →
     * `available:false` dengan pesan "belum mendukung" (bukan galat 500).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed> bentuk blok `AppStorage::summarize()`
     */
    public function storage(array $app, DeployerInterface $deployer): array
    {
        $project = (string) ($app['name'] ?? '');

        try {
            if ($deployer instanceof AgentDeployer) {
                return $deployer->diskUsage($project);
            }

            $df = $this->docker()->getDiskUsage(['volume', 'container']);

            return AppStorage::summarize(is_array($df) ? $df : [], $project);
        } catch (AgentError $e) {
            if ($e->reason === AgentError::NOT_FOUND) {
                return AppStorage::unavailable('Versi agent belum mendukung ukuran storage.');
            }

            return AppStorage::unavailable('Ukuran storage tidak tersedia.');
        } catch (\Throwable) {
            return AppStorage::unavailable('Ukuran storage tidak tersedia.');
        }
    }

    /**
     * Engine API host ini (lazy supaya unit test bisa menyuntikkan tiruan tanpa
     * menyentuh socket Docker).
     */
    protected function docker(): DockerClient
    {
        return $this->docker ??= new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            (int) config('deploy.monitor_stats_timeout', 20)
        );
    }

    /**
     * Saring baris monitoring ke app ini (`app_id`) atau compose project app
     * (`project` = `apps.name`). Jalur agent hanya punya `app_id` dari proyeksi
     * app di sana; jalur lokal memakai keduanya supaya container project app
     * tetap terhitung walau proyeksi `app_id` belum ada.
     *
     * @param array<int,mixed> $rows
     * @param array<string,mixed> $app
     * @return array<int,array>
     */
    private function rowsForApp(array $rows, array $app): array
    {
        $id = (string) ($app['id'] ?? '');
        $project = (string) ($app['name'] ?? '');

        return array_values(array_filter($rows, static function (mixed $row) use ($id, $project): bool {
            if (!is_array($row)) {
                return false;
            }
            $rowId = (string) ($row['app_id'] ?? '');
            if ($id !== '' && $rowId === $id) {
                return true;
            }

            return $project !== '' && (string) ($row['project'] ?? '') === $project;
        }));
    }

    /**
     * Rakit agregat + detail minimal dari baris monitoring app.
     *
     * @param array<int,array> $rows
     * @return array<string,mixed>
     */
    private function assemble(array $rows, ?string $error): array
    {
        $aggregate = ContainerStats::aggregate($rows);

        // `ContainerStats::aggregate()` meng-coalesce nilai null menjadi 0
        // (perilaku halaman `/monitor` tidak boleh berubah). Untuk metrik
        // per-app, 0 pada CPU/RAM yang **tidak terbaca** menyesatkan: app yang
        // seluruh containernya berhenti/tak terbaca harus tampil "—".
        $hasMetrics = false;
        foreach ($rows as $row) {
            if (($row['cpu_percent'] ?? null) !== null || ($row['mem_used'] ?? null) !== null) {
                $hasMetrics = true;
                break;
            }
        }
        if (!$hasMetrics) {
            $aggregate['cpu_percent'] = null;
            $aggregate['mem_used'] = null;
            $aggregate['mem_limit'] = null;
            $aggregate['mem_percent'] = null;
        }

        $detail = [];
        foreach ($rows as $row) {
            $detail[] = [
                'name' => (string) ($row['name'] ?? ''),
                'service' => (string) ($row['service'] ?? ''),
                'state' => $this->textOrNull($row['state'] ?? $row['status'] ?? null),
                'cpu_percent' => $this->floatOrNull($row['cpu_percent'] ?? null),
                'mem_used' => $this->intOrNull($row['mem_used'] ?? null),
                'mem_limit' => $this->intOrNull($row['mem_limit'] ?? null),
                'mem_percent' => $this->floatOrNull($row['mem_percent'] ?? null),
                'uptime_seconds' => $this->intOrNull($row['uptime_seconds'] ?? null),
                'health' => $this->textOrNull($row['health'] ?? null),
            ];
        }

        return $aggregate + [
            'containers_detail' => $detail,
            'error' => $error,
        ];
    }

    /** @return array<string,mixed> */
    private function unavailableUsage(?string $error): array
    {
        return [
            'cpu_percent' => null,
            'mem_used' => null,
            'mem_limit' => null,
            'mem_percent' => null,
            'containers' => 0,
            'running' => 0,
            'containers_detail' => [],
            'error' => $this->textOrNull($error),
        ];
    }

    private function textOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
