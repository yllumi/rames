<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Adminer\AdminerHelper;
use app\library\Adminer\AdminerProxy;
use app\library\Adminer\AdminerProxyError;
use app\library\Adminer\AdminerProxyLimitExceeded;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Db\DbConnectionResolver;
use app\library\Db\DbContainerDetector;
use app\library\Db\DbCredentialResolver;
use app\library\Docker\AppContainers;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Webman\Http\Response as WebmanResponse;

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
        $apps = AppAccess::visible((new AppStore())->all(), $user);

        $appById = [];
        foreach ($apps as $app) {
            $appById[(string) ($app['id'] ?? '')] = $app;
        }

        $engineError = null;
        $rows = [];
        try {
            $rows = (new DbContainerDetector())->detectAll($apps, $isAdmin);
        } catch (\Throwable $e) {
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
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

        return view('db/index', [
            'rows' => $rows,
            'engineError' => $engineError,
            'isAdmin' => $isAdmin,
            'ownerNamesByApp' => $ownerNamesByApp,
        ]);
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

        try {
            $inspect = $this->inspectDbContainer($container);
        } catch (RuntimeException $e) {
            return $this->adminerError($e->getMessage(), 502);
        }

        $network = AdminerHelper::targetNetwork($inspect, (string) ($app['name'] ?? ''));

        try {
            $helper = new AdminerHelper();
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
    // Helper internal
    // ==================================================================

    private function inspectDbContainer(string $container): array
    {
        $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
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
     * @throws AppAccessDenied dirender sebagai 404 oleh webman
     */
    private function findOwningApp(string $container): ?array
    {
        $owner = null;
        foreach ((new AppStore())->all() as $app) {
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
        return view('db/session-expired', [
            'container' => $container,
            'retryUrl' => $this->adminerPrefix($container) . '/',
        ])->withStatus(409)->withHeader('Cache-Control', 'no-store');
    }

    private function audit(?array $app, string $container, string $action): void
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
