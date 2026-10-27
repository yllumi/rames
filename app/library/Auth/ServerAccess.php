<?php
declare(strict_types=1);

namespace app\library\Auth;

use app\library\Storage\ServerStore;

/**
 * Otorisasi registry server (multi-server, plan §4.2 & keputusan user §9.3).
 *
 * Dua jenis kuasa yang **terpisah**:
 *  - **kelola & uji** registry server (tambah/ubah/hapus/test/token/PEM) tetap
 *    **admin-only**. Penolakan memakai `AppAccessDenied` yang sudah ada sehingga
 *    controller/exception handler memetakannya ke **404** — sama seperti halaman
 *    operasional host lain (pola `NginxController::index()`), supaya keberadaan
 *    halaman/registry server tidak bocor.
 *  - **memilih server** saat membuat app dapat diberikan **per user** oleh admin
 *    lewat flag `server_select` (+ allowlist opsional `server_ids`, kosong =
 *    semua server). Grant ini hanya membuka *pemilihan server*, bukan kelola
 *    registry dan bukan akses ke app milik user lain (app tetap lewat
 *    `AppAccess`).
 *
 * Read-only yang tetap diberikan (tanpa kuasa baru): setelah login, seorang
 * member bisa melihat **server yang menaungi app yang ia boleh lihat**. Ini
 * informasi yang sudah ia punya dari halaman app-nya sendiri (mis. host tujuan
 * app), jadi bukan kebocoran — tetapi ia tidak pernah mendapat daftar server
 * lengkap dari fakta itu.
 *
 * Kelas ini **stateless & statik murni** (tanpa properti, tanpa store, tanpa
 * HTTP) — aman untuk Webman persistent worker dan mudah diuji. Sumber
 * kebenaran role tetap `AppAccess`; normalisasi flag/allowlist milik
 * `UserStore` (tidak disalin); kelas ini tidak menyalin logika role, ia hanya
 * memanggil konstanta/`visible()` milik `AppAccess`.
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
     * Hanya role global `admin` — grant pemilihan server **tidak** membuka ini.
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
     * Boleh memilih server saat membuat app? Admin **atau** pemegang grant
     * `server_select` dari admin. Owner/member tanpa grant tetap boleh membuat
     * app — hanya **pemilihan servernya** yang terkunci ke server lokal.
     */
    public static function canSelectServer(?array $user): bool
    {
        return self::isAdmin($user) || self::hasServerSelectGrant($user);
    }

    /**
     * Apakah user diberi grant pemilihan server (flag `server_select`)?
     * Admin tidak butuh flag ini; nilai flag milik admin tidak bermakna.
     */
    public static function hasServerSelectGrant(?array $user): bool
    {
        return $user !== null && UserStore::serverSelectOf($user);
    }

    /**
     * Allowlist server user (id string unik). Kosong = semua server **bila**
     * grant aktif; tanpa grant allowlist tidak dipakai sama sekali.
     *
     * @return array<int,string>
     */
    public static function serverAllowlist(?array $user): array
    {
        return $user === null ? [] : UserStore::serverIdsOf($user);
    }

    /**
     * Daftar server yang boleh **dipilih** user pada form create.
     *
     * - admin ⇒ semua baris registry;
     * - grant ⇒ baris server lokal **selalu** ikut + server yang id-nya ada di
     *   allowlist (allowlist kosong ⇒ semua server);
     * - non-grant ⇒ `[]` (form create menambahkan baris local sendiri, perilaku
     *   lama tidak berubah).
     *
     * Dedup berdasarkan id dengan urutan registry dipertahankan.
     *
     * @param array<int,array> $servers daftar server (butuh `id`)
     * @return array<int,array>
     */
    public static function selectableServers(array $servers, ?array $user): array
    {
        if (self::isAdmin($user)) {
            return array_values($servers);
        }
        if (!self::hasServerSelectGrant($user)) {
            return [];
        }

        $allow = self::serverAllowlist($user);
        $out = [];
        $seen = [];
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $id = (string) ($server['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $isLocal = $id === ServerStore::LOCAL_ID
                || strtolower((string) ($server['kind'] ?? '')) === 'local';
            if (!$isLocal && $allow !== [] && !in_array($id, $allow, true)) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $server;
        }

        return $out;
    }

    /**
     * **Satu pintu validasi** id server yang dipilih user (dipakai
     * `AppController::resolveCreateServer()`).
     *
     * - admin ⇒ boleh id apa pun (id tak dikenal tetap ditolak `ServerResolver`);
     * - id kosong / `local` ⇒ selalu boleh;
     * - grant ⇒ boleh bila allowlist kosong atau id ada di allowlist;
     * - selain itu ⇒ tidak boleh (controller memakai pesan generik, sehingga
     *   keberadaan server lain tidak bocor).
     */
    public static function maySelect(?array $user, string $serverId): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }

        $id = trim($serverId);
        if ($id === '' || $id === ServerStore::LOCAL_ID) {
            return true;
        }

        if (!self::hasServerSelectGrant($user)) {
            return false;
        }

        $allow = self::serverAllowlist($user);

        return $allow === [] || in_array($id, $allow, true);
    }

    /**
     * Saring daftar server menjadi yang boleh **dilihat** user.
     *
     * - admin → semua server;
     * - non-admin → UNION dari:
     *   (a) server yang menaungi app yang bisa ia lihat (`AppAccess::visible()`)
     *       — perilaku lama, wajib dipertahankan; dan
     *   (b) server yang boleh ia pilih karena grant (`selectableServers()`),
     *       bila grant aktif;
     * - user `null`/belum login ⇒ daftar kosong.
     *
     * Grant server **tidak** membuka app user lain: app tetap disaring
     * `AppAccess::visible()` di sini dan di seluruh controller. Dedup
     * berdasarkan id, urutan registry dipertahankan (stabil).
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
        $ids = [];
        foreach (AppAccess::visible($apps, $user) as $app) {
            $serverId = is_array($app) ? (string) ($app['server_id'] ?? '') : '';
            if ($serverId !== '') {
                $ids[$serverId] = true;
            }
        }

        // Grant pemilihan server memperluas **visibilitas server** (bukan app):
        // server yang boleh dipilih juga boleh dilihat/difilter di halaman
        // sadar-server (Network/Volume/Monitor/Backup).
        if (self::hasServerSelectGrant($user)) {
            foreach (self::selectableServers($servers, $user) as $server) {
                $serverId = (string) ($server['id'] ?? '');
                if ($serverId !== '') {
                    $ids[$serverId] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $servers,
            static function (array $server) use ($ids): bool {
                $id = (string) ($server['id'] ?? '');

                return $id !== '' && isset($ids[$id]);
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
