<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Support\ProcessRunner;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pembungkus restic untuk backup volume ke S3 (PLAN_VOLUME_BACKUP.md §5.3).
 *
 * restic **tidak** dijalankan di container dashboard (dashboard tidak melihat
 * filesystem volume app), melainkan di **helper container**:
 *
 *   docker run --rm [--network …] [--env-file …] \
 *     -v <volume>:/data:ro -v <passphrase-file>:/restic-password:ro \
 *     <image> restic --password-file /restic-password --repo <repo> <args…>
 *
 * Aturan keras yang ditegakkan di sini:
 *  - **argv berbentuk ARRAY** (`ProcessRunner` + `bypass_shell`) — tidak pernah
 *    string shell (larangan #8). Karena itu seluruh pembentukan argv dipisah ke
 *    method **statik murni** (`build*Argv()`) agar bisa diuji tanpa I/O.
 *  - **Tidak ada secret di argv**: passphrase lewat `--password-file` (file
 *    di-mount `:ro`), kredensial S3 lewat `--env-file` (bukan `-e KEY=VALUE`
 *    yang bocor ke `ps`). Repository (non-secret) boleh di argv.
 *  - **Validasi nama** volume & id snapshot (regex) sebelum masuk argv helper.
 *  - Semua spawn lewat `ProcessRunner` → `SigchldGuard` (exit code terbaca).
 *
 * Instance: spec (image, repo, path passphrase, binds) di-inject agar teruji;
 * default dibaca dari `config('deploy.volume_backup_*')` / `config('deploy.restic_*')`.
 * Satu instance = satu volume/bind (dibuat per target oleh service).
 */
class ResticRunner
{
    /** Path mount data di dalam helper container. */
    public const DATA_PATH = '/data';

    /** Path mount passphrase restic di dalam helper container (read-only). */
    public const PASSWORD_MOUNT = '/restic-password';

    /** Pola nama volume Docker yang boleh masuk argv helper. */
    public const VOLUME_NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/';

    /** Pola id snapshot restic (short 8 atau penuh 64 hex). */
    public const SNAPSHOT_ID_PATTERN = '/^[0-9a-fA-F]{4,64}$/';

    /** Kunci `--keep-*` restic forget yang diizinkan. */
    private const KEEP_KEYS = ['last', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'];

    private ProcessRunner $runner;

    /** @var array<string,mixed> */
    private array $spec;

    /**
     * @param array<string,mixed> $spec override spec (mis. `['binds' => ['vol:/data:ro']]`);
     *        kunci yang tak dikenal diabaikan normalisasi.
     */
    public function __construct(?ProcessRunner $runner = null, array $spec = [])
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->spec = self::normalizeSpec($spec);
    }

    /**
     * Spec efektif (setelah digabung dengan default config) — dipakai
     * `VolumeBackupService` untuk membuat runner per volume.
     *
     * @return array<string,mixed>
     */
    public function spec(): array
    {
        return $this->spec;
    }

    // ==================================================================
    // Eksekusi (instance)
    // ==================================================================

    /**
     * `restic backup` untuk `$paths` (path di dalam helper container, mis. `/data`).
     *
     * @param array<int,string> $paths
     * @param array<int,string> $tags
     * @param string            $host nama host restic ('' = pakai hostname helper)
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     * @throws RuntimeException bila restic keluar non-zero / timeout
     */
    public function backup(array $paths, array $tags, string $host): array
    {
        return $this->runArgv(self::buildBackupArgv($this->spec, $paths, $tags, $host));
    }

    /**
     * `restic snapshots --json` (opsional difilter tag).
     *
     * @param array<int,string> $tags
     * @return array<int,array> daftar snapshot hasil parse JSON
     * @throws RuntimeException bila restic gagal atau keluaran JSON tidak valid
     */
    public function snapshots(array $tags): array
    {
        $result = $this->runArgv(self::buildSnapshotsArgv($this->spec, $tags));
        $data = json_decode($result['stdout'], true);
        if (!is_array($data)) {
            throw new RuntimeException('Keluaran `restic snapshots --json` tidak bisa dibaca.');
        }
        return array_values(array_filter($data, static fn ($row): bool => is_array($row)));
    }

    /**
     * `restic restore <snapshot> --target <target>` (target path di container helper).
     *
     * **Tidak** memakai `--delete` — jalur ini untuk restore **dump** ke direktori
     * sementara (`/restore`), bukan menimpa volume. Restore snapshot volume
     * memakai `buildRestoreArgv(..., delete: true, include: DATA_PATH)` agar
     * berkas basi ikut terhapus.
     *
     * @param string $target path di dalam helper (mis. `/data`) — divalidasi
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     * @throws RuntimeException bila restic gagal / timeout
     */
    public function restore(string $snapshotId, string $target): array
    {
        return $this->runArgv(self::buildRestoreArgv($this->spec, $snapshotId, $target));
    }

    /**
     * `restic forget --prune --keep-*` (retensi).
     *
     * @param array<string,int> $keep mis. `['daily' => 7, 'weekly' => 4, 'monthly' => 3]`
     * @param array<int,string> $tags
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     * @throws RuntimeException bila restic gagal / timeout
     */
    public function forget(array $keep, array $tags): array
    {
        return $this->runArgv(self::buildForgetArgv($this->spec, $keep, $tags));
    }

    /**
     * `restic check` — diagnostik **tidak melempar** (dipakai UI/CLI untuk
     * melaporkan kesehatan repo).
     *
     * @return array{ok:bool,output:string,code:int}
     */
    public function check(): array
    {
        try {
            $argv = self::buildCheckArgv($this->spec);
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage(), 'code' => -1];
        }

        $result = $this->runner->run($argv, null, (int) $this->spec['timeout']);
        $output = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

        return [
            'ok' => $result['code'] === 0 && !$result['timedOut'],
            'output' => $output !== '' ? $output : ('exit code ' . $result['code']),
            'code' => (int) $result['code'],
        ];
    }

    /**
     * Jalankan helper + fail-fast bila exit code non-zero / timeout.
     *
     * @param array<int,string> $argv
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function runArgv(array $argv): array
    {
        $this->assertSpecReady($this->spec);
        $result = $this->runner->run($argv, null, (int) $this->spec['timeout']);
        if ($result['code'] !== 0 || $result['timedOut']) {
            $message = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            if ($result['timedOut']) {
                $message = 'timeout setelah ' . (int) $this->spec['timeout'] . ' detik';
            }
            throw new RuntimeException('restic gagal: ' . ($message !== '' ? $message : 'exit code ' . $result['code']));
        }
        return $result;
    }

    // ==================================================================
    // Pembentuk argv (statik murni — tanpa I/O)
    // ==================================================================

    /**
     * argv lengkap helper container: `docker run --rm … <image> restic <args>`.
     *
     * @param array<string,mixed>  $spec
     * @param array<int,string>    $resticArgs argumen restic (setelah binary)
     * @return array<int,string>
     */
    public static function buildHelperArgv(array $spec, array $resticArgs): array
    {
        $spec = self::normalizeSpec($spec);

        $image = (string) $spec['image'];
        if ($image === '') {
            throw new InvalidArgumentException(
                'Image helper backup belum dikonfigurasi (VOLUME_BACKUP_IMAGE atau image container dashboard).'
            );
        }

        $argv = [(string) $spec['docker'], 'run', '--rm'];

        $entrypoint = (string) $spec['entrypoint'];
        if ($entrypoint !== '') {
            $argv[] = '--entrypoint';
            $argv[] = $entrypoint;
        }
        $network = (string) $spec['network'];
        if ($network !== '') {
            $argv[] = '--network';
            $argv[] = $network;
        }
        foreach ((array) $spec['dns'] as $dns) {
            if ((string) $dns !== '') {
                $argv[] = '--dns';
                $argv[] = (string) $dns;
            }
        }

        // Kredensial S3: hanya lewat file (0600) yang dibaca docker CLI — nilai
        // secret TIDAK pernah muncul di argv (`ps` aman).
        $envFile = (string) $spec['env_file'];
        if ($envFile !== '') {
            $argv[] = '--env-file';
            $argv[] = $envFile;
        }

        foreach ((array) $spec['binds'] as $bind) {
            $bind = (string) $bind;
            if ($bind !== '') {
                $argv[] = '-v';
                $argv[] = $bind;
            }
        }

        // Passphrase restic: file di-mount read-only, dirujuk via --password-file.
        $passwordFile = (string) $spec['password_file'];
        if ($passwordFile !== '') {
            $argv[] = '-v';
            $argv[] = $passwordFile . ':' . self::PASSWORD_MOUNT . ':ro';
        }

        $argv[] = $image;

        $binary = (string) $spec['restic_binary'];
        if ($binary !== '') {
            $argv[] = $binary;
        }
        if ($passwordFile !== '') {
            $argv[] = '--password-file';
            $argv[] = self::PASSWORD_MOUNT;
        }
        $repository = (string) $spec['repository'];
        if ($repository !== '') {
            $argv[] = '--repo';
            $argv[] = $repository;
        }

        foreach ($resticArgs as $arg) {
            $argv[] = (string) $arg;
        }

        return $argv;
    }

    /**
     * `… restic backup <paths…> --tag … --host …`
     *
     * @param array<string,mixed> $spec
     * @param array<int,string>   $paths path di dalam helper (divalidasi)
     * @param array<int,string>   $tags
     * @return array<int,string>
     */
    public static function buildBackupArgv(array $spec, array $paths, array $tags, string $host = ''): array
    {
        if ($paths === []) {
            throw new InvalidArgumentException('restic backup butuh minimal satu path.');
        }
        $args = ['backup'];
        foreach ($paths as $path) {
            $args[] = self::assertContainerPath((string) $path);
        }
        foreach ($tags as $tag) {
            $args[] = '--tag';
            $args[] = self::assertTag((string) $tag);
        }
        if ($host !== '') {
            $args[] = '--host';
            $args[] = self::assertTag($host);
        }

        return self::buildHelperArgv($spec, $args);
    }

    /**
     * `… restic snapshots --json [--tag …]`
     *
     * @param array<string,mixed> $spec
     * @param array<int,string>   $tags
     * @return array<int,string>
     */
    public static function buildSnapshotsArgv(array $spec, array $tags = []): array
    {
        $args = ['snapshots', '--json'];
        foreach ($tags as $tag) {
            $args[] = '--tag';
            $args[] = self::assertTag((string) $tag);
        }

        return self::buildHelperArgv($spec, $args);
    }

    /**
     * `… restic restore <snapshot> --target <target> [--delete --include <filter>]`
     *
     * `$delete = true` mengaktifkan **penghapusan berkas basi**: berkas di dalam
     * `$include` yang tidak ada di snapshot ikut dihapus sehingga volume
     * benar-benar kembali ke keadaan snapshot (PLAN_VOLUME_BACKUP.md §2/§5.3 —
     * "isi volume ditimpa"). Tanpa ini restic hanya **menambah/menimpa** berkas
     * snapshot dan membiarkan berkas asing tetap ada.
     *
     * restic **menolak** `--delete` tanpa filter (`'--target … --delete' must be
     * combined with an include or exclude filter`), jadi `$include` wajib diisi
     * saat `$delete = true`. Filter di sini adalah `$include` = path mount volume
     * di helper (`ResticRunner::DATA_PATH`, `/data`), **bukan** path lain, agar
     * penghapusan terbatas pada isi volume dan filesystem helper (mis.
     * `/etc/hostname`) tidak pernah tersentuh.
     *
     * Default `$delete = false` mempertahankan perilaku jalur restore **dump**
     * (target `/restore`) yang tidak boleh menghapus apa pun di luar dirinya.
     *
     * @param array<string,mixed> $spec
     * @return array<int,string>
     * @throws InvalidArgumentException `--delete` tanpa `--include`
     */
    public static function buildRestoreArgv(
        array $spec,
        string $snapshotId,
        string $target,
        bool $delete = false,
        string $include = ''
    ): array {
        $args = [
            'restore',
            self::assertSnapshotId($snapshotId),
            '--target',
            self::assertRestoreTarget($target),
        ];
        if ($delete) {
            if ($include === '') {
                throw new InvalidArgumentException(
                    'restic restore --delete wajib disertai --include (restic menolak --delete tanpa filter include/exclude).'
                );
            }
            $args[] = '--delete';
        }
        if ($include !== '') {
            $args[] = '--include';
            $args[] = self::assertContainerPath($include);
        }

        return self::buildHelperArgv($spec, $args);
    }

    /**
     * `… restic forget --prune --keep-daily N … [--tag …]`
     *
     * @param array<string,mixed> $spec
     * @param array<string,int>   $keep
     * @param array<int,string>   $tags
     * @return array<int,string>
     */
    public static function buildForgetArgv(array $spec, array $keep, array $tags = []): array
    {
        $args = ['forget', '--prune'];
        foreach ($keep as $key => $value) {
            $key = strtolower(trim((string) $key));
            if (!in_array($key, self::KEEP_KEYS, true)) {
                throw new InvalidArgumentException("Kunci retensi tidak dikenal: \"{$key}\".");
            }
            if ((int) $value > 0) {
                $args[] = '--keep-' . $key;
                $args[] = (string) (int) $value;
            }
        }
        foreach ($tags as $tag) {
            $args[] = '--tag';
            $args[] = self::assertTag((string) $tag);
        }

        return self::buildHelperArgv($spec, $args);
    }

    /**
     * `… restic check`
     *
     * @param array<string,mixed> $spec
     * @return array<int,string>
     */
    public static function buildCheckArgv(array $spec): array
    {
        return self::buildHelperArgv($spec, ['check']);
    }

    // ==================================================================
    // Helper statik murni
    // ==================================================================

    /**
     * Bind mount sebuah named volume ke `/data` (`ro` untuk backup, `rw` untuk
     * restore). Nama volume divalidasi lebih dulu.
     */
    public static function volumeBind(string $volume, string $mode = 'ro'): string
    {
        self::assertVolumeName($volume);
        $mode = $mode === 'rw' ? 'rw' : 'ro';
        return $volume . ':' . self::DATA_PATH . ':' . $mode;
    }

    /**
     * Nama volume Docker yang aman dipakai sebagai argumen helper.
     *
     * @throws InvalidArgumentException
     */
    public static function assertVolumeName(string $volume): string
    {
        if (!preg_match(self::VOLUME_NAME_PATTERN, $volume)) {
            throw new InvalidArgumentException("Nama volume tidak valid: \"{$volume}\".");
        }
        return $volume;
    }

    /**
     * Id snapshot restic (hex pendek/penuh).
     *
     * @throws InvalidArgumentException
     */
    public static function assertSnapshotId(string $snapshotId): string
    {
        if (!preg_match(self::SNAPSHOT_ID_PATTERN, $snapshotId)) {
            throw new InvalidArgumentException("Id snapshot tidak valid: \"{$snapshotId}\".");
        }
        return $snapshotId;
    }

    /**
     * Path di dalam helper container (absolut, tanpa `..`).
     *
     * @throws InvalidArgumentException
     */
    public static function assertContainerPath(string $path): string
    {
        if (!preg_match('#^/[A-Za-z0-9._/-]*$#', $path) || str_contains($path, '..')) {
            throw new InvalidArgumentException("Path container tidak valid: \"{$path}\".");
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            throw new InvalidArgumentException('Path container tidak boleh root (\"/\").');
        }
        return $path;
    }

    /**
     * Path target `--target` untuk `restic restore`.
     *
     * Berbeda dari `assertContainerPath()`: **akar `/` sah** sebagai target
     * karena restore snapshot volume menulis ke akar volume yang di-mount
     * (`restic` mereproduksi path absolut snapshot, `/data/…`). `/` **hanya**
     * diperbolehkan di sini, dan jalur snapshot selalu memasangkannya dengan
     * `--include` (mount volume) sehingga filesystem helper tidak tersentuh.
     *
     * @throws InvalidArgumentException
     */
    public static function assertRestoreTarget(string $target): string
    {
        if ($target === '/') {
            return '/';
        }
        return self::assertContainerPath($target);
    }

    /**
     * Tag restic (`project:…`, `volume:…`, nama host, dsb).
     *
     * @throws InvalidArgumentException
     */
    public static function assertTag(string $tag): string
    {
        if ($tag === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $tag)) {
            throw new InvalidArgumentException("Tag restic tidak valid: \"{$tag}\".");
        }
        return $tag;
    }

    /**
     * Ambil id snapshot dari keluaran `restic backup` (teks `snapshot <id> saved`
     * **atau** baris JSON `{"…","snapshot_id":"…"}`). Null bila tidak ditemukan.
     */
    public static function parseSnapshotId(string $output): ?string
    {
        $found = null;
        if (preg_match_all('/"snapshot_id"\s*:\s*"([0-9a-fA-F]{4,64})"/', $output, $m) > 0) {
            $found = end($m[1]) ?: null;
        }
        if ($found === null && preg_match('/snapshot ([0-9a-fA-F]{4,64}) saved/i', $output, $m) === 1) {
            $found = $m[1];
        }
        return is_string($found) && $found !== '' ? strtolower($found) : null;
    }

    /**
     * Default spec dari config (tanpa I/O ke Engine).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function normalizeSpec(array $overrides): array
    {
        $defaults = [
            'docker' => (string) config('deploy.docker_binary', 'docker'),
            'image' => (string) config('deploy.volume_backup_image', ''),
            'repository' => (string) config('deploy.restic_repository', ''),
            'password_file' => (string) config('deploy.restic_password_file', ''),
            'env_file' => '',
            'entrypoint' => '',
            'restic_binary' => 'restic',
            'network' => '',
            'dns' => [],
            'binds' => [],
            'timeout' => (int) config('deploy.volume_backup_timeout', 3600),
        ];

        $spec = array_replace($defaults, array_intersect_key($overrides, $defaults));

        $spec['dns'] = array_values(array_filter(array_map('strval', (array) $spec['dns'])));
        $spec['binds'] = array_values(array_filter(array_map('strval', (array) $spec['binds'])));
        $spec['timeout'] = max(1, (int) $spec['timeout']);

        return $spec;
    }

    /**
     * Fail-fast sebelum spawn: image & repository wajib ada, passphrase harus
     * file nyata (kalau kosong, restic akan menunggu input interaktif — di
     * worker persistent itu menggantung).
     *
     * @param array<string,mixed> $spec
     * @throws RuntimeException
     */
    private function assertSpecReady(array $spec): void
    {
        if ((string) $spec['image'] === '') {
            // Resolusi image (override → image container dashboard) adalah tugas
            // `HelperImageResolver` yang dipanggil service *sebelum* runner dibuat.
            // Bila sampai di sini spec kosong, itu berarti resolusi dilewati.
            throw new RuntimeException(
                'Image helper backup belum ditentukan (VOLUME_BACKUP_IMAGE atau image container dashboard).'
            );
        }
        if ((string) $spec['repository'] === '') {
            throw new RuntimeException('RESTIC_REPOSITORY belum dikonfigurasi — backup volume tidak bisa dijalankan.');
        }
        $passwordFile = (string) $spec['password_file'];
        if ($passwordFile === '') {
            throw new RuntimeException('RESTIC_PASSWORD_FILE belum dikonfigurasi — passphrase restic wajib lewat file.');
        }
        if (!is_file($passwordFile)) {
            throw new RuntimeException("File passphrase restic tidak ditemukan: {$passwordFile}.");
        }
    }
}
