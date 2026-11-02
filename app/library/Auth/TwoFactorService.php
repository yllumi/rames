<?php
declare(strict_types=1);

namespace app\library\Auth;

use InvalidArgumentException;
use RuntimeException;

/**
 * Orkestrasi kebijakan **2FA TOTP** (setup, verifikasi login, pemulihan,
 * penonaktifan) — tanpa menyimpan state HTTP apa pun.
 *
 * Kelas ini hanya memegang {@see UserStore}; seluruh data lintas-request
 * (pending login, sesi setup, kode pemulihan sekali tampil) disimpan
 * controller di session dengan kunci konstanta di bawah. Algoritma TOTP ada di
 * {@see Totp}; hash kode pemulihan ada di {@see UserStore}.
 *
 * Invarian keamanan:
 *  - secret & kode pemulihan plaintext **tidak pernah** dicatat ke log/flash;
 *  - kode pemulihan hanya dikonsumsi di alur login (`verifyLoginCode()`);
 *    verifikasi untuk disable/regenerate hanya mencocokkan tanpa menghapus;
 *  - pesan kegagalan verifikasi seragam & generik.
 */
final class TwoFactorService
{
    /** Umur sesi "menunggu kode 2FA" setelah password benar (detik). */
    public const PENDING_TTL = 600;

    /** Umur sesi setup (secret sudah dibuat, belum dikonfirmasi) — detik. */
    public const SETUP_TTL = 900;

    /** Batas percobaan kode pada langkah kedua login. */
    public const MAX_ATTEMPTS = 5;

    /** Jumlah kode pemulihan yang dibuat sekali waktu. */
    public const RECOVERY_CODE_COUNT = 10;

    /** Session: `['user_id'=>string,'created_at'=>int,'attempts'=>int,'redirect'=>string]`. */
    public const SESSION_PENDING = '2fa_pending';

    /** Session: `['secret'=>string,'created_at'=>int]`. */
    public const SESSION_SETUP = '2fa_setup';

    /** Session: `array<int,string>` kode pemulihan plaintext — sekali tampil. */
    public const SESSION_RECOVERY = '2fa_recovery_once';

    /** Alfabet kode pemulihan tanpa karakter ambigu (0/O, 1/I/L). */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private UserStore $users;

    public function __construct(?UserStore $users = null)
    {
        $this->users = $users ?? new UserStore();
    }

    /**
     * Nama issuer pada URI `otpauth://` (brand dashboard aktif / `Rames`).
     */
    public static function issuer(): string
    {
        $name = function_exists('brand_name') ? trim((string) brand_name()) : '';

        return $name !== '' ? $name : 'Rames';
    }

    /**
     * URI `otpauth://totp/...` untuk akun tertentu.
     */
    public static function provisioningUri(string $secret, string $account): string
    {
        return Totp::provisioningUri($secret, $account, self::issuer());
    }

    /**
     * QR SVG untuk sebuah URI provisioning.
     */
    public static function qrSvg(string $uri, int $size = 320): string
    {
        return QrSvg::render($uri, $size);
    }

    /**
     * Apakah user (dokumen publik/session) sudah mengaktifkan 2FA.
     */
    public function isEnabled(array $user): bool
    {
        return UserStore::totpEnabled($user);
    }

    /**
     * Jumlah kode pemulihan tersisa.
     *
     * Menerima dokumen publik (tanpa hash) — bila hash tidak ada di array,
     * hitung dari store berdasarkan `id` user.
     */
    public function recoveryRemaining(array $user): int
    {
        if (isset($user['totp_recovery_codes']) && is_array($user['totp_recovery_codes'])) {
            return UserStore::recoveryCodeCount($user);
        }

        $id = (string) ($user['id'] ?? '');

        return $id === '' ? 0 : $this->users->recoveryCodesRemaining($id);
    }

    /**
     * Mulai setup 2FA: verifikasi password saat ini lalu buat secret + QR.
     *
     * @return array{secret:string,uri:string,qr_svg:string}
     * @throws InvalidArgumentException password salah / 2FA sudah aktif
     * @throws RuntimeException user tidak ditemukan
     */
    public function beginSetup(string $userId, string $password): array
    {
        if (!$this->users->verifyPassword($userId, $password)) {
            throw new InvalidArgumentException('Password saat ini salah.');
        }

        $user = $this->users->findPublicById($userId);
        if ($user === null) {
            throw new RuntimeException('User tidak ditemukan.');
        }
        if (UserStore::totpEnabled($user)) {
            throw new InvalidArgumentException('2FA sudah aktif.');
        }

        $secret = Totp::generateSecret();
        $uri = self::provisioningUri($secret, (string) ($user['username'] ?? ''));

        return [
            'secret' => $secret,
            'uri' => $uri,
            'qr_svg' => self::qrSvg($uri),
        ];
    }

