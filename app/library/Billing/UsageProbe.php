<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Agent\AgentError;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Monitor\ResourceCollector;
use app\library\Storage\AppStore;
use Throwable;

/**
 * Pengamat container **per server** (misi `ms-f4b-metering-server`) — satu-satunya
 * tempat meteran kredit menentukan "apakah app ini benar-benar berjalan, dan di
 * server mana".
 *
 * Alasan keberadaannya: sebelum F4 meteran hanya memandang `status` app di
 * control plane, sehingga app yang berjalan di **server agent** tidak pernah
 * diperiksa dari server tempat container-nya hidup (risiko salah hitung). Kelas
 * ini membaca bukti dari **server pemilik app** — satu pembacaan per server per
 * tick (bukan per app):
 *  - `server_id` app = server lokal ⇒ Engine host ini (`DockerClient::listContainers()`);
 *  - `server_id` app = server agent ⇒ `AgentDeployer::containerStats()`
 *    (`GET /monitor/containers` di server itu) — **tidak pernah** Engine host ini.
 *
 * Kebijakan hasil (dipakai `UsageMeter`):
 *  - server terjangkau & container app teramati hidup
 *    (`LIVE_STATES`: `running`/`paused`/`restarting`) ⇒ app layak diakru;
 *  - server terjangkau & container app tidak ada / tidak hidup ⇒ **tidak**
 *    layak diakru (control plane tidak perlu menagih app yang mati);
 *  - server tidak terjangkau (offline/timeout/config cacat) ⇒ `reachable = false`
 *    beserta `detail`, dan `UsageMeter` **melewati** akrual app di server itu
 *    serta mencatat alasannya (lihat `UsageMeter::sampleReport()`).
 *
 * Kelas ini **tidak** menghitung uang dan **tidak** menyentuh store: ia hanya
 * melaporkan apa yang teramati. Kegagalan satu server tidak pernah menggagalkan
 * server lain (tiap server dibungkus `try/catch(\Throwable)`), dan pesan galat
 * disanitasi (`AgentError::sanitize()` + token server) supaya tidak ada token
 * yang bocor ke log billing.
 *
 * `AgentDeployer::hostUsage()` **sengaja tidak dipakai**: keputusan akrual per
 * app butuh bukti container app (bukan metrik host), dan metrik host tiap server
 * sudah dilaporkan halaman `/monitor` (`ResourceCollector::hostSnapshot()` di
 * agent) — menambahkannya di sini hanya menambah satu panggilan HTTP per tick
 * tanpa mengubah keputusan akrual.
 *
 * Seam uji (pola `DockerClient` di `Monitor\ResourceCollector`): `$localStats`,
 * `$agentStats`, `$docker`, dan `$servers` dapat disuntik sehingga tes berjalan
 * tanpa daemon Docker, tanpa jaringan, dan tanpa store runtime nyata.
 */
final class UsageProbe
{
    /** Server tidak dapat dihubungi / pembacaan metrik gagal. */
    public const CAUSE_UNREACHABLE = 'server_unreachable';

    /** Server terjangkau, tetapi container app tidak teramati `running`. */
    public const CAUSE_NOT_RUNNING = 'container_not_running';

    /** `server_id` app tidak ada di registry server (fail-fast, bukan fallback lokal). */
    public const CAUSE_UNKNOWN_SERVER = 'server_unknown';

    /**
     * State container Docker yang dianggap "app hidup" (masih memegang resource):
     * `running` (normal), `paused` (RAM tetap tertahan), `restarting` (crash-loop
     * — app sedang dicoba dijalankan lagi; menilainya mati akan membuka celah
     * "crash-loop = gratis"). State lain (`created`, `exited`, `dead`, `removing`,
     * tak dikenal) **tidak** dianggap hidup ⇒ app tidak diakru pada tick itu.
     *
     * @var array<int,string>
     */
    public const LIVE_STATES = ['running', 'paused', 'restarting'];

    private ?ServerResolver $servers;

    /** @var callable|null fn(array<int,array<string,mixed>> $apps): array{containers:array<int,array>,error:?string} */
    private $localStats;

    /** @var callable|null fn(array<string,mixed> $server): array{containers:array<int,array>,error:?string} */
    private $agentStats;

