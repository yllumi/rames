<?php
declare(strict_types=1);

namespace app\library\Docker;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parse docker-compose.yml untuk mengekstrak service + port mapping
 * (SPECS.md §7.2 langkah 5).
 */
class ComposeParser
{
    /**
     * @return array{services: array<string, array{internal_port:?int, host_port:?int, ports:array<int,array{host:?int,container:int,protocol:string}>}>}
     */
    public function parse(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("File compose tidak ditemukan: {$filePath}");
        }
        try {
            $data = Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            throw new RuntimeException('Gagal parse YAML: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || !isset($data['services']) || !is_array($data['services'])) {
            throw new RuntimeException('docker-compose.yml tidak memiliki bagian "services".');
        }

        $services = [];
        foreach ($data['services'] as $serviceName => $config) {
            if (!is_array($config)) {
                continue;
            }
            $ports = $this->extractPorts($config['ports'] ?? null);
            $first = $ports[0] ?? null;
            $services[(string) $serviceName] = [
                'internal_port' => $first['container'] ?? null,
                'host_port' => $first['host'] ?? null,
                'ports' => $ports,
            ];
        }

        if ($services === []) {
            throw new RuntimeException('docker-compose.yml tidak memiliki service yang valid.');
        }

        return ['services' => $services];
    }

    /**
     * @return array<int,array{host:?int,container:int,protocol:string}>
     */
    private function extractPorts(mixed $ports): array
    {
        $result = [];
        if (!is_array($ports)) {
            return $result;
        }
        foreach ($ports as $entry) {
            if (is_string($entry)) {
                $parsed = $this->parseShortSyntax($entry);
                if ($parsed !== null) {
                    $result[] = $parsed;
                }
            } elseif (is_array($entry)) {
                // Long syntax: {target: 80, published: 8080, protocol: tcp}
                $target = isset($entry['target']) ? (int) $entry['target'] : null;
                $published = isset($entry['published']) ? (int) $entry['published'] : null;
                if ($target !== null && $target > 0) {
                    $result[] = [
                        'host' => $published !== null && $published > 0 ? $published : null,
                        'container' => $target,
                        'protocol' => (string) ($entry['protocol'] ?? 'tcp'),
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * @return array{host:?int,container:int,protocol:string}|null
     */
    private function parseShortSyntax(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $parts = explode(':', $raw);
        $containerRaw = end($parts);
        $container = (int) $containerRaw;
        if ($container <= 0) {
            return null;
        }

        $host = null;
        if (count($parts) >= 2) {
            $hostRaw = $parts[count($parts) - 2];
            // abaikan IP binding ("127.0.0.1:8080:80" -> host = 8080)
            if (ctype_digit($hostRaw) && !str_contains($hostRaw, '-')) {
                $host = (int) $hostRaw;
            }
        }

        return ['host' => $host, 'container' => $container, 'protocol' => 'tcp'];
    }
}
