<?php
declare(strict_types=1);

namespace app\library\Docker;

/**
 * Penafsiran respons Docker Engine untuk monitoring resource container
 * (SPECS §8d): `GET /containers/{id}/stats` + `GET /containers/{id}/json`.
 *
 * Murni statik tanpa I/O (pola `VolumeUsage`/`ContainerLogs`) — pengambilan data
 * dilakukan `DockerClient`, kelas ini hanya menghitung & memetakan, sehingga
 * rumusnya bisa diuji tanpa daemon Docker.
 *
 * Catatan cgroup: Docker melaporkan `memory_stats.usage` **termasuk page cache**,
 * jadi nilai itu harus dikurangi `inactive_file` (cgroup v2) / `total_inactive_file`
 * (cgroup v1) agar angkanya setara dengan `docker stats`.
 */
final class ContainerStats
{
    /** Batas atas yang dianggap "tanpa limit" (Linux melaporkan ~2^63 bila bebas). */
    private const UNLIMITED_LIMIT = 1 << 60;

    /**
     * Petakan stats (+ inspect opsional) menjadi satu baris monitoring.
     *
     * Semua field bernilai null bila datanya tidak tersedia (container berhenti,
     * Engine menolak, dsb.) — UI menampilkan "—" alih-alih 0 yang menyesatkan.
     *
     * @param array $stats   respons `GET /containers/{id}/stats?stream=false`
     * @param array $inspect respons `GET /containers/{id}/json` (status/uptime/restart/health)
     * @return array{
     *   cpu_percent:?float, mem_used:?int, mem_limit:?int, mem_percent:?float,
     *   pids:?int, status:?string, uptime_seconds:?int, restart_count:?int, health:?string
     * }
     */
    public static function map(array $stats, array $inspect = []): array
    {
        return [
            'cpu_percent' => self::cpuPercent($stats),
            'mem_used' => self::memUsed($stats),
            'mem_limit' => self::memLimit($stats),
            'mem_percent' => self::memPercent($stats),
            'pids' => self::pids($stats),
            'status' => self::status($inspect),
            'uptime_seconds' => self::uptimeSeconds($inspect),
            'restart_count' => isset($inspect['RestartCount']) ? (int) $inspect['RestartCount'] : null,
            'health' => self::health($inspect),
        ];
    }

    /**
     * Pemakaian CPU (%) ala `docker stats`:
     * `(cpu_delta / system_delta) × jumlah CPU online × 100`.
     *
     * Butuh DUA sampel (Engine mengambilnya saat `stream=false`; `precpu_stats`).
     * Container yang benar-benar tidak memakai CPU dalam jendela sampling
     * dilaporkan `0.0` — bukan null — supaya UI tidak menyamakan "idle" dengan
     * "tidak diketahui". `null` hanya untuk data yang memang tak ada: jendela
     * sampling kosong (`system_delta` ≤ 0) atau counter turun (container baru
     * di-restart antar sampel).
     */
    public static function cpuPercent(array $stats): ?float
    {
        $usage = $stats['cpu_stats']['cpu_usage']['total_usage'] ?? null;
        $usagePrev = $stats['precpu_stats']['cpu_usage']['total_usage'] ?? null;
        $system = $stats['cpu_stats']['system_cpu_usage'] ?? null;
        $systemPrev = $stats['precpu_stats']['system_cpu_usage'] ?? null;

        if ($usage === null || $usagePrev === null || $system === null || $systemPrev === null) {
            return null;
        }

        $cpuDelta = (int) $usage - (int) $usagePrev;
        $systemDelta = (int) $system - (int) $systemPrev;
        if ($cpuDelta < 0 || $systemDelta <= 0) {
            return null;
        }

        $cpu = $stats['cpu_stats'] ?? [];
        $online = (int) ($cpu['online_cpus'] ?? 0);
        if ($online <= 0) {
            $percpu = $cpu['cpu_usage']['percpu_usage'] ?? null;
            $online = is_array($percpu) && $percpu !== [] ? count($percpu) : 1;
        }

        return round($cpuDelta / $systemDelta * $online * 100, 2);
    }

