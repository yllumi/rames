<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Eksekutor backup/restore/dump **di server tempat kelas ini dijalankan**
 * (multi-server F4, plan §4.3/§5).
 *
 * Peran: satu pintu untuk op job agent `backup_run`/`restore`/`db_dump` — seluruh
 * pekerjaan berat (restic, helper container, dump logis) dijalankan memakai
 * daemon & konfigurasi server itu sendiri. Kelas ini **tidak** tahu-menahu soal
 * control plane: ia tidak pernah mengirim kredensial, tidak menerima payload
 * selain nama volume/snapshot/trigger, dan tidak menulis riwayat ke store
 * `backup` — kredensial S3/passphrase dibaca dari konfigurasi server ini
 * (`CredentialEnvFile`) dan hasil run dilaporkan lewat nilai kembalian (dipakai
 * worker untuk log + pesan job).
 *
 * Yang dipertahankan dari jalur lokal (satu sumber kebenaran, tanpa salinan
 * logika):
 *  - {@see VolumeBackupService::run()} — termasuk {@see VolumeStateGuard}
 *    (`assertStopped()`: snapshot filesystem **hanya** sah bila tidak ada
 *    container yang me-mount volume) dan strategi dump/snapshot;
 *  - {@see VolumeRestoreService::restore()} — termasuk validasi kepemilikan
 *    volume & strategi sadar-snapshot;
 *  - {@see DumpRunner} + {@see DbDump} untuk dump logis DB (kredensial hanya lewat
 *    `MYSQL_PWD` di dalam container, bukan argv/log).
 *
 * Kegagalan selalu **dilempar**, tidak pernah dikembalikan sebagai sukses palsu —
 * pemanggil (worker agent) menandai job `error`.
 *
 * Seluruh dependensi dapat disuntikkan (seam uji) sehingga kelas ini dapat diuji
 * tanpa Docker/restic/jaringan: instans dibangun per pemanggilan, tanpa state
 * lintas-request.
 */
final class BackupJobRunner
{
    /** Trigger run yang dikenal (`cli/backup.php` memakai dua nilai yang sama). */
    public const TRIGGERS = ['manual', 'schedule'];

    private readonly DockerClient $docker;

    private readonly AppStore $apps;

    private readonly VolumeBackupService $backup;

    private readonly VolumeRestoreService $restore;

    private readonly DumpRunner $dumper;

    /**
     * @param VolumeBackupService|null  $backup  service backup (default: konfigurasi server ini)
     * @param VolumeRestoreService|null $restore service restore
     * @param DumpRunner|null           $dumper  runner dump logis DB
     * @param AppStore|null             $apps    store app (proyeksi) server ini
     * @param DockerClient|null         $docker  klien Engine server ini
     */
    public function __construct(
        ?VolumeBackupService $backup = null,
        ?VolumeRestoreService $restore = null,
        ?DumpRunner $dumper = null,
        ?AppStore $apps = null,
        ?DockerClient $docker = null,
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
        $this->apps = $apps ?? new AppStore();
        // `recordHistory: false` — runner ini hidup di **server agent**: riwayat
        // backup milik control plane (plan §4.3), agent hanya menjalankan job +
        // menulis log/cache miliknya sendiri.
        $this->backup = $backup ?? new VolumeBackupService(recordHistory: false);
        $this->restore = $restore ?? new VolumeRestoreService();
        $this->dumper = $dumper ?? new DumpRunner($this->docker);
    }

