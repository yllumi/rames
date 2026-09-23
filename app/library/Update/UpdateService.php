<?php
declare(strict_types=1);

namespace app\library\Update;

use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Orkestrasi self-update dashboard (SPECS.md §7.8 / ARCHITECTURE.md §5.14).
 *
 * Masalah inti: proses yang menjalankan update adalah proses DI DALAM container
 * yang akan di-recreate oleh `docker compose up -d --build` — ia akan mematikan
 * dirinya sendiri di tengah pekerjaan (container lama di-stop lebih dulu,
 * sehingga kegagalan di jendela itu meninggalkan dashboard mati). Karena itu
 * update dijalankan oleh **helper container terpisah** (`docker run -d`, bukan
 * bagian compose project) yang tetap hidup saat dashboard di-recreate, dengan
 * pola yang sama seperti `NginxReloader` (memakai Docker socket host).
 *
 * Helper dijalankan sebagai **uid/gid pemilik repo** karena:
 *  - `git pull` sebagai root meninggalkan file milik root di repo milik user host
 *    (`git pull` berikutnya dari SSH jadi gagal), dan
 *  - git sendiri menolak repo ber-owner lain saat berjalan sebagai root
 *    (`detected dubious ownership`).
 * Akses Docker socket diberikan lewat `--group-add <gid socket>` (angka, sesuai
 * gid host yang terlihat dari dalam container).
 *
 * Semua argumen helper disusun sebagai array (tanpa shell) dan nilainya
 * divalidasi (SHA hex, branch, path) — lihat `buildHelperCommand()`. Skrip helper
 * sendiri disalin ke `/tmp` saat start container supaya `git pull` tidak
 * menimpa berkas skrip yang sedang dieksekusi.
 */
final class UpdateService
{
    /** Aksi yang didukung. */
    public const MODES = ['update', 'rollback'];

    /** Nama container helper (prefiks). */
    public const HELPER_PREFIX = 'rames-self-update-';

    /** Skrip helper (di repo, dieksekusi di dalam helper container). */
    public const HELPER_SCRIPT = 'cli/self-update.sh';

    /** Penulis status dari helper (PHP mandiri, tanpa dependensi aplikasi). */
    public const REPORT_SCRIPT = 'cli/update-report.php';

    private ProcessRunner $runner;
    private DockerClient $docker;

