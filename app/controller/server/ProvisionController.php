<?php
declare(strict_types=1);

namespace app\controller\server;

use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Provision\AnsibleRunner;
use app\library\Provision\ProvisionAgentKey;
use app\library\Provision\ProvisionInput;
use app\library\Provision\ProvisionJobStore;
use app\library\Provision\ProvisionLog;
use app\library\Provision\ServerSshKeyStore;
use app\library\Storage\ServerStore;
use app\library\Support\DetachedWorker;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use Throwable;
use TypeError;
use Webman\Http\Response;

/**
 * Provisioning server agent lewat Ansible (opsi B) — **admin-only**.
 *
 * Alur: form (`GET /servers/provision`) → job (`POST /servers/provision`) →
 * worker detached `cli/provision.php <jobId>` → polling
 * (`GET /servers/provision/status?job=&offset=`) → batal
 * (`POST /servers/provision/cancel`).
 *
 * Controller ini **mediator tipis**:
 *  - validasi & normalisasi payload di {@see ProvisionInput} (satu sumber aturan
 *    nama/host/port/playbook; tanpa salinan di sini);
 *  - antrean job di {@see ProvisionJobStore} (tabel `provision_jobs`, skema v6);
 *  - kunci provisioning **bersama** di {@see ProvisionAgentKey} (satu pasangan
 *    `ed25519` untuk semua VM; private 0600 di control plane, public ditampilkan
 *    agar operator memasangnya di server tujuan) — form tidak meminta kunci privat;
 *  - proses Ansible di {@see AnsibleRunner} (helper container), dijalankan worker
 *    detached lewat {@see DetachedWorker} — bukan di worker HTTP.
 *
 * Invarian yang ditegakkan:
 *  - **non-admin ⇒ 404** (bukan 403) di **semua** action, dicek SEBELUM efek
 *    samping apa pun (tanpa store, tanpa spawn, tanpa jaringan) — pola
 *    `ServerController::guard()`;
 *  - **tanpa state properti lintas-request**: properti hanya seam pengujian
 *    (`null` ⇒ dibangun per panggilan);
 *  - **kunci privat & token agent tidak pernah** masuk log, pesan galat, atau
 *    respons JSON: controller hanya menyebut nama field/path, dan payload status
 *    dibatasi ke kolom `provision_jobs` + potongan log worker;
 *  - status HTTP lewat `->withStatus()`; argumen kedua `json()` = options JSON.
 */
class ProvisionController
{
    /** Bentuk id job yang aman untuk query string (sama dengan `ProvisionJobStore`). */
    private const JOB_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Field form yang boleh dikembalikan ke view setelah validasi gagal.
     * `private_key` SENGAJA tidak ada — rahasia tidak pernah dirender ulang.
     */
    private const ECHO_FIELDS = [
        'name',
        'host',
        'ssh_user',
        'ssh_port',
        'domain',
        'email',
        'port_range_start',
        'port_range_end',
        'agent_port',
        'bind',
        'ip',
        'manage_firewall',
        'playbook',
        'reset_host_key',
        'allow_identity_change',
        'ssl_challenge',
        'ssl_ca_server',
    ];

    // Seam pengujian (pola `ServerController`): null ⇒ dibuat per panggilan.
    private readonly ?ProvisionJobStore $jobs;
    private readonly ?ServerStore $servers;
    private readonly ?ProvisionAgentKey $agentKey;
    private readonly ?ProvisionLog $logs;
    private readonly ?AnsibleRunner $runner;

    public function __construct(
        ?ProvisionJobStore $jobs = null,
        ?ServerStore $servers = null,
        ?ProvisionAgentKey $agentKey = null,
        ?ProvisionLog $logs = null,
        ?AnsibleRunner $runner = null,
    ) {
        $this->jobs = $jobs;
        $this->servers = $servers;
        $this->agentKey = $agentKey;
        $this->logs = $logs;
        $this->runner = $runner;
    }

    // ==================================================================
    // Halaman & aksi
    // ==================================================================

