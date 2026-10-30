<?php
declare(strict_types=1);

namespace app\library\Update;

use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Orkestrasi self-update dashboard (docs/SPECS.md §7.8 / docs/ARCHITECTURE.md §5.14).
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
 *
 * Instalasi yang SELURUHNYA dijalankan sebagai root (mis. server agent yang
 * di-login sebagai root) adalah satu-satunya pengecualian, dan hanya lewat
 * opt-in eksplisit `UPDATE_ALLOW_ROOT=true` — helper lalu berjalan `--user 0:0`
 * setelah kepemilikan repo terbukti konsisten (repo + `.git` sama-sama root).
 * Akses Docker socket diberikan lewat `--group-add <gid socket>` (angka, sesuai
 * gid host yang terlihat dari dalam container).
 *
 * Semua argumen helper disusun sebagai array (tanpa shell) dan nilainya
 * divalidasi (SHA hex, branch, path) — lihat `buildHelperCommand()`. Skrip helper
 * sendiri disalin ke `/tmp` saat start container supaya `git pull` tidak
 * menimpa berkas skrip yang sedang dieksekusi.
 *
 * Helper di-attach ke **network non-internal milik project compose dashboard**
 * (biasanya `<project>_default`): ia butuh egress internet (`git fetch`,
 * `composer install`, `docker pull`) sekaligus harus berada di network tempat
 * container dashboard hasil recreate berada (health-check lewat nama container).
 * Network dipilih lewat `chooseHelperNetwork()` — TIDAK boleh diambil sembarang
 * dari `NetworkSettings.Networks` (lihat method tersebut).
 *
 * Dua kebenaran lagi yang WAJIB diteruskan ke helper (kalau tidak, update di
 * server agent merusak dirinya sendiri):
 *  - **berkas compose** container ini (`resolveComposeFiles()` →
 *    `COMPOSE_FILE`/`COMPOSE_PROJECT_NAME`): tanpa `-f`, `docker compose` memilih
 *    `docker-compose.yml` default di `--project-directory` — di server agent itu
 *    template DASHBOARD, sehingga container agent diganti container dashboard;
 *  - **skema health check** (`healthUrl()`): mode agent menyalakan TLS lewat
 *    `process.webman.transport = ssl`, jadi `/healthz` hanya dilayani HTTPS
 *    (helper memakai `curl -k` untuk sertifikat self-signed).
 */
final class UpdateService implements UpdateRunner
{
    /** Aksi yang didukung. */
    public const MODES = ['update', 'rollback'];

    /** Nama container helper (prefiks). */
    public const HELPER_PREFIX = 'rames-self-update-';

    /** Skrip helper (di repo, dieksekusi di dalam helper container). */
    public const HELPER_SCRIPT = 'cli/self-update.sh';

    /** Penulis status dari helper (PHP mandiri, tanpa dependensi aplikasi). */
    public const REPORT_SCRIPT = 'cli/update-report.php';

    /**
     * Env interpolasi compose yang boleh diteruskan ke helper (`-e NAME=value`).
     *
     * Helper menjalankan `docker compose -p … --project-directory … up` dengan
     * environment-nya SENDIRI, sehingga setiap `${VAR:-default}` di compose
     * kembali ke default bila variabelnya tidak ada. Untuk agent itu berarti
     * `AGENT_PORT_PUBLISH` kembali ke `8443` walau instalasi memakai port lain
     * (`--bind`, dipandu `agent/install.sh`) — container hasil recreate pindah
     * port dan control plane menembak port lama (timeout). Hal yang sama berlaku
     * untuk wiring host yang dikustomisasi (`NGINX_*`, `DOCKER_*`, `RAMES_DB_DIR`,
     * `DNS_*`).
     *
     * Allowlist ini **eksplisit**: rahasia/identitas (`AGENT_TOKEN`,
     * `AGENT_TLS_*`, `ADMIN_*`, `APP_DOMAIN`, `PORT_RANGE_*`, kunci apa pun)
     * TIDAK PERNAH diteruskan. Nilai itu memang dibaca `docker compose` dari
     * `env_file` di dalam project — bukan dari environment helper.
     */
    private const COMPOSE_ENV_ALLOWLIST = [
        'NGINX_CONF_PATH',
        'NGINX_ENABLED_PATH',
        'NGINX_HTTP_CONF',
        'NGINX_BIN',
        'NGINX_RELOAD_IMAGE',
        'DOCKER_SOCKET',
        'DOCKER_BINARY',
        'RAMES_DB_DIR',
        'DNS_1',
        'DNS_2',
    ];

    /** Port container agent yang dipublikasikan lewat `AGENT_PORT_PUBLISH`. */
    private const AGENT_CONTAINER_PORT = '8443';

    /**
     * Nama project compose container agent (`docker compose -f docker-compose.agent.yml
     * -p rames-agent`, ditulis `agent/install.sh`). Dipakai HANYA di fallback
     * pemilihan berkas compose saat label `com.docker.compose.project.config_files`
     * tidak ada — bukan tebakan dari nama container.
     */
    private const AGENT_COMPOSE_PROJECT = 'rames-agent';

    /** Nama berkas compose agent (selain template dashboard `docker-compose.yml`). */
    private const AGENT_COMPOSE_FILE = 'docker-compose.agent.yml';

    private ProcessRunner $runner;
    private DockerClient $docker;