    public function __construct(
        private readonly RepoInfo $repo,
        private readonly UpdateState $state,
        ?DockerClient $docker = null,
        ?ProcessRunner $runner = null,
        private readonly string $branchOverride = '',
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 10);
        $this->runner = $runner ?? new ProcessRunner();
    }

    public function state(): UpdateState
    {
        return $this->state;
    }

    public function repo(): RepoInfo
    {
        return $this->repo;
    }

    // ==================================================================
    // Ringkasan untuk UI
    // ==================================================================

    /**
     * Data siap-pakai untuk panel di halaman /nginx.
     *
     * @param bool $withPreflight false = lewati pemeriksaan prasyarat (beberapa
     *             perintah git) — dipakai endpoint status yang dipoll saat update
     *             berjalan agar tidak membebani server tiap beberapa detik.
     * @return array<string,mixed>
     */
    public function panel(bool $withPreflight = true): array
    {
        $check = $this->state->check();
        $run = $this->state->run();
        $running = $this->state->isRunning($run);
        $stale = false;

        // Run yang tidak pernah dilaporkan helper (mis. helper mati mendadak)
        // ditutup di sini supaya UI tidak macet di status "sedang berjalan".
        if ($running && !$this->isHelperRunning((string) $run['id'])) {
            $this->state->finalize('error', 'Helper update berhenti tanpa melaporkan hasil — periksa log.', $this->repoUid(), $this->repoGid());
            $run = $this->state->run();
            $running = false;
            $stale = true;
        }

        $head = $check['head'] ?? null;
        if ($head === null && $this->repo->isRepo()) {
            $head = $this->repo->headCommit();
        }

        return [
            'enabled' => (bool) config('deploy.update_enabled', true),
            'check' => $check,
            'run' => $run,
            'running' => $running,
            'stale' => $stale,
            'head' => is_array($head) ? $head : null,
            'branch' => $check['branch'] !== '' ? $check['branch'] : (string) $this->repo->branch(),
            'logs' => $this->state->logs(10),
            'preflight' => $withPreflight ? $this->preflight() : null,
        ];
    }

    // ==================================================================
    // Preflight (tanpa efek samping)
    // ==================================================================

    /**
     * Pemeriksaan sebelum update dijalankan — gagal cepat dengan pesan jelas,
     * bukan di tengah `git pull`/build.
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,context:array,branch:string,local_sha:?string,running:bool}
     */
    public function preflight(): array
    {
        $errors = [];
        $warnings = [];

        if (!(bool) config('deploy.update_enabled', true)) {
            $errors[] = 'Fitur update dinonaktifkan (UPDATE_ENABLED=false).';
        }

        $context = $this->selfContext();
        if (!$context['ok']) {
            $errors[] = (string) $context['error'];
        }

        if (!$this->repo->isRepo()) {
            $errors[] = 'Direktori dashboard bukan repo Git: ' . $this->repo->root();
        }

        foreach ([self::HELPER_SCRIPT, self::REPORT_SCRIPT] as $script) {
            if (!is_file($this->repo->root() . '/' . $script)) {
                $errors[] = 'Berkas helper tidak ditemukan: ' . $script;
            }
        }

        if ($this->repo->isRepo() && $this->repo->gitVersion() === null) {
            $errors[] = 'Binary `git` tidak tersedia di container dashboard.';
        }

        $branch = trim($this->branchOverride) !== '' ? trim($this->branchOverride) : (string) $this->repo->branch();
        if ($branch === '') {
            $errors[] = 'HEAD repo tidak berada di branch (detached) — set UPDATE_BRANCH bila memang memakai detached HEAD.';
        }

        $changes = $this->repo->trackedChanges();
        if ($changes !== []) {
            $listing = array_map(static fn (array $e): string => $e['code'] . ' ' . $e['path'], array_slice($changes, 0, 5));
            $errors[] = 'Repo punya perubahan yang belum di-commit (' . count($changes) . ' berkas): '
                . implode(', ', $listing) . (count($changes) > 5 ? ', …' : '')
                . ' — commit/stash dulu; update selalu ditolak bila repo kotor.';
        }

        $untracked = $this->repo->untrackedFiles();
        if ($untracked !== []) {
            $warnings[] = count($untracked) . ' berkas belum dilacak git (mis. '
                . implode(', ', array_slice($untracked, 0, 3)) . ') — dibiarkan apa adanya; `git pull` akan menolak sendiri bila bentrok.';
        }

        $run = $this->state->run();
        $running = $this->state->isRunning($run) && $this->isHelperRunning((string) $run['id']);
        if ($running) {
            $errors[] = 'Masih ada update yang berjalan (sejak ' . ($run['started_at'] ?? '?') . ').';
        }

        if (!is_writable($this->repo->root())) {
            $errors[] = 'Repo tidak bisa ditulis oleh dashboard: ' . $this->repo->root();
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'context' => $context,
            'branch' => $branch,
            'local_sha' => $this->repo->sha(),
            'running' => $running,
        ];
    }

    // ==================================================================
    // Menjalankan update
    // ==================================================================

    /**
     * Spawn helper update (mode `update` atau `rollback`) — detached, request
     * langsung kembali dan progres dipantau lewat `GET /api/update/status`.
     *
     * @return array{id:string,container:string,mode:string,old_sha:?string,target_sha:?string,log:string}
     */
    public function start(string $mode, string $actor): array
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('Mode update tidak dikenali: ' . $mode);
        }

        $preflight = $this->preflight();
        if (!$preflight['ok']) {
            throw new RuntimeException(implode(' ', $preflight['errors']));
        }

        $context = $preflight['context'];
        $oldSha = (string) ($preflight['local_sha'] ?? '');
        $targetSha = '';

        if ($mode === 'rollback') {
            // Rollback hanya tersedia tepat setelah UPDATE yang berhasil: targetnya
            // adalah versi yang digantikan update tersebut. Run yang gagal tidak
            // boleh jadi target (itu akan "me-rollback" ke versi yang sudah terbukti
            // tidak sehat).
            $previous = $this->state->run();
            $candidate = (string) ($previous['old_sha'] ?? '');
            if ($previous['result'] !== 'success' || $previous['mode'] !== 'update' || !self::isSha($candidate)) {
                throw new RuntimeException('Rollback hanya tersedia setelah update yang berhasil (versi sebelumnya tidak diketahui).');
            }
            $targetSha = $candidate;
        }

        $uid = $this->repoUid();
        $gid = $this->repoGid();
        if ($uid <= 0) {
            throw new RuntimeException('Tidak bisa menentukan pemilik direktori repo (uid) — update dibatalkan agar kepemilikan berkas tidak rusak.');
        }

        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $logName = $id . '.log';
        $runDir = (string) config('deploy.update_run_dir', runtime_path('logs/update'));

        $this->state->prepareRunDir($uid, $gid);

        $plan = [
            'id' => $id,
            'mode' => $mode,
            'root' => $this->repo->root(),
            'branch' => (string) $preflight['branch'],
            'project' => (string) $context['project'],
            'service' => (string) $context['service'],
            'old_sha' => $oldSha,
            'target_sha' => $targetSha,
            'health_url' => (string) $this->healthUrl($context),
            'health_timeout' => (int) config('deploy.update_health_timeout', 180),
            'rollback_timeout' => (int) config('deploy.update_rollback_timeout', 180),
            'run_dir' => rtrim($runDir, '/'),
            'log_file' => rtrim($runDir, '/') . '/' . $logName,
            'actor' => $actor,
        ];

        $planFile = rtrim($runDir, '/') . '/' . $id . '.plan.json';
        $this->writePlan($planFile, $plan);

        $this->state->startRun([
            'id' => $id,
            'mode' => $mode,
            'stage' => 'starting',
            'message' => 'Menjalankan helper update…',
            'result' => null,
            'error' => null,
            'started_at' => date('c'),
            'finished_at' => null,
            'old_sha' => $oldSha !== '' ? $oldSha : null,
            'target_sha' => $targetSha !== '' ? $targetSha : null,
            'actor' => $actor,
            'log' => $logName,
            'health_url' => $plan['health_url'],
            'rollback_from' => $mode === 'update' ? ($oldSha !== '' ? $oldSha : null) : null,
        ], $uid, $gid);

        $container = self::HELPER_PREFIX . $id;
        $command = self::buildHelperCommand([
            'image' => (string) ($context['image'] !== '' ? $context['image'] : config('deploy.update_image', '')),
            'container' => $container,
            'network' => (string) $context['network'],
            'dns' => (array) $context['dns'],
            'uid' => $uid,
            'gid' => $gid,
            'group_add' => $this->socketGid(),
            'root' => $this->repo->root(),
            'script' => $this->repo->root() . '/' . self::HELPER_SCRIPT,
            'report' => $this->repo->root() . '/' . self::REPORT_SCRIPT,
            'plan' => $planFile,
            'socket' => (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            'docker_binary' => (string) config('deploy.docker_binary', 'docker'),
        ]);

        $result = $this->runner->run($command, null, 60);
        if ($result['code'] !== 0) {
            $message = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            $this->state->finalize('error', 'Gagal menjalankan helper update: ' . ($message !== '' ? $message : 'tanpa pesan'), $uid, $gid);
            throw new RuntimeException('Gagal menjalankan helper update: ' . ($message !== '' ? $message : 'tanpa pesan'));
        }

        return [
            'id' => $id,
            'container' => $container,
            'mode' => $mode,
            'old_sha' => $oldSha !== '' ? $oldSha : null,
            'target_sha' => $targetSha !== '' ? $targetSha : null,
            'log' => $logName,
        ];
    }

    /**
     * Perintah `docker run` helper (array, tanpa shell — bebas command injection).
     *
     * `sh -c 'cp "$1" … && cp "$2" … && exec sh /tmp/… "$3"'` menyalin skrip helper
     * ke /tmp SEBELUM dijalankan: `git pull` di dalam repo akan mengganti berkas
     * skrip di tengah eksekusi, dan shell membaca skrip secara bertahap —
     * menjalankannya dari /tmp membuat eksekusi stabil.
     *
     * @param array<string,mixed> $spec
     * @return array<int,string>
     */
    public static function buildHelperCommand(array $spec): array
    {
        $docker = (string) ($spec['docker_binary'] ?? 'docker');
        $root = (string) ($spec['root'] ?? '');
        $uid = (int) ($spec['uid'] ?? 0);
        $gid = (int) ($spec['gid'] ?? 0);
        $groupAdd = (int) ($spec['group_add'] ?? 0);

        $command = [
            $docker, 'run', '-d', '--rm',
            '--name', (string) ($spec['container'] ?? ''),
            '--user', $uid . ':' . $gid,
        ];

        if ($groupAdd > 0) {
            $command[] = '--group-add';
            $command[] = (string) $groupAdd;
        }

        $network = (string) ($spec['network'] ?? '');
        if ($network !== '') {
            $command[] = '--network';
            $command[] = $network;
        }

        // DNS: daemon Docker tidak mewarisi `dns:` milik container dashboard, dan
        // di sebagian host /etc/resolv.conf host rusak → helper WAJIB memakai DNS
        // yang sama dengan dashboard agar `git pull` bisa resolve remote.
        foreach ((array) ($spec['dns'] ?? []) as $dns) {
            $dns = trim((string) $dns);
            if ($dns !== '') {
                $command[] = '--dns';
                $command[] = $dns;
            }
        }

        // Helper butuh HOME/DOCKER_CONFIG/COMPOSER_HOME yang bisa ditulis.
        foreach (['HOME=/tmp', 'DOCKER_CONFIG=/tmp/.docker', 'COMPOSER_HOME=/tmp/composer', 'PWD=' . $root] as $env) {
            $command[] = '-e';
            $command[] = $env;
        }

        $socket = (string) ($spec['socket'] ?? '/var/run/docker.sock');
        $command[] = '-v';
        $command[] = $socket . ':' . $socket;
        $command[] = '-v';
        $command[] = $root . ':' . $root;
        $command[] = '-w';
        $command[] = $root;
        $command[] = (string) ($spec['image'] ?? '');
        $command[] = 'sh';
        $command[] = '-c';
        $command[] = 'cp "$1" /tmp/rames-self-update.sh && cp "$2" /tmp/rames-update-report.php && exec sh /tmp/rames-self-update.sh "$3"';
        $command[] = 'sh';
        $command[] = (string) ($spec['script'] ?? '');
        $command[] = (string) ($spec['report'] ?? '');
        $command[] = (string) ($spec['plan'] ?? '');

        return $command;
    }

    // ==================================================================
    // Konteks container dashboard (untuk helper)
    // ==================================================================

    /**
     * Identitas container dashboard (dibaca dari Engine API saat container ini
     * meng-inspect dirinya sendiri) — dipakai helper untuk me-recreate dirinya
     * tanpa nama project/service yang di-hardcode.
     *
     * @return array{ok:bool,error:?string,id:string,name:string,image:string,project:string,service:string,network:string,dns:array<int,string>}
     */
    public function selfContext(): array
    {
        $empty = [
            'ok' => false,
            'error' => null,
            'id' => '',
            'name' => '',
            'image' => '',
            'project' => '',
            'service' => '',
            'network' => '',
            'dns' => [],
        ];

        $id = (string) (gethostname() ?: '');
        if ($id === '') {
            $id = (string) config('deploy.dashboard_container', 'rames-webman');
        }

        try {
            $inspect = $this->docker->inspectContainer($id);
        } catch (\Throwable $e) {
            $empty['error'] = 'Tidak bisa membaca identitas container dashboard dari Docker Engine: ' . $e->getMessage();

            return $empty;
        }

        $labels = is_array($inspect['Config']['Labels'] ?? null) ? $inspect['Config']['Labels'] : [];
        $networks = is_array($inspect['NetworkSettings']['Networks'] ?? null) ? $inspect['NetworkSettings']['Networks'] : [];
        $network = $networks === [] ? '' : (string) array_key_first($networks);

        $context = [
            'ok' => true,
            'error' => null,
            'id' => $id,
            'name' => ltrim((string) ($inspect['Name'] ?? ''), '/'),
            'image' => (string) ($inspect['Config']['Image'] ?? ''),
            'project' => (string) ($labels['com.docker.compose.project'] ?? ''),
            'service' => (string) ($labels['com.docker.compose.service'] ?? ''),
            'network' => $network,
            'dns' => array_values(array_filter(array_map('strval', (array) ($inspect['HostConfig']['Dns'] ?? [])))),
        ];

        if ($context['image'] === '') {
            $context['image'] = (string) config('deploy.update_image', '');
        }
        if ($context['name'] === '') {
            $context['error'] = 'Nama container dashboard tidak terbaca dari Docker Engine.';
            $context['ok'] = false;

            return $context;
        }
        if ($context['project'] === '' || $context['service'] === '') {
            $context['error'] = 'Container dashboard tidak punya label compose (project/service) — update dari dashboard tidak didukung untuk instalasi ini.';
            $context['ok'] = false;

            return $context;
        }
        if ($context['network'] === '') {
            $context['error'] = 'Container dashboard tidak terhubung ke network apa pun — helper tidak bisa menghubungi endpoint /healthz.';
            $context['ok'] = false;

            return $context;
        }

        return $context;
    }

    /**
     * URL /healthz versi baru, diakses helper lewat nama container di network yang
     * sama (bukan lewat port publik host — tidak bergantung pada APP_PORT).
     *
     * @param array<string,mixed> $context
     */
    public function healthUrl(array $context): string
    {
        return 'http://' . (string) $context['name'] . ':' . $this->listenPort() . '/healthz';
    }

    private function listenPort(): int
    {
        $listen = (string) config('process.webman.listen', '');
        if (preg_match('/:(\d+)\s*$/', $listen, $m) === 1) {
            return (int) $m[1];
        }

        return 8787;
    }

    /**
     * ID container helper yang sedang hidup (untuk deteksi run yang macet).
     */
    public function isHelperRunning(string $runId): bool
    {
        if ($runId === '') {
            return false;
        }
        try {
            $containers = $this->docker->listContainers(['name' => [self::HELPER_PREFIX . $runId]]);
        } catch (\Throwable) {
            // Engine tidak terjangkau → anggap tidak ada helper; run akan
            // ditutup sebagai error oleh panel() (fail-safe, bukan macet).
            return false;
        }

        foreach ($containers as $container) {
            $names = array_map(static fn ($n): string => ltrim((string) $n, '/'), (array) ($container['Names'] ?? []));
            if (in_array(self::HELPER_PREFIX . $runId, $names, true)) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Util
    // ==================================================================

    public function repoUid(): int
    {
        $uid = @fileowner($this->repo->root());

        return $uid === false ? 0 : (int) $uid;
    }

    public function repoGid(): int
    {
        $gid = @filegroup($this->repo->root());

        return $gid === false ? 0 : (int) $gid;
    }

    /**
     * GID socket Docker (agar helper bisa memakai `docker`/`docker compose`
     * dengan user non-root).
     */
    public function socketGid(): int
    {
        $socket = (string) config('deploy.docker_socket', '/var/run/docker.sock');
        $gid = @filegroup($socket);

        return $gid === false ? 0 : (int) $gid;
    }

    public static function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{7,64}$/i', trim($value)) === 1;
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function writePlan(string $path, array $plan): void
    {
        $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal meng-encode rencana update.');
        }
        if (@file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis rencana update: ' . $path);
        }
        @chmod($path, 0644);
    }
}
