<?php
declare(strict_types=1);

namespace app\library\Auth;

use app\library\Storage\JsonStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penyimpanan user (database/auth.json).
 *
 * Struktur: id, username, password_hash, role (admin|member), created_at,
 * email (opsional — prasyarat top-up Duitku, lihat SPECS §7.12).
 * Password disimpan sebagai bcrypt hash, tidak pernah plaintext.
 *
 * Field `email` **tanpa migrasi**: entri lama yang belum punya `email` tetap
 * valid (`emailOf()` mengembalikan string kosong).
 *
 * Kepemilikan app (owner/member) diatur terpisah di database/apps.json —
 * lihat app\library\Auth\AppAccess.
 */
class UserStore
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    private JsonStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new JsonStore($filePath ?? (config('deploy.database_path') . '/auth.json'));
    }

    /**
     * @return array<int, array{id:string, username:string, password_hash:string, role:string, created_at:string}>
     */
    public function all(): array
    {
        return $this->store->read();
    }

    /**
     * Daftar user siap tampil (tanpa password_hash, dengan role hasil resolusi).
     *
     * @return array<int, array{id:string, username:string, role:string, created_at:string, email:string}>
     */
    public function listWithRoles(): array
    {
        $all = $this->all();
        $firstId = (string) ($all[0]['id'] ?? '');

        $users = [];
        foreach ($all as $user) {
            $users[] = $this->publicUser($user, $firstId);
        }
        return $users;
    }

    /**
     * Resolusi role user.
     *
     * Berkas auth.json versi lama belum punya field `role`. Aturan migrasinya
     * (tanpa menulis ulang berkas): user pertama = admin, sisanya member —
     * sehingga instalasi lama tetap punya minimal satu admin tanpa lockout.
     * Begitu sebuah entri punya `role` eksplisit, nilai itulah yang dipakai.
     */
    public function roleOf(array $user): string
    {
        $explicit = (string) ($user['role'] ?? '');
        if ($explicit === self::ROLE_ADMIN || $explicit === self::ROLE_MEMBER) {
            return $explicit;
        }

        return $this->legacyRole($user, (string) ($this->all()[0]['id'] ?? ''));
    }

    /**
     * Role untuk berkas legacy (tanpa field role): user pertama = admin.
     */
    private function legacyRole(array $user, string $firstUserId): string
    {
        $userId = (string) ($user['id'] ?? '');
        return ($firstUserId !== '' && $firstUserId === $userId) ? self::ROLE_ADMIN : self::ROLE_MEMBER;
    }

    /**
     * Apakah user (array dari session) adalah admin.
     */
    public function isAdmin(?array $user): bool
    {
        return $user !== null && $this->roleOf($user) === self::ROLE_ADMIN;
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
     * Cari user by id dalam bentuk aman (tanpa password_hash, sudah ada role).
     */
    public function findPublicById(string $id): ?array
    {
        $user = $this->findById($id);
        return $user === null ? null : $this->publicUser($user);
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
            // User pertama = admin (provisioning awal, SPECS.md §6.3).
            $isFirst = $data === [];
            $created = [
                'id' => bin2hex(random_bytes(8)),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role' => $isFirst ? self::ROLE_ADMIN : self::ROLE_MEMBER,
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

    /**
     * Ubah role user (admin|member).
     */
    public function changeRole(string $id, string $role): void
    {
        if ($role !== self::ROLE_ADMIN && $role !== self::ROLE_MEMBER) {
            throw new InvalidArgumentException('Role tidak valid.');
        }
        $this->store->update(function (array &$data) use ($id, $role): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') === $id) {
                    $user['role'] = $role;
                    return;
                }
            }
            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * Jumlah admin aktif — dipakai untuk mencegah penghapusan admin terakhir.
     */
    public function countAdmins(): int
    {
        $count = 0;
        foreach ($this->all() as $user) {
            if ($this->roleOf($user) === self::ROLE_ADMIN) {
                $count++;
            }
        }
        return $count;
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
     * Email user (dinormalisasi trim); string kosong bila belum diatur/blank.
     *
     * `email` adalah field **opsional** pada auth.json — user lama tetap valid
     * tanpa migrasi. Dipakai sebagai prasyarat top-up Duitku (SPECS §7.12).
     */
    public function emailOf(array $user): string
    {
        $email = $user['email'] ?? '';

        return is_string($email) ? trim($email) : '';
    }

    /**
     * Set/hapus email user.
     *
     * Validasi: format email (FILTER_VALIDATE_EMAIL) + panjang maksimum 50
     * karakter (batas praktis Duitku). String kosong (setelah trim) **menghapus**
     * field email. Pesan exception dalam Bahasa Indonesia.
     */
    public function setEmail(string $id, string $email): void
    {
        $email = trim($email);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Format email tidak valid.');
        }
        if (mb_strlen($email) > 50) {
            throw new InvalidArgumentException('Email maksimal 50 karakter.');
        }

        $this->store->update(function (array &$data) use ($id, $email): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') === $id) {
                    if ($email === '') {
                        unset($user['email']);
                    } else {
                        $user['email'] = $email;
                    }
                    return;
                }
            }
            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * @param string|null $firstUserId id user pertama (untuk berkas legacy tanpa role)
     * @return array{id:string, username:string, role:string, created_at:string, email:string}
     */
    private function publicUser(array $user, ?string $firstUserId = null): array
    {
        unset($user['password_hash']);
        $explicit = (string) ($user['role'] ?? '');
        $user['role'] = in_array($explicit, [self::ROLE_ADMIN, self::ROLE_MEMBER], true)
            ? $explicit
            : $this->legacyRole($user, $firstUserId ?? (string) ($this->all()[0]['id'] ?? ''));
        // Selalu hadir sebagai string ('' bila belum diatur) agar session & view
        // dapat menampilkannya tanpa memeriksa keberadaan key.
        $user['email'] = $this->emailOf($user);

        return $user;
    }
}
