<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentAppProjection;
use app\library\Agent\AgentArtifactMaterializer;
use app\library\Agent\AgentJobStore;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Storage\AppStore;
use InvalidArgumentException;
use support\Request;
use Webman\Http\Response;

/**
 * Endpoint **job async** server agent (plan §4.1/§4.3, misi F1) — dipanggil
 * control plane, bukan UI:
 *
 * ```
 * POST /jobs              {op, app:{…}, ref?, actor_id?, preserve_volumes?, artifacts:{…}, domain?}
 *                         → {code:0, job_id, op, log_offset}
 * GET  /jobs/{id}                                        → {code:0, job:{…}}
 * GET  /jobs/{id}/log?offset=N                           → teks log (polling)
 * GET  /apps/{id}                                        → {code:0, app:{…}} | 404
 * GET  /apps/{id}/nginx                                  → {code:0, config} (F2)
 * POST /nginx/check                                      → {code:0, op:'nginx_check'} (F2)
 * ```
 *
 * F2 multi-server (Nginx/SSL, plan §5) — op baru di jalur job yang sama
 * (`MODES`/`OPS`, satu sumber): `nginx_write`, `nginx_remove`, `nginx_reload`,
 * `ssl_issue`, `ssl_revoke`. Semuanya dijalankan `cli/deploy.php` **di server
 * agent** memakai library Nginx/SSL server itu. `ssl_issue`/`ssl_revoke`
 * membawa `domain` di payload (satu-satunya field payload tambahan yang
 * diizinkan — whitelist, jadi kredensial apa pun yang coba diselipkan klien
 * tidak pernah masuk rekaman job). Dua endpoint tambahan:
 *
 *  - `GET /apps/{id}/nginx` — config Nginx **hasil perakitan di agent**
 *    (`DeployerFactory::createForApp()` ⇒ `LocalDeployer::renderNginxConfig()`),
 *    supaya control plane tidak perlu menyalin logika/path Nginx;
 *  - `POST /nginx/check` — preflight `ensureWritable()` server itu. Bukan job
 *    karena `DeployerInterface::ensureWritable()` tidak membawa app, sedangkan
 *    `POST /jobs` selalu butuh app (proyeksi + log per-app).
 *
 * Alur `POST /jobs` (mediator tipis — logika di library):
 *   1. `op` wajib ada di allowlist {@see self::OPS} (mode `cli/deploy.php` yang
 *      benar-benar ada). Op tak dikenal ⇒ **501** `{reason:'unsupported'}` —
 *      diterima-tetapi-diamkan akan menyembunyikan fitur yang belum ada dari
 *      control plane;
 *   2. `app.id` + `app.name` wajib → proyeksi ke `AppStore` agent
 *      ({@see AgentAppProjection}, §4.3);
 *   3. bentuk & tipe `artifacts` divalidasi
 *      ({@see AgentArtifactMaterializer::validate()}); salah bentuk ⇒ **400**
 *      `{reason:'invalid_request'}`;
 *   4. catat job di {@see AgentJobStore} (`pending`);
 *   5. **materialisasi artefak** ke disk agent
 *      ({@see AgentArtifactMaterializer}) — file compose / deploy key SSH /
 *      template. Gagal ⇒ job ditandai `error` + respons **500**
 *      `{code:1, reason:'materialize_failed'}` (pesan disanitasi: tanpa isi
 *      kunci) dan worker **tidak** di-spawn. Urutan ini penting: worker
 *      langsung membaca direktori app, jadi artefak wajib siap lebih dulu —
 *      spawn tanpa artefak = gagal di tengah dengan pesan yang tidak
 *      menjelaskan apa pun;
 *   6. luncurkan worker detached ({@see DeployWorkerLauncher}) → respons
 *      langsung kembali; pekerjaan build berjalan sendiri (timeout HTTP ≤ 30 s,
 *      plan §3 butir 1). Spawn **gagal** ⇒ job ditandai `error` dan respons
 *      `{code:1}` — tidak pernah mengaku sukses.
 *
 * `log_offset` pada respons = **ukuran berkas log deploy app** saat job dibuat
 * ({@see DeployWorkerLauncher::logPath()}). Wajib dipakai control plane
 * ({@see \app\library\Deploy\AgentDeployer}) karena log per-app dipakai bersama
 * antar-job (plan §12 butir 5) — tanpa itu baris log job sebelumnya akan
 * terulang. Pekerja agent sendiri menerima id job lewat env `RAMES_JOB_ID`
 * (`DeployWorkerLauncher::launch()`) supaya dapat menandai job `running` →
 * `done`/`error`.
 *
 * `GET /jobs/{id}/log` melayani **log deploy app** (satu berkas yang sama
 * dengan yang ditulis worker: `runtime/logs/deploy/{appId}.log`, plan §11
 * catatan 6) lewat pembaca log job yang sudah ada
 * ({@see AgentJobStore::readLog()} — plafon 256 KiB per panggilan, potong di
 * batas baris, tanpa streaming panjang: kontrolnya polling). Karena itu store
 * pembaca diarahkan ke direktori log deploy dan kuncinya = **id app**. Header
 * `X-Rames-Offset` memberi offset lanjutan yang harus dikirim klien berikutnya.
 *
 * `GET /apps/{id}` (F1, plan §4.3 sisi *pull*) mengembalikan **dokumen proyeksi
 * app** apa adanya untuk field yang aman ({@see self::APP_FIELDS}) — tanpa
 * `env`, deploy key, token, atau PEM. Control plane memakainya untuk mengambil
 * **state eksekusi** (status/stage/message/error/containers/deploy_history/
 * ssl_status) sesudah job; metadata tetap milik control plane.
 *
 * Keamanan: route ini hanya terdaftar di mode agent (dan hanya bila
 * `AGENT_TOKEN` terisi) serta sudah dibungkus `AgentAuthMiddleware` (token +
 * timestamp + nonce + signature). Respons & rekaman job **tidak pernah** memuat
 * token, PEM, atau nilai `env` (payload job diredaksi oleh `AgentJobStore`), dan
 * pesan galat tidak pernah menggemakan isi payload.
 *
 * Deploy key (`artifacts.ssh_key`) hanya **lewat** endpoint ini sebagai payload:
 * materializer menuliskannya ke berkas `0600` lalu tidak meneruskannya ke mana
 * pun — rekaman job & respons tidak pernah memuat `artifacts`, dan pesan galat
 * materialisasi disanitasi ({@see AgentArtifactMaterializer::sanitizeError()}).
 *
 * Stateless: dependensi hanya seam uji (pola `ServerController`); `null` ⇒
 * dibangun bawaan per-request sehingga tidak ada state lintas-request pada
 * worker Webman persistent. Tanpa `session()`/`request()` di konstruktor.
 */
