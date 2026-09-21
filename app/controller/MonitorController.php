<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Monitor\ResourceCollector;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Monitoring resource container & VM (SPECS §8d) — halaman global `/monitor`.
 *
 * Controller hanya mediator: pembacaan `/proc` host ada di
 * `System\HostUsage`, perhitungan stats container di `Docker\ContainerStats`,
 * dan penggabungan + aturan visibilitas di `Monitor\ResourceCollector`.
 *
 * Hak akses (bertingkat):
 *  - per container: hanya container milik app yang boleh diakses user
 *    (`AppAccess::visible()`); container eksternal (di luar compose project
 *    dashboard) hanya untuk admin;
 *  - total VM: admin melihat seluruh host, user lain melihat ringkasan host
 *    dengan daftar container terbatas pada app yang bisa diakses.
 *
 * Endpoint read-only (GET) → tidak ada CSRF, tanpa state di server
 * (tanpa cache/interval) sehingga aman pada worker Webman persistent.
 */
class MonitorController
{
    /**
     * Halaman `/monitor` — total VM + tabel resource seluruh container.
     *
     * Data diambil asinkron (AJAX) karena `stats` Engine butuh ±1 detik;
     * halaman tidak boleh menunggu Engine agar tetap responsif.
     */
    public function index(Request $request)
    {
        return view('monitor/index', [
            'pageTitle' => 'Monitor',
            'active' => 'monitor',
            'isAdmin' => is_admin(),
            'appCount' => count($this->visibleApps()),
            'pollMs' => max(0, (int) config('deploy.monitor_poll_ms', 7000)),
        ]);
    }

    /**
     * GET /api/monitor/overview — metrik host + resource container yang boleh
     * dilihat user (admin: seluruh container di host).
     */
    public function overview(Request $request)
    {
        $isAdmin = is_admin();
        $data = ResourceCollector::collect($this->visibleApps(), $isAdmin);

        return json(['code' => 0, 'data' => $data + [
            'scope' => $isAdmin ? 'all' : 'accessible',
            'at' => date('H:i:s'),
        ]]);
    }

    /**
     * GET /api/monitor/host — snapshot metrik host saja (dipanggil berkala oleh
     * halaman `/monitor` selama terbuka).
     *
     * Sengaja terpisah dari `overview()`: `stats` container memblokir ±1 detik
     * per container sedangkan `/proc` hanya pembacaan file lokal, sehingga
     * polling 5–10 detik tidak membebani daemon Docker. Hak akses sama untuk
     * semua user yang login (angka host bersifat ringkasan).
     */
    public function host(Request $request)
    {
        return json(['code' => 0, 'data' => [
            'host' => ResourceCollector::hostSnapshot(),
            'at' => date('H:i:s'),
        ]]);
    }

    /**
     * App yang boleh dilihat user saat ini (admin: semua app).
     *
     * @return array<int,array>
     */
    private function visibleApps(): array
    {
        return AppAccess::visible((new AppStore())->all(), current_user());
    }
}
