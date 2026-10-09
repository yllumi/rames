<?php
declare(strict_types=1);

namespace app\library\Backup;

/**
 * Pemetaan volume Docker → target backup (PLAN_VOLUME_BACKUP.md §5.3).
 *
 * Cakupan (keputusan #1): **hanya named volume ber-label
 * `com.docker.compose.project`**. Bind mount host, anonymous volume, dan volume
 * container eksternal berada di luar cakupan.
 *
 * Kelas ini **statik murni** (tanpa I/O) sehingga pemetaan bisa diuji langsung:
 *  - `$volumes` = keluaran `DockerClient::listVolumes(['label' => ['com.docker.compose.project']])`
 *    (respons `GET /volumes`, entri berisi `Name`, `Labels`, `Driver`, …);
 *  - `$apps` = `AppStore::all()` / entri `apps.json` (field `id`, `name`, …).
 *
 * Nama compose project app = field `name` app (lihat `VolumeController::activeProjectNames()`
 * dan `LocalDeployer::appDir()`, yang memakai konvensi yang sama).
 *
 * Otorisasi **tidak** ada di sini (itu pintu `AppAccess` di controller). Yang
 * disediakan hanyalah flag `orphaned` = project tidak (lagi) ada di apps.json —
 * mis. app dihapus dengan mode "pertahankan volume". Keputusan #7: volume yatim
 * ikut di-backup sampai retensi habis, tetapi hanya admin yang melihat &
 * memulihkannya.
 */
final class VolumeTargetMap
{
    /**
     * Satu target backup (satu named volume).
     *
     * `app_id`/`app_name` bernilai null bila volume yatim (project tidak ada di
     * apps.json) — konsumen tidak boleh menebak pemiliknya.
     *
     * @phpstan-type VolumeTarget array{
     *     name:string, project:string, app_id:?string, app_name:?string, orphaned:bool
     * }
     */
    public const LABEL_PROJECT = 'com.docker.compose.project';

    /**
     * Label penanda volume database dashboard. `docker-compose.yml` memberi
     * named volume `rames` (mount `/var/lib/rames`) label `rames.role=dashboard-db`.
     *
     * Volume itu **ikut** ber-label compose (`com.docker.compose.project=rames`)
     * sehingga lolos filter label di atas — padahal ia bukan volume app.
     * Bila ikut menjadi target backup, run berkala berkebijakan snapshot `stop`
     * + `require_stopped` akan menghentikan container dashboard (proses yang
     * menjalankan backup itu sendiri) → run gagal / dashboard mati. Karena itu
     * volume ber-label ini **dikecualikan permanen** dari target
     * backup/restore/schedule (dan dari daftar/purge volume di `/volumes`).
     */
    public const DASHBOARD_VOLUME_LABEL_KEY = 'rames.role';
    public const DASHBOARD_VOLUME_LABEL_VALUE = 'dashboard-db';

    /**
     * Apakah entri volume Engine ini volume database dashboard (yang harus
     * dikecualikan). Bekerja pada bentuk respons `GET /volumes`:
     * `Labels` = map label → nilai (bukan list).
     *
     * @param array<string,mixed> $volume entri `DockerClient::listVolumes()`
     */
    public static function isDashboardVolume(array $volume): bool
    {
        $labels = $volume['Labels'] ?? null;
        if (!is_array($labels)) {
            return false;
        }

        return (string) ($labels[self::DASHBOARD_VOLUME_LABEL_KEY] ?? '') === self::DASHBOARD_VOLUME_LABEL_VALUE;
    }

    /**
     * Bangun daftar target backup dari daftar volume Engine + daftar app.
     *
     * @param array<int,array> $volumes keluaran `DockerClient::listVolumes()` (respons `GET /volumes`)
     * @param array<int,array> $apps    daftar app (`AppStore::all()`)
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    public static function build(array $volumes, array $apps): array
    {
        $appByProject = self::appsByProject($apps);

        $targets = [];
        $seen = [];
        foreach ($volumes as $volume) {
            $name = (string) ($volume['Name'] ?? '');
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            if (self::isDashboardVolume($volume)) {
                // Volume DB dashboard bukan volume app — dikecualikan (lihat konstanta).
                continue;
            }

            $labels = $volume['Labels'] ?? [];
            $project = is_array($labels) ? (string) ($labels[self::LABEL_PROJECT] ?? '') : '';
            if ($project === '') {
                // hanya volume ber-label compose yang dikelola dashboard
                continue;
            }

            $seen[$name] = true;
            $app = $appByProject[$project] ?? null;
            $targets[] = [
                'name' => $name,
                'project' => $project,
                'app_id' => $app !== null ? (string) ($app['id'] ?? '') : null,
                'app_name' => $app !== null ? (string) ($app['name'] ?? '') : null,
                'orphaned' => $app === null,
            ];
        }

        // determinisme keluaran (agar UI & laporan stabil antar-run)
        usort($targets, static function (array $a, array $b): int {
            return strcmp((string) $a['project'], (string) $b['project'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return $targets;
    }

    /**
     * Hanya nama volume dari daftar target (mis. untuk filter `listVolumes` lain).
     *
     * @param array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}> $targets
     * @return array<int,string>
     */
    public static function names(array $targets): array
    {
        return array_values(array_map(
            static fn (array $t): string => (string) $t['name'],
            $targets
        ));
    }

    /**
     * Saring target ke project yang boleh diakses user (dipanggil controller
     * dengan daftar project hasil `AppAccess::visible()`; volume yatim hanya
     * untuk admin sesuai keputusan #7 — aturan itu ditegakkan pemanggil).
     *
     * @param array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}> $targets
     * @param array<int,string> $allowedProjects
     * @param bool              $includeOrphaned
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    public static function filterAccessible(array $targets, array $allowedProjects, bool $includeOrphaned): array
    {
        $allowed = [];
        foreach ($allowedProjects as $project) {
            $allowed[(string) $project] = true;
        }

        return array_values(array_filter(
            $targets,
            static function (array $t) use ($allowed, $includeOrphaned): bool {
                if ($t['orphaned']) {
                    return $includeOrphaned;
                }
                return isset($allowed[$t['project']]);
            }
        ));
    }

    /**
     * Peta nama project → app. Project pertama menang (nama app unik di apps.json).
     *
     * @param array<int,array> $apps
     * @return array<string,array>
     */
    private static function appsByProject(array $apps): array
    {
        $map = [];
        foreach ($apps as $app) {
            $name = (string) ($app['name'] ?? '');
            if ($name === '' || isset($map[$name])) {
                continue;
            }
            $map[$name] = $app;
        }
        return $map;
    }
}