    /**
     * @param string        $branchOverride branch paksa (UPDATE_BRANCH), '' = dari HEAD
     * @param Closure|null  $ownerStat      seam uji: `(string $path): array{0:int|false,1:int|false}`
     *                                      pengganti `fileowner()`/`filegroup()` direktori repo
     *                                      (default: stat sungguhan). Dipakai uji untuk
     *                                      mensimulasikan repo milik root / stat gagal tanpa
     *                                      bergantung pada uid proses uji. Seam yang sama dipanggil
     *                                      dengan `<root>/.git` saat memeriksa konsistensi
     *                                      kepemilikan root (UPDATE_ALLOW_ROOT).
     */
    public function __construct(
        private readonly RepoInfo $repo,
        private readonly UpdateState $state,
        ?DockerClient $docker = null,
        ?ProcessRunner $runner = null,
        private readonly string $branchOverride = '',
        private readonly ?\Closure $ownerStat = null,
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
            // Catatan: pemeriksaan ini lemah — ia dievaluasi sebagai **root**
            // (container dashboard berjalan sebagai root, `is_writable()` hampir
            // selalu true). Yang menentukan adalah blok kepemilikan di bawah.
            $errors[] = 'Repo tidak bisa ditulis oleh dashboard: ' . $this->repo->root();
        }

        // 1) Repo yang OWNER-nya campur: git sendiri menolaknya walau izin tulis
        //    cukup (`detected dubious ownership`). Diperiksa lebih dulu karena
        //    pesannya berbeda dari "tidak bisa ditulis" — dan karena urutan cek
        //    ini murni `fileowner`/`filegroup` (tanpa menjalankan git).
        $gitDirError = self::gitDirOwnershipError($this->repo->root());
        if ($gitDirError !== null) {
            $errors[] = $gitDirError;
        }

        // 2) Kepemilikan repo: helper berjalan `--user <uid>:<gid>` = **pemilik
        //    direktori repo** (SPECS §7.8 / ARCHITECTURE §5.14) supaya berkas hasil
        //    pull tidak jadi milik root. Konsekuensinya repo — termasuk `.git` —
        //    WAJIB bisa ditulis oleh uid:gid itu; kalau tidak, helper sudah menyentuh
        //    repo (fetch/merge) lalu gagal di tengah jalan. Kepemilikan campuran
        //    (mis. `.git` root-owned sisa `sudo git …`) adalah penyebab klasiknya.
        //    Bila pemilik direktori repo TIDAK bisa dipakai (stat gagal atau uid 0
        //    = root), itu ditolak lebih dulu dengan pesan yang menyebut sebab +
        //    jalan keluar — dulu kasus ini hanya muncul sebagai kegagalan job
        //    ("Tidak bisa menentukan pemilik direktori repo (uid)") di `start()`.
        //    uid 0 hanya diloloskan bila UPDATE_ALLOW_ROOT=true (opt-in eksplisit)
        //    DAN kepemilikan konsisten (repo + `.git` sama-sama root).
        $allowRoot = $this->allowRootEnabled();
        $owner = $this->repoOwnerStat();
        $repoUid = $owner['uid'] === false ? 0 : (int) $owner['uid'];
        $repoGid = $owner['gid'] === false ? 0 : (int) $owner['gid'];
        $ownerError = $this->repo->isRepo()
            ? self::repoOwnerUnusableError(
                $this->repo->root(),
                $owner['uid'],
                $owner['gid'],
                $allowRoot,
                $allowRoot && $repoUid === 0 ? $this->gitDirOwnerUid() : null
            )
            : null;
        if ($ownerError !== null) {
            $errors[] = $ownerError;
        }

