<?php
declare(strict_types=1);

namespace app\controller\backup;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Backup\BackupAccess;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use support\Request;

/**
 * Basis bersama seluruh controller fitur backup (`app\controller\backup\*`).
 *
 * Kelas ini **hanya** memuat helper yang dipakai lebih dari satu controller:
 * resolusi & otorisasi target volume (lokal + server agent), registry server,
 * service backup, 404 satu bentuk, spawn worker detached, dan nama berkas
 * unduhan yang aman. Helper yang hanya dipakai satu controller tinggal di
 * controller itu.
 *
 * Pola lama tidak berubah: controller mediator, tanpa logika bisnis, dan tanpa
 * state properti lintas-request (worker Webman persistent).
 *
 * Invarian yang dijaga: error akses → **404** (via `AppAccessDenied`), bukan
 * 403; "bukan milik user" dan "id/volume tidak dikenal" memakai bentuk respons
 * yang sama lewat `notFound()` sehingga tidak dapat dibedakan pemanggil.
 */
abstract class BackupControllerBase
{
    /**
     * @param array<int,array{name:string}> $targets
     * @return array|null
     */
    /**
     * Cari target volume berdasarkan nama (dan opsional server).
     *
     * Nama volume bisa sama di dua server (keunikan bersifat per server, plan
     * §4.2), jadi:
     *  - `$serverId` diisi ⇒ hanya target server itu;
     *  - `$serverId` kosong dan hanya ada satu kandidat ⇒ kandidat itu (perilaku
     *    lama untuk instalasi satu server);
     *  - lebih dari satu kandidat di server berbeda ⇒ **RuntimeException** dengan
     *    pesan yang meminta pemanggil menyebut server (fail-fast; tidak menebak).
     *
     * @param array<int,array<string,mixed>> $targets
     * @throws \RuntimeException volume ada di beberapa server tanpa `server_id`
     */
    protected function findTarget(array $targets, string $volume, string $serverId = ''): ?array
    {
        $serverId = trim($serverId);

        $matches = [];
        foreach ($targets as $target) {
            if ((string) ($target['name'] ?? '') !== $volume) {
                continue;
            }
            if ($serverId !== '' && self::serverIdOf($target) !== $serverId) {
                continue;
            }
            $matches[] = $target;
        }

        if ($matches === []) {
            return null;
        }

        $serverIds = [];
        foreach ($matches as $match) {
            $serverIds[self::serverIdOf($match)] = true;
        }
        if (count($serverIds) > 1) {
            throw new \RuntimeException(
                "Volume \"{$volume}\" ada di lebih dari satu server (" . implode(', ', array_keys($serverIds)) . '). '
                . 'Sebutkan `server_id` untuk memilih server yang benar — operasi tidak dijalankan.'
            );
        }

        return $matches[0];
    }

    /**
     * Deployer agent untuk target volume (multi-server F4).
     *
     * `null`/server lokal tidak pernah sampai di sini; server agent yang tidak
     * dapat dipakai ⇒ exception (bukan fallback lokal).
     *
     * @param array<string,mixed> $target
     * @throws \RuntimeException
     */
    protected function agentDeployerForTarget(array $target): AgentDeployer
    {
        $serverId = self::serverIdOf($target);
        $agent = $this->agentDeployerForServer($this->serverResolver()->resolve($serverId));
        if ($agent === null) {
            throw new \RuntimeException(
                "Server \"{$serverId}\" bukan server agent — operasi volume server itu dibatalkan (tidak dijalankan lokal)."
            );
        }

        return $agent;
    }

    /**
     * @param array<int,array> $apps
     * @return array|null
     */
    protected function findApp(array $apps, string $appId): ?array
    {
        if ($appId === '') {
            return null;
        }
        foreach ($apps as $app) {
            if ((string) ($app['id'] ?? '') === $appId) {
                return $app;
            }
        }
        return null;
    }

