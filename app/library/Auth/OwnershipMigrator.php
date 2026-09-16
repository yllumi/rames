<?php
declare(strict_types=1);

namespace app\library\Auth;

use app\library\Storage\AppStore;

/**
 * Migrasi kepemilikan app untuk data lama (database/apps.json).
 *
 * Sebelum fitur ownership ada, entri app tidak punya `owner_id`. Migrasi ini
 * menugaskannya ke owner default (admin pertama, atau user yang diberikan),
 * sehingga aplikasi lama tidak "hilang" dari daftar semua user.
 *
 * Idempoten: bila semua app sudah punya owner & field `members`, tidak ada
 * penulisan berkas sama sekali.
 */
class OwnershipMigrator
{
    /**
     * @param string|null $defaultOwnerId owner yang dipakai (default: admin pertama,
     *                                    fallback: user pertama)
     * @return int jumlah app yang di-assign owner
     */
    public function run(?string $defaultOwnerId = null): int
    {
        $users = (new UserStore())->listWithRoles();
        if ($users === []) {
            return 0; // belum ada user (provisioning awal) — tidak ada owner yang valid
        }

        $ownerId = trim((string) $defaultOwnerId);
        if ($ownerId === '') {
            foreach ($users as $user) {
                if (($user['role'] ?? '') === UserStore::ROLE_ADMIN) {
                    $ownerId = (string) $user['id'];
                    break;
                }
            }
        }
        if ($ownerId === '') {
            $ownerId = (string) ($users[0]['id'] ?? '');
        }

        return (new AppStore())->assignMissingOwners($ownerId);
    }
}
