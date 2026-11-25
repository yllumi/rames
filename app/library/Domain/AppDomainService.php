<?php
declare(strict_types=1);

namespace app\library\Domain;

use app\library\Agent\AgentClient;
use app\library\Deploy\AppNginxReloader;
use app\library\Deploy\AppSslRevoker;
use app\library\Deploy\DeployerFactory;
use app\library\Docker\AppPorts;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslErrorSummary;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use RuntimeException;

/**
 * Orkestrasi domain per app (§7, §8b, SPECS §8b): custom domain **utama**
 * (`apps.custom_domain`), **domain tambahan/alias** (`apps.custom_domains[]`),
 * dan status domain yang sudah diset.
 *
 * Satu sumber perilaku untuk **dua** konsumen:
 *  - `DomainSettingsController` (form dashboard, flash + redirect);
 *  - `AppApiController` (API eksternal `/api/v1/**`, JSON).
 *
 * Controller hanya mediator: otorisasi app (**`AppAccess` di pemanggil**)
 * dilakukan sebelum service dipanggil; service tidak pernah membaca sesi.
 *
 * Urutan fail-fast dipertahankan: validasi (host port, FQDN, duplikat,
 * keunikan lintas app/parkir, target port) **sebelum** efek samping apa pun,
 * baru update store → tulis config Nginx di server pemilik app → reload.
 * Kegagalan tulis/reload **tidak** membatalkan data yang sudah tersimpan —
 * hasilnya dilaporkan lewat `write_error`/`reload_error` agar pemanggil bisa
 * memberi peringatan (bukan sukses palsu).
 *
 * Tanpa state lintas-request (Webman persistent): seluruh dependensi masuk
 * lewat konstruktor dan tidak ada cache properti yang diisi per operasi.
 */
final class AppDomainService
{
    private readonly ?AppStore $store;

    private readonly ?NginxReloader $localReloader;

    private readonly ?AgentClient $agentClient;

    private readonly ?SslIssuer $localIssuer;

    /**
     * @param AppStore|null       $store         seam uji: store app temp
     * @param NginxReloader|null  $localReloader seam uji: reloader app lokal
     * @param AgentClient|null    $agentClient   seam uji: klien agent
     * @param SslIssuer|null      $localIssuer   seam uji: issuer app lokal
     */
    public function __construct(
        ?AppStore $store = null,
        ?NginxReloader $localReloader = null,
        ?AgentClient $agentClient = null,
        ?SslIssuer $localIssuer = null,
    ) {
        $this->store = $store;
        $this->localReloader = $localReloader;
        $this->agentClient = $agentClient;
        $this->localIssuer = $localIssuer;
    }

