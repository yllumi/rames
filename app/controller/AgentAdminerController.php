<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Adminer\AdminerHelper;
use app\library\Adminer\AdminerProxy;
use app\library\Adminer\AdminerProxyError;
use app\library\Adminer\AdminerProxyLimitExceeded;
use app\library\Agent\AgentAppProjection;
use app\library\Db\DbConnectionResolver;
use app\library\Db\DbContainerDetector;
use app\library\Db\DbCredentialResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

/**
 * Database/Adminer **sisi agent** (F3d multi-server, plan §4.1/§5):
 *
 * ```
 * GET  /database/containers                 → {code:0,data:{rows}}   (deteksi server ini)
 * ANY  /adminer/{container}[/{path:.*}]     → respons helper Adminer (HTML/berkas)
 * ```
 *
 * Dipanggil control plane (`DatabaseController` ⇒ `AgentDeployer`), bukan UI:
 * route ini hanya terdaftar di mode `agent` dan otomatis tertutup
 * `AgentAuthMiddleware` (token + timestamp + nonce + signature).
 *
 * Aturan yang dijaga kelas ini: **helper Adminer hidup & dipanggil di server
 * ini**. Helper dijalankan `AdminerHelper` (image `adminer:6`, tanpa port
 * publik) pada Engine server agent, dan proxy HTTP-nya memakai `AdminerProxy`
 * yang sama dengan jalur lokal — Docker/network/proc host control plane tidak
 * pernah tersentuh (plan §3 butir 4).
 *
 * Kontrak wirisan dari jalur lokal (`DatabaseController::adminer()`):
 *  - **body POST/PUT/PATCH/DELETE diteruskan mentah** (`rawBody()`), tidak
 *    pernah diparse — unggahan multipart impor Adminer harus utuh;
 *  - **prefix dilaporkan lewat `X-Forwarded-Prefix`** (dari control plane), HTML
 *    Adminer **tidak** pernah disunting;
 *  - jar cookie Adminer (state sesi helper) **tidak** disimpan di agent: ia
 *    dititipkan control plane lewat header `X-Rames-Adminer-Jar` (base64 JSON)
 *    dan dikembalikan pada respons — agent tetap tanpa state lintas-request;
 *  - batas ukuran respons = `deploy.adminer_proxy_max_bytes` (64 MiB, tidak
 *    dinaikkan) + timeout ≤30 s.
 *
 * Otorisasi **tidak** dilakukan di sini (agent tidak punya identitas user
 * dashboard): ability `database` + kepemilikan container sudah dicek control
 * plane sebelum memanggil. Nama container dari request tetap divalidasi bentuk
 * dan diverifikasi sebagai container DB **server ini**.
 */
class AgentAdminerController
{
    /** Header titipan jar cookie Adminer (state sesi helper). */
    public const HEADER_JAR = 'X-Rames-Adminer-Jar';

    /** Penanda respons "sesi Adminer kedaluwarsa" untuk control plane. */
    public const HEADER_SESSION_EXPIRED = 'X-Rames-Adminer-Session-Expired';

    /** Batas ukuran body request yang diteruskan (byte) — sama dengan respons. */
    public const MAX_BODY_BYTES = 67108864;

    /** Pola nama container yang boleh masuk route. */
    private const CONTAINER_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /** Header respons helper yang boleh diteruskan (− hop-by-hop & cookie). */
    private const RESPONSE_DROP = [
        'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'set-cookie',
        'content-encoding', 'trailer', 'upgrade', 'proxy-authenticate', 'proxy-authorization',
        'te',
    ];

    private ?AppStore $apps;

    private ?DockerClient $docker;

    /**
     * @param AppStore|null     $apps   proyeksi app agent (seam uji; null ⇒ bawaan)
     * @param DockerClient|null $docker Engine API server ini (seam uji; null ⇒ bawaan)
     */
    public function __construct(?AppStore $apps = null, ?DockerClient $docker = null)
    {
        $this->apps = $apps;
        $this->docker = $docker;
    }

    // ==================================================================
    // GET /database/containers — deteksi container DB server ini
    // ==================================================================

