<?php
declare(strict_types=1);

namespace app\library\Auth;

use app\library\Storage\JsonStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penyimpanan user (database/auth.json).
 *
 * Multi user tanpa role/permission — semua user punya hak akses penuh
 * (SPECS.md §6.1). Password disimpan sebagai bcrypt hash, tidak pernah plaintext.
 */
class UserStore
{
    private JsonStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new JsonStore($filePath ?? (config('deploy.database_path') . '/auth.json'));
    }

    /**
     * @return array<int, array{id:string, username:string, password_hash:string, created_at:string}>
     */
    public function all(): array
    {
        return $this->store->read();
    }

    public function findById(string $id): ?array
    {
        foreach ($this->all() as $user) {
            if (($user['id'] ?? '') === $id) {
                return $user;
            }
        }
        return null;
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->all() as $user) {
            if (strcasecmp((string) ($user['username'] ?? ''), $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Verifikasi kredensial login. Kembalikan user (tanpa password_hash) jika valid.
     */
    public function verify(string $username, string $password): ?array
    {
        $user = $this->findByUsername($username);
        if ($user === null || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            return null;
        }
        return $this->publicUser($user);
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    /**
     * Tambah user baru. Melempar exception jika username sudah dipakai / input tidak valid.
     *
     * @return array{id:string, username:string, created_at:string}
     */
    public function create(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 64) {
            throw new InvalidArgumentException('Username tidak valid (1-64 karakter, tanpa spasi di ujung).');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Password minimal 6 karakter.');
        }

        $created = null;
        $this->store->update(function (array &$data) use ($username, $password, &$created): void {
            foreach ($data as $existing) {
                if (strcasecmp((string) ($existing['username'] ?? ''), $username) === 0) {
                    throw new RuntimeException("Username \"{$username}\" sudah dipakai.");
                }
            }
            $created = [
                'id' => bin2hex(random_bytes(8)),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'created_at' => date('c'),
            ];
            $data[] = $created;
        });

        return $this->publicUser($created ?? []);
    }

    public function delete(string $id): void
    {
        $this->store->update(function (array &$data) use ($id): void {
            $data = array_values(array_filter(
                $data,
                static fn (array $u): bool => ($u['id'] ?? '') !== $id
            ));
        });
    }

    public function changePassword(string $id, string $newPassword): void
    {
        if (strlen($newPassword) < 6) {
            throw new InvalidArgumentException('Password minimal 6 karakter.');
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->store->update(function (array &$data) use ($id, $hash): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') === $id) {
                    $user['password_hash'] = $hash;
                    return;
                }
            }
            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * @return array{id:string, username:string, created_at:string}
     */
    private function publicUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
