<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Pembatalan deploy/rebuild yang **benar-benar menghentikan build**.
 *
 * Worker deploy di-spawn detached oleh {@see DeployWorkerLauncher}
 * (`pcntl_fork` + `posix_setsid` + `pcntl_exec`), sehingga worker menjadi
 * **leader sesi & grup proses**. `docker compose`/BuildKit yang dijalankannya
 * mewarisi grup itu — jadi mengirim sinyal ke **`-pid`** (grup) menghentikan
 * worker **beserta** proses build-nya, bukan hanya meninggalkannya berjalan.
 *
 * Keamanan (jangan pernah membunuh proses yang salah):
 *  - PID hanya dipakai bila `/proc/<pid>/cmdline` benar-benar berisi
 *    `cli/deploy.php` dengan argumen app/target yang sama;
 *  - proses harus milik euid yang sama dengan proses HTTP ini;
 *  - PID ≤ 1 selalu ditolak;
 *  - bila `apps.worker_pid` tidak ada (worker lama / status macet), proses dicari
 *    lewat pemindaian `/proc` dengan aturan pencocokan yang sama.
 *
 * Setelah proses berhenti, status app dipulihkan (`running` bila container lama
 * masih hidup, selain itu `stopped`) sehingga UI tidak tertahan di `deploying`.
 * Untuk app di server agent, job agent dibatalkan lebih dulu lewat
 * {@see AgentDeployer::cancelJob()} — mematikan worker control plane saja tidak
 * menghentikan build yang berjalan di server lain.
 */
final class DeployCanceller
{
    /** Lama menunggu SIGTERM sebelum SIGKILL (detik, dalam iterasi 100 ms). */
    private const TERM_WAIT_TICKS = 30;

    /** Lama menunggu SIGKILL (detik, dalam iterasi 100 ms). */
    private const KILL_WAIT_TICKS = 20;

    /** @var callable(int,int):bool */
    private $kill;

    /** @var callable(int):bool */
    private $alive;

    /**
     * @param callable|null $kill  seam uji: pengganti `posix_kill($pid, $signal)`
     * @param callable|null $alive seam uji: pengganti cek hidup proses
     */
    public function __construct(
        private readonly ?AppStore $store = null,
        private readonly ?DockerClient $docker = null,
        private readonly string $procRoot = '/proc',
        ?callable $kill = null,
        ?callable $alive = null,
        private readonly ?DeployLog $log = null,
    ) {
        $this->kill = $kill ?? static fn (int $pid, int $signal): bool => function_exists('posix_kill') && @posix_kill($pid, $signal);
        $this->alive = $alive ?? fn (int $pid): bool => $this->processAlive($pid);
    }

    /**
     * Batalkan deploy sebuah app: job agent (bila ada) → worker lokal + grup
     * build → status app dipulihkan + jejak cancel di log deploy.
     *
     * @param array<string,mixed> $app
     * @param string              $actor label aktor untuk log (nama/username/id)
     * @return array{ok:bool,status:string,killed:int,pids:array<int,int>,running:array<int,int>,agent_cancelled:bool,warnings:array<int,string>}
     * @throws RuntimeException app tanpa id, atau proses tidak bisa disentuh sama sekali
     */
    public function cancelApp(array $app, ?AgentDeployer $agent = null, string $actor = ''): array
    {
        $appId = trim((string) ($app['id'] ?? ''));
        if ($appId === '') {
            throw new RuntimeException('App tanpa id — deploy tidak dapat dibatalkan.');
        }

        $warnings = [];
        $log = $this->log();
        $agentCancelled = false;

        if ($agent !== null) {
            $jobId = trim((string) ($app['agent_job_id'] ?? ''));
            if ($jobId === '') {
                $jobId = $log->lastAgentJobId($appId);
            }

            if ($jobId === '') {
                $warnings[] = 'Id job agent tidak ditemukan — build di server agent mungkin masih berjalan.';
            } else {
                try {
                    $agent->cancelJob($jobId);
                    $agentCancelled = true;
                } catch (\Throwable $e) {
                    $warnings[] = 'Job di server agent tidak dapat dibatalkan: ' . $e->getMessage();
                }
            }
        }

        $terminated = $this->terminate($appId, (int) ($app['worker_pid'] ?? 0));
        if ($terminated['running'] !== []) {
            $warnings[] = 'Sebagian proses build masih hidup (PID ' . implode(', ', $terminated['running'])
                . ') — coba batalkan lagi atau hentikan dari terminal.';
        }

        $status = $this->statusAfterCancel($app);

        $log->append($appId, '[' . date('c') . '] [cancelled] Deploy dibatalkan'
            . ($actor !== '' ? ' oleh ' . $actor : '') . '.');

        $this->store()->update($appId, static function (array &$s) use ($status): void {
            $s['status'] = $status;
            $s['stage'] = null;
            $s['message'] = 'Deploy dibatalkan.';
            $s['error'] = null;
            unset($s['worker_pid'], $s['worker_mode'], $s['worker_started_at'], $s['agent_job_id']);
        });

        return [
            'ok' => true,
            'status' => $status,
            'killed' => $terminated['killed'],
            'pids' => $terminated['pids'],
            'running' => $terminated['running'],
            'agent_cancelled' => $agentCancelled,
            'warnings' => $warnings,
        ];
    }