    /**
     * Selesaikan setup: verifikasi kode terhadap secret **dari sesi setup**,
     * aktifkan 2FA, dan kembalikan kode pemulihan plaintext (sekali tampil).
     *
     * @return array<int,string>
     * @throws InvalidArgumentException kode salah / secret sesi tidak valid
     * @throws RuntimeException user tidak ditemukan
     */
    public function completeSetup(string $userId, string $secret, string $code, ?int $now = null): array
    {
        $secret = trim($secret);
        if ($secret === '' || Totp::verify($secret, $code, $now) === null) {
            throw new InvalidArgumentException('Kode verifikasi salah.');
        }

        $codes = $this->generateRecoveryCodes();
        $this->users->enableTotp($userId, $secret, $codes);

        return $codes;
    }

    /**
     * Nonaktifkan 2FA: password saat ini **dan** kode TOTP/kode pemulihan valid.
     *
     * @throws InvalidArgumentException password atau kode salah
     * @throws RuntimeException user tidak ditemukan
     */
    public function disable(string $userId, string $password, string $code, ?int $now = null): void
    {
        if (!$this->confirmWithPasswordAndCode($userId, $password, $code, $now)) {
            throw new InvalidArgumentException('Password atau kode verifikasi salah.');
        }

        $this->users->disableTotp($userId);
    }

    /**
     * Buat ulang kode pemulihan (syarat sama seperti {@see self::disable()}).
     *
     * @return array<int,string> kode plaintext (sekali tampil)
     * @throws InvalidArgumentException password atau kode salah
     * @throws RuntimeException user tidak ditemukan
     */
    public function regenerateRecoveryCodes(
        string $userId,
        string $password,
        string $code,
        ?int $now = null
    ): array {
        if (!$this->confirmWithPasswordAndCode($userId, $password, $code, $now)) {
            throw new InvalidArgumentException('Password atau kode verifikasi salah.');
        }

        $codes = $this->generateRecoveryCodes();
        $this->users->replaceRecoveryCodes($userId, $codes);

        return $codes;
    }

    /**
     * Verifikasi kode pada langkah kedua login: TOTP (anti-replay) **atau**
     * kode pemulihan (single-use — pemakaian menandai habis).
     */
    public function verifyLoginCode(string $userId, string $code, ?int $now = null): bool
    {
        if ($this->users->verifyTotp($userId, $code, $now)) {
            return true;
        }

        return $this->users->consumeRecoveryCode($userId, $code);
    }

    /**
     * Apakah tanda waktu pembuatan sesi sudah melewati TTL.
     *
     * `createdAt <= 0` dianggap kedaluwarsa (data sesi rusak/tidak lengkap).
     */
    public static function isExpired(int $createdAt, int $ttl, ?int $now = null): bool
    {
        if ($createdAt <= 0) {
            return true;
        }

        return (($now ?? time()) - $createdAt) > $ttl;
    }

    /**
     * Password wajib benar + kode TOTP/recovery valid (tanpa mengonsumsi kode
     * pemulihan — konsumsi hanya di alur login).
     */
    private function confirmWithPasswordAndCode(
        string $userId,
        string $password,
        string $code,
        ?int $now = null
    ): bool {
        if (!$this->users->verifyPassword($userId, $password)) {
            return false;
        }

        if ($this->users->verifyTotp($userId, $code, $now)) {
            return true;
        }

        return $this->matchesRecoveryCode($userId, $code);
    }

    /**
     * Cocokkan kode pemulihan tanpa menghapusnya (baca dokumen mentah store).
     */
    private function matchesRecoveryCode(string $userId, string $code): bool
    {
        $normalized = UserStore::normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }

        $hashes = $user['totp_recovery_codes'] ?? [];
        if (!is_array($hashes)) {
            return false;
        }

        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '' && password_verify($normalized, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kode pemulihan acak berformat `XXXX-XXXX` (alfabet tanpa ambigu).
     *
     * @return array<int,string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        $alphabetLength = strlen(self::RECOVERY_ALPHABET);

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $part = '';
            for ($j = 0; $j < 8; $j++) {
                $part .= self::RECOVERY_ALPHABET[random_int(0, $alphabetLength - 1)];
                if ($j === 3) {
                    $part .= '-';
                }
            }
            $codes[] = $part;
        }

        return $codes;
    }
}