class AgentJobController
{
    /**
     * Op yang didukung agent = mode `cli/deploy.php` (satu sumber:
     * {@see DeployWorkerLauncher::MODES}). Dipakai untuk validasi di sini **dan**
     * dilaporkan apa adanya sebagai `capabilities` di `GET /version`
     * ({@see AgentController::version()}) — jangan menyalin daftarnya.
     */
    public const OPS = DeployWorkerLauncher::MODES;

    /**
     * Field dokumen app yang boleh keluar dari agent (whitelist, bukan
     * blacklist): metadata non-rahasia + seluruh state eksekusi. `env` (nilai
     * rahasia app), `ssh_key`, token, dan PEM **tidak pernah** ikut.
     *
     * F2: field domain/SSL ditambahkan agar jalur SSL control plane bisa
     * menyalin **state eksekusi milik agent** (status/expiry sertifikat yang
     * benar-benar ada di server itu) — tetap bukan rahasia.
     */
    public const APP_FIELDS = [
        'id',
        'name',
        'server_id',
        'subdomain',
        'custom_domain',
        'source',
        'repo_url',
        'branch',
        'compose_files',
        'limits',
        'primary_service',
        'primary_port',
        'container_prefix',
        'external_networks',
        'template',
        'status',
        'stage',
        'message',
        'error',
        'containers',
        'deploy_history',
        'ssl_status',
        'ssl_stage',
        'ssl_message',
        'ssl_error',
        'ssl_expires_at',
        'custom_ssl_status',
        'custom_ssl_stage',
        'custom_ssl_message',
        'custom_ssl_error',
        'custom_ssl_expires_at',
        'updated_at',
    ];

