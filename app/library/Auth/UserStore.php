<?php
declare(strict_types=1);

namespace app\library\Auth;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * Penyimpanan user — store logis `auth` di basis data SQLite
 * (`config('deploy.sqlite_file')`, dahulu `database/auth.json`). API publik
 * tidak berubah; `$filePath` konstruktor = berkas .sqlite (dapat di-override
 * saat tes).
 *
 * Struktur: id, username, password_hash, role (admin|member), created_at,
 * email (opsional — prasyarat top-up Duitku, lihat SPECS §7.12), dan akses
 * pemilihan server per user (`server_select` bool + `server_ids` allowlist,
 * lihat {@see self::setServerAccess()}).
 * Password disimpan sebagai bcrypt hash, tidak pernah plaintext.
 *
 * Field `email` **tanpa migrasi**: entri lama yang belum punya `email` tetap
 * valid (`emailOf()` mengembalikan string kosong). Field `server_select` /
 * `server_ids` juga tanpa migrasi: dokumen lama dibaca sebagai `false` / `[]`.
 *
 * Kepemilikan app (owner/member) diatur di store `apps` pada basis data SQLite
 * yang sama — lihat app\library\Auth\AppAccess.
 */
class UserStore
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    /** Prefix plaintext API key (mudah dikenali di log/kebocoran). */
    public const API_KEY_PREFIX = 'rmk_';

    /** Panjang prefix plaintext yang disimpan untuk ditampilkan (bukan rahasia). */
    public const API_KEY_DISPLAY_LENGTH = 12;

    /** Interval minimum pembaruan `api_key_last_used_at` (detik). */
    public const API_KEY_TOUCH_INTERVAL = 60;

    private SqliteStore $store;

    public function __construct(?string $filePath = null)
    {
        $this->store = new SqliteStore('auth', SchemaMigrations::storeDefinitions()['auth'], $filePath);
    }

    /**
     * Path berkas basis data .sqlite (dahulu path `auth.json`).
     */
    public function path(): string
    {
        return $this->store->path();
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

    /**
     * Verifikasi password milik user tertentu **berdasarkan id**.
     *
     * Dipakai alur self-service "ubah password" di halaman Profil: id berasal
     * dari session user login, bukan dari username yang dikirim request.
     * Perbandingan memakai `password_verify()` (constant-time) — hash mentah
     * tidak pernah dibandingkan, dikembalikan, atau dicatat.
     *
     * Selalu `false` (tanpa exception/notice) bila user tidak ada, hash tidak
     * tersedia (legacy/korup/bukan string), atau password tidak cocok.
     * Password kosong selalu ditolak: kebijakan aplikasi mensyaratkan minimal
     * 6 karakter, dan hash bcrypt dari string kosong tidak boleh lolos.
     */
    public function verifyPassword(string $id, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $user = $this->findById($id);
        if ($user === null) {
            return false;
        }

        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
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
            // User pertama = admin (provisioning awal, docs/SPECS.md §6.3).
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

    // ==================================================================
    // API key per user (docs/SPECS.md §7.19)
    // ==================================================================
    //
    // Satu key aktif per user. Plaintext HANYA dikembalikan sekali oleh
    // `issueApiKey()`; yang disimpan adalah SHA-256 + prefix tampilan. Generate
    // ulang menimpa hash lama ⇒ key lama langsung tidak berlaku.

    /**
     * Buat (atau ganti) API key user. Mengembalikan **plaintext sekali**.
     *
     * @throws RuntimeException bila user tidak ditemukan
     */
    public function issueApiKey(string $id): string
    {
        $plain = self::API_KEY_PREFIX . bin2hex(random_bytes(24));
        $hash = self::hashApiKey($plain);
        $prefix = substr($plain, 0, self::API_KEY_DISPLAY_LENGTH);
        $now = date('c');

        $this->store->update(function (array &$data) use ($id, $hash, $prefix, $now): void {
            foreach ($data as &$user) {
                if ((string) ($user['id'] ?? '') !== $id) {
                    continue;
                }
                $user['api_key_hash'] = $hash;
                $user['api_key_prefix'] = $prefix;
                $user['api_key_created_at'] = $now;
                $user['api_key_last_used_at'] = '';

                return;
            }
            throw new RuntimeException('User tidak ditemukan.');
        });

        return $plain;
    }

    /**
     * Cabut API key user (idempoten: user tanpa key tetap aman).
     *
     * @throws RuntimeException bila user tidak ditemukan
     */
    public function revokeApiKey(string $id): void
    {
        $this->store->update(function (array &$data) use ($id): void {
            foreach ($data as &$user) {
                if ((string) ($user['id'] ?? '') !== $id) {
                    continue;
                }
                unset(
                    $user['api_key_hash'],
                    $user['api_key_prefix'],
                    $user['api_key_created_at'],
                    $user['api_key_last_used_at']
                );

                return;
            }
            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * Metadata API key untuk UI (tanpa hash/plaintext).
     *
     * @return array{active:bool,prefix:string,created_at:string,last_used_at:string}
     */
    public function apiKeyMeta(string $id): array
    {
        // Dokumen mentah: status aktif butuh `api_key_hash`, yang sengaja
        // dibuang `publicUser()` dari session/view/API.
        $user = $this->findById($id);
        if ($user === null) {
            return ['active' => false, 'prefix' => '', 'created_at' => '', 'last_used_at' => ''];
        }

        $prefix = (string) ($user['api_key_prefix'] ?? '');

        return [
            'active' => trim((string) ($user['api_key_hash'] ?? '')) !== '' && $prefix !== '',
            'prefix' => $prefix,
            'created_at' => (string) ($user['api_key_created_at'] ?? ''),
            'last_used_at' => (string) ($user['api_key_last_used_at'] ?? ''),
        ];
    }

    /**
     * User aktor pemilik API key — `null` bila key kosong/tidak dikenal.
     *
     * Perbandingan hash memakai `hash_equals()` (constant-time) terhadap hash
     * yang tersimpan; plaintext key **tidak pernah** disimpan.
     *
     * @return array<string,mixed>|null bentuk publik (tanpa hash/secret)
     */
    public function actorByApiKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || !str_starts_with($key, self::API_KEY_PREFIX)) {
            return null;
        }

        $hash = self::hashApiKey($key);
        $all = $this->all();
        $firstId = (string) ($all[0]['id'] ?? '');
        foreach ($all as $user) {
            $stored = (string) ($user['api_key_hash'] ?? '');
            if ($stored === '' || !hash_equals($stored, $hash)) {
                continue;
            }

            return $this->publicUser($user, $firstId);
        }

        return null;
    }

    /**
     * Perbarui `api_key_last_used_at` (throttle: maksimal sekali per
     * {@see self::API_KEY_TOUCH_INTERVAL} detik agar tidak menulis store tiap
     * request API).
     *
     * Best-effort oleh pemanggil: kegagalan mencatat pemakaian tidak boleh
     * menggagalkan request yang sudah terautentikasi.
     */
    public function touchApiKey(string $id, int $minIntervalSeconds = self::API_KEY_TOUCH_INTERVAL): void
    {
        $user = $this->findById($id);
        if ($user === null || trim((string) ($user['api_key_hash'] ?? '')) === '') {
            return;
        }

        $now = time();
        $last = (string) ($user['api_key_last_used_at'] ?? '');
        $lastTs = $last === '' ? false : strtotime($last);
        if ($lastTs !== false && $now - $lastTs < max(0, $minIntervalSeconds)) {
            return;
        }

        $stamp = date('c', $now);
        $this->store->update(function (array &$data) use ($id, $stamp): void {
            foreach ($data as &$user) {
                if ((string) ($user['id'] ?? '') === $id && trim((string) ($user['api_key_hash'] ?? '')) !== '') {
                    $user['api_key_last_used_at'] = $stamp;
                }
            }
        });
    }

    /** SHA-256 hex dari plaintext key — satu-satunya bentuk yang disimpan. */
    public static function hashApiKey(string $key): string
    {
        return hash('sha256', $key);
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
     * Apakah user diberi hak **memilih server** saat membuat app (grant per user).
     *
     * Normalisasi nilai dokumen (tanpa migrasi): `true`, `1`, `"1"`, `"true"`,
     * dan `"on"` dianggap aktif; selain itu nonaktif.
     *
     * Statik murni: tidak menyentuh store, jadi aman dipanggil dari
     * `ServerAccess` (satu sumber normalisasi, tanpa salinan aturan).
     */
    public static function serverSelectOf(array $user): bool
    {
        $value = $user['server_select'] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value == 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on'], true);
        }

        return false;
    }

    /**
     * Allowlist server milik user: array string unik, trim, tanpa nilai kosong.
     *
     * Allowlist **kosong** = semua server (hanya bila grant aktif); nilai
     * non-skalar diabaikan. Statik murni (tanpa store).
     *
     * @return array<int,string>
     */
    public static function serverIdsOf(array $user): array
    {
        $raw = $user['server_ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Simpan hak pemilihan server user dalam **satu tulisan atomik**:
     * `server_select` (bool) + `server_ids` (allowlist; kosong tetap `[]`).
     *
     * Validasi keberadaan user dilakukan di dalam transaksi (`update()`):
     * id tak dikenal ⇒ `RuntimeException`, tanpa efek samping. Allowlist
     * dinormalisasi seperti {@see self::serverIdsOf()} (unik, trim, buang kosong)
     * — validasi id terhadap registry server adalah tanggung jawab controller.
     *
     * @param array<int,mixed> $serverIds
     * @throws RuntimeException user tidak ditemukan
     */
    public function setServerAccess(string $id, bool $select, array $serverIds): void
    {
        $ids = [];
        foreach ($serverIds as $serverId) {
            if (!is_scalar($serverId)) {
                continue;
            }
            $serverId = trim((string) $serverId);
            if ($serverId !== '') {
                $ids[$serverId] = $serverId;
            }
        }
        $ids = array_values($ids);

        $this->store->update(function (array &$data) use ($id, $select, $ids): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') === $id) {
                    $user['server_select'] = $select;
                    $user['server_ids'] = $ids;
                    unset($user);
                    return;
                }
            }
            unset($user);
            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    // ==================================================================
    // Two-factor authentication (TOTP) — field tambahan pada dokumen user,
    // tanpa migrasi skema (kolom `data` SqliteStore = sumber kebenaran).
    // ==================================================================

    /**
     * Apakah 2FA TOTP aktif untuk user.
     *
     * Statik murni (tanpa store) sehingga aman dipakai di luar store — mis.
     * `AuthController::login()` yang menerima user hasil `verify()` (bentuk
     * publik, tanpa secret). Aktif = secret base32 terisi **dan**
     * `totp_enabled_at` terisi; dokumen publik membawa flag `totp_enabled`
     * yang ekuivalen.
     */
    public static function totpEnabled(array $user): bool
    {
        if (($user['totp_enabled'] ?? false) === true) {
            return true;
        }

        $secret = $user['totp_secret'] ?? '';
        $enabledAt = $user['totp_enabled_at'] ?? '';

        return is_string($secret) && trim($secret) !== ''
            && is_string($enabledAt) && trim($enabledAt) !== '';
    }

    /**
     * Jumlah kode pemulihan tersisa pada dokumen user (statik murni).
     */
    public static function recoveryCodeCount(array $user): int
    {
        $hashes = $user['totp_recovery_codes'] ?? [];
        if (!is_array($hashes)) {
            return 0;
        }

        $count = 0;
        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Secret TOTP mentah user (base32) atau `''` bila belum diatur.
     *
     * Hanya dipakai internal alur 2FA; **tidak** boleh dikirim ke view/session
     * selain saat setup.
     */
    public function totpSecret(string $id): string
    {
        $user = $this->findById($id);
        $secret = $user['totp_secret'] ?? '';

        return is_string($secret) ? trim($secret) : '';
    }

    /**
     * Aktifkan 2FA: simpan secret + hash kode pemulihan dalam **satu** tulis
     * atomik, reset anti-replay (`totp_last_step = 0`).
     *
     * Kode pemulihan dinormalisasi lewat {@see self::normalizeRecoveryCode()}
     * lalu di-hash bcrypt — plaintext tidak pernah disimpan.
     *
     * @param array<int,string> $recoveryCodes kode plaintext (sekali tampil)
     * @throws InvalidArgumentException secret kosong/tidak valid
     * @throws RuntimeException user tidak ditemukan
     */
    public function enableTotp(string $id, string $secret, array $recoveryCodes): void
    {
        // Normalisasi ke bentuk kanonik base32 (toleran spasi/hyphen/padding
        // saat disalin dari authenticator) dan tolak secret yang tak bisa
        // didekode / kosong.
        try {
            $decoded = Totp::base32Decode(trim($secret));
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Secret 2FA tidak valid.');
        }
        if ($decoded === '') {
            throw new InvalidArgumentException('Secret 2FA tidak valid.');
        }
        $secret = Totp::base32Encode($decoded);

        $hashes = [];
        foreach ($recoveryCodes as $code) {
            if (!is_scalar($code)) {
                continue;
            }
            $normalized = self::normalizeRecoveryCode((string) $code);
            if ($normalized === '') {
                continue;
            }
            $hashes[] = password_hash($normalized, PASSWORD_BCRYPT);
        }

        $this->store->update(function (array &$data) use ($id, $secret, $hashes): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') !== $id) {
                    continue;
                }
                $user['totp_secret'] = $secret;
                $user['totp_enabled_at'] = date('c');
                $user['totp_last_step'] = 0;
                $user['totp_recovery_codes'] = $hashes;
                unset($user);

                return;
            }
            unset($user);

            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * Nonaktifkan 2FA: hapus secret, waktu aktivasi, anti-replay, dan hash
     * kode pemulihan sekaligus.
     *
     * @throws RuntimeException user tidak ditemukan
     */
    public function disableTotp(string $id): void
    {
        $this->store->update(function (array &$data) use ($id): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') !== $id) {
                    continue;
                }
                unset(
                    $user['totp_secret'],
                    $user['totp_enabled_at'],
                    $user['totp_last_step'],
                    $user['totp_recovery_codes']
                );
                unset($user);

                return;
            }
            unset($user);

            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * Verifikasi kode TOTP user dengan anti-replay.
     *
     * Langkah yang cocok **harus** lebih besar dari `totp_last_step` (kode yang
     * sama tidak bisa dipakai dua kali). Sukses ⇒ langkah disimpan dalam satu
     * `update()`; gagal ⇒ tanpa mutasi.
     */
    public function verifyTotp(string $id, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $user = $this->findById($id);
        if ($user === null) {
            return false;
        }

        $secret = $user['totp_secret'] ?? '';
        if (!is_string($secret) || trim($secret) === '') {
            return false;
        }

        $step = Totp::verify($secret, $code, $timestamp, $window);
        if ($step === null) {
            return false;
        }

        $lastStep = (int) ($user['totp_last_step'] ?? 0);
        if ($step <= $lastStep) {
            return false;
        }

        $accepted = false;
        $this->store->update(function (array &$data) use ($id, $step, &$accepted): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') !== $id) {
                    continue;
                }
                // Periksa ulang di dalam transaksi: request paralel bisa sudah
                // memakai langkah yang sama.
                if ($step <= (int) ($user['totp_last_step'] ?? 0)) {
                    unset($user);

                    return;
                }
                $user['totp_last_step'] = $step;
                $accepted = true;
                unset($user);

                return;
            }
            unset($user);
        });

        return $accepted;
    }

    /**
     * Normalisasi kode pemulihan: uppercase, hanya A-Z0-9 (toleran spasi/tanda
     * hubung saat disalin dari tampilan).
     */
    public static function normalizeRecoveryCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($code)));
    }

    /**
     * Pakai satu kode pemulihan (single-use).
     *
     * Cocok dengan `password_verify()` terhadap salah satu hash; bila cocok,
     * entri tersebut dihapus dalam satu `update()`. Tidak cocok ⇒ `false` tanpa
     * mutasi.
     */
    public function consumeRecoveryCode(string $id, string $code): bool
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        $user = $this->findById($id);
        if ($user === null) {
            return false;
        }
        $hashes = $user['totp_recovery_codes'] ?? [];
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }

        $match = null;
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && $hash !== '' && password_verify($normalized, $hash)) {
                $match = $index;
                break;
            }
        }
        if ($match === null) {
            return false;
        }

        $consumed = false;
        $this->store->update(function (array &$data) use ($id, $normalized, &$consumed): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') !== $id) {
                    continue;
                }
                $hashes = $user['totp_recovery_codes'] ?? [];
                if (!is_array($hashes)) {
                    unset($user);

                    return;
                }
                foreach ($hashes as $index => $hash) {
                    if (is_string($hash) && $hash !== '' && password_verify($normalized, $hash)) {
                        unset($hashes[$index]);
                        $user['totp_recovery_codes'] = array_values($hashes);
                        $consumed = true;
                        break;
                    }
                }
                unset($user);

                return;
            }
            unset($user);
        });

        return $consumed;
    }

    /**
     * Ganti seluruh kode pemulihan dengan kumpulan baru (hash bcrypt).
     *
     * @param array<int,string> $codes kode plaintext (sekali tampil)
     * @throws RuntimeException user tidak ditemukan
     */
    public function replaceRecoveryCodes(string $id, array $codes): void
    {
        $hashes = [];
        foreach ($codes as $code) {
            if (!is_scalar($code)) {
                continue;
            }
            $normalized = self::normalizeRecoveryCode((string) $code);
            if ($normalized === '') {
                continue;
            }
            $hashes[] = password_hash($normalized, PASSWORD_BCRYPT);
        }

        $this->store->update(function (array &$data) use ($id, $hashes): void {
            foreach ($data as &$user) {
                if (($user['id'] ?? '') !== $id) {
                    continue;
                }
                $user['totp_recovery_codes'] = $hashes;
                unset($user);

                return;
            }
            unset($user);

            throw new RuntimeException('User tidak ditemukan.');
        });
    }

    /**
     * Jumlah kode pemulihan tersisa milik user (0 bila user tak ada/2FA mati).
     */
    public function recoveryCodesRemaining(string $id): int
    {
        $user = $this->findById($id);

        return $user === null ? 0 : self::recoveryCodeCount($user);
    }

    /**
     * @param string|null $firstUserId id user pertama (untuk berkas legacy tanpa role)
     * @return array{id:string, username:string, role:string, created_at:string, email:string, server_select:bool, server_ids:array<int,string>, totp_enabled:bool, api_key_prefix:string, api_key_created_at:string, api_key_last_used_at:string}
     */
    private function publicUser(array $user, ?string $firstUserId = null): array
    {
        // Evaluasi status 2FA SEBELUM rahasianya dibuang, lalu hanya ekspos flag
        // boolean — secret/hash kode pemulihan tidak boleh masuk session/view.
        $totpEnabled = self::totpEnabled($user);
        unset(
            $user['password_hash'],
            $user['totp_secret'],
            $user['totp_enabled_at'],
            $user['totp_last_step'],
            $user['totp_recovery_codes'],
            // Hash API key TIDAK PERNAH keluar dari store (session/view/API).
            $user['api_key_hash']
        );
        // Metadata API key (bukan rahasia) selalu bertipe string stabil.
        $user['api_key_prefix'] = (string) ($user['api_key_prefix'] ?? '');
        $user['api_key_created_at'] = (string) ($user['api_key_created_at'] ?? '');
        $user['api_key_last_used_at'] = (string) ($user['api_key_last_used_at'] ?? '');
        $explicit = (string) ($user['role'] ?? '');
        $user['role'] = in_array($explicit, [self::ROLE_ADMIN, self::ROLE_MEMBER], true)
            ? $explicit
            : $this->legacyRole($user, $firstUserId ?? (string) ($this->all()[0]['id'] ?? ''));
        // Selalu hadir sebagai string ('' bila belum diatur) agar session & view
        // dapat menampilkannya tanpa memeriksa keberadaan key.
        $user['email'] = $this->emailOf($user);
        // Hak pemilihan server juga selalu hadir dengan tipe stabil (bool/array)
        // agar session, view, dan `ServerAccess` tidak perlu menebak bentuknya.
        $user['server_select'] = self::serverSelectOf($user);
        $user['server_ids'] = self::serverIdsOf($user);
        // Tipe stabil: `totp_enabled` selalu bool pada bentuk publik.
        $user['totp_enabled'] = $totpEnabled;

        return $user;
    }
}
