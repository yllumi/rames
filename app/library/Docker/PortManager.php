<?php
declare(strict_types=1);

namespace app\library\Docker;

use RuntimeException;

/**
 * Deteksi & resolusi konflik host port (SPECS.md §7.2 langkah 6).
 */
class PortManager
{
    public function __construct(
        private readonly int $rangeStart,
        private readonly int $rangeEnd,
    ) {
    }

    /**
     * Semua host port yang sudah terpakai dari seluruh site.
     *
     * @param array<int,array> $sites
     * @return array<int,int>
     */
    public function usedHostPorts(array $sites): array
    {
        $used = [];
        foreach ($sites as $site) {
            foreach ($site['containers'] ?? [] as $container) {
                $port = $container['host_port'] ?? null;
                if ($port !== null && (int) $port > 0) {
                    $used[(int) $port] = (int) $port;
                }
            }
        }
        return array_values($used);
    }

    public function isConflict(int $port, array $usedPorts): bool
    {
        return in_array($port, $usedPorts, true);
    }

    /**
     * Cari port bebas pertama dalam rentang konfigurasi.
     */
    public function suggestPort(array $usedPorts): int
    {
        for ($p = $this->rangeStart; $p <= $this->rangeEnd; $p++) {
            if (!in_array($p, $usedPorts, true)) {
                return $p;
            }
        }
        throw new RuntimeException("Rentang port {$this->rangeStart}-{$this->rangeEnd} sudah penuh.");
    }

    /**
     * Isi host port yang belum ada (null) dan ganti yang konflik dengan saran
     * port baru — untuk SEMUA entry port. Mengembalikan service yang diperbarui.
     *
     * @param array<string,array> $services
     * @param array<int,int>      $usedPorts
     * @return array<string,array>
     */
    public function resolve(array $services, array $usedPorts): array
    {
        foreach ($services as $name => $svc) {
            $assignedFirst = false;
            foreach ($svc['ports'] as $i => &$entry) {
                $host = $entry['host'] ?? null;
                if ($host === null || $this->isConflict((int) $host, $usedPorts)) {
                    $host = $this->suggestPort($usedPorts);
                    $usedPorts[] = $host;
                } else {
                    $host = (int) $host;
                }
                $entry['host'] = $host;
                if (!$assignedFirst) {
                    $svc['host_port'] = $host;
                    $assignedFirst = true;
                }
            }
            unset($entry);
            $services[$name] = $svc;
        }
        return $services;
    }

    /**
     * Validasi port dari input user (integer, 1-65535).
     */
    public function validatePort(mixed $port): bool
    {
        if (!is_numeric($port)) {
            return false;
        }
        $port = (int) $port;
        return $port >= 1 && $port <= 65535;
    }
}