    /**
     * Jalankan backup **satu** volume di server ini.
     *
     * Volume di-validasi fail-fast sebelum menyentuh Engine/restic; trigger di
     * luar daftar {@see self::TRIGGERS} jatuh ke `manual` (bukan melempar — nilai
     * itu hanya memengaruhi pemilihan volume berkala, sedangkan daftar volume di
     * sini **eksplisit**).
     *
     * @param callable(string,string):void|null $log `(project, message)`
     * @return array{volume:string,status:string,message:string,strategy:string,snapshot_id:?string,bytes:int}
     * @throws RuntimeException volume tak dikenal / run gagal
     */
    public function runBackup(string $volume, string $trigger = 'manual', ?callable $log = null): array
    {
        VolumeStateGuard::assertVolumeName($volume);

        $trigger = in_array($trigger, self::TRIGGERS, true) ? $trigger : 'manual';

        $summary = $this->backup->run([
            'trigger' => $trigger,
            'host' => gethostname() ?: 'rames',
            'volumes' => [$volume],
        ]);

        $row = $this->rowForVolume($summary, $volume);
        $status = (string) ($row['status'] ?? 'failed');
        $error = trim((string) ($row['error'] ?? ''));

        if ($status !== 'ok') {
            throw new RuntimeException(
                "Backup volume \"{$volume}\" {$status}"
                . ($error !== '' ? ' — ' . $error : ' — tidak ada laporan sukses dari service backup.')
            );
        }

        $message = sprintf(
            'Backup volume %s ok (strategi %s, %d byte).',
            $volume,
            (string) ($row['strategy'] ?? ''),
            (int) ($row['bytes'] ?? 0)
        );

        if ($log !== null) {
            $log((string) ($row['project'] ?? ''), $message);
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => $message,
            'strategy' => (string) ($row['strategy'] ?? ''),
            'snapshot_id' => isset($row['snapshot_id']) && is_string($row['snapshot_id']) ? $row['snapshot_id'] : null,
            'bytes' => (int) ($row['bytes'] ?? 0),
        ];
    }

    /**
     * Pulihkan satu volume dari snapshot **di server ini**.
     *
     * Target (project/app/orphaned) dipetakan dari label volume Engine server ini
     * (`VolumeTargetMap::build`) — bukan dari parameter pemanggil: nama volume
     * dari request tidak pernah dipercaya menjadi pemilik datanya.
     *
     * @param string|null $appId app pemilik volume; null/'' = volume yatim (admin)
     * @throws RuntimeException volume tak ada/bukan milik app / restore gagal
     */
    public function restoreVolume(string $volume, string $snapshot, ?string $appId = null, ?callable $log = null): array
    {
        VolumeStateGuard::assertVolumeName($volume);
        $snapshot = ResticRunner::assertSnapshotId(trim($snapshot));

        $target = $this->targetFor($volume);
        $appId = $appId !== null ? trim($appId) : '';
        $app = $appId === '' ? null : $this->apps->find($appId);

        if ($appId !== '' && $app === null) {
            throw new RuntimeException("App tidak ditemukan di server ini: {$appId} — restore dibatalkan.");
        }
        if ($app !== null && (string) ($target['app_id'] ?? '') !== $appId) {
            throw new RuntimeException("Volume \"{$volume}\" bukan milik app {$appId} — restore dibatalkan.");
        }
        if ($app === null && empty($target['orphaned'])) {
            throw new RuntimeException("Volume \"{$volume}\" milik app lain — restore dibatalkan.");
        }

        $result = $this->restore->restore($target, $app, $snapshot);
        $message = 'Restore volume ' . $volume . ' selesai: ' . (string) ($result['message'] ?? 'ok');

        if ($log !== null) {
            $log((string) ($target['project'] ?? ''), $message);
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => $message,
            'strategy' => (string) ($result['strategy'] ?? ''),
        ];
    }

