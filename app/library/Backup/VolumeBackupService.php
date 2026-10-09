<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Orkestrasi SATU run backup volume ke S3 via restic
 * (PLAN_VOLUME_BACKUP.md §2, §3, §4, §5.3).
 *
 * Alur:
 *   1. ambil `BackupRunLock` (tolak run ganda: timer harian vs manual);
 *   2. volume ber-label compose (`DockerClient::listVolumes`) + apps (`AppStore`)
 *      → `VolumeTargetMap::build()`;
 *   3. proses **serial per app** (project):
 *        - strategi `dump`  → `DumpRunner` (container DB hidup) lalu
 *          `ResticRunner::backup()` atas direktori staging;
 *        - strategi `snapshot` → bila policy `stop`: `stopProject` →
 *          `assertStopped` → `restic backup` → `startProject` **di `finally`**;
 *          bila policy `skip`: dicatat `skipped`;
 *   4. `forget` retensi (`volume_backup_keep_daily|weekly|monthly`) per volume;
 *   5. tulis `BackupReport` (status + run), staging dibersihkan di `finally`.
 *
 * Kegagalan satu volume **tidak** menghentikan volume lain, dan app tidak
 * pernah ditinggalkan dalam keadaan mati (start ulang dijamin `finally`).
 *
 * Instance per pemakaian (tanpa state lintas-request). Semua dependensi dapat
 * di-inject agar teruji tanpa Docker/restic nyata.
 */
class VolumeBackupService
{
    private const POLICY_STOP = 'stop';
    private const POLICY_SKIP = 'skip';

    /**
     * Timeout (detik) untuk panggilan `restic snapshots` **penghitung** di
     * endpoint status (`GET /api/backups/status`). Sengaja pendek agar S3 yang
     * lambat/tak terjangkau tidak menggantung UI sampai `volume_backup_timeout`
     * (3600 dtk); timeout → exception tertangkap → jumlah snapshot `0` tanpa
     * menutup bagian status lain.
     */
    private const SNAPSHOT_COUNT_TIMEOUT = 15;

    private DockerClient $docker;
    private AppStore $apps;
    private VolumeStateGuard $guard;
    private DumpRunner $dumper;
    private ProcessRunner $process;
    private BackupRunLock $lock;
    private BackupReport $report;
    private HelperImageResolver $imageResolver;

    /** Override policy snapshot (`stop`/`skip`); null = baca config. */
    private ?string $snapshotPolicy;

    /** Override spec restic (image/repository/password_file/…); kosong = config. */
    private array $resticOverrides;

    /** Env-file kredensial S3 per-run (satu sumber kebenaran bersama restore). */
    private CredentialEnvFile $envFiles;

    /** Seleksi volume untuk run berkala (opt-in per volume; default OFF). */
    private BackupSelection $selection;

    /** Cache baris ringkasan (ditulis saat run/refresh, dibaca saat polling status). */
    private BackupCatalog $catalog;

    /** Riwayat volume yang pernah ter-backup (restore volume yang sudah dihapus). */
    private BackupRegistry $registry;

    private \Closure $logger;

    /**
     * @param array<string,mixed> $resticOverrides override spec `ResticRunner::normalizeSpec()`
     * @param callable(string,string):void|null $logger dipanggil `(project, message)` per volume
     * @param HelperImageResolver|null $imageResolver penentu image helper (default: override → image dashboard)
     * @param string|null $snapshotPolicy override policy snapshot (`stop`/`skip`); null = config
     * @param string|null $envDir direktori env-file kredensial per-run; null = `<runtime>/backup/tmp`
     * @param int $staleEnvMaxAge umur (detik) env-file yatim sebelum dibersihkan best-effort
     * @param array<string,string>|null $credentialEnv override nilai env kredensial (uji); null = config
     * @param BackupSelection|null $selection seleksi volume run berkala (default: store `backup` di SQLite)
     * @param BackupCatalog|null $catalog cache ringkasan (default: `<report dir>/catalog.json`)
     * @param BackupRegistry|null $registry riwayat volume ter-backup (default: path sama dengan seleksi)
     */
    public function __construct(
        ?DockerClient $docker = null,
        ?AppStore $apps = null,
        ?VolumeStateGuard $guard = null,
        ?DumpRunner $dumper = null,
        ?ProcessRunner $process = null,
        ?BackupRunLock $lock = null,
        ?BackupReport $report = null,
        array $resticOverrides = [],
        ?callable $logger = null,
        ?HelperImageResolver $imageResolver = null,
        ?string $snapshotPolicy = null,
        ?string $envDir = null,
        int $staleEnvMaxAge = 3600,
        ?array $credentialEnv = null,
        ?BackupSelection $selection = null,
        ?BackupCatalog $catalog = null,
        ?BackupRegistry $registry = null,
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
        $this->apps = $apps ?? new AppStore();
        $this->guard = $guard ?? new VolumeStateGuard($this->docker, null, $this->apps);
        $this->dumper = $dumper ?? new DumpRunner($this->docker);
        $this->process = $process ?? new ProcessRunner();
        $this->lock = $lock ?? new BackupRunLock();
        $this->report = $report ?? new BackupReport();
        $this->imageResolver = $imageResolver ?? new HelperImageResolver($this->docker);
        $this->snapshotPolicy = $snapshotPolicy;
        $this->resticOverrides = $resticOverrides;
        $this->envFiles = new CredentialEnvFile(
            CredentialEnvFile::credentialsFromConfig($credentialEnv),
            $envDir,
            $this->report->dir(),
            $staleEnvMaxAge,
        );
        $this->logger = $logger !== null
            ? \Closure::fromCallable($logger)
            : static function (string $project, string $message): void {
            };
        $this->selection = $selection ?? new BackupSelection();
        // Default cache ikut direktori laporan (runtime/backup) agar tes dengan
        // `BackupReport($tmp)` otomatis terisolasi — tanpa menyentuh runtime nyata.
        $this->catalog = $catalog ?? new BackupCatalog($this->report->dir() . '/catalog.json');
        // Registry berbagi store dengan seleksi (store `backup` di SQLite) — turunkan
        // path dari instans seleksi agar override temp pada tes ikut terisolasi
        // (larangan #15: jangan menyentuh store `backup`/DB nyata saat tes).
        $this->registry = $registry ?? new BackupRegistry($this->selection->path());
    }

