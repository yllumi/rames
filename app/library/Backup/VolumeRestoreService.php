<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Db\DbConnectionResolver;
use app\library\Db\DbCredentialResolver;
use app\library\Db\DbDump;
use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use InvalidArgumentException;
use RuntimeException;

/**
 * Restore volume sadar-strategi (PLAN_VOLUME_BACKUP.md §2, §5.3).
 *
 * Dua jalur, dipilih dari tag snapshot (`strategy:`), dengan cadangan deteksi
 * container DB (`BackupStrategyResolver::isDb()`):
 *
 *  - **dump** (Strategi A): snapshot dibuka ke direktori sementara (helper
 *    container, target `/restore`), berkas `.sql` di-import ke container DB
 *    **hidup** via `DbDump::import()` (kredensial lewat `MYSQL_PWD`, bukan argv).
 *
 *  - **snapshot** (Strategi B): container app **wajib berhenti**. Snapshot
 *    di-restore ke volume yang di-mount `rw` pada helper; karena restic
 *    mereproduksi path absolut snapshot (`/data/…`), target restore adalah `/`
 *    di dalam helper sehingga isi kembali ke akar volume. **Tidak** memakai
 *    `docker volume rm` (isi ditimpa di tempat); berkas basi di dalam volume
 *    (tidak ada di snapshot) dihapus lewat `--delete --include /data`
 *    (PLAN §2/§5.3). `startProject()` dijamin lewat `finally`.
 *
 * Fail-fast: validasi nama volume, id snapshot, keberadaan snapshot di repo,
 * dan kepemilikan volume → **sebelum** efek samping apa pun.
 */
class VolumeRestoreService
{
    private DockerClient $docker;
    private VolumeStateGuard $guard;
    private DbDump $dbDump;
    private ProcessRunner $process;
    private HelperImageResolver $imageResolver;
    private string $workRoot;

    /** @var array<string,mixed> */
    private array $resticOverrides;

    /** Env-file kredensial S3 per-run (satu sumber kebenaran bersama backup). */
    private CredentialEnvFile $envFiles;

    private \Closure $logger;

    /**
     * @param array<string,mixed> $resticOverrides override spec `ResticRunner::normalizeSpec()`
     * @param callable(string,string):void|null $logger dipanggil `(project, message)`
     * @param HelperImageResolver|null $imageResolver penentu image helper (default: override → image dashboard)
     * @param string|null $envDir direktori env-file kredensial per-run; null = `<runtime>/backup/tmp`
     * @param int $staleEnvMaxAge umur (detik) env-file yatim sebelum dibersihkan best-effort
     * @param array<string,string>|null $credentialEnv override nilai env kredensial (uji); null = config
     */
    public function __construct(
        ?DockerClient $docker = null,
        ?VolumeStateGuard $guard = null,
        ?DbDump $dbDump = null,
        ?ProcessRunner $process = null,
        ?string $workRoot = null,
        array $resticOverrides = [],
        ?callable $logger = null,
        ?HelperImageResolver $imageResolver = null,
        ?string $envDir = null,
        int $staleEnvMaxAge = 3600,
        ?array $credentialEnv = null,
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
        $this->guard = $guard ?? new VolumeStateGuard($this->docker);
        $this->dbDump = $dbDump ?? new DbDump();
        $this->process = $process ?? new ProcessRunner();
        $this->imageResolver = $imageResolver ?? new HelperImageResolver($this->docker);
        $this->workRoot = $workRoot ?? runtime_path('backup/restore');
        $this->resticOverrides = $resticOverrides;
        $this->envFiles = new CredentialEnvFile(
            CredentialEnvFile::credentialsFromConfig($credentialEnv),
            $envDir,
            dirname(rtrim($this->workRoot, '/')),
            $staleEnvMaxAge,
        );
        $this->logger = $logger !== null
            ? \Closure::fromCallable($logger)
            : static function (string $project, string $message): void {
            };
    }

