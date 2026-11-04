<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Billing\InsufficientCredits;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\DeployCanceller;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\EnvManager;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\ServerResolver;
use app\library\Git\SshKeyManager;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;

/**
 * Aksi siklus hidup app: rebuild, cancel, rollback, stop, start, delete (§7).
 *
 * Route: `POST /apps/{id}/rebuild`, `/deploy/cancel`, `/rollback`, `/stop`,
 * `/start`, `/delete`. Seluruh keputusan ada di `app/library/Deploy/**`.
 */
class DeploymentController extends AppControllerBase
{
    /**
     * Pesan penolakan pra-deploy untuk aksi yang membaca artefak app **di host
     * ini** (rebuild/rollback) — `null` bila aksi boleh dilanjutkan.
     *
     * Multi-server F1: app milik server **agent** **tidak** diperiksa —
     * checkout repo-nya hidup di server itu (`AgentArtifactMaterializer`), jadi
     * ketiadaan direktori lokal bukan kesalahan dan bukan alasan menolak; worker
     * yang mengerjakan aksinya lewat `AgentDeployer` di server target. Server
     * lokal mempertahankan pesan lamanya persis.
     *
     * @param array<string,mixed> $app
     * @param string              $feature nama aksi untuk pesan galat
     */
    private function localArtifactsError(array $app, string $feature): ?string
    {
        try {
            if (!ServerResolver::isLocal($this->serverForApp($app))) {
                return null;
            }
        } catch (\Throwable $e) {
            // `server_id` app menunjuk server yang hilang dari registry: tolak
            // eksplisit (bukan 500 mentah) — jangan menyentuh host yang salah.
            return $feature . ' tidak bisa dijalankan: ' . $e->getMessage();
        }

        $dir = (string) config('deploy.apps_path') . '/' . (string) ($app['name'] ?? '');

        return is_dir($dir) ? null : 'Direktori app tidak ada. App mungkin sudah dihapus.';
    }

