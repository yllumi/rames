<?php
declare(strict_types=1);

namespace app\controller\server;

use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Provision\AnsibleRunner;
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
 *  - kunci privat SSH di {@see ServerSshKeyStore} (0600, slot = NAMA server yang
 *    juga dipakai {@see AnsibleRunner});
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

    /** Batas ukuran kunci privat yang diterima (64 KiB, sama dengan store). */
    private const MAX_PRIVATE_KEY_BYTES = 65536;

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
        'ssl_challenge',
        'ssl_ca_server',
    ];

    // Seam pengujian (pola `ServerController`): null ⇒ dibuat per panggilan.
    private readonly ?ProvisionJobStore $jobs;
    private readonly ?ServerStore $servers;
    private readonly ?ServerSshKeyStore $keys;
    private readonly ?ProvisionLog $logs;
    private readonly ?AnsibleRunner $runner;

    public function __construct(
        ?ProvisionJobStore $jobs = null,
        ?ServerStore $servers = null,
        ?ServerSshKeyStore $keys = null,
        ?ProvisionLog $logs = null,
        ?AnsibleRunner $runner = null,
    ) {
        $this->jobs = $jobs;
        $this->servers = $servers;
        $this->keys = $keys;
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

        $privateKey = $request->post('private_key', '');
        $privateKey = is_string($privateKey) ? trim($privateKey) : '';
        $keyError = self::privateKeyError($privateKey);
        if ($keyError !== null) {
            return $this->invalidInput($request, $post, [$keyError]);
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

        // Slot kunci = NAMA server (job berjalan sebelum baris server ada).
        try {
            $this->keys()->save($input['name'], $privateKey);
        } catch (Throwable $e) {
            $message = 'Kunci privat SSH tidak bisa disimpan: ' . $e->getMessage();
            $this->markError($jobId, $message);

            return $this->jsonError($message);
        }

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
            'active' => 'servers',
        ]);
    }

    /** Galat sisi server (job/kunci/spawn) — JSON 500 tanpa jejak rahasia. */
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

    /**
     * Pesan galat kunci privat (null = sah). Pesan **tidak pernah** memuat
     * potongan isi kunci.
     */
    private static function privateKeyError(string $privateKey): ?string
    {
        if ($privateKey === '') {
            return 'Field "private_key" wajib diisi: tempel kunci privat SSH (PEM/OpenSSH) untuk user SSH di atas.';
        }

        if (strlen($privateKey) > self::MAX_PRIVATE_KEY_BYTES) {
            return 'Field "private_key" terlalu besar (maksimal 64 KiB).';
        }

        if (!str_contains($privateKey, '-----BEGIN ')
            || !str_contains($privateKey, 'PRIVATE KEY-----')
            || !str_contains($privateKey, '-----END ')
        ) {
            return 'Field "private_key" bukan kunci privat PEM utuh (harus memuat header "BEGIN ... PRIVATE KEY"'
                . ' dan penutup "-----END").';
        }

        return null;
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

    private function keys(): ServerSshKeyStore
    {
        return $this->keys ?? new ServerSshKeyStore();
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