    /**
     * Pulihkan satu volume dari snapshot.
     *
     * @param array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool} $target
     * @param array|null $app entri apps.json pemilik volume (null untuk yatim)
     * @param string     $snapshotId id snapshot restic (short/full hex)
     * @param string|null $sourceVolume nama volume asal snapshot (tag `volume:<nama>`);
     *        null = `$target['name']` (jalur lama). Dipakai restore **arsip**: snapshot
     *        milik volume yang sudah dihapus di-restore ke `$target['name']` (nama baru),
     *        sehingga pencarian snapshot harus memakai nama **asal**, bukan nama target.
     * @return array{ok:bool,strategy:string,volume:string,snapshot:string,database:?string,message:string}
     * @throws RuntimeException validasi gagal / restore gagal
     */
    public function restore(array $target, ?array $app, string $snapshotId, ?string $sourceVolume = null): array
    {
        $volume = (string) $target['name'];
        $project = (string) $target['project'];
        VolumeStateGuard::assertVolumeName($volume);
        VolumeStateGuard::assertProjectName($project);
        ResticRunner::assertSnapshotId($snapshotId);

        $containers = $this->guard->containersForVolume($volume);
        [$strategy, $snapshot] = $this->resolveStrategy($sourceVolume ?? $volume, $snapshotId, $containers);

        if ($strategy === BackupStrategyResolver::STRATEGY_DUMP) {
            $result = $this->restoreDump($project, $app ?? [], $target, $volume, (string) $snapshot['id'], $containers);
        } else {
            $result = $this->restoreSnapshot($project, $target, $volume, (string) $snapshot['id']);
        }

        $this->log($project, sprintf('restore %s dari snapshot %s — %s', $volume, $snapshotId, $result['message']));

        return $result;
    }

    /**
     * Buka snapshot arsip ke direktori temp dan temukan berkas `.sql` pertama di
     * dalamnya (tombol "Unduh SQL" untuk snapshot strategi `dump`).
     *
     * **Tidak** menyentuh Engine/volume app: hanya menjalankan helper restic
     * dengan `--target /restore` ke direktori kerja sementara. Berbeda dari
     * `restore()`, ia tidak butuh container DB dan tidak menyentuh data live.
     *
     * Pemanggil **wajib** menghapus `dir` yang dikembalikan (mis. lewat
     * `removeWorkDir()`) di `finally`. Bila snapshot tidak memuat `.sql`, metoda
     * melempar `InvalidArgumentException` (input tak dapat diproses) **dan**
     * direktori temp sudah dibersihkan.
     *
     * @return array{path:string,dir:string}
     * @throws InvalidArgumentException snapshot tidak memuat berkas .sql
     * @throws RuntimeException validasi nama/id atau restic gagal
     */
    public function extractSnapshotSql(string $volume, string $snapshotId): array
    {
        VolumeStateGuard::assertVolumeName($volume);
        ResticRunner::assertSnapshotId($snapshotId);

        $dir = $this->makeWorkDir($volume);
        try {
            $this->withResticSpec([$dir . ':/restore:rw'], function (array $spec) use ($snapshotId): void {
                (new ResticRunner($this->process, $spec))->restore($snapshotId, '/restore');
            });

            $sqlFile = self::findFirst($dir, 'sql');
            if ($sqlFile === null) {
                throw new InvalidArgumentException(
                    "Snapshot \"{$snapshotId}\" tidak memuat berkas .sql — tidak ada yang bisa diunduh."
                );
            }

            return ['path' => $sqlFile, 'dir' => $dir];
        } catch (\Throwable $e) {
            self::removeTree($dir);
            throw $e;
        }
    }

