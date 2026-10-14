<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Restore **volume arsip** (volume yang sudah dihapus) ke volume BARU bernama
 * `$targetName` (PLAN_VOLUME_BACKUP.md keputusan 1c) — satu implementasi untuk
 * dua pemanggil:
 *
 *  - `cli/backup.php restore-archived …` di **server lokal** (control plane);
 *  - `cli/deploy.php … restore_archived` di **server agent** (multi-server F4
 *    lanjutan, plan §5) — job agent, jadi seluruh pekerjaan (restic + pembuatan
 *    volume) berjalan di server itu, memakai repo restic & daemon server itu.
 *
 * Urutan yang ditegakkan (tidak boleh dilonggarkan):
 *  1. nama volume/snapshot/target divalidasi fail-fast (`VolumeStateGuard` /
 *     `ResticRunner`) **sebelum** efek samping apa pun;
 *  2. kelayakan diputuskan **satu sumber** — `VolumeRestoreService::planArchiveRestore()`
 *     (entri registry ada, strategi `snapshot`, nama target belum dipakai);
 *  3. volume target dibuat ber-label compose-project lewat `ProcessRunner`
 *     (array + `bypass_shell`, tanpa secret di argv);
 *  4. restore dijalankan `VolumeRestoreService::restore()` yang **tetap**
 *     menegakkan `VolumeStateGuard` (`assertStopped`: snapshot filesystem hanya
 *     sah bila tidak ada container yang me-mount volume);
 *  5. gagal setelah volume dibuat ⇒ volume target yang baru dihapus (best-effort)
 *     supaya tidak meninggalkan volume setengah jadi ber-label project; snapshot
 *     sumber tetap utuh di repo.
 *
 * Tanpa state lintas-request (instans dibuat per pemanggilan); dependensi dapat
 * disuntikkan supaya dapat diuji tanpa Docker/restic nyata.
 */
final class VolumeArchiveRestore
{
    private readonly DockerClient $docker;

    private readonly ProcessRunner $process;

    private readonly VolumeRestoreService $restore;

    private readonly BackupRegistry $registry;

    private readonly string $dockerBinary;

    /** @var callable(string,string):void */
    private readonly \Closure $logger;

    /**
     * @param DockerClient|null         $docker       klien Engine server ini (cek nama target)
     * @param ProcessRunner|null        $process      eksekutor argv (`docker volume create/rm`)
     * @param VolumeRestoreService|null $restore      service restore (strategi + guard)
     * @param BackupRegistry|null       $registry     registry arsip (entri volume asal)
     * @param string|null               $dockerBinary binary docker server ini
     * @param callable|null             $logger       `(project, message)` — log progress
     */
    public function __construct(
        ?DockerClient $docker = null,
        ?ProcessRunner $process = null,
        ?VolumeRestoreService $restore = null,
        ?BackupRegistry $registry = null,
        ?string $dockerBinary = null,
        ?callable $logger = null,
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
        $this->process = $process ?? new ProcessRunner();
        $this->registry = $registry ?? new BackupRegistry();
        $this->dockerBinary = $dockerBinary !== null && $dockerBinary !== ''
            ? $dockerBinary
            : (string) config('deploy.docker_binary', 'docker');
        $this->logger = \Closure::fromCallable($logger ?? static function (string $project, string $message): void {
        });
        $this->restore = $restore ?? new VolumeRestoreService(null, null, null, null, null, [], $this->logger);
    }

    /**
     * Jalankan restore arsip **di server ini**.
     *
     * @param string $volume     nama volume arsip (sumber snapshot, sudah dihapus)
     * @param string $snapshot   id snapshot restic
     * @param string $targetName nama volume BARU yang dibuat
     * @return array{message:string,project:string,target:string,volume:string,snapshot:string}
     * @throws RuntimeException kelayakan gagal / Docker-Engine tidak bisa diakses /
     *                          pembuatan volume atau restore gagal
     */
    public function run(string $volume, string $snapshot, string $targetName): array
    {
        VolumeStateGuard::assertVolumeName($volume);
        VolumeStateGuard::assertVolumeName($targetName);
        ResticRunner::assertSnapshotId($snapshot);

        // Entri registry (store `backup` di SQLite server ini) memiliki `project`
        // asal — dipakai sebagai label volume target & konteks log.
        $entry = $this->registry->read()[$volume] ?? null;

        $targetExists = false;
        if ($entry !== null) {
            foreach ($this->docker->listVolumes(['name' => [$targetName]]) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                // Docker Engine API mengembalikan `Name` (kapital); toleransi
                // `name` untuk klien/uji yang menormalkan kunci.
                $name = (string) ($candidate['Name'] ?? $candidate['name'] ?? '');
                if ($name === $targetName) {
                    $targetExists = true;
                    break;
                }
            }
        }

        $plan = VolumeRestoreService::planArchiveRestore($entry, $targetExists);
        if (!$plan['ok']) {
            throw new RuntimeException($plan['msg']);
        }

        $project = (string) $plan['project'];
        VolumeStateGuard::assertProjectName($project);
        $this->log($project, "restore-archived mulai volume={$volume} snapshot={$snapshot} target={$targetName}");

        $created = false;
        try {
            $result = $this->process->run(
                VolumeRestoreService::archiveVolumeCreateArgv($this->dockerBinary, $project, $targetName),
                null,
                60
            );
            if (($result['code'] ?? 1) !== 0) {
                $detail = trim((string) ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
                throw new RuntimeException(
                    'Gagal membuat volume target: ' . ($detail !== '' ? $detail : 'exit ' . (int) $result['code'])
                );
            }
            $created = true;

            // Target sintetis: volume baru, tanpa app (yatim) → strategi `snapshot`.
            // `sourceVolume` = nama volume ASAL agar snapshot (tag `volume:<asal>`)
            // tetap ditemukan walau nama volume target berbeda.
            $target = [
                'name' => $targetName,
                'project' => $project,
                'app_id' => null,
                'app_name' => null,
                'orphaned' => true,
            ];

            $restore = $this->restore->restore($target, null, $snapshot, $volume);
            $message = 'restore-archived selesai: ' . (string) ($restore['message'] ?? 'ok');
            $this->log($project, $message);

            return [
                'message' => $message,
                'project' => $project,
                'target' => $targetName,
                'volume' => $volume,
                'snapshot' => $snapshot,
            ];
        } catch (\Throwable $e) {
            // Bila restore gagal SETELAH volume dibuat: hapus volume target yang
            // baru kita buat (best-effort) agar tidak meninggalkan volume setengah
            // jadi ber-label project yang bisa disalahartikan sebagai data nyata.
            if ($created) {
                $removed = $this->process->run(
                    VolumeRestoreService::archiveVolumeRemoveArgv($this->dockerBinary, $targetName),
                    null,
                    60
                );
                $note = ($removed['code'] ?? 1) === 0
                    ? "volume target \"{$targetName}\" dihapus"
                    : "volume target \"{$targetName}\" GAGAL dihapus — periksa manual";
                $this->log($project, 'restore-archived gagal setelah volume dibuat: ' . $note . ' — ' . $e->getMessage());
            }
            $this->log($project, 'restore-archived GAGAL: ' . $e->getMessage());

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** Tulis satu baris log progress (tanpa kredensial — hanya nama/Pesan). */
    private function log(string $project, string $message): void
    {
        ($this->logger)($project, $message);
    }
}
