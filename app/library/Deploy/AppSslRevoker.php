<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Agent\AgentClient;
use app\library\SSL\SslIssuer;
use RuntimeException;

/**
 * Cabut (revoke) sertifikat SSL **di server pemilik app** (multi-server F2,
 * lanjutan).
 *
 * `SslIssuer` lokal hanya benar untuk app di server lokal: sertifikat app server
 * `kind=agent` ada di `/etc/letsencrypt` server itu (diterbitkan job `ssl_issue`
 * di sana), sehingga memanggil `SslIssuer::revoke()` di host control plane
 * adalah no-op yang menyesatkan — sertifikat di server agent tetap aktif, dan
 * berbahaya bila kelak host lokal punya cert domain yang sama. Kelas ini:
 *
 *  - app di server **lokal** ⇒ `SslIssuer::revoke()` persis seperti sebelumnya;
 *  - app di server **agent** ⇒ job `ssl_revoke` di server itu
 *    ({@see AgentDeployer::revokeSsl()});
 *  - server tak dikenal / agent tanpa dukungan op ⇒ **melempar** dengan pesan
 *    jelas (pemanggil memutuskan cara melaporkannya; tidak ada "sukses palsu").
 *
 * Kegagalan revoke **dilempar**, bukan ditelan: pemanggil lama
 * (`AppController::setDomain()`/`removeDomain()`) memang sudah menangkapnya untuk
 * memberi peringatan ke user, dan perilaku itu dipertahankan.
 *
 * Tanpa state lintas-request; seam uji: resolver server, klien agent, & issuer
 * lokal pengganti.
 */
final class AppSslRevoker
{
    private readonly ServerResolver $resolver;

    private readonly ?AgentClient $client;

    private readonly ?SslIssuer $localIssuer;

    /**
     * @param ServerResolver|null $resolver    resolver server (null = store bawaan)
     * @param AgentClient|null    $client      klien agent (seam uji, tanpa jaringan)
     * @param SslIssuer|null      $localIssuer issuer untuk app lokal (seam uji;
     *                                         null ⇒ `new SslIssuer()`)
     */
    public function __construct(
        ?ServerResolver $resolver = null,
        ?AgentClient $client = null,
        ?SslIssuer $localIssuer = null,
    ) {
        $this->resolver = $resolver ?? new ServerResolver();
        $this->client = $client;
        $this->localIssuer = $localIssuer;
    }

    /**
     * Cabut sertifikat `$domain` di server tempat app berjalan.
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException server app tak dikenal, agent menolak/gagal,
     *                          atau konfigurasi server agent cacat (AgentError)
     */
    public function revoke(array $app, string $domain): void
    {
        $server = $this->resolver->serverForApp($app);

        if (ServerResolver::isLocal($server)) {
            ($this->localIssuer ?? new SslIssuer())->revoke($domain);

            return;
        }

        $deployer = DeployerFactory::createFor($server, $this->client);
        if (!$deployer instanceof AgentDeployer) {
            throw new RuntimeException(
                'Pencabutan sertifikat untuk app di server agent dibatalkan: deployer server itu bukan AgentDeployer '
                . '(sertifikat TIDAK dicabut di host kontrol ini).'
            );
        }

        $deployer->revokeSsl($app, $domain);
    }
}
