<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\EnvManager;
use app\library\Deploy\PortOverrides;
use app\library\Deploy\ResourceLimits;
use app\library\Deploy\ServerResolver;
use app\library\Docker\AppPorts;
use app\library\Docker\DockerClient;
use app\library\Docker\PortManager;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;
use support\Request;

/**
 * Basis controller yang membaca/menulis **artefak compose** app: wizard create
 * ({@see CreateController}, {@see CreateDeployController},
 * {@see TemplateController}), editor compose ({@see ComposeController}), tab
 * container ({@see ContainerController}), dan konteks limit di halaman detail
 * ({@see InfoController}).
 *
 * Isinya helper bersama kelompok itu: konteks port per server, rencana host
 * port, pemilihan service primary, penulisan override compose, pengumpulan
 * berkas upload, konteks limit service, serta prefiks nama container. Tidak
 * ada logika bisnis di sini — semuanya mediator.
 */
abstract class AppArtifactControllerBase extends AppControllerBase
{
    /**
     * Konteks alokasi host port pada scope server (multi-server F1, plan §4.2):
     * `PortManager` berentang port **efektif server** + host port yang sudah
     * terpakai **app di server itu** (app di server lain tidak menahan port di
     * sini). Server lokal tidak berubah: rentangnya tetap
     * `config('deploy.port_range')` dan seluruh app lama (tanpa `server_id`)
     * memang ternormalisasi ke server lokal.
     *
     * @param string $serverId id server (''/`local` = server lokal); id tak
     *                         dikenal ⇒ melempar (fail-fast `ServerResolver`)
     * @return array{manager:PortManager,used:array<int,int>}
     */
    protected function portContext(string $serverId): array
    {
        $server = (new ServerResolver())->resolve($serverId);
        $range = ServerResolver::portRange($server);
        $manager = new PortManager((int) $range['start'], (int) $range['end']);

        return [
            'manager' => $manager,
            'used' => $manager->usedHostPorts(AppPorts::onServer(
                (new AppStore())->all(),
                (string) ($server['id'] ?? ServerStore::LOCAL_ID)
            )),
        ];
    }