    /**
     * Putuskan kelayakan restore arsip **tanpa I/O** (murni data) — satu sumber
     * kebenaran yang dipakai controller (`POST /backups/archive/restore`) **dan**
     * `cli/backup.php restore-archived` agar aturan konsisten & mudah diuji.
     *
     * Urutan gate: entri registry ada (404) → strategi `snapshot` (422, arsip
     * `dump` diblokir) → nama target belum dipakai (422) → lolos.
     *
     * @param array<string,mixed>|null $entry entri registry volume asal (null = tak ada)
     * @param bool $targetExists apakah nama volume target sudah dipakai Engine
     * @return array{ok:bool,code:int,msg:string,project:?string}
     */
    public static function planArchiveRestore(?array $entry, bool $targetExists): array
    {
        if ($entry === null) {
            return ['ok' => false, 'code' => 404, 'msg' => 'Volume arsip tidak ditemukan.', 'project' => null];
        }
        if ((string) ($entry['strategy'] ?? '') !== BackupStrategyResolver::STRATEGY_SNAPSHOT) {
            return [
                'ok' => false,
                'code' => 422,
                'msg' => "Volume ini backup-nya berupa dump DB — restore butuh container DB (buat ulang app dulu), atau gunakan 'Unduh SQL'.",
                'project' => null,
            ];
        }
        if ($targetExists) {
            return ['ok' => false, 'code' => 422, 'msg' => 'Volume target sudah ada — pilih nama lain.', 'project' => null];
        }

        return ['ok' => true, 'code' => 0, 'msg' => '', 'project' => (string) ($entry['project'] ?? '')];
    }

    /**
     * Argv `docker volume create` untuk volume **target** restore arsip — statik
     * murni (tanpa I/O) agar `cli/backup.php restore-archived` dan tes memakai
     * bentuk yang sama.
     *
     * Volume diberi label compose-project (`VolumeTargetMap::LABEL_PROJECT`) agar
     * ikut tervalidasi Engine sebagai volume terkelola dashboard. Dipanggil lewat
     * `ProcessRunner` (array + `bypass_shell`) — tanpa secret di argv.
     *
     * @return array<int,string>
     */
    public static function archiveVolumeCreateArgv(string $dockerBinary, string $project, string $targetName): array
    {
        return [
            $dockerBinary,
            'volume',
            'create',
            '--label',
            VolumeTargetMap::LABEL_PROJECT . '=' . $project,
            $targetName,
        ];
    }

    /**
     * Argv `docker volume rm` untuk membersihkan volume target yang gagal
     * direstore (best-effort) — statik murni, pasangan
     * `archiveVolumeCreateArgv()`.
     *
     * @return array<int,string>
     */
    public static function archiveVolumeRemoveArgv(string $dockerBinary, string $targetName): array
    {
        return [$dockerBinary, 'volume', 'rm', $targetName];
    }

    // ==================================================================
    // Strategi
    // ==================================================================

    /**
     * Tentukan strategi restore + pastikan snapshot ADA di repo (fail-fast).
     *
     * `$sourceVolume` adalah nama volume asal yang dipakai tag `volume:<nama>` saat
     * backup; pada restore biasa ia sama dengan `$target['name']`, pada restore arsip
     * ia nama volume **lama** (snapshot tetap bertag nama lama walau volumenya sudah
     * dihapus).
     *
     * @param array<int,array{is_db?:bool,running?:bool}> $containers
     * @return array{0:string,1:array}
     */
    private function resolveStrategy(string $sourceVolume, string $snapshotId, array $containers): array
    {
        $rows = $this->withResticSpec([], function (array $spec) use ($sourceVolume): array {
            return (new ResticRunner($this->process, $spec))->snapshots(['volume:' . $sourceVolume]);
        });

        $match = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $short = (string) ($row['short_id'] ?? '');
            $needle = strtolower($snapshotId);
            if (($id !== '' && str_starts_with(strtolower($id), $needle))
                || ($short !== '' && str_starts_with($short, $needle))
                || ($id !== '' && str_starts_with($needle, strtolower($id)))
            ) {
                $match = $row;
                break;
            }
        }

        if ($match === null) {
            throw new RuntimeException(
                "Snapshot \"{$snapshotId}\" tidak ditemukan untuk volume \"{$sourceVolume}\" — restore dibatalkan."
            );
        }

