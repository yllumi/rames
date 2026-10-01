<?php
declare(strict_types=1);

namespace app\library\Backup;

/**
 * Pemilihan strategi backup per volume (PLAN_VOLUME_BACKUP.md §2 / §5.3).
 *
 * Dua strategi (keputusan #6):
 *  - **A — `dump`** : container DB terdeteksi **dan hidup** → dump logis
 *    (`mysqldump`/`pg_dump`) di dalam container, tanpa downtime.
 *  - **B — `snapshot`** : volume non-DB **atau** container DB sedang mati
 *    (fallback aman) → snapshot filesystem restic, hanya sah saat container
 *    berhenti (`VolumeStateGuard`).
 *
 * Kelas ini **statik murni** (tanpa I/O) agar aturan pemilihan bisa diuji
 * langsung: pengumpulan data (container apa saja yang me-mount volume + mana
 * yang container DB) dilakukan pemanggil, mis. `VolumeStateGuard::containersForVolume()`
 * dengan deteksi DB dari `DbContainerDetector`.
 *
 * Kontrak input — tiap entri container:
 *   array{name?:string, state?:string, running?:bool, is_db?:bool}
 *
 * `running` boleh dikirim eksplisit; bila absen diturunkan dari `state`
 * (`running` = hidup, `exited`/`created`/`dead`/`paused` = tidak).
 */
final class BackupStrategyResolver
{
    /** Strategi A — dump logis di dalam container DB. */
    public const STRATEGY_DUMP = 'dump';

    /** Strategi B — snapshot filesystem restic (container wajib berhenti). */
    public const STRATEGY_SNAPSHOT = 'snapshot';

    /**
     * Strategi untuk satu volume.
     *
     * @param array<int,array{name?:string,state?:string,running?:bool,is_db?:bool}> $containers
     *        container yang me-mount volume (boleh kosong — mis. volume yatim tanpa container)
     * @param bool $dbDumpEnabled `VOLUME_BACKUP_DB_DUMP_ENABLED`; false = selalu snapshot
     * @return string BackupStrategyResolver::STRATEGY_DUMP|STRATEGY_SNAPSHOT
     */
    public static function resolve(array $containers, bool $dbDumpEnabled = true): string
    {
        if (!$dbDumpEnabled) {
            return self::STRATEGY_SNAPSHOT;
        }

        foreach ($containers as $container) {
            if (self::isDb($container) && self::isRunning($container)) {
                return self::STRATEGY_DUMP;
            }
        }

        return self::STRATEGY_SNAPSHOT;
    }

    /**
     * Apakah container ini server DB terdeteksi (klaim pemanggil).
     */
    public static function isDb(array $container): bool
    {
        if (array_key_exists('is_db', $container)) {
            return (bool) $container['is_db'];
        }
        return (bool) ($container['db'] ?? false);
    }

    /**
     * Apakah container hidup. `running` eksplisit menang; bila absen diturunkan
     * dari `state`/`State`/`status`.
     */
    public static function isRunning(array $container): bool
    {
        if (array_key_exists('running', $container)) {
            return (bool) $container['running'];
        }
        $state = strtolower((string) ($container['state'] ?? $container['State'] ?? $container['status'] ?? ''));
        return $state === 'running';
    }
}
