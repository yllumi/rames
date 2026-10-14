<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;

/**
 * Router eksekusi backup/restore/dump **antar server** (multi-server F4,
 * plan §4.2/§4.3/§5).
 *
 * Aturan yang ditegakkan kelas ini (plan §3 butir 4 — inti multi-server):
 *  - volume milik server **agent** dijalankan **di server itu** lewat job agent
 *    (`backup_run`/`restore`/`db_dump` → `AgentDeployer`); control plane **tidak
 *    pernah** menjalankan restic/helper/`docker` terhadap volume tersebut;
 *  - volume server **lokal** tidak pernah dikirim ke agent;
 *  - op yang belum didukung untuk server agent **ditolak dengan pesan jelas**
 *    (volume yatim) — bukan fallback ke server lokal dan bukan sukses palsu;
 *    `restore-archived` kini **didukung** untuk server agent sebagai op tingkat
 *    server `restore_archived` (volume target dibuat & restic dijalankan di
 *    server itu, plan §5 / F4 lanjutan).
 *
 * Riwayat backup server agent dicatat **di control plane** (plan §4.3: registry
 * & seleksi milik control plane; agent hanya menjalankan job + menulis log)
 * dengan kunci ter-scope `server_id` ({@see BackupRegistry::scopeKey()}) sehingga
 * dua server yang punya volume bernama sama tidak bertabrakan.
 *
 * Kredensial: payload job hanya memuat `volume`/`snapshot`/`trigger` — tidak ada
 * kredensial restic/S3/DB yang dikirim (server agent memakai env & berkas
 * kredensialnya sendiri, plan §10.5).
 *
 * Stateless: seluruh dependensi disuntikkan lewat konstruktor (seam uji) —
 * uji berjalan tanpa jaringan/Docker nyata.
 *
 * Kelas **tidak** `final` semata supaya dapat diganti di uji CLI (`create()`
 * mendukung {@see self::CLASS_ENV}); produksi selalu memakai kelas ini apa adanya.
 */
class BackupDispatcher
{
    /**
     * Seam uji CLI (pola `DEPLOYER_CLASS` di `DeployerFactory`): kelas pengganti
     * dispatcher supaya uji `cli/backup.php` tidak menuntut server agent nyata.
     * Produksi: kosong.
     */
    public const CLASS_ENV = 'RAMES_BACKUP_DISPATCHER_CLASS';

    /** @var callable(array<string,mixed>):?AgentDeployer */
    private readonly \Closure $agentFactory;

    private readonly ServerResolver $servers;

    private readonly AppStore $apps;

    /**
     * @param callable(array<string,mixed>):?AgentDeployer|null $agentFactory pembuat
     *        deployer agent untuk satu dokumen server (default
     *        `AgentDeployer::forServer()`; `null` = server lokal)
     * @param ServerResolver|null $servers resolver registry server (seam uji: store temp)
     * @param AppStore|null       $apps    store app control plane (sumber metadata)
     */
    public function __construct(
        ?callable $agentFactory = null,
        ?ServerResolver $servers = null,
        ?AppStore $apps = null,
    ) {
        $this->agentFactory = \Closure::fromCallable($agentFactory ?? static fn (array $server): ?AgentDeployer => AgentDeployer::forServer($server));
        $this->servers = $servers ?? new ServerResolver();
        $this->apps = $apps ?? new AppStore();
    }

    /**
     * Dispatcher yang dipakai CLI — mendukung hook uji {@see self::CLASS_ENV}.
     */
    public static function create(): self
    {
        $override = trim((string) getenv(self::CLASS_ENV));
        if ($override !== '' && is_a($override, self::class, true)) {
            return new $override();
        }

        return new self();
    }

    /**
     * Server lokal (`''`/`local`)?
     */
    public static function isLocalServer(string $serverId): bool
    {
        $serverId = trim($serverId);

        return $serverId === '' || $serverId === ServerStore::LOCAL_ID;
    }

    /**
     * Tolak op yang belum didukung untuk server agent **sebelum** efek samping
     * apa pun (plan §5: penjaga antar-fase — bukan dieksekusi ke server lokal).
     *
     * @throws RuntimeException
     */
    public static function assertSupported(string $mode, ?string $serverId = null): void
    {
        $serverId = trim((string) $serverId);
        if (self::isLocalServer($serverId)) {
            return;
        }

        if (!in_array($mode, ['run', 'restore', 'restore-archived', 'db-dump'], true)) {
            throw new RuntimeException(
                "Op `{$mode}` tidak dikenal dispatcher backup — operasi dibatalkan (tidak dijalankan di server lokal)."
            );
        }
    }