    public function rebuild(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        // App impor dikelola `docker compose` di terminal user — Rames tidak
        // menulis override & tidak menciptakan ulang container.
        try {
            ImportedApp::assertManageable($app, 'Rebuild');
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Tolak rebuild ganda saat app sedang diproses worker lain.
        if (($app['status'] ?? '') === 'deploying') {
            $msg = 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg, 'busy' => true]);
            }
            flash_set('error', $msg);
            return redirect('/apps/' . $id);
        }

        $blocked = $this->localArtifactsError($app, 'Rebuild');
        if ($blocked !== null) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $blocked]);
            }
            flash_set('error', $blocked);
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum mengubah status / spawn worker.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        $store->update($id, function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rebuild ...';
            $s['error'] = null;
        });

        if (!$this->spawnWorker($id, 'rebuild', null, $this->currentActorId())) {
            $store->update($id, function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rebuild.';
                $s['error'] = 'Gagal spawn worker rebuild.';
            });
            $msg = 'Gagal menjalankan rebuild.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);
        } else {
            if ($request->expectsJson()) {
                return json([
                    'code' => 0,
                    'status' => 'deploying',
                    'stage' => 'queued',
                    'message' => 'Rebuild dimulai.',
                ]);
            }
            flash_set('success', 'Rebuild dijalankan.');
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Batalkan deploy/rebuild/rollback yang sedang berjalan.
     *
     * Bukan sekadar reset status: `DeployCanceller` menghentikan worker (grup
     * proses, termasuk `docker compose`/build) dan — untuk app server agent —
     * membatalkan job di agent lebih dulu. Status app dipulihkan (`running`
     * bila container lama masih hidup, selain itu `stopped`) sehingga UI tidak
     * tertahan di `deploying` meski worker sudah mati tanpa jejak.
     *
     * Otorisasi: ability `deploy` (Operator ke atas) — sama dengan tombol
     * rebuild/rollback. App tanpa hak tetap **404** lewat `findApp()`.
     */
    public function cancelDeploy(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        if (($app['status'] ?? '') !== 'deploying') {
            $msg = 'Tidak ada deploy yang sedang berjalan untuk app ini.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg, 'idle' => true]);
            }
            flash_set('error', $msg);

            return redirect('/apps/' . $id);
        }

        // Job di server agent harus dihentikan di sana; bila konfigurasi server
        // cacat, jangan gagalkan pembatalan worker lokal — laporkan sebagai
        // peringatan (build di agent mungkin masih berjalan).
        $agent = null;
        $agentError = '';
        try {
            $agent = AgentDeployer::forApp($app);
        } catch (\Throwable $e) {
            $agentError = $this->appErrorText($app, $e);
        }

        $user = current_user();
        $actor = trim((string) ($user['username'] ?? ($user['id'] ?? '')));

        try {
            $result = $this->deployCanceller($app)->cancelApp($app, $agent, $actor);
        } catch (\Throwable $e) {
            $msg = 'Gagal membatalkan deploy: ' . $this->appErrorText($app, $e);
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);

            return redirect('/apps/' . $id);
        }

        // Peringatan bisa berasal dari agent (mis. `cancelJob` transport) —
        // identitas server tetap wajib dibuang sebelum masuk flash/JSON.
        $warnings = array_map(
            fn (string $warning): string => $this->appErrorText($app, $warning),
            $result['warnings']
        );
        if ($agentError !== '') {
            $warnings[] = 'Server agent bermasalah: ' . $agentError;
        }

        $summary = 'Deploy dibatalkan (' . ($result['killed'] > 0 ? $result['killed'] . ' proses dihentikan' : 'tidak ada proses aktif') . ').';
        if ($warnings !== []) {
            $summary .= ' ' . implode(' ', $warnings);
        }

        if ($request->expectsJson()) {
            flash_set($warnings === [] ? 'success' : 'error', $summary);

            return json([
                'code' => 0,
                'status' => $result['status'],
                'message' => $summary,
                'warnings' => $warnings,
            ]);
        }

        flash_set($warnings === [] ? 'success' : 'error', $summary);

        return redirect('/apps/' . $id);
    }

    /**
     * Seam uji: canceller dengan proses/Engine palsu. Produksi: implementasi
     * bawaan (proc nyata + Docker Engine host ini).
     *
     * @param array<string,mixed> $app
     */
    protected function deployCanceller(array $app): DeployCanceller
    {
        return new DeployCanceller();
    }

    /**
     * Rollback app ke versi (commit) yang pernah sukses.
     *
     * Ref divalidasi: harus ada di deploy_history dengan status sukses/restored
     * dan bukan versi yang sedang aktif. Ditandai busy (status deploying) agar
     * tidak bentrok dengan deploy/rebuild/rollback lain, lalu worker di-spawn.
     */
    public function rollback(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        try {
            ImportedApp::assertManageable($app, 'Rollback');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (ComposeSource::isCompose($app)) {
            flash_set('error', 'App ini dibuat dari file compose (tanpa repo Git) — rollback versi tidak tersedia. Gunakan tab Compose untuk mengubah compose lalu Deploy Ulang.');
            return redirect('/apps/' . $id);
        }
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $ref = trim((string) $request->post('ref', ''));
        if ($ref === '' || !preg_match('/^[0-9a-fA-F]{7,40}$/', $ref)) {
            flash_set('error', 'Ref rollback tidak valid.');
            return redirect('/apps/' . $id);
        }

        // Cari di history — resolve ke full SHA bila user kirim short SHA.
        $fullRef = '';
        foreach (($app['deploy_history'] ?? []) as $h) {
            if (in_array(($h['status'] ?? ''), ['success', 'restored'], true) && str_starts_with((string) ($h['sha'] ?? ''), $ref)) {
                $fullRef = (string) $h['sha'];
                break;
            }
        }
        if ($fullRef === '') {
            flash_set('error', 'Ref tidak ditemukan di riwayat deploy yang sukses.');
            return redirect('/apps/' . $id);
        }

        // Tolak rollback ke versi yang sedang aktif (no-op).
        if ($fullRef === $this->resolveActiveSha($app)) {
            flash_set('error', 'Ref yang dipilih adalah versi yang sedang aktif.');
            return redirect('/apps/' . $id);
        }

        $blocked = $this->localArtifactsError($app, 'Rollback');
        if ($blocked !== null) {
            flash_set('error', $blocked);
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum mengubah status / spawn worker rollback.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        $store->update($id, function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rollback ...';
            $s['error'] = null;
        });

        if (!$this->spawnWorker($id, 'rollback', $fullRef, $this->currentActorId())) {
            $store->update($id, function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rollback.';
                $s['error'] = 'Gagal spawn worker rollback.';
            });
            flash_set('error', 'Gagal menjalankan rollback.');
        } else {
            flash_set('success', 'Rollback ke ' . substr($fullRef, 0, 7) . ' dijalankan.');
        }
        return redirect('/apps/' . $id);
    }

    public function stop(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'stop');
        try {
            DeployerFactory::createForApp($app)->stop($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'stopped';
                $s['message'] = 'Stopped';
            });
            flash_set('success', 'App dihentikan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal stop: ' . $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    public function start(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'stop');

        // Gerbang kredit — sebelum menyalakan container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            DeployerFactory::createForApp($app)->start($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'running';
                $s['message'] = 'Running';
            });
            flash_set('success', 'App dijalankan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal start: ' . $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    public function delete(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'delete');

        // App impor = project compose milik user di terminal. "Hapus" berarti
        // MELEPAS record dari dashboard saja: container/volume/berkas proyek
        // TIDAK disentuh (tanpa teardown, tanpa cleanupDir, tanpa volume).
        if (ImportedApp::isImported($app)) {
            try {
                $server = (new ServerResolver())->findForApp($app);
                if ($server !== null) {
                    // Buang vhost app bila pernah dibuat lewat domain/SSL.
                    DeployerFactory::createFor($server)->removeNginxConfig($app);
                }
                (new EnvManager())->remove((string) $app['name']);
                $store->delete($id);
                flash_set('success', 'App "' . $app['name'] . '" dilepas dari dashboard. Container TIDAK dihentikan/dihapus — kelola project compose di terminal proyek itu.');
            } catch (\Throwable $e) {
                flash_set('error', 'Gagal menghapus: ' . $this->appErrorText($app, $e));
            }

            return redirect('/apps');
        }

        // mode: preserve (default, aman — volume tercentang dipertahankan) atau
        // purge (hapus total termasuk semua volume).
        $mode = (string) $request->post('mode', 'preserve');
        $preserve = array_values(array_filter(
            array_map('strval', (array) $request->post('preserve_volumes', [])),
            static fn (string $v): bool => $v !== ''
        ));

        $orphanServerId = null;

        try {
            // Server pemilik app bisa SUDAH dihapus dari halaman Servers
            // (registry tidak lagi memuat barisnya). App seperti itu yatim:
            // tidak ada jalur jaringan untuk teardown, jadi teardown remote
            // DILEWATI — DILARANG jatuh ke server lokal — dan record app tetap
            // bisa dibersihkan. Registry yang tidak terbaca tetap melempar
            // (`findForApp()` hanya `null` untuk baris yang benar-benar hilang).
            $server = (new ServerResolver())->findForApp($app);

            if ($server === null) {
                $orphanServerId = trim((string) ($app['server_id'] ?? ''));
            } else {
                // Satu deployer untuk server pemilik app: app di server agent
                // di-teardown LEWAT AGENT (job `teardown`), tidak pernah di host ini.
                // `purge` ⇒ null (down -v: semua volume termasuk anonymous ikut
                // terhapus); default aman ⇒ pertahankan volume tercentang.
                DeployerFactory::createFor($server)->teardown($app, $mode === 'purge' ? null : $preserve);
            }

            $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
            if (is_dir($dir)) {
                $this->cleanupDir($dir);
            }

            // Bersihkan pasangan kunci SSH deploy key app
            if (($app['auth_method'] ?? 'none') === 'ssh') {
                (new SshKeyManager())->remove($app['name']);
            }

            // Bersihkan managed env file app (override env di dalam dir app
            // ikut terhapus bersama cleanupDir).
            (new EnvManager())->remove($app['name']);

            $store->delete($id);

            if ($orphanServerId !== null) {
                flash_set('info', 'App "' . $app['name'] . '" dihapus dari dashboard. Server "'
                    . ($orphanServerId !== '' ? $orphanServerId : '(kosong)')
                    . '" sudah tidak ada di registry, jadi teardown container/volume di server itu '
                    . 'TIDAK dijalankan — bersihkan manual di mesin tersebut (mis. '
                    . 'docker compose -p <project> down -v) bila mesinnya masih hidup.');
            } else {
                flash_set('success', 'App "' . $app['name'] . '" dihapus.');
            }
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus: ' . $this->appErrorText($app, $e));
        }
        return redirect('/apps');
    }
}
