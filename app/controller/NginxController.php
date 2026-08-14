<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Nginx\NginxReloader;
use support\Request;

/**
 * Aksi Nginx (reload host dari dashboard).
 *
 * Dashboard tidak punya shell ke host; reload dijalankan lewat helper container
 * via Docker socket (NginxReloader). Controller hanya mediator.
 */
class NginxController
{
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
            $back = '/sites';
        }
        return redirect($back);
    }
}
