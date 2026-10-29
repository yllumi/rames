<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Docker\AppPorts;

/**
 * Pemetaan store → baris tabel **"Semua Domain"** (`/domains`, SPECS §8a/§8j).
 *
 * Satu-satunya sumber bentuk baris domain untuk halaman SSL (`SslController`)
 * maupun halaman domain (`DomainController`) — controller tidak boleh lagi
 * merakit baris sendiri. Kelas ini **statik murni**: tanpa `current_user()`,
 * tanpa otorisasi, dan tanpa I/O tulis; pemanggil yang menyaring app
 * (`AppAccess::visible()`) dan menghitung `can_ssl`. Perakitan subdomain tetap
 * lewat satu jalur resmi `app_subdomain_of()` (yang membaca domain server app).
 *
 * Baris app mempertahankan kunci lama (`app`, `app_id`, `app_name`, `domain`,
 * `is_custom`, `ssl_status`, `ssl_message`, `ssl_expires_at`, `ssl_error`) agar
 * view/test lama tetap membaca bentuk yang sama, ditambah kunci baru:
 *  - `kind`     — `app` (lihat {@see self::parkedRows()} untuk `parked`);
 *  - `id`       — id app (untuk app; id domain parkir pada baris parkir);
 *  - `role`     — `serve` (melayani app) | `redirect` (subdomain dialihkan ke
 *                 custom domain) | `parkir`;
 *  - `ssl_slot` — awalan field status di `apps.json` (`ssl` | `custom_ssl`);
 *  - `ssl_queue_position` / `ssl_queue_size` — posisi antrean aktivasi SSL
 *    (hanya terisi saat baris `pending`; lihat {@see SslQueue::decorate()}).
 *    Dekorasi dilakukan **di sini saja** supaya `/domains` & `/ssl` menerima
 *    bentuk baris yang sama tanpa duplikasi logika di controller. Fail-safe:
 *    store antrean rusak ⇒ nilainya null, halaman tetap dirender.
 *
 * Aturan baris app:
 *  - app tanpa port host terpublish tidak dilayani Nginx ⇒ **tidak** punya baris
 *    (subdomain/custom domain-nya tidak pernah menerima request);
 *  - tanpa `custom_domain` ⇒ satu baris subdomain, peran `serve`, slot `ssl`;
 *  - dengan `custom_domain` ⇒ baris **custom domain** (peran `serve`, slot
 *    `custom_ssl`) **lalu** baris subdomain (peran `redirect`, slot `ssl`) —
 *    urutan ini dikunci (custom domain selalu di atas) supaya tampilan stabil.
 */
final class SslDomainRows
{
    /**
     * Baris domain untuk daftar app (sudah disaring pemanggil).
     *
     * @param array<int,array<string,mixed>> $apps
     * @return array<int,array<string,mixed>>
     */
    public static function appRows(array $apps): array
    {
        $rows = [];

        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }

            // App tanpa port terpublish tidak di-proxy Nginx (vhost/subdomain
            // dilewati saat deploy) → tidak ada domain yang bisa di-SSL.
            if (!AppPorts::hasHostPort($app)) {
                continue;
            }

            $appId = (string) ($app['id'] ?? '');
            $appName = (string) ($app['name'] ?? '');
            $subdomain = app_subdomain_of($app);
            $customDomain = trim((string) ($app['custom_domain'] ?? ''));

            if ($customDomain !== '') {
                // Custom domain melayani app; subdomain hanya redirect ke sana.
                $rows[] = self::appRow($app, $appId, $appName, $customDomain, true, 'serve', 'custom_ssl');
                $rows[] = self::appRow($app, $appId, $appName, $subdomain, false, 'redirect', 'ssl');

                continue;
            }

            $rows[] = self::appRow($app, $appId, $appName, $subdomain, false, 'serve', 'ssl');
        }

        return (new SslQueue())->decorate($rows);
    }

    /**
     * Baris domain untuk entri domain parkir.
     *
     * Domain parkir adalah entitas global (tidak terikat app), bentuknya sama
     * dengan baris app supaya tabel `/domains` bisa merender keduanya dengan
     * satu template. `app_id`/`app_name` sengaja string kosong (bukan null):
     * view lama memakai `e($row['app_id'])` dan JSON tetap bertipe stabil.
     *
     * @param array<int,array<string,mixed>> $parked
     * @return array<int,array<string,mixed>>
     */
    public static function parkedRows(array $parked): array
    {
        $rows = [];

        foreach ($parked as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rows[] = [
                'kind' => 'parked',
                'id' => (string) ($entry['id'] ?? ''),
                'domain' => (string) ($entry['domain'] ?? ''),
                'app_id' => '',
                'app_name' => '',
                'is_custom' => false,
                'role' => 'parkir',
                'mode' => (string) ($entry['mode'] ?? ''),
                'ssl_status' => (string) ($entry['ssl_status'] ?? 'disabled'),
                'ssl_stage' => $entry['ssl_stage'] ?? null,
                'ssl_message' => $entry['ssl_message'] ?? null,
                'ssl_error' => $entry['ssl_error'] ?? null,
                'ssl_expires_at' => $entry['ssl_expires_at'] ?? null,
            ];
        }

        return (new SslQueue())->decorate($rows);
    }

    /**
     * Satu baris app untuk slot SSL tertentu.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private static function appRow(
        array $app,
        string $appId,
        string $appName,
        string $domain,
        bool $isCustom,
        string $role,
        string $slot,
    ): array {
        return [
            'kind' => 'app',
            'id' => $appId,
            // Objek app dipertahankan untuk view/test lama; `DomainController`
            // membuangnya sebelum mengirim payload (jangan bocorkan objek app).
            'app' => $app,
            'app_id' => $appId,
            'app_name' => $appName,
            'domain' => $domain,
            'is_custom' => $isCustom,
            'role' => $role,
            'ssl_slot' => $slot,
            'ssl_status' => (string) ($app[$slot . '_status'] ?? 'disabled'),
            'ssl_message' => $app[$slot . '_message'] ?? null,
            'ssl_expires_at' => $app[$slot . '_expires_at'] ?? null,
            'ssl_error' => $app[$slot . '_error'] ?? null,
        ];
    }
}