    /**
     * Op job yang **butuh** `domain` di payload (F2): penerbitan/pencabutan
     * sertifikat di server agent. Domain wajib supaya worker di agent tidak
     * pernah menebak-nebak domain mana yang dimaksud.
     */
    public const SSL_OPS = ['ssl_issue', 'ssl_revoke'];

    /** Bentuk domain yang boleh masuk payload (FQDN huruf kecil, tanpa skema/path/port). */
    private const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    private const DOMAIN_MAX_LENGTH = 253;

    /**
     * Field payload job yang diteruskan ke rekaman job (whitelist ketat).
     *
     * Payload **tidak pernah** disalin apa adanya: hanya field di bawah yang
     * boleh masuk rekaman. Jadi kredensial (mis. `cloudflare_creds`) yang
     * diselipkan klien tidak akan pernah tersimpan di `runtime/agent-jobs/` —
     * aturan "kredensial DNS tidak ke JSON/log/UI" ditegakkan di sini, bukan
     * hanya mengandalkan redaksi kunci.
     */
    private const PAYLOAD_FIELDS = ['ref', 'actor_id', 'preserve_volumes', 'domain'];


    private ?AgentJobStore $jobs;

    private ?DeployWorkerLauncher $launcher;

    private ?AgentAppProjection $projection;

    private ?AgentArtifactMaterializer $materializer;

    public function __construct(
        ?AgentJobStore $jobs = null,
        ?DeployWorkerLauncher $launcher = null,
        ?AgentAppProjection $projection = null,
        ?AgentArtifactMaterializer $materializer = null,
    ) {
        $this->jobs = $jobs;
        $this->launcher = $launcher;
        $this->projection = $projection;
        $this->materializer = $materializer;
    }

