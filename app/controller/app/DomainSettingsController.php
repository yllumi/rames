<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Agent\AgentClient;
use app\library\Deploy\AppNginxReloader;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ServerAddress;
use app\library\Deploy\ServerResolver;
use app\library\Deploy\SubdomainManager;
use app\library\Domain\AppDomainService;
use app\library\Domain\AppDomains;
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
 * Route: `POST /apps/{id}/domain/set`, `/domain/remove`, `/domains`,
 * `/domains/remove`, `/subdomain`, `/routes`. Nama kelas sengaja bukan
 * `DomainController` — itu halaman `/domains` global.
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
     * Seluruh validasi & efek samping ada di {@see AppDomainService::setPrimary()}
     * (satu sumber dengan API eksternal `PUT /api/v1/apps/{slug}/domain`) —
     * controller hanya menerjemahkan hasil menjadi flash + redirect:
     * FQDN publik, bukan subdomain bawaan, bukan alias app sendiri, unik di
     * semua app (subdomain & custom domain app lain), sertifikat domain lama
     * di-revoke, lalu config Nginx ditulis ulang + di-reload.
     */
    public function setDomain(
        Request $request,
        string $id,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ) {
        $app = $this->findApp($id, 'domain');
        $oldCustom = (string) ($app['custom_domain'] ?? '');
        $domain = strtolower(trim((string) $request->post('domain', '')));

        try {
            $result = $this->domainService($localReloader, $agentClient, $localIssuer)->setPrimary($app, $domain);
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));

            return redirect('/apps/' . $id);
        }

        if ($result['revoke_error'] !== null) {
            flash_set('info', 'Peringatan: gagal revoke sertifikat lama ' . $oldCustom . ' — ' . $this->appErrorText($app, $result['revoke_error']));
        }

        if (!$result['changed']) {
            flash_set('info', 'Custom domain sudah di-set ke ' . $result['domain'] . '.');

            return redirect('/apps/' . $id);
        }

        if ($result['write_error'] !== null) {
            flash_set('error', 'Custom domain ' . $result['domain'] . ' tersimpan, tetapi gagal menulis config Nginx: ' . $this->appErrorText($app, $result['write_error']) . ' (jalankan Rebuild untuk menerapkan).');

            return redirect('/apps/' . $id);
        }

        // Reload Nginx di server app agar reverse proxy custom domain aktif.
        $reloadNote = $this->reloadNoteFromResult($result['app'], $result['reload_error']);
        flash_set('success', 'Custom domain ' . $result['domain'] . ' diset. Subdomain bawaan kini redirect ke domain tersebut.' . ($reloadNote !== '' ? ' ' . $reloadNote : '') . ' ' . $this->domainDnsHint($result['app'], $result['domain']));

        return redirect('/apps/' . $id);
    }

    /**
     * Hapus custom domain app. Sertifikat SSL domain (bila ada) di-revoke;
     * bila revoke gagal, domain tetap dihapus dari config (recovery via Rebuild).
     *
     * Logika di {@see AppDomainService::removePrimary()}.
     */
    public function removeDomain(
        Request $request,
        string $id,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ) {
        $app = $this->findApp($id, 'domain');
        $customDomain = (string) ($app['custom_domain'] ?? '');

        if ($customDomain === '') {
            flash_set('info', 'App tidak memiliki custom domain.');

            return redirect('/apps/' . $id);
        }

        try {
            $result = $this->domainService($localReloader, $agentClient, $localIssuer)->removePrimary($app);
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));

            return redirect('/apps/' . $id);
        }

        $notes = [];
        if ($result['revoke_error'] !== null) {
            $notes[] = 'Peringatan: gagal revoke sertifikat ' . $customDomain . ' — ' . $this->appErrorText($app, $result['revoke_error']) . '.';
        }
        if ($result['write_error'] !== null) {
            $notes[] = 'Gagal menulis config Nginx: ' . $this->appErrorText($app, $result['write_error']);
        } else {
            // Reload Nginx di server app agar subdomain kembali melayani app (best-effort).
            $reloadNote = $this->reloadNoteFromResult($result['app'], $result['reload_error']);
            if ($reloadNote !== '') {
                $notes[] = $reloadNote;
            }
        }

        if ($notes !== []) {
            flash_set('error', 'Custom domain ' . $customDomain . ' dihapus, tetapi: ' . implode(' ', $notes) . ' (jalankan Rebuild untuk menerapkan config).');
        } else {
            flash_set('success', 'Custom domain ' . $customDomain . ' dihapus. Sertifikat SSL-nya di-revoke.');
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Tambah **domain tambahan (alias)** app — `POST /apps/{id}/domains`.
     *
     * Alias melayani app **langsung** (tanpa redirect 301): app multi-site
     * memutuskan sendiri berdasarkan header `Host`. Domain utama
     * (`custom_domain`) & perilaku redirect subdomain tidak berubah.
     *
     * Validasi fail-fast (host port app, FQDN/duplikat/batas, target proxy,
     * keunikan lintas app & domain parkir) serta penulisan config/reload Nginx
     * seluruhnya di {@see AppDomainService::addAlias()}.
     * Otorisasi dicek `findApp(..., 'domain')` ⇒ 404.
     */
    public function addDomain(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $app = $this->findApp($id, 'domain');

        try {
            $result = $this->domainService($localReloader, $agentClient)->addAlias(
                $app,
                (string) $request->post('domain', ''),
                (string) $request->post('target_host_port', '')
            );
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));

            return redirect('/apps/' . $id);
        }

        if ($result['write_error'] !== null) {
            flash_set('error', 'Domain tambahan ' . $result['domain'] . ' tersimpan, tetapi gagal menulis config Nginx: ' . $this->appErrorText($app, $result['write_error']) . ' (jalankan Rebuild untuk menerapkan).');

            return redirect('/apps/' . $id);
        }

        $reloadNote = $this->reloadNoteFromResult($result['app'], $result['reload_error']);
        flash_set('success', 'Domain tambahan ' . $result['domain'] . ' ditambahkan — diarahkan ke ' . $this->aliasTargetNote($result['app'], $result['target_host_port']) . '. App kini melayani domain tersebut langsung (tanpa redirect).'
            . ($reloadNote !== '' ? ' ' . $reloadNote : '') . ' ' . $this->domainDnsHint($result['app'], $result['domain']));

        return redirect('/apps/' . $id);
    }

    /**
     * Hapus satu **domain tambahan (alias)** — `POST /apps/{id}/domains/remove`.
     *
     * Hanya entri `custom_domains[]` yang cocok yang dibuang (domain lain tetap).
     * Sertifikat SSL domain itu di-revoke best-effort di server pemilik app;
     * kegagalan revoke hanya memunculkan peringatan `info` — domain tetap
     * dihapus dan config Nginx ditulis ulang (recovery via Rebuild).
     *
     * Logika di {@see AppDomainService::removeAlias()}.
     */
    public function removeDomainAlias(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $app = $this->findApp($id, 'domain');
        $domain = AppDomains::normalizeDomain((string) $request->post('domain', ''));

        try {
            $result = $this->domainService($localReloader, $agentClient)->removeAlias($app, $domain);
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));

            return redirect('/apps/' . $id);
        }

        if (!$result['found']) {
            flash_set('info', 'Domain tambahan ' . $domain . ' tidak ditemukan pada app ini.');

            return redirect('/apps/' . $id);
        }

        $notes = [];
        if ($result['revoke_error'] !== null) {
            $notes[] = 'Peringatan: gagal revoke sertifikat ' . $domain . ' — ' . $this->appErrorText($app, $result['revoke_error']) . '.';
        }
        if ($result['write_error'] !== null) {
            $notes[] = 'Gagal menulis config Nginx: ' . $this->appErrorText($app, $result['write_error']);
        } else {
            $reloadNote = $this->reloadNoteFromResult($result['app'], $result['reload_error']);
            if ($reloadNote !== '') {
                $notes[] = $reloadNote;
            }
        }

        if ($notes !== []) {
            flash_set('info', 'Domain tambahan ' . $domain . ' dihapus, tetapi: ' . implode(' ', $notes) . ' (jalankan Rebuild untuk menerapkan config).');
        } else {
            flash_set('success', 'Domain tambahan ' . $domain . ' dihapus. Sertifikat SSL-nya di-revoke.');
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Ubah **tujuan proxy** satu domain tambahan — `POST /apps/{id}/domains/target`.
     *
     * Field `domain` = alias yang sudah terdaftar, `target_host_port` = host
     * port app tujuan (`proxy_pass` Nginx); kosong = kembali ke **primary
     * service** app. Berguna untuk app multi-service: satu domain ke web utama,
     * domain lain ke service/port lain milik app yang sama.
     *
     * Fail-fast sebelum efek samping: alias wajib terdaftar di app ini dan port
     * wajib dipublikasikan app — lihat {@see AppDomainService::updateAliasTarget()}.
     * Otorisasi lewat `findApp(..., 'domain')` ⇒ 404.
     */
    public function setDomainTarget(Request $request, string $id, ?NginxReloader $localReloader = null, ?AgentClient $agentClient = null)
    {
        $app = $this->findApp($id, 'domain');
        $domain = AppDomains::normalizeDomain((string) $request->post('domain', ''));

        try {
            $result = $this->domainService($localReloader, $agentClient)->updateAliasTarget(
                $app,
                $domain,
                (string) $request->post('target_host_port', '')
            );
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));

            return redirect('/apps/' . $id);
        }

        if ($result['write_error'] !== null) {
            flash_set('error', 'Tujuan domain tambahan ' . $domain . ' tersimpan, tetapi gagal menulis config Nginx: ' . $this->appErrorText($app, $result['write_error']) . ' (jalankan Rebuild untuk menerapkan).');

            return redirect('/apps/' . $id);
        }

        $reloadNote = $this->reloadNoteFromResult($result['app'], $result['reload_error']);
        flash_set('success', 'Tujuan domain tambahan ' . $domain . ' diset ke ' . $this->aliasTargetNote($result['app'], $result['target_host_port']) . '.'
            . ($reloadNote !== '' ? ' ' . $reloadNote : ''));

        return redirect('/apps/' . $id);
    }
    /**
     * Ringkasan tujuan proxy alias untuk flash: port host terpilih, atau
     * primary service app beserta port host-nya (bila bisa dipastikan).
     *
     * @param array<string,mixed> $app
     */
    private function aliasTargetNote(array $app, int $hostPort): string
    {
        if ($hostPort > 0) {
            $owner = AppPorts::findHostPort($app, $hostPort);
            $service = trim((string) ($owner['service'] ?? ''));
            $container = (int) ($owner['container'] ?? 0);

            $detail = '';
            if ($service !== '') {
                $detail = ' (service ' . $service . ($container > 0 ? ', container port ' . $container : '') . ')';
            } elseif ($container > 0) {
                $detail = ' (container port ' . $container . ')';
            }

            return 'port host ' . $hostPort . $detail;
        }

        try {
            return 'primary service (port host ' . AppPorts::primaryHostPort($app) . ')';
        } catch (\Throwable) {
            return 'primary service';
        }
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
     * Service domain app dengan seam uji dari signature action (reloader lokal,
     * klien agent, issuer lokal). Satu jalur untuk operasi domain utama &
     * domain tambahan — logika bisnis tidak disalin ke controller.
     */
    private function domainService(
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null
    ): AppDomainService {
        return new AppDomainService(null, $localReloader, $agentClient, $localIssuer);
    }

    /**
     * Catatan reload Nginx dari hasil {@see AppDomainService} — string kosong
     * bila reload sukses, atau kalimat peringatan yang seragam dengan UI lama.
     */
    private function reloadNoteFromResult(array $app, ?string $reloadError): string
    {
        if ($reloadError === null) {
            return '';
        }

        return 'Reload Nginx GAGAL: ' . $this->appErrorText($app, $reloadError) . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
    }

}
