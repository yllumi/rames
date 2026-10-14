<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Support\DetachedWorker;
use app\library\Support\SigchldGuard;

/**
 * Peluncur worker deploy **detached** — satu implementasi bersama untuk
 * `AppController::spawnWorker()` (dashboard) dan `AgentJobController` (server
 * agent, misi F1 multi-server).
 *
 * Perintah yang dijalankan (posisi argumen **wajib** stabil — dibaca
 * `cli/deploy.php` sebagai `argv[1..4]`):
 *
 * ```
 * php cli/deploy.php <appId> <mode> [ref] [actorId]
 * ```
 *
 * Untuk op tingkat server ({@see self::SERVER_OPS}) slot `appId` berisi *target*
 * (slug template / `agent`) — bentuk argv-nya identik; hanya pemaknaan slot itu
 * yang berbeda (didokumentasikan juga di `cli/deploy.php`).
 *
 * `ref` selalu menempati slot ke-4 (string kosong bila bukan rollback) supaya
 * `actorId` (argv[4]) tidak bergeser; worker memakai aktor untuk pengecualian
 * kredit yang sama dengan controller.
 *
 * Alasan `pcntl_fork` + `pcntl_exec` (bukan `proc_open`/`ProcessRunner`):
 * `proc_open` + `proc_close` **BLOCKING** sampai worker selesai, sehingga
 * request HTTP yang memanggilnya menggantung selama build (menit-an) dan
 * timeout/refresh browser tampak "menggagalkan" deploy. Larangan repo #12 juga
 * melarang mengganti mekanisme spawn detached ini. Karena itu
 * {@see \app\library\Support\ProcessRunner} sengaja **tidak** dipakai di sini;
 * bentuk array + `pcntl_exec` sudah bebas command injection (langsung `execve`,
 * tanpa shell — larangan #8/#11 tetap dipenuhi).
 *
 * SIGCHLD selalu dijaga lewat {@see SigchldGuard}: `SIG_IGN` di HTTP worker
 * (kernel auto-reap anak ⇒ tanpa zombie) dan `SIG_DFL` sebelum `pcntl_exec`
 * (worker harus bisa membaca exit code proses anaknya sendiri — lihat
 * `SigchldGuard`). Mekanisme spawn sendiri ada di
 * {@see \app\library\Support\DetachedWorker} (pola yang sama dengan kode lama).
 *
 * Stateless (hanya dependensi yang disuntikkan) ⇒ aman di worker Webman
 * persistent.
 */