    /**
     * `POST /jobs` — terima job deploy, proyeksikan app, materialisasi artefak,
     * luncurkan worker.
     */
    public function create(Request $request): Response
    {
        $payload = json_decode((string) $request->rawBody(), true);
        if (!is_array($payload)) {
            return $this->error('Body request harus JSON objek yang valid.', 'invalid_json', 400);
        }

        $op = is_string($payload['op'] ?? null) ? trim($payload['op']) : '';
        if (!in_array($op, self::OPS, true)) {
            // Pesan sengaja tidak menggemakan nilai op yang dikirim klien.
            return $this->error('Op tidak didukung agent ini.', 'unsupported', 501);
        }

        $app = $payload['app'] ?? null;
        if (!is_array($app) || !is_scalar($app['id'] ?? null) || trim((string) $app['id']) === ''
            || !is_scalar($app['name'] ?? null) || trim((string) $app['name']) === '') {
            return $this->error('`app.id` dan `app.name` wajib diisi.', 'invalid_request', 400);
        }

        $appId = trim((string) $app['id']);
        $ref = is_string($payload['ref'] ?? null) ? trim($payload['ref']) : '';
        $actorId = is_string($payload['actor_id'] ?? null) ? trim($payload['actor_id']) : '';

        // Domain (F2): wajib untuk op SSL, opsional untuk op lain. Validasi
        // bentuk dilakukan di sini supaya worker di agent tidak pernah menerima
        // domain kosong/tak masuk akal; pesan galat tidak menggemakan nilainya.
        $domain = '';
        if (array_key_exists('domain', $payload)) {
            $rawDomain = $payload['domain'];
            $domain = is_scalar($rawDomain) ? strtolower(rtrim(trim((string) $rawDomain), '.')) : '';
            if (!self::validDomainShape($domain)) {
                return $this->error('`domain` tidak sah (harus FQDN tanpa skema/path/port).', 'invalid_request', 400);
            }
        }
        if ($domain === '' && in_array($op, self::SSL_OPS, true)) {
            return $this->error('`domain` wajib untuk operasi SSL.', 'invalid_request', 400);
        }

        // Artefak app (file compose / deploy key / template) divalidasi di
        // library — controller hanya mediator. Bentuk salah ⇒ 400 tanpa
        // menggemakan isi payload (bisa memuat kunci deploy).
        $rawArtifacts = $payload['artifacts'] ?? [];
        if (!is_array($rawArtifacts)) {
            return $this->error('`artifacts` harus objek JSON.', 'invalid_request', 400);
        }
        try {
            $artifacts = AgentArtifactMaterializer::validate($rawArtifacts);
        } catch (InvalidArgumentException) {
            return $this->error('Artefak job tidak valid.', 'invalid_request', 400);
        }

        // `preserve_volumes` (op `teardown`): null = hapus semua volume; array =
        // nama volume yang dipertahankan. Diteruskan lewat rekaman job — worker
        // agent (`cli/deploy.php`) membacanya dari sana.
        $preserve = null;
        if (is_array($payload['preserve_volumes'] ?? null)) {
            $preserve = array_values(array_map('strval', $payload['preserve_volumes']));
        }

        try {
            $this->projection()->upsert($app);
        } catch (InvalidArgumentException) {
            return $this->error('Dokumen app tidak valid untuk diproyeksikan.', 'invalid_request', 400);
        } catch (\Throwable) {
            // Mis. nama app bentrok di store agent: jangan jatuhkan request
            // menjadi halaman galat HTML — API mesin selalu menjawab JSON.
            return $this->error('Gagal memproyeksikan app di server agent.', 'projection_failed', 500);
        }

        // `custom_domain` (dan field metadata lain) sudah diproyeksikan oleh
        // `AgentAppProjection::METADATA` di atas — satu daftar field, tidak ada
        // salinan manual di controller (lihat catatan kelas proyeksi).

        $jobPayload = [
            'app_id' => $appId,
            'name' => (string) $app['name'],
            'ref' => $ref,
            'actor_id' => $actorId,
            'preserve_volumes' => $preserve,
        ];
        if ($domain !== '') {
            $jobPayload['domain'] = $domain;
        }

        $job = $this->jobs()->create($op, $appId, $jobPayload);
        $jobId = (string) $job['id'];

        // Ukuran log deploy app SAAT job dibuat — control plane wajib mulai
        // membaca dari sini (log dipakai bersama antar-job, plan §12 butir 5).
        $logOffset = $this->logOffset($appId);

        // Artefak WAJIB siap sebelum worker di-spawn: worker langsung membaca
        // direktori app & deploy key. Gagal ⇒ job ditandai error (jangan ada job
        // `pending` yang tidak pernah dikerjakan) dan worker TIDAK dijalankan.
        try {
            $this->materializer()->materialize($app, $artifacts);
        } catch (\Throwable $e) {
            $message = AgentArtifactMaterializer::sanitizeError($e->getMessage(), $artifacts);
            $this->jobs()->update($jobId, static function (array &$record) use ($message): void {
                $record['status'] = 'error';
                $record['stage'] = 'materialize';
                $record['message'] = $message;
                $record['error'] = $message;
                $record['finished_at'] = date('c');
            });

            return $this->error($message, 'materialize_failed', 500, $jobId);
        }

        if (!$this->launcher()->launch($appId, $op, $ref, $actorId, $jobId)) {
            // Jangan pernah mengaku sukses: job ditandai error agar control plane
            // tidak menunggu pekerjaan yang tidak pernah berjalan.
            $this->jobs()->update($jobId, static function (array &$record): void {
                $record['status'] = 'error';
                $record['message'] = 'Gagal menjalankan worker deploy di server agent.';
                $record['error'] = 'Gagal spawn worker deploy.';
                $record['finished_at'] = date('c');
            });

            return $this->error('Gagal menjalankan worker deploy di server agent.', 'spawn_failed', 500, $jobId);
        }

        return json(['code' => 0, 'job_id' => $jobId, 'op' => $op, 'log_offset' => $logOffset]);
    }

    /**
     * `GET /jobs/{id}` — status job; id tak dikenal ⇒ 404.
     */
    public function show(Request $request, string $id): Response
    {
        $job = $this->jobs()->find($id);
        if ($job === null) {
            return $this->error('Job tidak ditemukan.', 'not_found', 404);
        }

        return json(['code' => 0, 'job' => $this->publicJob($job)]);
    }

