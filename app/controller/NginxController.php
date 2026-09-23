<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxStatusReader;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use support\Request;

/**
 * Halaman & aksi Nginx (status + reload host dari dashboard).
 *
 * Nginx bersifat GLOBAL (berlaku untuk semua app) — status & tombol reload
 * dipindah ke halaman tersendiri /nginx, bukan di detail app.
 *
 * Halaman ini juga menampung panel **self-update dashboard** (SPECS.md §7.8):
 * halaman operasional host, sehingga tidak perlu menu nav baru.
 */
class NginxController
{
    /**
     * GET /nginx — status reload Nginx host terakhir + tombol Reload + panel update.
     */
    public function index(Request $request)
    {
        $status = (new NginxStatusReader((string) config('deploy.nginx_reload_status_file')))->lastReload();
        return view('nginx/index', ['status' => $status, 'update' => $this->updatePanel()]);
    }

    /**
     * Data panel self-update; `null` bila fitur dimatikan, atau memuat kunci
     * `failed` bila pembacaannya gagal (halaman tetap tampil, tidak 500).
     *
     * @return array<string,mixed>|null
     */
    private function updatePanel(): ?array
    {
        if (!(bool) config('deploy.update_enabled', true)) {
            return null;
        }

        try {
            $service = new UpdateService(
                new RepoInfo((string) config('deploy.update_path', base_path())),
                new UpdateState(
                    (string) config('deploy.update_check_file', runtime_path('update/check.json')),
                    (string) config('deploy.update_run_dir', runtime_path('logs/update'))
                ),
                null,
                null,
                (string) config('deploy.update_branch', '')
            );

            $panel = $service->panel();
            // Ekor log run terakhir untuk tampilan awal panel (JS menyegarkannya
            // lewat /api/update/status saat update berjalan).
            $logName = (string) ($panel['run']['log'] ?? '');
            $panel['log_tail'] = $logName !== '' ? $service->state()->logTail($logName, 12000) : '';

            return $panel;
        } catch (\Throwable $e) {
            return ['failed' => $e->getMessage()];
        }
    }

    /**
     * POST /nginx/reload — validasi (nginx -t) lalu reload (nginx -s reload)
     * pada nginx HOST. Hasil ditulis ke last-reload.json (dibaca NginxStatusReader).
     */
    public function reload(Request $request)
    {
        if (!is_admin()) {
            flash_set('error', 'Reload Nginx berlaku untuk seluruh host — hanya admin yang boleh melakukannya.');
            return redirect('/nginx');
        }

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