        // Cek izin tulis hanya relevan untuk helper NON-root: uid 0 boleh menulis
        // apa pun, jadi daftar "path yang tidak bisa ditulis" tidak bermakna.
        if ($this->repo->isRepo() && $ownerError === null && $repoUid > 0) {
            $offenders = self::unwritableHelperPaths($this->repo->root(), $repoUid, $repoGid);
            if ($offenders !== []) {
                $errors[] = 'Repo tidak bisa ditulis oleh helper update (uid:gid '
                    . $repoUid . ':' . $repoGid . '): ' . implode(', ', $offenders)
                    . '. Helper self-update berjalan sebagai pemilik direktori repo, sehingga repo — termasuk `.git` — '
                    . 'wajib milik uid:gid itu (kepemilikan campuran, mis. `.git` milik root sisa `sudo git …`, '
                    . 'membuat `git fetch` gagal menulis object: "insufficient permission for adding an object to repository database"). '
                    . 'Perbaiki di host: sudo chown -R ' . $repoUid . ':' . $repoGid . ' ' . $this->repo->root();
            }
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

        // Sabuk pengaman terakhir (preflight sudah menolak lebih dulu dengan pesan
        // yang sama): pastikan TIDAK ADA efek samping sebelum uid:gid helper jelas.
        // uid 0 = root SAH hanya bila UPDATE_ALLOW_ROOT=true dan kepemilikan repo
        // konsisten (repo + `.git` sama-sama root) — helper lalu berjalan `--user 0:0`.
        $allowRoot = $this->allowRootEnabled();
        $owner = $this->repoOwnerStat();
        $uid = $owner['uid'] === false ? 0 : (int) $owner['uid'];
        $gid = $owner['gid'] === false ? 0 : (int) $owner['gid'];
        $ownerError = self::repoOwnerUnusableError(
            $this->repo->root(),
            $owner['uid'],
            $owner['gid'],
            $allowRoot,
            $allowRoot && $uid === 0 ? $this->gitDirOwnerUid() : null
        );
        if ($uid <= 0 && ($ownerError !== null || !$allowRoot)) {
            throw new RuntimeException(
                $ownerError
                    ?? 'Tidak bisa menentukan pemilik direktori repo ' . $this->repo->root()
                        . ' (uid:gid ' . self::ownerLabel($owner['uid'], $owner['gid'])
                        . ') — update dibatalkan agar kepemilikan berkas tidak rusak.'
            );
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
            // Berkas compose yang dipakai (diagnosis: tanpa `-f` yang benar helper
            // bisa me-recreate service template dashboard di server agent).
            'compose_files' => array_values((array) ($context['compose_files'] ?? [])),
            // Dicatat di plan supaya helper bisa menuliskannya ke log: kegagalan
            // jaringan (DNS/egress) tanpa jejak network hampir mustahil didiagnosis.
            'network' => (string) $context['network'],
            'dns' => array_values((array) $context['dns']),
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
            // Interpolasi compose yang efektif (AGENT_PORT_PUBLISH, NGINX_*, …)
            // + berkas compose container ini (COMPOSE_FILE/COMPOSE_PROJECT_NAME)
            // diteruskan sebagai env helper supaya recreate tidak kembali ke
            // default installer dan tidak memakai template compose yang salah.
            'env' => self::helperEnv($context),
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
     * `$spec['env']` (nama⇒nilai, hasil {@see helperEnv()}) diteruskan sebagai
     * pasangan `-e NAME=value` SETELAH env bawaan — nilainya sudah disaring
     * allowlist (`composeEnvFromInspect()`) + `COMPOSE_FILE`/`COMPOSE_PROJECT_NAME`,
     * jadi tanpa rahasia dan tetap satu elemen argv per variabel.
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

        // Nilai interpolasi compose yang EFEKTIF di container dashboard (mis.
        // `AGENT_PORT_PUBLISH` hasil `--bind` installer, `NGINX_*`/`DNS_*` kustom).
        // Tanpa ini `docker compose up` di helper memakai `${VAR:-default}` sehingga
        // container hasil recreate bisa pindah port/path. Nilainya sudah disaring
        // allowlist di `composeEnvFromInspect()` — tanpa rahasia, tanpa shell
        // (`NAME=value` tetap SATU elemen argv).
        foreach ((array) ($spec['env'] ?? []) as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if ($name === '' || $value === '') {
                continue;
            }
            $command[] = '-e';
            $command[] = $name . '=' . $value;
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
     * @return array{ok:bool,error:?string,id:string,name:string,image:string,project:string,service:string,network:string,dns:array<int,string>,env:array<string,string>,compose_files:array<int,string>}
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
            'env' => [],
            'compose_files' => [],
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
        $attached = array_values(array_map('strval', array_keys($networks)));
        $project = (string) ($labels['com.docker.compose.project'] ?? '');
        $composeFiles = self::resolveComposeFiles(
            (string) ($labels['com.docker.compose.project.config_files'] ?? ''),
            (string) ($labels['com.docker.compose.project.working_dir'] ?? ''),
            $this->repo->root(),
            $project
        );
        $network = self::chooseHelperNetwork($attached, $this->internalNetworkFlags($attached), $project);

        $context = [
            'ok' => true,
            'error' => null,
            'id' => $id,
            'name' => ltrim((string) ($inspect['Name'] ?? ''), '/'),
            'image' => (string) ($inspect['Config']['Image'] ?? ''),
            'project' => $project,
            'service' => (string) ($labels['com.docker.compose.service'] ?? ''),
            'network' => $network,
            'dns' => array_values(array_filter(array_map('strval', (array) ($inspect['HostConfig']['Dns'] ?? [])))),
            // Nilai interpolasi compose yang saat ini efektif — diteruskan helper
            // sebagai `-e NAME=value` supaya recreate tidak kembali ke default
            // (lihat COMPOSE_ENV_ALLOWLIST).
            'env' => self::composeEnvFromInspect($inspect),
            // Berkas compose yang SAMA dengan yang dipakai container ini. Helper
            // WAJIB memakai daftar ini (`COMPOSE_FILE`): tanpa `-f`, `docker
            // compose` di `--project-directory` memilih `docker-compose.yml`
            // default — di server agent itu template DASHBOARD, sehingga helper
            // mengganti container agent (`rames-agent`) dengan container
            // dashboard (`rames-webman`) dan agent hilang.
            'compose_files' => $composeFiles,
        ];

        // Fail-fast paling awal: berkas compose yang salah/tidak ada bersifat
        // DESTRUKTIF (service yang di-recreate bukan milik instalasi ini).
        $composeError = self::composeFilesError($composeFiles);
        if ($composeError !== null) {
            $context['error'] = $composeError;
            $context['ok'] = false;

            return $context;
        }

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
            $context['error'] = $attached === []
                ? 'Container dashboard tidak terhubung ke network apa pun — helper tidak bisa menghubungi endpoint /healthz.'
                : 'Tidak ada network helper yang layak di antara network container dashboard (' . implode(', ', $attached) . '): '
                    . 'helper butuh network NON-internal milik project compose dashboard (biasanya '
                    . ($project !== '' ? $project . '_default' : '<project>_default') . '). '
                    . 'Network `internal: true` tidak punya akses keluar (`git fetch` gagal: "Could not resolve host"), '
                    . 'sedangkan network milik project lain tidak memuat container dashboard setelah recreate (health-check gagal).';
            $context['ok'] = false;

            return $context;
        }

        return $context;
    }

    /**
     * Berkas compose yang dipakai container ini — sumber kebenaran untuk helper.
     *
     * Sumber utama: label `com.docker.compose.project.config_files` (daftar path
     * dipisah koma yang ditulis Docker Compose saat container dibuat). Label itu
     * WAJIB dihormati: agent dibuat dengan `-f docker-compose.agent.yml`
     * (`-p rames-agent`), sedangkan berkas default di direktori project adalah
     * `docker-compose.yml` milik DASHBOARD. Menjalankan compose tanpa `-f`
     * membuat helper "memperbarui" service `webman` project agent memakai
     * template dashboard: container `rames-agent` diganti `rames-webman`
     * (RAMES_MODE dashboard, port 8787) dan agent hilang ⇒ control plane
     * timeout (`cURL error 28`).
     *
     * Fallback hanya bila label tidak ada (container dibuat di luar compose atau
     * Compose versi lama): project `rames-agent` + `<root>/docker-compose.agent.yml`
     * ada ⇒ berkas itu; selain itu `<root>/docker-compose.yml` (perilaku lama).
     * Jangan menebak dari nama container.
     *
     * @param string $labelFiles nilai label `com.docker.compose.project.config_files`
     * @param string $workingDir nilai label `com.docker.compose.project.working_dir` (basis path relatif)
     * @param string $root       akar repo (fallback bila label kosong)
     * @param string $project    nama project compose (label `com.docker.compose.project`)
     * @return array<int,string> path absolut berkas compose (urutan dipertahankan)
     */
    public static function resolveComposeFiles(string $labelFiles, string $workingDir, string $root, string $project): array
    {
        $root = rtrim($root, '/');
        $base = $workingDir !== '' ? rtrim($workingDir, '/') : $root;

        $files = [];
        foreach (explode(',', $labelFiles) as $file) {
            $file = trim($file);
            if ($file === '') {
                continue;
            }
            if (!str_starts_with($file, '/') && $base !== '') {
                $file = $base . '/' . $file;
            }
            $files[] = $file;
        }
        if ($files !== []) {
            return $files;
        }

        $agent = ($root !== '' ? $root : '.') . '/' . self::AGENT_COMPOSE_FILE;
        if ($project === self::AGENT_COMPOSE_PROJECT && is_file($agent)) {
            return [$agent];
        }

        return [($root !== '' ? $root : '.') . '/docker-compose.yml'];
    }

    /**
     * Pesan galat bila berkas compose tidak bisa dipakai (tidak ada / kosong) —
     * `null` = aman. Diperiksa SEBELUM helper di-spawn: compose tanpa berkas
     * yang benar me-recreate service yang salah (lihat `resolveComposeFiles()`).
     *
     * @param array<int,string> $files
     */
    private static function composeFilesError(array $files): ?string
    {
        if ($files === []) {
            return 'Berkas compose tidak diketahui — update dibatalkan agar tidak me-recreate service yang salah.';
        }
        foreach ($files as $file) {
            if (!is_file($file)) {
                return 'Berkas compose ' . $file . ' tidak ditemukan — update dibatalkan agar tidak me-recreate service yang salah.';
            }
            if ((int) @filesize($file) <= 0) {
                return 'Berkas compose ' . $file . ' kosong — update dibatalkan agar tidak me-recreate service yang salah.';
            }
        }

        return null;
    }

    /**
     * Env lengkap untuk helper (`-e NAME=value`), fungsi murni:
     *
     *  - interpolasi compose efektif hasil {@see composeEnvFromInspect()}
     *    (`AGENT_PORT_PUBLISH`, `NGINX_*`, `DOCKER_*`, `RAMES_DB_DIR`, `DNS_*`);
     *  - `COMPOSE_FILE` = berkas compose container ini (pemisah `:`, format
     *    Docker Compose) — TANPA ini helper memakai `docker-compose.yml` default
     *    dan me-recreate service yang salah (bug production agent);
     *  - `COMPOSE_PROJECT_NAME` = project compose container ini, berpasangan
     *    dengan `COMPOSE_FILE` (project agent `rames-agent`, dashboard `rames`).
     *
     * @param array<string,mixed> $context hasil {@see selfContext()}
     * @return array<string,string>
     */
    public static function helperEnv(array $context): array
    {
        $env = (array) ($context['env'] ?? []);

        $files = array_values(array_filter(
            array_map('strval', (array) ($context['compose_files'] ?? [])),
            static fn (string $file): bool => $file !== ''
        ));
        if ($files !== []) {
            $env['COMPOSE_FILE'] = implode(':', $files);
        }

        $project = (string) ($context['project'] ?? '');
        if ($project !== '') {
            $env['COMPOSE_PROJECT_NAME'] = $project;
        }

        return $env;
    }

    /**
     * Nilai interpolasi compose yang EFEKTIF di container dashboard, dibaca dari
     * hasil `inspectContainer()`:
     *
     *  - `NGINX_*`, `DOCKER_*`, `RAMES_DB_DIR`, `DNS_*` dari `Config.Env` (env
     *    nyata yang dipakai container — sudah termasuk nilai dari `env_file` &
     *    hasil interpolasi compose saat container dibuat);
     *  - `AGENT_PORT_PUBLISH` dari `HostConfig.PortBindings`: port host yang
     *    dipublikasikan untuk port container agent `8443` (HostPort numerik
     *    pertama). Ini SATU-SATUNYA sumber port publish agent — nilainya tidak
     *    ada di `Config.Env` karena hanya dipakai saat compose membuat container.
     *
     * Hanya nama di {@see COMPOSE_ENV_ALLOWLIST} yang lolos; nama lain (termasuk
     * rahasia) diabaikan. Nilai kosong dilewati — lebih baik compose memakai
     * default-nya sendiri daripada helper mengirim `-e NAME=` (mengosongkan
     * variabel yang justru punya default berguna).
     *
     * @param array<string,mixed> $inspect hasil `DockerClient::inspectContainer()`
     * @return array<string,string>
     */
    private static function composeEnvFromInspect(array $inspect): array
    {
        $env = [];
        foreach ((array) ($inspect['Config']['Env'] ?? []) as $entry) {
            if (!is_string($entry) || !str_contains($entry, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $entry, 2);
            if (!in_array($name, self::COMPOSE_ENV_ALLOWLIST, true) || $value === '') {
                continue;
            }
            $env[$name] = $value;
        }

        $hostPort = self::publishedHostPort(
            is_array($inspect['HostConfig']['PortBindings'] ?? null) ? $inspect['HostConfig']['PortBindings'] : [],
            self::AGENT_CONTAINER_PORT
        );
        if ($hostPort !== '') {
            $env['AGENT_PORT_PUBLISH'] = $hostPort;
        }

        return $env;
    }

    /**
     * Port host numerik pertama yang dipublikasikan untuk satu port container
     * (`PortBindings` berbentuk `"8443/tcp" => [{"HostIp":…,"HostPort":"9443"}]`).
     *
     * Rentang (`"8000-8010"`) diabaikan: itu bukan nilai `AGENT_PORT_PUBLISH`
     * yang sah dan tidak boleh diteruskan apa adanya.
     *
     * @param array<string,mixed> $bindings
     */
    private static function publishedHostPort(array $bindings, string $containerPort): string
    {
        foreach ($bindings as $key => $entries) {
            if (explode('/', (string) $key, 2)[0] !== $containerPort) {
                continue;
            }
            foreach ((array) $entries as $entry) {
                $hostPort = is_array($entry) ? (string) ($entry['HostPort'] ?? '') : '';
                if ($hostPort !== '' && ctype_digit($hostPort)) {
                    return $hostPort;
                }
            }
        }

        return '';
    }

    /**
     * Pilih network untuk helper self-update (murni — mudah diuji).
     *
     * Helper WAJIB berada di network yang memenuhi DUA syarat:
     *  1. **non-internal** — network `internal: true` tidak punya route keluar,
     *     sehingga DNS eksternal pun gagal walau `--dns` diberikan
     *     (`fatal: … Could not resolve host: github.com`) → `git fetch gagal`.
     *  2. **tempat container dashboard hasil recreate berada** — helper mengecek
     *     kesehatan lewat NAMA container dashboard (`http://<name>:<port>/healthz`),
     *     dan nama itu hanya ter-resolve di network yang sama. Container dashboard
     *     dibuat ulang dari `docker-compose.yml`, jadi network default project
     *     compose (`<project>_default`) adalah pilihan paling pasti.
     *
     * Kenapa tidak boleh `array_key_first(NetworkSettings.Networks)`: dashboard
     * juga ter-attach ke network di luar compose — helper Adminer
     * (`rames-helpers`, `internal: true`, di-attach permanen oleh
     * `AdminerHelper`) dan network DB app saat dump/restore
     * (`DbConnectionResolver`). Urutan peta itu mengikuti urutan kunci JSON
     * (terurut abjad), dan `rames-helpers` < `rames_default`, sehingga network
     * **internal** justru terpilih lebih dulu.
     *
     * Network milik project lain TIDAK dipakai sebagai pelarian: ia memang punya
     * egress, tetapi container dashboard hasil recreate tidak ada di sana sehingga
     * `/healthz` lewat nama container gagal → update berakhir rollback. Bila tidak
     * ada network project compose yang non-internal, kembalikan `''` (preflight
     * menolak dengan pesan jelas) — kecuali status internal tidak bisa dipastikan
     * sama sekali (Engine tidak menjawab), yang memakai preferensi lama.
     *
     * @param array<int,string> $attached nama network yang di-attach dashboard
     * @param array<string,bool> $internal hasil inspect network; nama yang TIDAK
     *        ada di sini berarti statusnya belum bisa dipastikan (Engine tidak
     *        menjawab) dan dianggap layak agar update tidak terblokir.
     * @return string nama network terpilih, '' bila tidak ada yang layak
     */
    public static function chooseHelperNetwork(array $attached, array $internal, string $project): string
    {
        if ($attached === []) {
            return '';
        }

        $usable = static fn (string $name): bool => ($internal[$name] ?? false) === false;

        $default = $project !== '' ? $project . '_default' : '';
        if ($default !== '' && in_array($default, $attached, true) && $usable($default)) {
            return $default;
        }

        // Network lain milik project compose yang sama (compose dengan daftar
        // `networks:` eksplisit / tanpa network default).
        foreach ($attached as $name) {
            if ($project !== '' && str_starts_with($name, $project . '_') && $usable($name)) {
                return $name;
            }
        }

        // Status internal tidak bisa dipastikan sama sekali (Engine tidak menjawab)
        // → jangan menebak: pakai preferensi lama (default project, lalu yang
        // pertama) supaya update tidak terblokir oleh keterbatasan Engine.
        if ($internal === []) {
            return $default !== '' && in_array($default, $attached, true) ? $default : (string) reset($attached);
        }

        // Informasi ADA dan tidak menyisakan network project compose yang
        // non-internal → berhenti. Network milik project/app lain punya egress,
        // tetapi TIDAK memuat container dashboard setelah recreate (health-check
        // lewat nama container gagal) — memilihnya "supaya jalan" hanya
        // menghasilkan update yang berakhir rollback.
        return '';
    }

    /**
     * Tandai network yang `internal: true` lewat Engine API (best-effort).
     *
     * Gagal inspect satu network → network itu TIDAK ditandai: lebih baik tetap
     * mencoba update daripada memblokir karena Engine tidak menjawab.
     *
     * @param array<int,string> $names
     * @return array<string,bool>
     */
    private function internalNetworkFlags(array $names): array
    {
        $flags = [];
        foreach ($names as $name) {
            try {
                $info = $this->docker->inspectNetwork($name);
            } catch (\Throwable) {
                continue;
            }
            $flags[$name] = (bool) ($info['Internal'] ?? false);
        }

        return $flags;
    }

    /**
     * URL /healthz versi baru, diakses helper lewat nama container di network yang
     * sama (bukan lewat port publik host — tidak bergantung pada APP_PORT).
     *
     * Skema WAJIB mengikuti protokol listener, bukan ditebak dari nama container:
     * mode agent menyalakan TLS lewat `process.webman.transport => 'ssl'` (TLS
     * diterminasi Workerman), sehingga `/healthz` agent HANYA dilayani HTTPS.
     * Dengan `http://…` helper selalu menganggap versi baru tidak sehat →
     * update dianggap gagal/rollback walau agent sehat. Dashboard tidak menyetel
     * `transport` ⇒ tetap `http`.
     *
     * @param array<string,mixed> $context
     */
    public function healthUrl(array $context): string
    {
        return $this->healthScheme() . '://' . (string) $context['name'] . ':' . $this->listenPort() . '/healthz';
    }

    /** Skema HTTP untuk health check internal (satu sumber: config listener). */
    private function healthScheme(): string
    {
        return strtolower(trim((string) config('process.webman.transport', ''))) === 'ssl' ? 'https' : 'http';
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

    /**
     * Pemilik direktori repo **mentah** (`false` = stat gagal). Dibedakan dari
     * `repoUid()`/`repoGid()` yang memetakan kegagalan ke 0, karena pesan galat
     * harus bisa membedakan "path tidak terbaca dari container" dari "pemiliknya
     * memang root".
     *
     * @return array{uid:int|false,gid:int|false}
     */
    public function repoOwnerStat(): array
    {
        if ($this->ownerStat !== null) {
            $stat = ($this->ownerStat)($this->repo->root());

            return [
                'uid' => $stat[0] ?? false,
                'gid' => $stat[1] ?? false,
            ];
        }

        return [
            'uid' => @fileowner($this->repo->root()),
            'gid' => @filegroup($this->repo->root()),
        ];
    }

    public function repoUid(): int
    {
        $uid = $this->repoOwnerStat()['uid'];

        return $uid === false ? 0 : (int) $uid;
    }

    public function repoGid(): int
    {
        $gid = $this->repoOwnerStat()['gid'];

        return $gid === false ? 0 : (int) $gid;
    }

    /**
     * `deploy.update_allow_root` (UPDATE_ALLOW_ROOT) — opt-in eksplisit untuk
     * instalasi yang seluruhnya dijalankan sebagai root. Default false: repo
     * milik root tetap ditolak seperti sebelumnya.
     */
    private function allowRootEnabled(): bool
    {
        return (bool) config('deploy.update_allow_root', false);
    }

    /**
     * Pemilik `<root>/.git` untuk pemeriksaan konsistensi kepemilikan root
     * (UPDATE_ALLOW_ROOT). Melewati seam `$ownerStat` bila ada (uji tidak boleh
     * bergantung uid nyata mesin), selain itu `fileowner()` sungguhan.
     */
    private function gitDirOwnerUid(): int|false
    {
        $path = rtrim($this->repo->root(), '/') . '/.git';
        if ($this->ownerStat !== null) {
            $stat = ($this->ownerStat)($path);

            return $stat[0] ?? false;
        }

        return @fileowner($path);
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

    /**
     * Penyebab **git sendiri** menolak repo walau izin tulisnya cukup: `.git`
     * (gitdir) dimiliki uid lain daripada direktori repo, atau `.git` bukan
     * direktori. Murni `fileowner`/`filegroup`/`is_dir` — **tanpa** menjalankan
     * `git`, jadi murah dan bebas efek samping.
     *
     * Helper berjalan `--user <uid>:<gid>` = pemilik direktori repo
     * (SPECS §7.8 / ARCHITECTURE §5.14), sehingga git melihat gitdir milik user
     * lain dan berhenti dengan `fatal: detected dubious ownership in repository
     * at '<root>'` — pemeriksaan keamanan git yang **sengaja tidak** dilewati repo
     * ini (tidak ada `safe.directory` tambahan). Tanpa pemeriksaan ini update
     * sudah menyentuh repo lalu mati di tengah `git fetch` dengan pesan yang dulu
     * menyesatkan ("jaringan/DNS pada network …").
     *
     * Diuji empiris pada git image ini (2.49): `.git` milik uid lain tetap ditolak
     * walau gid-nya sama dengan gid helper dan bit setgid menyala (2775) — jadi
     * perbandingan **uid** sudah cukup. Bila suatu build git menerimanya, preflight
     * ini hanya fail-fast dengan jalan keluar `chown` (bukan penyebab kegagalan
     * baru); kalau toh lolos, klasifikasi galat di helper tetap mengenali
     * `dubious ownership`.
     *
     * @return string|null pesan galat siap pakai (path + owner yang ditemukan +
     *         owner repo + jalan keluar), atau null bila tidak ada masalah
     */
    public static function gitDirOwnershipError(string $root): ?string
    {
        $root = rtrim($root, '/');
        $gitDir = $root . '/.git';

        if (!file_exists($gitDir) && !is_link($gitDir)) {
            return null; // `.git` tidak ada → sudah ditangani pemeriksaan "bukan repo Git".
        }

        $gitUid = @fileowner($gitDir);
        $gitGid = @filegroup($gitDir);
        $repoUid = @fileowner($root);
        $repoGid = @filegroup($root);
        $repoOwner = self::ownerLabel($repoUid, $repoGid);
        $gitOwner = self::ownerLabel($gitUid, $gitGid);
        // Dipakai HANYA oleh cabang layout di bawah: `chown` baru berguna bila
        // pasangan kepemilikan benar-benar berbeda (uid DAN gid sama ⇒ `chown`
        // tidak mengubah apa pun, jadi jangan dicetak seolah wajib).
        $ownersDiffer = $gitUid !== $repoUid || $gitGid !== $repoGid;
        $fix = 'Perbaiki di host: sudo chown -R ' . $repoOwner . ' ' . $root;

        if (!is_dir($gitDir)) {
            $layout = '`.git` ada tetapi BUKAN direktori: ' . $gitDir . ' (ditemukan ' . $gitOwner
                . ', direktori repo ' . $repoOwner . ') — helper self-update membaca `.git/HEAD` langsung dan menjalankan '
                . '`git fetch` di dalam gitdir, sehingga layout berkas (worktree `gitdir:` / symlink rusak) tidak didukung. '
                . 'Perbaiki layoutnya: pakai layout repo normal (gitdir direktori di dalam repo — mis. `git clone`/`git init` '
                . 'ulang di tempat ini) atau jalankan update dari instalasi non-worktree.';

            return $ownersDiffer
                ? $layout . ' Kepemilikannya juga campur — samakan dulu: ' . $fix . '.'
                : $layout;
        }

        if ($gitUid === false || $repoUid === false || (int) $gitUid === (int) $repoUid) {
            return null;
        }

        return '`.git` dimiliki user lain: ' . $gitDir . ' dimiliki ' . $gitOwner . ', sedangkan direktori repo dimiliki '
            . $repoOwner . ' (uid:gid itulah yang dipakai helper) — helper berjalan sebagai pemilik direktori repo, dan git menolak '
            . 'repository milik user lain: "fatal: detected dubious ownership in repository at \'' . $root . '\'" '
            . '(repo ini sengaja tidak menambah `safe.directory`). ' . $fix . '.';
    }

    /**
     * Sebab **uid:gid pemilik direktori repo tidak bisa dipakai helper update**
     * (stat gagal atau pemiliknya root/uid 0) — `null` bila kepemilikannya wajar
     * (uid > 0, baik tertulis maupun tidak).
     *
     * Tiga sebab yang dulu memunculkan pesan yang sama di `start()`
     * ("Tidak bisa menentukan pemilik direktori repo (uid)") dibedakan di sini:
     *
     *  a. `fileowner()` gagal ⇒ direktori repo tidak terbaca dari dalam container
     *     (path tidak ada / bind-mount `${PWD}:${PWD}` hilang).
     *  b. pemilik = root (uid 0) dan dukungan root TIDAK di-opt-in ⇒ helper
     *     SENGAJA tidak dijalankan sebagai root (berkas hasil `git pull`/build akan
     *     jadi milik root dan repo milik user host rusak), jadi checkout hasil
     *     `sudo git clone`/`sudo ./agent/install.sh` tidak didukung — pesannya
     *     menyertakan perintah `chown` konkret + cara meng-opt-in root.
     *  c. pemilik = root dan `UPDATE_ALLOW_ROOT=true` ⇒ diloloskan HANYA bila
     *     kepemilikan konsisten (`.git` ada dan juga milik root). `.git` bukan
     *     milik root = kepemilikan campuran ⇒ ditolak dengan jalan keluar
     *     `sudo chown -R 0:0 <root>` (atau matikan opt-in).
     *
     * Aturan default **tidak dilonggarkan**: tanpa opt-in eksplisit, uid 0 tetap
     * ditolak. Statik-murni: tidak membaca `config()` — flag datang sebagai
     * parameter. `$gitUid` adalah seam uji (pemilik `.git`); `null` = `fileowner()`
     * sungguhan, sehingga uji tidak bergantung pada uid nyata mesin.
     *
     * @param int|false      $uid      hasil `fileowner()` direktori repo (false = stat gagal)
     * @param int|false      $gid      hasil `filegroup()` direktori repo
     * @param bool           $allowRoot UPDATE_ALLOW_ROOT (opt-in dukungan instalasi root)
     * @param int|false|null $gitUid   seam uji pemilik `<root>/.git` (null = stat sungguhan)
     * @return string|null pesan galat satu baris (path + sebab + jalan keluar)
     */
    public static function repoOwnerUnusableError(
        string $root,
        int|false $uid,
        int|false $gid,
        bool $allowRoot = false,
        int|false|null $gitUid = null,
    ): ?string {
        $root = rtrim($root, '/');
        if ($root === '') {
            $root = '/';
        }

        if ($uid === false) {
            return 'Direktori repo tidak bisa dibaca dari dalam container dashboard/agent: ' . $root
                . ' — `fileowner()` gagal, jadi pemilik direktori (uid:gid yang dipakai helper update) tidak bisa ditentukan '
                . 'dan update dibatalkan sebelum menyentuh repo. Pastikan path tersebut ada di host dan ter-bind ke container '
                . '(bind-mount `- ${PWD}:${PWD}` pada docker-compose.yml dashboard/agent), lalu deploy ulang dashboard.';
        }

        if ((int) $uid > 0) {
            return null;
        }

        if (!$allowRoot) {
            $fix = self::ownershipFixHint($root);

            return 'Direktori repo dimiliki root (uid:gid ' . self::ownerLabel($uid, $gid) . '): ' . $root
                . ' — self-update tidak dijalankan karena helper update berjalan `--user <uid>:<gid>` sebagai pemilik direktori repo; '
                . 'helper sebagai root akan meninggalkan berkas hasil `git pull`/build milik root di repo milik user host '
                . '(pull berikutnya dari SSH user itu gagal) — jadi checkout milik root memang tidak didukung. '
                . 'Perbaiki di host: sudo chown -R ' . $fix['owner'] . ' ' . $root
                . ($fix['concrete'] ? '' : ' (<uid>:<gid> = pemilik direktori induk repo, biasanya user yang menjalankan instalasi)')
                . ', lalu jalankan ulang update. Alternatif aman: ulangi instalasi sebagai user itu — sudo -u ' . $fix['user']
                . ' ./agent/install.sh … — jangan menjalankan helper sebagai root. '
                . 'Bila seluruh instalasi ini memang dijalankan sebagai root, set UPDATE_ALLOW_ROOT=true di environment '
                . '(dashboard: .env, agent: secrets/agent.env) lalu jalankan ulang update — dukungan root hanya aktif untuk '
                . 'repo yang kepemilikannya konsisten (direktori repo dan .git sama-sama root).';
        }

        // Opt-in root: loloskan hanya bila `.git` juga milik root (kepemilikan
        // konsisten). `.git` tidak ada = bukan repo Git ⇒ biarkan pemeriksaan
        // "bukan repo Git"/layout yang melaporkan sebabnya.
        $gitDir = $root . '/.git';
        if (!is_dir($gitDir) && !is_file($gitDir)) {
            return null;
        }

        $gitOwnerUid = $gitUid ?? @fileowner($gitDir);
        if ($gitOwnerUid === false || (int) $gitOwnerUid !== 0) {
            return 'Repo milik root tetapi ' . $gitDir . ' dimiliki uid '
                . ($gitOwnerUid === false ? '?' : (string) (int) $gitOwnerUid)
                . ' (kepemilikan campuran) — self-update dibatalkan; jalankan sudo chown -R 0:0 ' . $root
                . ' di host, atau matikan UPDATE_ALLOW_ROOT.';
        }

        return null;
    }

    /**
     * Jalan keluar `chown` untuk repo milik root: `uid:gid` pemilik **direktori
     * induk** repo (yang biasanya milik user host, bukan root). Placeholder
     * `<uid>:<gid>`/`<user>` dipakai bila induknya tidak terbaca atau juga milik
     * root — lebih baik daripada mencetak angka yang salah.
     *
     * @return array{owner:string,user:string,concrete:bool}
     */
    private static function ownershipFixHint(string $root): array
    {
        $parent = dirname($root === '/' ? '/' : $root);
        $uid = @fileowner($parent);
        $gid = @filegroup($parent);

        if ($uid === false || $gid === false || (int) $uid <= 0) {
            return ['owner' => '<uid>:<gid>', 'user' => '<user>', 'concrete' => false];
        }

        $user = '<user>';
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid((int) $uid);
            if (is_array($pw) && isset($pw['name']) && (string) $pw['name'] !== '') {
                $user = (string) $pw['name'];
            }
        }

        return ['owner' => (int) $uid . ':' . (int) $gid, 'user' => $user, 'concrete' => true];
    }

    /**
     * Label `uid:gid` untuk pesan galat (`?` bila stat gagal).
     */
    private static function ownerLabel(int|false $uid, int|false $gid): string
    {
        return ($uid === false ? '?' : (string) $uid) . ':' . ($gid === false ? '?' : (string) $gid);
    }

    /**
     * Path yang WAJIB bisa ditulis oleh helper update (uid/gid pemilik repo).
     *
     * `$root` = worktree (`git checkout`/`merge` menulis berkas di sini),
     * `$root/.git` = indeks/HEAD (`index.lock`, `refs`), `$root/.git/objects` =
     * tempat `git fetch` menulis object baru, `$root/.git/refs` = tempat ref
     * remote ditulis.
     *
     * @return array<int,string>
     */
    public static function helperWritablePaths(string $root): array
    {
        $root = rtrim($root, '/');

        return [
            $root,
            $root . '/.git',
            $root . '/.git/objects',
            $root . '/.git/refs',
        ];
    }

    /**
     * Path helper yang TIDAK bisa ditulis uid/gid pemilik repo, lengkap dengan mode
     * oktalnya supaya pesan galat bisa langsung ditindaklanjuti. Kosong = aman.
     *
     * @return array<int,string> mis. ["/srv/rames/.git (mode 0755)"]
     */
    public static function unwritableHelperPaths(string $root, int $uid, int $gid): array
    {
        $offenders = [];
        foreach (self::helperWritablePaths($root) as $path) {
            if (self::canUidWritePath($path, $uid, $gid)) {
                continue;
            }
            $perms = @fileperms($path);
            $offenders[] = $path . ($perms === false ? ' (tidak ada)' : ' (mode ' . sprintf('%04o', $perms & 0777) . ')');
        }

        return $offenders;
    }

    /**
     * Bolehkah uid/gid ini menulis path tersebut? Dihitung dari **stat**
     * (owner/group/other + bit mode) — bukan `is_writable()`.
     *
     * Kenapa bukan `is_writable()`: preflight berjalan di container dashboard yang
     * berjalan sebagai **root**, dan bagi root `is_writable()` hampir selalu `true`
     * (CAP_DAC_OVERRIDE) — justru itu yang membuat bug kepemilikan lolos preflight.
     * Helper self-update **tidak** berjalan sebagai root: ia dijalankan
     * `--user <uid>:<gid>` = pemilik direktori repo (`repoUid()`/`repoGid()`,
     * SPECS §7.8 / ARCHITECTURE §5.14). Jadi pertanyaan yang benar adalah persis
     * "bolehkah uid:gid ITU menulis path ini?", dan jawabannya hanya bisa dibaca
     * dari kepemilikan + mode berkas.
     *
     * uid 0 dikecualikan: root menembus bit mode (selama bukan mount read-only),
     * jadi repo milik root tetap boleh di-update oleh helper root.
     *
     * Untuk **direktori**, bit `w` saja tidak cukup: `git fetch` membuat
     * subdirektori (`objects/xx`) dan berkas baru di dalamnya, jadi butuh `w` dan
     * `x` (traverse). Untuk berkas biasa, `w` sudah cukup.
     *
     * Path di ATAS `$root` sengaja tidak diperiksa: bind mount `${PWD}:${PWD}`
     * membuat direktori induk di dalam helper berasal dari image, bukan dari host,
     * sehingga memeriksanya justru menyesatkan.
     *
     * **Batas (disengaja):** ini pemeriksaan DAC murni. ACL POSIX, SELinux/AppArmor,
     * mount read-only, dan `chattr +i` **tidak** terlihat di sini — ACL yang
     * memberi izin tanpa bit mode bisa ditolak lebih awal (fail-fast), sedangkan
     * mount read-only/immutable bisa lolos dan baru gagal di helper (pesannya
     * terkalsifikasi sebagai kepemilikan/izin, bukan jaringan).
     *
     * @param string $path berkas/direktori yang diperiksa
     */
    public static function canUidWritePath(string $path, int $uid, int $gid): bool
    {
        if ($uid === 0) {
            return true;
        }

        $stat = @stat($path);
        if ($stat === false) {
            // Tidak ada (atau symlink rusak) → tidak bisa ditulis; git pasti gagal.
            return false;
        }

        $mode = (int) $stat['mode'];
        if ($uid === (int) $stat['uid']) {
            $bits = ($mode >> 6) & 07;
        } elseif ($gid === (int) $stat['gid']) {
            $bits = ($mode >> 3) & 07;
        } else {
            $bits = $mode & 07;
        }

        if (($bits & 02) === 0) {
            return false;
        }

        // Direktori: menulis entri baru juga butuh bit `x`.
        if (($mode & 0040000) !== 0) {
            return ($bits & 01) !== 0;
        }

        return true;
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
