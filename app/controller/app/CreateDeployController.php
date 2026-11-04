<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Agent\AgentClient;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\EnvManager;
use app\library\Deploy\EnvTemplate;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\ServerResolver;
use app\library\Deploy\SubdomainManager;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;
use support\Request;

/**
 * Wizard create app — langkah konfirmasi & eksekusi deploy (§7).
 *
 * Route: `GET /apps/create/confirm`, `POST /apps/create/confirm`.
 * Mewarisi helper analisis dari {@see CreateController}; eksekusi worker tetap
 * lewat `DeployWorkerLauncher`.
 */
class CreateDeployController extends CreateController
{
    /**
     * Apakah minimal satu service mem-publikasikan port (kandidat `proxy_pass`)?
     * App yang tidak punya port sama sekali dibuat tanpa vhost/subdomain, jadi
     * direktori Nginx tidak perlu bisa ditulis (SPECS §7.2).
     *
     * @param array<string,array> $services
     */
    private function hasDeclaredPorts(array $services): bool
    {
        foreach ($services as $svc) {
            if (is_array($svc) && !empty($svc['ports'])) {
                return true;
            }
        }

        return false;
    }

    public function confirmForm(Request $request)
    {
        $pending = $request->session()->get('pending_app');
        if (!$pending) {
            return redirect('/apps/create');
        }

        // Server target yang dipilih di langkah analisis (kosong = server lokal).
        // Validasi ulang fail-fast: registry server bisa berubah di antara dua
        // langkah (server dihapus/dinonaktifkan) — jangan sampai halaman
        // konfirmasi menampilkan FQDN/limit untuk server yang salah.
        $serverId = (string) ServerStore::LOCAL_ID;
        try {
            $server = $this->resolveCreateServer((string) ($pending['server_id'] ?? ''), current_user());
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
        } catch (\Throwable $e) {
            $request->session()->delete('pending_app');
            flash_set('error', $e->getMessage());
            return redirect('/apps/create');
        }
        $pending['server_id'] = $serverId;

        // Base compose sudah ada di disk (hasil clone/upload langkah analisis).
        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];

        // Data subdomain untuk ditampilkan/diedit di halaman konfirmasi: FQDN
        // efektif + label + flag "eksplisit" (field terisi, bukan fallback name).
        // FQDN-nya milik **server target** (keputusan 4: `{label}.{domain server}`).
        $label = SubdomainManager::normalize((string) ($pending['subdomain'] ?? ''));
        $asIf = ['name' => (string) $pending['name'], 'subdomain' => $label, 'server_id' => $serverId];