    /**
     * Dump logis DB volume **di server ini** (strategi A) ke direktori staging
     * server ini. Berkas `.sql` dibiarkan di staging agar bisa diperiksa/diunduh
     * operator di server itu; **tidak** ada kredensial di nama berkas, log, atau
     * argv (pola `DumpRunner`).
     *
     * @throws RuntimeException tidak ada container DB pada volume / dump gagal
     */
    public function dbDump(string $volume, ?string $appId = null, ?callable $log = null): array
    {
        VolumeStateGuard::assertVolumeName($volume);

        $target = $this->targetFor($volume);
        $appId = $appId !== null ? trim($appId) : '';
        $app = $appId === '' ? null : $this->apps->find($appId);

        $project = (string) ($target['project'] ?? '');
        VolumeStateGuard::assertProjectName($project);

        if ($app !== null && (string) ($target['app_id'] ?? '') !== $appId) {
            throw new RuntimeException("Volume \"{$volume}\" bukan milik app {$appId} — dump dibatalkan.");
        }

        $container = $this->dbContainerFor($volume);
        if ($container === null) {
            throw new RuntimeException(
                "Tidak ada container database pada volume \"{$volume}\" — dump logis dibatalkan (pakai strategi snapshot)."
            );
        }

        $result = $this->dumper->dump($project, $app ?? [], $container, date('Ymd-His'));
        $file = (string) ($result['file'] ?? '');
        $message = sprintf('Dump DB volume %s → %s (%d byte).', $volume, $file, (int) ($result['bytes'] ?? 0));

        if ($log !== null) {
            $log($project, $message);
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => $message,
            'file' => $file,
            'bytes' => (int) ($result['bytes'] ?? 0),
            'database' => $result['database'] ?? null,
        ];
    }

    /**
     * Baris ringkasan volume backup **server ini** (target + strategi + state
     * container + jumlah snapshot) — dipakai control plane agar bisa menampilkan
     * volume yang hidup di server agent tanpa menyentuh Engine control plane.
     *
     * Memakai `VolumeBackupService::overview()` (tanpa menulis apa pun: tidak
     * menulis cache/registry) sehingga definisi strategi/state tetap satu sumber
     * dengan jalur lokal.
     *
     * @return array<int,array<string,mixed>>
     */
    public function volumeRows(): array
    {
        $targets = $this->backup->targets();

        return $this->backup->overview($targets);
    }

    /**
     * Daftar snapshot restic sebuah volume **di server ini** (repo restic milik
     * server ini; kredensial tidak pernah keluar dari server ini).
     *
     * @return array<int,array<string,mixed>>
     */
    public function snapshotsFor(string $volume): array
    {
        VolumeStateGuard::assertVolumeName($volume);

        return $this->backup->snapshotsFor($volume);
    }

    /**
     * Target (project/app/orphaned) sebuah volume dari label Engine server ini.
     *
     * @return array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}
     * @throws RuntimeException volume tidak ada / bukan volume compose terkelola
     */
    private function targetFor(string $volume): array
    {
        $volumes = $this->docker->listVolumes(['name' => [$volume]]);
        $targets = VolumeTargetMap::build($volumes, $this->apps->all());

        foreach ($targets as $target) {
            if ((string) ($target['name'] ?? '') === $volume) {
                return $target;
            }
        }

        throw new RuntimeException(
            "Volume \"{$volume}\" tidak ditemukan di server ini atau bukan volume compose yang dikelola dashboard."
        );
    }

    /** Nama container DB (layak dump) dari volume — `null` bila tidak ada. */
    private function dbContainerFor(string $volume): ?string
    {
        $guard = new VolumeStateGuard($this->docker, null, $this->apps);

        foreach ($guard->containersForVolume($volume) as $container) {
            if (!empty($container['is_db']) && (string) ($container['name'] ?? '') !== '') {
                return (string) $container['name'];
            }
        }

        return null;
    }

    /**
     * Baris `volumes[]` milik satu volume dari ringkasan run.
     *
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     * @throws RuntimeException ringkasan tidak menyebut volume itu sama sekali
     */
    private function rowForVolume(array $summary, string $volume): array
    {
        foreach ((array) ($summary['volumes'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $volume) {
                return $row;
            }
        }

        throw new RuntimeException(
            "Run backup di server ini tidak melaporkan volume \"{$volume}\" — hasil tidak dapat dipastikan."
        );
    }
}
