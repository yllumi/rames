<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Agent\AgentAppProjection;
use app\library\Agent\AgentArtifactPackager;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Docker\AppContainers;
use RuntimeException;

/**
 * Implementasi {@see DeployerInterface} untuk server **`kind=agent`**
 * (multi-server F1, plan §4/§4.1/§11 catatan 6).
 *
 * Peran kelas ini: menjalankan operasi deploy app **di server lain** lewat HTTP,
 * tanpa pernah menyentuh Docker/Nginx host control plane. Ia adalah penjaga
 * utama aturan plan §3 butir 4 — app di server agent **tidak boleh** diam-diam
 * dieksekusi di server lokal. Karena itu:
 *
 *  - **operasi panjang** (deploy/rebuild/rollback/apply/apply_env/stop/start/
 *    teardown) → job async di agent (`POST /jobs` → polling `GET /jobs/{id}` +
 *    `GET /jobs/{id}/log?offset=N`). Setiap panggilan HTTP ≤30 s (aturan repo
 *    #7; dijaga `AgentClient`), sedangkan total waktu ditutup
 *    `config('deploy.deploy_timeout')`. Baris log **baru** diteruskan ke
 *    `$logger($stage, $message)` sehingga worker control plane menulis log yang
 *    sama bentuknya dengan deploy lokal (UI tidak berubah);
 *  - **state eksekusi** (status/stage/message/error/containers/deploy_history/
 *    ssl_status) **ditarik dari agent** sesudah job (`GET /apps/{id}`) lalu
 *    digabungkan ke dokumen app yang dikembalikan — sisi *pull* plan §4.3.
 *    Metadata tetap milik control plane (tidak pernah ditimpa agent);
 *  - **Nginx & SSL** (F2, plan §5) juga dieksekusi **di server agent** dengan
 *    library yang sama yang dipakai worker biasa di sana
 *    (`NginxConfigGenerator`/`NginxReloader`/`SslIssuer` lewat
 *    `LocalDeployer`/`cli/deploy.php`): tulis/hapus vhost = job
 *    `nginx_write`/`nginx_remove`, reload = job `nginx_reload`, penerbitan
 *    sertifikat = job `ssl_issue` (+ `ssl_revoke`), dan perakitan config
 *    (`renderNginxConfig()`) dibaca dari `GET /apps/{id}/nginx` — **tidak
 *    pernah** menyentuh direktori Nginx/certbot host control plane;
 *  - **volume project & resource server** (F3d, plan §5): daftar volume project
 *    (`GET /volumes?project=…`), daftar container DB (`GET /database/containers`),
 *    proxy Adminer (`/adminer/*`) dan operasi network (`GET /networks*`,
 *    `POST /networks/*`) dibaca dari agent. Tidak ada jalur mundur ke
 *    `LocalDeployer`: operasi yang belum server-aware melempar
 *    `AgentError('unsupported')` (mis. metode yang tidak dipetakan) — itu persis
 *    kesalahan "app remote dieksekusi lokal" yang harus dicegah.
 *
 * **Artefak app wajib ikut** (misi `ms-f1-artifacts`, plan §4.3/§11 catatan 4):
 * agent hanya memegang proyeksi metadata app, jadi tiga hal yang tidak bisa ia
 * regenerasi sendiri harus dikirim di payload job — file compose utama app mode
 * compose, deploy key SSH app repo private, dan slug template. Itu tugas
 * {@see AgentArtifactPackager} ({@see self::ARTIFACT_OPS}), dipanggil **sebelum**
 * `POST /jobs`: gagal memaketkan (mis. file compose hilang di control plane) ⇒
 * **tidak ada** job yang dikirim dan galatnya disampaikan ke pemanggil — agent
 * tidak boleh menerima job yang pasti gagal separuh jalan. Op yang tidak
 * menyentuh artefak (`apply_env`, `stop`, `start`, `teardown`) tidak memaketkan
 * apa pun.
 *
 * **Offset log wajib**: log deploy per-app (`runtime/logs/deploy/{appId}.log`)
 * dipakai bersama antar-job (plan §12 butir 5), jadi polling mulai dari
 * `log_offset` yang dikembalikan `POST /jobs` (ukuran berkas saat job dibuat,
 * `DeployWorkerLauncher::logPath()`), bukan dari 0. Agent versi lama yang tidak
 * mengembalikan `log_offset` ⇒ mulai dari 0 **dan** menandainya di log deploy
 * bahwa baris lama mungkin terulang (bukan diam-diam).
 *
 * Baris diteruskan **tepat sekali**: offset maju hanya sampai newline terakhir
 * potongan yang diterima (`offset + strlen(baris_lengkap)`), sehingga potongan
 * baris yang belum selesai akan dibaca ulang pada polling berikutnya —
 * duplikasi atau pemotongan baris tidak mungkin terjadi. Offset ini identik
 * dengan `X-Rames-Offset` agent (`next = offset + strlen(text)`), hanya
 * dihitung dari panjang body respons.
 *
 * Tanpa state lintas-request: seluruh properti `readonly` dan hanya diisi
 * konstruktor (`$sleep`/`$clock` adalah seam uji). `AgentClient` disuntikkan
 * supaya uji berjalan **tanpa jaringan & tanpa Docker**.
 */
final class AgentDeployer implements DeployerInterface
{
    /** Interval polling default (ms) — cukup responsif, hemat panggilan HTTP. */
    public const DEFAULT_POLL_INTERVAL_MS = 1000;

    /** Batas bawah/atas interval polling (ms) — menahan nilai absurd dari pemanggil. */
    public const MIN_POLL_INTERVAL_MS = 50;
    public const MAX_POLL_INTERVAL_MS = 30000;

    /** Batas total waktu satu job (detik) bila config tidak tersedia. */
    public const DEFAULT_TIMEOUT_SECONDS = 600;

    /**
     * Field state eksekusi yang ditarik dari agent (plan §4.3). Satu sumber
     * dengan proyeksi sisi agent ({@see AgentAppProjection::EXECUTION}) — jangan
     * menyalin daftarnya.
     */
    private const PULL_FIELDS = AgentAppProjection::EXECUTION;

    /** Bentuk job id/ id app yang boleh masuk path URL (anti path traversal). */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * Op job yang membawa **artefak app** di payloadnya (file compose / deploy
     * key / template) — lihat catatan kelas. `apply_env` tidak butuh (env sudah
     * ikut `app`), `stop`/`start`/`teardown` hanya menyentuh container.
     */
    private const ARTIFACT_OPS = ['deploy', 'rebuild', 'rollback', 'apply'];

    /** @var array<string,mixed> */
    private readonly array $server;
    private readonly AgentClient $client;
    private readonly int $timeout;
    private readonly int $pollIntervalMs;

    /** Pemaket artefak app (control plane → agent). */
    private readonly AgentArtifactPackager $packager;

    /**
     * Direktori berkas sementara respons proxy Adminer (null ⇒ default
     * `runtime/adminer-proxy`, sama dengan jalur lokal). Seam uji supaya uji
     * proxy Adminer tidak menulis ke `runtime/` nyata.
     */
    private readonly ?string $adminerTempDir;

    /** @var \Closure(int):void */
    private readonly \Closure $sleep;

    /** @var \Closure():float */
    private readonly \Closure $clock;

    /**
     * @param array<string,mixed> $server dokumen server (dipakai untuk identitas di
     *                                      pesan galat + sanitasi token)
     * @param AgentClient         $client klien agent (disuntikkan; seam uji)
     * @param int|null            $timeout batas total waktu satu job (detik);
     *                                      null ⇒ `config('deploy.deploy_timeout')`
     * @param int                 $pollIntervalMs interval polling (ms)
     * @param callable|null       $sleep   seam uji: `(int $microseconds): void`
     * @param callable|null       $clock   seam uji: `(): float` detik (monoton naik)
     * @param AgentArtifactPackager|null $packager pemaket artefak app (null ⇒ bawaan;
     *                                      seam uji supaya uji tidak menyentuh `apps/` nyata)
     * @param string|null                 $adminerTempDir direktori berkas sementara proxy
     *                                      Adminer (null ⇒ `runtime/adminer-proxy`); seam uji
     */
    public function __construct(
        array $server,
        AgentClient $client,
        ?int $timeout = null,
        int $pollIntervalMs = self::DEFAULT_POLL_INTERVAL_MS,
        ?callable $sleep = null,
        ?callable $clock = null,
        ?AgentArtifactPackager $packager = null,
        ?string $adminerTempDir = null,
    ) {
        $this->server = $server;
        $this->client = $client;

        $configured = (int) config('deploy.deploy_timeout', self::DEFAULT_TIMEOUT_SECONDS);
        if ($configured <= 0) {
            $configured = self::DEFAULT_TIMEOUT_SECONDS;
        }
        $this->timeout = $timeout !== null && $timeout > 0 ? $timeout : $configured;

        $this->pollIntervalMs = max(
            self::MIN_POLL_INTERVAL_MS,
            min(self::MAX_POLL_INTERVAL_MS, $pollIntervalMs)
        );

        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (int $microseconds): void {
                usleep($microseconds);
            };
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): float => microtime(true);

