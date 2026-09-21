<?php
declare(strict_types=1);

namespace app\library\Monitor;

use app\library\Docker\ContainerStats;
use app\library\Docker\DockerClient;
use app\library\System\HostUsage;

/**
 * Perakit data monitoring resource (SPECS §8d) untuk halaman `/monitor`
 * (seluruh VM) dan tab Monitoring di detail app.
 *
 * Aturan visibilitas (satu implementasi, dipakai kedua halaman):
 *  - container milik compose project yang TIDAK ada di `apps.json`
 *    ("eksternal") hanya disertakan bila `$includeExternal` = true → admin;
 *  - selain itu hanya container milik project yang ada di daftar `$apps`
 *    (pemanggil sudah menyaringnya dengan `AppAccess::visible()`).
 *
 * Aturan itu ditegakkan di sisi server — daftar container memang tidak dikirim
 * ke user yang tidak berhak, bukan sekadar disembunyikan di UI.
 *
 * Metrik diambil langsung dari Engine (`listContainers` + `containersOverview`
 * paralel) sehingga tidak ada penambahan field pada `apps.json`; container ID
 * selalu di-resolve live (data tersimpan hanya memuat nama container).
 */
final class ResourceCollector
{
    /**
     * @param array<int,array> $apps            app yang boleh dilihat user
     * @param bool             $includeExternal sertakan container non-app (admin saja)
     * @param DockerClient|null $docker         klien Engine (disuntik saat test)
     * @return array{host:array,containers:array<int,array>,totals:array,error:?string}
     */
    public static function collect(array $apps, bool $includeExternal, ?DockerClient $docker = null): array
    {
        $host = self::hostSnapshot();
        $empty = ['host' => $host, 'containers' => [], 'totals' => ContainerStats::aggregate([]), 'error' => null];

        $projects = self::projectIndex($apps);
        try {
            $docker ??= new DockerClient(
                (string) config('deploy.docker_socket', '/var/run/docker.sock'),
                (int) config('deploy.monitor_stats_timeout', 20)
            );
            $raw = $docker->listContainers();
        } catch (\Throwable $e) {
            // halaman tetap tampil (metrik host dari /proc tidak butuh Engine)
            $empty['error'] = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();

            return $empty;
        }

        $visible = self::visibleContainers($raw, $projects, $includeExternal);
        if ($visible === []) {
            return $empty;
        }

        $overview = [];
        try {
            $overview = $docker->containersOverview(
                array_map(static fn (array $c): string => (string) ($c['Id'] ?? ''), $visible),
                (int) config('deploy.monitor_stats_timeout', 20)
            );
        } catch (\Throwable $e) {
            // stats gagal (mis. Engine menolak) — status container tetap dilaporkan
            $empty['error'] = 'Statistik container tidak tersedia: ' . $e->getMessage();
        }

        $rows = self::rows($visible, $overview, $projects);

        return [
            'host' => $host,
            'containers' => $rows,
            'totals' => ContainerStats::aggregate($rows),
            'error' => $empty['error'],
        ];
    }

    /**
     * Snapshot metrik host saja ("total VM") — TANPA menyentuh Docker Engine.
     *
     * Dipakai endpoint polling halaman `/monitor`: pembacaan `/proc` hanya file
     * lokal, jadi bisa dipanggil berkala (mis. tiap 5–10 detik selagi halaman
     * terbuka) tanpa membebani daemon seperti `stats` container.
     *
     * @param string|null $procPath path `/proc` host; null = dari config deploy
     * @return array hasil `HostUsage::snapshot()`
     */
    public static function hostSnapshot(?string $procPath = null): array
    {
        return HostUsage::snapshot($procPath ?? (string) config('deploy.host_proc_path', '/proc'));
    }

    /**
     * Peta nama project compose → identitas app dashboard.
     *
     * Nama project compose = nama app (`AppStore` memakai nama app sebagai
     * project name), lihat ARCHITECTURE §5.1.
     *
     * @param array<int,array> $apps
     * @return array<string,array{id:string,name:string}>
     */
    public static function projectIndex(array $apps): array
    {
        $index = [];
        foreach ($apps as $app) {
            $name = (string) ($app['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $index[$name] = ['id' => (string) ($app['id'] ?? ''), 'name' => $name];
        }

        return $index;
    }

    /**
     * Saring daftar container Engine sesuai hak akses (murni, tanpa I/O).
     *
     * @param array<int,array>          $containers respons `GET /containers/json`
     * @param array<string,array>       $projects   peta dari `projectIndex()`
     * @param bool                      $includeExternal
     * @return array<int,array>
     */
    public static function visibleContainers(array $containers, array $projects, bool $includeExternal): array
    {
        $visible = [];
        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }
            $project = self::projectOf($container);
            if ($project === '') {
                if ($includeExternal) {
                    $visible[] = $container;
                }
                continue;
            }
            if (isset($projects[$project])) {
                $visible[] = $container;
            }
        }

        return $visible;
    }

    /**
     * Gabungkan data Engine (list + stats + inspect) menjadi baris monitoring.
     *
     * @param array<int,array>       $containers hasil `visibleContainers()`
     * @param array<string,array>    $overview   hasil `DockerClient::containersOverview()`
     * @param array<string,array>    $projects   peta dari `projectIndex()`
     * @return array<int,array>
     */
    public static function rows(array $containers, array $overview, array $projects): array
    {
        $rows = [];
        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            $names = $container['Names'] ?? [];
            $name = is_array($names) && isset($names[0])
                ? ltrim((string) $names[0], '/')
                : substr($id, 0, 12);
            $project = self::projectOf($container);
            $app = $projects[$project] ?? null;
            $metrics = ContainerStats::map(
                (array) ($overview[$id]['stats'] ?? []),
                (array) ($overview[$id]['inspect'] ?? [])
            );

            $rows[] = array_merge($metrics, [
                'id' => substr($id, 0, 12),
                'name' => $name,
                'image' => (string) ($container['Image'] ?? ''),
                'state' => (string) ($container['State'] ?? 'unknown'),
                'project' => $project,
                'service' => (string) (($container['Labels'] ?? [])['com.docker.compose.service'] ?? ''),
                'managed' => $app !== null,
                'app_id' => $app['id'] ?? null,
                'app_name' => $app['name'] ?? null,
                'error' => $overview[$id]['error'] ?? null,
            ]);
        }

        usort($rows, static function (array $a, array $b): int {
            // container app dashboard lebih dulu, lalu per app, lalu per nama
            if ($a['managed'] !== $b['managed']) {
                return $a['managed'] ? -1 : 1;
            }
            return strcmp((string) ($a['app_name'] ?? $a['project']), (string) ($b['app_name'] ?? $b['project']))
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }

    /**
     * Nama compose project sebuah container ('' = di luar compose/dashboard).
     */
    public static function projectOf(array $container): string
    {
        $labels = $container['Labels'] ?? [];

        return (string) ($labels['com.docker.compose.project'] ?? '');
    }
}
