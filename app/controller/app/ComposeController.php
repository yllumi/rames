<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Billing\InsufficientCredits;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\ServerResolver;
use app\library\Docker\PortManager;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;
use support\Request;

/**
 * Editor compose app mode compose (§7).
 *
 * Route: `POST /apps/{id}/compose`.
 */
class ComposeController extends AppArtifactControllerBase
{
    /**
     * Simpan perubahan compose app mode compose (POST /apps/{id}/compose) —
     * tab "Compose" di detail app.
     *
     * Dua jalur, dipilih dari **server pemilik app**:
     *  - server **lokal** (perilaku lama, tidak berubah): validasi → rencana host
     *    port → tulis file compose + berkas pendukung di `apps/<name>` → tulis
     *    ulang override port (2 lapis) → persist + spawn worker `apply`.
     *  - server **agent**: stdout compose disimpan sebagai metadata app
     *    (`compose_content`) + rencana host port (`host_ports`), lalu worker
     *    `apply` mengirimnya ke agent lewat `artifacts.compose`/`artifacts.files`
     *    (`AgentArtifactPackager` → `AgentArtifactMaterializer`). Host ini
     *    **tidak** menulis direktori/berkas artefak app agent; nama file utama
     *    tetap mengikuti aturan repo ({@see ComposeSource::resolveMainFile()}).
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi tidak
     *                                            pernah mengisinya
     */
    public function saveCompose(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');

        try {
            ImportedApp::assertManageable($app, 'Editor compose');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (ComposeSource::isGit($app)) {
            flash_set('error', 'App ini dibuat dari repo Git — perbarui source lewat Rebuild (git pull).');
            return redirect('/apps/' . $id);
        }
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis file compose / mengubah apps.json /
        // spawn worker `apply` (jalur ignition yang membuat ulang container).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];

        // Server pemilik app (fail-fast: `server_id` menunjuk server yang hilang).
        try {
            $server = $this->serverForApp($app);
        } catch (\Throwable $e) {
            flash_set('error', 'Compose tidak bisa disimpan: ' . $e->getMessage());
            return redirect('/apps/' . $id);
        }

        if (!ServerResolver::isLocal($server)) {
            return $this->saveComposeOnAgent($request, $app, $store, $launcher);
        }

        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        try {
            $content = (string) $request->post('compose', '');
            $mainFile = ComposeSource::resolveMainFile($dir, $app);

            // 1) Validasi & rencana port DULU (belum ada file/state yang diubah).
            ComposeSource::assertDeployable($content);
            $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
            $services = ComposeSource::planHostPorts(
                ComposeSource::parseContent($content),
                ComposeSource::existingHostPorts($app),
                $this->usedHostPortsExcept($id),
                (int) $range['start'],
                (int) $range['end']
            );
            $primaryPortSelection = $this->resolvePrimarySelection(
                $services,
                '',
                (string) ($app['primary_service'] ?? ''),
                (int) ($app['primary_port'] ?? 0)
            );
            $primary = $primaryPortSelection['service'];
            $primaryPort = $primaryPortSelection['port'];

            // 2) Tulis file: compose utama (divalidasi ulang oleh store) +
            //    file pendukung baru (upload), lalu hapus file tercentang.
            $uploads = $this->uploadsFrom($request);
            ComposeSource::store(
                $dir,
                $uploads,
                $content,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES),
                $mainFile
            );
            $delete = array_values(array_filter(
                array_map('strval', (array) $request->post('file_delete', [])),
                static fn (string $f): bool => $f !== ''
            ));
            $removed = ComposeSource::removeFiles($dir, $delete, $mainFile);

            // 3) Tulis ulang override port; override lain (network/env) tetap
            //    di urutan belakang agar prioritas compose tidak berubah.
            $composeFiles = [$mainFile];
            $this->writeOverride(
                ['name' => $app['name']],
                $services,
                $composeFiles,
                ContainerNames::normalizePrefix($app['container_prefix'] ?? '')
            );
            $composeFiles = array_values(array_unique(array_merge($composeFiles, $this->generatedExtras($app))));

            // 4) Persist + spawn worker apply (up -d tanpa build + Nginx).
            $store->update($id, function (array &$s) use ($composeFiles, $primary, $primaryPort): void {
                $s['compose_files'] = $composeFiles;
                $s['primary_service'] = $primary;
                $s['primary_port'] = $primaryPort;
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan perubahan compose ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply', null, $this->currentActorId(), $launcher)) {
                $store->update($id, function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
                flash_set('error', 'Compose tersimpan, tetapi gagal menjalankan deploy ulang.');
                return redirect('/apps/' . $id);
            }

            $extra = $removed !== [] ? ' ' . count($removed) . ' file dihapus.' : '';
            flash_set('success', 'Compose disimpan — container diciptakan ulang di latar belakang.' . $extra);
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan compose app yang tinggal di server **agent** (misi F1 celah A).
     *
     * Perbedaan pokok dari jalur lokal: host ini **tidak** memiliki (dan tidak
     * membuat) direktori artefak app agent, jadi compose hasil edit disimpan
     * sebagai metadata app (`compose_content`) + rencana host port (`host_ports`)
     * dan dikirim worker `apply` lewat `artifacts.compose`/`artifacts.files`;
     * `AgentArtifactMaterializer` di agent yang menulis berkasnya (compose +
     * override port) sebelum worker agent berjalan — "simpan dulu, baru up -d".
     *
     * Urutan fail-fast: validasi isi compose & bentuk berkas unggahan **sebelum**
     * `apps.json` disentuh; job hanya di-spawn bila semuanya sah. `apply` dipakai
     * (bukan `deploy`) supaya tidak ada build di server app.
     *
     * Catatan urutan persist: seperti jalur lokal, `compose_content` +
     * `compose_files` + rencana port ditulis **sebelum** worker di-spawn — payload
     * job membawa dokumen app itu (`AgentDeployer` → proyeksi agent), dan packager
     * membaca rencana port dari sana. Worker gagal ⇒ status app ditandai `error`
     * (perilaku yang sama dengan jalur lokal), bukan rollback metadata.
     *
     * @param array<string,mixed>       $app      dokumen app (server agent)
     * @param DeployWorkerLauncher|null $launcher seam uji (null = bawaan)
     */
    private function saveComposeOnAgent(Request $request, array $app, AppStore $store, ?DeployWorkerLauncher $launcher = null): \Webman\Http\Response
    {
        $id = (string) $app['id'];
        $name = (string) $app['name'];
        $dir = (string) config('deploy.apps_path') . '/' . $name;

        try {
            $content = (string) $request->post('compose', '');

            // 1) Validasi isi compose DULU (fail-fast, belum ada efek samping).
            ComposeSource::assertDeployable($content);

            // 2) Berkas pendukung (upload/hapus) belum bisa dikirim ke agent —
            //    tolak eksplisit, jangan dibuang diam-diam.
            $delete = array_values(array_filter(
                array_map('strval', (array) $request->post('file_delete', [])),
                static fn (string $f): bool => $f !== ''
            ));
            if ($this->uploadsFrom($request) !== [] || $delete !== []) {
                throw new RuntimeException(
                    'Berkas pendukung compose (unggah/hapus) belum didukung untuk app di server agent — '
                    . 'simpan compose tanpa berkas pendukung dulu; berkas yang sudah ada di server agent tidak diubah.'
                );
            }

            // 3) Rencana host port untuk service di compose baru (scope server app
            //    ini: rentang port & port terpakai milik server itu) + primary.
            $server = $this->serverForApp($app);
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
            $range = ServerResolver::portRange($server);
            $context = $this->portContext($serverId);
            $services = ComposeSource::planHostPorts(
                ComposeSource::parseContent($content),
                ComposeSource::existingHostPorts($app),
                $context['used'],
                (int) $range['start'],
                (int) $range['end']
            );
            $portPlan = $this->hostPortPlan($services);
            $primarySelection = $this->resolvePrimarySelection(
                $services,
                '',
                (string) ($app['primary_service'] ?? ''),
                (int) ($app['primary_port'] ?? 0)
            );

            // 4) Daftar compose_files kanonik (base → override port → … → env).
            $composeFiles = $this->agentComposeFiles(
                ComposeSource::resolveMainFile($dir, $app),
                $portPlan,
                ContainerNames::normalizePrefix($app['container_prefix'] ?? ''),
                $this->limitsOf($app),
                is_array($app['env'] ?? null) ? $app['env'] : [],
                (array) ($app['compose_files'] ?? [])
            );

            // 5) Persist metadata (master compose + rencana port + daftar berkas).
            $store->update($id, function (array &$s) use ($content, $composeFiles, $primarySelection, $portPlan): void {
                $s['compose_content'] = $content;
                $s['compose_files'] = $composeFiles;
                if ($portPlan === []) {
                    unset($s['host_ports']);   // compose baru tanpa port → jangan pertahankan rencana lama
                } else {
                    $s['host_ports'] = $portPlan;
                }
                $s['primary_service'] = $primarySelection['service'];
                $s['primary_port'] = $primarySelection['port'];
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan perubahan compose di server agent ...';
                $s['error'] = null;
            });

            // 6) Spawn worker `apply` — worker control plane mengirim job ke agent
            //    (artifacts.compose + files), agent menulis berkas lalu up -d.
            if (!$this->spawnWorker($id, 'apply', null, $this->currentActorId(), $launcher)) {
                $store->update($id, function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
                flash_set('error', 'Compose tersimpan, tetapi gagal menjalankan deploy ulang di server agent.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', 'Compose disimpan & diterapkan di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Batas CPU/memori app (metadata `limits`) sebagai array — `[]` bila tidak
     * diatur/tidak valid (jalur agent tidak boleh gagal karena data lama).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function limitsOf(array $app): array
    {
        try {
            return ResourceLimits::of($app);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Host port yang terpakai app LAIN (untuk mengedit compose app ini: port
     * milik app sendiri bukan konflik).
     *
     * @return array<int,int>
     */
    private function usedHostPortsExcept(string $id): array
    {
        $others = array_values(array_filter(
            (new AppStore())->all(),
            static fn (array $a): bool => (string) ($a['id'] ?? '') !== $id
        ));

        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);

        return $portManager->usedHostPorts($others);
    }

    /**
     * File override generated milik app (selain override port yang ditulis ulang
     * di sini) — mis. override network & env. Urutannya dipertahankan agar
     * override env tetap paling akhir (menang atas repo).
     *
     * @return array<int,string>
     */
    private function generatedExtras(array $app): array
    {
        $keep = [];
        foreach ((array) ($app['compose_files'] ?? []) as $file) {
            $file = (string) $file;
            if (!str_starts_with($file, ComposeSource::GENERATED_PREFIX)) {
                continue;
            }
            if (in_array($file, [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE, ContainerNames::OVERRIDE_FILE], true)) {
                continue; // ditulis ulang oleh writeOverride()
            }
            $keep[] = $file;
        }
        return $keep;
    }
}