    protected function validVolume(string $volume): bool
    {
        try {
            VolumeStateGuard::assertVolumeName($volume);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Apakah user login admin global (seam uji; default helper `is_admin()`).
     *
     * Ditulis agar mediator dapat diuji tanpa memuat `app/functions.php`
     * (pola `CreditController`) — perilaku produksi identik dengan `is_admin()`.
     */
    protected function isAdmin(): bool
    {
        return is_admin();
    }

    /**
     * User login saat ini (seam uji; default helper `current_user()`).
     *
     * Session Webman butuh konteks HTTP, jadi tes menyuntik user lewat seam ini
     * (pola `CreditController`).
     */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /**
     * Target backup (volume → app) dari Docker Engine — **satu-satunya jalur
     * endpoint ini yang menyentuh Engine** (PLAN_VOLUME_BACKUP.md §5.3).
     *
     * Method `protected` (bukan `private`) supaya tes dapat meng-override-nya
     * dengan daftar volume tiruan: menguji matriks hak & gerbang kredit tidak
     * boleh menuntut Docker Engine nyata di lingkungan tes.
     *
     * Jalur agent-nya **bercakupan user** (`agentVolumeTargets()`): server di luar
     * jangkauan pemanggil tidak pernah dihubungi, sesuai invarian multi-server
     * (§9.3) — jangan kembali memanggil seluruh registry di sini.
     *
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    protected function volumeTargets(): array
    {
        $volumes = (new DockerClient())->listVolumes(['label' => [VolumeTargetMap::LABEL_PROJECT]]);

        $apps = (new AppStore())->all();
        $local = VolumeTargetMap::build($volumes, $apps);
        foreach ($local as $index => $target) {
            $local[$index]['server_id'] = ServerStore::LOCAL_ID;
        }

        return array_merge($local, $this->agentVolumeTargets($apps));
    }

    /**
     * Target volume yang hidup di **server agent** (multi-server F4) — dibaca dari
     * server itu (`AgentDeployer::backupVolumes()`), bukan dari Engine host ini.
     *
     * Nama app diambil dari **control plane** (sumber metadata, plan §4.3): baris
     * agent hanya memberi `app_id`; app yang tidak ada di control plane ditandai
     * `orphaned` (perlakukan seperti volume yatim — admin-only).
     *
     * Best-effort: server agent yang tidak dapat dihubungi **dilewati** (halaman
     * `/backups` tetap jalan) — ia tidak pernah membuat control plane menjalankan
     * operasi lokal sebagai pengganti.
     *
     * **Cakupan user ditegakkan sebelum jaringan** (keputusan §9.3): hanya server
     * di `visibleAgentServers()` yang dihubungi, dan untuk non-admin hanya baris
     * volume milik app yang boleh ia lihat yang menjadi target. Member tanpa app
     * karena itu **tidak menghubungi server mana pun** (sebelumnya seluruh server
     * agent dipanggil lalu baru disaring) — sama dengan
     * `VolumeController::collectAgentVolumes()`.
     *
     * `protected` supaya tes dapat meng-override tanpa jaringan.
     *
     * @param array<int,array> $apps daftar app control plane
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool,server_id:string}>
     */
    protected function agentVolumeTargets(array $apps = []): array
    {
        $byId = [];
        foreach ($apps as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                $byId[(string) $app['id']] = $app;
            }
        }

        // Cakupan app user **sebelum** menyentuh jaringan: non-admin hanya boleh
        // menyentuh server tempat ia punya app terlihat, dan hanya baris app itu
        // yang menjadi target. Admin: seluruh app (server yang app-nya sudah
        // dihapus justru satu-satunya tempat volume yatim berada).
        $isAdmin = $this->isAdmin();
        $accessibleAppIds = null;
        if (!$isAdmin) {
            $accessibleAppIds = [];
            foreach (AppAccess::visible($apps, $this->currentUser()) as $app) {
                if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                    $accessibleAppIds[(string) $app['id']] = true;
                }
            }
        }

        $out = [];
        foreach ($this->visibleAgentServers($apps) as $server) {
            $serverId = (string) ($server['id'] ?? '');
            $agent = $this->agentDeployerForServer($server);
            if ($agent === null) {
                continue;
            }

            try {
                $listing = $agent->backupVolumes();
            } catch (\Throwable $e) {
                continue; // server agent tak terjangkau: jangan jatuhkan halaman
            }

            foreach ($listing['volumes'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $appId = (string) ($row['app_id'] ?? '');
                $app = $appId !== '' ? ($byId[$appId] ?? null) : null;

                // Non-admin: baris app lain/yatim tidak pernah menjadi target
                // (fail-closed) — akses tidak sah tetap 404, sama seperti volume
                // yang tidak ada, dan nama volume app lain tidak ikut dikirim.
                if ($accessibleAppIds !== null && ($app === null || !isset($accessibleAppIds[$appId]))) {
                    continue;
                }

                $out[] = [
                    'name' => $name,
                    'project' => (string) ($row['project'] ?? ($app['name'] ?? '')),
                    'app_id' => $app !== null ? $appId : null,
                    'app_name' => $app !== null ? (string) ($app['name'] ?? '') : null,
                    'orphaned' => $app === null,
                    'server_id' => $serverId,
                ];
            }
        }

        return $out;
    }

    /**
     * Dokumen server **agent** yang boleh **dilihat** pemanggil — satu pintu
     * `ServerAccess::visibleServers()` (aturan role tidak disalin di sini),
     * lalu disaring ke baris yang benar-benar server agent aktif.
     *
     * Invarian yang dijaga: server di luar jangkauan user **tidak pernah
     * dihubungi** (bukan sekadar disembunyikan setelah dipanggil), dan user
     * tanpa app terlihat tidak menghubungi server mana pun. Perilaku ini sama
     * dengan `VolumeController::visibleAgentServers()` — satu kebijakan untuk
     * semua permukaan volume/backup.
     *
     * Best-effort: registry server tak terbaca ⇒ daftar kosong.
     *
     * @param array<int,array> $apps daftar app control plane (`AppStore::all()`)
     * @return array<int,array<string,mixed>>
     */
    protected function visibleAgentServers(array $apps = []): array
    {
        try {
            $servers = $this->serverResolver()->all();
        } catch (\Throwable $e) {
            return [];
        }

        $visible = ServerAccess::visibleServers($servers, $this->currentUser(), $apps);

        $out = [];
        foreach ($visible as $server) {
            if (!is_array($server) || ServerResolver::isLocal($server) || !ServerResolver::isEnabled($server)) {
                continue;
            }
            if ((string) ($server['id'] ?? '') === '') {
                continue;
            }
            $out[] = $server;
        }

        return $out;
    }

    /**
     * Resolver registry server (seam uji) — produksi: store bawaan
     * (`config('deploy.sqlite_file')`).
     */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Deployer agent untuk satu dokumen server (seam uji) — `null` = server lokal.
     *
     * @param array<string,mixed> $server
     */
    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return AgentDeployer::forServer($server);
    }

