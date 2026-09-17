<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Docker\AppContainers;
use app\library\Docker\ContainerLogs;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Log container app (`docker logs`) untuk popup modal di halaman detail app.
 *
 * Controller hanya mediator: pengambilan log di DockerClient, pembersihan stream
 * multiplexed & normalisasi parameter di ContainerLogs, validasi kepemilikan
 * container di AppContainers.
 *
 * Otorisasi: ability `logs` (Viewer ke atas — membaca log tidak mengubah apa pun).
 * Nama container dari request selalu divalidasi milik app ini sebelum menyentuh
 * Docker Engine.
 */
class LogController
{
    /**
     * GET /api/apps/{id}/logs?container={nama}&tail={baris}
     */
    public function index(Request $request, string $id)
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied('logs', $id);
        }
        // ability `logs` = viewer ke atas; app user lain → 404 (tidak terbocor)
        AppAccess::require('logs', $app, current_user());

        $requested = trim((string) $request->get('container', ''));
        $container = $requested === ''
            ? AppContainers::defaultContainer($app)
            : AppContainers::resolve($app, $requested);
        if ($container === null) {
            return json(['code' => 404, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
        }

        $tail = ContainerLogs::normalizeTail($request->get('tail', ContainerLogs::DEFAULT_TAIL));
        $timestamps = (string) $request->get('timestamps', '1') !== '0';

        try {
            $raw = (new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 20))
                ->containerLogs($container, $tail, $timestamps);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => [
            'container' => $container,
            'tail' => $tail,
            'text' => ContainerLogs::demultiplex($raw),
            'at' => date('H:i:s'),
        ]]);
    }
}
