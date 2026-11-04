<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Agent\AgentClient;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\AppNginxReloader;
use app\library\Deploy\AppSslRevoker;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ServerAddress;
use app\library\Deploy\ServerResolver;
use app\library\Deploy\SubdomainManager;
use app\library\Docker\AppPorts;
use app\library\Nginx\NginxConfigGuard;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\NginxRoutes;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use RuntimeException;
use support\Request;

/**
 * Domain, subdomain, dan rute proxy tambahan per app (§7).
 *
 * Route: `POST /apps/{id}/domain/set`, `/domain/remove`, `/subdomain`,
 * `/routes`. Nama kelas sengaja bukan `DomainController` — itu halaman
 * `/domains` global.
 */
class DomainSettingsController extends AppControllerBase
{
    /**
     * Kalimat penutup flash `setDomain()`: menyebut **target DNS konkret**
     * (alamat publik server pemilik app) plus jenis record (A/AAAA) bila bisa
     * dipastikan, selain itu kalimat generik "ke IP server tempat app ini
     * berjalan". Tidak ada DNS di sini — {@see ServerAddress} hanya membaca
     * registry.
     *
     * `$label` mengikuti badge header: server **lokal** selalu `lokal` (bukan
     * `name` baris seed seperti `lokal-utama`), server agent memakai `name`
     * (fallback `id`). Tidak ada rahasia registry yang ikut.
     *
     * @param array<string,mixed> $app
     */
    private function domainDnsHint(array $app, string $domain): string
    {
        $resolver = $this->serverResolver();

        $label = 'lokal';
        try {
            $server = $resolver->findForApp($app);
            if ($server !== null && !ServerResolver::isLocal($server)) {
                $name = trim((string) ($server['name'] ?? ''));
                $id = trim((string) ($server['id'] ?? ''));
                $label = $name !== '' ? $name : ($id !== '' ? $id : 'lokal');
            }
        } catch (\Throwable) {
            // registry tak terbaca: kalimat generik tetap benar & tidak menyesatkan
        }

        $address = $this->serverAddressForApp($app, $resolver);
        if ($address === '') {
            return 'Jangan lupa arahkan DNS A/AAAA record ' . $domain
                . ' ke IP server tempat app ini berjalan, lalu aktifkan SSL-nya.';
        }

        return 'Jangan lupa arahkan DNS ' . ServerAddress::recordType($address) . ' record ' . $domain
            . ' ke ' . $address . ' (server ' . $label . '), lalu aktifkan SSL-nya.';
    }

