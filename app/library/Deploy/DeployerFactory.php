<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Nginx\NginxConfigGenerator;
use RuntimeException;

/**
 * Factory deployer — satu-satunya titik pembuatan DeployerInterface.
 *
 * Sejak multi-server F0 (plan §4) kelas ini juga **sadar server**:
 *  - {@see self::create()} tetap berarti "server lokal" dan berperilaku persis
 *    seperti sebelumnya (pemanggil lama tidak berubah);
 *  - {@see self::createFor()} memilih implementasi berdasarkan dokumen server:
 *    `kind=local` ⇒ {@see LocalDeployer}, `kind=agent` ⇒ {@see AgentDeployer}
 *    (F1) — **tidak pernah** silang;
 *  - {@see self::createForApp()} adalah pintu tunggal untuk F1+ (resolve
 *    `server_id` app lalu pilih implementasi).
 *
 * Kontrak fail-fast yang dikunci (plan §3 butir 4): server `kind=agent` **tidak
 * pernah** dijalankan sebagai `LocalDeployer`. Konfigurasi server agent yang
 * cacat (mis. `base_url` bukan https, token/PEM hilang) melempar `AgentError`
 * **sebelum** efek samping apa pun (bukan fallback ke lokal).
 */
class DeployerFactory
{
    /**
     * Deployer untuk server lokal (perilaku lama).
     */
    public static function create(): DeployerInterface
    {
        return self::localDeployer();
    }

    /**
     * Deployer untuk dokumen server tertentu.
     *
     * - `kind=local` ⇒ {@see LocalDeployer} (jalur yang sama dengan
     *   {@see self::create()}, termasuk hook uji `DEPLOYER_CLASS`);
     * - `kind=agent` ⇒ {@see AgentDeployer} — seluruh eksekusi lewat HTTP ke
     *   agent (job async), tidak ada jalur lokal;
     * - `kind` kosong/tak dikenal ⇒ **RuntimeException** (fail-fast, tidak
     *   menebak "mungkin lokal").
     *
     * @param array<string,mixed> $server dokumen server (butuh `id`/`name` untuk
     *                                      pesan galat, `kind` untuk pemilihan, dan
     *                                      `base_url`/`token`/`tls_cert_pem` untuk
     *                                      membangun klien agent)
     * @param AgentClient|null    $client klien agent yang sudah jadi — seam uji
     *                                      (tanpa jaringan); null ⇒ dibangun dari
     *                                      dokumen server
     * @throws RuntimeException kind tak dikenal / konfigurasi server agent cacat
     *                          (AgentError) — SELALU, agar app di server remote
     *                          tidak jatuh ke runner lokal
     */
    public static function createFor(array $server, ?AgentClient $client = null): DeployerInterface
    {
        $kind = strtolower(trim((string) ($server['kind'] ?? '')));

        if ($kind === 'local') {
            return self::localDeployer();
        }

        $label = self::label($server);

        if ($kind === 'agent') {
            if ($client === null) {
                try {
                    $client = new AgentClient($server);
                } catch (AgentError $e) {
                    // Identitas server wajib ikut supaya admin tahu server mana
                    // yang konfigurasinya cacat (tanpa membocorkan token).
                    throw new AgentError(
                        $label . ': ' . $e->getMessage(),
                        $e->reason
                    );
                }
            }

            return new AgentDeployer($server, $client);
        }

        throw new RuntimeException(
            "{$label} punya `kind` yang tidak dikenal: \"" . $kind . '". Operasi dibatalkan (fail-fast).'
        );
    }

