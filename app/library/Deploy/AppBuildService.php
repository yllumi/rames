<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Pipeline **build ulang app** (`mode=rebuild` di `cli/deploy.php`) — satu
 * sumber perilaku untuk dua pemanggil:
 *
 *  - dashboard: `POST /apps/{id}/rebuild` (`DeploymentController::rebuild`);
 *  - API eksternal: `POST /api/v1/apps/{slug}/build` (`AppApiController`).
 *
 * Urutan langkah (sengaja fail-fast sebelum efek samping apa pun):
 *  1. app impor project compose eksternal ⇒ tolak (Rames tidak menjalankan
 *     compose untuk app impor, lihat {@see ImportedApp});
 *  2. status `deploying` ⇒ tolak sebagai **busy** (jangan menumpuk worker);
 *  3. app server **lokal** wajib masih punya direktori artefak; app server
 *     agent tidak diperiksa di host ini (checkout-nya hidup di agent);
 *  4. `$guard` (gerbang kredit milik pemanggil) dijalankan — **sebelum** status
 *     diubah atau worker di-spawn;
 *  5. status → `deploying/queued`, spawn worker detached ({@see DeploySpawner}),
 *     lalu kembalikan status awal untuk respons pemanggil.
 *
 * Otorisasi **bukan** tanggung jawab kelas ini: pemanggil wajib memeriksa
 * `AppAccess` lebih dulu (satu pintu hak akses). Stateless terhadap request —
 * aman untuk worker Webman persistent.
 */
final class AppBuildService
{
    public const BUSY_MESSAGE = 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.';
    public const MISSING_DIR_MESSAGE = 'Direktori app tidak ada. App mungkin sudah dihapus.';
    public const SPAWN_FAILED_MESSAGE = 'Gagal menjalankan rebuild.';

    private readonly ?AppStore $store;
    private readonly ?DeploySpawner $spawner;
    private readonly ?string $appsPath;
    private readonly ?ServerResolver $servers;

    /**
     * @param AppStore|null        $store    seam uji: store app temp
     * @param DeploySpawner|null   $spawner  seam uji: spawner dengan launcher palsu
     * @param string|null          $appsPath seam uji: akar direktori app (null = config)
     * @param ServerResolver|null  $servers  seam uji: registry server temp
     */
    public function __construct(
        ?AppStore $store = null,
        ?DeploySpawner $spawner = null,
        ?string $appsPath = null,
        ?ServerResolver $servers = null,
    ) {
        $this->store = $store;
        $this->spawner = $spawner;
        $this->appsPath = $appsPath;
        $this->servers = $servers;
    }

    /**
     * Jalankan build ulang app.
     *
     * @param array<string,mixed> $app
     * @param callable():void|null $guard gerbang tambahan (billing) — dipanggil
     *                                    setelah preflight, sebelum efek samping.
     *                                    Boleh melempar `InsufficientCredits`.
     * @param string               $actorId id user aktor untuk `argv[4]` worker
     * @return array{id:string,status:string,stage:string,message:string}
     * @throws AppBuildBlocked
     */
    public function rebuild(array $app, ?callable $guard = null, string $actorId = ''): array
    {
        $id = trim((string) ($app['id'] ?? ''));
        if ($id === '') {
            throw new AppBuildBlocked('App tidak memiliki id — tidak bisa di-build.');
        }

        try {
            ImportedApp::assertManageable($app, 'Rebuild');
        } catch (RuntimeException $e) {
            throw new AppBuildBlocked($e->getMessage());
        }

        if (($app['status'] ?? '') === 'deploying') {
            throw new AppBuildBlocked(self::BUSY_MESSAGE, true);
        }

        $blocked = $this->artifactsError($app, 'Rebuild');
        if ($blocked !== null) {
            throw new AppBuildBlocked($blocked);
        }

        if ($guard !== null) {
            $guard();
        }

        $store = $this->store();
        $store->update($id, static function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rebuild ...';
            $s['error'] = null;
        });

        if (!$this->spawner()->spawn($id, 'rebuild', null, $actorId)) {
            $store->update($id, static function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rebuild.';
                $s['error'] = 'Gagal spawn worker rebuild.';
            });
            throw new AppBuildBlocked(self::SPAWN_FAILED_MESSAGE);
        }

        return [
            'id' => $id,
            'status' => 'deploying',
            'stage' => 'queued',
            'message' => 'Rebuild dimulai.',
        ];
    }

    /**
     * Pesan penolakan pra-deploy untuk aksi yang membaca artefak app **di host
     * ini** (rebuild/rollback) — `null` bila aksi boleh dilanjutkan.
     *
     * Multi-server F1: app milik server **agent** **tidak** diperiksa — checkout
     * repo-nya hidup di server itu (`AgentArtifactMaterializer`), jadi ketiadaan
     * direktori lokal bukan kesalahan dan bukan alasan menolak; worker yang
     * mengerjakan aksinya lewat `AgentDeployer` di server target. Server lokal
     * mempertahankan pesan lamanya persis.
     *
     * @param array<string,mixed> $app
     * @param string              $feature nama aksi untuk pesan galat
     */
    public function artifactsError(array $app, string $feature): ?string
    {
        try {
            $resolver = $this->servers ?? new ServerResolver();
            if (!ServerResolver::isLocal($resolver->resolveForApp($app))) {
                return null;
            }
        } catch (\Throwable $e) {
            // `server_id` app menunjuk server yang hilang dari registry: tolak
            // eksplisit (bukan 500 mentah) — jangan menyentuh host yang salah.
            return $feature . ' tidak bisa dijalankan: ' . $e->getMessage();
        }

        $root = $this->appsPath ?? (string) config('deploy.apps_path');
        $dir = rtrim($root, '/') . '/' . (string) ($app['name'] ?? '');

        return is_dir($dir) ? null : self::MISSING_DIR_MESSAGE;
    }

    private function store(): AppStore
    {
        return $this->store ?? new AppStore();
    }

    private function spawner(): DeploySpawner
    {
        return $this->spawner ?? new DeploySpawner();
    }
}
