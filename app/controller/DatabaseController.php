<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Adminer\AdminerHelper;
use app\library\Adminer\AdminerProxy;
use app\library\Adminer\AdminerProxyError;
use app\library\Adminer\AdminerProxyLimitExceeded;
use app\library\Adminer\AdminerTempDir;
use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Db\DbConnectionResolver;
use app\library\Db\DbContainerDetector;
use app\library\Db\DbCredentialResolver;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\AppContainers;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Webman\Http\Response as WebmanResponse;
use Workerman\Timer;

/**
 * Database (MySQL/MariaDB) — halaman daftar container + proxy Adminer.
 *
 * Controller hanya mediator; seluruh logika di app/library. State hanya di sesi
 * (jar cookie helper Adminer per container) — tidak ada properti controller yang
 * menyimpan state lintas-request (Webman persistent, lihat copilot-instructions).
 *
 * Fitur D1 memakai **proxy Adminer** (`AdminerHelper` + `AdminerProxy`) yang
 * meneruskan HTTP ke helper container Adminer tanpa port publik; kredensial DB
 * terdeteksi otomatis dari env app/container (fallback: form login Adminer).
 */
class DatabaseController
{
    /** Prefiks kunci sesi untuk jar cookie Adminer (per container DB). */
    private const ADMINER_SESSION_PREFIX = 'adminer:';

    // ==================================================================
    // Daftar container DB (difilter per kepemilikan app)
    // ==================================================================

    public function index(Request $request)
    {
        $user = current_user();
        $isAdmin = is_admin($user);

        // Non-admin hanya melihat container DB milik app yang boleh diakses
        // (miliknya sendiri atau yang dibagikan kepadanya); container app user
        // lain & container eksternal disembunyikan. Admin melihat semua.
        $apps = AppAccess::visible($this->appStore()->all(), $user);

        $appById = [];
        foreach ($apps as $app) {
            $appById[(string) ($app['id'] ?? '')] = $app;
        }

        $engineError = null;
        $rows = [];
        try {
            // Deteksi server lokal: Engine host ini (seam uji — TIDAK pernah
            // dipakai untuk app di server agent).
            $rows = $this->detector($this->dockerClient())->detectAll($apps, $isAdmin);
        } catch (\Throwable $e) {
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
        }

        // Server agent (F3d): container DB app remote dibaca dari agent tempat app
        // itu berjalan — Engine host control plane tidak pernah disentuh untuk app
        // remote (plan §3 butir 4). Kepemilikan tetap dipetakan di sini (satu
        // sumber: AppStore control plane + AppAccess), agent hanya mendeteksi.
        $agentRows = $this->agentDbRows($apps, $isAdmin);
        if ($agentRows['rows'] !== []) {
            $rows = array_merge($rows, $agentRows['rows']);
        }
        foreach ($agentRows['errors'] as $message) {
            $engineError = $engineError === null ? $message : $engineError . ' | ' . $message;
        }

        // Hak kelola per container: viewer hanya boleh melihat (ability 'database'
        // butuh operator ke atas); container eksternal (tanpa app) hanya admin.
        foreach ($rows as &$row) {
            $owner = $appById[(string) ($row['app_id'] ?? '')] ?? null;
            $row['can_manage'] = $owner !== null
                ? AppAccess::can('database', $owner, $user)
                : $isAdmin;
        }
        unset($row);

        // Peta appId → username owner (kolom audit untuk admin).
        $ownerNamesByApp = [];
        if ($isAdmin) {
            $names = user_names();
            foreach ($apps as $app) {
                $ownerNamesByApp[(string) ($app['id'] ?? '')] = $names[(string) ($app['owner_id'] ?? '')] ?? '';
            }
        }

        return $this->renderView('db/index', [
            'rows' => $rows,
            'engineError' => $engineError,
            'isAdmin' => $isAdmin,
            'ownerNamesByApp' => $ownerNamesByApp,
        ]);
    }

