<?php
declare(strict_types=1);

namespace app\library\Nginx;

use app\library\Deploy\DeployerInterface;
use app\library\Storage\AppStore;
use Throwable;

/**
 * Penjaga penyimpanan rute proxy tambahan (`apps.json` → `nginx_routes`).
 *
 * MENGAPA ROLLBACK ADA — `nginx -t` yang dijalankan {@see NginxReloader::reload()}
 * memvalidasi SELURUH config Nginx host. Satu rute dengan `target` hostname yang
 * tidak resolvable dari host membuat `nginx -t` GAGAL. Karena watcher/reloader
 * sengaja TIDAK me-reload saat config invalid, config rusak yang tertinggal di
 * disk akan **memblokir reload Nginx SELURUH host** (semua app kehilangan
 * kemampuan reload) sampai file rusak itu dibersihkan manual.
 *
 * Karena itu penyimpanan rute memakai alur "uji dulu, rollback bila gagal":
 * tulis rute baru → tulis config → uji+reload → bila gagal, pulihkan
 * `nginx_routes` ke nilai lama dan tulis ulang config lama. `reload()` sendiri
 * sudah menjalankan `nginx -t` sebelum `nginx -s reload`, jadi aman dipanggil
 * sebagai uji sebelum commit.
 *
 * Instance (bukan statik) & tanpa state lintas-request — dependensi disuntikkan
 * sehingga aman untuk worker Webman persistent dan mudah diganti fake di test.
 *
 * JAMINAN ERROR (tidak ada exception bocor): `applyRoutes()` selalu mengembalikan
 * array 4 kunci. Kegagalan tak terduga — termasuk kegagalan `AppStore::update()`
 * pada jalur ROLLBACK (app terhapus konkuren / IO gagal / JSON korup) — TIDAK
 * dilempar keluar, melainkan dilaporkan lewat `error`; pada kasus itu
 * `rolled_back` bernilai `false` karena rute lama gagal dipulihkan. Bila rollback
 * store berhasil tetapi penulisan ulang config lama gagal, `rolled_back` tetap
 * `true` namun `error` menyertakan peringatan bahwa config di disk mungkin belum
 * pulih.
 */
final class NginxConfigGuard
{
    public function __construct(
        private readonly NginxReloader $reloader,
        private readonly DeployerInterface $deployer,
        private readonly AppStore $store,
    ) {
    }

    /**
     * Simpan rute baru + tulis config + uji/reload; rollback bila gagal.
     *
     * Urutan wajib (lihat doc class):
     *  1. App wajib ada (tanpa efek samping bila tidak).
     *  2. Snapshot nilai `nginx_routes` lama (mentah).
     *  3. Persist rute baru.
     *  4. Tulis config Nginx — bila melempar, pulihkan rute lama dan JANGAN
     *     panggil `reload()` (tidak ada config valid untuk direload).
     *  5. `reload()` (berisi `nginx -t` + reload) — gagal → rollback ke rute
     *     lama + tulis ulang config lama (best-effort).
     *
     * Tidak ada `Throwable` yang bocor keluar: setiap operasi I/O (`find()`,
     * `update()`, `writeNginxConfig()`, `reload()`) dibungkus, termasuk
     * `update()` pada jalur rollback. Kegagalan rollback dilaporkan lewat
     * `error` dengan `rolled_back => false` (bukan exception), dan kegagalan
     * penulisan ulang config lama ditambahkan sebagai peringatan di `error`.
     *
     * @param array<int,array{path:string,target:string}> $routes
     * @return array{ok:bool,reloaded:bool,rolled_back:bool,error:string}
     */
    public function applyRoutes(string $appId, array $routes): array
    {
        try {
            $app = $this->store->find($appId);
        } catch (Throwable $e) {
            return $this->failed('Gagal membaca data app: ' . $e->getMessage());
        }
        if ($app === null) {
            return ['ok' => false, 'reloaded' => false, 'rolled_back' => false, 'error' => 'App tidak ditemukan.'];
        }

        /** @var mixed $previous */
        $previous = $app['nginx_routes'] ?? [];

        try {
            $app = $this->store->update($appId, static function (array &$state) use ($routes): void {
                $state['nginx_routes'] = $routes;
            });
        } catch (Throwable $e) {
            // Belum ada efek samping ke config Nginx — cukup lapor, tanpa rollback.
            return $this->failed('Gagal menyimpan rute proxy: ' . $e->getMessage());
        }

        try {
            $this->deployer->writeNginxConfig($app);
        } catch (Throwable $e) {
            // Tidak ada config valid untuk direload → JANGAN panggil reload().
            return $this->rollback($appId, $previous, 'Gagal menulis config Nginx: ' . $e->getMessage(), false);
        }

        try {
            $result = $this->reloader->reload();
        } catch (Throwable $e) {
            // Perlakukan seperti kegagalan reload → jalur rollback di bawah.
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'nginx menolak config');
            return $this->rollback($appId, $previous, $error, true);
        }

        return ['ok' => true, 'reloaded' => true, 'rolled_back' => false, 'error' => ''];
    }

    /**
     * Respons seragam untuk kegagalan tanpa rollback.
     *
     * @return array{ok:bool,reloaded:bool,rolled_back:bool,error:string}
     */
    private function failed(string $error): array
    {
        return ['ok' => false, 'reloaded' => false, 'rolled_back' => false, 'error' => $error];
    }

    /**
     * Pulihkan `nginx_routes` ke nilai lama dan (opsional) tulis ulang config lama.
     *
     * Kegagalan pada tahap ini TIDAK dilempar keluar: error asli dipertahankan
     * lalu digabung dengan peringatan, sementara `rolled_back` mencerminkan
     * apakah pemulihan rute di `apps.json` benar-benar berhasil.
     *
     * @param mixed $previous nilai mentah `nginx_routes` sebelum perubahan
     * @return array{ok:bool,reloaded:bool,rolled_back:bool,error:string}
     */
    private function rollback(string $appId, mixed $previous, string $originalError, bool $rewriteConfig): array
    {
        try {
            $restored = $this->store->update($appId, static function (array &$state) use ($previous): void {
                $state['nginx_routes'] = $previous;
            });
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reloaded' => false,
                'rolled_back' => false,
                'error' => $originalError . ' — PERINGATAN: rollback gagal: ' . $e->getMessage(),
            ];
        }

        if (!$rewriteConfig) {
            return ['ok' => false, 'reloaded' => false, 'rolled_back' => true, 'error' => $originalError];
        }

        try {
            $this->deployer->writeNginxConfig($restored);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reloaded' => false,
                'rolled_back' => true,
                'error' => $originalError
                    . ' — PERINGATAN: config lama gagal ditulis ulang: ' . $e->getMessage()
                    . ' (periksa/Deploy Ulang).',
            ];
        }

        return ['ok' => false, 'reloaded' => false, 'rolled_back' => true, 'error' => $originalError];
    }
}