    /**
     * Hentikan worker deploy milik `$target` beserta grup prosesnya.
     *
     * Mengembalikan daftar PID yang ditemukan/dihentikan; `running` berisi PID
     * yang masih hidup sesudah SIGTERM+SIGKILL (biasanya karena izin).
     *
     * @return array{killed:int,pids:array<int,int>,running:array<int,int>}
     */
    public function terminate(string $target, int $pid = 0): array
    {
        $target = trim($target);
        if ($target === '') {
            return ['killed' => 0, 'pids' => [], 'running' => []];
        }

        $pids = [];
        if ($pid > 1 && $this->isWorker($pid, $target)) {
            $pids[] = $pid;
        }
        foreach ($this->findWorkers($target) as $found) {
            if (!in_array($found, $pids, true)) {
                $pids[] = $found;
            }
        }

        $killed = 0;
        foreach ($pids as $found) {
            if ($this->stop($found)) {
                $killed++;
            }
        }

        $running = array_values(array_filter($pids, fn (int $found): bool => ($this->alive)($found)));

        return ['killed' => $killed, 'pids' => $pids, 'running' => $running];
    }

    /**
     * Status app sesudah cancel: container lama yang masih hidup tetap `running`
     * (build yang dibatalkan tidak menghapus container), selain itu `stopped`.
     *
     * @param array<string,mixed> $app
     */
    public function statusAfterCancel(array $app): string
    {
        $name = trim((string) ($app['name'] ?? ''));
        if ($name !== '') {
            try {
                $live = $this->docker()->listContainersForProject($name);
                if ($live !== []) {
                    foreach ($live as $container) {
                        if (strtolower((string) ($container['State'] ?? '')) === 'running') {
                            return 'running';
                        }
                    }

                    return 'stopped';
                }
            } catch (\Throwable) {
                // Engine tidak terjangkau: pakai salinan terakhir (best-effort).
            }
        }

        return !empty($app['containers']) ? 'running' : 'stopped';
    }

    /**
     * Apakah PID adalah worker deploy untuk target ini (aturan ketat — mencegah
     * PID reuse membunuh proses lain).
     */
    public function isWorker(int $pid, string $target): bool
    {
        if ($pid <= 1 || $pid === getmypid()) {
            return false;
        }

        $args = $this->cmdline($pid);
        if ($args === []) {
            return false;
        }

        foreach ($args as $index => $arg) {
            if (preg_match('#(^|/)cli/deploy\.php$#', $arg) !== 1) {
                continue;
            }

            return ($args[$index + 1] ?? '') === $target;
        }

        return false;
    }

    /**
     * Cari worker untuk target lewat `/proc` (jalur cadangan bila `worker_pid`
     * tidak tercatat).
     *
     * @return array<int,int>
     */
    private function findWorkers(string $target): array
    {
        $found = [];
        foreach (glob(rtrim($this->procRoot, '/') . '/[0-9]*/cmdline') ?: [] as $path) {
            $pid = (int) basename(dirname($path));
            if ($this->isWorker($pid, $target)) {
                $found[] = $pid;
            }
        }

        return $found;
    }

    /**
     * Argumen proses dari `/proc/<pid>/cmdline` (kosong bila tidak terbaca,
     * bukan milik euid ini, atau bukan proses biasa).
     *
     * @return array<int,string>
     */
    private function cmdline(int $pid): array
    {
        $dir = rtrim($this->procRoot, '/') . '/' . $pid;
        if (!is_dir($dir)) {
            return [];
        }

        $owner = @fileowner($dir);
        if ($owner !== false && function_exists('posix_geteuid') && $owner !== posix_geteuid()) {
            return [];
        }

        $raw = @file_get_contents($dir . '/cmdline');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $args = explode("\0", rtrim($raw, "\0"));

        return array_values(array_filter($args, static fn (string $arg): bool => $arg !== ''));
    }

    /**
     * Kirim SIGTERM (ke grup bila worker adalah pemimpin grup), tunggu, lalu
     * SIGKILL. Mengembalikan `true` bila proses sudah tidak hidup.
     */
    private function stop(int $pid): bool
    {
        if (!($this->alive)($pid)) {
            return true;
        }

        $pgid = function_exists('posix_getpgid') ? @posix_getpgid($pid) : false;
        $target = ($pgid !== false && $pgid === $pid) ? -$pid : $pid;

        ($this->kill)($target, SIGTERM);
        for ($i = 0; $i < self::TERM_WAIT_TICKS; $i++) {
            if (!($this->alive)($pid)) {
                return true;
            }
            usleep(100000);
        }

        ($this->kill)($target, SIGKILL);
        for ($i = 0; $i < self::KILL_WAIT_TICKS; $i++) {
            if (!($this->alive)($pid)) {
                return true;
            }
            usleep(100000);
        }

        return !($this->alive)($pid);
    }

    /**
     * Proses hidup? Zombie dihitung **mati** (sudah keluar, hanya menunggu
     * di-reap induk — pada worker deploy induk meng-auto-reap).
     */
    private function processAlive(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }

        $stat = @file_get_contents(rtrim($this->procRoot, '/') . '/' . $pid . '/stat');
        if (is_string($stat) && $stat !== '') {
            $end = strrpos($stat, ')');
            $state = $end === false ? '' : substr(trim(substr($stat, $end + 1)), 0, 1);
            if ($state === 'Z') {
                return false;
            }
        }

        if (($this->kill)($pid, 0)) {
            return true;
        }

        // `kill(pid, 0)` gagal tetapi proses masih terlihat di /proc (EPERM):
        // proses milik user lain — tetap dianggap hidup (jangan diklaim dibunuh).
        return is_string($stat) && $stat !== '';
    }

    private function store(): AppStore
    {
        return $this->store ?? new AppStore();
    }

    private function docker(): DockerClient
    {
        return $this->docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 5);
    }

    private function log(): DeployLog
    {
        return $this->log ?? new DeployLog();
    }
}