    /**
     * Preflight **ketersediaan nama app di server target** sebelum record app
     * dibuat (`AppController::createAndDeploy()` — satu-satunya jalur create app).
     *
     * Kenapa di factory: pemilihan perilaku per `kind` harus berada di pintu yang
     * sama dengan pemilihan deployer ({@see self::createFor()}), supaya tidak ada
     * jalur create yang lupa memeriksanya.
     *
     *  - `kind=agent` ⇒ delegasi ke {@see AgentDeployer::assertAppTargetAvailable()}
     *    (`POST /apps/check` di server itu). Deployer dibuat lewat
     *    {@see self::createFor()} sehingga konfigurasi server yang cacat tetap
     *    fail-fast seperti biasa, dan klien agent bisa disuntikkan uji;
     *  - `kind=local` ⇒ **no-op yang disengaja**: jalur lokal tidak punya
     *    preflight direktori (direktori `apps/<name>` lokal dibersihkan
     *    `createPreview()`, dan materialisasi lokal tidak memakai penanda
     *    kepemilikan). Ini **bukan** kelalaian — sengaja tidak ada panggilan
     *    jaringan/Docker sama sekali, jadi create app lokal berperilaku persis
     *    seperti sebelumnya;
     *  - `kind` kosong/tak dikenal ⇒ dibiarkan `createFor()` melempar (fail-fast,
     *    tidak menebak "mungkin lokal").
     *
     * @param array<string,mixed> $server dokumen server target (`kind`, `base_url`,
     *                                      `token`, `tls_cert_pem` untuk agent)
     * @param array<string,mixed> $app    dokumen app minimal `{id,name}` — id sudah
     *                                      final (dibuat pemanggil) supaya sama dengan
     *                                      id yang dipakai jalur job nanti
     * @param AgentClient|null    $client klien agent yang sudah jadi (seam uji)
     * @throws RuntimeException nama app tidak bisa dipakai / `kind` tak dikenal /
     *                          konfigurasi server agent cacat (AgentError)
     */
    public static function assertAppTargetAvailable(array $server, array $app, ?AgentClient $client = null): void
    {
        if (strtolower(trim((string) ($server['kind'] ?? ''))) === 'local') {
            return;
        }

        $deployer = self::createFor($server, $client);
        if ($deployer instanceof AgentDeployer) {
            $deployer->assertAppTargetAvailable($app);
        }
    }

    /**
     * Pintu tunggal F1+: resolve server pemilik app (`apps.server_id`, kosong ⇒
     * server lokal) lalu pilih deployer-nya.
     *
     * @param array<string,mixed>  $app      dokumen app (`server_id`)
     * @param ServerResolver|null  $resolver resolver server; null = store bawaan
     *                                       (tes menyuntikkan resolver ber-path temp)
     * @param AgentClient|null     $client   klien agent yang sudah jadi (seam uji)
     * @throws RuntimeException `server_id` app tidak ada di registry, atau
     *                          konfigurasi server agent cacat
     */
    public static function createForApp(
        array $app,
        ?ServerResolver $resolver = null,
        ?AgentClient $client = null,
    ): DeployerInterface {
        $server = ($resolver ?? new ServerResolver())->resolveForApp($app);

        return self::createFor($server, $client);
    }

    /**
     * Label server untuk pesan galat (nama, id) agar mudah didiagnosis.
     *
     * @param array<string,mixed> $server
     */
    private static function label(array $server): string
    {
        $name = trim((string) ($server['name'] ?? ''));
        $id = trim((string) ($server['id'] ?? ''));
        if ($name !== '' && $id !== '') {
            return "Server \"{$name}\" ({$id})";
        }
        if ($name !== '') {
            return "Server \"{$name}\"";
        }

        return $id !== '' ? "Server ({$id})" : 'Server tanpa id';
    }

    /**
     * Konstruksi deployer lokal — satu-satunya tempat `LocalDeployer` dibuat
     * (dipakai `create()` dan `createFor(kind=local)`).
     */
    private static function localDeployer(): DeployerInterface
    {
        // Hook pengujian: override implementasi lewat env DEPLOYER_CLASS
        // (mis. fake deployer pada unit test tanpa daemon Docker). Hanya aktif
        // bila env diset; normalnya memakai LocalDeployer.
        $override = getenv('DEPLOYER_CLASS');
        if ($override !== false && $override !== '' && class_exists($override) && is_callable([$override, 'create'])) {
            return $override::create();
        }

        return new LocalDeployer(
            compose: new DockerComposeRunner(timeout: (int) config('deploy.deploy_timeout', 600)),
            dockerClient: new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')),
            nginx: new NginxConfigGenerator(
                confPath: (string) config('deploy.nginx_conf_path', '/etc/nginx/sites-available'),
                enabledPath: (string) config('deploy.nginx_enabled_path', ''),
            ),
            appsPath: (string) config('deploy.apps_path'),
            env: new EnvManager(),
            network: new NetworkManager(),
        );
    }
}