        return view('app/confirm', $this->serverFormContext(current_user(), $serverId) + [
            'pending' => $pending,
            'subdomain' => app_subdomain_of($asIf),
            'subdomain_label' => $label,
            'subdomain_custom' => $label !== '',
            // Template environment variable (dari `.env.example` repo atau
            // referensi ${...} compose) + draft isian user bila create gagal.
            'envTemplate' => is_array($pending['env_template'] ?? null)
                ? $pending['env_template']
                : ['source' => EnvTemplate::SOURCE_NONE, 'vars' => []],
            'envDraft' => is_array($pending['env_draft'] ?? null) ? $pending['env_draft'] : [],
            'resourceLimits' => $this->limitsContext(
                $dir,
                [(string) $pending['compose_file']],
                current_user() !== null,
                [],
                array_map('strval', array_keys((array) ($pending['services'] ?? [])))
            ),
            'billing' => $this->billingContext(
                $this->defaultLimitsFor(array_map('strval', array_keys((array) ($pending['services'] ?? [])))),
                current_user(),
                null,
                null,
                $serverId
            ),
            'limitsScaleMax' => $this->limitsScaleMax(),
        ]);
    }

    public function confirmCreate(Request $request)
    {
        $pending = $request->session()->get('pending_app');
        if (!$pending) {
            return redirect('/apps/create');
        }

        $serviceInput = (array) $request->post('services', []);
        $primarySelection = (string) $request->post('primary', '');

        // Isian environment variable dari halaman konfirmasi. Template-nya
        // dideteksi di langkah analisis (`createPreview()`/`composePreview()`)
        // dan disimpan di session — POST hanya membawa nilainya.
        $envInput = $this->collectCreateEnvInput($request);
        $envTemplate = is_array($pending['env_template'] ?? null) ? $pending['env_template'] : [];

        // Server target: form konfirmasi boleh mengirim ulang `server_id`
        // (dipilih di langkah pertama); selain itu pilihan tersimpan di session
        // yang dipakai. Validasi fail-fast SEBELUM efek samping apa pun.
        $postedServerId = $request->post('server_id') === null
            ? (string) ($pending['server_id'] ?? '')
            : $this->requestedServerId($request);

        try {
            $server = $this->resolveCreateServer($postedServerId, current_user());
        } catch (\Throwable $e) {
            // Sengaja render ulang dari `pending_app` (id langkah analisis), bukan
            // id kiriman: langkah konfirmasi tidak punya pemilih server di UI
            // (hanya hidden input), jadi jalan ini hanya bisa dicapai dengan
            // memanipulasi POST — fail-fast + target deploy tetap pilihan yang
            // sudah divalidasi lebih aman daripada diam-diam memakai id kiriman.
            $this->persistEnvDraft($request, $pending, $envInput);
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }
        $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);
        $pending['server_id'] = $serverId;

        // Prefix nama container divalidasi lebih dulu supaya pesan errornya kembali
        // ke halaman konfirmasi (pending_app masih tersimpan), bukan ke form create.
        try {
            $containerPrefix = $this->resolveContainerPrefix(
                (string) $pending['name'],
                (string) $pending['compose_file'],
                (string) $request->post('container_prefix', ''),
                $serverId
            );
        } catch (\Throwable $e) {
            $this->persistEnvDraft($request, $pending, $envInput);
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }

        try {
            $services = $this->validateAndApplyPorts($pending['services'], $serviceInput, $serverId);
            $primary = $this->resolvePrimarySelection(
                $services,
                $primarySelection,
                (string) ($pending['primary_service'] ?? ''),
                (int) ($pending['primary_port'] ?? 0)
            );

            // Subdomain: form konfirmasi boleh menimpanya (bila UI mengirim field);
            // selain itu pakai nilai yang sudah divalidasi di langkah analisis.
            $subdomain = $request->post('subdomain') !== null
                ? SubdomainManager::normalize((string) $request->post('subdomain'))
                : SubdomainManager::normalize((string) ($pending['subdomain'] ?? ''));
            $this->assertSubdomainAvailable($subdomain, (string) $pending['name'], '', $serverId);

            // Environment variable: validasi isian + pastikan variabel yang
            // "wajib" menurut template tidak dibiarkan kosong. Dilakukan sebelum
            // efek samping apa pun (credit gate, pembuatan direktori app).
            $env = EnvTemplate::resolveValues($envInput, $envTemplate);

            // Batas CPU/memori: owner/member boleh memilih saat create (D2=a),
            // dibatasi plafon billing. Admin bebas (billing bukan untuk admin).
            $limits = $this->resolveLimits(
                (array) $request->post('limits', []),
                array_map('strval', array_keys((array) ($pending['services'] ?? [])))
            );
            $user = current_user();
            if ($this->isBillingMember($user)) {
                Pricing::assertWithinCaps($limits);
                if ($limits === []) {
                    // Anti-lubang harga: app member selalu punya limit nyata.
                    $limits = $this->defaultLimitsFor(
                        array_map('strval', array_keys((array) ($pending['services'] ?? [])))
                    );
                }
            }

            // Gerbang kredit — fail-fast SEBELUM efek samping apa pun; tarif
            // mengikuti server target yang sudah di-resolve.
            $this->billingAssertCanCreate($limits, $user, null, $serverId);

            $result = $this->createAndDeploy(
                $pending,
                $services,
                $primary['service'],
                $primary['port'],
                $containerPrefix,
                $env,
                null,
                $limits,
                $subdomain
            );
            $app = $result['app'];
            $spawned = $result['spawned'];

            $request->session()->delete('pending_app');

            // Panggilan AJAX (fetch) mengembalikan JSON; form biasa tetap redirect.
            if ($request->expectsJson()) {
                return json([
                    'code' => $spawned ? 0 : 1,
                    'id' => $app['id'],
                    'name' => $app['name'],
                    'error' => $spawned ? null : 'Gagal menjalankan worker deploy.',
                ]);
            }

            flash_set('success', 'App "' . $app['name'] . '" sedang di-deploy.');
            return redirect('/apps/' . $app['id']);
        } catch (InsufficientCredits $e) {
            $this->persistEnvDraft($request, $pending, $envInput);
            return $this->billingBlocked($request, $e);
        } catch (\Throwable $e) {
            // Redirect balik ke halaman konfirmasi tidak membawa POST: simpan
            // isian env user sebagai draft supaya form terisi lagi.
            $this->persistEnvDraft($request, $pending, $envInput);
            // `createAndDeploy()` dapat memanggil agent (preflight nama app):
            // pesan transport memuat `base_url`/host server — redaksi dulu.
            $errorText = $this->appErrorText($pending, $e);
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $errorText]);
            }
            flash_set('error', $errorText);
            return redirect('/apps/create/confirm');
        }
    }

    /**
     * Kumpulkan isian environment variable dari halaman konfirmasi.
     *
     * Form mengirim dua bentuk:
     *   - `env[KEY]=value`                → variabel hasil deteksi template
     *   - `env_extra[i][key]` + `[value]` → variabel tambahan yang diketik user
     *
     * @return array<string,mixed>
     */
    private function collectCreateEnvInput(Request $request): array
    {
        $input = [];
        $primary = $request->post('env', []);
        if (is_array($primary)) {
            foreach ($primary as $key => $value) {
                $input[(string) $key] = $value;
            }
        }

        $extra = $request->post('env_extra', []);
        if (is_array($extra)) {
            foreach ($extra as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $input[$key] = $row['value'] ?? '';
            }
        }

        return $input;
    }

    /**
     * Simpan isian env sebagai draft di session `pending_app` agar tidak hilang
     * saat controller me-redirect kembali ke halaman konfirmasi.
     *
     * @param array<string,mixed> $pending
     * @param array<string,mixed> $envInput
     */
    private function persistEnvDraft(Request $request, array $pending, array $envInput): void
    {
        if ($envInput === []) {
            return;
        }
        $pending['env_draft'] = $envInput;
        $request->session()->set('pending_app', $pending);
    }

    /**
     * Buat app + spawn worker deploy — jalur bersama halaman konfirmasi (mode
     * git/compose) dan deploy template.
     *
     * Semua penulisan file (override port/nama container melalui
     * `writeOverride()`, managed env file + override env melalui `EnvManager`)
     * selesai SEBELUM entri `apps.json` dibuat, sehingga kegagalan tidak
     * meninggalkan app setengah jadi — pemanggil cukup membersihkan direktori
     * app. Env sengaja juga dipersist ke `apps.json` agar `EnvManager::sync()`
     * di `LocalDeployer` tidak menghapus file env saat deploy berjalan.
     *
     * Preflight ketersediaan nama app di server **target** dijalankan paling awal
     * (`DeployerFactory::assertAppTargetAvailable()`, no-op untuk server lokal):
     * app di server agent tidak boleh lahir kalau direktori `apps/<name>` di sana
     * berisi data yang bukan milik Rames.
     *
     * @param array<string,array>       $services  hasil ComposeParser + resolusi host port
     * @param array<string,string>      $env       map KEY => value (kosong = tanpa env)
     * @param array<string,string>|null $template  metadata asal template (bila dibuat dari template)
     * @param array<string,array{cpus:?float,memory_mb:?int}> $limits batas CPU/memori per service (kosong = tidak diatur)
     * @param string|null               $subdomain label subdomain eksplisit (null/'' = tanpa field, fallback `name`)
     * @param DeployWorkerLauncher|null $launcher  seam uji: peluncur worker (null = bawaan)
     * @param AgentClient|null          $agentClient seam uji: klien agent untuk preflight
     *                                  nama app di server target (null = dibangun dari
     *                                  dokumen server, seperti produksi)
     * @return array{app:array,spawned:bool}
     */
    protected function createAndDeploy(
        array $pending,
        array $services,
        string $primaryService,
        int $primaryPort,
        string $containerPrefix,
        array $env = [],
        ?array $template = null,
        array $limits = [],
        ?string $subdomain = null,
        ?DeployWorkerLauncher $launcher = null,
        ?AgentClient $agentClient = null
    ): array {
        // Server target app baru (`$pending['server_id']`, absen/kosong = lokal).
        // Fail-fast bila id server sudah tidak ada di registry.
        $server = (new ServerResolver())->resolveForApp($pending);

        // Id app dibuat **di sini**, bukan diserahkan ke `AppStore::create()`:
        // preflight di bawah harus membandingkan id yang persis sama dengan yang
        // nanti dipakai penanda kepemilikan/`dropStaleDir()` di server agent.
        // Formatnya sama dengan `AppStore::create()` (`bin2hex(random_bytes(8))`).
        $appId = bin2hex(random_bytes(8));

        // Preflight ketersediaan nama/direktori app di server target SEBELUM efek
        // samping apa pun (termasuk record app di store): server agent yang
        // direktori `apps/<name>`-nya berisi data tanpa penanda Rames menolak di
        // sini, sehingga wizard menampilkan pesan yang bisa ditindaklanjuti —
        // bukan "deploy gagal" sesudah record app terlanjur dibuat. Server lokal
        // = no-op ({@see DeployerFactory::assertAppTargetAvailable()}).
        //
        // Dokumen minimal: hanya field yang dipakai keputusan "nama ini bisa
        // dipakai?" (`source`/`repo_url`/`template`) + identitas app. `repo_url`
        // diperlukan agent untuk membandingkan remote checkout yang **sudah ada di
        // sana** (read-only) — tanpa itu app repo yang pernah di-deploy dilaporkan
        // bentrok. `env`, deploy key, dan token tidak pernah ikut.
        $preflightApp = [
            'id' => $appId,
            'name' => (string) $pending['name'],
            'source' => (string) ($pending['source'] ?? ComposeSource::SOURCE_GIT),
        ];
        $repoUrl = is_scalar($pending['repo_url'] ?? null) ? trim((string) $pending['repo_url']) : '';
        if ($repoUrl !== '') {
            $preflightApp['repo_url'] = $repoUrl;
        }
        if (is_array($template) && is_scalar($template['slug'] ?? null) && trim((string) $template['slug']) !== '') {
            $preflightApp['template'] = ['slug' => trim((string) $template['slug'])];
        }

        DeployerFactory::assertAppTargetAvailable($server, $preflightApp, $agentClient);

        // Fail-fast: pastikan direktori Nginx dapat ditulis sebelum deploy —
        // hanya bila ada port yang akan di-proxy ke domain. App tanpa `ports:`
        // tidak membuat vhost, jadi direktori Nginx tidak relevan (SPECS §7.2).
        //
        // Prasyarat itu milik host INI, jadi hanya berlaku untuk server lokal:
        // untuk server `kind=agent` vhost dibuat di server itu (fase F2) dan
        // `AgentDeployer::ensureWritable()` memang melempar "belum didukung" —
        // karena itu tidak dipanggil (bukan diam-diam memakai direktori lokal).
        if ($this->hasDeclaredPorts($services) && ServerResolver::isLocal($server)) {
            DeployerFactory::createFor($server)->ensureWritable();
        }

        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];
        $agentServer = !ServerResolver::isLocal($server);

        // Rencana host port pilihan user (server **agent**): disimpan di metadata
        // app (`host_ports`) dan dikirim `AgentArtifactPackager` lewat
        // `artifacts.files` saat job berangkat — host ini **tidak** menulis
        // berkas override untuk app di server agent (misi F1 celah B).
        $portPlan = $agentServer ? $this->hostPortPlan($services) : [];

        // Artefak lokal app (override port/nama container, batas CPU/memori,
        // override env) hanya bisa ditulis bila direktori app lokal memang ada di
        // host ini **dan app memang milik server ini**. App **repo** milik server
        // **agent** tidak punya direktori itu — checkout-nya dibuat agent
        // (`AgentArtifactMaterializer`), jadi penulisan lokal **dilewati** dengan
        // alasan yang benar (bukan gagal "Gagal menulis
        // docker-compose.override.yml"); override env/limits/nama container
        // dibuat worker di agent dari metadata app ini.
        $localArtifacts = is_dir($dir) && !$agentServer;

        $composeFiles = [$pending['compose_file']];
        if ($agentServer) {
            $composeFiles = $this->agentComposeFiles(
                (string) $pending['compose_file'],
                $portPlan,
                $containerPrefix,
                $limits,
                $env,
                (array) ($pending['compose_files'] ?? [])
            );
        } else {
            if ($localArtifacts) {
                $this->writeOverride($pending, $services, $composeFiles, $containerPrefix);
            }

            // Batas CPU/memori (admin saja) — file override ditulis SEBELUM entri
            // apps.json dibuat; kegagalan ⇒ pemanggil membersihkan direktori app.
            if ($localArtifacts && ResourceLimits::writeOverride($dir, [$pending['compose_file']], $limits)) {
                $composeFiles[] = ResourceLimits::OVERRIDE_FILE;
            }

            if ($env !== [] && $localArtifacts) {
                $composeFiles[] = EnvManager::OVERRIDE_FILE;
                $composeFiles = $this->orderComposeFiles($composeFiles);

                $envManager = new EnvManager();
                $envManager->write((string) $pending['name'], $env);
                $envManager->writeOverride($dir, $composeFiles, $env);
            } else {
                // Nilai env tetap dipersist di `apps.json` (`$appData['env']`) supaya
                // worker di server target bisa menulis override-nya sendiri.
                $composeFiles = $this->orderComposeFiles($composeFiles);
            }
        }

        $appData = [
            'id' => $appId,
            'name' => $pending['name'],
            // Server pemilik app (multi-server F1): selalu eksplisit — kosong
            // ternormalisasi ke server lokal oleh `AppStore::create()`.
            'server_id' => AppStore::normalizeServerId($pending['server_id'] ?? ''),
            'owner_id' => (string) (current_user()['id'] ?? ''),
            'members' => [],
            'source' => $pending['source'] ?? ComposeSource::SOURCE_GIT,
            'repo_url' => $pending['repo_url'] ?? null,
            'branch' => $pending['branch'] ?? null,
            'local_path' => $pending['local_path'],
            'primary_service' => $primaryService,
            'primary_port' => $primaryPort,
            'status' => 'deploying',
            'stage' => 'queued',
            'message' => 'Menunggu worker deploy ...',
            'compose_files' => $composeFiles,
            'container_prefix' => $containerPrefix !== '' ? $containerPrefix : null,
            'env' => $env,
            'limits' => $limits !== [] ? $limits : null,
            'template' => $template,
            'needs_ssl' => false,
            'ssl_status' => null,
            'auth_method' => $pending['auth_method'] ?? 'none',
            'ssh_key' => $pending['ssh_key'] ?? null,
            'containers' => [],
        ];

        // Rencana host port (server agent) ikut metadata app: `AgentArtifactPackager`
        // membangkitkan override port dari field ini saat job berangkat, sehingga
        // agent memakai pilihan user — bukan port bawaan repo (misi F1 celah B).
        if ($portPlan !== []) {
            $appData['host_ports'] = $portPlan;
        }

        // Subdomain opsional: kosong ⇒ field tidak ditulis (fallback ke `name`).
        if ($subdomain !== null && $subdomain !== '') {
            $appData['subdomain'] = $subdomain;
        }

        $app = (new AppStore())->create($appData);

        $spawned = $this->spawnWorker($app['id'], 'deploy', null, $this->currentActorId(), $launcher);
        if (!$spawned) {
            (new AppStore())->update($app['id'], function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker deploy. Cek log & coba Rebuild.';
                $s['error'] = 'Gagal spawn worker deploy.';
            });
        }

        return ['app' => $app, 'spawned' => $spawned];
    }

    /**
     * Validasi input batas CPU/memori dari form: tolak service yang tidak dikenal,
     * normalkan nilai, lalu buang entri yang tidak mengatur apa pun.
     *
     * @param array<string,mixed> $posted
     * @param array<int,string>   $services nama service yang dikenal
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    protected function resolveLimits(array $posted, array $services): array
    {
        return ResourceLimits::fromInput($posted, $services);
    }

    /**
     * Validasi & terapkan host port hasil edit user (langkah konfirmasi).
     *
     * Form mengirim satu input per port service:
     * `services[<svc>][ports][<containerPort>][host_port]` — jadi service dengan
     * lebih dari satu port (mis. web + gateway API) bisa punya host port sendiri
     * masing-masing. Bentuk lama `services[<svc>][host_port]` (hanya port pertama)
     * tetap diterima sebagai fallback.
     *
     * @param array<string,array> $services
     * @param array               $input
     * @return array<string,array>
     */
    private function validateAndApplyPorts(array $services, array $input, string $serverId = ''): array
    {
        $context = $this->portContext($serverId);
        $portManager = $context['manager'];
        $usedPorts = $context['used'];

        $localUsed = [];
        foreach ($services as $svcName => $svc) {
            foreach ((array) ($svc['ports'] ?? []) as $i => $entry) {
                $containerPort = (int) ($entry['container'] ?? 0);
                $posted = $input[$svcName]['ports'][$containerPort]['host_port']
                    ?? $input[$svcName]['ports'][$i]['host_port']
                    ?? ($i === 0 ? ($input[$svcName]['host_port'] ?? null) : null);

                $hostPort = $posted !== null && $posted !== '' ? (int) $posted : (int) ($entry['host'] ?? 0);
                $label = 'service "' . $svcName . '"' . ($containerPort > 0 ? ', container port ' . $containerPort : '');

                if ($hostPort <= 0 || !$portManager->validatePort($hostPort)) {
                    throw new RuntimeException("Port tidak valid untuk {$label}.");
                }
                if (in_array($hostPort, $usedPorts, true) || in_array($hostPort, $localUsed, true)) {
                    throw new RuntimeException("Port {$hostPort} ({$label}) sudah terpakai. Pilih port lain.");
                }

                $localUsed[] = $hostPort;
                $usedPorts[] = $hostPort;

                $services[$svcName]['ports'][$i]['host'] = $hostPort;
                if ($i === 0) {
                    $services[$svcName]['host_port'] = $hostPort;
                }
            }
        }

        return $services;
    }
}
