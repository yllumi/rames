<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Db\DbConnectionResolver;
use app\library\Db\DbCredentialResolver;
use app\library\Db\DbDump;
use app\library\Docker\DockerClient;
use RuntimeException;

/**
 * Dump logis container DB (Strategi A — PLAN_VOLUME_BACKUP.md §2) ke direktori
 * staging sebelum diunggah restic.
 *
 * Sumber kebenaran yang dipakai ulang (tidak diduplikasi):
 *  - `DbDump`              → perintah dump (`mysqldump`/`mariadb-dump`) di dalam
 *                            container via `docker exec`, kredensial lewat
 *                            `MYSQL_PWD` (bukan argv), stdout di-stream ke file.
 *  - `DbCredentialResolver`→ user/password/database hasil deteksi env container
 *                            (mengutamakan user aplikasi di atas root).
 *  - `DbConnectionResolver`→ port internal server DB.
 *
 * Wajib: container DB dalam keadaan **hidup** (strategi `dump` hanya dipilih
 * `BackupStrategyResolver` saat container DB running). Dump keluar non-zero
 * membuat `DbDump::export()` melempar — kegagalan muncul, bukan dump kosong.
 *
 * Catatan timeout: `timeout()` mengembalikan `VOLUME_BACKUP_DUMP_TIMEOUT`.
 * Dump dieksekusi lewat `DbDump::export()` yang **tidak** menerima parameter
 * timeout (kontrak library `Db` yang ada, sama seperti `DatabaseController::export()`),
 * sehingga batas waktu keras berada di level worker/`systemd` (timer host).
 * Nilai timeout tetap dilaporkan agar pemanggil/CLI dapat menampilkannya.
 *
 * Staging: `{stagingRoot}/{project}/{timestamp}/{database|all-databases}.sql`.
 * `cleanup()` dipanggil pemanggil di blok `finally` (sukses maupun gagal).
 */
class DumpRunner
{
    private DockerClient $docker;
    private DbDump $dump;
    private DbCredentialResolver $credentials;
    private DbConnectionResolver $connections;
    private string $stagingRoot;
    private int $timeout;

    public function __construct(
        ?DockerClient $docker = null,
        ?DbDump $dump = null,
        ?DbCredentialResolver $credentials = null,
        ?DbConnectionResolver $connections = null,
        ?string $stagingRoot = null,
        ?int $timeout = null,
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
        $this->dump = $dump ?? new DbDump();
        $this->credentials = $credentials ?? new DbCredentialResolver();
        $this->connections = $connections ?? new DbConnectionResolver($this->docker);
        $this->stagingRoot = $stagingRoot ?? runtime_path('backup/staging');
        $this->timeout = $timeout ?? (int) config('deploy.volume_backup_dump_timeout', 600);
    }

    public function stagingRoot(): string
    {
        return $this->stagingRoot;
    }

    /**
     * Timeout efektif (detik) untuk fase dump — nilai `VOLUME_BACKUP_DUMP_TIMEOUT`.
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Dump database milik `$container` ke direktori staging.
     *
     * @param string $project   nama project (segmen direktori staging — divalidasi)
     * @param array  $app       entri apps.json pemilik volume (boleh `[]` untuk volume yatim)
     * @param string $container nama container DB (hidup)
     * @param string $timestamp segmen nama direktori (mis. `20260930-023000-ab12cd`)
     * @return array{dir:string,file:string,files:array<int,string>,database:?string,bytes:int,container:string}
     * @throws RuntimeException kredensial tidak terdeteksi / dump gagal
     */
    public function dump(string $project, array $app, string $container, string $timestamp): array
    {
        VolumeStateGuard::assertProjectName($project);
        if ($container === '') {
            throw new RuntimeException('Container DB tidak diketahui — dump dibatalkan.');
        }

        $inspect = $this->docker->inspectContainer($container);
        $profile = $this->credentials->resolve($app, $inspect);
        if ($profile === null) {
            throw new RuntimeException(
                "Kredensial DB tidak dapat dideteksi pada container \"{$container}\" — dump dibatalkan."
            );
        }

        $database = $profile['database'] ?? null;
        if (is_string($database) && $database !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            $database = null; // nama tak aman → dump semua database
        }
        if ($database === '') {
            $database = null;
        }

        $dir = rtrim($this->stagingRoot, '/') . '/' . $project . '/' . $timestamp;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori staging dump: {$dir}.");
        }

        $fileName = ($database ?? 'all-databases') . '.sql';
        $outFile = $dir . '/' . $fileName;

        $dumpProfile = [
            'username' => (string) ($profile['username'] ?? 'root'),
            'password' => (string) ($profile['password'] ?? ''),
            'internal_port' => $this->connections->internalPort($inspect),
        ];

        $result = $this->dump->export($container, $dumpProfile, $database, $outFile);
        if (!is_file($outFile) || (int) ($result['bytes'] ?? 0) <= 0) {
            throw new RuntimeException("Dump database menghasilkan berkas kosong ({$fileName}).");
        }

        return [
            'dir' => $dir,
            'file' => $outFile,
            'files' => [$outFile],
            'database' => $database,
            'bytes' => (int) $result['bytes'],
            'container' => $container,
        ];
    }

    /**
     * Bersihkan direktori staging (default: seluruh `stagingRoot`).
     *
     * Path di luar `stagingRoot` diabaikan (pengaman agar tidak menghapus
     * direktori lain karena bug pemanggil).
     */
    public function cleanup(?string $dir = null): void
    {
        $root = rtrim($this->stagingRoot, '/');
        $target = rtrim($dir ?? $this->stagingRoot, '/');
        if ($target === '' || $root === '') {
            return;
        }
        if ($target !== $root && !str_starts_with($target, $root . '/')) {
            return;
        }
        self::removeTree($target);
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
}