    private ?DockerClient $docker;

    /**
     * @param ServerResolver|null $servers    resolver registry server; null = store
     *                                        bawaan (dibuat malas saat pertama dipakai)
     * @param callable|null       $localStats seam uji pembacaan Engine **host lokal**
     * @param callable|null       $agentStats seam uji pembacaan stats server agent
     * @param DockerClient|null   $docker     klien Engine host ini (seam uji)
     */
    public function __construct(
        ?ServerResolver $servers = null,
        ?callable $localStats = null,
        ?callable $agentStats = null,
        ?DockerClient $docker = null,
    ) {
        $this->servers = $servers;
        $this->localStats = $localStats;
        $this->agentStats = $agentStats;
        $this->docker = $docker;
    }

    /**
     * Amati seluruh `$apps` yang diberikan, dikelompokkan per `server_id` app.
     *
     * @param array<string,array<string,mixed>> $apps appId => dokumen app
     *                                               (hanya app yang sedang dinilai
     *                                               `UsageMeter` yang dikirim)
     * @return array{
     *   servers: array<string,array{server_id:string,kind:string,reachable:bool,error:?string,apps:int,running:int}>,
     *   apps: array<string,array{server_id:string,kind:string,reachable:bool,container_running:bool,containers:int,cpu_percent:?float,memory_mb:?float,cause:?string,detail:string}>
     * }
     */
    public function observe(array $apps): array
    {
        $groups = [];
        foreach ($apps as $appId => $app) {
            $id = trim((string) $appId);
            if ($id === '' || !is_array($app)) {
                continue;
            }
            // Dokumen dinormalkan: `id` selalu ada (pemanggil boleh mengirim baris
            // ringkas tanpa `id`, mis. `UsageMeter` yang hanya butuh harga/owner).
            $app['id'] = $id;
            $groups[AppStore::serverIdOf($app)][$id] = $app;
        }
        // Urutan deterministik (mudah dibaca di log & tes).
        ksort($groups);

        $servers = [];
        $verdicts = [];

        foreach ($groups as $serverId => $group) {
            $serverId = (string) $serverId;
            try {
                $server = $this->resolver()->resolve($serverId);
            } catch (Throwable $e) {
                $detail = $this->sanitize('server tidak dikenal di registry: ' . $e->getMessage());
                $servers[$serverId] = $this->serverRecord($serverId, 'unknown', false, $detail, count($group), 0);
                foreach ($group as $appId => $app) {
                    $verdicts[$appId] = $this->verdict($serverId, 'unknown', false, false, 0, null, null, self::CAUSE_UNKNOWN_SERVER, $detail);
                }
                continue;
            }

            $kind = ServerResolver::isLocal($server) ? 'local' : 'agent';

            try {
                $read = $kind === 'local'
                    ? $this->readLocal(array_values($group))
                    : $this->readAgent($server);
            } catch (Throwable $e) {
                $detail = $this->sanitize('tidak dapat membaca metrik server ' . $serverId . ': ' . $e->getMessage(), $server);
                $servers[$serverId] = $this->serverRecord($serverId, $kind, false, $detail, count($group), 0);
                foreach ($group as $appId => $app) {
                    $verdicts[$appId] = $this->verdict($serverId, $kind, false, false, 0, null, null, self::CAUSE_UNREACHABLE, $detail);
                }
                continue;
            }

            $rows = $this->rows($read);
            $error = $this->readError($read);

            // Engine/agent terjangkau tetapi daftar container gagal dibaca
            // (mis. daftar kosong + pesan galat): perlakukan sebagai server tak
            // terjangkau — jangan menuduh "container app mati" tanpa bukti.
            if ($rows === [] && $error !== null) {
                $servers[$serverId] = $this->serverRecord($serverId, $kind, false, $error, count($group), 0);
                foreach ($group as $appId => $app) {
                    $verdicts[$appId] = $this->verdict($serverId, $kind, false, false, 0, null, null, self::CAUSE_UNREACHABLE, $error);
                }
                continue;
            }

            $found = $this->matchRows($group, $rows);
            $running = 0;
            foreach ($group as $appId => $app) {
                $observation = $found[$appId] ?? null;
                $isRunning = $observation !== null && $observation['running'];
                if ($isRunning) {
                    $running++;
                }
                $containers = $observation['containers'] ?? 0;
                $detail = $isRunning
                    ? sprintf('container app teramati hidup di server "%s" (%d container)', $serverId, $containers)
                    : sprintf('tidak ada container app ini yang teramati hidup di server "%s"', $serverId);
                $verdicts[$appId] = $this->verdict(
                    $serverId,
                    $kind,
                    true,
                    $isRunning,
                    $containers,
                    $observation['cpu_percent'] ?? null,
                    $observation['memory_mb'] ?? null,
                    $isRunning ? null : self::CAUSE_NOT_RUNNING,
                    $detail
                );
            }
            $servers[$serverId] = $this->serverRecord($serverId, $kind, true, $error, count($group), $running);
        }

        return ['servers' => $servers, 'apps' => $verdicts];
    }