    /**
     * Daftar container MySQL/MariaDB **server ini** (deteksi `DbContainerDetector`
     * yang sama dengan halaman `/database` lokal). Kepemilikan app **tidak**
     * ditentukan di sini: control plane memetakan container → app lewat
     * `AppStore`-nya + `AppAccess::visible()`.
     */
    public function containers(Request $request): Response
    {
        try {
            $rows = (new DbContainerDetector($this->dockerClient()))->detectAll([], true);
        } catch (\Throwable $e) {
            return $this->error('Gagal membaca container database di server agent: ' . $e->getMessage(), 'docker_failed', 502);
        }

        // Hanya field deteksi yang keluar (tanpa env/inspect mentah).
        $rows = array_map(static fn (array $row): array => [
            'container_name' => (string) ($row['container_name'] ?? ''),
            'container_id' => (string) ($row['container_id'] ?? ''),
            'image' => (string) ($row['image'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ], $rows);

        return json(['code' => 0, 'data' => ['rows' => array_values($rows)]]);
    }

    // ==================================================================
    // ANY /adminer/{container}[/{path}] — reverse proxy ke helper Adminer
    // ==================================================================

    /**
     * @param string $path path SETELAH prefix (`''` = root Adminer)
     */
    public function proxy(Request $request, string $container, string $path = ''): Response
    {
        if (preg_match(self::CONTAINER_PATTERN, $container) !== 1) {
            return $this->error('Nama container tidak valid.', 'invalid_container', 400);
        }

        try {
            $app = $this->owningApp($container);
        } catch (\Throwable) {
            return $this->error('Gagal membaca proyeksi app di server agent.', 'store_failed', 500);
        }

        try {
            $docker = $this->dockerClient();
            $inspect = $docker->inspectContainer($container);
        } catch (\Throwable $e) {
            return $this->error('Container tidak ditemukan di server agent: ' . $e->getMessage(), 'not_found', 404);
        }

        if (!(new DbContainerDetector($docker))->isDbContainer($inspect)) {
            return $this->error(
                'Container "' . $container . '" bukan server MySQL/MariaDB.',
                'not_db_container',
                404
            );
        }

        $project = (string) ($app['name'] ?? '');
        $network = AdminerHelper::targetNetwork($inspect, $project);

        try {
            $helper = $this->helper($docker);
            $helper->ensureRunning();
            $helper->ensureOnNetwork($network);
        } catch (\Throwable $e) {
            return $this->error('Helper Adminer tidak siap: ' . $e->getMessage(), 'helper_failed', 502);
        }

        $credentials = (new DbCredentialResolver())->resolve($app ?? [], $inspect);
        $host = AdminerHelper::databaseHost($inspect, $network);
        $port = (new DbConnectionResolver($docker))->internalPort($inspect);
        if ($host === '' || $port <= 0) {
            return $this->error(
                'Container DB tidak punya alamat TCP yang bisa dijangkau helper Adminer.',
                'no_address',
                502
            );
        }

        $proxy = $this->adminerProxy([
            'base_url' => $helper->baseUrl(),
            'prefix' => $this->publicPrefix($request, $container),
            'forwarded_proto' => $this->forwardedProto($request),
            'forwarded_for' => $this->forwardedFor($request),
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
        if ($body !== null && strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(
                AdminerProxy::LIMIT_MESSAGE,
                'too_large',
                413
            );
        }

        $jar = self::decodeJar((string) $request->header(self::HEADER_JAR, ''));

        try {
            $result = $proxy->forward(
                $method,
                $path,
                (string) $request->queryString(),
                $body,
                $this->forwardHeaders($request),
                $jar
            );
        } catch (AdminerProxyLimitExceeded $e) {
            return $this->error($e->getMessage(), 'too_large', 413);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'bad_request', 400);
        } catch (AdminerProxyError $e) {
            return $this->error($e->getMessage(), 'proxy_failed', 502);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'helper_failed', 502);
        }

        $headers = self::forwardResponseHeaders((array) $result['headers']);
        $headers[self::HEADER_JAR] = self::encodeJar($jar);

        if (($result['session_expired'] ?? false) === true) {
            // Sesi helper basi pada request yang mengubah data: input user tidak
            // berguna lagi (token form terikat sesi). Control plane yang
            // menampilkan halaman pesan Rames (jalur lokal memakai 409 + view).
            $headers[self::HEADER_SESSION_EXPIRED] = '1';
        }

        $status = (int) $result['status'];
        if ((string) $result['body_file'] !== '') {
            // Workerman membaca berkas SETELAH handler kembali (proxy sudah
            // menjadwalkan pembersihan) → byte tidak ditahan di memori PHP.
            return (new Response($status, $headers))->withFile((string) $result['body_file']);
        }

        return new Response($status, $headers);
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    /**
     * App proyeksi yang memuat container ini (untuk kredensial + nama project).
     *
     * Container milik app yang tidak lagi ada di proyeksi tetap dilayani dengan
     * `null` (mode manual Adminer) — sama seperti jalur lokal untuk container
     * "eksternal"; otorisasi sudah diputuskan control plane.
     */
    private function owningApp(string $container): ?array
    {
        foreach ($this->appStore()->all() as $app) {
            foreach (($app['containers'] ?? []) as $c) {
                if (is_array($c) && (string) ($c['container_name'] ?? '') === $container) {
                    return $app;
                }
            }
        }

        return null;
    }

    /**
     * Prefix URL publik halaman Adminer — **dari control plane** lewat
     * `X-Forwarded-Prefix` (bukan dari browser: header itu tidak pernah
     * diteruskan ke helper oleh klien, lihat `AdminerProxy`).
     *
     * Nilai tidak sah/kosong ⇒ fallback path route agent (`/adminer/{container}`)
     * supaya halaman tetap tampil walau control plane versi lama tidak mengirim
     * header ini.
     */
    private function publicPrefix(Request $request, string $container): string
    {
        $prefix = trim((string) $request->header('x-forwarded-prefix', ''));
        if ($prefix === '' || preg_match('#^/[^\r\n\0]*$#', $prefix) !== 1) {
            return '/adminer/' . rawurlencode($container);
        }

        return '/' . trim($prefix, '/');
    }

    /**
     * Skema eksternal halaman dashboard (dari control plane, bukan klien).
     */
    private function forwardedProto(Request $request): string
    {
        return strtolower(trim((string) $request->header('x-forwarded-proto', ''))) === 'https' ? 'https' : 'http';
    }

    /**
     * Alamat klien dashboard (dari control plane) — hanya IP valid.
     */
    private function forwardedFor(Request $request): string
    {
        $for = trim((string) $request->header('x-forwarded-for', ''));

        return filter_var($for, FILTER_VALIDATE_IP) !== false ? $for : '';
    }

    /**
     * Subset header browser yang boleh diteruskan (proxy memfilternya ulang;
     * cookie sesi & `X-Forwarded-*` milik klien tidak pernah ikut).
     *
     * @return array<string,string>
     */
    private function forwardHeaders(Request $request): array
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
     * Header respons helper yang lolos (tanpa hop-by-hop/`Set-Cookie`/
     * `Content-Length`; `AdminerProxy` sudah membersihkan `Location` + prefix).
     *
     * @param array<string,array<int,string>> $headers
     * @return array<string,string|array<int,string>>
     */
    private static function forwardResponseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $name = (string) $name;
            if (in_array(strtolower($name), self::RESPONSE_DROP, true)) {
                continue;
            }
            $out[$name] = $values;
        }

        return $out;
    }

    /**
     * Jar cookie Adminer dari header titipan control plane (base64 JSON).
     *
     * @return array<string,string>
     */
    public static function decodeJar(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return [];
        }
        $jar = json_decode($decoded, true);
        if (!is_array($jar)) {
            return [];
        }

        $out = [];
        foreach ($jar as $name => $value) {
            if (is_scalar($value)) {
                $out[(string) $name] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Encode jar (termasuk kunci reserved `__landing`) untuk control plane.
     *
     * @param array<string,string> $jar
     */
    public static function encodeJar(array $jar): string
    {
        $json = json_encode($jar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : base64_encode($json);
    }

    /**
     * Respons galat seragam (`code:1`, sama pola `AgentLogController::error()`).
     */
    private function error(string $message, string $reason, int $status): Response
    {
        return json([
            'code' => 1,
            'error' => $message,
            'reason' => $reason,
        ])->withStatus($status);
    }

    /** Proyeksi app agent (path mengikuti seam yang disuntikkan). */
    private function appStore(): AppStore
    {
        return $this->apps ?? new AppStore((new AgentAppProjection())->path());
    }

    /**
     * Helper Adminer container **server ini** — seam uji supaya jalur proxy bisa
     * diuji tanpa Docker/network nyata (produksi: `new AdminerHelper($docker)`).
     */
    protected function helper(DockerClient $docker): AdminerHelper
    {
        return new AdminerHelper($docker);
    }

    /**
     * Proxy Adminer satu permintaan — seam uji (produksi: `new AdminerProxy($spec)`).
     *
     * @param array<string,mixed> $spec
     */
    protected function adminerProxy(array $spec): AdminerProxy
    {
        return new AdminerProxy($spec);
    }

    /** Engine API server ini (bukan host control plane) — seam uji. */
    private function dockerClient(): DockerClient
    {
        return $this->docker ?? new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            20
        );
    }
}
