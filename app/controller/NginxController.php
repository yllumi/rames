<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxStatusReader;
use support\Request;

/**
 * Halaman & aksi Nginx (status + reload host dari dashboard).
 *
 * Nginx bersifat GLOBAL (berlaku untuk semua app) — status & tombol reload
 * dipindah ke halaman tersendiri /nginx, bukan di detail app.
 */
class NginxController
{
    /**
     * GET /nginx — halaman status reload Nginx host terakhir + tombol Reload.
     */
    public function index(Request $request)
    {
        $status = (new NginxStatusReader((string) config('deploy.nginx_reload_status_file')))->lastReload();
        return view('nginx/index', ['status' => $status]);
    }

    /**
     * POST /nginx/reload — validasi (nginx -t) lalu reload (nginx -s reload)
     * pada nginx HOST. Hasil ditulis ke last-reload.json (dibaca NginxStatusReader).
     */
    public function reload(Request $request)
    {
        $result = (new NginxReloader())->reload();
        if ($result['ok']) {
            flash_set('success', $result['message']);
        } else {
            flash_set('error', 'Reload Nginx GAGAL: ' . ($result['error'] ?? 'unknown error'));
        }

        // kembali ke halaman asal (hanya path internal untuk hindari open redirect)
        $back = (string) $request->header('referer', '');
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || str_starts_with($back, '/\\')) {
            $back = '/apps';
        }
        return redirect($back);
    }
}