    /**
     * Resolusi registry server (dibuat malas: `UsageMeter` yang hanya dipakai
     * untuk menampilkan ringkasan tidak perlu menyentuh store server).
     */
    private function resolver(): ServerResolver
    {
        return $this->servers ??= new ServerResolver();
    }

    /**
     * Pembacaan **Engine host lokal** (server `kind=local`).
     *
     * Dipakai `DockerClient::listContainers()` (bukan stats per container):
     * keputusan kelayakan akrual hanya butuh daftar container + `State`, sehingga
     * tick tidak menambah beban `stats` ke daemon.
     *
     * @param array<int,array<string,mixed>> $apps app server lokal
     * @return array{containers:array<int,array<string,mixed>>,error:?string}
     * @throws \RuntimeException dari `DockerClient` bila Engine tidak terjangkau
     */
    private function readLocal(array $apps): array
    {
        if ($this->localStats !== null) {
            return $this->normalize(($this->localStats)($apps));
        }

        $byProject = [];
        foreach ($apps as $app) {
            $name = trim((string) ($app['name'] ?? ''));
            if ($name !== '') {
                $byProject[$name] = (string) ($app['id'] ?? '');
            }
        }

        $docker = $this->docker ?? new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            10
        );

        $rows = [];
        foreach ($docker->listContainers() as $container) {
            if (!is_array($container)) {
                continue;
            }
            $project = ResourceCollector::projectOf($container);
            if ($project === '' || !isset($byProject[$project])) {
                continue; // container di luar app yang sedang dinilai
            }
            $names = $container['Names'] ?? [];
            $rows[] = [
                'app_id' => $byProject[$project],
                'project' => $project,
                'state' => (string) ($container['State'] ?? 'unknown'),
                'container_name' => is_array($names) && isset($names[0])
                    ? ltrim((string) $names[0], '/')
                    : substr((string) ($container['Id'] ?? ''), 0, 12),
            ];
        }

