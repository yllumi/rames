<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Db\DbContainerDetector;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Penjaga keadaan container terhadap sebuah volume + jalur stop/start project
 * (PLAN_VOLUME_BACKUP.md §2 aturan D1, §5.3, §7).
 *
 * Aturan keras: **snapshot filesystem hanya sah saat tidak ada container yang
 * me-mount volume**. `assertStopped()` memeriksa lewat Docker Engine dan menolak
 * dengan pesan jelas bila masih ada container `running` — tidak ada jalur paksa
 * dari UI. Run harian memakai `stopProject()` → snapshot → `startProject()`
 * (start ulang dijamin pemanggil lewat `finally`).
 *
 * `stopProject()`/`startProject()` memakai `docker compose stop|start` **tanpa
 * `-v`** (volume tidak pernah dihapus) dan melengkapi argumen (`dir`,
 * compose_files yang masih ada, `--env-file` managed) dengan konvensi yang sama
 * seperti `LocalDeployer` — project = field `name` app, dir = `apps_path/{name}`.
 *
 * Instance (menerima `DockerClient`), bukan statik: operasi menyentuh Engine &
 * filesystem, tetapi semua dependensi dapat di-inject agar teruji.
 */
class VolumeStateGuard
{
    private DockerClient $docker;
    private DockerComposeRunner $compose;
    private AppStore $apps;
    private string $appsPath;
    private EnvManager $env;
    private DbContainerDetector $dbDetector;

    public function __construct(
        ?DockerClient $docker = null,
        ?DockerComposeRunner $compose = null,
        ?AppStore $apps = null,
        ?string $appsPath = null,
        ?EnvManager $env = null,
        ?DbContainerDetector $dbDetector = null,
    ) {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
        $this->compose = $compose ?? new DockerComposeRunner();
        $this->apps = $apps ?? new AppStore();
        $this->appsPath = $appsPath ?? (string) config('deploy.apps_path', '');
        if ($this->appsPath === '') {
            $this->appsPath = dirname((string) config('deploy.database_path', '/tmp')) . '/apps';
        }
        $this->env = $env ?? new EnvManager();
        $this->dbDetector = $dbDetector ?? new DbContainerDetector($this->docker);
    }

    /**
     * Container yang me-mount sebuah named volume.
     *
     * Memakai filter Engine `volume=<nama>` pada `GET /containers/json` (baca
     * murni, satu panggilan) lalu melengkapi `is_db` lewat `DbContainerDetector`
     * — satu-satunya sumber kebenaran deteksi container DB (jangan menduplikasi
     * heuristik image di sini).
     *
     * Kegagalan inspect SATU container tidak menggagalkan seluruh daftar
     * (`is_db` = false pada entri itu → pemanggil memilih strategi `snapshot`,
     * jalur aman).
     *
     * @return array<int,array{id:string,name:string,project:string,state:string,running:bool,is_db:bool}>
     */
    public function containersForVolume(string $volume): array
    {
        self::assertVolumeName($volume);

        $result = [];
        foreach ($this->docker->listContainers(['volume' => [$volume]]) as $container) {
            $id = (string) ($container['Id'] ?? '');
            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            $state = strtolower((string) ($container['State'] ?? ''));
            $result[] = [
                'id' => $id,
                'name' => self::firstName($container['Names'] ?? []),
                'project' => (string) ($labels[VolumeTargetMap::LABEL_PROJECT] ?? ''),
                'state' => $state,
                'running' => $state === 'running',
                'is_db' => $this->isDbContainer($id),
            ];
        }

        return $result;
    }