    /**
     * Normalisasi file unggahan dari request (Webman UploadFile / struktur
     * $_FILES) menjadi daftar entri yang dipahami ComposeSource::store().
     *
     * @return array<int,array{name:string,tmp_name:string,size:int,error:int}>
     */
    protected function uploadsFrom(Request $request): array
    {
        $files = $request->file('files');
        if (!is_array($files) || $files === []) {
            return [];
        }

        $result = [];
        foreach (array_values($files) as $file) {
            if ($file instanceof \Webman\Http\UploadFile) {
                $name = trim((string) $file->getUploadName());
                // Input file kosong dikirim browser dengan nama kosong → abaikan.
                if ($name === '') {
                    continue;
                }
                $path = (string) $file->getPathname();
                $size = 0;
                try {
                    $size = (int) $file->getSize();
                } catch (\Throwable $e) {
                    $size = 0; // file gagal di-upload → error code yang menentukan
                }
                $result[] = [
                    'name' => $name,
                    'tmp_name' => $file->isValid() ? $path : '',
                    'size' => $size,
                    'error' => (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE),
                ];
                continue;
            }
            if (is_array($file)) {
                foreach (ComposeSource::normalizeUploads($file) as $entry) {
                    if (trim((string) ($entry['name'] ?? '')) === '') {
                        continue;
                    }
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * Validasi prefix nama container dari form:
     *  - normalisasi + validasi format;
     *  - service ber-replica > 1 ditolak (compose tidak mengizinkan container_name);
     *  - nama final harus belum dipakai container lain di host — fail-fast, tanpa
     *    ini `docker compose up` gagal di tengah deploy dengan "Conflict".
     *
     * @return string prefix final ('' = pakai nama default compose)
     */
    protected function resolveContainerPrefix(string $appName, string $composeFile, string $rawPrefix, string $serverId = ''): string
    {
        $prefix = ContainerNames::normalizePrefix($rawPrefix);
        ContainerNames::assertValidPrefix($prefix);
        if ($prefix === '') {
            return '';
        }

        $dir = (string) config('deploy.apps_path') . '/' . $appName;
        if (!is_dir($dir)) {
            // App **repo** milik server **agent**: base compose-nya hanya ada di
            // server itu (checkout di agent), jadi daftar service & pemeriksaan
            // `deploy.replicas` tidak bisa dijalankan dari control plane. Ditolak
            // **eksplisit** (bukan diloloskan tanpa cek / bukan pesan menyesatkan
            // "base compose tidak ditemukan").
            throw new RuntimeException(
                'Nama container kustom belum aktif untuk app repo di server agent pada fase ini: base compose '
                . 'app ini ada di server agent, sehingga pemeriksaan daftar service & `deploy.replicas` tidak '
                . 'bisa dilakukan dari dashboard. Biarkan kolom "Prefix nama container" kosong lalu deploy lagi.'
            );
        }
        $services = ContainerNames::services($dir, [$composeFile]);
        ContainerNames::assertNotReplicated($services);
        ContainerNames::assertAvailable(
            ContainerNames::mapFor(array_keys($services), $prefix),
            $this->usedContainerNames($appName, $serverId)
        );

        return $prefix;
    }

    /**
     * Nama container yang sudah terpakai **di server app ini**, di luar project
     * app ini.
     *
     * - Server **lokal**: Engine API jadi acuan utama (mencakup container app
     *   lain, container eksternal, dan container dashboard sendiri); apps.json
     *   dipakai sebagai cadangan bila Engine tidak dapat diakses — perilaku
     *   lama, tidak berubah.
     * - Server **agent**: Engine server itu belum bisa dibaca dari control plane
     *   (F3 akan merutekannya lewat agent), jadi Engine **lokal tidak pernah**
     *   dibaca untuk app milik server lain (kesalahan paling berbahaya, plan §3
     *   butir 4). Yang bisa dipastikan dari control plane adalah bentrok antar
     *   app dashboard di server itu lewat apps.json (`ContainerNames::usedFromApps()`
     *   berscope server); container di luar dashboard baru terlihat pada F3.
     *
     * @param string $ownProject nama project app ini (dilewati)
     * @param string $serverId   id server app (''/`local` = lokal)
     * @return array<string,string> nama => deskripsi pemilik
     */
    private function usedContainerNames(string $ownProject, string $serverId = ''): array
    {
        $server = (new ServerResolver())->resolve($serverId);
        $scope = (string) ($server['id'] ?? ServerStore::LOCAL_ID);

        if (!ServerResolver::isLocal($server)) {
            return ContainerNames::usedFromApps((new AppStore())->all(), $ownProject, $scope);
        }

        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            return ContainerNames::usedFromEngine($docker->listContainers(), $ownProject, $scope);
        } catch (\Throwable $e) {
            return ContainerNames::usedFromApps((new AppStore())->all(), $ownProject, $scope);
        }
    }

    /**
     * Konteks data batas CPU/memori (`resourceLimits`) untuk view.
     *
     * Kontrak data (dipakai view — jangan diubah tanpa memberi tahu Frontend UI):
     * `canManage`, `services`, `saved`, `repo`, `hasSaved`, `error`.
     *
     * Bila base compose tidak terbaca (mis. file belum dimaterialisasi), `error`
     * diisi & daftar service jatuh ke `$servicesFallback` — halaman tetap bisa
     * dirender, bukan error.
     *
     * @param array<int,string>                                    $composeFiles
     * @param array<string,array{cpus:?float,memory_mb:?int}>      $saved
     * @param array<int,string>                                    $servicesFallback
     * @return array{canManage:bool,services:array<int,string>,saved:array<string,array{cpus:?float,memory_mb:?int}>,repo:array<string,array{cpus:?float,memory_mb:?int}>,hasSaved:bool,error:?string}
     */
    protected function limitsContext(
        string $dir,
        array $composeFiles,
        bool $canManage,
        array $saved,
        array $servicesFallback = []
    ): array {
        $services = [];
        $repo = [];
        $error = null;

        try {
            $services = ResourceLimits::services($dir, $composeFiles);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $services = array_values(array_filter(
                array_map('strval', $servicesFallback),
                static fn (string $s): bool => $s !== ''
            ));
        }

        if ($services !== []) {
            try {
                $detected = ResourceLimits::detect($dir, $composeFiles);
                foreach ($services as $service) {
                    $repo[$service] = [
                        'cpus' => $detected[$service]['cpus'] ?? null,
                        'memory_mb' => $detected[$service]['memory_mb'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // Daftar service tetap valid; hanya prefill dari base yang hilang.
                $error ??= $e->getMessage();
            }
        }

        return [
            'canManage' => $canManage,
            'services' => $services,
            'saved' => $saved,
            'repo' => $repo,
            'hasSaved' => $saved !== [],
            'error' => $error,
        ];
    }

    /**
     * Rencana host port app (metadata `host_ports`) dari hasil resolusi port
     * wizard/create: daftar `{service, container, host}` — `host` `null` untuk
     * port yang hanya diekspos (tidak dipublikasikan ke host).
     *
     * Isinya = pilihan user (atau alokasi otomatis `PortManager`), bukan port
     * bawaan repo; dibangkitkan ulang menjadi berkas override port oleh
     * {@see \app\library\Agent\AgentArtifactPackager} saat job berangkat.
     *
     * Protokol ikut disimpan **hanya** bila bukan `tcp` (entri TCP tetap ringkas,
     * jadi dokumen lama & assertion lama tidak berubah). Tanpa itu port UDP app
     * agent (`53/udp`) ditulis ulang sebagai TCP saat `apply_ports`.
     *
     * @param array<string,array> $services hasil ComposeParser + resolusi host port
     * @return array<int,array{service:string,container:int,host:?int,protocol?:string}>
     */
    protected function hostPortPlan(array $services): array
    {
        $plan = [];
        foreach ($services as $service => $svc) {
            $service = (string) $service;
            if ($service === '' || !is_array($svc) || empty($svc['ports'])) {
                continue;
            }
            foreach ((array) $svc['ports'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $container = (int) ($entry['container'] ?? 0);
                if ($container <= 0) {
                    continue;
                }
                $host = isset($entry['host']) && (int) $entry['host'] > 0 ? (int) $entry['host'] : null;
                $planned = ['service' => $service, 'container' => $container, 'host' => $host];
                $protocol = is_scalar($entry['protocol'] ?? null)
                    ? strtolower(trim((string) $entry['protocol']))
                    : '';
                if ($protocol !== '' && $protocol !== 'tcp') {
                    $planned['protocol'] = $protocol;
                }
                $plan[] = $planned;
            }
        }

        return $plan;
    }

    /**
     * Daftar `compose_files` app di server **agent**, urutan kanonik repo:
     * base → override port (reset + ports, hanya bila ada rencana port) → override
     * yang diregenerasi worker agent dari metadata app (nama container, limits,
     * jaringan) → override env **paling akhir**.
     *
     * Berbeda dari jalur lokal, host ini **tidak** menulis berkas override
     * tersebut: berkas override port dikirim `AgentArtifactPackager` lewat
     * `artifacts.files` (materializer di agent yang menulis), sedangkan
     * nama/limits/env/jaringan ditulis worker agent dari metadata app. Entri-nya
     * tetap dicatat supaya `docker compose -f …` di agent benar-benar memakainya
     * — dan hanya dicatat bila berkasnya memang akan ada (worker agent selalu
     * menulisnya lebih dulu); `LocalDeployer::resolveComposeFiles()` di agent
     * membuang entri generated yang berkasnya hilang, jadi tidak pernah ada entri
     * yatim.
     *
     * @param array<int,array{service:string,container:int,host:?int}> $portPlan
     * @param array<string,mixed>  $limits
     * @param array<string,string> $env
     * @param array<int,string>    $existing compose_files app saat ini (override lain dipertahankan)
     * @return array<int,string>
     */
    protected function agentComposeFiles(
        string $mainFile,
        array $portPlan,
        ?string $containerPrefix,
        array $limits,
        array $env,
        array $existing = []
    ): array {
        $managed = [
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
            ResourceLimits::OVERRIDE_FILE,
            EnvManager::OVERRIDE_FILE,
        ];

        $files = [$mainFile];
        if ($portPlan !== []) {
            $files[] = ComposeSource::RESET_OVERRIDE_FILE;
            $files[] = ComposeSource::PORTS_OVERRIDE_FILE;
        }
        if (ContainerNames::normalizePrefix((string) ($containerPrefix ?? '')) !== '') {
            $files[] = ContainerNames::OVERRIDE_FILE;
        }
        if ($limits !== []) {
            $files[] = ResourceLimits::OVERRIDE_FILE;
        }
        foreach ($existing as $file) {
            $file = (string) $file;
            if (!str_starts_with($file, ComposeSource::GENERATED_PREFIX) || in_array($file, $managed, true)) {
                continue;
            }
            $files[] = $file;   // mis. override jaringan
        }
        if ($env !== []) {
            $files[] = EnvManager::OVERRIDE_FILE;   // selalu paling akhir → menang atas repo
        }

        return $this->orderComposeFiles($files);
    }

    /**
     * Resolusi service + port yang di-proxy Nginx dari input halaman konfirmasi.
     *
     * `primary` berbentuk `<service>:<containerPort>` (boleh hanya `<service>`
     * untuk kompatibilitas). Bila tidak ada/tidak valid dipakai `$fallbackService`
     * + `$preferredPort` (dari langkah analisis), else port pertama service tsb.
     *
     * App yang tidak mem-publikasikan port sama sekali (mis. server database tanpa
     * `ports:`) mengembalikan `['service' => '', 'port' => 0]` — app dibuat tanpa
     * vhost/subdomain, bukan ditolak (SPECS §7.2).
     *
     * @param array<string,array> $services
     * @return array{service:string,port:int}
     */
    protected function resolvePrimarySelection(array $services, string $selection, string $fallbackService = '', int $preferredPort = 0): array
    {
        $service = '';
        $port = 0;
        if ($selection !== '') {
            if (str_contains($selection, ':')) {
                [$svc, $p] = explode(':', $selection, 2);
                $service = trim($svc);
                $port = (int) $p;
            } else {
                $service = trim($selection);
            }
        }

        if ($service === '') {
            $service = $this->resolvePrimaryService($services, $fallbackService);
        } elseif (!isset($services[$service])) {
            throw new RuntimeException('Service "' . $service . '" tidak ditemukan pada compose app.');
        }

        // Port yang valid = port yang benar-benar punya host port di service itu.
        $available = [];
        foreach ((array) ($services[$service]['ports'] ?? []) as $entry) {
            if (!empty($entry['host'])) {
                $available[] = (int) $entry['container'];
            }
        }

        if ($port <= 0 && $preferredPort > 0 && in_array($preferredPort, $available, true)) {
            $port = $preferredPort;
        }
        if ($port > 0 && !in_array($port, $available, true)) {
            throw new RuntimeException(
                'Port ' . $port . ' tidak tersedia pada service "' . $service . '" — pilih salah satu port yang terdaftar.'
            );
        }
        if ($port <= 0) {
            $port = (int) ($available[0] ?? 0);
        }
        if ($port <= 0) {
            // Tidak ada port yang dipublikasikan ke host → app tanpa domain
            // (vhost & subdomain dilewati saat deploy, SPECS §7.2).
            return ['service' => '', 'port' => 0];
        }

        return ['service' => $service, 'port' => $port];
    }

    /**
     * Service primary (service yang sudah punya host port) atau '' bila app tidak
     * mem-publikasikan port sama sekali.
     *
     * @param array<string,array> $services
     */
    private function resolvePrimaryService(array $services, string $primary): string
    {
        $candidates = array_filter(array_keys($services), static fn (string $n): bool => isset($services[$n]['host_port']));
        if ($primary !== '' && in_array($primary, array_keys($services), true) && isset($services[$primary]['host_port'])) {
            return $primary;
        }
        if ($candidates !== []) {
            return (string) array_values($candidates)[0];
        }

        return '';
    }

    /**
     * Tulis docker-compose override berisi ports hasil edit user —
     * file compose asli dari repo tetap bersih (docs/SPECS.md §7.2 langkah 8).
     *
     * Penting: docker compose MENGGABUNGKAN daftar "ports" (base + override,
     * bukan mengganti). Supaya port bawaan repo benar-benar diganti, dipakai
     * dua lapis override sesuai compose spec:
     *   1) docker-compose.override.yml       -> ports: !reset [] (hapus port bawaan)
     *   2) docker-compose.override.ports.yml -> ports: [host:container] (port final)
     *
     * Lapis ketiga (opsional) = override nama container
     * (docker-compose.override.names.yml) bila prefix nama diisi user.
     *
     * @param array                  $pending
     * @param array<string,array>    $services
     * @param array<int,string>      $composeFiles (by reference — ditambah nama override)
     * @param string|null            $containerPrefix '' = pakai nama default compose
     */
    protected function writeOverride(array $pending, array $services, array &$composeFiles, ?string $containerPrefix = null): void
    {
        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];

        // Bentuk berkas = milik `PortOverrides` (jangan dirender ulang di sini).
        // Perilaku lama dipertahankan: kedua berkas SELALU ditulis — juga saat
        // tidak ada service ber-port — dan namanya selalu masuk daftar compose.
        $yaml = PortOverrides::render(PortOverrides::rowsFromServices($services));

        if (file_put_contents($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE, $yaml['reset'], LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . ComposeSource::RESET_OVERRIDE_FILE . '.');
        }
        $composeFiles[] = ComposeSource::RESET_OVERRIDE_FILE;

        if (file_put_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE, $yaml['ports'], LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . ComposeSource::PORTS_OVERRIDE_FILE . '.');
        }
        $composeFiles[] = ComposeSource::PORTS_OVERRIDE_FILE;

        // Lapis 3 (opsional): nama container (container_name) hasil override user.
        // Daftar service diambil dari base compose yang ada di disk (bukan dari
        // $services pemanggil) supaya jalur tab Compose pun ikut tervalidasi
        // replicanya — container_name tidak kompatibel dengan deploy.replicas > 1.
        if ($containerPrefix !== null && $containerPrefix !== '') {
            $declared = ContainerNames::services($dir, $composeFiles);
            ContainerNames::assertNotReplicated($declared);
            ContainerNames::writeOverride($dir, array_keys($declared), $containerPrefix);
            $composeFiles[] = ContainerNames::OVERRIDE_FILE;
        } else {
            ContainerNames::removeOverride($dir);
        }
    }
}
