<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Deploy\DeployerInterface;
use app\library\Docker\AppPorts;
use app\library\Nginx\NginxReloader;
use RuntimeException;

/**
 * Pastikan vhost HTTP-01 **sudah dimuat Nginx** SEBELUM certbot diminta
 * menerbitkan sertifikat (HTTP-01 webroot).
 *
 * Kenapa ini ada: certbot menulis berkas challenge ke webroot
 * (`<webroot>/.well-known/acme-challenge/<token>`), tetapi yang MENYAJIKANNYA
 * adalah Nginx **host**, yang membaca `root` dari blok
 * `location ^~ /.well-known/acme-challenge/` pada vhost yang **sedang dimuat**.
 * Vhost basi (mis. masih menunjuk webroot lama atau installasi di `$PWD` lain)
 * membuat nginx menyajikan path itu dari direktori yang tidak memuat berkas
 * certbot ⇒ Let's Encrypt menerima `Invalid response … 404` walau berkasnya
 * benar-benar ada di disk dan permintaan `/.well-known/acme-challenge/` dijawab
 * `403` (direktori ada, root-nya salah).
 *
 * Karena itu jalur penerbitan SSL (`cli/deploy.php` job `ssl_issue` di server
 * agent, `cli/ssl.php` di server lokal) **wajib** menulis ulang vhost lalu
 * me-reload Nginx lebih dulu. Bila reload di sini gagal, penerbitan DIBATALKAN
 * (fatal): berbeda dengan perilaku mode `nginx_write`
 * (`testNginxWriteModeIsNotFailedByAReloadError`) yang best-effort & non-fatal —
 * di mode itu config sudah benar di disk dan kegagalan reload hanya berarti
 * perubahan belum aktif, sedangkan di sini certbot akan pasti gagal dan
 * membuang jatah rate limit Let's Encrypt.
 */
class SslChallengeVhost
{
    private NginxReloader $reloader;

    public function __construct(?NginxReloader $reloader = null)
    {
        $this->reloader = $reloader ?? new NginxReloader();
    }

    /**
     * Tulis ulang config Nginx app lalu reload Nginx host (fatal bila gagal).
     *
     * Urutan disengaja:
     *  1. guard port host — app tanpa host port tidak punya vhost sama sekali;
     *     `writeNginxConfig()` justru akan MENGHAPUS vhost app itu, jadi
     *     jangan dipanggil. Ini **warn + skip**, bukan fatal: data container
     *     yang basi (port belum terdeteksi) tidak boleh mematikan jalur SSL
     *     yang sebelumnya bekerja — HTTP-01 tetap memakai vhost yang sudah
     *     dimuat Nginx;
     *  2. tulis ulang vhost (webroot blok acme = webroot yang dipakai certbot
     *     sekarang);
     *  3. reload Nginx host — tanpa langkah ini berkas config baru tidak dimuat
     *     dan certbot hanya akan melihat vhost lama.
     *
     * @param array $app    dokumen app (`apps.json`/proyeksi agent)
     * @param DeployerInterface $deployer deployer server tempat app berjalan
     * @param callable $logger callable(string $stage, string $message): void
     * @throws RuntimeException reload Nginx gagal
     */
    public function ensure(array $app, DeployerInterface $deployer, callable $logger): void
    {
        if (!AppPorts::hasHostPort($app)) {
            $logger('nginx', 'Tidak ada host port terdeteksi pada data container app — vhost TIDAK ditulis ulang '
                . '(writeNginxConfig akan menghapusnya). HTTP-01 memakai vhost yang sudah dimuat; bila penerbitan '
                . 'gagal 404, Deploy Ulang app lebih dulu.');

            return;
        }

        $logger('nginx', 'Menulis ulang config Nginx sebelum penerbitan '
            . '(blok acme harus dimuat dari webroot sekarang) ...');
        $deployer->writeNginxConfig($app);

        $result = $this->reloader->reload();
        if (($result['ok'] ?? false) !== true) {
            $detail = trim((string) ($result['error'] ?? ''));

            throw new RuntimeException(
                'Reload Nginx sebelum penerbitan SSL GAGAL — blok acme tidak akan dimuat, penerbitan dibatalkan: '
                . ($detail !== '' ? $detail : 'tanpa detail')
            );
        }
    }
}