    /**
     * Set / ganti custom domain **utama** app.
     *
     * @param array<string,mixed> $app dokumen app (sudah lolos otorisasi)
     * @return array{changed:bool,domain:string,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     * @throws RuntimeException validasi gagal (sebelum efek samping)
     */
    public function setPrimary(array $app, string $rawDomain): array
    {
        $store = $this->store();
        $domain = strtolower(trim($rawDomain));
        $subdomain = app_subdomain_of($app);
        $oldCustom = (string) ($app['custom_domain'] ?? '');

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
        // Alias app sendiri tidak terlihat oleh `assertDomainUnique()` (app
        // sendiri sengaja dilewati). Tanpa cek ini domain bisa menjadi domain
        // utama SEKALIGUS alias: `slotOf()` memetakannya ke `custom_ssl` dan
        // slot alias tak pernah bisa di-SSL-kan.
        if (AppDomains::hasAlias($app, $domain)) {
            throw new RuntimeException(
                'Domain ' . $domain . ' sudah terdaftar sebagai domain tambahan app ini — hapus dulu dari daftar domain tambahan bila ingin menjadikannya domain utama.'
            );
        }

        $appId = (string) ($app['id'] ?? '');
        if ($domain === $oldCustom) {
            return $this->unchanged($app, $domain);
        }

        $this->assertDomainUnique($store, $appId, $domain);

        // Custom domain lama (bila ada sertifikat) di-revoke sebelum diganti —
        // di server pemilik app (agent ⇒ job `ssl_revoke` di sana).
        $revokeError = null;
        if ($oldCustom !== '') {
            try {
                $this->revokeCert($app, $oldCustom);
            } catch (\Throwable $e) {
                $revokeError = $e->getMessage();
            }
        }

        $store->update($appId, static function (array &$s) use ($domain): void {
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
        $fresh = $store->find($appId) ?? $app;

        return $this->finish($fresh, $domain, $revokeError);
    }

    /**
     * Hapus custom domain utama app (sertifikat SSL-nya di-revoke best-effort).
     *
     * @param array<string,mixed> $app
     * @return array{changed:bool,domain:string,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     */
    public function removePrimary(array $app): array
    {
        $store = $this->store();
        $appId = (string) ($app['id'] ?? '');
        $customDomain = (string) ($app['custom_domain'] ?? '');

        if ($customDomain === '') {
            return $this->unchanged($app, '');
        }

        $revokeError = null;
        try {
            $this->revokeCert($app, $customDomain);
        } catch (\Throwable $e) {
            $revokeError = $e->getMessage();
        }

        $store->update($appId, static function (array &$s): void {
            $s['custom_domain'] = null;
            $s['custom_ssl_status'] = 'disabled';
            $s['custom_ssl_stage'] = null;
            $s['custom_ssl_message'] = null;
            $s['custom_ssl_error'] = null;
            $s['custom_ssl_expires_at'] = null;
            $s['custom_needs_ssl'] = false;
        });

        $fresh = $store->find($appId) ?? $app;

        return $this->finish($fresh, $customDomain, $revokeError);
    }

    /**
     * Tambah **domain tambahan (alias)** — melayani app langsung tanpa redirect.
     *
     * @param array<string,mixed> $app
     * @return array{changed:bool,domain:string,target_host_port:int,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     * @throws RuntimeException validasi gagal (sebelum efek samping)
     */
    public function addAlias(array $app, string $rawDomain, string $rawTargetPort = ''): array
    {
        $store = $this->store();
        $appId = (string) ($app['id'] ?? '');
        $domain = AppDomains::normalizeDomain($rawDomain);

        // App tanpa port terpublish tidak punya target `proxy_pass` — vhost
        // domain tambahan tak akan pernah dilayani (sama seperti setPrimary).
        if (!AppPorts::hasHostPort($app)) {
            throw new RuntimeException('App ini tidak mem-publish port host, jadi tidak ada yang bisa di-proxy ke domain. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
        }

        AppDomains::assertAliasAddable($app, $domain);
        // Host port tujuan proxy domain ini (0 = primary service app).
        $targetPort = AppDomains::parseAliasTargetPort($rawTargetPort);
        AppDomains::assertAliasTargetPort($app, $targetPort);
        $this->assertDomainUnique($store, $appId, $domain);

        $store->update($appId, static function (array &$s) use ($domain, $targetPort): void {
            $list = is_array($s['custom_domains'] ?? null) ? $s['custom_domains'] : [];
            $list[] = [
                'domain' => $domain,
                'target_host_port' => $targetPort,
                'ssl_status' => 'disabled',
                'ssl_stage' => null,
                'ssl_message' => null,
                'ssl_error' => null,
                'ssl_expires_at' => null,
                'created_at' => date('c'),
            ];
            $s['custom_domains'] = array_values($list);
        });

        $fresh = $store->find($appId) ?? $app;

        return $this->finish($fresh, $domain, null, $targetPort);
    }

    /**
     * Ubah **tujuan proxy** satu domain tambahan (`target_host_port`).
     *
     * @param array<string,mixed> $app
     * @return array{changed:bool,domain:string,target_host_port:int,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     * @throws AppDomainNotFound alias tidak terdaftar pada app ini
     * @throws RuntimeException  port tujuan tidak sah/dipublikasikan
     */
    public function updateAliasTarget(array $app, string $rawDomain, string $rawTargetPort = ''): array
    {
        $store = $this->store();
        $appId = (string) ($app['id'] ?? '');
        $domain = AppDomains::normalizeDomain($rawDomain);

        if ($domain === '' || !AppDomains::hasAlias($app, $domain)) {
            throw new AppDomainNotFound('Domain tambahan ' . ($domain !== '' ? $domain : '?') . ' tidak ditemukan pada app ini.');
        }

        $targetPort = AppDomains::parseAliasTargetPort($rawTargetPort);
        AppDomains::assertAliasTargetPort($app, $targetPort);

        $store->update($appId, static function (array &$s) use ($domain, $targetPort): void {
            $list = is_array($s['custom_domains'] ?? null) ? $s['custom_domains'] : [];
            foreach ($list as $index => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (AppDomains::normalizeDomain((string) ($entry['domain'] ?? '')) !== $domain) {
                    continue;
                }
                $list[$index]['target_host_port'] = $targetPort;
            }
            $s['custom_domains'] = array_values($list);
        });

        $fresh = $store->find($appId) ?? $app;

        return $this->finish($fresh, $domain, null, $targetPort);
    }

    /**
     * Hapus satu **domain tambahan (alias)** (sertifikatnya di-revoke best-effort).
     *
     * @param array<string,mixed> $app
     * @return array{found:bool,changed:bool,domain:string,target_host_port:int,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     */
    public function removeAlias(array $app, string $rawDomain): array
    {
        $store = $this->store();
        $appId = (string) ($app['id'] ?? '');
        $domain = AppDomains::normalizeDomain($rawDomain);

        if ($domain === '' || !AppDomains::hasAlias($app, $domain)) {
            return ['found' => false] + $this->unchanged($app, $domain);
        }

        $revokeError = null;
        try {
            $this->revokeCert($app, $domain);
        } catch (\Throwable $e) {
            $revokeError = $e->getMessage();
        }

        $store->update($appId, static function (array &$s) use ($domain): void {
            $list = is_array($s['custom_domains'] ?? null) ? $s['custom_domains'] : [];
            $remaining = [];
            foreach ($list as $entry) {
                if (is_array($entry) && AppDomains::normalizeDomain((string) ($entry['domain'] ?? '')) === $domain) {
                    continue;
                }
                $remaining[] = $entry;
            }
            $s['custom_domains'] = array_values($remaining);
        });

        $fresh = $store->find($appId) ?? $app;

        return ['found' => true] + $this->finish($fresh, $domain, $revokeError);
    }

    /**
     * Status domain yang sudah diset: subdomain efektif, custom domain utama,
     * dan domain tambahan — beserta status SSL per slot.
     *
     * Statik murni (tanpa I/O tulis, tanpa otorisasi) supaya bisa dipakai API
     * tanpa menyentuh Docker/agent. `ssl.error_summary` adalah ringkasan satu
     * baris (`SslErrorSummary`), bukan keluaran certbot mentah.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    public static function status(array $app): array
    {
        $subdomain = '';
        try {
            $subdomain = app_subdomain_of($app);
        } catch (\Throwable) {
            // Server app hilang dari registry: status tetap bisa dibaca.
            $subdomain = '';
        }

        $primaryDomain = AppDomains::normalizeDomain((string) ($app['custom_domain'] ?? ''));
        $hasPrimary = $primaryDomain !== '' && AppDomains::isValidFqdn($primaryDomain);

        $aliases = [];
        foreach (AppDomains::aliases($app) as $entry) {
            $aliases[] = [
                'domain' => (string) ($entry['domain'] ?? ''),
                'target_host_port' => (int) ($entry['target_host_port'] ?? 0),
                'role' => 'serve',
                'ssl' => self::sslPayload(AppDomains::status($app, AppDomains::ALIAS_SLOT_PREFIX . (string) ($entry['domain'] ?? ''))),
            ];
        }

        return [
            'slug' => (string) ($app['name'] ?? ''),
            'served' => AppPorts::hasHostPort($app),
            'subdomain' => $subdomain === '' ? null : [
                'domain' => $subdomain,
                'role' => $hasPrimary ? 'redirect' : 'serve',
                'ssl' => self::sslPayload(AppDomains::status($app, AppDomains::SLOT_SUBDOMAIN)),
            ],
            'primary' => $hasPrimary ? [
                'domain' => $primaryDomain,
                'role' => 'serve',
                'ssl' => self::sslPayload(AppDomains::status($app, AppDomains::SLOT_PRIMARY)),
            ] : null,
            'aliases' => $aliases,
        ];
    }

    /**
     * @param array<string,mixed> $status keluaran {@see AppDomains::status()}
     * @return array{status:string,stage:mixed,message:mixed,expires_at:mixed,error_summary:string}
     */
    private static function sslPayload(array $status): array
    {
        $rawStatus = $status['ssl_status'] ?? 'disabled';

        return [
            'status' => is_scalar($rawStatus) && (string) $rawStatus !== '' ? (string) $rawStatus : 'disabled',
            'stage' => $status['ssl_stage'] ?? null,
            'message' => $status['ssl_message'] ?? null,
            'expires_at' => $status['ssl_expires_at'] ?? null,
            'error_summary' => SslErrorSummary::short(
                is_string($status['ssl_error'] ?? null) ? $status['ssl_error'] : null
            ),
        ];
    }

    /**
     * Tulis config Nginx **di server pemilik app** + reload (best-effort).
     *
     * @param array<string,mixed> $app
     * @return array{changed:bool,domain:string,target_host_port:int,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     */
    private function finish(array $app, string $domain, ?string $revokeError, int $targetPort = 0): array
    {
        $result = [
            'changed' => true,
            'domain' => $domain,
            'target_host_port' => $targetPort,
            'applied' => false,
            'write_error' => null,
            'reloaded' => false,
            'reload_error' => null,
            'revoke_error' => $revokeError,
            'app' => $app,
        ];

        try {
            DeployerFactory::createForApp($app, null, $this->agentClient)->writeNginxConfig($app);
        } catch (\Throwable $e) {
            $result['write_error'] = $e->getMessage();

            return $result;
        }

        $result['applied'] = true;

        try {
            $reload = (new AppNginxReloader($app, null, $this->agentClient, $this->localReloader))->reload();
            $result['reloaded'] = (bool) $reload['ok'];
            if (!$reload['ok']) {
                $result['reload_error'] = (string) ($reload['error'] ?? 'unknown error');
            }
        } catch (\Throwable $e) {
            $result['reload_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Hasil "tidak ada perubahan" (idempoten) — tanpa efek samping.
     *
     * @param array<string,mixed> $app
     * @return array{changed:bool,domain:string,target_host_port:int,applied:bool,write_error:?string,reloaded:bool,reload_error:?string,revoke_error:?string,app:array<string,mixed>}
     */
    private function unchanged(array $app, string $domain): array
    {
        return [
            'changed' => false,
            'domain' => $domain,
            'target_host_port' => 0,
            'applied' => false,
            'write_error' => null,
            'reloaded' => false,
            'reload_error' => null,
            'revoke_error' => null,
            'app' => $app,
        ];
    }

    /**
     * Cabut sertifikat `$domain` di **server pemilik app** — lokal lewat
     * `SslIssuer`, server agent lewat job `ssl_revoke` di sana.
     *
     * @param array<string,mixed> $app
     */
    private function revokeCert(array $app, string $domain): void
    {
        (new AppSslRevoker(null, $this->agentClient, $this->localIssuer))->revoke($app, $domain);
    }

    /**
     * Pastikan sebuah domain belum dipakai app lain **di server yang sama**
     * (subdomain efektif, custom domain utama, maupun domain tambahan).
     *
     * Multi-server: keunikan domain berlaku **per server**. Domain parkir
     * adalah entitas global, jadi diperiksa lintas server — cermin
     * `ParkedDomainValidator::assertDomainAvailable()`.
     */
    private function assertDomainUnique(AppStore $store, string $excludeId, string $domain): void
    {
        $scope = AppStore::serverIdOf($store->find($excludeId) ?? []);
        $parked = new ParkedDomainStore();

        $owner = AppDomains::ownerOf($store->all(), $domain, $excludeId, $scope);
        if ($owner !== null) {
            $name = (string) ($owner['app_name'] ?? '?');
            $kind = (string) ($owner['kind'] ?? '');
            if ($kind === 'subdomain') {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai subdomain app "' . $name . '".');
            }
            if ($kind === 'primary') {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai custom domain app "' . $name . '".');
            }

            throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai domain tambahan app "' . $name . '".');
        }

        if ($parked->byDomain($domain) !== null) {
            throw new RuntimeException(
                'Domain ' . $domain . ' sudah dipakai sebagai domain parkir.'
                . ' Hapus/ubah domain parkir itu di halaman /domains terlebih dahulu.'
            );
        }
    }

    private function store(): AppStore
    {
        return $this->store ?? new AppStore();
    }
}
