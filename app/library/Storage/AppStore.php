<?php
declare(strict_types=1);

namespace app\library\Storage;

use app\library\Auth\AppAccess;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penyimpanan app (database/apps.json). Struktur app mengikuti SPECS.md §7.1,
 * ditambah kepemilikan: `owner_id` + `members` (map userId → {role, ...}).
 *
 * Pemeriksaan hak akses TIDAK dilakukan di sini — gunakan AppAccess.
 */
class AppStore
{
    private JsonStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new JsonStore($filePath ?? (config('deploy.database_path') . '/apps.json'));
    }

    /**
     * @return array<int,array>
     */
    public function all(): array
    {
        return $this->store->read();
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $app) {
            if (($app['id'] ?? '') === $id) {
                return $app;
            }
        }
        return null;
    }

    public function findByName(string $name): ?array
    {
        foreach ($this->all() as $app) {
            if (($app['name'] ?? '') === $name) {
                return $app;
            }
        }
        return null;
    }

    public function nameExists(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    public function create(array $app): array
    {
        $app['id'] = $app['id'] ?? bin2hex(random_bytes(8));
        $app['created_at'] = $app['created_at'] ?? date('c');
        $app['updated_at'] = date('c');
        $app['owner_id'] = (string) ($app['owner_id'] ?? '');
        $app['members'] = is_array($app['members'] ?? null) ? $app['members'] : [];

        $this->store->update(function (array &$data) use ($app): void {
            foreach ($data as $existing) {
                if (($existing['name'] ?? '') === $app['name']) {
                    throw new RuntimeException("Nama app \"{$app['name']}\" sudah dipakai.");
                }
            }
            $data[] = $app;
        });

        return $app;
    }

    /**
     * Update app by id; mutator(array &$app): void.
     */
    public function update(string $id, callable $mutator): array
    {
        $updated = null;
        $this->store->update(function (array &$data) use ($id, $mutator, &$updated): void {
            foreach ($data as &$app) {
                if (($app['id'] ?? '') === $id) {
                    $mutator($app);
                    $app['updated_at'] = date('c');
                    $updated = $app;
                    return;
                }
            }
            throw new RuntimeException("App tidak ditemukan: {$id}");
        });

        return $updated ?? [];
    }

    public function delete(string $id): void
    {
        $this->store->update(function (array &$data) use ($id): void {
            $data = array_values(array_filter(
                $data,
                static fn (array $s): bool => ($s['id'] ?? '') !== $id
            ));
        });
    }

    // ==================================================================
    // Kepemilikan & sharing (owner_id + members)
    // ==================================================================

    /**
     * Tambah / ubah role member app (idempoten).
     *
     * @param string $role salah satu AppAccess::ASSIGNABLE_ROLES
     */
    public function addMember(string $id, string $userId, string $role, string $addedBy = ''): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new InvalidArgumentException('User tidak valid.');
        }
        if (!in_array($role, AppAccess::ASSIGNABLE_ROLES, true)) {
            throw new InvalidArgumentException('Role tidak valid.');
        }

        return $this->update($id, function (array &$app) use ($userId, $role, $addedBy): void {
            if ((string) ($app['owner_id'] ?? '') === $userId) {
                throw new RuntimeException('User tersebut sudah menjadi owner app ini.');
            }
            $members = is_array($app['members'] ?? null) ? $app['members'] : [];
            $existing = $members[$userId] ?? null;
            $members[$userId] = [
                'role' => $role,
                'added_at' => is_array($existing) ? (string) ($existing['added_at'] ?? date('c')) : date('c'),
                'added_by' => is_array($existing) ? (string) ($existing['added_by'] ?? $addedBy) : $addedBy,
                'updated_at' => date('c'),
            ];
            $app['members'] = $members;
        });
    }

    /**
     * Hapus akses seorang member.
     */
    public function removeMember(string $id, string $userId): array
    {
        return $this->update($id, function (array &$app) use ($userId): void {
            $members = is_array($app['members'] ?? null) ? $app['members'] : [];
            if (array_key_exists($userId, $members)) {
                unset($members[$userId]);
            }
            $app['members'] = $members;
        });
    }

    /**
     * Pindahkan kepemilikan app ke user lain.
     *
     * Owner lama TIDAK kehilangan akses — ia tetap terdaftar sebagai
     * co-owner (member role `owner`) supaya serah-terima tidak memutus akses
     * secara mendadak.
     */
    public function transferOwner(string $id, string $userId, string $actorId = ''): array
    {
        return $this->update($id, function (array &$app) use ($userId, $actorId): void {
            $previous = (string) ($app['owner_id'] ?? '');
            $members = is_array($app['members'] ?? null) ? $app['members'] : [];

            unset($members[$userId]); // owner baru tidak perlu entri member
            if ($previous !== '' && $previous !== $userId) {
                $members[$previous] = [
                    'role' => AppAccess::ROLE_OWNER,
                    'added_at' => date('c'),
                    'added_by' => $actorId,
                    'updated_at' => date('c'),
                ];
            }

            $app['owner_id'] = $userId;
            $app['members'] = $members;
        });
    }

    /**
     * Serahkan SEMUA app milik $fromUserId ke $toUserId dan hapus keanggotaan
     * user tersebut dari semua app — dipakai saat user dihapus.
     *
     * @return int jumlah app yang diserahkan
     */
    public function transferAllFrom(string $fromUserId, string $toUserId, string $actorId = ''): int
    {
        if ($fromUserId === '' || $toUserId === '' || $fromUserId === $toUserId) {
            return 0;
        }

        $moved = 0;
        $this->store->update(function (array &$data) use ($fromUserId, $toUserId, $actorId, &$moved): void {
            foreach ($data as &$app) {
                if ((string) ($app['owner_id'] ?? '') === $fromUserId) {
                    $app['owner_id'] = $toUserId;
                    $moved++;
                }
                $members = is_array($app['members'] ?? null) ? $app['members'] : [];
                if (array_key_exists($fromUserId, $members)) {
                    unset($members[$fromUserId]);
                    $app['members'] = $members;
                }
            }
            unset($app);
        });

        return $moved;
    }

    /**
     * Daftar app yang dimiliki seorang user (owner_id).
     *
     * @return array<int,array>
     */
    public function ownedBy(string $userId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $app): bool => (string) ($app['owner_id'] ?? '') === $userId
        ));
    }

    /**
     * Migrasi data lama: app tanpa `owner_id` di-assign ke owner default
     * (biasanya admin pertama). Idempoten — tidak menulis bila tidak perlu.
     *
     * @return int jumlah app yang di-assign
     */
    public function assignMissingOwners(string $ownerId): int
    {
        if ($ownerId === '') {
            return 0;
        }

        // Cek dulu tanpa menulis — dipanggil pada jalur login, jadi berkas yang
        // sudah bersih tidak boleh ikut ditulis ulang.
        $needs = false;
        foreach ($this->all() as $app) {
            if ((string) ($app['owner_id'] ?? '') === '' || !is_array($app['members'] ?? null)) {
                $needs = true;
                break;
            }
        }
        if (!$needs) {
            return 0;
        }

        $count = 0;
        $this->store->update(function (array &$data) use ($ownerId, &$count): void {
            foreach ($data as &$app) {
                if ((string) ($app['owner_id'] ?? '') === '') {
                    $app['owner_id'] = $ownerId;
                    $app['updated_at'] = date('c');
                    $count++;
                }
                if (!is_array($app['members'] ?? null)) {
                    $app['members'] = [];
                }
            }
            unset($app);
        });

        return $count;
    }
}
