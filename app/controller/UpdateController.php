<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Update\RepoInfo;
use app\library\Update\UpdateChecker;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use RuntimeException;
use support\Request;

/**
 * Self-update dashboard (SPECS.md §7.8 / ARCHITECTURE.md §5.14).
 *
 * Panel UI-nya ada di halaman `/nginx` (halaman operasional host). Endpoint:
 *  - `GET  /api/update/status`   — status run + cek terakhir + ekor log (semua user login)
 *  - `POST /api/update/check`    — cek pembaruan (ls-remote) & simpan cache
 *  - `POST /api/update/start`    — jalankan update (admin)
 *  - `POST /api/update/rollback` — kembalikan ke versi sebelumnya (admin)
 *
 * Kenapa update dijalankan helper container: lihat `UpdateService`. Controller ini
 * hanya mediator — seluruh logika ada di `app/library/Update/`.
 */
class UpdateController
{
    /**
     * Status untuk polling UI. Sengaja TANPA preflight (yang menjalankan beberapa
     * perintah git) karena endpoint ini dipoll tiap beberapa detik saat update
     * berjalan — preflight hanya dihitung saat halaman /nginx dirender.
     */
    public function status(Request $request)
    {
        try {
            $service = $this->service();
            $panel = $service->panel(false);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => 'Gagal membaca status update: ' . $e->getMessage()]);
        }

        $logName = (string) ($panel['run']['log'] ?? '');

        return json([
            'code' => 0,
            'data' => [
                'run' => $panel['run'],
                'running' => $panel['running'],
                'check' => $panel['check'],
                'head' => $panel['head'],
                'branch' => $panel['branch'],
                'logs' => $panel['logs'],
                'log_tail' => $logName !== '' ? $service->state()->logTail($logName, 12000) : '',
            ],
        ]);
    }

    /**
     * Cek ketersediaan pembaruan (jaringan). Boleh dilakukan semua user login —
     * sifatnya pembacaan (`git ls-remote` tidak menyentuh repo lokal) dan sama
     * dengan yang dijalankan proses periodik `update-check`.
     */
    public function check(Request $request)
    {
        try {
            $checker = new UpdateChecker(
                new RepoInfo((string) config('deploy.update_path', base_path())),
                $this->state(),
                (string) config('deploy.update_branch', '')
            );
            $result = $checker->check();
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => 'Gagal memeriksa pembaruan: ' . $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'data' => $result,
            'msg' => $result['ok']
                ? ($result['update_available'] ? 'Ada pembaruan tersedia.' : 'Dashboard sudah versi terbaru.')
                : 'Pengecekan gagal: ' . (string) $result['error'],
        ]);
    }

    /**
     * Jalankan update (`git pull` + rebuild + recreate + verifikasi sehat).
     */
    public function start(Request $request)
    {
        if (!is_admin()) {
            return json(['code' => 403, 'msg' => 'Update dashboard hanya bisa dijalankan admin.']);
        }

        try {
            $result = $this->service()->start('update', (string) (current_user()['username'] ?? ''));
        } catch (RuntimeException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => 'Gagal memulai update: ' . $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'data' => $result,
            'msg' => 'Update dimulai. Dashboard akan di-recreate; halaman ini akan menyambung kembali otomatis.',
        ]);
    }

    /**
     * Kembalikan dashboard ke versi sebelum update terakhir.
     */
    public function rollback(Request $request)
    {
        if (!is_admin()) {
            return json(['code' => 403, 'msg' => 'Rollback dashboard hanya bisa dijalankan admin.']);
        }

        try {
            $result = $this->service()->start('rollback', (string) (current_user()['username'] ?? ''));
        } catch (RuntimeException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => 'Gagal memulai rollback: ' . $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'data' => $result,
            'msg' => 'Rollback dimulai ke versi sebelumnya.',
        ]);
    }

    private function service(): UpdateService
    {
        $repo = new RepoInfo((string) config('deploy.update_path', base_path()));

        return new UpdateService($repo, $this->state(), null, null, (string) config('deploy.update_branch', ''));
    }

    private function state(): UpdateState
    {
        return new UpdateState(
            (string) config('deploy.update_check_file', runtime_path('update/check.json')),
            (string) config('deploy.update_run_dir', runtime_path('logs/update'))
        );
    }
}