    /**
     * `GET /servers/provision` — form provisioning.
     *
     * Payload view: `defaults` (nilai awal form), `values` (`[]`), `errors`
     * (`[]`), `active` (`'servers'`).
     */
    public function form(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        return $this->renderView('server/provision', [
            'defaults' => $this->defaults(),
            'values' => [],
            'errors' => [],
            'agent_key' => $this->agentKeyState(),
            // Daftar job supaya status tetap terlihat setelah reload/tab baru.
            'jobs' => $this->recentJobs(),
            'active_job' => $this->latestActiveJob(),
            'active' => 'servers',
        ]);
    }

    /**
     * `POST /servers/provision` — buat job, simpan kunci, jalankan worker.
     *
     * Respons sukses `{code:0, job_id, status_url}`; validasi gagal ⇒ 422 JSON
     * (pemanggil AJAX) atau form dengan `errors` + input lama (tanpa kunci
     * privat); kegagalan menyimpan kunci/spawn ⇒ 500 JSON + `markError`.
     */
    public function store(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $post = $request->post();
        $post = is_array($post) ? $post : [];

        try {
            $input = ProvisionInput::validate($post);
        } catch (InvalidArgumentException | TypeError | RuntimeException $e) {
            return $this->invalidInput($request, $post, [$e->getMessage()]);
        }

        if ($this->servers()->nameExists($input['name'])) {
            return $this->invalidInput($request, $post, [
                'Nama server "' . $input['name'] . '" sudah dipakai di registry server. Pilih nama lain'
                . ' (nama itu juga menjadi slot kunci SSH & nama host inventori).',
            ]);
        }

        // Form tidak lagi meminta kunci privat: kunci provisioning bersama
        // harus sudah dibuat (klik "Buat kunci" di halaman ini) dan public
        // key-nya dipasang di server tujuan. Tanpa itu, SSH helper pasti gagal.
        if (!$this->agentKey()->exists()) {
            return $this->invalidInput($request, $post, [
                'Kunci provisioning bersama belum dibuat. Klik "Buat kunci" di bagian atas halaman,'
                . ' salin public key yang muncul ke ~/.ssh/authorized_keys user SSH di server tujuan,'
                . ' lalu jalankan provisioning.',
            ]);
        }

        // "Percayai host key baru": aksi admin eksplisit yang menghapus pin lama
        // untuk host:port itu SEBELUM worker mem-pin ulang (TOFU). Tanpa ini,
        // server yang dipasang ulang memblokir provisioning selamanya dengan
        // "host key server berubah". CSRF & otorisasi admin sudah berlaku di
        // route ini; halaman menjelaskan bahwa fingerprint wajib dibandingkan
        // dulu dengan server tujuan.
        if (self::truthy($post['reset_host_key'] ?? null)) {
            try {
                $this->hostKeyStore()->forgetKnownHost($input['host'], (int) $input['ssh_port']);
            } catch (Throwable $e) {
                return $this->invalidInput($request, $post, [
                    'Pin host key lama tidak bisa dihapus: ' . $e->getMessage(),
                ]);
            }
        }

        try {
            $job = $this->jobs()->create([
                'name' => $input['name'],
                'playbook' => $input['playbook'],
                'host' => $input['host'],
                'ssh_user' => $input['ssh_user'],
                'ssh_port' => $input['ssh_port'],
                'domain' => $input['domain'],
                'port_range_start' => $input['port_range_start'],
                'port_range_end' => $input['port_range_end'],
                'agent_port' => $input['agent_port'],
                'control_plane_ips' => $input['control_plane_ips'],
                'manage_firewall' => $input['manage_firewall'],
                // Opsi identitas opsional (bukan rahasia); kosong = installer
                // mempertahankan nilai lama di `secrets/agent.env`.
                'email' => $input['email'],
                'bind' => $input['bind'],
                'ip' => $input['ip'],
                'ssl_challenge' => $input['ssl_challenge'],
                'ssl_ca_server' => $input['ssl_ca_server'],
                'message' => 'Menunggu worker provisioning.',
            ]);
        } catch (Throwable $e) {
            return $this->jsonError('Job provisioning tidak bisa dibuat: ' . $e->getMessage());
        }

        $jobId = (string) ($job['id'] ?? '');

        $pid = 0;
        try {
            $spawned = $this->spawnWorker(
                [PHP_BINARY, base_path() . '/cli/provision.php', $jobId],
                $pid,
                ['RAMES_PROVISION_JOB_ID' => $jobId]
            );
        } catch (Throwable) {
            $spawned = false;
        }

        if (!$spawned) {
            $message = 'Worker provisioning tidak bisa dijalankan di control plane ini'
                . ' (pcntl tidak tersedia atau fork gagal). Job ditandai gagal; periksa log server.';
            $this->markError($jobId, $message);

            return $this->jsonError($message);
        }

        return json([
            'code' => 0,
            'job_id' => $jobId,
            'status_url' => '/servers/provision/status?job=' . rawurlencode($jobId),
        ]);
    }