    /**
     * Tolak snapshot bila masih ada container `running` yang me-mount volume.
     *
     * @throws RuntimeException pesan menyebut volume + nama container yang harus dihentikan
     */
    public function assertStopped(string $volume): void
    {
        $running = array_values(array_filter(
            $this->containersForVolume($volume),
            static fn (array $c): bool => $c['running']
        ));

        if ($running === []) {
            return;
        }

        $names = array_values(array_filter(array_map(
            static fn (array $c): string => (string) $c['name'],
            $running
        ), static fn (string $n): bool => $n !== ''));

        $detail = $names === [] ? 'container tanpa nama' : implode(', ', $names);
        throw new RuntimeException(
            "Snapshot volume \"{$volume}\" ditolak: masih ada container berjalan yang memakai volume ini ({$detail}). "
            . 'Hentikan container tersebut lebih dulu (tidak ada jalur paksa).'
        );
    }

    /**
     * Hentikan seluruh container project (`docker compose stop`, tanpa `-v`).
     *
     * @throws RuntimeException bila project tidak ada di apps.json atau stop gagal
     */
    public function stopProject(string $project): void
    {
        $app = $this->requireApp($project);
        $dir = $this->appDir($project);
        $this->compose->stop($project, $dir, $this->composeFiles($app, $dir), $this->envFile($project));
    }

    /**
     * Hidupkan kembali seluruh container project (`docker compose start`).
     *
     * Pemanggil **wajib** memanggil ini dari blok `finally` (termasuk saat
     * snapshot/upload gagal) agar app tidak tertinggal mati.
     *
     * @throws RuntimeException bila project tidak ada di apps.json atau start gagal
     */
    public function startProject(string $project): void
    {
        $app = $this->requireApp($project);
        $dir = $this->appDir($project);
        $this->compose->start($project, $dir, $this->composeFiles($app, $dir), $this->envFile($project));
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    /**
     * @return array app dari apps.json
     */
    private function requireApp(string $project): array
    {
        self::assertProjectName($project);
        $app = $this->apps->findByName($project);
        if ($app === null) {
            throw new RuntimeException("App \"{$project}\" tidak ditemukan — tidak bisa stop/start project.");
        }
        return $app;
    }

    private function appDir(string $project): string
    {
        return rtrim($this->appsPath, '/') . '/' . $project;
    }

    /**
     * Daftar compose_files yang siap dipakai: buang entri override generated
     * yang filenya sudah hilang (sumber sama dengan `LocalDeployer`).
     *
     * @param array $app
     * @return array<int,string> tidak pernah kosong
     */
    private function composeFiles(array $app, string $dir): array
    {
        $files = (array) ($app['compose_files'] ?? ['docker-compose.yml']);
        if ($files === []) {
            $files = ['docker-compose.yml'];
        }
        return ComposeSource::filterMissingGenerated($dir, $files);
    }

    private function envFile(string $project): ?string
    {
        $path = $this->env->managedPath($project);
        return is_file($path) ? $path : null;
    }

    /**
     * Deteksi container DB lewat `DbContainerDetector` (image/env inspect).
     * Gagal inspect → false (jalur aman: strategi `snapshot`).
     */
    private function isDbContainer(string $id): bool
    {
        if ($id === '') {
            return false;
        }
        try {
            return $this->dbDetector->isDbContainer($this->docker->inspectContainer($id));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Nama container pertama (tanpa slash) dari daftar Names.
     */
    private static function firstName(array $names): string
    {
        foreach ($names as $name) {
            $name = ltrim((string) $name, '/');
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    /**
     * Nama volume Docker: huruf/angka di awal, sisanya huruf/angka/`_`/`.`/`-`.
     * Validasi wajib sebelum nama masuk ke filter Engine / argv helper.
     */
    public static function assertVolumeName(string $volume): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $volume)) {
            throw new RuntimeException("Nama volume tidak valid: \"{$volume}\".");
        }
    }

    /**
     * Nama project compose tidak boleh mengandung pemisah path / traversal
     * (nama dipakai menyusun direktori app). Nama app dashboard sudah dibatasi
     * slug `[a-z0-9-]`; ini pengaman tambahan, bukan pengganti.
     */
    public static function assertProjectName(string $project): void
    {
        if ($project === '' || str_contains($project, '/') || str_contains($project, '\\') || str_contains($project, '..')) {
            throw new RuntimeException("Nama project tidak valid: \"{$project}\".");
        }
    }
}
