<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\AppPorts;
use app\library\Nginx\NginxConfigGuard;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use RuntimeException;

/**
 * Kebijakan & penerapan "subdomain app" (field `apps.json.subdomain`).
 *
 * Desain field (lihat SPECS/ARCHITECTURE — dokumentasi diperbarui Rames Assure):
 *   - `subdomain` menyimpan **label** slug (mis. `blog-x7k2`), bukan FQDN.
 *   - FQDN efektif = `{label}.{APP_DOMAIN}`; `null`/kosong = fallback ke `name`
 *     (perilaku lama, tanpa migrasi data).
 *   - Nilai lama yang sudah berupa FQDN penuh tetap dihormati (lihat
 *     `app_subdomain_of()`), jadi app lama tidak perlu diubah.
 *
 * Semua resolusi memakai helper global (`app_subdomain_of()`) — jangan menebak
 * dari `name` langsung. Alur ubah memakai pola "uji dulu, rollback bila gagal"
 * lewat {@see NginxConfigGuard::applySubdomain()} (store+config+reload), sehingga
 * class ini murni kebijakan/orchestrasi dan mudah diuji tanpa Docker.
 *
 * Multi-server (misi `ms-f1-domain`, plan §9 keputusan 4): domain dasar FQDN
 * diambil dari **server pemilik app** (`ServerResolver::domainForAppOrLocal()`),
 * bukan lagi `config('deploy.app_domain')` global — sehingga dua server bisa
 * memakai label subdomain yang sama tanpa dianggap bentrok. Resolver opsional di
 * konstruktor agar tes bisa memakai store server di path temp.
 *
 * Stateless — dependensi disuntikkan (aman untuk worker Webman persistent).
 *
 * Domain parking (entitas global di luar app, halaman `/domains`) ikut diperiksa
 * agar arah app → parkir tertutup seperti arah parkir → app di
 * {@see \app\library\Domain\ParkedDomainValidator::assertDomainAvailable()}.
 * Store parkir **opsional**: `null` = pemeriksaan parkir dilewati (perilaku lama
 * & kompatibilitas tes/pemanggil yang belum menyuntikkan store).
 */
final class SubdomainManager
{
    /** Batas panjang label subdomain (batas label DNS). */
    public const MAX_LENGTH = 63;

    /**
     * @param ParkedDomainStore|null $parked store domain parkir; `null` =
     *        pemeriksaan bentrok parkir dilewati (backward-compat)
     */
    public function __construct(
        private readonly AppStore $store,
        private readonly ?NginxConfigGuard $guard = null,
        private readonly ?ServerResolver $resolver = null,
        private readonly ?ParkedDomainStore $parked = null,
    ) {
    }

    /** Normalisasi input user: trim + lowercase (label selalu huruf kecil). */
    public static function normalize(string $raw): string
    {
        return strtolower(trim($raw));
    }

    /** Label valid? (slug `[a-z0-9-]`, awal/akhir alfanumerik, maks 63). */
    public static function isValidLabel(string $label): bool
    {
        return app_subdomain_valid($label);
    }

    /**
     * Pastikan label (boleh kosong → fallback `name`) bisa dipakai app
     * `$excludeId`. Keunikan diperiksa atas **FQDN efektif** app lain, termasuk
     * app yang belum punya field `subdomain` (mereka memakai `name`), bentrok
     * dengan custom domain app lain, serta bentrok dengan domain parkir
     * (`/domains`) bila store parkir disuntikkan ke konstruktor.
     *
     * Scope server (multi-server F1, plan §4.2 + §9 keputusan 4): `$serverId`
     * diisi ⇒ keunikan hanya diperiksa terhadap app di server itu (FQDN di server
     * lain memakai domain dasar berbeda, jadi tidak pernah bentrok). `null` =
     * semua server (perilaku lama, fail-safe) agar pemanggil lama tidak berubah;
     * FQDN-nya lalu dirakit dengan domain server **lokal**.
     *
     * Melempar RuntimeException dengan pesan jelas bila format salah / bentrok.
     *
     * @return string FQDN efektif
     */
    public function assertAvailable(string $label, string $name, string $excludeId = '', ?string $serverId = null): string
    {
        $label = self::normalize($label);
        if ($label !== '' && !self::isValidLabel($label)) {
            throw new RuntimeException(self::formatError());
        }

        $scope = $serverId === null ? null : AppStore::normalizeServerId($serverId);
        $effective = self::fqdn($label === '' ? $name : $label, $scope, $this->resolver);

        foreach ($this->store->all() as $other) {
            if ((string) ($other['id'] ?? '') === $excludeId) {
                continue;
            }
            if ($scope !== null && AppStore::serverIdOf($other) !== $scope) {
                continue; // server lain: FQDN-nya di domain lain, tidak bentrok
            }
            if (app_subdomain_of($other, $this->resolver) === $effective) {
                throw new RuntimeException('Subdomain ' . $effective . ' sudah dipakai app "' . (string) ($other['name'] ?? '?') . '".');
            }
            if ((string) ($other['custom_domain'] ?? '') === $effective) {
                throw new RuntimeException('Subdomain ' . $effective . ' bentrok dengan custom domain app "' . (string) ($other['name'] ?? '?') . '".');
            }
        }

        // Arah sebaliknya dari ParkedDomainValidator (parkir → app): FQDN app
        // tidak boleh sama dengan domain parkir mana pun, kalau tidak dua
        // `server_name` identik akan ditulis ke Nginx.
        if ($this->parked !== null && $this->parked->byDomain($effective) !== null) {
            throw new RuntimeException(
                'Subdomain ' . $effective . ' sudah dipakai sebagai domain parkir.'
                . ' Hapus/ubah domain parkir itu di halaman /domains terlebih dahulu.'
            );
        }

        return $effective;
    }