final class DeployWorkerLauncher
{
    /**
     * Mode yang **benar-benar** didukung `cli/deploy.php` — satu-satunya sumber
     * daftar ini (jangan menyalinnya ke controller: `AgentJobController::OPS`
     * dan `AgentController::version()['capabilities']` merujuk konstanta ini).
     * Diverifikasi terhadap berkas aslinya oleh `DeployWorkerLauncherTest`.
     *
     * F1 multi-server: `stop|start|teardown|apply_env` ditambahkan bersama mode
     * barunya di `cli/deploy.php` (`DeployerInterface::stop()/start()/teardown()/
     * applyEnv()`) sehingga op job agent & capabilities ikut bertambah tanpa
     * daftar kedua.
     *
     * F2 multi-server (Nginx/SSL): `nginx_write|nginx_remove|nginx_reload|
     * ssl_issue|ssl_revoke` — worker **di server agent** memakai library Nginx &
     * SSL milik server itu (`DeployerFactory::createForApp()` ⇒ `LocalDeployer`
     * + `NginxReloader`/`SslIssuer` di sana). Preflight `ensureWritable()`
     * memakai endpoint app-less `POST /nginx/check` (bukan job) — lihat
     * `AgentDeployer::ensureWritable()`.
     *
     * F4 multi-server (data): `backup_run|restore|db_dump` — worker **di server
     * agent** menjalankan backup/restore/dump volume lewat `BackupJobRunner`
     * (restic + helper container di daemon server itu, kredensial milik server
     * itu). Control plane tidak pernah menjalankan restic pada volume agent.
     *
     * F4 lanjutan (op **tingkat server**, {@see self::SERVER_OPS}):
     * `template_pull` — worker di agent menjalankan mekanisme tarik image
     * template yang sama dengan jalur lokal (`cli/pull.php`: `TemplateCatalog` +
     * `docker pull` per image via `ProcessRunner`), sehingga image masuk ke
     * daemon **server itu**; `self_update` — worker di agent menjalankan
     * self-update agen lewat helper container di luar compose project
     * (`UpdateService`, pola yang sama dengan self-update dashboard), tidak
     * pernah dari control plane; `restore_archived` — worker di agent memulihkan
     * volume **arsip** ke volume target baru di server itu
     * (`VolumeArchiveRestore`, `VolumeStateGuard` tetap ditegakkan). Ketiga op ini
     * **tidak terikat app** sehingga `argv[1]` berisi *target* (slug template /
     * `agent` / nama volume), bukan id app — lihat {@see self::SERVER_OPS}.
     */
    public const MODES = [
        'deploy',
        'rebuild',
        'rollback',
        'apply',
        'stop',
        'start',
        'teardown',
        'apply_env',
        'nginx_write',
        'nginx_remove',
        'nginx_reload',
        'ssl_issue',
        'ssl_revoke',
        'backup_run',
        'restore',
        'db_dump',
        'restore_archived',
        'template_pull',
        'self_update',
    ];

    /**
     * Mode yang **tidak terikat satu app** (op tingkat server) — pemanggilnya
     * (`AgentJobController::createServerJob()`) mengisi `argv[1]`/rekaman job
     * dengan *target*, bukan id app:
     *
     *  - `template_pull`: target = slug template (checkout `templates/<slug>`
     *    server itu);
     *  - `self_update`  : target = `agent` (tidak ada arti lain; nilai tetap
     *    supaya bentuk argv/rekaman job seragam);
     *  - `restore_archived`: target = **nama volume arsip** (tanpa app; payload
     *    membawa `snapshot` + `target_name`). Volume arsip memang sudah tidak
     *    punya app, jadi op ini tidak boleh menuntut proyeksi app.
     *
     * Karena semuanya tetap mode `cli/deploy.php` yang nyata, daftar ini bagian
     * dari {@see self::MODES} ⇒ otomatis ikut `AgentJobController::OPS` dan
     * `capabilities` (`GET /version`) — tidak ada op "senyap".
     *
     * @var array<int,string>
     */
    public const SERVER_OPS = ['template_pull', 'self_update', 'restore_archived'];

    /** Op tingkat server untuk **self-update agen** (nama op job di agent). */
    public const AGENT_UPDATE_OP = 'self_update';

    /**
     * Target tetap op {@see self::AGENT_UPDATE_OP} — nilai semantik (bukan nama
     * app): tidak ada arti lain, dipakai supaya bentuk argv/rekaman job seragam
     * dengan op tingkat server lain.
     */
    public const AGENT_UPDATE_TARGET = 'agent';

    /** Skrip worker (relatif `base_path()`). */
    public const WORKER_SCRIPT = 'cli/deploy.php';

    private string $workerScript;

    private string $logDir;

    /** @var callable(array<int,string>, ?int&, array<string,string>):bool */
    private $spawn;

