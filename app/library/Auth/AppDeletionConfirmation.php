<?php
declare(strict_types=1);

namespace app\library\Auth;

/**
 * Validasi konfirmasi eksplisit sebelum record app atau resource-nya dihapus.
 *
 * Stateless dan murni: nilai harus berupa string yang sama persis dengan nama
 * app; tidak ada trim/case-folding agar input yang hampir cocok tetap ditolak.
 */
final class AppDeletionConfirmation
{
    /**
     * Apakah input konfirmasi cocok persis dengan nama app.
     */
    public static function matches(mixed $confirmation, string $appName): bool
    {
        return is_string($confirmation) && hash_equals($appName, $confirmation);
    }
}