    // ==================================================================
    // Server agent (F3d) — deteksi container DB & proxy Adminer per server
    // ==================================================================

    /**
     * Baris container DB yang hidup **di server agent** untuk app yang boleh
     * dilihat user (satu panggilan per server, bukan per app).
     *
     * Pemetaan kepemilikan memakai `AppStore` control plane — agent tidak
     * mengetahui identitas user dashboard. Non-admin hanya melihat baris
     * container milik app yang boleh diaksesnya (penyaringan sisi server, bukan
     * di view).
     *
     * Kegagalan satu server **tidak** menggagalkan halaman: pesannya
     * dikembalikan di `errors` (server offline ≠ daftar lokal rusak).
     *
     * @param array<int,array<string,mixed>> $apps app yang boleh dilihat user
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    private function agentDbRows(array $apps, bool $isAdmin): array
    {
        $rows = [];
        $errors = [];

        // Kelompokkan app per `server_id` (hanya yang bukan server lokal).
        $byServer = [];
        foreach ($apps as $app) {
            try {
                $server = $this->serverResolver()->resolveForApp($app);
            } catch (\Throwable $e) {
                continue; // server_id tak dikenal: AppAccess sudah menanganinya di tempat lain
            }
            if (ServerResolver::isLocal($server)) {
                continue;
            }
            $byServer[(string) ($server['id'] ?? '')] = $server;
        }

        // Admin melihat kepemilikan seluruh app (termasuk milik user lain) pada
        // server itu; non-admin hanya app yang boleh diakses.
        $ownerApps = $isAdmin ? $this->appStore()->all() : $apps;

        foreach ($byServer as $serverId => $server) {
            $label = trim((string) ($server['name'] ?? '')) !== '' ? (string) $server['name'] : $serverId;
            try {
                $agent = $this->agentDeployerForServer($server);
                if ($agent === null) {
                    continue;
                }
                $detected = $agent->dbContainers();
            } catch (\Throwable $e) {
                $errors[] = 'Server "' . $label . '": ' . AgentError::sanitize($e->getMessage());
                continue;
            }

            $ownerByContainer = [];
            foreach ($ownerApps as $app) {
                if ((string) ($app['server_id'] ?? '') !== $serverId) {
                    continue;
                }
                foreach (($app['containers'] ?? []) as $container) {
                    $name = is_array($container) ? (string) ($container['container_name'] ?? '') : '';
                    if ($name !== '') {
                        $ownerByContainer[$name] = $app;
                    }
                }
            }

            $visible = [];
            foreach ($apps as $app) {
                if ((string) ($app['server_id'] ?? '') === $serverId) {
                    $visible[(string) ($app['id'] ?? '')] = true;
                }
            }

            foreach ($detected as $row) {
                $name = (string) ($row['container_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $owner = $ownerByContainer[$name] ?? null;
                if (!$isAdmin && ($owner === null || !isset($visible[(string) ($owner['id'] ?? '')]))) {
                    continue; // milik app user lain / eksternal — tidak ditampilkan
                }

                $rows[] = [
                    'container_name' => $name,
                    'container_id' => (string) ($row['container_id'] ?? ''),
                    'image' => (string) ($row['image'] ?? ''),
                    'state' => (string) ($row['state'] ?? 'unknown'),
                    'status' => (string) ($row['status'] ?? ''),
                    'owned' => $owner !== null,
                    'app_id' => $owner !== null ? (string) ($owner['id'] ?? '') : null,
                    'app_name' => $owner !== null ? (string) ($owner['name'] ?? '') : null,
                    'server_id' => $serverId,
                    'server_name' => (string) ($server['name'] ?? ''),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['container_name'], (string) $b['container_name']));

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Deployer agent untuk app, atau `null` bila app dilayani server lokal
     * (seam uji: subclass menyuntikkan deployer dengan klien agent tiruan).
     *
     * @param array<string,mixed> $app
     * @throws AgentError server `kind=agent` cacat — fail-fast, tanpa jalur lokal
     */
    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return AgentDeployer::forApp($app);
    }

    /**
     * Deployer agent untuk dokumen server (operasi tingkat server: daftar
     * container DB) — seam uji.
     *
     * @param array<string,mixed> $server
     * @throws AgentError
     */
    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return AgentDeployer::forServer($server);
    }

    // ==================================================================
    // Adminer (fitur D1) — reverse proxy ke helper container
    // ==================================================================

    /**
     * Reverse proxy Adminer: `ANY /database/{container}/adminer[/{path:.*}]`.
     *
     * Alur: AppAccess (penolakan → 404) → resolve container DB (nama container
     * dari request tidak pernah dipercaya) → pastikan helper Adminer hidup dan
     * terhubung ke network app target → kredensial DB dari env app/container →
     * teruskan permintaan ke helper via {@see AdminerProxy} (jar cookie
     * server-side di sesi + auto-login sisi server).
     *
     * Tidak ada state di properti controller: jar ada di sesi (per container),
     * berkas respons di `runtime/adminer-proxy/` (dibersihkan terjadwal oleh
     * proxy). Helper tidak punya port publik dan tidak ada route publik baru —
     * semuanya di balik login + AppAccess.
     *
     * @param string $path path SETELAH prefix (`''` = root Adminer)
     */
    public function adminer(Request $request, string $container, string $path = '')
    {
        if (!$this->validContainerName($container)) {
            return $this->adminerError('Nama container tidak valid.', 404);
        }

        // Otorisasi SEBELUM efek samping apa pun (AppAccessDenied → 404).
        $app = $this->findOwningApp($container);
        if ($app !== null && AppContainers::resolve($app, $container) === null) {
            throw new AppAccessDenied('database', $app);
        }

        // App di server agent (F3d): helper Adminer hidup di sana — control plane
        // hanya mem-forward (body mentah + streaming berkas), tidak pernah
        // menjalankan helper/Docker di host ini.
        if ($app !== null) {
            try {
                $agent = $this->agentDeployer($app);
            } catch (\Throwable $e) {
                return $this->adminerError(AgentError::sanitize($e->getMessage()), 502);
            }
            if ($agent !== null) {
                return $this->adminerViaAgent($request, $app, $agent, $container, $path);
            }
        }

        try {
            $inspect = $this->inspectDbContainer($container);
        } catch (RuntimeException $e) {
            return $this->adminerError($e->getMessage(), 502);
        }

        $network = AdminerHelper::targetNetwork($inspect, (string) ($app['name'] ?? ''));

        try {
            $helper = $this->adminerHelper();
            $helper->ensureRunning();
            $helper->ensureOnNetwork($network);
        } catch (\Throwable $e) {
            return $this->adminerError('Helper Adminer tidak siap: ' . $e->getMessage(), 502);
        }

        // Kredensial **tidak wajib** ada. Bila tidak terdeteksi (mis. image DB
        // custom tanpa env kredensial baku), AdminerProxy masuk mode manual:
        // helper diberi `?server=<host:port>` sehingga halaman login Adminer
        // tampil dengan kolom Server ter-prefill dan user mengetik sendiri
        // (tidak boleh jadi regresi 409).
        $credentials = (new DbCredentialResolver())->resolve($app ?? [], $inspect);

        $host = AdminerHelper::databaseHost($inspect, $network);
        $port = (new DbConnectionResolver())->internalPort($inspect);
        if ($host === '' || $port <= 0) {
            // Satu-satunya kasus "benar-benar tidak bisa dilayani": container DB
            // tanpa alamat TCP yang bisa dijangkau helper.
            return $this->adminerError('Container DB tidak punya alamat TCP yang bisa dijangkau helper Adminer.', 502);
        }

        [$jar, $helperId] = $this->adminerState($request, $container, $helper);

        // Konteks proxy tepercaya: `X-Forwarded-Proto`/`X-Forwarded-For` yang
        // dikirim ke helper dibentuk dari request dashboard (skema + REMOTE_ADDR),
        // BUKAN dari header kiriman klien — lihat adminerProxyContext().
        $forwarded = $this->adminerForwardContext($request);

        $proxy = new AdminerProxy([
            'base_url' => $helper->baseUrl(),
            'prefix' => $this->adminerPrefix($container),
            'forwarded_proto' => $forwarded['proto'],
            'forwarded_for' => $forwarded['for'],
            'credentials' => [
                'driver' => 'server',
                'server' => $host . ':' . $port,
                'username' => (string) ($credentials['username'] ?? ''),
                'password' => (string) ($credentials['password'] ?? ''),
                'db' => (string) ($credentials['database'] ?? ''),
            ],
        ]);

        $method = strtoupper((string) $request->method());
        // Body mentah (JANGAN `post()` — multipart import tidak boleh diparse).
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string) $request->rawBody() : null;

        try {
            $result = $proxy->forward(
                $method,
                $path,
                (string) $request->queryString(),
                $body,
                $this->adminerForwardHeaders($request),
                $jar
            );
        } catch (AdminerProxyLimitExceeded $e) {
            return $this->adminerError($e->getMessage(), 413);
        } catch (InvalidArgumentException $e) {
            return $this->adminerError($e->getMessage(), 400);
        } catch (AdminerProxyError $e) {
            return $this->adminerError($e->getMessage(), 502);
        }

        $request->session()->set(self::ADMINER_SESSION_PREFIX . $container, [
            'helper_id' => $helperId,
            'jar' => $jar,
        ]);

        // Sesi Adminer kedaluwarsa pada request yang mengubah data: body Adminer
        // tidak berguna bagi user (input form-nya hilang) → halaman pesan Rames.
        if (($result['session_expired'] ?? false) === true) {
            $this->audit($app, $container, 'adminer sesi kedaluwarsa');

            return $this->adminerSessionExpired($container);
        }

        // Audit trail akses DB (sekali per navigasi halaman, bukan per aset).
        if ($path === '' && $method === 'GET') {
            $this->audit($app, $container, 'adminer');
        }

        $status = (int) $result['status'];
        $headers = (array) $result['headers'];

        if ((string) $result['body_file'] !== '') {
            // Berkas dibaca Workerman SETELAH handler kembali (proxy sudah
            // menjadwalkan pembersihan) → byte tidak ditahan di memori PHP.
            return (new WebmanResponse($status, $headers))->withFile((string) $result['body_file']);
        }

        return new WebmanResponse($status, $headers);
    }

    // ==================================================================
    // Adminer per server agent (F3d) — control plane mem-forward
    // ==================================================================

    /**
     * Teruskan satu permintaan Adminer ke helper **di server agent**.
     *
     * Kontrak yang dijaga (sama dengan jalur lokal):
     *  - **body mutasi mentah** (`rawBody()`, tidak pernah `post()`/parse) ⇒
     *    unggahan multipart impor Adminer utuh;
     *  - **prefix dilaporkan lewat `X-Forwarded-Prefix`** (dari konfigurasi
     *    halaman, bukan dari klien) ⇒ HTML Adminer tidak disunting;
     *  - jar cookie Adminer tetap **server-side di sesi dashboard** (dititipkan
     *    ke agent lewat header `X-Rames-Adminer-Jar`) sehingga agent tetap tanpa
     *    state lintas-request;
     *  - batas ukuran respons 64 MiB & timeout ≤30 s (`AgentClient`).
     *
     * @param array<string,mixed> $app
     */
    private function adminerViaAgent(
        Request $request,
        array $app,
        AgentDeployer $agent,
        string $container,
        string $path,
    ): WebmanResponse {
        $prefix = $this->adminerPrefix($container);
        $jar = $this->sessionJar($request, $container);
        $forwarded = $this->adminerForwardContext($request);

        $headers = $this->adminerForwardHeaders($request);
        $headers['X-Forwarded-Prefix'] = $prefix;
        $headers['X-Forwarded-Proto'] = $forwarded['proto'];
        if ($forwarded['for'] !== '') {
            $headers['X-Forwarded-For'] = $forwarded['for'];
        }
        $headers[AgentAdminerController::HEADER_JAR] = AgentAdminerController::encodeJar($jar);

        $method = strtoupper((string) $request->method());
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string) $request->rawBody() : null;

        try {
            $result = $agent->adminerForward(
                $app,
                $container,
                $method,
                $path,
                (string) $request->queryString(),
                $body,
                $headers
            );
        } catch (AgentError $e) {
            return $this->adminerError(AgentError::sanitize($e->getMessage()), AgentDeployer::failureCode($e));
        }

        if (($result['too_large'] ?? false) === true) {
            return $this->adminerError(AdminerProxy::LIMIT_MESSAGE, 413);
        }

        // Galat domain agent dikembalikan sebagai respons polos Rames (bukan body
        // JSON mesin); status HTTP-nya tetap milik agent.
        $agentError = $this->agentJsonError($result);
        if ($agentError !== null) {
            $this->discardAgentBody((string) $result['body_file']);

            return $this->adminerError($agentError[1], $agentError[0]);
        }

        $responseHeaders = $result['headers'];
        $this->rememberJar(
            $request,
            $container,
            AgentAdminerController::decodeJar(self::agentHeader($responseHeaders, AgentAdminerController::HEADER_JAR))
        );

        // Sesi helper basi pada request yang mengubah data: halaman pesan Rames
        // (bentuk sama dengan jalur lokal), bukan body Adminer yang tak berguna.
        if (trim(self::agentHeader($responseHeaders, AgentAdminerController::HEADER_SESSION_EXPIRED)) === '1') {
            $this->discardAgentBody((string) $result['body_file']);
            $this->audit($app, $container, 'adminer sesi kedaluwarsa');

            return $this->adminerSessionExpired($container);
        }

        if ($path === '' && $method === 'GET') {
            $this->audit($app, $container, 'adminer');
        }

        $out = $this->agentResponseHeaders($responseHeaders, $result);
        if ((string) $result['body_file'] !== '') {
            $this->scheduleTempCleanup((string) $result['body_file']);

            return (new WebmanResponse((int) $result['status'], $out))->withFile((string) $result['body_file']);
        }

        return new WebmanResponse((int) $result['status'], $out);
    }

    /**
     * Jar cookie Adminer container ini dari sesi dashboard (kunci sama dengan
     * jalur lokal supaya berganti jalur server tidak mencampur sesi helper).
     *
     * `protected` = seam uji (subclass memakai jar in-memory) — produksi membaca
     * sesi Webman seperti jalur lokal.
     *
     * @return array<string,string>
     */
    protected function sessionJar(Request $request, string $container): array
    {
        $state = $request->session()->get(self::ADMINER_SESSION_PREFIX . $container);
        $jar = is_array($state) ? ($state['jar'] ?? []) : [];

        return is_array($jar) ? $jar : [];
    }

    /**
     * Simpan jar cookie Adminer (dirotasi agent) ke sesi dashboard.
     *
     * @param array<string,string> $jar
     */
    protected function rememberJar(Request $request, string $container, array $jar): void
    {
        $request->session()->set(self::ADMINER_SESSION_PREFIX . $container, ['jar' => $jar]);
    }

    /**
     * Nilai satu header dari daftar header Guzzle (case-insensitive).
     *
     * @param array<string,array<int,string>> $headers
     */
    private static function agentHeader(array $headers, string $name): string
    {
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) === strtolower($name)) {
                $values = is_array($values) ? $values : [$values];

                return trim(implode(', ', array_map('strval', $values)));
            }
        }

        return '';
    }

    /**
     * Header respons agent yang boleh diteruskan ke browser.
     *
     *  - header hop-by-hop/`Content-Length` dibuang (Workerman yang mengurusnya
     *    saat membaca berkas);
     *  - `X-Rames-Adminer-*` **tidak** pernah keluar ke browser: jar adalah state
     *    server-side dan penanda sesi kedaluwarsa hanya untuk control plane.
     *
     * @param array<string,array<int,string>> $headers
     * @return array<string,array<int,string>>
     */
    private function agentResponseHeaders(array $headers, array $result): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $key = (string) $name;
            $lower = strtolower($key);
            if (in_array($lower, ['connection', 'keep-alive', 'transfer-encoding', 'content-length', 'content-encoding'], true)) {
                continue;
            }
            if (str_starts_with($lower, 'x-rames-')) {
                continue;
            }
            $out[$key] = is_array($values) ? array_values(array_map('strval', $values)) : [(string) $values];
        }

        return $out;
    }

    /**
     * Jadwalkan penghapusan berkas respons sementara control plane (Workerman
     * membaca berkas setelah handler kembali) + prune oportunistik sebagai
     * backstop bila `Timer` tidak tersedia (proses CLI/uji).
     */
    private function scheduleTempCleanup(string $path): void
    {
        AdminerTempDir::prune(dirname($path));
        try {
            Timer::add(600, static function () use ($path): void {
                @unlink($path);
            }, [], false);
        } catch (\Throwable $e) {
            // tanpa event loop: andalkan prune oportunistik di atas
        }
    }

    private function discardAgentBody(string $path): void
    {
        if ($path !== '') {
            @unlink($path);
        }
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    private function inspectDbContainer(string $container): array
    {
        $docker = $this->dockerClient();
        $inspect = $docker->inspectContainer($container);
        if (!(new DbContainerDetector($docker))->isDbContainer($inspect)) {
            throw new RuntimeException('Container "' . $container . '" bukan server MySQL/MariaDB.');
        }
        return $inspect;
    }

    /**
     * Cari app pemilik container DB + pastikan user berhak mengelolanya.
     *
     * Dipakai endpoint /database (proxy Adminer) sehingga otorisasi hanya ada
     * di satu tempat. Container DB yang tidak terdaftar di apps.json (mis.
     * dibuat manual di host) hanya boleh diakses admin.
     *
     * `protected` (bukan private) semata agar unit test bisa men-bypass
     * `AppStore`/`AppAccess` (data runtime nyata) — produksi tidak berubah.
     *
     * @throws AppAccessDenied dirender sebagai 404 oleh webman
     */
    protected function findOwningApp(string $container): ?array
    {
        $owner = null;
        foreach ($this->appStore()->all() as $app) {
            foreach (($app['containers'] ?? []) as $c) {
                if (($c['container_name'] ?? '') === $container) {
                    $owner = $app;
                    break 2;
                }
            }
        }

        if ($owner === null) {
            if (!is_admin()) {
                throw new AppAccessDenied('database', null);
            }
            return null;
        }

        AppAccess::require('database', $owner, current_user());

        return $owner;
    }

    /**
     * Store app control plane (seam uji: path temp tanpa `database/` nyata).
     */
    protected function appStore(): AppStore
    {
        return new AppStore();
    }

    /**
     * Registry server (seam uji: store temp tanpa `database/` nyata).
     */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Detektor container DB — seam uji supaya engine dapat diganti (dan
     * dibuktikan tidak tersentuh untuk app di server agent).
     */
    protected function detector(DockerClient $docker): DbContainerDetector
    {
        return new DbContainerDetector($docker);
    }

    /**
     * Engine Docker **host ini** (jalur lokal) — seam uji supaya daemon lokal
     * dapat dibuktikan TIDAK tersentuh untuk app di server agent.
     */
    protected function dockerClient(): DockerClient
    {
        return new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
    }

    /**
     * Helper Adminer **host ini** (jalur lokal) — seam uji supaya uji jalur lokal
     * tidak menjalankan `docker run` nyata.
     */
    protected function adminerHelper(): AdminerHelper
    {
        return new AdminerHelper();
    }

    /**
     * Envelope galat JSON dari agent (`{code:1,error,reason}`, `Content-Type`
     * `application/json`) ⇒ pasangan `[status, pesan]` untuk respons polos
     * `Adminer: …`.
     *
     * Agent memakai status HTTP yang benar untuk galat domainnya (404 container
     * tak ada, 502 helper gagal, 400 nama tak sah); control plane **tidak**
     * membocorkan body JSON mesin ke browser (dan tidak pernah meneruskan pesan
     * mentah agent tanpa sanitasi). Respons helper Adminer sendiri selalu HTML,
     * jadi deteksi ini tidak pernah salah menandai halaman Adminer.
     *
     * @param array{status:int,body_file:string} $result
     * @return array{0:int,1:string}|null null = bukan galat JSON
     */
    private function agentJsonError(array $result): ?array
    {
        $status = (int) ($result['status'] ?? 0);
        if ($status < 400) {
            return null;
        }

        $file = (string) ($result['body_file'] ?? '');
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file, false, null, 0, 4096);
        if ($raw === false || !str_starts_with(ltrim($raw), '{')) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $message = trim((string) ($decoded['error'] ?? $decoded['msg'] ?? ''));
        if ($message === '') {
            $message = 'Server agent menolak permintaan Adminer (HTTP ' . $status . ').';
        }

        return [$status, AgentError::sanitize($message)];
    }

    private function validContainerName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) === 1;
    }

    /**
     * Prefix URL publik halaman Adminer container ini.
     */
    private function adminerPrefix(string $container): string
    {
        $base = rtrim('/' . trim((string) config('deploy.adminer_prefix_base', '/database'), '/'), '/');

        return $base . '/' . rawurlencode($container) . '/adminer';
    }

    /**
     * State jar Adminer per container DB (state hanya di sesi — bukan properti
     * controller). Jar **tidak** boleh dipakai lintas container: `Server`/landing
     * di dalamnya spesifik untuk satu container DB.
     *
     * Bila id helper berubah (helper di-recreate → sesi PHP Adminer hilang), jar
     * lama dibuang supaya auto-login dijalankan ulang, bukan menampilkan form
     * login Adminer.
     *
     * @return array{0:array<string,string>,1:string} jar + id helper saat ini
     */
    private function adminerState(Request $request, string $container, AdminerHelper $helper): array
    {
        $helperId = (string) ($helper->containerId() ?? '');
        $state = $request->session()->get(self::ADMINER_SESSION_PREFIX . $container);

        if (!is_array($state) || (string) ($state['helper_id'] ?? '') !== $helperId) {
            return [[], $helperId];
        }

        $jar = $state['jar'] ?? [];

        return [is_array($jar) ? $jar : [], $helperId];
    }

    /**
     * Subset header browser yang boleh diteruskan ke helper (proxy memfilternya
     * ulang; cookie sesi Rames TIDAK pernah diteruskan).
     *
     * `X-Forwarded-*` **tidak** diambil dari sini: konteks proxy dibentuk
     * terpisah dari request dashboard ({@see self::adminerProxyContext()}) agar
     * header kiriman klien tidak bisa dipalsukan, dan `X-Forwarded-Prefix`
     * selalu ditulis ulang proxy dari konfigurasi prefix halaman.
     *
     * @return array<string,string>
     */
    private function adminerForwardHeaders(Request $request): array
    {
        $headers = [];
        foreach (['content-type', 'accept', 'accept-language', 'referer', 'origin'] as $name) {
            $value = $request->header($name);
            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Konteks `X-Forwarded-*` untuk {@see AdminerProxy} dari request dashboard.
     *
     * @return array{proto:string,for:string}
     */
    private function adminerForwardContext(Request $request): array
    {
        $connection = $request->connection;
        $tlsHere = $connection !== null && strtolower((string) $connection->transport) === 'ssl';

        return self::adminerProxyContext(
            (string) $request->getRemoteIp(),
            (string) $request->header('x-forwarded-proto', ''),
            $tlsHere
        );
    }

    /**
     * Skema + alamat klien dashboard yang diteruskan ke helper (statik murni —
     * dipakai {@see self::adminerForwardContext()} dan diuji tanpa HTTP).
     *
     * - `proto` = `https` bila **(a)** listener Webman sendiri ber-TLS, atau
     *   **(b)** `X-Forwarded-Proto: https` datang dari **peer internal** — Nginx
     *   host/dashboard yang meneruskan permintaan. Aturan peer-internal-nya sama
     *   dengan `Request::getRealIp()`: klien luar yang menembus port dashboard
     *   tidak dipercaya, sehingga header skema kiriman klien tidak bisa
     *   memalsukan skema. Selain itu `http`.
     * - `for` = `REMOTE_ADDR` permintaan dashboard (satu IP valid saja);
     *   `''` bila kosong/tidak valid/`0.0.0.0`/`::` → proxy tidak mengirim
     *   `X-Forwarded-For` sama sekali.
     *
     * @return array{proto:string,for:string}
     */
    public static function adminerProxyContext(string $remoteIp, string $forwardedProto, bool $tlsTerminatedHere = false): array
    {
        $proto = 'http';
        if ($tlsTerminatedHere) {
            $proto = 'https';
        } elseif (Request::isIntranetIp($remoteIp) && strtolower(trim($forwardedProto)) === 'https') {
            $proto = 'https';
        }

        return ['proto' => $proto, 'for' => self::adminerClientIp($remoteIp)];
    }

    /**
     * `REMOTE_ADDR` yang layak diteruskan (IP valid, bukan unspecified).
     */
    private static function adminerClientIp(string $remoteIp): string
    {
        $ip = trim($remoteIp);
        if ($ip === '' || $ip === '0.0.0.0' || $ip === '::') {
            return '';
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * Respons galat polos halaman Adminer — selalu `text/plain` dan tidak pernah
     * memuat kredensial DB.
     */
    private function adminerError(string $message, int $status): WebmanResponse
    {
        return new WebmanResponse(
            $status,
            ['Content-Type' => ['text/plain; charset=utf-8']],
            'Adminer: ' . $message
        );
    }

    /**
     * Halaman pesan kecil milik Rames (bukan HTML Adminer) untuk request yang
     * mengubah data ketika sesi Adminer ternyata sudah kedaluwarsa.
     *
     * Kenapa perlu: form Adminer mengikat token CSRF ke sesi
     * (`AdminerProxy::recoverLoginPage()` tidak memalsukan token), jadi POST dari
     * sesi lama tetap ditolak Adminer setelah login ulang — input user hilang dan
     * body Adminer tidak berguna. Halaman ini menjelaskan keadaannya; sengaja
     * TIDAK menyediakan tombol yang mengirim ulang aksi user (token terikat sesi).
     * `409 Conflict` = permintaan berbenturan dengan state sesi (dipakai HANYA
     * untuk kasus ini; kredensial tak terdeteksi bukan 409).
     */
    private function adminerSessionExpired(string $container): WebmanResponse
    {
        return $this->renderView('db/session-expired', [
            'container' => $container,
            'retryUrl' => $this->adminerPrefix($container) . '/',
        ])->withStatus(409)->withHeader('Cache-Control', 'no-store');
    }

    protected function renderView(string $template, array $vars): WebmanResponse
    {
        return view($template, $vars);
    }

    /**
     * Audit trail akses DB — `protected` (bukan private) semata agar unit test
     * tidak menulis ke `runtime/logs/db` nyata.
     */
    protected function audit(?array $app, string $container, string $action): void
    {
        $dir = runtime_path() . '/logs/db';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $user = '?';
        try {
            if (function_exists('current_user')) {
                $cu = current_user();
                if (is_array($cu)) {
                    $user = (string) ($cu['username'] ?? '?');
                }
            }
        } catch (\Throwable $e) {
            $user = '?';
        }
        $line = sprintf(
            "[%s] %s | container=%s | app=%s | %s\n",
            date('c'),
            $user,
            $container,
            $app['name'] ?? 'external',
            $action
        );
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
