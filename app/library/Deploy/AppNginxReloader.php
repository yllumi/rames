<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Agent\AgentClient;
use app\library\Nginx\NginxReloader;
use RuntimeException;

/**
 * Reload Nginx **di server pemilik app** (multi-server F2, lanjutan).
 *
 * `NginxConfigGuard` membutuhkan reloader (`NginxReloader`) untuk alur "uji dulu
 * (nginx -t), rollback bila gagal". Untuk app di server `kind=agent`, reloader
 * **lokal** adalah bug Tinggi: reload privileged dijalankan di host control
 * plane, dan bila gagal guard akan me-rollback subdomain/rute app yang
 * sesungguhnya dilayani server lain. Kelas ini menggantikannya:
 *
 *  - app di server **lokal** ⇒ perilaku lama persis (`NginxReloader` yang sama);
 *  - app di server **agent** ⇒ job `nginx_reload` di server itu lewat
 *    {@see AgentDeployer::reloadNginx()} — server agent menjalankan `nginx -t` +
 *    `nginx -s reload` miliknya sendiri, dan **tidak ada** proses Nginx/Docker
 *    host control plane yang disentuh;
 *  - server `server_id` tak dikenal / bukan agent ⇒ **melempar** (fail-fast,
 *    bukan diam-diam reload host lokal).
 *
 * `reload()` sengaja **tidak** melempar kegagalan reload agent: `NginxConfigGuard`
 * memakai nilai `ok` untuk memutuskan rollback dan melaporkan pesannya ke user
 * (kontrak guard), sedangkan kegagalan transport/op tak didukung tetap terlihat
 * sebagai `ok=false` + pesan aslinya (tidak pernah "sukses palsu").
 *
 * Extends `NginxReloader` (bukan final, pola `FakeGuardReloader` di tes) supaya
 * bisa diserahkan apa adanya ke {@see \app\library\Nginx\NginxConfigGuard} —
 * tidak ada logika guard yang disalin. Tanpa state lintas-request; seluruh
 * dependensi masuk lewat konstruktor (seam uji: `$app`, resolver, klien agent,
 * reloader lokal pengganti).
 */
final class AppNginxReloader extends NginxReloader
{
    /** @var array<string,mixed> */
    private readonly array $app;

    private readonly ServerResolver $resolver;

    /** Klien agent yang sudah jadi (seam uji; null ⇒ dibangun dari dokumen server). */
    private readonly ?AgentClient $client;

    /** Reloader lokal pengganti (seam uji; null ⇒ `NginxReloader` bawaan). */
    private readonly ?NginxReloader $localReloader;

    /**
     * @param array<string,mixed> $app dokumen app (dipakai untuk resolve server +
     *                                    konteks job ke agent)
     * @param ServerResolver|null $resolver resolver server (null = store bawaan)
     * @param AgentClient|null    $client   klien agent (seam uji, tanpa jaringan)
     * @param NginxReloader|null  $localReloader reloader untuk app lokal (seam uji)
     */
    public function __construct(
        array $app,
        ?ServerResolver $resolver = null,
        ?AgentClient $client = null,
        ?NginxReloader $localReloader = null,
    ) {
        parent::__construct();

        $this->app = $app;
        $this->resolver = $resolver ?? new ServerResolver();
        $this->client = $client;
        $this->localReloader = $localReloader;
    }

    /**
     * Reload Nginx di server pemilik app.
     *
     * @return array{ok:bool,message:string,error:?string,output:string}
     * @throws RuntimeException `server_id` app menunjuk server yang tidak ada /
     *                          server agent yang konfigurasinya cacat (AgentError)
     */
    public function reload(): array
    {
        $server = $this->resolver->serverForApp($this->app);

        if (ServerResolver::isLocal($server)) {
            return $this->localReloader !== null ? $this->localReloader->reload() : parent::reload();
        }

        return $this->reloadAtAgent($server);
    }

    /**
     * Reload lewat job `nginx_reload` di server agent.
     *
     * @param array<string,mixed> $server dokumen server agent
     * @return array{ok:bool,message:string,error:?string,output:string}
     */
    private function reloadAtAgent(array $server): array
    {
        $deployer = DeployerFactory::createFor($server, $this->client);
        if (!$deployer instanceof AgentDeployer) {
            // Tidak mungkin terjadi untuk kind=agent, tapi jangan pernah menebak.
            throw new RuntimeException(
                'Reload Nginx untuk app di server agent dibatalkan: deployer server itu bukan AgentDeployer '
                . '(operasi TIDAK dijalankan di host kontrol ini).'
            );
        }

        try {
            $deployer->reloadNginx($this->app);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Reload Nginx di server agent gagal.',
                'error' => $this->sanitize($e->getMessage()),
                'output' => '',
            ];
        }

        return ['ok' => true, 'message' => 'Reload Nginx di server agent berhasil.', 'error' => null, 'output' => ''];
    }

    /**
     * Pesan galat dari agent dipangkas & token tidak ikut (AgentClient/AgentError
     * sudah menyanitasi token; ini sabuk pengaman panjang pesan).
     */
    private function sanitize(string $message): string
    {
        $message = trim($message);

        return $message === '' ? 'agent tidak melaporkan pesan galat' : substr($message, 0, 300);
    }
}