    /**
     * Jalankan backup satu volume di server `$serverId` (agent) — job `backup_run`.
     *
     * Setelah job sukses, hasilnya **dicatat di registry control plane** dengan
     * kunci ter-scope server (jumlah snapshot & snapshot terakhir ditarik dari
     * server itu) sehingga riwayat/laporan tetap milik control plane.
     *
     * @param callable(string,string):void|null $log `(project, message)`
     * @return array{volume:string,status:string,message:string,server_id:string}
     * @throws RuntimeException server tak dikenal/disabled, app pemilik tak ketemu,
     *                          volume yatim, atau job agent gagal
     */
    public function runBackup(string $serverId, string $volume, string $trigger = 'manual', ?callable $log = null): array
    {
        VolumeStateGuard::assertVolumeName($volume);
        $trigger = in_array($trigger, BackupJobRunner::TRIGGERS, true) ? $trigger : 'manual';

        $deployer = $this->agentFor($serverId);
        $strategy = null;
        $app = $this->appOwning($serverId, $volume, $deployer, $strategy);

        $result = $deployer->backupRun($app, $volume, $trigger, $log);

        $this->recordRegistry($serverId, $volume, $app, $strategy ?? '', $deployer, $log);

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => (string) ($result['message'] ?? '') !== ''
                ? (string) $result['message']
                : "Backup volume {$volume} selesai di server {$serverId}.",
            'server_id' => trim($serverId),
        ];
    }

    /**
     * Pulihkan satu volume **di server agent** dari snapshot (job `restore`).
     *
     * @param string $appId id app pemilik volume (`''`/`-` = volume yatim)
     * @return array{volume:string,status:string,message:string,server_id:string}
     * @throws RuntimeException
     */
    public function restore(string $serverId, string $appId, string $volume, string $snapshot, ?callable $log = null): array
    {
        VolumeStateGuard::assertVolumeName($volume);
        $snapshot = ResticRunner::assertSnapshotId(trim($snapshot));

        $deployer = $this->agentFor($serverId);
        $strategy = null;
        $app = $this->appOwning($serverId, $volume, $deployer, $strategy);
        $appId = trim((string) $appId);
        if ($appId !== '' && $appId !== '-' && (string) ($app['id'] ?? '') !== $appId) {
            throw new RuntimeException(
                "Volume \"{$volume}\" bukan milik app {$appId} di server {$serverId} — restore dibatalkan."
            );
        }

        $result = $deployer->restoreVolume($app, $volume, $snapshot, $log);

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => (string) ($result['message'] ?? '') !== ''
                ? (string) $result['message']
                : "Restore volume {$volume} selesai di server {$serverId}.",
            'server_id' => trim($serverId),
        ];
    }

    /**
     * Dump logis DB sebuah volume **di server agent** (job `db_dump`).
     *
     * @return array{volume:string,status:string,message:string,server_id:string}
     * @throws RuntimeException
     */
    public function dbDump(string $serverId, ?string $appId, string $volume, ?callable $log = null): array
    {
        VolumeStateGuard::assertVolumeName($volume);

        $deployer = $this->agentFor($serverId);
        $strategy = null;
        $app = $this->appOwning($serverId, $volume, $deployer, $strategy);

        $appId = $appId !== null ? trim($appId) : '';
        if ($appId !== '' && $appId !== '-' && (string) ($app['id'] ?? '') !== $appId) {
            throw new RuntimeException(
                "Volume \"{$volume}\" bukan milik app {$appId} di server {$serverId} — dump dibatalkan."
            );
        }

        $result = $deployer->dbDump($app, $volume, $log);

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => (string) ($result['message'] ?? '') !== ''
                ? (string) $result['message']
                : "Dump DB volume {$volume} selesai di server {$serverId}.",
            'server_id' => trim($serverId),
        ];
    }

    /**
     * Pulihkan volume **arsip** di server agent ke volume target BARU
     * (job `restore_archived`, op tingkat server — plan §5).
     *
     * Berbeda dari {@see self::runBackup()}/{@see self::restore()}: volume arsip
     * **tidak lagi punya app**, jadi op ini tidak butuh proyeksi app dan dikirim
     * sebagai op tingkat server (`target` = nama volume). Seluruh pekerjaan
     * (pembuatan volume target + restic) berjalan **di server itu**, memakai repo
     * restic dan daemon server itu; `VolumeStateGuard` tetap ditegakkan di sana.
     *
     * @param callable(string,string):void|null $log `(project, message)`
     * @return array{volume:string,status:string,message:string,server_id:string}
     * @throws RuntimeException server tak dikenal/disabled, atau job agent gagal
     */
    public function restoreArchived(
        string $serverId,
        string $volume,
        string $snapshot,
        string $targetName,
        ?callable $log = null,
    ): array {
        VolumeStateGuard::assertVolumeName($volume);
        VolumeStateGuard::assertVolumeName($targetName);
        $snapshot = ResticRunner::assertSnapshotId(trim($snapshot));

        $deployer = $this->agentFor($serverId);

        $result = $deployer->restoreArchivedVolume($volume, $snapshot, $targetName, $log);

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => (string) ($result['message'] ?? '') !== ''
                ? (string) $result['message']
                : "Restore arsip volume {$volume} → {$targetName} selesai di server {$serverId}.",
            'server_id' => trim($serverId),
        ];
    }

    /**
     * Deployer agent untuk server itu — `null` (server lokal) atau server yang
     * tidak dapat dipakai ⇒ **RuntimeException**. Tidak ada jalur mundur ke
     * eksekusi lokal.
     *
     * @throws RuntimeException
     */
    private function agentFor(string $serverId): AgentDeployer
    {
        $serverId = trim($serverId);
        if (self::isLocalServer($serverId)) {
            throw new RuntimeException(
                'Dispatcher agent dipanggil untuk server lokal — jalur lokal dipakai langsung (kesalahan pemanggil).'
            );
        }

        $server = $this->servers->resolve($serverId);
        if (!ServerResolver::isEnabled($server)) {
            throw new RuntimeException("Server \"{$serverId}\" dimatikan — operasi backup/restore dibatalkan.");
        }

        $deployer = ($this->agentFactory)($server);
        if (!$deployer instanceof AgentDeployer) {
            throw new RuntimeException(
                "Server \"{$serverId}\" bukan server agent (atau konfigurasinya cacat) — operasi dibatalkan, "
                . 'TIDAK dijalankan di server lokal.'
            );
        }

        return $deployer;
    }

    /**
     * Dokumen app control plane yang memiliki volume itu **di server tersebut**.
     *
     * Volume dibaca dari daftar volume server agent (bukan dari tebakan pemanggil),
     * lalu app-nya diambil dari store control plane (sumber metadata). Volume
     * yatim / app dengan `server_id` berbeda ditolak dengan pesan jelas.
     *
     * @return array<string,mixed>
     * @throws RuntimeException
     */
    private function appOwning(string $serverId, string $volume, AgentDeployer $deployer, ?string &$strategy = null): array
    {
        $listing = $deployer->backupVolumes();
        $appId = '';
        $strategy = null;
        foreach ($listing['volumes'] as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $volume) {
                $appId = (string) ($row['app_id'] ?? '');
                $strategy = (string) ($row['strategy'] ?? '');
                break;
            }
        }

        if ($appId === '') {
            throw new RuntimeException(
                "Volume \"{$volume}\" tidak ditemukan di server \"{$serverId}\" atau tidak terhubung ke app mana pun. "
                . 'Backup/restore volume yatim server agent belum didukung — operasi dibatalkan.'
            );
        }

        $app = $this->apps->find($appId);
        if ($app === null) {
            throw new RuntimeException(
                "App {$appId} pemilik volume \"{$volume}\" tidak ada di control plane — operasi dibatalkan."
            );
        }

        $appServerId = trim((string) ($app['server_id'] ?? ''));
        if ($appServerId !== trim($serverId)) {
            throw new RuntimeException(
                "App {$appId} terdaftar di server \"" . ($appServerId !== '' ? $appServerId : ServerStore::LOCAL_ID)
                . "\", bukan \"{$serverId}\" — operasi dibatalkan (mencegah backup/restore di server yang salah)."
            );
        }

        return $app;
    }

    /**
     * Catat hasil backup agent di registry **control plane** (kunci ter-scope
     * server). Best-effort: kegagalan membaca snapshot di server agent tidak
     * membatalkan backup yang sudah sukses — entri tetap dicatat tanpa jumlah
     * snapshot (dan akan disegarkan pada run berikutnya).
     *
     * @param array<string,mixed> $app
     */
    private function recordRegistry(
        string $serverId,
        string $volume,
        array $app,
        string $strategy,
        AgentDeployer $deployer,
        ?callable $log,
    ): void {
        $snapshots = 0;
        $lastSnapshot = null;

        try {
            $rows = $deployer->backupSnapshots($volume);
            $snapshots = count($rows);
            $last = $rows !== [] ? $rows[count($rows) - 1] : null;
            if (is_array($last) && isset($last['id']) && is_scalar($last['id'])) {
                $lastSnapshot = (string) $last['id'];
            }
        } catch (\Throwable $e) {
            if ($log !== null) {
                $log(
                    (string) ($app['name'] ?? ''),
                    'Peringatan: jumlah snapshot di server agent tidak terbaca (' . $e->getMessage() . ').'
                );
            }
        }

        try {
            (new BackupRegistry())->upsertMany([
                $volume => [
                    'project' => (string) ($app['name'] ?? ''),
                    'app_id' => (string) ($app['id'] ?? ''),
                    'app_name' => (string) ($app['name'] ?? ''),
                    'strategy' => $strategy,
                    'last_snapshot' => $lastSnapshot,
                    'snapshots' => $snapshots,
                ],
            ], $serverId);
        } catch (\Throwable $e) {
            if ($log !== null) {
                $log((string) ($app['name'] ?? ''), 'Peringatan: riwayat backup tidak tercatat (' . $e->getMessage() . ').');
            }
        }
    }
}