        $strategy = null;
        foreach ((array) ($match['tags'] ?? []) as $tag) {
            $tag = (string) $tag;
            $prefix = 'strategy:';
            if (str_starts_with($tag, $prefix)) {
                $candidate = substr($tag, strlen($prefix));
                if (in_array($candidate, [BackupStrategyResolver::STRATEGY_DUMP, BackupStrategyResolver::STRATEGY_SNAPSHOT], true)) {
                    $strategy = $candidate;
                }
            }
        }

        if ($strategy === null) {
            // Cadangan: volume dipakai container DB → dump, selain itu snapshot.
            $strategy = BackupStrategyResolver::STRATEGY_SNAPSHOT;
            foreach ($containers as $container) {
                if (BackupStrategyResolver::isDb($container)) {
                    $strategy = BackupStrategyResolver::STRATEGY_DUMP;
                    break;
                }
            }
        }

        return [$strategy, $match];
    }

    // ==================================================================
    // Strategi A — import dump
    // ==================================================================

    /**
     * @param array<int,array{name?:string,is_db?:bool,running?:bool}> $containers
     * @return array{ok:bool,strategy:string,volume:string,snapshot:string,database:?string,message:string}
     */
    private function restoreDump(
        string $project,
        array $app,
        array $target,
        string $volume,
        string $snapshotId,
        array $containers
    ): array {
        $container = $this->resolveDbContainer($containers, empty($target['orphaned']));
        if ($container === '') {
            throw new RuntimeException("Container DB untuk volume \"{$volume}\" tidak ditemukan — import dump tidak mungkin.");
        }

        $inspect = $this->docker->inspectContainer($container);
        $profile = (new DbCredentialResolver())->resolve($app, $inspect);
        if ($profile === null) {
            throw new RuntimeException("Kredensial DB tidak dapat dideteksi pada container \"{$container}\".");
        }

        $tmp = $this->makeWorkDir($volume);
        try {
            // Kredensial S3 diteruskan lewat env-file per-run (0600) — dihapus
            // sebelum import dump (tidak dibutuhkan lagi).
            $this->withResticSpec([$tmp . ':/restore:rw'], function (array $spec) use ($snapshotId): void {
                (new ResticRunner($this->process, $spec))->restore($snapshotId, '/restore');
            });

            $sqlFile = self::findFirst($tmp, 'sql');
            if ($sqlFile === null) {
                throw new RuntimeException("Snapshot \"{$snapshotId}\" tidak memuat berkas .sql untuk di-import.");
            }

            $database = basename($sqlFile, '.sql');
            $importDb = $database;
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $importDb)) {
                // Nama berkas `all-databases` dsb. → pakai database terdeteksi,
                // fallback `mysql` (dump `--all-databases` berisi USE/CREATE sendiri).
                $importDb = (string) ($profile['database'] ?? '');
                if ($importDb === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $importDb)) {
                    $importDb = 'mysql';
                }
            }

            $sql = @file_get_contents($sqlFile);
            if ($sql === false || $sql === '') {
                throw new RuntimeException('Berkas dump tidak dapat dibaca atau kosong.');
            }

            $result = $this->dbDump->import($container, [
                'username' => (string) ($profile['username'] ?? 'root'),
                'password' => (string) ($profile['password'] ?? ''),
                'internal_port' => (new DbConnectionResolver($this->docker))->internalPort($inspect),
            ], $importDb, $sql);

            if (($result['code'] ?? 1) !== 0) {
                throw new RuntimeException('Import dump gagal (exit ' . (int) $result['code'] . '): ' . trim((string) $result['stderr']));
            }
        } finally {
            self::removeTree($tmp);
        }

        return [
            'ok' => true,
            'strategy' => BackupStrategyResolver::STRATEGY_DUMP,
            'volume' => $volume,
            'snapshot' => $snapshotId,
            'database' => $importDb,
            'message' => "Dump di-import ke container {$container} (database {$importDb}).",
        ];
    }

    /**
     * Container DB untuk import — dahulukan yang hidup; bila ada container DB
     * yang mati dan project dikenal, hidupkan project lebih dulu.
     *
     * @param array<int,array{name?:string,is_db?:bool,running?:bool,project?:string}> $containers
     */
    private function resolveDbContainer(array $containers, bool $canStart): string
    {
        $dbStopped = '';
        foreach ($containers as $container) {
            if (empty($container['is_db'])) {
                continue;
            }
            $name = (string) ($container['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!empty($container['running'])) {
                return $name;
            }
            $dbStopped = $name;
        }

        if ($dbStopped !== '') {
            if (!$canStart) {
                throw new RuntimeException(
                    "Container DB \"{$dbStopped}\" berhenti dan project tidak dapat dihidupkan (volume yatim) — import dibatalkan."
                );
            }
            $project = (string) ($containers[0]['project'] ?? '');
            if ($project === '') {
                throw new RuntimeException("Nama project tidak diketahui — tidak bisa menghidupkan container DB \"{$dbStopped}\".");
            }
            $this->guard->startProject($project);
            return $dbStopped;
        }

        return '';
    }

    // ==================================================================
    // Strategi B — restore snapshot ke volume
    // ==================================================================

    /**
     * @param array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool} $target
     * @return array{ok:bool,strategy:string,volume:string,snapshot:string,database:?string,message:string}
     */
    private function restoreSnapshot(string $project, array $target, string $volume, string $snapshotId): array
    {
        $containers = $this->guard->containersForVolume($volume);
        $anyRunning = false;
        foreach ($containers as $container) {
            if (!empty($container['running'])) {
                $anyRunning = true;
                break;
            }
        }

        $stoppedForRestore = false;
        try {
            if ($anyRunning) {
                if (!empty($target['orphaned'])) {
                    throw new RuntimeException(
                        "Volume yatim \"{$volume}\": container berjalan tidak dapat dihentikan (project tidak ada di apps.json)."
                    );
                }
                // Tandai sebelum stop: bila stop gagal di tengah, finally tetap
                // menyalakan ulang agar app tidak tertinggal mati.
                $stoppedForRestore = true;
                $this->guard->stopProject($project);
            }

            if ((bool) config('deploy.volume_backup_require_stopped', true)) {
                $this->guard->assertStopped($volume);
            }

            // restic mereproduksi path absolut snapshot (`/data/…`); volume
            // di-mount pada path aslinya (`/data`) sehingga target `/` menulis
            // kembali ke akar volume. Tidak ada `docker volume rm`. Kredensial
            // S3 lewat env-file per-run (0600, dihapus di `finally`).
            //
            // `--delete` + `--include /data` (PLAN §2/§5.3: "isi volume ditimpa")
            // menghapus berkas di volume yang **tidak** ada di snapshot; tanpa
            // ini volume tidak kembali ke keadaan snapshot. Filter `/data`
            // (path mount volume, `ResticRunner::DATA_PATH`) membatasi
            // penghapusan pada isi volume — filesystem helper (mis.
            // `/etc/hostname`) tidak tersentuh. restic menolak `--delete` tanpa
            // filter, jadi `--include` wajib.
            $result = $this->withResticSpec(
                [ResticRunner::volumeBind($volume, 'rw')],
                function (array $spec) use ($snapshotId): array {
                    $argv = ResticRunner::buildRestoreArgv(
                        $spec,
                        $snapshotId,
                        '/',
                        true,
                        ResticRunner::DATA_PATH,
                    );
                    return $this->process->run($argv, null, (int) $spec['timeout']);
                }
            );
            if (($result['code'] ?? 1) !== 0 || !empty($result['timedOut'])) {
                $message = trim((string) ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
                throw new RuntimeException('restic restore gagal: ' . ($message !== '' ? $message : 'exit code ' . (int) $result['code']));
            }
        } finally {
            if ($stoppedForRestore) {
                $this->guard->startProject($project);
            }
        }

        return [
            'ok' => true,
            'strategy' => BackupStrategyResolver::STRATEGY_SNAPSHOT,
            'volume' => $volume,
            'snapshot' => $snapshotId,
            'database' => null,
            'message' => 'Isi volume dipulihkan dari snapshot (container dinyalakan kembali).',
        ];
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function makeWorkDir(string $volume): string
    {
        $dir = rtrim($this->workRoot, '/') . '/' . $volume . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori kerja restore: {$dir}.");
        }
        return $dir;
    }

    /**
     * Berkas pertama dengan ekstensi tertentu (rekursif).
     */
    private static function findFirst(string $dir, string $extension): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        $entries = scandir($dir) ?: [];
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $found = self::findFirst($path, $extension);
                if ($found !== null) {
                    return $found;
                }
                continue;
            }
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === $extension) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Berkas `.sql` pertama di dalam direktori hasil buka snapshot (rekursif).
     *
     * Publik + statik murni agar penemuan berkas dapat diuji tanpa restic nyata.
     * Urutan deterministik (nama terurut) sehingga berkas yang dipilih stabil.
     */
    public static function findSqlFile(string $dir): ?string
    {
        return self::findFirst($dir, 'sql');
    }

    /**
     * Hapus direktori kerja sementara (best-effort, idempotent) — pasangan
     * `extractSnapshotSql()` agar berkas dump tidak tertinggal di disk.
     */
    public static function removeWorkDir(string $dir): void
    {
        if ($dir === '') {
            return;
        }
        self::removeTree($dir);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Jalankan `$fn` dengan spec restic yang memakai env-file kredensial
     * **per-run**; env-file (chmod 0600) **selalu dihapus** lewat `finally`
     * (sukses maupun gagal) sehingga kredensial S3 tidak pernah bertahan di
     * disk (PLAN §4.4/§7). Mekanisme sama dengan jalur backup
     * (`CredentialEnvFile`) — satu sumber kebenaran.
     *
     * @template T
     * @param array<int,string> $binds
     * @param callable(array<string,mixed>):T $fn
     * @return T
     */
    private function withResticSpec(array $binds, callable $fn): mixed
    {
        // Override `env_file` eksplisit dihormati — jangan tulis env-file kedua.
        if (trim((string) ($this->resticOverrides['env_file'] ?? '')) !== '') {
            return $fn($this->buildResticSpec($binds, ''));
        }

        $this->envFiles->sweepStale();
        $envFile = $this->envFiles->create();
        try {
            return $fn($this->buildResticSpec($binds, $envFile));
        } finally {
            $this->envFiles->remove($envFile);
        }
    }

    /**
     * Susun spec restic efektif (override service + binds + env-file per-run +
     * resolusi image helper fail-fast).
     *
     * @param array<int,string> $binds
     * @return array<string,mixed>
     */
    private function buildResticSpec(array $binds, string $envFile): array
    {
        $spec = $this->resticOverrides;
        $spec['binds'] = $binds;
        // Env-file kredensial milik service hanya dipakai bila pemanggil tidak
        // menyuplai `env_file` sendiri (override eksplisit dihormati).
        if ((!isset($spec['env_file']) || (string) $spec['env_file'] === '') && $envFile !== '') {
            $spec['env_file'] = $envFile;
        }
        // Kontrak §4.3: `VOLUME_BACKUP_IMAGE` kosong ⇒ image container dashboard.
        // `apply()` sekaligus mengisi `dns` dari `HostConfig.Dns` dashboard
        // (best-effort; override eksplisit dihormati) agar helper restore tetap
        // bisa me-resolve S3 meski resolv.conf host rusak.
        return $this->imageResolver->apply(ResticRunner::normalizeSpec($spec));
    }

    private function log(string $project, string $message): void
    {
        ($this->logger)($project, $message);
    }
}
