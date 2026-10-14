<?php
declare(strict_types=1);

namespace app\library\Auth;

/**
 * Otorisasi registry server (multi-server, plan §4.2 & keputusan user §9.3).
 *
 * Keputusan yang dikunci: **kelola & pilih server = admin-only**. Non-admin
 * (member, owner, operator, viewer, tamu) tidak melihat kontrol server sama
 * sekali; penolakan memakai `AppAccessDenied` yang sudah ada sehingga
 * controller/exception handler memetakannya ke **404** — sama seperti halaman
 * operasional host lain (pola `NginxController::index()`), supaya keberadaan
 * halaman/registry server tidak bocor.
 *
 * Read-only yang tetap diberikan (tanpa kuasa baru): setelah login, seorang
 * member bisa melihat **server yang menaungi app yang ia boleh lihat**. Ini
 * informasi yang sudah ia punya dari halaman app-nya sendiri (mis. host tujuan
 * app), jadi bukan kebocoran — tetapi ia tidak pernah mendapat daftar server
 * lengkap, dan tidak mendapat hak apa pun untuk menambah/mengubah/memilih.
 *
 * Kelas ini **stateless & statik murni** (tanpa properti, tanpa store, tanpa
 * HTTP) — aman untuk Webman persistent worker dan mudah diuji. Sumber
 * kebenaran role tetap `AppAccess`; kelas ini tidak menyalin logika role,
 * ia hanya memanggil konstanta/`visible()` milik `AppAccess`.
 */
final class ServerAccess
{
    /**
     * Ability semu untuk penolakan 404 pada operasi registry server. Dipakai
     * sebagai `AppAccessDenied::$ability` supaya penelusuran tetap jelas.
     */
    public const ABILITY_MANAGE = 'servers';
    public const ABILITY_SELECT = 'servers.select';

    /**
     * Boleh mengelola registry server (tambah/ubah/hapus/test koneksi/token)?
     * Hanya role global `admin`.
     */
    public static function canManageServers(?array $user): bool
    {
        return self::isAdmin($user);
    }

    /**
     * Versi `canManageServers()` yang melempar `AppAccessDenied` (404) untuk
     * dipakai controller — pola satu pintu, sama seperti `AppAccess::require()`.
     *
     * @throws AppAccessDenied
     */
    public static function requireManageServers(?array $user): void
    {
        if (!self::canManageServers($user)) {
            throw new AppAccessDenied(self::ABILITY_MANAGE);
        }
    }

    /**
     * Boleh memilih server saat membuat app? Sama: admin-only (keputusan §9.3).
     * Owner/member tetap boleh membuat app — hanya **pemilihan servernya** yang
     * dikunci (app non-admin selalu jatuh ke server lokal/default).
     */
    public static function canSelectServer(?array $user): bool
    {
        return self::isAdmin($user);
    }

    /**
     * Saring daftar server menjadi yang boleh **dilihat** user.
     *
     * - admin → semua server;
     * - non-admin → hanya server yang menaungi app yang bisa ia lihat
     *   (`AppAccess::visible()`); tanpa app terlihat ⇒ daftar kosong
     *   (fail-closed, bukan "semua server");
     * - user `null`/belum login ⇒ daftar kosong.
     *
     * `$apps` boleh berupa daftar app mentah maupun yang sudah disaring
     * pemanggil: penyaringan dilakukan **di sini** lewat `AppAccess::visible()`
     * supaya app milik user lain tidak pernah bisa dipakai untuk "membuka"
     * visibilitas server (jangan menambah kuasa baru), dan kelas ini tidak
     * pernah menyentuh store dari dalam.
     *
     * @param array<int,array> $servers daftar server (butuh field `id`)
     * @param array<int,array> $apps    app yang relevan (butuh `owner_id`/`members` + `server_id`)
     * @return array<int,array>
     */
    public static function visibleServers(array $servers, ?array $user, array $apps = []): array
    {
        if (self::isAdmin($user)) {
            return array_values($servers);
        }

        // User tanpa `id` (belum login) tidak punya app terlihat — `AppAccess`
        // yang memutuskan itu, kelas ini tidak menyalin aturannya.
        $serverIds = [];
        foreach (AppAccess::visible($apps, $user) as $app) {
            $serverId = is_array($app) ? (string) ($app['server_id'] ?? '') : '';
            if ($serverId !== '') {
                $serverIds[$serverId] = true;
            }
        }
        if ($serverIds === []) {
            return [];
        }

        return array_values(array_filter(
            $servers,
            static function (array $server) use ($serverIds): bool {
                $id = (string) ($server['id'] ?? '');

                return $id !== '' && isset($serverIds[$id]);
            }
        ));
    }

    private static function isAdmin(?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (string) ($user['role'] ?? '') === AppAccess::ROLE_ADMIN;
    }
}
