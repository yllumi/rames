<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Agent\AgentError;
use app\library\Billing\InsufficientCredits;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\NetworkManager;
use app\library\Deploy\PortOverrides;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Update\AgentVersionGate;
use RuntimeException;
use support\Request;

/**
 * Pengaturan container app: network eksternal, nama container, port, dan
 * batas CPU/memori (§7).
 *
 * Route: `POST /apps/{id}/network`, `/container-names`, `/ports`, `/limits`.
 */
class ContainerController extends AppArtifactControllerBase
{
    /**
     * Simpan external network app (POST /apps/{id}/network).
     *
     * Menghubungkan seluruh service app ke shared network (via compose override
     * `external: true` + `networks: [default, <ext>]`) agar persisten lintas
     * Rebuild/Rollback. Validasi ketat: nama network & keberadaannya di Engine;
     * lalu tulis override, persist ke apps.json, dan recreate container
     * (`up -d` tanpa build) untuk menerapkan.
     */
    public function saveNetworks(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'network');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Jaringan eksternal');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Jaringan eksternal');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        // Normalisasi pilihan dari form + validasi format nama.
        $selected = [];
        foreach (array_map('strval', (array) $request->post('external_networks', [])) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                flash_set('error', "Nama network tidak valid: {$name}");
                return redirect('/apps/' . $id);
            }
            if (!in_array($name, $selected, true)) {
                $selected[] = $name;
            }
        }

        // Validasi network benar-benar ada **di server app** (bukan built-in).
        // Satu network hanya bermakna di satu server: untuk app di server agent
        // daftarnya dibaca dari agent itu (`GET /networks`), untuk app lokal dari
        // daemon lokal. Network yang hanya ada di server lain ⇒ ditolak dengan
        // pesan jelas (bukan diteruskan sampai `compose up` gagal).
        if ($selected !== []) {
            $server = null;
            try {
                $server = (new ServerResolver())->resolveForApp($app);
            } catch (\Throwable $e) {
                flash_set('error', $e->getMessage());
                return redirect('/apps/' . $id);
            }

            try {
                $deployer = DeployerFactory::createFor($server);
                $networks = $deployer instanceof AgentDeployer
                    ? $deployer->networks()
                    : (new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')))->listNetworks();

                $existing = [];
                foreach ($networks as $n) {
                    $existing[(string) ($n['Name'] ?? '')] = true;
                }
                foreach ($selected as $name) {
                    if (!isset($existing[$name]) || in_array($name, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                        flash_set('error', "Network \"{$name}\" tidak ditemukan di server app ini (atau built-in). Buat shared network dulu di halaman Networks server yang sama.");
                        return redirect('/apps/' . $id);
                    }
                }
            } catch (\Throwable $e) {
                flash_set('error', 'Tidak dapat membaca daftar network server app: ' . $this->appErrorText($app, $e));
                return redirect('/apps/' . $id);
            }
        }

        // Gerbang kredit — sebelum menulis override & recreate container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // 1) Tulis override dulu — gagal => state apps.json tidak berubah.
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            (new NetworkManager())->sync(['name' => $app['name'], 'external_networks' => $selected], $dir, $composeFiles);

            // 2) Persist ke apps.json (external_networks + compose_files).
            $store->update($id, function (array &$s) use ($selected): void {
                $s['external_networks'] = $selected;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== NetworkManager::OVERRIDE_FILE));
                if ($selected !== []) {
                    $files[] = NetworkManager::OVERRIDE_FILE; // selalu terakhir
                }
                $s['compose_files'] = $files;
            });

            // 3) Recreate container (up -d tanpa build) agar network diterapkan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'External networks disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'External networks tersimpan, tetapi gagal diterapkan ke container: ' . $this->appErrorText($app, $e) . ' — pastikan network eksternal ada, lalu coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Simpan prefix nama container app (POST /apps/{id}/container-names) —
     * form "Nama container" di tab Container (ability `compose`, operator+).
     *
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) Normalisasi & validasi prefix; tolak service ber-replica; fail-fast bila
     *     nama final sudah dipakai container lain (nama container unik se-host,
     *     tanpa prefix project).
     *  2) Tulis/hapus `docker-compose.override.names.yml` + rapikan compose_files.
     *  3) Recreate container (`up -d` tanpa build) agar nama baru dipakai.
     */
    public function saveContainerNames(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Nama container');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Nama container');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        $composeFiles = (array) ($app['compose_files'] ?? ['docker-compose.yml']);

        // Gerbang kredit — sebelum menulis override nama & recreate container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $prefix = $this->resolveContainerPrefix(
                (string) $app['name'],
                ComposeSource::resolveMainFile($dir, $app),
                (string) $request->post('container_prefix', '')
            );

            // 1) Tulis file dulu — gagal => state apps.json tidak berubah.
            if ($prefix === '') {
                ContainerNames::removeOverride($dir);
            } else {
                ContainerNames::writeOverride($dir, array_keys(ContainerNames::services($dir, $composeFiles)), $prefix);
            }

            // 2) Persist prefix + compose_files (urutan tetap: reset → ports → names → lain).
            $store->update($id, function (array &$s) use ($prefix, $composeFiles): void {
                $s['container_prefix'] = $prefix !== '' ? $prefix : null;
                $files = array_values(array_filter(
                    $composeFiles,
                    static fn (string $f): bool => $f !== ContainerNames::OVERRIDE_FILE
                ));
                if ($prefix !== '') {
                    $files[] = ContainerNames::OVERRIDE_FILE;
                }
                $s['compose_files'] = $this->orderComposeFiles($files);
            });

            // 3) Recreate container agar nama baru dipakai (tanpa build).
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', $prefix === ''
                    ? 'Nama container dikembalikan ke default compose & container diciptakan ulang.'
                    : 'Nama container disimpan (prefix "' . $prefix . '") & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Nama container tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan batas maksimum CPU/memori per service (POST /apps/{id}/limits) —
     * form "Batas resource" di tab Container. Ability `limits` = **admin saja**
     * (penolakan → 404, satu pintu lewat AppAccess).
     *
     * Dua jalur, dipilih dari **server pemilik app**:
     *  - server **lokal** (perilaku lama, tidak berubah): whitelist service
     *    terhadap base compose lokal → validasi nilai → tulis/hapus
     *    `docker-compose.override.limits.yml` + rapikan `compose_files` →
     *    recreate container (`applyEnv`, `up -d` tanpa build) secara sinkron;
     *  - server **agent**: control plane **tidak** punya base compose app itu,
     *    jadi daftar service dibaca dari agent (`GET /apps/{id}/services`),
     *    `limits`/`compose_files` disimpan sebagai **metadata**, lalu worker
     *    `apply_limits` menerapkannya di agent. Tidak ada artefak lokal ditulis.
     *
     * Field kosong = tidak diatur dashboard → key tidak ditulis, nilai CPU/memori
     * milik base compose repo tetap berlaku (bukan `!reset`).
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi
     *                                            tidak pernah mengisinya
     */
    public function saveLimits(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'limits');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        // App impor tidak punya base compose di host ini: Rames tidak menulis
        // override limits dan tidak menciptakan ulang container.
        try {
            ImportedApp::assertManageable($app, 'Batas CPU/memori');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server pemilik app (fail-fast bila `server_id` menunjuk server hilang):
        // jangan pernah menulis artefak app agent di host control plane.
        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Batas CPU/memori tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (!$local) {
            return $this->saveLimitsOnAgent($request, $app, $store, $launcher);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Batas CPU/memori');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis override limit & recreate container.
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // 1) Validasi → tulis/hapus file override → persist state (di library).
            $result = ResourceLimits::persist($store, $app, $dir, (array) $request->post('limits', []));
            $limits = $result['limits'];

            // 2) Recreate container agar batas baru dipakai (tanpa build).
            $app = $result['app'];
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', $limits === []
                    ? 'Batas CPU/memori dihapus (nilai base compose berlaku) & container diciptakan ulang.'
                    : 'Batas CPU/memori disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', $limits === []
                    ? 'Batas CPU/memori dihapus, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.'
                    : 'Batas CPU/memori tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan batas CPU/memori app yang berjalan di server **agent**
     * (POST /apps/{id}/limits — cabang non-lokal `saveLimits()`).
     *
     * Control plane tidak punya base compose app agent, sehingga:
     *  1) daftar service dibaca **dari agent** (`GET /apps/{id}/services`).
     *     Agent lama (endpoint 404) ⇒ pesan "perbarui agent" — bukan galat 500;
     *  2) validasi service/nilai + persist **metadata** (`limits`,
     *     `compose_files`) lewat `ResourceLimits::persistMetadata()` — fail-fast,
     *     tanpa menyentuh filesystem;
     *  3) worker `apply_limits` dipicu; agent menulis override limits & `up -d`
     *     tanpa build dari metadata itu.
     *
     * Urutan: gerbang kredit **sebelum** panggilan agent/metadata (op ini
     * menciptakan ulang container di agent).
     *
     * @param array<string,mixed>       $app      dokumen app (server agent)
     * @param DeployWorkerLauncher|null $launcher seam uji (null = bawaan)
     */
    private function saveLimitsOnAgent(
        Request $request,
        array $app,
        AppStore $store,
        ?DeployWorkerLauncher $launcher = null
    ): \Webman\Http\Response {
        $id = (string) $app['id'];

        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // 1) Daftar service dari base compose server agent.
            try {
                $data = $this->agentAppServices($app);
            } catch (AgentError $e) {
                if ($e->reason === AgentError::NOT_FOUND) {
                    throw new RuntimeException(
                        'Perbarui agent di server ini agar batas resource (CPU/memori) bisa diatur dari dashboard.'
                    );
                }

                throw new RuntimeException($this->appErrorText($app, $e), 0, $e);
            }

            $services = array_values(array_map('strval', (array) ($data['services'] ?? [])));
            if ($services === []) {
                $detail = trim((string) ($data['error'] ?? ''));
                throw new RuntimeException(
                    'Daftar service app tidak bisa dibaca di server agent'
                    . ($detail !== '' ? ': ' . $this->appErrorText($app, $detail) : '.')
                    . ' Pastikan base compose app tersedia di server itu, lalu coba lagi.'
                );
            }

            // 2) Validasi + persist metadata SAJA (tanpa artefak lokal).
            $result = ResourceLimits::persistMetadata(
                $store,
                $app,
                (array) $request->post('limits', []),
                $services
            );
            $limits = $result['limits'];

            // 3) Serahkan penerapan ke worker agent.
            $store->update($id, static function (array &$s): void {
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan batas CPU/memori di server agent ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply_limits', null, $this->currentActorId(), $launcher)) {
                $store->update($id, static function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker penerapan batas CPU/memori di server agent.';
                    $s['error'] = 'Gagal spawn worker apply_limits.';
                });
                flash_set('error', 'Batas CPU/memori tersimpan, tetapi gagal menjalankan worker penerapan di server agent. Coba Rebuild untuk menerapkan.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', $limits === []
                ? 'Batas CPU/memori dihapus — container diciptakan ulang di server agent (tanpa build).'
                : 'Batas CPU/memori disimpan — container diciptakan ulang di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Simpan **port container** per service (POST /apps/{id}/ports) — form
     * "Port container" di tab Container (ability `compose` = operator+, satu
     * pintu `AppAccess`; penolakan tetap 404).
     *
     * ## Mengapa host port tetap, port container yang diedit
     *
     * Port **host** adalah kontrak app ini dengan dunia luar: vhost Nginx
     * me-reverse-proxy domain app ke `127.0.0.1:<host port>` yang dipilih saat
     * app dibuat ({@see \app\library\Docker\AppPorts::primaryHostPort()}), dan
     * host port itu **unik se-host**. Mengubahnya dari dashboard akan
     * memindahkan target `proxy_pass` (domain yang sudah dipakai user putus)
     * dan berpotensi bentrok dengan container lain. Karena itu form hanya
     * membuka port **container** — port tempat app listen di jaringan
     * container — dan hasilnya tetap dipetakan ke host port yang sama
     * (`host:container`, lihat `PortOverrides`).
     *
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) app berstatus `deploying` ditolak (worker lain sedang menulis container);
     *  2) app **impor** ditolak (`ImportedApp::assertManageable`) — project
     *     compose eksternal dikelola user sendiri di direktori proyeknya;
     *  3) server pemilik app diresolusi (tanpa pengumpulan host port app lain:
     *     host port tidak berubah, jadi operasi ini tidak bisa menciptakan
     *     konflik host baru);
     *  4) cabang **lokal** (sinkron, pola `saveContainerNames`):
     *     `PortOverrides::persist()` menulis dua lapis override port
     *     (`docker-compose.override.yml` ber-`ports: !reset []` lalu
     *     `docker-compose.override.ports.yml` berisi port final) dan merapikan
     *     `compose_files`, lalu container diciptakan ulang tanpa build lewat
     *     `applyPortsToApp()`. **Config Nginx tidak ikut berubah** karena
     *     `proxy_pass` menunjuk host port yang tetap; `primary_port` (port
     *     container yang di-proxy) diperbarui oleh `PortOverrides::persist()`
     *     bila mapping yang di-proxy ikut berubah;
     *  5) cabang **server agent** (asinkron, pola `saveLimitsOnAgent`): hanya
     *     **metadata** (`host_ports` + `compose_files`) yang disimpan di control
     *     plane — host ini tidak punya artefak app remote — lalu worker
     *     `apply_ports` menerapkannya di agent. Gate capability `apply_ports`
     *     dibaca dari dokumen server (tanpa HTTP tambahan); daftar capability
     *     kosong = keputusan diserahkan ke agent. Baris port app agent wajib
     *     memuat minimal satu mapping ber-host port (baris `host === null`
     *     hanya diekspos di jaringan container, tanpa identitas untuk form).
     *
     * Input form: `ports[<service>][<host>] = "<port container>"`; host port
     * **tidak pernah** diubah, nilai kosong = pertahankan port container lama.
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi
     *                                            tidak pernah mengisinya
     */
    public function savePorts(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Port container');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server pemilik app (fail-fast bila `server_id` menunjuk server hilang):
        // jangan pernah menulis artefak app agent di host control plane.
        try {
            $server = $this->serverForApp($app);
        } catch (\Throwable $e) {
            flash_set('error', 'Port container tidak bisa dijalankan: ' . $this->appErrorText($app, $e));

            return redirect('/apps/' . $id);
        }

        $local = ServerResolver::isLocal($server);

        $posted = $request->post('ports', []);
        $posted = is_array($posted) ? $posted : [];

        if ($local) {
            $dir = $this->appArtifactDirOrFail($app, 'Port container');
            if ($dir === null) {
                return redirect('/apps/' . $id);
            }

            // Gerbang kredit — sebelum menulis override port & recreate container.
            try {
                $this->billingAssertCanStart($app, current_user());
            } catch (InsufficientCredits $e) {
                return $this->billingBlocked($request, $e);
            }

            try {
                // 1) Validasi → tulis dua lapis override → persist compose_files.
                //    `$usedPorts` = [] : host port tidak berubah, jadi tidak ada
                //    konflik host baru yang perlu diperiksa (parameter signature
                //    `PortOverrides::persist()` dipertahankan).
                $result = PortOverrides::persist($store, $app, $dir, $posted, []);
                $app = $result['app'];

                // 2) Recreate container tanpa build; host port (target
                //    `proxy_pass`) tetap, jadi vhost Nginx tidak berubah.
                try {
                    $applied = $this->applyPortsToApp($app);
                    $store->update($id, function (array &$s) use ($applied): void {
                        $s['containers'] = $applied['containers'] ?? [];
                        $s['status'] = 'running';
                        $s['message'] = 'Running';
                        $s['error'] = null;
                    });
                    flash_set('success', 'Port container disimpan & container diciptakan ulang (tanpa build).');
                } catch (\Throwable $e) {
                    flash_set('error', 'Port tersimpan, tetapi gagal diterapkan ke container: ' . $this->appErrorText($app, $e) . ' — coba Rebuild.');
                }
            } catch (\Throwable $e) {
                flash_set('error', $this->appErrorText($app, $e));
            }

            return redirect('/apps/' . $id);
        }

        // --------------------------------------------------------------
        // Server agent: metadata di control plane + worker `apply_ports`
        // --------------------------------------------------------------
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            // Gate capability (fail-fast, tanpa panggilan HTTP tambahan) — satu
            // sumber aturan `AgentVersionGate::supports()`; daftar capability
            // kosong = agent lama/tidak melaporkan capability → biarkan agent
            // yang memutuskan (job `apply_ports` tetap dikirim).
            if (!AgentVersionGate::supports($server, 'apply_ports')) {
                $serverName = (string) ($server['name'] ?? ($server['id'] ?? ''));
                throw new RuntimeException(
                    'Server "' . $serverName . '" belum mendukung perubahan port container dari dashboard — perbarui agent di server itu.'
                );
            }

            // Baris port app agent: metadata `host_ports` (tanpa filesystem).
            $rows = PortOverrides::rows($app);
            $hasHostRow = false;
            foreach ($rows as $row) {
                if (($row['host'] ?? null) !== null) {
                    $hasHostRow = true;
                    break;
                }
            }
            if (!$hasHostRow) {
                throw new RuntimeException('App ini tidak memublikasikan port ke host, jadi tidak ada port container yang bisa diubah.');
            }

            // 1) Validasi + persist metadata saja (tidak ada artefak lokal).
            //    `$usedPorts` = [] : host port tidak berubah.
            PortOverrides::persistMetadata($store, $app, $posted, $rows, []);

            // 2) Serahkan penerapan ke worker agent (`up -d` tanpa build).
            $store->update($id, static function (array &$s): void {
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan port container di server agent ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply_ports', null, $this->currentActorId(), $launcher)) {
                $store->update($id, static function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker penerapan port container di server agent.';
                    $s['error'] = 'Gagal spawn worker apply_ports.';
                });
                flash_set('error', 'Port container tersimpan, tetapi gagal menjalankan worker penerapan di server agent. Coba Rebuild untuk menerapkan.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', 'Port container disimpan — container diciptakan ulang di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Terapkan override port ke container app lokal (seam uji: kelas turunan di
     * tes mengganti ini agar tidak menjalankan docker compose nyata).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed> app dengan `containers` hasil deploy
     */
    protected function applyPortsToApp(array $app): array
    {
        return DeployerFactory::createForApp($app)->applyPorts($app, static function (string $stage, string $message): void {
        });
    }
}
