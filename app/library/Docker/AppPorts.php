<?php
declare(strict_types=1);

namespace app\library\Docker;

use app\library\Storage\AppStore;
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
 * App yang tidak mem-publish port sama sekali (mis. server database yang dikelola
 * lewat `/database`, SPECS §7.2) tidak punya target `proxy_pass`: `hasHostPort()`
 * false dan dashboard tidak membuat vhost/subdomain untuk app itu.
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

    // ==================================================================
    // Scope server (multi-server F1)
    // ==================================================================

    /**
     * Filter daftar app berdasarkan scope server.
     *
     * `null` = **semua server** (perilaku lama; fail-safe — pemanggil lama yang
     * belum server-aware tidak boleh kehilangan data). Bila diisi = hanya app di
     * server itu (id server dinormalisasi lewat {@see AppStore::serverIdOf()}).
     *
     * @param array<int,array> $apps
     * @return array<int,array>
     */
    public static function onServer(array $apps, ?string $serverId = null): array
    {
        if ($serverId === null) {
            return array_values(array_filter($apps, 'is_array'));
        }

        $scope = AppStore::normalizeServerId($serverId);
        $result = [];
        foreach ($apps as $app) {
            if (is_array($app) && AppStore::serverIdOf($app) === $scope) {
                $result[] = $app;
            }
        }

        return $result;
    }

    /**
     * Seluruh port publish semua app pada scope server (satu baris per port,
     * sudah termasuk identitas app pemiliknya).
     *
     * `$serverId` null = semua server (perilaku lama).
     *
     * @param array<int,array> $apps
     * @return array<int,array{id:string,name:string,server_id:string,service:string,container:int,host:int}>
     */
    public static function allOnServer(array $apps, ?string $serverId = null): array
    {
        $result = [];
        foreach (self::onServer($apps, $serverId) as $app) {
            foreach (self::all($app) as $port) {
                $result[] = [
                    'id' => (string) ($app['id'] ?? ''),
                    'name' => (string) ($app['name'] ?? ''),
                    'server_id' => AppStore::serverIdOf($app),
                    'service' => $port['service'],
                    'container' => $port['container'],
                    'host' => $port['host'],
                ];
            }
        }

        return $result;
    }

    /**
     * Host port yang sudah terpakai pada scope server — **tanpa duplikat**.
     *
     * `$serverId` null = semua server: himpunan port seluruh app, jadi sama
     * dengan perilaku sebelum multi-server (uji regresi eksplisit). Isi
     * `$serverId` = hanya port app di server itu, sehingga dua app dengan port
     * sama di **server berbeda** tidak dianggap bentrok.
     *
     * @param array<int,array> $apps
     * @return array<int,int>
     */
    public static function usedOn(array $apps, ?string $serverId = null): array
    {
        $used = [];
        foreach (self::allOnServer($apps, $serverId) as $port) {
            if ($port['host'] > 0) {
                $used[$port['host']] = $port['host'];
            }
        }

        return array_values($used);
    }

    /**
     * App pemilik `$hostPort` pada scope server (null = tidak ada bentrok).
     *
     * Dedupe/pemetaan port memakai {@see all()} sehingga host port dari data app
     * lama (`host_port`) maupun data Engine (`ports[]`) ikut terhitung. Port ≤ 0
     * (belum dipublikasikan) tidak pernah dianggap bentrok.
     *
     * @param array<int,array> $apps
     * @param string $excludeId id app yang boleh memakai port itu (app sendiri)
     * @return array|null app pemilik port
     */
    public static function conflictsOn(array $apps, int $hostPort, ?string $serverId = null, string $excludeId = ''): ?array
    {
        if ($hostPort <= 0) {
            return null;
        }

        foreach (self::onServer($apps, $serverId) as $app) {
            if ($excludeId !== '' && (string) ($app['id'] ?? '') === $excludeId) {
                continue;
            }
            foreach (self::all($app) as $port) {
                if ($port['host'] === $hostPort) {
                    return $app;
                }
            }
        }

        return null;
    }

    /**
     * Apakah app punya **host port** terpublish (target `proxy_pass` Nginx)?
     *
     * False = app tidak di-proxy ke domain (tidak ada vhost/subdomain): mis.
     * service database yang sengaja tidak menulis `ports:`, atau container yang
     * belum/tidak jalan sehingga Engine tidak melaporkan port apa pun. Deploy
     * TIDAK gagal karena kondisi ini (SPECS §7.2).
     *
     * @param array $app
     */
    public static function hasHostPort(array $app): bool
    {
        foreach (self::all($app) as $port) {
            if ($port['host'] > 0) {
                return true;
            }
        }

        return false;
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
