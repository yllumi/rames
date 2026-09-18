<?php
declare(strict_types=1);

namespace app\library\Docker;

use RuntimeException;

/**
 * Resolusi port aplikasi: daftar port yang dipublikasikan container app dan
 * penentuan **port yang di-proxy Nginx** (`proxy_pass` ke `127.0.0.1:{host_port}`).
 *
 * Satu service bisa mempublikasikan lebih dari satu port (mis. web 9119 +
 * gateway API 8642). Dashboard hanya me-reverse-proxy **satu** port — dipilih
 * user saat create (`primary_port` = port container). Port lain tetap
 * dipublikasikan compose ke host port masing-masing dan diakses langsung
 * (http://host:port).
 *
 * Data dibaca dari `apps.json`:
 *   containers[].ports[] = {host, container}   (semua port publish)
 *   containers[].host_port / internal_port     (port pertama — data app lama)
 *   primary_service, primary_port
 *
 * Stateless (murni statik, tanpa I/O) — aman untuk worker Webman persistent.
 */
final class AppPorts
{
    /**
     * Semua port app (gabungan seluruh container), urut sesuai data container.
     *
     * @param array $app
     * @return array<int,array{service:string,container:int,host:int}>
     */
    public static function all(array $app): array
    {
        $result = [];
        foreach ((array) ($app['containers'] ?? []) as $container) {
            if (!is_array($container)) {
                continue;
            }
            $service = (string) ($container['service_name'] ?? '');
            foreach (self::forContainer($container) as $port) {
                $result[] = [
                    'service' => $service,
                    'container' => $port['container'],
                    'host' => $port['host'],
                ];
            }
        }

        return $result;
    }

    /**
     * Port satu container — **tanpa duplikat**.
     *
     * Docker Engine mengembalikan satu entri per alamat IP untuk publish
     * dual-stack (IPv4 `0.0.0.0` + IPv6 `::`) dengan PublicPort/PrivatePort yang
     * sama, sehingga tanpa dedupe port yang sama tampil dua kali. Duplikat
     * dibuang berdasarkan pasangan (container port, host port).
     *
     * Fallback ke field lama (`internal_port`/`host_port`) untuk data app yang
     * dibuat sebelum field `ports` ada.
     *
     * @param array $container
     * @return array<int,array{host:int,container:int}>
     */
    public static function forContainer(array $container): array
    {
        $result = [];
        $seen = [];

        foreach ((array) ($container['ports'] ?? []) as $port) {
            if (!is_array($port) || empty($port['container'])) {
                continue;
            }
            $containerPort = (int) $port['container'];
            $host = (int) ($port['host'] ?? 0);
            if ($host <= 0) {
                $host = self::matchHostPort($container, $containerPort);
            }

            $key = $containerPort . ':' . $host;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['host' => $host, 'container' => $containerPort];
        }

        if ($result === [] && !empty($container['internal_port'])) {
            $result[] = [
                'host' => (int) ($container['host_port'] ?? 0),
                'container' => (int) $container['internal_port'],
            ];
        }

        return $result;
    }

    /**
     * Port container yang menerima trafik domain (di-proxy Nginx):
     *  - `primary_port` bila port itu masih dipublikasikan container app;
     *  - fallback: port pertama milik `primary_service` (perilaku app lama).
     *
     * @param array $app
     * @return int 0 bila app tidak punya port
     */
    public static function proxiedContainerPort(array $app): int
    {
        $ports = self::all($app);
        $service = (string) ($app['primary_service'] ?? '');
        $wanted = (int) ($app['primary_port'] ?? 0);

        if ($wanted > 0) {
            foreach ($ports as $port) {
                if ($port['container'] === $wanted && ($service === '' || $port['service'] === $service)) {
                    return $wanted;
                }
            }
        }

        foreach ($ports as $port) {
            if ($service === '' || $port['service'] === $service) {
                return $port['container'];
            }
        }

        return 0;
    }

    /**
     * Host port target config Nginx.
     *
     * @throws RuntimeException bila app tidak punya host port sama sekali
     */
    public static function primaryHostPort(array $app): int
    {
        $ports = self::all($app);
        $service = (string) ($app['primary_service'] ?? '');
        $target = self::proxiedContainerPort($app);

        foreach ($ports as $port) {
            if ($target > 0 && $port['container'] === $target && ($service === '' || $port['service'] === $service) && $port['host'] > 0) {
                return $port['host'];
            }
        }
        // Port pilihan tidak (lagi) punya host port → pakai port lain yang tersedia.
        foreach ($ports as $port) {
            if (($service === '' || $port['service'] === $service) && $port['host'] > 0) {
                return $port['host'];
            }
        }

        throw new RuntimeException(
            'Tidak ada host port untuk app "' . ($app['name'] ?? '') . '"'
            . ($target > 0 ? ' (port container ' . $target . ')' : '') . '.'
        );
    }

    /**
     * Host port untuk satu container port (0 bila tidak ada).
     */
    public static function hostPortFor(array $app, int $containerPort): int
    {
        foreach (self::all($app) as $port) {
            if ($port['container'] === $containerPort) {
                return $port['host'];
            }
        }
        return 0;
    }

    /**
     * Cocokkan host port dari field lama (`host_port`/`internal_port`) bila
     * entri `ports[]` tidak memuat host (mis. data hasil versi lama).
     */
    private static function matchHostPort(array $container, int $containerPort): int
    {
        if ((int) ($container['internal_port'] ?? 0) === $containerPort) {
            return (int) ($container['host_port'] ?? 0);
        }
        return 0;
    }
}