    /**
     * `GET /servers/provision/jobs` — daftar job provisioning terbaru (JSON).
     *
     * Dipakai halaman `/servers` untuk memantau job yang belum punya baris server
     * (job berjalan SEBELUM registrasi). Admin-only ⇒ 404; tidak ada rahasia di
     * payload (kolom `provision_jobs` saja).
     */
    public function jobList(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        return json(['code' => 0, 'jobs' => $this->recentJobs()]);
    }

    /**
     * `POST /servers/provision/keypair` — buat kunci provisioning bersama bila belum ada.
     *
     * Idempoten: kunci yang sudah ada **tidak** diregenerasi (public key yang
     * sudah dipasang di VM lain tetap sah). Respons **hanya** memuat public key +
     * fingerprint; private key tidak pernah dikirim ke browser.
     *
     * Respons: `{code:0, public_key, fingerprint, created}`; gagal ⇒ 500 `{code:1,error}`.
     */
    public function keypair(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $key = $this->agentKey()->ensure();
        } catch (Throwable $e) {
            return $this->jsonError('Kunci provisioning tidak bisa dibuat: ' . $e->getMessage());
        }

        return json([
            'code' => 0,
            'public_key' => $key['public_key'],
            'fingerprint' => $key['fingerprint'],
            'created' => $key['created'],
        ]);
    }

    /**
     * `GET /servers/provision/status?job=<id>&offset=<n>` — status job + log baru.
     *
     * Job tidak ada / id berbentuk asing ⇒ 404. Respons **tidak pernah** memuat
     * token agent atau kunci privat: hanya kolom `provision_jobs` + potongan log
     * worker mulai `offset`.
     */
    public function status(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $jobId = $request->get('job', '');
        $jobId = is_string($jobId) ? trim($jobId) : '';

        $offset = $request->get('offset', 0);
        $offset = is_scalar($offset) ? (int) $offset : 0;
        $offset = max(0, $offset);

        $job = $this->jobs()->find($jobId);
        if ($job === null) {
            return json(['code' => 1, 'error' => 'Job provisioning tidak ditemukan.'])->withStatus(404);
        }

        $chunk = ['content' => '', 'offset' => $offset];
        try {
            $chunk = $this->logs()->read($jobId, $offset);
        } catch (Throwable) {
            // id sudah valid; kegagalan I/O log tidak boleh menggagalkan polling.
        }

        return json([
            'code' => 0,
            'job_id' => (string) ($job['id'] ?? $jobId),
            'status' => (string) ($job['status'] ?? ''),
            'stage' => (string) ($job['stage'] ?? ''),
            'message' => (string) ($job['message'] ?? ''),
            'error' => (string) ($job['error'] ?? ''),
            'server_id' => (string) ($job['server_id'] ?? ''),
            'created_at' => (string) ($job['created_at'] ?? ''),
            'started_at' => (string) ($job['started_at'] ?? ''),
            'finished_at' => (string) ($job['finished_at'] ?? ''),
            'log' => (string) $chunk['content'],
            'offset' => (int) $chunk['offset'],
        ]);
    }

    /**
     * `POST /servers/provision/cancel` — hentikan helper container job (idempoten).
     *
     * Job yang sudah terminal / tidak ada tetap dijawab `{code:0}`: tombol Batal
     * tidak boleh berubah menjadi galat hanya karena worker selesai lebih dulu.
     */
    public function cancel(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $jobId = $request->post('job', '');
        $jobId = is_string($jobId) ? trim($jobId) : '';
        if (preg_match(self::JOB_ID_PATTERN, $jobId) !== 1) {
            return json(['code' => 1, 'error' => 'Id job provisioning tidak valid.'])->withStatus(422);
        }

        $job = $this->jobs()->find($jobId);
        if ($job === null) {
            return json(['code' => 0]);
        }

        $status = (string) ($job['status'] ?? '');
        if (in_array($status, [
            ProvisionJobStore::STATUS_DONE,
            ProvisionJobStore::STATUS_ERROR,
            ProvisionJobStore::STATUS_CANCELLED,
        ], true)) {
            return json(['code' => 0]);
        }

        try {
            $this->runner()->cancel($jobId);
        } catch (Throwable) {
            // Pembatalan best-effort: docker bisa tidak tersedia; status job
            // tetap ditandai `cancelled` supaya polling berhenti.
        }

        try {
            $this->jobs()->markCancelled($jobId);
        } catch (Throwable) {
            return json(['code' => 1, 'error' => 'Status job tidak bisa diperbarui.'])->withStatus(500);
        }

        return json(['code' => 0]);
    }

    // ==================================================================
    // Respons & seam
    // ==================================================================

    /**
     * Penjagaan hak akses: **non-admin ⇒ 404** (bukan 403, tanpa membocorkan
     * keberadaan provisioning). `null` = boleh lanjut.
     */
    private function guard(Request $request): ?Response
    {
        try {
            ServerAccess::requireManageServers($this->currentUser());
        } catch (AppAccessDenied) {
            return response('', 404)->withStatus(404);
        }

        return null;
    }

    /**
     * Input tidak sah: JSON 422 untuk pemanggil AJAX, selain itu form dirender
     * ulang dengan pesan galat + input lama (**tanpa** kunci privat).
     *
     * @param array<string,mixed> $post   input mentah form
     * @param array<int,string>   $errors
     */
    private function invalidInput(Request $request, array $post, array $errors): Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 1, 'error' => (string) ($errors[0] ?? 'Input provisioning tidak valid.')])
                ->withStatus(422);
        }

        return $this->renderView('server/provision', [
            'defaults' => $this->defaults(),
            'values' => self::echoValues($post),
            'errors' => $errors,
            // Status kunci harus ikut: kalau tidak, panel tampak "belum dibuat"
            // setelah validasi gagal dan user mengira kuncinya hilang.
            'agent_key' => $this->agentKeyState(),
            'active' => 'servers',
        ]);
    }

    /** Galat sisi server (job/kunci/spawn) — JSON 500 tanpa jejak rahasia. */
    /** Nilai checkbox/flag form → bool ('' / '0' / 'false' = false). */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return false;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off'], true);
    }

    private function jsonError(string $message): Response
    {
        return json(['code' => 1, 'error' => $message])->withStatus(500);
    }

    /** `markError` best-effort: kegagalan menyimpan status tidak menutupi galat asli. */
    private function markError(string $jobId, string $message): void
    {
        if ($jobId === '') {
            return;
        }

        try {
            $this->jobs()->markError($jobId, $message);
        } catch (Throwable) {
            // baris job bisa saja sudah dihapus operator
        }
    }

    /**
     * Job terbaru yang aman ditampilkan (tanpa kolom rahasia — tabelnya memang
     * tidak punya kolom rahasia) + `can_cancel` agar UI tidak menawarkan batal
     * pada job yang sudah terminal.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentJobs(int $limit = 10): array
    {
        try {
            $jobs = $this->jobs()->recent($limit);
        } catch (Throwable) {
            // Halaman utama tidak boleh gagal hanya karena daftar job bermasalah.
            return [];
        }

        return array_map(static function (array $job): array {
            $status = (string) ($job['status'] ?? '');
            $job['can_cancel'] = in_array($status, [
                ProvisionJobStore::STATUS_QUEUED,
                ProvisionJobStore::STATUS_RUNNING,
            ], true);

            return $job;
        }, $jobs);
    }

    /**
     * Job aktif terbaru (untuk melanjutkan pemantauan setelah reload).
     *
     * @return array<string,mixed>|null
     */
    private function latestActiveJob(): ?array
    {
        try {
            return $this->jobs()->latestActive();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Status kunci provisioning bersama untuk view (tanpa private key).
     *
     * @return array{exists:bool,slot:string,public_key:string,fingerprint:string,public_path:string}
     */
    private function agentKeyState(): array
    {
        $key = $this->agentKey();
        $exists = $key->exists();

        return [
            'exists' => $exists,
            'slot' => ProvisionAgentKey::SLOT,
            'public_key' => $exists ? $key->publicKey() : '',
            'fingerprint' => $exists ? $key->fingerprint() : '',
            'public_path' => $key->publicPath(),
        ];
    }

    /**
     * Nilai awal form (tanpa rahasia apa pun).
     *
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        $range = config('deploy.port_range');
        $range = is_array($range) ? $range : [];

        return [
            'name' => '',
            'host' => '',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'domain' => '',
            'email' => '',
            'port_range_start' => (int) ($range['start'] ?? 30000),
            'port_range_end' => (int) ($range['end'] ?? 30999),
            'agent_port' => ProvisionInput::AGENT_PORT,
            'bind' => '0.0.0.0:' . ProvisionInput::AGENT_PORT,
            'ip' => '',
            'control_plane_ips' => [],
            'manage_firewall' => true,
            'playbook' => ProvisionInput::DEFAULT_PLAYBOOK,
            'ssl_challenge' => '',
            'ssl_ca_server' => '',
            'playbooks' => array_keys(ProvisionInput::PLAYBOOKS),
        ];
    }

    /**
     * Input lama yang aman dirender ulang (hanya scalar field whitelist).
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function echoValues(array $post): array
    {
        $values = [];
        foreach (self::ECHO_FIELDS as $field) {
            $value = $post[$field] ?? null;
            if (is_scalar($value)) {
                $values[$field] = (string) $value;
            }
        }

        $ips = $post['control_plane_ips'] ?? null;
        if (is_string($ips)) {
            $values['control_plane_ips'] = $ips;
        } elseif (is_array($ips)) {
            $values['control_plane_ips'] = implode(', ', array_map(
                static fn (mixed $ip): string => is_scalar($ip) ? trim((string) $ip) : '',
                $ips
            ));
        }

        return $values;
    }

    /** User yang sedang login (seam; default membaca session). */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /**
     * Render view (seam pengujian: `view()` butuh konteks HTTP).
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        return view($template, $vars);
    }

    /**
     * Spawn worker CLI detached (seam pengujian: tanpa fork nyata).
     *
     * @param array<int,string>    $argv
     * @param array<string,string> $env
     */
    protected function spawnWorker(array $argv, ?int &$pid = null, array $env = []): bool
    {
        return DetachedWorker::spawn($argv, $pid, $env);
    }

    private function jobs(): ProvisionJobStore
    {
        return $this->jobs ?? new ProvisionJobStore();
    }

    private function servers(): ServerStore
    {
        return $this->servers ?? new ServerStore();
    }

    /**
     * Store pin host key (seam pengujian: direktori kunci temp).
     */
    protected function hostKeyStore(): ServerSshKeyStore
    {
        return new ServerSshKeyStore();
    }

    private function agentKey(): ProvisionAgentKey
    {
        return $this->agentKey ?? new ProvisionAgentKey();
    }

    private function logs(): ProvisionLog
    {
        return $this->logs ?? new ProvisionLog();
    }

    private function runner(): AnsibleRunner
    {
        return $this->runner ?? new AnsibleRunner();
    }
}
