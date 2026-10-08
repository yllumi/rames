<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\AppPorts;
use app\library\Nginx\NginxConfigGuard;
use app\library\Storage\AppStore;
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
 * Stateless — dependensi disuntikkan (aman untuk worker Webman persistent).
 */
final class SubdomainManager
{
    /** Batas panjang label subdomain (batas label DNS). */
    public const MAX_LENGTH = 63;

    public function __construct(
        private readonly AppStore $store,
        private readonly ?NginxConfigGuard $guard = null,
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
     * app yang belum punya field `subdomain` (mereka memakai `name`), serta
     * bentrok dengan custom domain app lain.
     *
     * Melempar RuntimeException dengan pesan jelas bila format salah / bentrok.
     *
     * @return string FQDN efektif
     */
    public function assertAvailable(string $label, string $name, string $excludeId = ''): string
    {
        $label = self::normalize($label);
        if ($label !== '' && !self::isValidLabel($label)) {
            throw new RuntimeException(self::formatError());
        }

        $effective = $label === '' ? app_subdomain($name) : app_subdomain($label);

        foreach ($this->store->all() as $other) {
            if ((string) ($other['id'] ?? '') === $excludeId) {
                continue;
            }
            if (app_subdomain_of($other) === $effective) {
                throw new RuntimeException('Subdomain ' . $effective . ' sudah dipakai app "' . (string) ($other['name'] ?? '?') . '".');
            }
            if ((string) ($other['custom_domain'] ?? '') === $effective) {
                throw new RuntimeException('Subdomain ' . $effective . ' bentrok dengan custom domain app "' . (string) ($other['name'] ?? '?') . '".');
            }
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
        $previous = app_subdomain_of($app);

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

        $effective = $label === '' ? app_subdomain($name) : app_subdomain($label);

        if ($effective === $previous) {
            return [
                'ok' => true, 'noop' => true, 'effective' => $previous, 'previous' => $previous,
                'reloaded' => false, 'rolled_back' => false, 'error' => '',
            ];
        }

        try {
            $this->assertAvailable($label, $name, $appId);
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