    /**
     * `GET /jobs/{id}/log?offset=N` — potongan log (maks 256 KiB, dipotong di
     * batas baris) + `X-Rames-Offset` = offset lanjutan. Klien polling memakai
     * header itu (bukan panjang body) sebagai `offset` panggilan berikutnya.
     */
    public function log(Request $request, string $id): Response
    {
        $job = $this->jobs()->find($id);
        if ($job === null) {
            return $this->error('Job tidak ditemukan.', 'not_found', 404);
        }

        $offset = $this->offsetOf($request);
        $appId = is_scalar($job['app_id'] ?? null) ? trim((string) $job['app_id']) : '';

        // Log job = log deploy app (satu berkas yang ditulis worker). Store
        // pembaca diarahkan ke direktori log worker ({@see DeployWorkerLauncher::logDir()}),
        // jadi yang dibaca adalah `{appId}.log` — bukan berkas per-job.
        $text = $appId === '' ? '' : $this->logReader()->readLog($appId, $offset);
        $next = $offset + strlen($text);

        return response($text, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Rames-Offset' => (string) $next,
        ]);
    }

    /**
     * `GET /apps/{id}` — dokumen proyeksi app (whitelist {@see self::APP_FIELDS}).
     *
     * Dipakai control plane setelah job untuk menarik **state eksekusi**
     * (plan §4.3, sisi pull). App tak dikenal ⇒ 404. Rahasia app (`env`,
     * deploy key) tidak pernah ikut: field dipilih dari whitelist, bukan
     * disalin lalu dihapus.
     */
    public function showApp(Request $request, string $id): Response
    {
        try {
            $app = $this->appStore()->find(trim($id));
        } catch (\Throwable) {
            // Basis data agent tidak terbaca: API mesin selalu menjawab JSON.
            return $this->error('Gagal membaca data app di server agent.', 'store_failed', 500);
        }

        if ($app === null) {
            return $this->error('App tidak ditemukan.', 'not_found', 404);
        }

        return json(['code' => 0, 'app' => $this->publicApp($app)]);
    }

    /**
     * `GET /apps/{id}/nginx` (F2) — config Nginx app **sebagaimana dirakit di
     * server ini** (library yang sama dengan worker deploy: `LocalDeployer` ⇒
     * `NginxConfigGenerator`), tanpa menulis apa pun ke disk.
     *
     * Perakitan di agent membuat path webroot/letsencrypt & domain efektif yang
     * dipakai adalah milik server ini (bukan control plane). App tak dikenal ⇒
     * 404; app tanpa vhost (tidak mem-publish port host) ⇒ 409 dengan pesan apa
     * adanya dari library.
     */
    public function renderNginx(Request $request, string $id): Response
    {
        try {
            $app = $this->appStore()->find(trim($id));
        } catch (\Throwable) {
            return $this->error('Gagal membaca data app di server agent.', 'store_failed', 500);
        }

        if ($app === null) {
            return $this->error('App tidak ditemukan.', 'not_found', 404);
        }

        try {
            $config = DeployerFactory::createForApp($app)->renderNginxConfig($app);
        } catch (\Throwable $e) {
            return $this->error($this->sanitizeMessage($e->getMessage()), 'render_failed', 409);
        }

        return json(['code' => 0, 'op' => 'nginx_render', 'config' => $config]);
    }

    /**
     * `POST /nginx/check` (F2) — preflight prasyarat direktori Nginx server ini
     * (`NginxConfigGenerator::ensureWritable()` lewat deployer lokal), dipakai
     * `AgentDeployer::ensureWritable()`.
     *
     * Bukan job karena tidak ada app di kontrak `DeployerInterface::ensureWritable()`
     * dan operasinya read-only (≤30 s). Yang dikembalikan hanya status —
     * tidak ada path sensitif yang digemakan.
     */
    public function checkNginx(Request $request): Response
    {
        try {
            DeployerFactory::create()->ensureWritable();
        } catch (\Throwable $e) {
            return $this->error($this->sanitizeMessage($e->getMessage()), 'nginx_unwritable', 500);
        }

        return json(['code' => 0, 'op' => 'nginx_check', 'ok' => true]);
    }

    // ==================================================================
    // Helper (mediator — tanpa logika bisnis)
    // ==================================================================

    /**
     * Bentuk domain yang boleh diproses (FQDN huruf kecil tanpa skema/path/port).
     * Keberlakuannya terhadap app diputuskan worker di server agent.
     */
    private static function validDomainShape(string $domain): bool
    {
        return $domain !== ''
            && strlen($domain) <= self::DOMAIN_MAX_LENGTH
            && preg_match(self::DOMAIN_PATTERN, $domain) === 1;
    }

    /**
     * Pesan galat dari library untuk respons API mesin: dipangkas (≤300 karakter)
     * supaya respons tetap ringkas. Dipakai apa adanya — endpoint ini hanya bisa
     * diakses pemegang token agent, dan tidak ada nilai sensitif di pesan
     * `NginxConfigGenerator` (yang dilaporkan justru path + saran izin).
     */
    private function sanitizeMessage(string $message): string
    {
        return substr(trim($message), 0, 300) ?: 'Operasi Nginx gagal di server agent.';
    }

    /**
     * Ukuran berkas log deploy app saat ini (0 bila belum ada) — dikirim sebagai
     * `log_offset` supaya control plane tidak mengulang baris log job lama.
     */
    private function logOffset(string $appId): int
    {
        $path = $this->launcher()->logPath($appId);
        clearstatcache(true, $path);
        $size = @filesize($path);

        return is_int($size) && $size > 0 ? $size : 0;
    }

    /**
     * Dokumen app untuk respons — hanya field whitelist (tanpa secret).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function publicApp(array $app): array
    {
        $out = [];
        foreach (self::APP_FIELDS as $field) {
            if (array_key_exists($field, $app)) {
                $out[$field] = $app[$field];
            }
        }

        return $out;
    }

    /** Store app milik agent (proyeksi) — path mengikuti seam yang disuntikkan. */
    private function appStore(): AppStore
    {
        return new AppStore($this->projection()->path());
    }

    /** Offset query yang diterima: bilangan bulat ≥ 0 (sisanya ⇒ 0). */
    private function offsetOf(Request $request): int
    {
        $raw = $request->get('offset', '0');
        if (is_array($raw)) {
            return 0;
        }

        return is_numeric($raw) ? max(0, (int) $raw) : 0;
    }

    /**
     * Rekaman job untuk respons — hanya field yang stabil & tidak sensitif.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function publicJob(array $job): array
    {
        return [
            'id' => (string) ($job['id'] ?? ''),
            'op' => (string) ($job['op'] ?? ''),
            'app_id' => (string) ($job['app_id'] ?? ''),
            'status' => (string) ($job['status'] ?? 'pending'),
            'stage' => (string) ($job['stage'] ?? ''),
            'message' => (string) ($job['message'] ?? ''),
            'error' => (string) ($job['error'] ?? ''),
            'created_at' => (string) ($job['created_at'] ?? ''),
            'started_at' => (string) ($job['started_at'] ?? ''),
            'finished_at' => (string) ($job['finished_at'] ?? ''),
        ];
    }

    /** Respons galat seragam (`code:1`) — pesan tetap, tanpa isi payload. */
    private function error(string $message, string $reason, int $status, string $jobId = ''): Response
    {
        $body = ['code' => 1, 'error' => $message, 'reason' => $reason];
        if ($jobId !== '') {
            $body['job_id'] = $jobId;
        }

        return json($body)->withStatus($status);
    }

    private function jobs(): AgentJobStore
    {
        return $this->jobs ??= new AgentJobStore();
    }

    private function launcher(): DeployWorkerLauncher
    {
        return $this->launcher ??= new DeployWorkerLauncher();
    }

    private function projection(): AgentAppProjection
    {
        return $this->projection ??= new AgentAppProjection();
    }

    /**
     * Materializer artefak app (sisi agent) — null ⇒ bawaan (path dari config,
     * pola yang sama `projection()`; seam uji mengarahkannya ke direktori temp).
     */
    private function materializer(): AgentArtifactMaterializer
    {
        return $this->materializer ??= new AgentArtifactMaterializer();
    }

    /** Pembaca log deploy (diarahkan ke direktori log worker — lihat {@see self::log()}). */
    private function logReader(): AgentJobStore
    {
        return new AgentJobStore($this->launcher()->logDir());
    }
}