    // ==================================================================
    // Enumerasi
    // ==================================================================

    /**
     * Semua target backup (volume ber-label compose) + flag `orphaned`.
     *
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    public function targets(): array
    {
        $apps = $this->apps->all();
        $volumes = $this->docker->listVolumes(['label' => [VolumeTargetMap::LABEL_PROJECT]]);
        return VolumeTargetMap::build($volumes, $apps);
    }

    /**
     * Apakah ada run backup yang sedang berjalan (status.json `running` atau
     * lock dipegang proses lain). Probe lock bersifat non-destruktif.
     */
    public function isRunning(): bool
    {
        $status = $this->report->readStatus();
        if (!empty($status['running'])) {
            return true;
        }

        $path = $this->lock->path();
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $free = @flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);

        return !$free;
    }

    /**
     * Cache baris ringkasan terakhir — **tanpa** Engine/restic (murni baca file).
     * Dipakai `GET /api/backups/status` agar polling tidak menyentuh Docker.
     *
     * @return array{volumes:array<int,array<string,mixed>>,cached_at:?string}
     */
    public function catalog(): array
    {
        return $this->catalog->read();
    }

    /**
     * Hitung ulang ringkasan secara **live** (Engine + restic) lalu simpan ke
     * cache; mengembalikan baris live. Dipakai tombol "Segarkan"
     * (`POST /backups/refresh`) — bukan polling status.
     *
     * @return array<int,array<string,mixed>>
     */
    public function refreshCatalog(): array
    {
        $targets = $this->targets();
        // Hitung snapshot live SEKALI: dipakai untuk kolom `snapshots` baris
        // maupun sinkronisasi/prune registry. `null` (repo tak terjangkau) dan
        // `[]` (repo terjangkau tetapi kosong/salah bucket) = "tidak diketahui"
        // → jangan prune (riwayat bisa hilang keliru); guard ada di
        // `BackupRegistry::syncCounts()`.
        $counts = $this->snapshotCountsByVolume();
        $rows = $this->overviewWithCounts($targets, $this->report->readStatus(), $counts ?? []);
        $this->backfillScheduled($rows);
        $this->catalog->write($rows);
        $this->syncRegistryCounts($counts);
        $this->backfillRegistry($rows);

        return $rows;
    }

    /**
     * Entri riwayat (registry) yang namanya **tidak ada** di daftar volume aktif
     * (cache `BackupCatalog`) — mis. app sudah dihapus total beserta volumenya.
     *
     * Murni baca berkas (tanpa Engine/restic) sehingga aman untuk polling status;
     * tiap entri diberi kunci `name`. Otorisasi (admin-only) ditegakkan controller.
     *
     * @return array<int,array<string,mixed>>
     */
    public function archived(): array
    {
        $active = [];
        foreach ($this->catalog->read()['volumes'] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $active[(string) $row['name']] = true;
            }
        }

        $rows = [];
        foreach ($this->registry->read() as $name => $entry) {
            if (isset($active[$name])) {
                continue;
            }
            $entry['name'] = $name;
            $rows[] = $entry;
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['last_backed_up_at'] ?? ''), (string) ($b['last_backed_up_at'] ?? ''))
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rows;
    }

    /**
     * Snapshot restic untuk sebuah volume (tag `volume:<nama>`).
     *
     * @return array<int,array{id:string,time:string,tags:array<int,string>,size:int,paths:array<int,string>}>
     */
    public function snapshotsFor(string $volume): array
    {
        VolumeStateGuard::assertVolumeName($volume);
        $rows = $this->withResticSpec([], function (array $spec) use ($volume): array {
            return (new ResticRunner($this->process, $spec))->snapshots(['volume:' . $volume]);
        });

        $snapshots = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['short_id'] ?? $row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
            $snapshots[] = [
                'id' => $id,
                'time' => (string) ($row['time'] ?? ''),
                'tags' => array_values(array_map('strval', (array) ($row['tags'] ?? []))),
                'size' => (int) ($summary['total_bytes_processed'] ?? $summary['total_bytes'] ?? $row['size'] ?? 0),
                'paths' => array_values(array_map('strval', (array) ($row['paths'] ?? []))),
            ];
        }

        return $snapshots;
    }

    /**
     * Ringkasan per volume untuk UI (`GET /api/backups/status`).
     *
     * `snapshots` = jumlah snapshot restic per volume (satu panggilan restic
     * untuk semua volume; dilewati bila repo belum dikonfigurasi).
     *
     * @param array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}> $targets
     * @param array $lastStatus isi `status.json` (untuk `last_*`)
     * @return array<int,array<string,mixed>>
     */
    public function overview(array $targets, array $lastStatus = []): array
    {
        return $this->overviewWithCounts($targets, $lastStatus, $this->snapshotCountsByVolume() ?? []);
    }

    /**
     * Inti `overview()` dengan jumlah snapshot yang sudah dihitung pemanggil —
     * menghindari panggilan restic ganda saat jalur refresh/run ingin **sekali**
     * hitung untuk kolom `snapshots` **dan** sinkronisasi registry.
     *
     * @param array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}> $targets
     * @param array $lastStatus isi `status.json` (untuk `last_*`)
     * @param array<string,int> $counts jumlah snapshot per volume
     * @return array<int,array<string,mixed>>
     */
    private function overviewWithCounts(array $targets, array $lastStatus, array $counts): array
    {
        $byVolume = [];
        foreach ((array) ($lastStatus['volumes'] ?? []) as $row) {
            if (is_array($row) && isset($row['name'])) {
                $byVolume[(string) $row['name']] = $row;
            }
        }

        $dbDumpEnabled = (bool) config('deploy.volume_backup_db_dump_enabled', true);

        $rows = [];
        foreach ($targets as $target) {
            $name = (string) $target['name'];
            $containers = [];
            try {
                $containers = $this->guard->containersForVolume($name);
            } catch (\Throwable $e) {
                $containers = [];
            }

            $last = $byVolume[$name] ?? [];
            $rows[] = [
                'name' => $name,
                'project' => (string) $target['project'],
                'app_id' => $target['app_id'],
                'app_name' => $target['app_name'],
                'orphaned' => (bool) $target['orphaned'],
                'strategy' => BackupStrategyResolver::resolve($containers, $dbDumpEnabled),
                'container_state' => self::containerState($containers),
                'last_run_at' => $last['finished_at'] ?? null,
                'last_ok' => (($last['status'] ?? '') === 'ok'),
                'last_message' => $last['error'] ?? null,
                'snapshots' => (int) ($counts[$name] ?? 0),
            ];
        }

        return $rows;
    }

    // ==================================================================
    // Run
    // ==================================================================

    /**
     * Jalankan satu run backup (ambil lock, proses semua volume yang diminta).
     *
     * @param array{trigger?:string,host?:string,volumes?:array<int,string>|null} $options
     * @return array<string,mixed> ringkasan run (bentuk sama dengan `status.json`)
     * @throws RuntimeException fitur dimatikan / lock dipegang run lain
     */
    public function run(array $options = []): array
    {
        if (!(bool) config('deploy.volume_backup_enabled', true)) {
            throw new RuntimeException('Backup volume dimatikan (VOLUME_BACKUP_ENABLED=false).');
        }

        $this->lock->acquire();
        try {
            return $this->execute($options);
        } catch (\Throwable $e) {
            $failed = [
                'run_id' => bin2hex(random_bytes(8)),
                'trigger' => (string) ($options['trigger'] ?? 'manual'),
                'host' => (string) ($options['host'] ?? (gethostname() ?: 'rames')),
                'status' => 'failed',
                'running' => false,
                'started_at' => date('c'),
                'finished_at' => date('c'),
                'duration_ms' => 0,
                'totals' => ['volumes' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'bytes' => 0],
                'summary' => ['ok' => 0, 'failed' => 0, 'skipped' => 0],
                'volumes' => [],
                'error' => $e->getMessage(),
            ];
            $this->report->writeStatus($failed);
            $this->report->appendRun($failed);
            throw $e;
        } finally {
            $this->lock->release();
            $this->dumper->cleanup();
        }
    }

    /**
     * @param array{trigger?:string,host?:string,volumes?:array<int,string>|null} $options
     * @return array<string,mixed>
     */
    private function execute(array $options): array
    {
        // Bersihkan sisa env-file kredensial dari run yang crash (SIGKILL dsb.)
        // SEBELUM menulis yang baru — kredensial tidak boleh menumpuk di disk.
        $this->envFiles->sweepStale();

        $startedAt = date('c');
        $runId = bin2hex(random_bytes(8));
        $trigger = (string) ($options['trigger'] ?? 'manual');
        $host = (string) ($options['host'] ?? (gethostname() ?: 'rames'));
        $filter = $this->volumeFilter($options['volumes'] ?? null);

        $dbDumpEnabled = (bool) config('deploy.volume_backup_db_dump_enabled', true);
        $policy = $this->snapshotPolicy ?? (string) config('deploy.volume_backup_snapshot_policy', self::POLICY_STOP);
        if (!in_array($policy, [self::POLICY_STOP, self::POLICY_SKIP], true)) {
            $policy = self::POLICY_STOP;
        }

        $apps = $this->apps->all();
        $appById = [];
        foreach ($apps as $app) {
            $appById[(string) ($app['id'] ?? '')] = $app;
        }

        $targets = VolumeTargetMap::build(
            $this->docker->listVolumes(['label' => [VolumeTargetMap::LABEL_PROJECT]]),
            $apps
        );
        if ($filter !== null) {
            $targets = array_values(array_filter(
                $targets,
                static fn (array $t): bool => isset($filter[(string) $t['name']])
            ));
        } elseif ($trigger === 'schedule') {
            // Run berkala (timer) memilih volume lewat seleksi per-volume
            // (default OFF/opt-in; volume yang sudah punya snapshot di-backfill
            // otomatis ke ON). Murni baca store `backup` — tanpa
            // panggilan Engine/restic tambahan. Daftar volume eksplisit (manual)
            // TIDAK disaring; pemanggil sudah memilih.
            $targets = array_values(array_filter(
                $targets,
                fn (array $t): bool => $this->selection->isScheduled((string) $t['name'])
            ));
        }

        $this->report->writeStatus([
            'run_id' => $runId,
            'trigger' => $trigger,
            'host' => $host,
            'status' => 'running',
            'running' => true,
            'started_at' => $startedAt,
            'volumes' => [],
            'totals' => ['volumes' => count($targets), 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'bytes' => 0],
        ]);

        $t0 = microtime(true);
        $rows = [];
        $byProject = [];
        foreach ($targets as $target) {
            $byProject[(string) $target['project']][] = $target;
        }

        foreach ($byProject as $project => $group) {
            foreach ($this->processProject((string) $project, $group, $appById, $dbDumpEnabled, $policy, $host) as $row) {
                $rows[] = $row;
                $this->logLine(
                    (string) $project,
                    sprintf(
                        '%s %s%s',
                        $row['name'],
                        $row['status'],
                        ($row['error'] ?? null) !== null ? ' — ' . $row['error'] : ''
                    )
                );
            }
        }

        $totals = ['volumes' => count($rows), 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'bytes' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'ok') {
                $totals['ok']++;
            } elseif ($row['status'] === 'skipped') {
                $totals['skipped']++;
            } else {
                $totals['failed']++;
            }
            $totals['bytes'] += (int) ($row['bytes'] ?? 0);
        }

        $status = $totals['failed'] === 0
            ? 'ok'
            : ($totals['ok'] > 0 ? 'partial' : 'failed');

        $run = [
            'run_id' => $runId,
            'trigger' => $trigger,
            'host' => $host,
            'status' => $status,
            'running' => false,
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'totals' => $totals,
            'summary' => ['ok' => $totals['ok'], 'failed' => $totals['failed'], 'skipped' => $totals['skipped']],
            'volumes' => $rows,
        ];
        if ($status === 'failed' && $rows !== []) {
            $firstError = null;
            foreach ($rows as $row) {
                if (($row['error'] ?? null) !== null) {
                    $firstError = (string) $row['error'];
                    break;
                }
            }
            $run['error'] = $firstError ?? 'Semua volume gagal di-backup.';
        }

        $this->report->writeStatus($run);
        $this->report->appendRun($run);

        // Riwayat permanen: catat volume yang sukses (best-effort).
        $this->recordRegistry($rows);

        // Segarkan cache ringkasan dari data live (satu pass tambahan; run harian,
        // bukan polling). Fail-safe: kegagalan cache tidak menggagalkan run.
        $this->writeCatalog($run);

        return $run;
    }

    /**
     * Tulis cache baris ringkasan dari data live. **Best-effort** — kegagalan
     * (Engine/restic/berkas) diabaikan agar tidak menggagalkan run yang sudah
     * sukses.
     *
     * @param array<string,mixed> $run status run baru (untuk kolom `last_*`)
     */
    private function writeCatalog(array $run): void
    {
        try {
            $counts = $this->snapshotCountsByVolume();
            $rows = $this->overviewWithCounts($this->targets(), $run, $counts ?? []);
            $this->backfillScheduled($rows);
            $this->catalog->write($rows);
            $this->syncRegistryCounts($counts);
            $this->backfillRegistry($rows);
        } catch (\Throwable $e) {
            // Cache bersifat best-effort.
        }
    }

    /**
     * Backfill flag `scheduled` untuk volume yang **sudah** punya snapshot
     * (default kini OFF/opt-in): volume yang pernah ter-backup otomatis tetap
     * ON tanpa intervensi UI. Dipanggil dari jalur cache (`refreshCatalog()`/
     * `writeCatalog()`), jadi kegagalannya tidak boleh menggagalkan run.
     *
     * @param array<int,array<string,mixed>> $rows baris hasil `overview()`
     */
    private function backfillScheduled(array $rows): void
    {
        $names = [];
        foreach ($rows as $row) {
            if (is_array($row) && (int) ($row['snapshots'] ?? 0) > 0) {
                $names[] = (string) ($row['name'] ?? '');
            }
        }
        $this->selection->backfill($names);
    }

    /**
     * Backfill riwayat (registry) untuk volume yang **sudah punya snapshot**
     * (`snapshots > 0`) tetapi belum tercatat — mis. snapshot lama yang dibuat
     * SEBELUM fitur registry ada (yang hanya diisi `recordRegistry()` saat run
     * sukses). Tanpa ini, volume yang app+volumenya dihapus sebelum run
     * berikutnya tidak akan bisa direstore dari tab Arsip.
     *
     * Hanya menyentuh kunci `registry` (lewat `BackupRegistry::backfill()`, yang
     * idempotent & tak menimpa entri eksisting). Dipanggil dari jalur cache
     * (`refreshCatalog()`/`writeCatalog()`) → **best-effort**, kegagalan tidak
     * boleh menggagalkan run/refresh.
     *
     * @param array<int,array<string,mixed>> $rows baris hasil `overviewWithCounts()`
     */
    private function backfillRegistry(array $rows): void
    {
        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['snapshots'] ?? 0) <= 0) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $entries[$name] = [
                'project' => (string) ($row['project'] ?? ''),
                'app_id' => $row['app_id'] ?? null,
                'app_name' => $row['app_name'] ?? null,
                'strategy' => (string) ($row['strategy'] ?? ''),
                'snapshots' => (int) $row['snapshots'],
                'at' => $row['last_run_at'] ?? null,
            ];
        }
        if ($entries === []) {
            return;
        }

        try {
            $this->registry->backfill($entries);
        } catch (\Throwable $e) {
            // Registry bersifat best-effort (jangan gagalkan run/refresh).
        }
    }

    // ==================================================================
    // Per-app (serial)
    // ==================================================================

    /**
     * @param array<int,array> $group
     * @param array<string,array> $appById
     * @return array<int,array<string,mixed>>
     */
    private function processProject(
        string $project,
        array $group,
        array $appById,
        bool $dbDumpEnabled,
        string $policy,
        string $host
    ): array {
        $rows = [];
        $plans = [];
        foreach ($group as $target) {
            try {
                $containers = $this->guard->containersForVolume((string) $target['name']);
                $strategy = BackupStrategyResolver::resolve($containers, $dbDumpEnabled);
            } catch (\Throwable $e) {
                // Tidak bisa memeriksa Engine untuk volume ini → catat gagal,
                // volume lain tetap diproses (kegagalan tidak menular).
                $row = $this->baseRow($target, BackupStrategyResolver::STRATEGY_SNAPSHOT);
                $row['status'] = 'failed';
                $row['error'] = $e->getMessage();
                $row['finished_at'] = date('c');
                $rows[] = $row;
                continue;
            }
            $plans[] = ['target' => $target, 'containers' => $containers, 'strategy' => $strategy];
        }

        // Fase 1 — strategi dump (container DB hidup).
        foreach ($plans as $plan) {
            if ($plan['strategy'] !== BackupStrategyResolver::STRATEGY_DUMP) {
                continue;
            }
            $rows[] = $this->backupDump($plan, $appById, $host, $project);
        }

        // Fase 2 — strategi snapshot (butuh container berhenti).
        $snapshotPlans = array_values(array_filter(
            $plans,
            static fn (array $p): bool => $p['strategy'] === BackupStrategyResolver::STRATEGY_SNAPSHOT
        ));
        if ($snapshotPlans !== []) {
            $rows = array_merge($rows, $this->backupSnapshots($project, $snapshotPlans, $appById, $policy, $host));
        }

        return $rows;
    }

    /**
     * @param array{target:array,containers:array,strategy:string} $plan
     * @param array<string,array> $appById
     * @return array<string,mixed>
     */
    private function backupDump(array $plan, array $appById, string $host, string $project): array
    {
        $target = $plan['target'];
        $t0 = microtime(true);
        $row = $this->baseRow($target, BackupStrategyResolver::STRATEGY_DUMP);
        $row['started_at'] = date('c');

        try {
            $container = '';
            foreach ($plan['containers'] as $candidate) {
                if (!empty($candidate['is_db']) && !empty($candidate['running'])) {
                    $container = (string) $candidate['name'];
                    break;
                }
            }
            if ($container === '') {
                throw new RuntimeException('Container DB berjalan tidak ditemukan untuk strategi dump.');
            }

            $app = $appById[(string) ($target['app_id'] ?? '')] ?? [];
            $timestamp = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $dump = $this->dumper->dump($project, $app, $container, $timestamp);

            $outcome = $this->withResticSpec(
                [$dump['dir'] . ':' . ResticRunner::DATA_PATH . ':ro'],
                function (array $spec) use ($target, $host): array {
                    $runner = new ResticRunner($this->process, $spec);
                    $result = $runner->backup(
                        [ResticRunner::DATA_PATH],
                        $this->tags($target, BackupStrategyResolver::STRATEGY_DUMP),
                        $host
                    );

                    return [
                        'result' => $result,
                        'forget_error' => $this->forget($runner, (string) $target['name']),
                    ];
                }
            );

            $row['status'] = 'ok';
            $row['snapshot_id'] = ResticRunner::parseSnapshotId($outcome['result']['stdout'] . "\n" . $outcome['result']['stderr']);
            $row['bytes'] = (int) $dump['bytes'];
            $row['error'] = $outcome['forget_error'];
        } catch (\Throwable $e) {
            $row['status'] = 'failed';
            $row['error'] = $e->getMessage();
        }

        $row['finished_at'] = date('c');
        $row['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $row;
    }

    /**
     * Snapshot seluruh volume non-DB milik satu project. Stop/start dilakukan
     * **sekali per app**; start ulang dijamin lewat `finally`.
     *
     * @param array<int,array{target:array,containers:array,strategy:string}> $plans
     * @param array<string,array> $appById
     * @return array<int,array<string,mixed>>
     */
    private function backupSnapshots(string $project, array $plans, array $appById, string $policy, string $host): array
    {
        $count = count($plans);
        $rows = [];
        $target0 = $plans[0]['target'];
        $app = $appById[(string) ($target0['app_id'] ?? '')] ?? null;
        $projectKnown = $app !== null;

        $anyRunning = false;
        foreach ($plans as $plan) {
            if (self::anyRunning($plan['containers'])) {
                $anyRunning = true;
                break;
            }
        }

        $shouldStop = $anyRunning && $policy === self::POLICY_STOP && $projectKnown;
        $stoppedForBackup = false;
        $startError = null;

        try {
            if ($shouldStop) {
                // Tandai sebelum stop: bila stop gagal di tengah (sebagian
                // container sudah berhenti), finally tetap menyalakan ulang.
                $stoppedForBackup = true;
                $this->guard->stopProject($project);
                $this->waitStopped(array_map(
                    static fn (array $p): string => (string) $p['target']['name'],
                    $plans
                ));
            }

            for ($i = 0; $i < $count; $i++) {
                $plan = $plans[$i];
                $target = $plan['target'];
                $row = $this->baseRow($target, BackupStrategyResolver::STRATEGY_SNAPSHOT);

                if ($anyRunning && !$projectKnown) {
                    $row['status'] = 'skipped';
                    $row['error'] = 'Volume yatim: container berjalan tidak dapat dihentikan (project tidak ada di apps.json).';
                    $row['finished_at'] = date('c');
                    $rows[$i] = $row;
                    continue;
                }

                // Policy `skip`: snapshot hanya sah saat container berhenti.
                // Bila container masih hidup, volume ini DILEWATI (status
                // `skipped` + alasan) — bukan dicap gagal. Error nyata
                // (restic/Engine) tetap `failed`.
                if ($policy === self::POLICY_SKIP && self::anyRunning($plan['containers'])) {
                    $row['status'] = 'skipped';
                    $row['error'] = 'Container masih berjalan dan policy VOLUME_BACKUP_SNAPSHOT_POLICY=skip — snapshot dilewati.';
                    $row['finished_at'] = date('c');
                    $rows[$i] = $row;
                    continue;
                }

                $rows[$i] = $this->backupSnapshot($plan, $host);
            }
        } catch (\Throwable $e) {
            for ($i = count($rows); $i < $count; $i++) {
                $row = $this->baseRow($plans[$i]['target'], BackupStrategyResolver::STRATEGY_SNAPSHOT);
                $row['status'] = 'failed';
                $row['error'] = $e->getMessage();
                $rows[$i] = $row;
            }
        } finally {
            if ($stoppedForBackup) {
                try {
                    $this->guard->startProject($project);
                } catch (\Throwable $e) {
                    $startError = $e->getMessage();
                }
            }
        }

        if ($startError !== null) {
            foreach ($rows as $index => $row) {
                $message = 'Gagal menyalakan ulang app setelah snapshot: ' . $startError;
                $rows[$index]['error'] = trim(($row['error'] ?? '') !== '' ? $row['error'] . ' | ' . $message : $message);
            }
        }

        ksort($rows);
        return array_values($rows);
    }

    /**
     * @param array{target:array,containers:array,strategy:string} $plan
     * @return array<string,mixed>
     */
    private function backupSnapshot(array $plan, string $host): array
    {
        $target = $plan['target'];
        $volume = (string) $target['name'];
        $t0 = microtime(true);
        $row = $this->baseRow($target, BackupStrategyResolver::STRATEGY_SNAPSHOT);
        $row['started_at'] = date('c');

        try {
            if ((bool) config('deploy.volume_backup_require_stopped', true)) {
                $this->guard->assertStopped($volume);
            }

            $outcome = $this->withResticSpec(
                [ResticRunner::volumeBind($volume, 'ro')],
                function (array $spec) use ($target, $volume, $host): array {
                    $runner = new ResticRunner($this->process, $spec);
                    $result = $runner->backup(
                        [ResticRunner::DATA_PATH],
                        $this->tags($target, BackupStrategyResolver::STRATEGY_SNAPSHOT),
                        $host
                    );

                    return [
                        'result' => $result,
                        'forget_error' => $this->forget($runner, $volume),
                    ];
                }
            );

            $row['status'] = 'ok';
            $row['snapshot_id'] = ResticRunner::parseSnapshotId($outcome['result']['stdout'] . "\n" . $outcome['result']['stderr']);
            $row['error'] = $outcome['forget_error'];
        } catch (\Throwable $e) {
            $row['status'] = 'failed';
            $row['error'] = $e->getMessage();
        }

        $row['finished_at'] = date('c');
        $row['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $row;
    }

    /**
     * Tunggu seluruh container volume benar-benar berhenti (batas
     * `VOLUME_BACKUP_STOP_TIMEOUT`).
     *
     * @param array<int,string> $volumes
     */
    private function waitStopped(array $volumes): void
    {
        $timeout = max(1, (int) config('deploy.volume_backup_stop_timeout', 120));
        $deadline = microtime(true) + $timeout;
        foreach ($volumes as $volume) {
            while (true) {
                try {
                    $this->guard->assertStopped($volume);
                    break;
                } catch (\Throwable $e) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException(
                            "Container volume \"{$volume}\" belum berhenti setelah {$timeout} detik: " . $e->getMessage()
                        );
                    }
                    usleep(500000);
                }
            }
        }
    }

    /**
     * `restic forget --prune` retensi per volume (non-fatal: backup sudah dibuat).
     *
     * @return ?string pesan error retensi bila gagal (null bila sukses/dilewati)
     */
    private function forget(ResticRunner $runner, string $volume): ?string
    {
        $keep = array_filter([
            'daily' => (int) config('deploy.volume_backup_keep_daily', 7),
            'weekly' => (int) config('deploy.volume_backup_keep_weekly', 4),
            'monthly' => (int) config('deploy.volume_backup_keep_monthly', 3),
        ], static fn (int $value): bool => $value > 0);
        if ($keep === []) {
            return null;
        }

        try {
            $runner->forget($keep, ['volume:' . $volume]);
            return null;
        } catch (\Throwable $e) {
            return 'Retensi (forget) gagal: ' . $e->getMessage();
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function baseRow(array $target, string $strategy): array
    {
        return [
            'name' => (string) $target['name'],
            'project' => (string) $target['project'],
            'app_id' => $target['app_id'] !== null ? (string) $target['app_id'] : null,
            'app_name' => $target['app_name'] !== null ? (string) $target['app_name'] : null,
            'orphaned' => (bool) $target['orphaned'],
            'strategy' => $strategy,
            'status' => 'failed',
            'snapshot_id' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => 0,
            'bytes' => 0,
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $target
     * @return array<int,string>
     */
    private function tags(array $target, string $strategy): array
    {
        $appId = (string) ($target['app_id'] ?? '');
        return [
            'volume:' . (string) $target['name'],
            'project:' . (string) $target['project'],
            'app:' . ($appId !== '' ? $appId : 'orphan'),
            'strategy:' . $strategy,
        ];
    }

    /**
     * Filter volume opsional dari pemanggil (`null` = semua); nama divalidasi.
     *
     * @param mixed $volumes
     * @return array<string,bool>|null
     */
    private function volumeFilter(mixed $volumes): ?array
    {
        if ($volumes === null) {
            return null;
        }
        $filter = [];
        foreach ((array) $volumes as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            try {
                VolumeStateGuard::assertVolumeName($name);
            } catch (\Throwable $e) {
                throw new RuntimeException($e->getMessage());
            }
            $filter[$name] = true;
        }
        return $filter === [] ? null : $filter;
    }

    /**
     * Jalankan `$fn` dengan spec restic yang memakai env-file kredensial
     * **per-run**; env-file (chmod 0600) **selalu dihapus** lewat `finally`
     * (sukses, exception, maupun gagal tulis) sehingga kredensial S3 tidak
     * pernah bertahan di disk (PLAN §4.4/§7).
     *
     * @template T
     * @param array<int,string> $binds
     * @param callable(array<string,mixed>):T $fn
     * @return T
     */
    private function withResticSpec(array $binds, callable $fn): mixed
    {
        // Pemanggil yang menyuplai `env_file` sendiri mengelola kredensialnya
        // (override eksplisit dihormati) — jangan tulis env-file kedua.
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
        // Resolusi fail-fast di sini agar semua jalur (backup/forget/snapshots)
        // memakai image yang sama — bukan menebak di dalam `docker run`.
        // `apply()` sekaligus mengisi `dns` dari `HostConfig.Dns` dashboard
        // (best-effort; override eksplisit dihormati) agar helper restic tetap
        // bisa me-resolve S3 meski resolv.conf host rusak.
        return $this->imageResolver->apply(ResticRunner::normalizeSpec($spec));
    }

    /**
     * Apakah ada container yang benar-benar `running` dalam daftar.
     *
     * @param array<int,array{running?:bool}> $containers
     */
    private static function anyRunning(array $containers): bool
    {
        foreach ($containers as $container) {
            if (!empty($container['running'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array{state?:string,running?:bool}> $containers
     */
    private static function containerState(array $containers): string
    {
        if ($containers === []) {
            return 'none';
        }
        foreach ($containers as $container) {
            if (!empty($container['running'])) {
                return 'running';
            }
        }
        foreach ($containers as $container) {
            $state = (string) ($container['state'] ?? '');
            if ($state !== '') {
                return $state;
            }
        }
        return 'unknown';
    }

    /**
     * Jumlah snapshot live per volume.
     *
     * `null` = **tidak diketahui** (fitur mati / repo-image-passphrase belum
     * lengkap / Engine-restic tak terjangkau). `[]` = repo terjangkau tetapi
     * **tak ada snapshot ber-tag volume** (kosong / salah bucket) — tak dapat
     * dibedakan dari "seluruh snapshot habis", jadi diperlakukan sama seperti
     * "tidak diketahui". Keduanya **tidak** memicu prune riwayat (guard di
     * `BackupRegistry::syncCounts()`); prune hanya sah bila peta non-kosong.
     * Pemanggil tetap memakai `[]` untuk kolom `snapshots` (bernilai 0).
     *
     * @return array<string,int>|null
     */
    private function snapshotCountsByVolume(): ?array
    {
        if (!(bool) config('deploy.volume_backup_enabled', true)) {
            return null;
        }
        try {
            return $this->withResticSpec([], function (array $spec): ?array {
                if ((string) $spec['repository'] === '' || (string) $spec['image'] === '' || !is_file((string) $spec['password_file'])) {
                    return null;
                }

                // Batasi panggilan hitung snapshot: ini bagian dari respons status
                // interaktif, bukan run backup — jangan pakai `volume_backup_timeout`
                // (3600 dtk). Timeout → RuntimeException → catch di bawah → `null`.
                $spec['timeout'] = self::SNAPSHOT_COUNT_TIMEOUT;

                $rows = (new ResticRunner($this->process, $spec))->snapshots([]);

                $counts = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ((array) ($row['tags'] ?? []) as $tag) {
                        $tag = (string) $tag;
                        if (str_starts_with($tag, 'volume:')) {
                            $name = substr($tag, strlen('volume:'));
                            if ($name !== '') {
                                $counts[$name] = ($counts[$name] ?? 0) + 1;
                            }
                        }
                    }
                }

                return $counts;
            });
        } catch (\Throwable $e) {
            // Repo/image tak terkonfigurasi / Engine tak terjangkau: jumlah
            // snapshot tidak diketahui (bagian lain status tetap tampil).
            return null;
        }
    }

    /**
     * Sinkronkan jumlah snapshot registry (update + prune). **Best-effort** —
     * `null` (tidak diketahui) dilewati. Peta kosong (`[]`) juga **tidak**
     * mem-prune (guard di `BackupRegistry::syncCounts()`): repo yang terjangkau
     * tetapi kosong / salah bucket tak boleh menghapus riwayat.
     *
     * @param array<string,int>|null $counts
     */
    private function syncRegistryCounts(?array $counts): void
    {
        if ($counts === null) {
            return;
        }
        try {
            $this->registry->syncCounts($counts);
        } catch (\Throwable $e) {
            // Registry bersifat best-effort (jangan gagalkan run/refresh).
        }
    }

    /**
     * Catat tiap volume yang **sukses** (`status === 'ok'`) ke registry. Satu
     * tulis untuk seluruh run; `first_backed_up_at` dipertahankan oleh registry.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function recordRegistry(array $rows): void
    {
        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row['status'] ?? '') !== 'ok') {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $entries[$name] = [
                'project' => (string) ($row['project'] ?? ''),
                'app_id' => $row['app_id'] ?? null,
                'app_name' => $row['app_name'] ?? null,
                'strategy' => (string) ($row['strategy'] ?? ''),
                'last_snapshot' => $row['snapshot_id'] ?? null,
                'bytes' => (int) ($row['bytes'] ?? 0),
            ];
        }
        if ($entries === []) {
            return;
        }

        try {
            $this->registry->upsertMany($entries);
        } catch (\Throwable $e) {
            // Registry bersifat best-effort (jangan gagalkan run).
        }
    }

    private function logLine(string $project, string $message): void
    {
        ($this->logger)($project, $message);
    }
}