    /**
     * Ubah subdomain app: validasi → simpan → tulis config Nginx → `nginx -t`
     * + reload → rollback bila gagal (orkestrasi di {@see NginxConfigGuard}).
     *
     * Idempoten: bila FQDN efektif baru sama dengan lama, kembalikan `noop`
     * tanpa efek samping. `$label` kosong = hapus field (kembali ke `name`).
     *
     * @return array{ok:bool,noop:bool,effective:string,previous:string,reloaded:bool,rolled_back:bool,error:string}
     */
    public function change(string $appId, string $label): array
    {
        $app = $this->store->find($appId);
        if ($app === null) {
            return $this->result('App tidak ditemukan.');
        }

        $name = (string) ($app['name'] ?? '');
        $previous = app_subdomain_of($app, $this->resolver);

        if (!AppPorts::hasHostPort($app)) {
            return $this->result(
                'App ini tidak mem-publish port host, jadi tidak ada yang bisa di-proxy ke subdomain. '
                . 'Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.',
                $previous
            );
        }

        $label = self::normalize($label);
        if ($label !== '' && !self::isValidLabel($label)) {
            return $this->result(self::formatError(), $previous);
        }

        $effective = self::fqdn($label === '' ? $name : $label, AppStore::serverIdOf($app), $this->resolver);

        if ($effective === $previous) {
            return [
                'ok' => true, 'noop' => true, 'effective' => $previous, 'previous' => $previous,
                'reloaded' => false, 'rolled_back' => false, 'error' => '',
            ];
        }

        try {
            $this->assertAvailable($label, $name, $appId, AppStore::serverIdOf($app));
        } catch (RuntimeException $e) {
            return $this->result($e->getMessage(), $previous);
        }

        if ($this->guard === null) {
            return $this->result('Penerapan subdomain tidak tersedia.', $previous);
        }

        $res = $this->guard->applySubdomain($appId, $label === '' ? null : $label);

        return [
            'ok' => $res['ok'],
            'noop' => false,
            'effective' => $res['ok'] ? $effective : $previous,
            'previous' => $previous,
            'reloaded' => $res['reloaded'],
            'rolled_back' => $res['rolled_back'],
            'error' => $res['error'],
        ];
    }

    private static function formatError(): string
    {
        return 'Subdomain hanya boleh huruf kecil a-z, angka, dan strip (-), maksimal 63 karakter, '
            . 'serta diawali & diakhiri huruf/angka.';
    }

    /**
     * Rakit FQDN `{label}.{domain server scope}`.
     *
     * `$scope` = id server pemilik app (sudah dinormalisasi `AppStore`); `null`
     * (pemanggil lama tanpa dimensi server) = domain server lokal.
     *
     * Domainnya lewat {@see ServerResolver::domainForAppOrLocal()} yang toleran:
     * registry server yang tidak terbaca (mis. helper dipakai dari CLI tanpa
     * config) jatuh ke `APP_DOMAIN` — perilaku lama, bukan galat.
     *
     * @param array<string,mixed>|null $resolver
     */
    private static function fqdn(string $label, ?string $scope, ?ServerResolver $resolver): string
    {
        $app = $scope === null ? [] : ['server_id' => $scope];

        return $label . '.' . ServerResolver::domainForAppOrLocal($app, $resolver);
    }

    /**
     * @return array{ok:bool,noop:bool,effective:string,previous:string,reloaded:bool,rolled_back:bool,error:string}
     */
    private function result(string $error, string $previous = ''): array
    {
        return [
            'ok' => false, 'noop' => false, 'effective' => $previous, 'previous' => $previous,
            'reloaded' => false, 'rolled_back' => false, 'error' => $error,
        ];
    }
}