    /**
     * @param callable|null $spawn        seam uji: pengganti
     *                                    `DetachedWorker::spawn()` (dipanggil
     *                                    `($spawn)($argv, $pid, $env)`; mengembalikan
     *                                    bool). Normalnya tidak diisi.
     * @param string        $workerScript seam uji: path worker (default
     *                                    `cli/deploy.php`).
     * @param string        $logDir       seam uji: direktori log deploy (default
     *                                    `runtime/logs/deploy`). Dipakai juga
     *                                    oleh agent untuk membaca log job
     *                                    (`GET /jobs/{id}/log`) sehingga kedua
     *                                    sisi memakai satu sumber path.
     */
    public function __construct(?callable $spawn = null, string $workerScript = '', string $logDir = '')
    {
        $this->spawn = $spawn ?? static fn (array $argv, ?int &$pid = null, array $env = []): bool => DetachedWorker::spawn($argv, $pid, $env);
        $this->workerScript = $workerScript !== '' ? $workerScript : base_path(self::WORKER_SCRIPT);
        $this->logDir = rtrim($logDir !== '' ? $logDir : runtime_path('logs/deploy'), '/');
    }

    /**
     * Direktori log deploy (worker menulis `{appId}.log` di sini sendiri).
     *
     * Dipakai agent sebagai sumber `GET /jobs/{id}/log`: log job **adalah** log
     * deploy app-nya (plan §11 catatan 6 — UI/control plane tidak berubah
     * bentuk), jadi tidak ada salinan log kedua.
     */
    public function logDir(): string
    {
        return $this->logDir;
    }

    /** Path log deploy satu app. */
    public function logPath(string $appId): string
    {
        return $this->logDir . '/' . trim($appId) . '.log';
    }

    /**
     * Jalankan worker deploy untuk app tersebut — request pemanggil **tidak**
     * menunggu pekerjaannya.
     *
     * Mode di luar {@see self::MODES} (atau appId kosong) **tidak** diluncurkan:
     * worker yang pasti ditolak `cli/deploy.php` hanya akan menghasilkan proses
     * mubazir + status app `error`.
     *
     * @param string      $appId   id app, atau **target** untuk op tingkat server
     *                             ({@see self::SERVER_OPS}: slug template / `agent`)
     * @param string      $mode    mode `cli/deploy.php` — lihat {@see self::MODES} (satu sumber)
     * @param string|null $ref     ref rollback (mode lain: null/'' ⇒ slot dibiarkan kosong)
     * @param string      $actorId id user penekan tombol (`argv[4]`), '' bila tak ada konteks login
     * @param string      $jobId   id job agent (`RAMES_JOB_ID` di worker), '' bila
     *                             spawn bukan bagian dari job agent (jalur control
     *                             plane) — worker memakainya untuk menandai status
     *                             job di `AgentJobStore` (multi-server F1)
     * @return bool true bila proses anak berhasil di-fork (bukan jaminan exec sukses)
     */
    public function launch(string $appId, string $mode, ?string $ref = null, string $actorId = '', string $jobId = ''): bool
    {
        $appId = trim($appId);
        $mode = trim($mode);
        if ($appId === '' || !in_array($mode, self::MODES, true)) {
            return false;
        }

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }

        $argv = [PHP_BINARY, $this->workerScript, $appId, $mode];
        if ($ref !== null && $ref !== '') {
            $argv[] = $ref;
        }

        // Slot argv[3] (`ref`) selalu diisi agar posisi aktor stabil — lihat
        // catatan kelas.
        $actorId = trim($actorId);
        if ($actorId !== '') {
            if (count($argv) < 5) {
                $argv[] = '';
            }
            $argv[] = $actorId;
        }

        // Env job hanya diisi bila ada (perilaku lama tanpa env tidak berubah).
        $jobId = trim($jobId);
        $env = $jobId !== '' ? ['RAMES_JOB_ID' => $jobId] : [];

        $pid = 0;

        // SIGCHLD=SIG_DFL selama fork supaya anak mewarisi disposisi normal;
        // disposisi sebelumnya (SIG_IGN di HTTP worker) dipulihkan sesudahnya
        // sekaligus membuang anak yang sempat berakhir (tanpa zombie).
        return SigchldGuard::withDefault(function () use ($argv, $env, &$pid): bool {
            return (bool) ($this->spawn)($argv, $pid, $env);
        });
    }
}