        // Bawaan dibangun langsung (bukan lazy): konstruksinya hanya menyimpan
        // path — pembacaan config & disk terjadi saat `pack()` dipanggil.
        $this->packager = $packager ?? new AgentArtifactPackager();
        $this->adminerTempDir = $adminerTempDir !== null ? rtrim($adminerTempDir, '/') : null;
    }

    /** Batas total waktu satu job (detik) — untuk diagnostik/uji. */
    public function timeout(): int
    {
        return $this->timeout;
    }

    // ==================================================================
    // Operasi panjang — semuanya job async di agent
    // ==================================================================

    public function deploy(array $app, callable $logger): array
    {
        return $this->runJob('deploy', $app, $logger);
    }

    public function rebuild(array $app, callable $logger): array
    {
        return $this->runJob('rebuild', $app, $logger);
    }

    public function rollback(array $app, string $ref, callable $logger): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new RuntimeException($this->label() . ': rollback butuh ref commit.');
        }

        return $this->runJob('rollback', $app, $logger, ['ref' => $ref]);
    }

    public function apply(array $app, callable $logger): array
    {
        return $this->runJob('apply', $app, $logger);
    }

    public function applyEnv(array $app, callable $logger): array
    {
        return $this->runJob('apply_env', $app, $logger);
    }

    public function stop(array $app): void
    {
        $this->runJob('stop', $app, null);
    }

    public function start(array $app): void
    {
        $this->runJob('start', $app, null);
    }

    /**
     * Teardown di agent (`docker compose down`).
     *
     * `$preserveVolumes === null` ⇒ hapus semua volume (`down -v`). Array nama
     * volume diteruskan sebagai `preserve_volumes` di payload job; worker agent
     * (`cli/deploy.php` mode `teardown`) membacanya dari rekaman job dan
     * mempertahankan volume tersebut (perilaku sama `LocalDeployer::teardown()`).
     *
     * `$logger` tidak ada di {@see DeployerInterface} (parameter opsional,
     * perluasan yang sama dengan `LocalDeployer::teardown()`) sehingga log job
     * tetap bisa diteruskan ke pemanggil yang membutuhkannya.
     *
     * @param array<int,string>|null $preserveVolumes
     */
    public function teardown(array $app, ?array $preserveVolumes = null, ?callable $logger = null): void
    {
        $preserve = $preserveVolumes === null
            ? null
            : array_values(array_map('strval', $preserveVolumes));

        $this->runJob('teardown', $app, $logger, ['preserve_volumes' => $preserve]);
    }

    // ==================================================================
    // Operasi pendek — data dari agent, bukan dari Engine lokal
    // ==================================================================

    /**
     * Info container project **dari agent** (`GET /apps/{id}`) — jangan pernah
     * membaca Docker Engine host control plane.
     *
     * `$project` di kontrak interface = nama project compose, sementara agent
     * menyimpan app dengan id control plane; pemanggil yang sudah memegang
     * dokumen app sebaiknya memakai {@see self::getContainersForApp()} supaya
     * tidak bergantung pada kebetulan `id === name`.
     *
     * @return array<int,array{service_name:string,container_name:string,image:string,internal_port:?int,host_port:?int,status:string}>
     */
    public function getContainers(string $project): array
    {
        return $this->getContainersForApp(['id' => trim($project), 'name' => trim($project)]);
    }

    /**
     * Info container milik app tertentu, diambil dari agent (`GET /apps/{id}`).
     *
     * @param array<string,mixed> $app
     * @return array<int,array<string,mixed>>
     */
    public function getContainersForApp(array $app): array
    {
        $doc = $this->pullApp($app);
        $containers = $doc['containers'] ?? [];

        return is_array($containers) ? array_values($containers) : [];
    }

    /**
     * Volume project **dari server agent** (`GET /volumes?project=…`, F3) —
     * daftar nama named volume milik project compose app, dipakai modal
     * konfirmasi delete di control plane (plan §5).
     *
     * Nama volume dibaca dari Engine **server agent**; daemon host control plane
     * tidak pernah dibaca untuk app remote (plan §3 butir 4). Project diambil
     * dari `apps.name` (label `com.docker.compose.project` di sana) — sama
     * nilainya dengan jalur lokal.
     *
     * @return array<int,string> nama volume (kosong bila agent tidak melaporkan)
     * @throws AgentError
     */
    public function getProjectVolumes(string $project): array
    {
        $project = trim($project);
        if ($project === '' || preg_match(self::PROJECT_PATTERN, $project) !== 1) {
            return [];
        }

        $data = $this->short(
            'membaca volume project',
            fn (): array => $this->client->getWithQuery('/volumes', ['project' => $project])
        );
        $data = $this->ok($data, 'membaca volume project');

        $names = $data['data']['volumes'] ?? null;
        if (!is_array($names)) {
            throw AgentError::badResponse(
                $this->label() . ': agent tidak mengembalikan daftar volume (kontrak: `{code:0,data:{volumes}}`).'
            );
        }

        $volumes = [];
        foreach ($names as $name) {
            $name = is_scalar($name) ? trim((string) $name) : '';
            if ($name !== '') {
                $volumes[] = $name;
            }
        }

        return $volumes;
    }

    // ==================================================================
    // F3a — operasional per server: log container & terminal
    // ==================================================================

    /**
     * Batas ukuran teks log per respons (byte). **Satu sumber** kontrak ini:
     * sisi agent (`AgentLogController`) memotong ke plafon yang sama, dan control
     * plane memotong lagi sebagai jaring pengaman supaya satu respons tidak
     * pernah menahan memori worker tanpa batas (plan §4.1: log = panggilan
     * pendek, bukan stream).
     */
    public const MAX_LOG_BYTES = 1048576;

    /**
     * Bentuk nama container yang boleh dikirim ke agent (huruf/angka, `_`, `.`,
     * `-`; tanpa spasi/slash) — dipakai control plane **sebelum** HTTP apa pun
     * (anti path/argumen injection). Keanggotaan container pada app tetap
     * diputuskan agent lewat `AppContainers::resolve()`.
     */
    private const CONTAINER_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /** Bentuk nama token sesi terminal (pola yang dibuat `DockerExec::open()`). */
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    /**
     * Bentuk nama project compose / network / container yang boleh masuk URL &
     * query ke agent (tanpa spasi, `/`, atau karakter kontrol).
     */
    private const PROJECT_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /**
     * Bentuk id network/volume (Engine memakai hash 64-hex untuk network; CLI
     * juga menerima nama) — dibatasi tanpa `/` & karakter kontrol.
     */
    private const ENGINE_ID_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/';

    /**
     * Deployer **agent** untuk app, atau `null` bila app dilayani server lokal
     * (satu pintu, dipakai `LogController`/`TerminalController`).
     *
     * Sengaja tidak menyentuh Docker/Nginx host ini: hanya resolusi registry
     * server (`server_id` app). Server `kind=agent` yang konfigurasinya cacat
     * ⇒ `AgentError` (fail-fast), **bukan** `null` — `null` selalu berarti
     * "server lokal" dan tidak boleh dipakai sebagai jalur mundur.
     *
     * @param array<string,mixed> $app
     * @param AgentClient|null    $client klien agent siap pakai (seam uji — tanpa
     *                                    jaringan & tanpa menulis `runtime/agent-tls`)
     * @throws AgentError|RuntimeException server agent cacat / `server_id` tak dikenal
     */
    public static function forApp(array $app, ?ServerResolver $resolver = null, ?AgentClient $client = null): ?self
    {
        $server = ($resolver ?? new ServerResolver())->resolveForApp($app);

        return self::forServer($server, $client);
    }

    /**
     * Deployer **agent** untuk dokumen server, atau `null` bila servernya lokal.
     *
     * Dipakai operasi yang **tidak terikat satu app** (mis. daftar volume
     * project, daftar/daftar-ulang network, daftar container DB server itu) —
     * halaman control plane memilih server lebih dulu, lalu memanggil endpoint
     * agent lewat server yang sama dengan {@see self::forApp()} (satu pintu, satu
     * pesan galat). Server `kind=agent` yang cacat tetap **melempar**
     * (`AgentError`), bukan mengembalikan `null`.
     *
     * @param array<string,mixed> $server
     * @throws AgentError|RuntimeException
     */
    public static function forServer(array $server, ?AgentClient $client = null): ?self
    {
        if (ServerResolver::isLocal($server)) {
            return null;
        }

        $deployer = DeployerFactory::createFor($server, $client);

        return $deployer instanceof self ? $deployer : null;
    }

    /**
     * Nama container dari request yang boleh diteruskan ke agent.
     *
     * Validasi control plane (tanpa I/O): bentuk nama + keanggotaan pada **data
     * proyeksi** app (`containers`) bila daftarnya sudah terisi. Daftar kosong
     * tidak ditolak: control plane kerap belum menarik state eksekusi agent, dan
     * daftar container hidupnya memang ada di sana — keputusan akhirnya di agent
     * (`AppContainers::resolve()` terhadap Engine server itu).
     *
     * `$requested` kosong ⇒ container default app (`primary_service`, pola sama
     * `AppContainers::defaultContainer()` — fungsi murni atas dokumen app).
     *
     * @param array<string,mixed> $app
     */
    public static function containerRef(array $app, string $requested): ?string
    {
        $name = trim($requested);
        if ($name === '') {
            $name = (string) (AppContainers::defaultContainer($app) ?? '');
        }
        if ($name === '' || preg_match(self::CONTAINER_PATTERN, $name) !== 1) {
            return null;
        }

        $known = [];
        foreach (($app['containers'] ?? []) as $container) {
            if (is_array($container) && (string) ($container['container_name'] ?? '') !== '') {
                $known[] = (string) $container['container_name'];
            }
        }
        if ($known !== [] && !in_array($name, $known, true)) {
            return null;
        }

        return $name;
    }

    /**
     * Log container app **dari agent** (`GET /apps/{id}/logs`) — Docker Engine
     * host control plane tidak pernah dibaca untuk app server agent.
     *
     * @param array<string,mixed> $app
     * @throws AgentError
     */
    public function containerLogs(array $app, string $container, int $tail, bool $timestamps = true): string
    {
        $id = $this->appId($app);
        $data = $this->short('membaca log container', fn (): array => $this->client->getWithQuery(
            '/apps/' . rawurlencode($id) . '/logs',
            [
                'container' => $container,
                'tail' => $tail,
                'timestamps' => $timestamps ? 1 : 0,
            ]
        ));
        $data = $this->ok($data, 'membaca log container');

        $payload = $data['data'] ?? null;
        $text = is_array($payload) ? (string) ($payload['text'] ?? '') : '';
        if (strlen($text) > self::MAX_LOG_BYTES) {
            $text = substr($text, -self::MAX_LOG_BYTES);
        }

        return $text;
    }

    /**
     * `POST /terminal/open` — buka sesi shell interaktif **di server agent**
     * (sesi hidup di sana; control plane hanya memegang token).
     *
     * @param array<string,mixed>   $app
     * @param array{shell?:string,user?:string} $opts
     * @return array{token:string,pid:int,shell:string,user:string,container:string}
     * @throws AgentError
     */
    public function terminalOpen(array $app, string $container, array $opts): array
    {
        $id = $this->appId($app);
        $data = $this->short('membuka sesi terminal', fn (): array => $this->client->post('/terminal/open', [
            'app_id' => $id,
            'container' => $container,
            'shell' => (string) ($opts['shell'] ?? ''),
            'user' => (string) ($opts['user'] ?? ''),
        ]));
        $data = $this->ok($data, 'membuka sesi terminal');

        $session = $data['data'] ?? null;
        if (!is_array($session)) {
            throw new AgentError(
                $this->label() . ': agent tidak mengembalikan data sesi terminal.',
                AgentError::BAD_RESPONSE
            );
        }

        $token = trim((string) ($session['token'] ?? ''));
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new AgentError(
                $this->label() . ': agent tidak mengembalikan token sesi terminal yang sah.',
                AgentError::BAD_RESPONSE
            );
        }

        // Whitelist field (bukan salin lalu hapus): hanya yang dipakai UI.
        return [
            'token' => $token,
            'pid' => (int) ($session['pid'] ?? 0),
            'shell' => (string) ($session['shell'] ?? ''),
            'user' => (string) ($session['user'] ?? ''),
            'container' => (string) ($session['container'] ?? $container),
        ];
    }

    /**
     * `POST /terminal/{token}/input` — kirim input keyboard/resize ke sesi yang
     * hidup di agent.
     *
     * @param array<string,mixed> $app
     * @throws AgentError
     */
    public function terminalInput(array $app, string $token, string $data): void
    {
        $id = $this->appId($app);
        $data = $this->short('mengirim input terminal', fn (): array => $this->client->post(
            '/terminal/' . $this->tokenRef($token) . '/input',
            ['app_id' => $id, 'data' => $data]
        ));

        $this->ok($data, 'mengirim input terminal');
    }

    /**
     * `POST /terminal/{token}/close` — tutup sesi **di agent** (hanya jalur ini
     * yang menutup sesi; koneksi stream yang drop tidak pernah menutupnya).
     *
     * @param array<string,mixed> $app
     * @throws AgentError
     */
    public function terminalClose(array $app, string $token): void
    {
        $id = $this->appId($app);
        $data = $this->short('menutup sesi terminal', fn (): array => $this->client->post(
            '/terminal/' . $this->tokenRef($token) . '/close',
            ['app_id' => $id]
        ));

        $this->ok($data, 'menutup sesi terminal');
    }

    /**
     * `GET /terminal/{token}/stream` — aliran output sesi dari agent, sudah
     * terhubung & **tidak** di-buffer (dibaca bertahap oleh controller lewat
     * `Workerman\Timer`).
     *
     * `close()` pada handle hanya menutup socket upstream; sesi di agent tetap
     * hidup sampai `terminalClose()` / TTL-nya sendiri (aturan repo: koneksi
     * SSE drop ≠ sesi berakhir, supaya `EventSource` boleh menyambung ulang).
     *
     * @param array<string,mixed> $app
     * @return array{status:int,headers:array<string,string>,read:callable():?string,close:callable():void}
     * @throws AgentError
     */
    public function terminalStream(array $app, string $token): array
    {
        $id = $this->appId($app);

        return $this->short('membuka aliran terminal', fn (): array => $this->client->openStream(
            '/terminal/' . $this->tokenRef($token) . '/stream',
            ['app_id' => $id]
        ));
    }

    // ==================================================================
    // F3b — operasional per server: file manager container & monitoring
    // ==================================================================

    /**
     * Batas besar transfer berkas per respons/permintaan (byte) di jalur agent.
     *
     * **Satu sumber** kontrak ini: sisi agent (`AgentFilesController`) menolak
     * unduhan/unggahan di atas plafon yang sama, dan control plane menegakkannya
     * lagi saat mem-proxy unduhan (jaring pengaman kedua) — satu respons tidak
     * boleh menahan/mengalirkan data tanpa batas.
     */
    public const MAX_TRANSFER_BYTES = 67108864; // 64 MiB

    /**
     * Bentuk path absolut yang boleh dikirim ke agent (bentuk sudah dinormalkan
     * `PathGuard` di control plane): diawali `/`, tanpa baris baru/NUL.
     */
    private const PATH_PATTERN = '#^/[^\r\n\0]*$#';

    /** Bentuk nama entri yang boleh dikirim ke agent (tanpa `/`, tak diawali `-`). */
    private const NAME_PATTERN = '/^[^\r\n\0\/\\\\][^\r\n\0\/\\\\]{0,254}$/';

    /**
     * Daftar isi direktori **di server agent** (`GET /files/list`) — daftar
     * container hidup dan seluruh operasi berkas dijalankan di sana; Docker
     * Engine host control plane tidak pernah disentuh untuk app server agent.
     *
     * Envelope respons agent (`{code:0,data:…}` / `{code:<status>,msg}`)
     * dikembalikan **apa adanya** supaya `FileController` bisa memetakannya ke
     * bentuk UI yang sama dengan jalur lokal (termasuk 409 container berhenti,
     * 413 terlalu besar, 415 biner) — jalur ini bukan tempat kedua yang
     * menerjemahkan galat.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError transport/HTTP ke agent (galat domain agent TIDAK melempar)
     */
    public function fileList(array $app, string $container, string $path): array
    {
        return $this->fileGet('membaca daftar berkas', '/files/list', $app, $container, [
            'path' => $this->assertPath($path),
        ]);
    }

    /**
     * Isi berkas teks **di server agent** (`GET /files/read`).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileRead(array $app, string $container, string $path): array
    {
        return $this->fileGet('membaca berkas', '/files/read', $app, $container, [
            'path' => $this->assertPath($path),
        ]);
    }

    /**
     * Buka aliran unduhan berkas **dari agent** (`GET /files/download`) —
     * byte diteruskan control plane apa adanya, tanpa di-buffer di memori.
     *
     * Jalur ini memakai `AgentClient::openStreamEnvelope()`: galat domain agent
     * (mis. 409 container berhenti, 413 terlalu besar, 404 berkas hilang)
     * dikembalikan di kunci `error` sebagai envelope `{code,msg}` **tanpa**
     * mengalirkan body galat sebagai isi berkas — pemanggil meneruskan `code`-nya
     * ke UI (sama seperti jalur lokal).
     *
     * @param array<string,mixed> $app
     * @return array{status:int,headers:array<string,string>,read:callable():?string,close:callable():void,error:?array<string,mixed>}
     * @throws AgentError transport/konfigurasi server (agent tidak bisa dihubungi)
     */
    public function fileDownload(array $app, string $container, string $path): array
    {
        $id = $this->appId($app);

        return $this->short('mengunduh berkas', fn (): array => $this->client->openStreamEnvelope('/files/download', [
            'app_id' => $id,
            'container' => $this->assertContainer($container),
            'path' => $this->assertPath($path),
        ]));
    }

    /**
     * Simpan isi berkas teks **di server agent** (`POST /files/write`) — batas
     * 2 MiB editor tetap milik library agent (`TextContent`).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileWrite(array $app, string $container, string $path, string $text): array
    {
        return $this->filePost('menyimpan berkas', '/files/write', $app, $container, [
            'path' => $this->assertPath($path),
            'text' => $text,
        ]);
    }

    /**
     * Buat direktori **di server agent** (`POST /files/mkdir`) — `$target` sudah
     * berupa path absolut hasil `FilesInput::mkdirTarget()` control plane.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileMkdir(array $app, string $container, string $target): array
    {
        return $this->filePost('membuat direktori', '/files/mkdir', $app, $container, [
            'target' => $this->assertPath($target),
        ]);
    }

    /**
     * Ganti nama/pindah path **di server agent** (`POST /files/rename`).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileRename(array $app, string $container, string $from, string $to): array
    {
        return $this->filePost('mengganti nama', '/files/rename', $app, $container, [
            'from' => $this->assertPath($from),
            'to' => $this->assertPath($to),
        ]);
    }

    /**
     * Hapus berkas/folder **di server agent** (`POST /files/delete`).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileDelete(array $app, string $container, string $path): array
    {
        return $this->filePost('menghapus berkas', '/files/delete', $app, $container, [
            'path' => $this->assertPath($path),
        ]);
    }

    /**
     * Pindahkan berkas/folder **di server agent** (`POST /files/move`).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileMove(array $app, string $container, string $path, string $dir, ?string $name = null): array
    {
        $payload = [
            'path' => $this->assertPath($path),
            'dest' => $this->assertPath($dir),
        ];
        if ($name !== null && $name !== '') {
            $payload['name'] = $this->assertName($name);
        }

        return $this->filePost('memindahkan berkas', '/files/move', $app, $container, $payload);
    }

    /**
     * Unggah satu berkas local→container **di server agent** (`POST /files/upload`).
     *
     * Isi berkas dikirim sebagai body mentah **streaming dari disk**
     * (`AgentClient::postFile()`), jadi berkas besar tidak pernah dimuat ke
     * memori worker control plane; field kecil (`app_id`/`container`/`dir`/`name`)
     * di query string.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileUpload(array $app, string $container, string $dir, string $localPath, string $name): array
    {
        $id = $this->appId($app);
        $query = [
            'app_id' => $id,
            'container' => $this->assertContainer($container),
            'dir' => $this->assertPath($dir),
            'name' => $this->assertName($name),
        ];

        return $this->envelope('mengunggah berkas', fn (): array => $this->client->postFile('/files/upload', $localPath, $query));
    }

    /**
     * Ekstrak arsip **di server agent** (`POST /files/extract`) — proteksi
     * zip-slip tetap milik library agent (`ArchiveGuard`), tidak disalin ke sini.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError
     */
    public function fileExtract(array $app, string $container, string $dir, string $name, ?string $dest = null): array
    {
        $payload = [
            'dir' => $this->assertPath($dir),
            'name' => $this->assertName($name),
        ];
        if ($dest !== null && trim($dest) !== '') {
            $payload['dest'] = $this->assertPath($dest);
        }

        return $this->filePost('mengekstrak arsip', '/files/extract', $app, $container, $payload);
    }

    /**
     * Metrik host **server agent** (`GET /system/host-usage`) — `/proc` milik
     * server itu, bukan `/proc` host control plane.
     *
     * @return array<string,mixed> hasil `HostUsage::snapshot()` server agent
     * @throws AgentError
     */
    public function hostUsage(): array
    {
        $data = $this->short('membaca pemakaian host', fn (): array => $this->client->get('/system/host-usage'));
        $data = $this->ok($data, 'membaca pemakaian host');

        $host = $data['data']['host'] ?? null;
        if (!is_array($host)) {
            throw AgentError::badResponse($this->label() . ': agent tidak mengembalikan metrik host (kontrak: `{code:0,data:{host}}`).');
        }

        return $host;
    }

    /**
     * Statistik container **server agent** (`GET /monitor/containers`) — baris
     * sudah dirakit `ResourceCollector` di sana (Engine server itu).
     *
     * Penyaringan hak akses **tidak** dilakukan di sini: itu milik control plane
     * (`MonitorController`, satu tempat dengan `AppAccess::visible()`), karena
     * agent tidak mengetahui identitas user dashboard.
     *
     * @return array{containers:array<int,array>,totals:array<string,mixed>,error:?string}
     * @throws AgentError
     */
    public function containerStats(): array
    {
        $data = $this->short('membaca statistik container', fn (): array => $this->client->get('/monitor/containers'));
        $data = $this->ok($data, 'membaca statistik container');

        $payload = $data['data'] ?? [];
        if (!is_array($payload)) {
            throw AgentError::badResponse($this->label() . ': agent tidak mengembalikan statistik container (kontrak: `{code:0,data:{containers,totals}}`).');
        }
        $containers = $payload['containers'] ?? [];
        $error = $payload['error'] ?? null;

        return [
            'containers' => is_array($containers) ? array_values(array_filter($containers, 'is_array')) : [],
            'totals' => is_array($payload['totals'] ?? null) ? $payload['totals'] : [],
            'error' => is_string($error) && trim($error) !== '' ? $error : null,
        ];
    }

    // ==================================================================
    // F3d — operasional per server: container DB (Adminer), volume, network
    // ==================================================================

    /**
     * Daftar container MySQL/MariaDB **server agent** (`GET /database/containers`)
     * — dipakai halaman `/database` control plane supaya container DB app remote
     * tampil (dan bisa dibuka Adminer-nya) walau daemon host control plane tidak
     * pernah menyentuhnya.
     *
     * Kepemilikan (`owned`/`app_id`/`app_name`) **tidak** ditentukan di sini:
     * hasil agent dikembalikan apa adanya (hanya deteksi container), lalu control
     * plane memetakannya lewat `AppStore`-nya sendiri + `AppAccess::visible()` —
     * agent tidak mengetahui identitas user dashboard.
     *
     * @return array<int,array<string,mixed>>
     * @throws AgentError
     */
    public function dbContainers(): array
    {
        $data = $this->short('membaca container database', fn (): array => $this->client->get('/database/containers'));
        $data = $this->ok($data, 'membaca container database');

        $rows = $data['data']['rows'] ?? null;
        if (!is_array($rows)) {
            throw AgentError::badResponse(
                $this->label() . ': agent tidak mengembalikan daftar container DB (kontrak: `{code:0,data:{rows}}`).'
            );
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Teruskan satu permintaan Adminer ke helper **di server agent** (F3d).
     *
     * Body `$body` = byte mentah dari browser (multipart import Adminer) dan
     * respons helper ditulis ke berkas sementara di control plane (dibaca
     * Workerman setelah handler kembali) — jalur ini **tidak** pernah menyentuh
     * Docker/Nginx host control plane.
     *
     * @param array<string,mixed>  $app       app pemilik container (validasi id)
     * @param string               $container nama container DB (divalidasi milik app)
     * @param string               $path      path SETELAH prefix ('' = root Adminer)
     * @param string               $query     query string mentah tanpa '?' (tidak ditandatangani)
     * @param array<string,string> $headers   header terpilih ke helper (mis.
     *                                        `Content-Type`, `X-Forwarded-Prefix`)
     * @return array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int,too_large:bool}
     *         `body_file` wajib dihapus/dijadwalkan pembersihannya oleh pemanggil
     * @throws AgentError
     */
    public function adminerForward(
        array $app,
        string $container,
        string $method,
        string $path,
        string $query,
        ?string $body,
        array $headers,
    ): array {
        $container = $this->assertContainer($container);
        $agentPath = self::adminerAgentPath($container, $path);

        return $this->short('meneruskan permintaan Adminer', fn (): array => $this->client->forwardToFile(
            $method,
            $agentPath,
            $body,
            $query,
            $headers,
            $this->adminerTempDir(),
            $this->adminerMaxBytes()
        ));
    }

    /**
     * Path route proxy Adminer sisi agent (`/adminer/{container}[/{path}]`).
     *
     * Segmen path dinormalkan (`rawurldecode` → `rawurlencode`) supaya byte yang
     * sudah ter-encode tidak ter-encode dua kali, sementara spasi/karakter khusus
     * tetap aman di URL. Path **tidak** pernah diterjemahkan di sini: batas
     * direktori/alamat helper ditentukan `AdminerProxy` di agent.
     *
     * @throws AgentError path memuat karakter yang tidak diizinkan
     */
    public static function adminerAgentPath(string $container, string $path): string
    {
        if (preg_match('/[\r\n\0]/', $path) === 1) {
            throw new AgentError('Path Adminer memuat karakter yang tidak diizinkan.', AgentError::BAD_RESPONSE);
        }

        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $segments[] = rawurlencode(rawurldecode($segment));
        }

        return '/adminer/' . rawurlencode($container)
            . ($segments === [] ? '' : '/' . implode('/', $segments));
    }

    /**
     * Batas ukuran respons proxy Adminer jalur agent — **satu sumber** dengan
     * jalur lokal (`config('deploy.adminer_proxy_max_bytes')`, default 64 MiB).
     * DILARANG dinaikkan: batas ini menjaga satu respons tidak menahan disk/
     * memori worker control plane.
     */
    public function adminerMaxBytes(): int
    {
        return max(1024, (int) config('deploy.adminer_proxy_max_bytes', 67108864));
    }

    /**
     * Direktori berkas sementara respons proxy Adminer di control plane — sama
     * dengan jalur lokal (`AdminerTempDir`) supaya pembersihan/pemangkasan satu
     * jalur.
     */
    public function adminerTempDir(): string
    {
        return $this->adminerTempDir ?? runtime_path() . '/adminer-proxy';
    }

    /**
     * Daftar network Docker **server agent** (`GET /networks`) — halaman
     * `/networks` control plane memakainya untuk cakupan per server.
     *
     * @return array<int,array<string,mixed>>
     * @throws AgentError
     */
    public function networks(): array
    {
        $data = $this->short('membaca daftar network', fn (): array => $this->client->get('/networks'));
        $data = $this->ok($data, 'membaca daftar network');

        $rows = $data['data']['networks'] ?? null;
        if (!is_array($rows)) {
            throw AgentError::badResponse(
                $this->label() . ': agent tidak mengembalikan daftar network (kontrak: `{code:0,data:{networks}}`).'
            );
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Detail satu network **server agent** (`GET /networks/{id}`):
     * `{network, attached, candidates}` — bentuknya sama dengan data yang
     * dirakit jalur lokal (`NetworkController::detail()`).
     *
     * @return array{network:array<string,mixed>,attached:array<int,array<string,mixed>>,candidates:array<int,array<string,mixed>>}
     * @throws AgentError
     */
    public function networkDetail(string $id): array
    {
        $data = $this->short('membaca detail network', fn (): array => $this->client->get(
            '/networks/' . $this->assertEngineRef($id)
        ));
        $data = $this->ok($data, 'membaca detail network');

        $payload = $data['data'] ?? null;
        if (!is_array($payload) || !is_array($payload['network'] ?? null)) {
            throw AgentError::badResponse(
                $this->label() . ': agent tidak mengembalikan detail network (kontrak: `{code:0,data:{network,…}}`).'
            );
        }

        return [
            'network' => $payload['network'],
            'attached' => is_array($payload['attached'] ?? null) ? array_values($payload['attached']) : [],
            'candidates' => is_array($payload['candidates'] ?? null) ? array_values($payload['candidates']) : [],
        ];
    }

    /**
     * Buat network **di server agent** (`POST /networks/create`).
     *
     * @param array<string,mixed> $config konfigurasi Engine (sudah divalidasi
     *                                    control plane: nama/driver/IPAM)
     * @throws AgentError
     */
    public function createNetwork(array $config): void
    {
        $name = trim((string) ($config['Name'] ?? ''));
        if ($name === '' || preg_match(self::PROJECT_PATTERN, $name) !== 1) {
            throw new AgentError($this->label() . ': nama network tidak sah.', AgentError::BAD_RESPONSE);
        }

        $data = $this->short('membuat network', fn (): array => $this->client->post('/networks/create', ['config' => $config]));
        $this->ok($data, 'membuat network');
    }

    /**
     * Sambungkan container ke network **di server agent** (`POST /networks/{id}/connect`).
     *
     * @throws AgentError
     */
    public function connectNetwork(string $id, string $container, string $alias = ''): void
    {
        $payload = ['container' => $this->assertContainerName($container)];
        $alias = trim($alias);
        if ($alias !== '') {
            if (preg_match(self::PROJECT_PATTERN, $alias) !== 1) {
                throw new AgentError($this->label() . ': alias network tidak sah.', AgentError::BAD_RESPONSE);
            }
            $payload['alias'] = $alias;
        }

        $data = $this->short('menghubungkan container ke network', fn (): array => $this->client->post(
            '/networks/' . $this->assertEngineRef($id) . '/connect',
            $payload
        ));
        $this->ok($data, 'menghubungkan container ke network');
    }

    /**
     * Putuskan container dari network **di server agent**
     * (`POST /networks/{id}/disconnect`).
     *
     * @throws AgentError
     */
    public function disconnectNetwork(string $id, string $container): void
    {
        $data = $this->short('memutus container dari network', fn (): array => $this->client->post(
            '/networks/' . $this->assertEngineRef($id) . '/disconnect',
            ['container' => $this->assertContainerName($container)]
        ));
        $this->ok($data, 'memutus container dari network');
    }

    /**
     * Hapus network **di server agent** (`POST /networks/{id}/delete`) —
     * proteksi berlapis (built-in / dikelola app / masih dipakai) tetap di
     * control plane yang memegang `AppStore`; agent hanya mengeksekusi.
     *
     * @throws AgentError
     */
    public function deleteNetwork(string $id): void
    {
        $data = $this->short('menghapus network', fn (): array => $this->client->post(
            '/networks/' . $this->assertEngineRef($id) . '/delete',
            []
        ));
        $this->ok($data, 'menghapus network');
    }

    /**
     * Bentuk id/nama network yang boleh masuk URL agent (anti path traversal).
     *
     * @throws AgentError
     */
    private function assertEngineRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || preg_match(self::ENGINE_ID_PATTERN, $ref) !== 1) {
            throw new AgentError($this->label() . ': id network tidak sah.', AgentError::BAD_RESPONSE);
        }

        return $ref;
    }

    /**
     * Bentuk nama container dari permintaan connect/disconnect — validasi
     * control plane tanpa I/O (keanggotaan container pada app tetap diputuskan
     * halaman yang menyusun daftar kandidat dari server itu).
     *
     * @throws AgentError
     */
    private function assertContainerName(string $container): string
    {
        $container = trim($container);
        if ($container === '' || preg_match(self::CONTAINER_PATTERN, $container) !== 1) {
            throw new AgentError($this->label() . ': nama container tidak sah.', AgentError::BAD_RESPONSE);
        }

        return $container;
    }

    /**
     * `GET {path}?{query}` endpoint berkas agent + validasi container/path
     * (tanpa I/O) sebelum HTTP apa pun.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     * @throws AgentError
     */
    private function fileGet(string $what, string $path, array $app, string $container, array $query): array
    {
        $id = $this->appId($app);
        $query = ['app_id' => $id, 'container' => $this->assertContainer($container)] + $query;

        return $this->envelope($what, fn (): array => $this->client->getWithQuery($path, $query));
    }

    /**
     * `POST {path}` endpoint berkas agent + validasi container (tanpa I/O).
     *
     * @param array<string,mixed>  $app
     * @param array<string,mixed>  $payload
     * @return array<string,mixed>
     * @throws AgentError
     */
    private function filePost(string $what, string $path, array $app, string $container, array $payload): array
    {
        $id = $this->appId($app);
        $payload = ['app_id' => $id, 'container' => $this->assertContainer($container)] + $payload;

        return $this->envelope($what, fn (): array => $this->client->post($path, $payload));
    }

    /**
     * Panggilan pendek yang mengembalikan **envelope berpasangan** agent
     * (`{code:0,data:…}` atau `{code:<status>,msg:…}`) apa adanya.
     *
     * Berbeda dari {@see self::ok()} yang menganggap `code != 0` sebagai galat
     * transport: di jalur berkas, `code` adalah **kode domain** (400/404/409/413/
     * 415/500) yang justru harus diteruskan ke UI supaya bentuk responsnya sama
     * dengan jalur lokal (container berhenti = 409, dst.).
     *
     * @param callable():array $call
     * @return array<string,mixed>
     * @throws AgentError transport/HTTP ke agent
     */
    private function envelope(string $what, callable $call): array
    {
        try {
            $data = $call();
        } catch (AgentError $e) {
            throw new AgentError(
                $this->label() . ': gagal ' . $what . ' di server agent: ' . $this->sanitize($e->getMessage()),
                $e->reason
            );
        }

        if (!is_array($data)) {
            throw AgentError::badResponse($this->label() . ': respons agent bukan JSON objek saat ' . $what . '.');
        }

        return $data;
    }

    /**
     * Path absolut yang boleh masuk query/body agent — validasi control plane
     * (tanpa I/O) **sebelum** HTTP apa pun; agent tetap menormalkan ulang lewat
     * `PathGuard` (pertahanan berlapis).
     *
     * @throws AgentError bentuk path tidak sah
     */
    private function assertPath(string $path): string
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new AgentError($this->label() . ': path berkas tidak sah.', AgentError::BAD_RESPONSE);
        }

        return $path;
    }

    /**
     * Nama entri yang boleh dikirim ke agent (tanpa separator, tak diawali `-`).
     *
     * @throws AgentError bentuk nama tidak sah
     */
    private function assertName(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || str_starts_with($name, '-')) {
            throw new AgentError($this->label() . ': nama entri berkas tidak sah.', AgentError::BAD_RESPONSE);
        }

        return $name;
    }

    /**
     * Nama container yang boleh dikirim ke agent (bentuk + keanggotaan sudah
     * diputuskan `containerRef()` di control plane; ini jaring terakhir).
     *
     * @throws AgentError bentuk nama container tidak sah
     */
    private function assertContainer(string $container): string
    {
        if (preg_match(self::CONTAINER_PATTERN, $container) !== 1) {
            throw new AgentError($this->label() . ': nama container tidak sah.', AgentError::BAD_RESPONSE);
        }

        return $container;
    }

    // ==================================================================
    // Nginx & SSL (F2) — seluruh eksekusi DI SERVER AGENT
    // ==================================================================

    /**
     * Preflight prasyarat tulis config Nginx **di server agent**
     * (`POST /nginx/check`): agent memakai `NginxConfigGenerator::ensureWritable()`
     * miliknya sendiri. Direktori Nginx host control plane **tidak pernah**
     * diperiksa (dulu itu justru sumber kesalahan: app remote "lolos" karena
     * host ini writable, padahal vhost-nya dibuat di server lain).
     *
     * Endpoint ini sengaja **bukan** job: `DeployerInterface::ensureWritable()`
     * tidak membawa konteks app, sedangkan `POST /jobs` selalu butuh app
     * (proyeksi + log per-app). Pemeriksaan ini read-only dan ≤30 s, jadi tidak
     * ada pekerjaan panjang yang perlu di-spawn.
     *
     * @throws AgentError          transport/HTTP ke agent
     * @throws RuntimeException    server agent tanpa `domain` (kontrak F2 §5)
     */
    public function ensureWritable(): void
    {
        $this->assertDomainBaseOrFail();

        try {
            $this->client->post('/nginx/check', []);
        } catch (AgentError $e) {
            throw new AgentError(
                $this->label() . ': prasyarat direktori Nginx server agent tidak terpenuhi: '
                . $this->sanitize($e->getMessage()),
                $e->reason
            );
        }
    }

    /**
     * Config Nginx app **sebagaimana dirakit di server agent** (`GET /apps/{id}/nginx`)
     * — perakitan memakai library yang sama di sana, jadi path webroot/letsencrypt
     * dan domain efektif server itu yang dipakai, bukan milik control plane.
     *
     * @param array<string,mixed> $app
     * @throws AgentError       transport/HTTP ke agent atau respons tanpa `config`
     * @throws RuntimeException app tanpa `id`, atau server agent tanpa `domain`
     */
    public function renderNginxConfig(array $app): string
    {
        $this->assertDomainBaseOrFail($app);
        $appId = $this->appId($app);

        try {
            $data = $this->client->get('/apps/' . rawurlencode($appId) . '/nginx');
        } catch (AgentError $e) {
            throw new AgentError(
                $this->label() . ": config Nginx app `{$appId}` gagal dirakit di server agent: "
                . $this->sanitize($e->getMessage()),
                $e->reason
            );
        }

        $config = $data['config'] ?? $data['data']['config'] ?? null;
        if (!is_string($config) || trim($config) === '') {
            throw AgentError::badResponse(
                $this->label() . ": agent tidak mengembalikan config Nginx app `{$appId}` (kontrak: `{code:0, config}`)."
            );
        }

        return $config;
    }

    /**
     * Tulis/segarkan vhost app **di server agent** (job `nginx_write`).
     *
     * Agent yang memutuskan bentuk config (termasuk membuang vhost bila app tidak
     * mem-publish port host) karena di sana container app diamati — control plane
     * tidak menyalin logika itu.
     *
     * @param array<string,mixed> $app
     */
    public function writeNginxConfig(array $app): void
    {
        $this->assertDomainBaseOrFail($app);
        $this->runJob('nginx_write', $app, null, [], false);
    }

    /**
     * Hapus vhost app **di server agent** (job `nginx_remove`).
     *
     * Tidak ada pemeriksaan domain di sini dengan sengaja: penghapusan harus
     * selalu bisa dilakukan (kalau tidak, vhost basi akan tertinggal).
     *
     * @param array<string,mixed> $app
     */
    public function removeNginxConfig(array $app): void
    {
        $this->runJob('nginx_remove', $app, null, [], false);
    }

    /**
     * Reload Nginx **di server agent** (job `nginx_reload`, memakai
     * `NginxReloader` milik agent).
     *
     * Reload bersifat host-wide di server itu; `$app` hanya konteks job/audit
     * (setiap `POST /jobs` memang selalu membawa app).
     *
     * @param array<string,mixed> $app
     * @param callable|null       $logger callable(string $stage, string $message): void
     */
    public function reloadNginx(array $app, ?callable $logger = null): void
    {
        $this->assertDomainBaseOrFail($app);
        $this->runJob('nginx_reload', $app, $logger, [], false);
    }

    /**
     * Terbitkan sertifikat SSL untuk `$domain` **di server agent** (job
     * `ssl_issue`): certbot + `/etc/letsencrypt` server itu, lalu agent menulis
     * ulang vhost & reload Nginx-nya sendiri.
     *
     * Kredensial DNS (mis. Cloudflare) **tidak pernah** ikut payload: agent
     * memakai berkas kredensial yang sudah ada di server itu (`CLOUDFLARE_CREDS`
     * pada konfigurasi agent) — pilihan sadar supaya secret tidak melintas HTTP,
     * tidak masuk rekaman job, dan tidak muncul di log/UI.
     *
     * @param array<string,mixed> $app
     * @param callable|null       $logger callable(string $stage, string $message): void
     * @throws RuntimeException app tanpa `id`, domain tak sah, server agent tanpa `domain`
     */
    public function issueSsl(array $app, string $domain, ?callable $logger = null): void
    {
        $this->runSslJob('ssl_issue', $app, $domain, $logger);
    }

    /**
     * Cabut (revoke) sertifikat `$domain` **di server agent** (job `ssl_revoke`)
     * lalu agent menulis ulang vhost tanpa SSL.
     *
     * @param array<string,mixed> $app
     * @param callable|null       $logger callable(string $stage, string $message): void
     */
    public function revokeSsl(array $app, string $domain, ?callable $logger = null): void
    {
        $this->runSslJob('ssl_revoke', $app, $domain, $logger);
    }

    /**
     * Dokumen app dari agent (whitelist field non-rahasia,
     * `AgentJobController::APP_FIELDS`) — dipakai jalur SSL control plane untuk
     * menyalin **state eksekusi** milik agent (`ssl_status`/`ssl_expires_at`/…)
     * setelah job SSL, tanpa menyentuh metadata control plane.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed> `[]` bila app tidak dikenal agent
     * @throws AgentError transport selain 404
     */
    public function appState(array $app): array
    {
        return $this->pullApp($app);
    }

    /**
     * Fail-fast kontrak F2 §5: server `kind=agent` **wajib** punya `domain`
     * (`APP_DOMAIN` server itu) sebelum vhost/SSL dibuat untuk app yang memakai
     * **subdomain bawaan**-nya.
     *
     * Tanpa itu domain efektif akan diisi `APP_DOMAIN` control plane — domain
     * milik server **lain** — sehingga vhost & permintaan sertifikat di agent
     * menunjuk domain yang salah. Lebih baik menolak dengan pesan yang meminta
     * admin mengisi domain server itu.
     *
     * Pengecualian: app ber-`custom_domain` tidak butuh domain dasar server
     * (vhost-nya domain custom itu) — hanya blok redirect subdomain yang memakai
     * domain server, dan itu tidak melayani app.
     *
     * Dipakai juga `SslController`/`cli/ssl.php` **sebelum** efek samping apa pun
     * (menandai status / meluncurkan worker), dan oleh operasi yang menulis vhost
     * di agent (deploy/rebuild/rollback/apply, nginx_*, ssl_*), sehingga satu
     * aturan hanya ada di satu tempat.
     *
     * @param array<string,mixed> $server dokumen server
     * @param array<string,mixed> $app    dokumen app (opsional; bila ber-`custom_domain`
     *                                    tidak butuh domain dasar server)
     * @throws RuntimeException server agent tanpa `domain` terisi
     */
    public static function assertDomainBase(array $server, array $app = []): void
    {
        if (ServerResolver::isLocal($server) || trim((string) ($server['domain'] ?? '')) !== '') {
            return;
        }

        if (trim((string) ($app['custom_domain'] ?? '')) !== '') {
            return;
        }

        $name = trim((string) ($server['name'] ?? ''));
        $id = trim((string) ($server['id'] ?? ''));
        $label = match (true) {
            $name !== '' && $id !== '' => "Server \"{$name}\" ({$id})",
            $name !== '' => "Server \"{$name}\"",
            $id !== '' => "Server ({$id})",
            default => 'Server tanpa id',
        };

        throw new RuntimeException(
            $label . ' belum mengisi APP_DOMAIN (`domain`). Vhost & SSL untuk app di server itu '
            . 'tidak bisa dibuat dengan domain yang benar — isi domain server itu di halaman Servers (admin), lalu ulangi.'
        );
    }

    // ==================================================================
    // Job async + polling
    // ==================================================================

    /**
     * Kirim job ke agent, tunggu sampai `done`/`error` (polling status + log),
     * lalu tarik state eksekusinya.
     *
     * @param array<string,mixed>  $app
     * @param callable|null        $logger callable(string $stage, string $message): void
     * @param array<string,mixed>  $payload metadata tambahan (`ref`, `preserve_volumes`, `domain`)
     * @param bool                 $pullState tarik state eksekusi sesudah job (operasi
     *                             yang tidak mengubah state app — nginx_write/nginx_remove/
     *                             nginx_reload/ssl_* — melewatinya supaya tidak ada
     *                             panggilan HTTP yang tidak perlu; jalur SSL menarik
     *                             state sendiri lewat {@see self::appState()})
     * @return array<string,mixed> app dengan state eksekusi dari agent
     * @throws AgentError          kegagalan transport/HTTP ke agent
     * @throws RuntimeException    job `error`, timeout total, atau app tanpa id
     */
    private function runJob(string $op, array $app, ?callable $logger, array $payload = [], bool $pullState = true): array
    {
        // Op yang menulis vhost di agent (deploy & turunannya) ikut memakai
        // aturan F2 §5: app ber-subdomain bawaan di server agent tanpa `domain`
        // akan menulis config dengan domain yang salah ⇒ tolak lebih awal.
        if (in_array($op, self::ARTIFACT_OPS, true)) {
            $this->assertDomainBaseOrFail($app);
        }

        $appId = $this->appId($app);
        $body = $payload;
        $body['op'] = $op;
        $body['app'] = $app;

        // Artefak app (file compose / deploy key / template) DIKIRIM bersama job:
        // agent tidak bisa meregenerasinya dari metadata (plan §4.3). Gagal
        // memaketkan ⇒ job tidak dikirim sama sekali (jangan mengirim job yang
        // pasti gagal di tengah).
        if (in_array($op, self::ARTIFACT_OPS, true)) {
            $body['artifacts'] = $this->packArtifacts($op, $app);
        }

        $data = $this->client->post('/jobs', $body);
        $jobId = trim((string) ($data['job_id'] ?? $data['data']['job_id'] ?? ''));
        if (preg_match(self::ID_PATTERN, $jobId) !== 1) {
            throw AgentError::badResponse(
                $this->label() . ': agent tidak mengembalikan `job_id` yang valid untuk op `' . $op . '`.'
            );
        }

        $offset = $this->initialOffset($data, $appId, $logger);
        if ($logger !== null) {
            $logger('agent', "Job `{$op}` dikirim ke {$this->label()} (job {$jobId}).");
        }

        $this->pollJob($jobId, $op, $offset, $logger);

        if (!$pullState) {
            return $app;
        }

        // State eksekusi milik agent (plan §4.3) — best-effort: job sudah sukses,
        // kegagalan menarik salinannya tidak boleh menggagalkan deploy.
        return $this->mergeExecutionState($app, $logger);
    }

    /**
     * Job SSL (`ssl_issue`/`ssl_revoke`) di server agent — domain divalidasi di
     * sini (bentuk, bukan keberadaannya di app; itu tugas worker agent yang
     * memegang dokumen app) lalu dikirim sebagai satu-satunya field payload
     * tambahan.
     *
     * Kredensial DNS **tidak pernah** ikut: agent memakai berkas kredensial yang
     * sudah ada di server itu (`CLOUDFLARE_CREDS`-nya sendiri) — lihat
     * {@see self::issueSsl()}. Payload di sisi agent juga dibatasi whitelist
     * (`AgentJobController`), jadi kunci asing apa pun tidak masuk rekaman job.
     *
     * @param array<string,mixed> $app
     * @param callable|null       $logger
     * @throws RuntimeException domain kosong/tak sah, app tanpa id, server tanpa `domain`
     */
    private function runSslJob(string $op, array $app, string $domain, ?callable $logger): void
    {
        $domain = self::sslDomain($domain);

        $this->assertDomainBaseOrFail($app);
        $this->runJob($op, $app, $logger, ['domain' => $domain], false);
    }

    /**
     * Normalisasi + validasi bentuk domain SSL (fail-fast sebelum HTTP):
     * FQDN huruf kecil, tanpa skema/path/port/whitespace, ≤253 karakter.
     *
     * Keberlakuan domain terhadap app (subdomain bawaan vs custom domain)
     * diputuskan worker di server agent, yang memegang dokumen app-nya.
     *
     * @throws RuntimeException bentuk domain tidak sah
     */
    private static function sslDomain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        $label = $domain === '' ? '(kosong)' : $domain;
        if ($domain === '' || strlen($domain) > 253
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1) {
            throw new RuntimeException(
                "Domain SSL `{$label}` tidak sah (harus FQDN tanpa skema/path/port). Penerbitan dibatalkan "
                . 'di control plane sebelum menyentuh server agent.'
            );
        }

        return $domain;
    }

    /**
     * Fail-fast domain server agent (kontrak F2 §5) — lihat
     * {@see self::assertDomainBase()}.
     *
     * @param array<string,mixed> $app dokumen app yang akan ditulis vhost/SSL-nya
     */
    private function assertDomainBaseOrFail(array $app = []): void
    {
        self::assertDomainBase($this->server, $app);
    }

    /**
     * Payload artefak app untuk satu op.
     *
     * Kegagalan pemaketan disanitasi (higienitas token: pesan galat bisa memuat
     * keluaran tool) dan dilempar sebagai {@see RuntimeException} **sebelum**
     * panggilan HTTP apa pun — pemanggil (mis. `AppController`) menampilkannya
     * ke user, agent tidak pernah menerima job yang artefaknya tidak lengkap.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws RuntimeException pemaketan gagal (file compose hilang, kunci tak terbaca, …)
     */
    private function packArtifacts(string $op, array $app): array
    {
        try {
            return $this->packager->pack($app);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                $this->label() . ': artefak app tidak bisa dikirim untuk op `' . $op . '`: '
                . $this->sanitize($e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Offset awal pembacaan log = `log_offset` dari `POST /jobs` (ukuran berkas
     * log app saat job dibuat). Agent versi lama yang tidak mengirimkannya ⇒ 0
     * **dan** ditandai di log deploy (baris lama mungkin terulang).
     *
     * @param array<string,mixed> $data respons `POST /jobs`
     */
    private function initialOffset(array $data, string $appId, ?callable $logger): int
    {
        $raw = $data['log_offset'] ?? $data['data']['log_offset'] ?? null;
        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
            return max(0, (int) $raw);
        }

        if ($logger !== null) {
            $logger(
                'agent',
                'Agent tidak mengembalikan `log_offset` (versi lama) — log dibaca dari awal, '
                . 'baris log lama app ' . $appId . ' mungkin terulang di log ini.'
            );
        }

        return 0;
    }

    /**
     * Polling sampai job `done`/`error` atau batas waktu total tercapai.
     *
     * @param callable|null $logger
     * @throws RuntimeException job error / timeout
     */
    private function pollJob(string $jobId, string $op, int $offset, ?callable $logger): void
    {
        $deadline = ($this->clock)() + $this->timeout;

        while (true) {
            $job = $this->jobRecord($jobId);
            $offset = $this->drainLog($jobId, $offset, $logger);
            $status = strtolower(trim((string) ($job['status'] ?? 'pending')));

            if ($status === 'done') {
                break;
            }

            if ($status === 'error') {
                throw new RuntimeException($this->jobError($job, $op));
            }

            if ($status !== 'pending' && $status !== 'running') {
                throw AgentError::badResponse(
                    $this->label() . ": status job agent `{$status}` tidak dikenal (kontrak: pending|running|done|error)."
                );
            }

            if (($this->clock)() >= $deadline) {
                throw new RuntimeException(
                    $this->label() . " tidak menyelesaikan job `{$op}` ({$jobId}) dalam {$this->timeout} detik. "
                    . 'Job kemungkinan MASIH berjalan di server agent — periksa log deploy di sana sebelum mengulang.'
                );
            }

            ($this->sleep)($this->pollIntervalMs * 1000);
        }

        // Sisa baris terakhir sesudah job selesai (jangan ada log yang hilang).
        $this->drainLog($jobId, $offset, $logger);
    }

    /**
     * Teruskan baris log **lengkap** yang baru ke `$logger`, lalu kembalikan
     * offset lanjutan.
     *
     * Offset hanya maju sampai newline terakhir: potongan baris yang belum
     * selesai dibaca ulang pada polling berikutnya (tidak pernah diteruskan dua
     * kali, tidak pernah terpotong). Nilai ini sama dengan `X-Rames-Offset`
     * agent karena agent menghitung `next = offset + strlen(text)`.
     *
     * @param callable|null $logger
     */
    private function drainLog(string $jobId, int $offset, ?callable $logger): int
    {
        $chunk = $this->client->jobLog($jobId, $offset);
        if ($chunk === '') {
            return $offset;
        }

        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false) {
            return $offset; // belum ada baris lengkap: baca ulang mulai offset ini
        }

        $complete = substr($chunk, 0, $lastNewline + 1);
        $this->forwardLines($complete, $logger);

        return $offset + strlen($complete);
    }

    /**
     * Teruskan setiap baris log agent ke `$logger` sebagai `(stage, message)`.
     *
     * Bentuk baris log worker agent (`cli/deploy.php`):
     * `[timestamp] [stage] pesan`, `[timestamp] start mode=…`,
     * `[timestamp] done`, `[timestamp] ERROR: …`.
     *
     * @param callable|null $logger
     */
    private function forwardLines(string $text, ?callable $logger): void
    {
        if ($logger === null) {
            return;
        }

        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            $line = rtrim($line, "\r");
            if (trim($line) === '') {
                continue;
            }

            [$stage, $message] = $this->parseLogLine($line);
            $logger($stage, $message);
        }
    }

    /**
     * Pisahkan baris log agent menjadi `[stage, message]`.
     *
     * Token server dibuang dari pesan (higienitas kredensial: log bisa memuat
     * galat tool yang menggemakan header `Authorization`).
     *
     * @return array{0:string,1:string}
     */
    private function parseLogLine(string $line): array
    {
        if (preg_match('/^\[[^\]]*\]\s*\[(?<stage>[A-Za-z0-9_.-]{1,32})\]\s*(?<message>.*)$/', $line, $match) === 1) {
            return [
                (string) $match['stage'],
                $this->sanitize(trim((string) $match['message'])),
            ];
        }

        $stripped = trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', $line));
        if (stripos($stripped, 'ERROR:') === 0) {
            return ['error', $this->sanitize(trim(substr($stripped, 6)))];
        }
        if (strcasecmp($stripped, 'done') === 0) {
            return ['done', 'done'];
        }

        return ['output', $this->sanitize($stripped)];
    }

    /** Sanitasi pesan (buang token server & header `Authorization`). */
    private function sanitize(string $message): string
    {
        return AgentError::sanitize($message, $this->token());
    }

    /** Token server dari dokumen (`token`, alias lama `token_ref`). */
    private function token(): string
    {
        return trim((string) ($this->server['token'] ?? $this->server['token_ref'] ?? ''));
    }

    /**
     * Tarik state eksekusi app dari agent lalu gabungkan ke dokumen app.
     *
     * Metadata (owner, members, env, domain, …) tetap milik control plane —
     * hanya field {@see self::PULL_FIELDS} yang boleh menang dari agent
     * (plan §4.3: himpunan field disjoint).
     *
     * @param array<string,mixed> $app
     * @param callable|null       $logger
     * @return array<string,mixed>
     */
    private function mergeExecutionState(array $app, ?callable $logger): array
    {
        try {
            $doc = $this->pullApp($app);
        } catch (AgentError $e) {
            // Job sudah selesai; salinan state bisa menyusul (refresh berikutnya).
            if ($logger !== null) {
                $logger('agent', 'Gagal menarik state app dari agent: ' . $e->getMessage());
            }

            return $app;
        }

        foreach (self::PULL_FIELDS as $field) {
            if (array_key_exists($field, $doc)) {
                $app[$field] = $doc[$field];
            }
        }

        return $app;
    }

    /**
     * Dokumen app dari agent (`GET /apps/{id}`) — `[]` bila app tidak dikenal.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws AgentError transport selain 404
     */
    private function pullApp(array $app): array
    {
        $appId = $this->appId($app);

        try {
            $data = $this->client->get('/apps/' . rawurlencode($appId));
        } catch (AgentError $e) {
            if ($e->reason === AgentError::NOT_FOUND) {
                return [];
            }

            throw $e;
        }

        $doc = $data['app'] ?? $data;

        return is_array($doc) ? $doc : [];
    }

    /**
     * Rekaman job dari agent (`GET /jobs/{id}`).
     *
     * @return array<string,mixed>
     * @throws AgentError
     */
    private function jobRecord(string $jobId): array
    {
        $data = $this->client->jobStatus($jobId);
        $job = $data['job'] ?? $data;

        return is_array($job) ? $job : [];
    }

    /**
     * Pesan galat job dari agent — disanitasi (tanpa token/`Authorization`).
     *
     * @param array<string,mixed> $job
     */
    private function jobError(array $job, string $op): string
    {
        $message = trim((string) ($job['error'] ?? ''));
        if ($message === '') {
            $message = trim((string) ($job['message'] ?? ''));
        }
        if ($message === '') {
            $message = 'agent tidak melaporkan pesan galat';
        }

        return $this->label() . " gagal menjalankan `{$op}`: " . $this->sanitize($message);
    }

    /**
     * Id app di store agent (= id control plane, lihat `AgentAppProjection`).
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException app tanpa id tidak bisa dikirim ke agent
     */
    private function appId(array $app): string
    {
        $id = is_scalar($app['id'] ?? null) ? trim((string) $app['id']) : '';
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new RuntimeException(
                $this->label() . ': app tanpa `id` yang sah tidak bisa dijalankan di server agent.'
            );
        }

        return $id;
    }

    /**
     * Exception seragam operasi yang belum server-aware pada fase ini
     * (`unsupported`) — **bukan** fallback ke `LocalDeployer`.
     */
    private function unsupported(string $method, string $what): AgentError
    {
        return AgentError::unsupported(
            $what . ' belum didukung di server agent (rencana: fase operasional). '
            . "Operasi dibatalkan — `{$method}()` dijalankan di server agent, BUKAN di server lokal."
        );
    }

    /**
     * Kode `code` respons UI untuk kegagalan jalur agent (dipakai controller
     * proxy log/terminal): **404 dipertahankan** sebagai 404 (app/sesi tidak ada
     * di agent), sisanya 502 (`bad gateway` — control plane tidak bisa
     * menyelesaikan permintaan lewat server agent). Satu sumber pemetaan.
     */
    public static function failureCode(\Throwable $e): int
    {
        return $e instanceof AgentError && $e->reason === AgentError::NOT_FOUND ? 404 : 502;
    }

    /**
     * Bungkus **panggilan pendek** ke agent (log/terminal ≤30 s): galat transport
     * diberi identitas server + disanitasi (`AgentError` sudah merahasiakan token,
     * `sanitize()` menutup sisanya) sehingga pemanggil tidak perlu tahu detailnya.
     *
     * @template T
     * @param callable():T $call
     * @return T
     * @throws AgentError
     */
    private function short(string $what, callable $call): mixed
    {
        try {
            return $call();
        } catch (AgentError $e) {
            throw new AgentError(
                $this->label() . ': gagal ' . $what . ' di server agent: ' . $this->sanitize($e->getMessage()),
                $e->reason
            );
        }
    }

    /**
     * Respons JSON endpoint terminal agent (konvensi dashboard `{code,msg}` —
     * sesi tidak ada ⇒ `code:404` dengan HTTP 200, sama seperti jalur lokal).
     * `code != 0` ⇒ `AgentError` dengan reason yang setara supaya controller bisa
     * meneruskan kode yang benar (404 tetap 404).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws AgentError
     */
    private function ok(array $data, string $what): array
    {
        $code = (int) ($data['code'] ?? 0);
        if ($code === 0) {
            return $data;
        }

        $message = trim((string) ($data['msg'] ?? $data['error'] ?? ''));
        if ($message === '') {
            $message = 'agent tidak menyertakan pesan';
        }

        throw new AgentError(
            $this->label() . ': ' . $what . ' ditolak agent: ' . $this->sanitize($message),
            match ($code) {
                404 => AgentError::NOT_FOUND,
                400 => AgentError::BAD_RESPONSE,
                default => AgentError::SERVER_ERROR,
            }
        );
    }

    /**
     * Token sesi terminal dari controller — dibatasi polanya sebelum masuk URL
     * (anti path traversal), tanpa memanggil agent.
     *
     * @throws AgentError bentuk token tidak sah (permintaan pemanggil, bukan respons agent)
     */
    private function tokenRef(string $token): string
    {
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new AgentError(
                $this->label() . ': token sesi terminal tidak sah.',
                AgentError::BAD_RESPONSE
            );
        }

        return $token;
    }

    /** Label server untuk pesan galat (nama + id) — pola `DeployerFactory::label()`. */
    private function label(): string
    {
        $name = trim((string) ($this->server['name'] ?? ''));
        $id = trim((string) ($this->server['id'] ?? ''));
        if ($name !== '' && $id !== '') {
            return "Server \"{$name}\" ({$id})";
        }
        if ($name !== '') {
            return "Server \"{$name}\"";
        }

        return $id !== '' ? "Server ({$id})" : 'Server tanpa id';
    }
}
