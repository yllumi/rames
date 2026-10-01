<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;

/**
 * Satu pintu otorisasi backup volume (PLAN_VOLUME_BACKUP.md §4.2, §5.1).
 *
 * Kelas ini **tidak** menyalin logika role: untuk volume milik app ia hanya
 * mendelegasikan ke `AppAccess` (satu-satunya sumber kebenaran role `owner_id`
 * + `members`). Yang ditambahkan hanyalah aturan **volume yatim** (keputusan
 * #7): project tidak (lagi) ada di `apps.json` sehingga tidak punya baris app
 * → hanya **admin global** yang boleh melihat/memulihkannya.
 *
 * Ability (dari `AppAccess::ABILITIES`):
 *   - `backup`  = operator (owner & operator; viewer ditolak)
 *   - `restore` = owner (destruktif)
 *
 * Penolakan selalu lewat `AppAccessDenied` → **404** (bukan 403).
 *
 * Stateless (tanpa properti) — aman untuk worker Webman persistent.
 */
final class BackupAccess
{
    /**
     * Apakah user boleh melakukan `$ability` pada `$target`.
     *
     * @param array{name?:string,project?:string,app_id?:?string,app_name?:?string,orphaned?:bool} $target
     */
    public static function can(string $ability, array $target, ?array $app, ?array $user): bool
    {
        // Volume yatim: tidak ada app untuk dievaluasi → hanya admin global.
        if (!empty($target['orphaned'])) {
            return self::isAdmin($user);
        }

        // Target mengklaim milik app tetapi app tidak ditemukan: perlakukan
        // seperti tidak berhak (jangan menebak pemiliknya).
        if ($app === null) {
            return false;
        }

        return AppAccess::can($ability, $app, $user);
    }

    /**
     * Versi `can()` yang melempar `AppAccessDenied` (404).
     *
     * @param array{name?:string,project?:string,app_id?:?string,app_name?:?string,orphaned?:bool} $target
     * @throws AppAccessDenied
     */
    public static function require(string $ability, array $target, ?array $app, ?array $user): void
    {
        if (self::can($ability, $target, $app, $user)) {
            return;
        }

        throw new AppAccessDenied($ability, $target['app_id'] !== null ? (string) $target['app_id'] : null);
    }

    /**
     * Admin global (delegasi ke `AppAccess::roleFor()` dengan app kosong —
     * admin selalu `ROLE_ADMIN` apa pun app-nya).
     */
    public static function isAdmin(?array $user): bool
    {
        return AppAccess::roleFor([], $user) === AppAccess::ROLE_ADMIN;
    }

    /**
     * Target yang boleh dilihat user (dipakai endpoint status & halaman).
     * Volume yatim hanya untuk admin; volume app lain tidak bocor.
     *
     * @param array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}> $targets
     * @param array<int,array> $apps daftar app (`AppStore::all()`)
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    public static function visible(array $targets, array $apps, ?array $user): array
    {
        return VolumeTargetMap::filterAccessible(
            $targets,
            self::allowedProjects($apps, $user),
            self::isAdmin($user)
        );
    }

    /**
     * Nama project app yang boleh diakses user (via `AppAccess::visible()`).
     *
     * @param array<int,array> $apps
     * @return array<int,string>
     */
    public static function allowedProjects(array $apps, ?array $user): array
    {
        $names = [];
        foreach (AppAccess::visible($apps, $user) as $app) {
            $name = (string) ($app['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}