    /**
     * Fail-fast: op yang belum didukung server agent ⇒ pesan jelas (plan §5).
     *
     * Bila server agent belum pernah melaporkan `capabilities` (daftar kosong),
     * op **tidak** ditolak di sini: worker di server itu sendiri yang memutuskan
     * (`AgentJobController::OPS`), sehingga control plane tidak menebak-nebak
     * kemampuan agent. Yang dipastikan: **tidak** ada fallback ke eksekusi lokal.
     *
     * @throws \RuntimeException
     */
    protected function assertAgentSupports(string $serverId, string $op): void
    {
        if (BackupDispatcher::isLocalServer($serverId)) {
            return;
        }

        $server = $this->serverResolver()->resolve($serverId);
        $capabilities = $server['capabilities'] ?? [];
        if (!is_array($capabilities) || $capabilities === []) {
            return; // kemampuan belum diketahui — keputusan di server agent
        }

        if (!in_array($op, array_map('strval', $capabilities), true)) {
            throw new \RuntimeException(
                "Server \"{$serverId}\" belum mendukung op `{$op}` — backup/restore volume di server itu belum tersedia. "
                . 'Operasi dibatalkan (tidak dijalankan di server lokal).'
            );
        }
    }

    /**
     * Id server sebuah baris volume (`''`/`local` = server ini).
     *
     * @param array<string,mixed> $row
     */
    protected static function serverIdOf(array $row): string
    {
        $serverId = isset($row['server_id']) && is_scalar($row['server_id']) ? trim((string) $row['server_id']) : '';

        return $serverId === '' ? ServerStore::LOCAL_ID : $serverId;
    }

    /**
     * Service backup untuk mediator ini (seam uji).
     *
     * Method `protected` (bukan `private`) supaya tes dapat meng-inject
     * `VolumeBackupService` dengan `DockerClient` palsu — pola sama dengan
     * `volumeTargets()`: menguji overlay `container_state` live di `BackupStatusController::status()`
     * tidak boleh menuntut Docker Engine nyata di lingkungan tes.
     */
    protected function backupService(): VolumeBackupService
    {
        return new VolumeBackupService();
    }