        return ['containers' => $rows, 'error' => null];
    }

    /**
     * Pembacaan statistik container **server agent** (`AgentDeployer::containerStats()`).
     *
     * @param array<string,mixed> $server dokumen server (`kind=agent`)
     * @return array{containers:array<int,array<string,mixed>>,error:?string}
     * @throws AgentError server agent tidak dapat dihubungi / kontrak respons cacat
     */
    private function readAgent(array $server): array
    {
        if ($this->agentStats !== null) {
            return $this->normalize(($this->agentStats)($server));
        }

        $deployer = DeployerFactory::createFor($server);
        if (!$deployer instanceof AgentDeployer) {
            throw new \RuntimeException(
                'deployer server "' . (string) ($server['id'] ?? '') . '" bukan AgentDeployer (kind tidak konsisten)'
            );
        }

        return $this->normalize($deployer->containerStats());
    }

    /**
     * Petakan baris container → app (kunci: `app_id` dari server; cadangan nama
     * project/app). Nama app unik per server (`AppStore`), jadi cadangan itu aman.
     *
     * @param array<string,array<string,mixed>> $group appId => app (server ini)
     * @param array<int,array<string,mixed>>    $rows
     * @return array<string,array{running:bool,containers:int,cpu_percent:?float,memory_mb:?float}>
     */
    private function matchRows(array $group, array $rows): array
    {
        $byName = [];
        foreach ($group as $appId => $app) {
            $name = trim((string) ($app['name'] ?? ''));
            if ($name !== '' && !isset($byName[$name])) {
                $byName[$name] = (string) $appId;
            }
        }

        $found = [];
        foreach ($rows as $row) {
            $appId = trim((string) ($row['app_id'] ?? ''));
            if ($appId === '' || !isset($group[$appId])) {
                $key = trim((string) ($row['project'] ?? ''));
                if ($key === '') {
                    $key = trim((string) ($row['app_name'] ?? ''));
                }
                $appId = $key !== '' ? ($byName[$key] ?? '') : '';
            }
            if ($appId === '') {
                continue; // container di luar app dashboard → bukan urusan meteran
            }

            $current = $found[$appId] ?? ['running' => false, 'containers' => 0, 'cpu_percent' => null, 'memory_mb' => null];
            $current['containers']++;
            if (in_array(strtolower(trim((string) ($row['state'] ?? ''))), self::LIVE_STATES, true)) {
                $current['running'] = true;
            }
            if (is_numeric($row['cpu_percent'] ?? null)) {
                $current['cpu_percent'] = round((float) ($current['cpu_percent'] ?? 0.0) + (float) $row['cpu_percent'], 2);
            }
            if (is_numeric($row['mem_used'] ?? null)) {
                $current['memory_mb'] = round((float) ($current['memory_mb'] ?? 0.0) + (int) $row['mem_used'] / 1048576, 1);
            }
            $found[$appId] = $current;
        }

        return $found;
    }

    /**
     * Normalisasi hasil seam/sumber metrik menjadi `{containers,error}`.
     *
     * @param mixed $read
     * @return array{containers:array<int,array<string,mixed>>,error:?string}
     */
    private function normalize(mixed $read): array
    {
        if (!is_array($read)) {
            throw new \RuntimeException('sumber metrik tidak mengembalikan array');
        }

        return ['containers' => $this->rows($read), 'error' => $this->readError($read)];
    }

    /**
     * Baris container dari hasil pembacaan (hanya entri array).
     *
     * @param array<string,mixed> $read
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $read): array
    {
        $rows = $read['containers'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Pesan galat (non-fatal) dari sumber metrik — mis. agent melaporkan daftar
     * container ada tetapi statistiknya gagal; disanitasi supaya aman di log.
     *
     * @param array<string,mixed> $read
     */
    private function readError(array $read): ?string
    {
        $error = $read['error'] ?? null;
        if (!is_string($error) || trim($error) === '') {
            return null;
        }

        return $this->sanitize($error);
    }

    /**
     * @return array{server_id:string,kind:string,reachable:bool,error:?string,apps:int,running:int}
     */
    private function serverRecord(string $serverId, string $kind, bool $reachable, ?string $error, int $apps, int $running): array
    {
        return [
            'server_id' => $serverId,
            'kind' => $kind,
            'reachable' => $reachable,
            'error' => $error,
            'apps' => $apps,
            'running' => $running,
        ];
    }

    /**
     * @return array{server_id:string,kind:string,reachable:bool,container_running:bool,containers:int,cpu_percent:?float,memory_mb:?float,cause:?string,detail:string}
     */
    private function verdict(
        string $serverId,
        string $kind,
        bool $reachable,
        bool $running,
        int $containers,
        ?float $cpuPercent,
        ?float $memoryMb,
        ?string $cause,
        string $detail,
    ): array {
        return [
            'server_id' => $serverId,
            'kind' => $kind,
            'reachable' => $reachable,
            'container_running' => $running,
            'containers' => $containers,
            'cpu_percent' => $cpuPercent,
            'memory_mb' => $memoryMb,
            'cause' => $cause,
            'detail' => $detail,
        ];
    }

    /**
     * Sanitasi pesan galat: pola yang sama dengan `MonitorController` — token
     * server (bila dokumennya ada) + header/signature yang tercecer dibuang.
     *
     * @param array<string,mixed>|null $server
     */
    private function sanitize(string $message, ?array $server = null): string
    {
        $token = '';
        try {
            $token = ServerResolver::token($server ?? []);
        } catch (Throwable) {
            $token = '';
        }

        $clean = AgentError::sanitize($message, $token);

        return $clean === '' ? 'tanpa detail' : $clean;
    }
}