    /**
     * Set / ganti custom domain app.
     *
     * Validasi: FQDN publik valid, bukan subdomain bawaan app sendiri, unik di
     * semua app (subdomain & custom domain app lain). Bila ada custom domain
     * lama yang punya sertifikat, di-revoke dulu. Setelah tersimpan, config
     * Nginx ditulis ulang (subdomain → redirect ke custom domain).
     */
    public function setDomain(
        Request $request,
        string $id,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ) {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $domain = strtolower(trim((string) $request->post('domain', '')));
        $subdomain = app_subdomain_of($app);
        $oldCustom = (string) ($app['custom_domain'] ?? '');

        try {
            // App tanpa port terpublish tidak punya target `proxy_pass` — domain
            // tak akan pernah dilayani Nginx (vhost dilewati saat deploy).
            if (!AppPorts::hasHostPort($app)) {
                throw new RuntimeException('App ini tidak mem-publish port host, jadi tidak ada yang bisa di-proxy ke domain. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
            }
            if (!SslIssuer::isPublicDomain($domain)) {
                throw new RuntimeException('Custom domain harus FQDN publik yang valid (mis. example.org).');
            }
            if ($domain === $subdomain) {
                throw new RuntimeException('Custom domain tidak boleh sama dengan subdomain bawaan app.');
            }
            if ($domain === $oldCustom) {
                flash_set('info', 'Custom domain sudah di-set ke ' . $domain . '.');
                return redirect('/apps/' . $id);
            }
            $this->assertDomainUnique($store, $id, $domain);

            // Custom domain lama (bila ada sertifikat) di-revoke sebelum diganti —
            // di server pemilik app (agent ⇒ job `ssl_revoke` di sana).
            if ($oldCustom !== '') {
                try {
                    $this->revokeDomainCert($app, $oldCustom, $localIssuer, $agentClient);
                } catch (\Throwable $e) {
                    flash_set('info', 'Peringatan: gagal revoke sertifikat lama ' . $oldCustom . ' — ' . $this->appErrorText($app, $e));
                }
            }

            $store->update($id, function (array &$s) use ($domain): void {
                $s['custom_domain'] = $domain;
                $s['custom_ssl_status'] = 'disabled';
                $s['custom_ssl_stage'] = null;
                $s['custom_ssl_message'] = null;
                $s['custom_ssl_error'] = null;
                $s['custom_ssl_expires_at'] = null;
                $s['custom_needs_ssl'] = false;
            });

            // Konteks server tidak boleh hilang bila pembacaan ulang gagal:
            // reload/penulisan harus tetap diarahkan ke server app (bukan lokal).
            $app = $store->find($id) ?? $app;
            try {
                $this->applyNginxConfig($app, $agentClient);
            } catch (\Throwable $e) {
                flash_set('error', 'Custom domain ' . $domain . ' tersimpan, tetapi gagal menulis config Nginx: ' . $this->appErrorText($app, $e) . ' (jalankan Rebuild untuk menerapkan).');
                return redirect('/apps/' . $id);
            }

            // Reload Nginx di server app agar reverse proxy custom domain aktif.
            $reloadNote = $this->reloadNginxNote($app, $localReloader, $agentClient);
            flash_set('success', 'Custom domain ' . $domain . ' diset. Subdomain bawaan kini redirect ke domain tersebut.' . ($reloadNote !== '' ? ' ' . $reloadNote : '') . ' ' . $this->domainDnsHint($app, $domain));
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Hapus custom domain app. Sertifikat SSL domain (bila ada) di-revoke;
     * bila revoke gagal, domain tetap dihapus dari config (recovery via Rebuild).
     */
    public function removeDomain(
        Request $request,
        string $id,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ) {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $customDomain = (string) ($app['custom_domain'] ?? '');
        if ($customDomain === '') {
            flash_set('info', 'App tidak memiliki custom domain.');
            return redirect('/apps/' . $id);
        }

        $revokeError = null;
        try {
            // Pencabutan terjadi di server pemilik app (agent ⇒ op `ssl_revoke`).
            $this->revokeDomainCert($app, $customDomain, $localIssuer, $agentClient);
        } catch (\Throwable $e) {
            $revokeError = $this->appErrorText($app, $e);
        }

        $store->update($id, function (array &$s): void {
            $s['custom_domain'] = null;
            $s['custom_ssl_status'] = 'disabled';
            $s['custom_ssl_stage'] = null;
            $s['custom_ssl_message'] = null;
            $s['custom_ssl_error'] = null;
            $s['custom_ssl_expires_at'] = null;
            $s['custom_needs_ssl'] = false;
        });

        // Lihat catatan di `setDomain()`: konteks server dipertahankan walau
        // pembacaan ulang gagal (jangan pernah jatuh ke host lokal).
        $app = $store->find($id) ?? $app;
        try {
            $this->applyNginxConfig($app, $agentClient);
            // Reload Nginx di server app agar subdomain kembali melayani app (best-effort).
            $reloadNote = $this->reloadNginxNote($app, $localReloader, $agentClient);
            if ($reloadNote !== '') {
                $revokeError = ($revokeError ?? '') . ' ' . $reloadNote;
            }
        } catch (\Throwable $e) {
            $revokeError = ($revokeError ?? '') . ' Gagal menulis config Nginx: ' . $this->appErrorText($app, $e);
        }

        if ($revokeError !== null) {
            flash_set('error', 'Custom domain ' . $customDomain . ' dihapus, tetapi: ' . $revokeError . ' (jalankan Rebuild untuk menerapkan config).');
        } else {
            flash_set('success', 'Custom domain ' . $customDomain . ' dihapus. Sertifikat SSL-nya di-revoke.');
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Ubah subdomain app (POST /apps/{id}/subdomain) — ability `domain` (operator+).
     *
     * Field `apps.json.subdomain` menyimpan label slug; subdomain efektif =
     * `{label}.{APP_DOMAIN}` (label kosong = kembali ke `name`). Alur "uji dulu,
     * rollback bila gagal" ditangani {@see SubdomainManager} +
     * {@see NginxConfigGuard::applySubdomain()}: validasi → simpan → tulis config
     * Nginx → `nginx -t` + reload → rollback bila gagal.
     *
     * Sertifikat Let's Encrypt terikat nama domain lama → tidak diterbitkan/
     * dihapus otomatis; status SSL subdomain di-reset dan user diarahkan
     * menerbitkan ulang di tab SSL. Otorisasi dicek lewat `findApp()` (404 bila
     * tak berhak) SEBELUM efek samping apa pun.
     */
    public function setSubdomain(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $label = SubdomainManager::normalize((string) $request->post('subdomain', ''));

        $manager = new SubdomainManager(
            $store,
            new NginxConfigGuard($this->nginxReloaderFor($app, $localReloader, $agentClient), DeployerFactory::createForApp($app, null, $agentClient), $store),
            null,
            new ParkedDomainStore()
        );
        $result = $manager->change($id, $label);

        if ($request->expectsJson()) {
            if (!$result['ok']) {
                return json(['code' => 1, 'error' => $this->appErrorText($app, $result['error']), 'subdomain' => $result['previous']]);
            }
            return json([
                'code' => 0,
                'noop' => $result['noop'],
                'subdomain' => $result['effective'],
                'previous' => $result['previous'],
                'reloaded' => $result['reloaded'],
                'message' => $this->subdomainChangeNote($result),
            ]);
        }

        if (!$result['ok']) {
            flash_set('error', $this->appErrorText($app, $result['error']));
        } elseif ($result['noop']) {
            flash_set('info', 'Subdomain sudah ' . $result['effective'] . ' — tidak ada perubahan.');
        } else {
            flash_set('success', $this->subdomainChangeNote($result));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Pesan hasil perubahan subdomain (dipakai flash & respons JSON).
     *
     * @param array{ok:bool,noop:bool,effective:string,previous:string,reloaded:bool,rolled_back:bool,error:string} $result
     */
    private function subdomainChangeNote(array $result): string
    {
        $note = 'Subdomain app diubah ke ' . $result['effective'] . '.';
        if (!$result['reloaded']) {
            $note .= ' Nginx host belum ter-reload — klik "Reload Nginx".';
        }
        $note .= ' Sertifikat SSL lama tidak lagi cocok untuk domain baru — terbitkan ulang di tab SSL bila app memakai HTTPS.';
        return $note;
    }

    /**
     * Simpan rute proxy tambahan per app (field apps.json `nginx_routes`).
     *
     * Alur "uji dulu, rollback bila gagal" — seluruh orkestrasi di
     * `NginxConfigGuard::applyRoutes()` (controller hanya mediator):
     *   1. simpan `nginx_routes` baru,
     *   2. tulis ulang config Nginx app,
     *   3. jalankan `nginx -t` lalu reload host,
     *   4. bila gagal: kembalikan `nginx_routes` ke nilai sebelumnya + tulis
     *      ulang config lama (guard melaporkan `rolled_back` + pesan nginx).
     *
     * Rute disimpan **terstruktur** (bukan snippet Nginx mentah); parsing &
     * validasi fail-fast seluruhnya di `NginxRoutes`. Textarea kosong = `[]` =
     * hapus semua rute. Kegagalan penerapan tidak meninggalkan config rusak —
     * controller hanya menerjemahkan hasil guard menjadi flash + redirect.
     * Otorisasi dicek lewat `findApp()` (app tak berhak = 404) sebelum efek
     * samping apa pun.
     */
    public function saveRoutes(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'routes');

        $text = (string) $request->post('routes', '');

        try {
            // Prasyarat fail-fast sebelum efek samping: app tanpa host port
            // terpublish tidak punya vhost → rute proxy tak akan pernah dilayani.
            if (!AppPorts::hasHostPort($app)) {
                throw new RuntimeException('App ini tidak mem-publish port host, jadi vhost/subdomain — dan rute proxy — tidak berlaku. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
            }

            $routes = NginxRoutes::parse($text);

            $guard = new NginxConfigGuard(
                $this->nginxReloaderFor($app, $localReloader, $agentClient),
                DeployerFactory::createForApp($app, null, $agentClient),
                $store
            );
            $result = $guard->applyRoutes($id, $routes);

            if (!$result['ok']) {
                flash_set('error', 'Rute proxy TIDAK diterapkan' . ($result['rolled_back'] ? ' — rute dikembalikan ke kondisi sebelumnya' : '') . ': ' . $this->appErrorText($app, $result['error']));
                return redirect('/apps/' . $id);
            }

            $count = count($routes);
            flash_set('success', ($count > 0 ? $count . ' rute proxy disimpan & diterapkan.' : 'Semua rute proxy dihapus.') . (!$result['reloaded'] ? ' Nginx host belum ter-reload — klik "Reload Nginx".' : ''));
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Tulis ulang config Nginx app (dipakai saat custom domain di-set/dihapus).
     *
     * App di server agent ⇒ job `nginx_write` **di server itu**
     * ({@see AgentDeployer::writeNginxConfig()}) — host kontrol tidak pernah
     * menulis config Nginx untuk app remote.
     */
    private function applyNginxConfig(array $app, ?AgentClient $agentClient = null): void
    {
        DeployerFactory::createForApp($app, null, $agentClient)->writeNginxConfig($app);
    }

    /**
     * Reloader Nginx untuk app ini: lokal ⇒ `NginxReloader` (perilaku lama),
     * server agent ⇒ {@see AppNginxReloader} (job `nginx_reload` di server itu).
     *
     * Dipakai jalur `NginxConfigGuard` (subdomain & rute proxy) supaya guard —
     * termasuk **jalur rollback**-nya — tidak pernah menyentuh Nginx host
     * kontrol untuk app di server agent.
     *
     * @param array<string,mixed> $app
     * @param NginxReloader|null  $localReloader seam uji: reloader lokal (dipakai
     *                                           hanya bila app memang lokal)
     * @param AgentClient|null    $agentClient   seam uji: klien agent (tanpa jaringan)
     */
    private function nginxReloaderFor(array $app, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null): NginxReloader
    {
        return new AppNginxReloader($app, null, $agentClient, $localReloader);
    }

    /**
     * Cabut sertifikat `$domain` di **server pemilik app** — lokal lewat
     * `SslIssuer` (perilaku lama), server agent lewat job `ssl_revoke` di sana
     * ({@see AppSslRevoker}). Fail-fast bila server app tak dikenal atau agent
     * belum mendukung op — tidak pernah mencabut di host yang salah.
     *
     * @param array<string,mixed> $app
     * @param SslIssuer|null       $localIssuer seam uji (dipakai hanya untuk app lokal)
     * @param AgentClient|null     $agentClient seam uji
     */
    private function revokeDomainCert(array $app, string $domain, ?SslIssuer $localIssuer = null, ?AgentClient $agentClient = null): void
    {
        (new AppSslRevoker(null, $agentClient, $localIssuer))->revoke($app, $domain);
    }

    /**
     * Reload Nginx **di server pemilik app** (best-effort). Mengembalikan string
     * pesan error bila gagal, atau string kosong bila sukses.
     *
     * App di server agent memakai job `nginx_reload` di server itu
     * ({@see AppNginxReloader}) — host kontrol tidak pernah me-reload Nginx untuk
     * app remote.
     *
     * @param array<string,mixed> $app
     * @param NginxReloader|null  $localReloader seam uji
     * @param AgentClient|null    $agentClient   seam uji
     */
    private function reloadNginxNote(array $app, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null): string
    {
        try {
            $r = $this->nginxReloaderFor($app, $localReloader, $agentClient)->reload();

            return $r['ok'] ? '' : 'Reload Nginx GAGAL: ' . $this->appErrorText($app, (string) ($r['error'] ?? 'unknown error')) . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        } catch (\Throwable $e) {
            return 'Reload Nginx gagal: ' . $this->appErrorText($app, $e) . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        }
    }

    /**
     * Pastikan sebuah domain belum dipakai app lain **di server yang sama**
     * (sebagai subdomain efektif maupun custom domain).
     *
     * Multi-server F1 (plan §4.2 + §9 keputusan 4): keunikan domain berlaku
     * **per server** — dua server berbeda boleh memakai domain yang sama (FQDN
     * efektifnya memakai domain dasar server masing-masing, dan custom domain
     * selalu diterbitkan di server pemilik app). Scope ditentukan dari app
     * `$excludeId` lewat {@see AppStore::serverIdOf()} (tanpa query per baris).
     * Subdomain app itu sendiri sudah dicek pemanggil.
     */
    private function assertDomainUnique(AppStore $store, string $excludeId, string $domain): void
    {
        $scope = AppStore::serverIdOf($store->find($excludeId) ?? []);
        $parked = new ParkedDomainStore();

        foreach ($store->all() as $other) {
            if (($other['id'] ?? '') === $excludeId) {
                continue;
            }
            if (AppStore::serverIdOf($other) !== $scope) {
                continue; // server lain: domainnya tidak saling berebut
            }
            if (app_subdomain_of($other) === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai subdomain app "' . ($other['name'] ?? '?') . '".');
            }
            if (($other['custom_domain'] ?? '') === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai custom domain app "' . ($other['name'] ?? '?') . '".');
            }
        }

        // Domain parkir adalah entitas global (tidak terikat app/server), jadi
        // diperiksa lintas server — cermin dari ParkedDomainValidator::assertDomainAvailable().
        if ($parked->byDomain($domain) !== null) {
            throw new RuntimeException(
                'Domain ' . $domain . ' sudah dipakai sebagai domain parkir.'
                . ' Hapus/ubah domain parkir itu di halaman /domains terlebih dahulu.'
            );
        }
    }
}