    /**
     * Memori terpakai (byte) = `usage` dikurangi page cache.
     */
    public static function memUsed(array $stats): ?int
    {
        $mem = $stats['memory_stats'] ?? [];
        if (!isset($mem['usage'])) {
            return null;
        }
        $usage = (int) $mem['usage'];
        $cache = (int) ($mem['stats']['inactive_file']
            ?? $mem['stats']['total_inactive_file']
            ?? $mem['stats']['cache']
            ?? 0);
        // cache bisa lebih besar dari usage pada beberapa kernel → jangan negatif
        if ($cache < 0 || $cache > $usage) {
            $cache = 0;
        }

        return max(0, $usage - $cache);
    }

    /**
     * Batas memori container (byte); null bila tidak dibatasi.
     */
    public static function memLimit(array $stats): ?int
    {
        $limit = (int) ($stats['memory_stats']['limit'] ?? 0);
        if ($limit <= 0 || $limit >= self::UNLIMITED_LIMIT) {
            return null;
        }

        return $limit;
    }

    /**
     * Persentase pemakaian memori terhadap limit; null bila tanpa limit.
     */
    public static function memPercent(array $stats): ?float
    {
        $used = self::memUsed($stats);
        $limit = self::memLimit($stats);
        if ($used === null || $limit === null) {
            return null;
        }

        return round(min(100.0, $used / $limit * 100), 1);
    }

    /**
     * Jumlah proses di dalam container (`pids_stats.current`).
     */
    public static function pids(array $stats): ?int
    {
        $pids = $stats['pids_stats']['current'] ?? null;

        return $pids === null ? null : max(0, (int) $pids);
    }

    /**
     * Status container dari inspect (`State.Status`).
     */
    public static function status(array $inspect): ?string
    {
        $status = $inspect['State']['Status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Uptime container (detik) dihitung dari `State.StartedAt`; null bila
     * container tidak sedang berjalan (waktu mulai bukan uptime).
     */
    public static function uptimeSeconds(array $inspect): ?int
    {
        $state = $inspect['State'] ?? [];
        if (!($state['Running'] ?? false)) {
            return null;
        }
        $startedAt = (string) ($state['StartedAt'] ?? '');
        $started = $startedAt !== '' ? strtotime($startedAt) : false;
        if ($started === false) {
            return null;
        }

        return max(0, time() - $started);
    }

    /**
     * Status healthcheck (`State.Health.Status`): healthy|unhealthy|starting.
     * Null bila container tidak punya healthcheck.
     */
    public static function health(array $inspect): ?string
    {
        $health = $inspect['State']['Health']['Status'] ?? null;

        return is_string($health) && $health !== '' ? $health : null;
    }

    /**
     * Total resource dari sekumpulan baris monitoring (kartu ringkas).
     *
     * `mem_limit` hanya dijumlahkan bila SEMUA container punya limit — kalau ada
     * satu saja tanpa limit, total limit bukan angka yang bermakna (null).
     *
     * @param array<int,array> $rows baris hasil `map()`
     * @return array{cpu_percent:float,containers:int,running:int,mem_used:int,mem_limit:?int,mem_percent:?float}
     */
    public static function aggregate(array $rows): array
    {
        $cpu = 0.0;
        $memUsed = 0;
        $memLimit = 0;
        $allLimited = $rows !== [];
        $running = 0;

        foreach ($rows as $row) {
            $cpu += (float) ($row['cpu_percent'] ?? 0);
            $memUsed += (int) ($row['mem_used'] ?? 0);

            $limit = $row['mem_limit'] ?? null;
            if ($limit === null) {
                $allLimited = false;
            } else {
                $memLimit += (int) $limit;
            }

            if (($row['status'] ?? '') === 'running' || ($row['state'] ?? '') === 'running') {
                $running++;
            }
        }

        $memLimit = $allLimited && $memLimit > 0 ? $memLimit : null;

        return [
            'cpu_percent' => round($cpu, 1),
            'containers' => count($rows),
            'running' => $running,
            'mem_used' => $memUsed,
            'mem_limit' => $memLimit,
            'mem_percent' => $memLimit === null ? null : round(min(100.0, $memUsed / $memLimit * 100), 1),
        ];
    }
}