    /**
     * 404 satu bentuk untuk seluruh endpoint `/backups/*`.
     *
     * Bentuknya **delegasi ke `AppAccessDenied::render()`**: JSON untuk endpoint
     * `/api/*` & request AJAX, halaman 404 untuk permintaan halaman biasa.
     *
     * Kenapa: "volume ada tetapi bukan milik user" ditolak `BackupAccess` →
     * `AppAccessDenied` (404), sedangkan "volume/id server tidak dikenal" keluar
     * dari sini. Dulu method ini **selalu** JSON, sehingga pada konteks yang sama
     * (mis. `POST /backups/run` dari form) kedua kasus bisa dibedakan dari bentuk
     * responsnya — keberadaan sebuah volume/server bocor. Sekarang keduanya
     * memakai renderer yang sama: status & bentuk respons identik, tanpa fallback.
     *
     * `$request` boleh `null` (pemanggil lama/uji) ⇒ JSON seperti sebelumnya;
     * jalur produksi selalu mengirim request nyata.
     */
    protected function notFound(?Request $request = null)
    {
        if ($request instanceof Request) {
            return (new AppAccessDenied())->render($request);
        }

        return json(['code' => 404, 'msg' => 'Not Found'])->withStatus(404);
    }

    /**
     * Spawn worker CLI detached (non-blocking) — pola sama dengan
     * `AppControllerBase::spawnWorker()`: `pcntl_fork` + `pcntl_exec` (tanpa shell).
     * `proc_open`/`proc_close` BLOCKING sampai worker selesai sehingga tidak
     * dipakai untuk run backup/restore yang bisa berjalan lama.
     *
     * `protected` (bukan private) semata agar unit test dapat meng-override-nya
     * dan memeriksa argumen worker (mis. `server_id` untuk volume server agent)
     * tanpa membuat proses nyata — perilaku produksi tidak berubah.
     *
     * @param array<int,string> $args argumen setelah `cli/backup.php`
     */
    protected function spawnWorker(array $args): bool
    {
        if (!function_exists('pcntl_fork')) {
            return false;
        }

        $command = array_merge([PHP_BINARY, base_path('cli/backup.php')], $args);

        // Otomatis-reap anak saat selesai supaya tidak menumpuk zombie.
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $pid = @pcntl_fork();
        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            // Proses anak: lepas dari sesi, ganti stdio ke /dev/null, lalu exec.
            @posix_setsid();
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            // Variabel dipertahankan sampai pcntl_exec agar fd tetap terbuka.
            $nullIn = @fopen('/dev/null', 'r');
            $nullOut = @fopen('/dev/null', 'w');
            $nullErr = @fopen('/dev/null', 'w');
            // SIGCHLD=SIG_DFL sebelum exec agar worker bisa membaca exit code
            // proses anaknya (docker/restic) — lihat SigchldGuard.
            @pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_exec(PHP_BINARY, array_slice($command, 1));
            exit(127);
        }

        return true;
    }

    /**
     * Dokumen server sebuah baris volume — **hanya** untuk meredaksi identitas
     * server (token, `base_url`/host internal, PEM) pada pesan galat; bukan untuk
     * keputusan otorisasi (itu tetap `BackupAccess`/`AppAccess`).
     *
     * Best-effort: server lokal/tak dikenal/gagal dibaca ⇒ `[]` dan
     * `AgentError::sanitizeForServer()` berperilaku seperti `sanitize()` biasa.
     *
     * @param array<string,mixed> $row baris target volume
     * @return array<string,mixed>
     */
    protected function agentServerOf(array $row): array
    {
        $serverId = self::serverIdOf($row);
        if (BackupDispatcher::isLocalServer($serverId)) {
            return [];
        }

        try {
            $server = $this->serverResolver()->resolve($serverId);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($server) ? $server : [];
    }

    /**
     * Nama berkas unduhan yang aman untuk header `Content-Disposition`.
     *
     * `volume`/`snapshot` sudah divalidasi sebelum ini, tetapi nama tetap
     * disanitasi (buang komponen path → cegah traversal, sisakan `[A-Za-z0-9._-]`)
     * agar header tidak bisa disuntik kutip/baris baru. Statik murni → mudah diuji.
     */
    public static function safeDownloadName(string $volume, string $snapshot): string
    {
        $sanitize = static function (string $value): string {
            $value = basename(str_replace('\\', '/', $value));
            $value = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
            $value = trim($value, '._-');
            return $value === '' ? 'backup' : $value;
        };

        return $sanitize($volume) . '-' . $sanitize($snapshot) . '.sql';
    }
}
