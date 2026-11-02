<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\TwoFactorService;
use app\library\Auth\UserStore;
use support\Request;
use Throwable;
use Webman\Http\Response;
use Workerman\Protocols\Http\Session;

/**
 * Halaman **Profil** self-service user login (`GET /profile`) dan aksi
 * self-service diri sendiri: ubah email (`POST /profile/email`) serta ubah
 * password (`POST /profile/password`).
 *
 * Form email sebelumnya menumpang di halaman `/credits` (lihat
 * `CreditController::setEmail()`); halaman itu tetap dipertahankan untuk
 * kompatibilitas mundur, sedangkan `/profile` menjadi rumah barunya.
 *
 * Controller ini **mediator murni**: validasi email (format + batas 50
 * karakter) dan kebijakan panjang password tidak diduplikasi di sini —
 * seluruhnya didelegasikan ke {@see UserStore} (`setEmail()`,
 * `verifyPassword()`, `changePassword()`), sehingga aturan hanya hidup di satu
 * tempat.
 *
 * Invarian:
 *  - **anti-IDOR**: `id`/`user_id`/`username` dari request diabaikan mutlak;
 *    id selalu diambil dari user login (`current_user()`);
 *  - **tanpa state lintas-request**: satu-satunya properti adalah seam
 *    pengujian (`UserStore` berpath temp); `controller_reuse=false`
 *    (config/app.php) membuat controller baru tiap request;
 *  - input tidak valid ⇒ flash error + redirect, **bukan** HTTP 500;
 *  - password/hash tidak pernah dicatat ke log, flash, atau respons, dan
 *    tidak pernah di-trim (spasi bisa signifikan).
 */
class ProfileController
{
    /**
     * Seam pengujian (pola `CreditController`): `null` ⇒ `UserStore` bawaan
     * dibangun per-request, tanpa memo lintas-request.
     */
    private ?UserStore $users;

    /**
     * Seam 2FA (pola sama dengan `$users`): `null` ⇒ service bawaan dibangun
     * per-request memakai store seam.
     */
    private ?TwoFactorService $twoFactor;

    public function __construct(?UserStore $users = null, ?TwoFactorService $twoFactor = null)
    {
        $this->users = $users;
        $this->twoFactor = $twoFactor;
    }

    // ==================================================================
    // Halaman
    // ==================================================================

    /**
     * `GET /profile` — kirim user login + email terkini ke view.
     *
     * Payload minimal yang dijamin untuk view:
     * `['user' => array, 'email' => string, 'twoFactor' => array]` (email
     * selalu string, `''` bila belum diatur). `twoFactor` berbentuk:
     * ```
     * [
     *   'enabled' => bool,
     *   'recovery_remaining' => int,
     *   'setup' => null|['secret'=>string,'uri'=>string,'qr_svg'=>string],
     *   'recovery_codes' => null|array<int,string>,
     * ]
     * ```
     * `setup` berasal dari session setup yang belum kedaluwarsa; `recovery_codes`
     * diambil sekali dari session lalu dihapus (sekali tampil).
     * View `profile/index.php` mengatur `$pageTitle`/`$active` sendiri.
     */
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            // Seharusnya dijaga AuthMiddleware; tetap tangani dengan sopan agar
            // tidak fatal (JSON 401 untuk klien API, redirect untuk browser).
            return $this->unauthenticated($request);
        }

        return $this->renderView('profile/index', [
            'user' => $user,
            'email' => (string) ($user['email'] ?? ''),
            'twoFactor' => $this->twoFactorPayload($user, $this->session($request)),
        ]);
    }

    // ==================================================================
    // Aksi
    // ==================================================================

    /**
     * `POST /profile/email` — ubah email **user login sendiri**.
     *
     * Email kosong = hapus email (perilaku `UserStore::setEmail()`). Gagal
     * validasi ⇒ flash error + redirect ke `/profile` (bukan 500).
     */
    public function setEmail(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $rawEmail = $request->post('email', '');
        if (!is_scalar($rawEmail)) {
            // Input berbentuk array (`email[]=…`) bukan "hapus email" — tolak.
            $this->flash('error', 'Email tidak valid.');

            return redirect('/profile');
        }

        $email = trim((string) $rawEmail);

        try {
            // Anti-IDOR: id SELALU dari sesi, tak pernah dari request.
            $this->users()->setEmail((string) $user['id'], $email);
            $this->flash('success', $email === '' ? 'Email dihapus.' : 'Email diperbarui.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/profile');
    }

    /**
     * `POST /profile/password` — ubah password **user login sendiri**.
     *
     * Field wajib: `current_password`, `new_password`, `new_password_confirm`.
     *
     * Urutan penjagaan (semua kegagalan ⇒ flash error + redirect `/profile`,
     * **tanpa** mutasi dan **tanpa** HTTP 500):
     *  1. field harus skalar (input array ditolak sebagai "Password tidak
     *     valid.");
     *  2. password saat ini wajib lolos `verifyPassword()` — pesan seragam
     *     "Password saat ini salah." agar tidak membocorkan apakah user/hash
     *     ada;
     *  3. konfirmasi harus identik;
     *  4. kebijakan panjang minimum **tidak** diduplikasi: `changePassword()`
     *     yang memvalidasi dan melempar `InvalidArgumentException`, pesannya
     *     diteruskan apa adanya.
     *
     * Password tidak di-trim (spasi signifikan) dan tidak pernah dicatat.
     * Session user tidak disentuh: setelah sukses user tetap login.
     */
    public function changePassword(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $currentPassword = $request->post('current_password', '');
        $newPassword = $request->post('new_password', '');
        $newPasswordConfirm = $request->post('new_password_confirm', '');

        // Input array (`current_password[]=…`) tidak boleh sampai ke store.
        if (!is_scalar($currentPassword) || !is_scalar($newPassword) || !is_scalar($newPasswordConfirm)) {
            $this->flash('error', 'Password tidak valid.');

            return redirect('/profile');
        }

        $currentPassword = (string) $currentPassword;
        $newPassword = (string) $newPassword;
        $newPasswordConfirm = (string) $newPasswordConfirm;

        // Anti-IDOR: id SELALU dari sesi, tak pernah dari request.
        $id = (string) $user['id'];

        if (!$this->users()->verifyPassword($id, $currentPassword)) {
            $this->flash('error', 'Password saat ini salah.');

            return redirect('/profile');
        }

        if ($newPassword !== $newPasswordConfirm) {
            $this->flash('error', 'Konfirmasi password baru tidak cocok.');

            return redirect('/profile');
        }

        try {
            $this->users()->changePassword($id, $newPassword);
            $this->flash('success', 'Password diperbarui.');
        } catch (Throwable $e) {
            // Pesan kebijakan (mis. "Password minimal 6 karakter.") dari store.
            $this->flash('error', $e->getMessage());
        }

        return redirect('/profile');
    }

    // ==================================================================
    // Aksi 2FA TOTP (self-service, hanya untuk user login sendiri)
    // ==================================================================

    /**
     * `POST /profile/2fa/setup` — mulai setup 2FA.
     *
     * Field: `current_password`. Sukses ⇒ secret + QR disimpan di **session**
     * (`2fa_setup`, bukan respons/payload) dan halaman profil menampilkannya
     * sampai dikonfirmasi. Gagal apa pun ⇒ flash error + redirect, tanpa 500.
     */
    public function startTotpSetup(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $password = $request->post('current_password', '');
        if (!is_scalar($password)) {
            $this->flash('error', 'Password tidak valid.');

            return redirect('/profile');
        }

        $session = $this->session($request);

        try {
            // Anti-IDOR: id SELALU dari sesi, tak pernah dari request.
            $result = $this->twoFactor()->beginSetup((string) $user['id'], (string) $password);
            $session?->set(TwoFactorService::SESSION_SETUP, [
                'secret' => $result['secret'],
                'created_at' => time(),
            ]);
            $session?->delete(TwoFactorService::SESSION_RECOVERY);
            $this->flash('success', 'Pindai QR lalu masukkan kode dari aplikasi authenticator.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/profile');
    }

    /**
     * `POST /profile/2fa/enable` — konfirmasi kode dari authenticator.
     *
     * Secret diambil dari **session** setup (bukan dari request), wajib belum
     * kedaluwarsa. Sukses ⇒ 2FA aktif, kode pemulihan plaintext disimpan ke
     * session sekali tampil. Gagal ⇒ flash error tanpa menghapus session setup
     * (user boleh mengulang sampai TTL habis).
     */
    public function enableTotp(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $rawCode = $request->post('code', '');
        if (!is_scalar($rawCode)) {
            $this->flash('error', 'Kode verifikasi tidak valid.');

            return redirect('/profile');
        }

        $session = $this->session($request);
        $setup = $session?->get(TwoFactorService::SESSION_SETUP);
        $secret = is_array($setup) && is_string($setup['secret'] ?? null) ? trim($setup['secret']) : '';
        $createdAt = is_array($setup) && is_int($setup['created_at'] ?? null) ? $setup['created_at'] : 0;

        if ($secret === '' || TwoFactorService::isExpired($createdAt, TwoFactorService::SETUP_TTL)) {
            if (is_array($setup)) {
                $session?->delete(TwoFactorService::SESSION_SETUP);
            }
            $this->flash('error', 'Sesi pengaturan 2FA sudah berakhir. Silakan mulai ulang.');

            return redirect('/profile');
        }

        try {
            // Anti-IDOR: id SELALU dari sesi, tak pernah dari request.
            $codes = $this->twoFactor()->completeSetup((string) $user['id'], $secret, (string) $rawCode);
            $session?->delete(TwoFactorService::SESSION_SETUP);
            $session?->set(TwoFactorService::SESSION_RECOVERY, $codes);
            $this->flash('success', '2FA aktif. Simpan kode pemulihan berikut.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/profile');
    }

    /**
     * `POST /profile/2fa/disable` — matikan 2FA (password + kode TOTP/recovery).
     */
    public function disableTotp(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $fields = $this->twoFactorFields($request);
        if ($fields === null) {
            return redirect('/profile');
        }

        $session = $this->session($request);

        try {
            // Anti-IDOR: id SELALU dari sesi, tak pernah dari request.
            $this->twoFactor()->disable((string) $user['id'], $fields['password'], $fields['code']);
            $session?->delete(TwoFactorService::SESSION_SETUP);
            $session?->delete(TwoFactorService::SESSION_RECOVERY);
            $this->flash('success', '2FA dinonaktifkan.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/profile');
    }

    /**
     * `POST /profile/2fa/recovery` — buat ulang kode pemulihan
     * (password + kode TOTP/recovery, sama seperti penonaktifan).
     */
    public function regenerateTotpRecovery(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $fields = $this->twoFactorFields($request);
        if ($fields === null) {
            return redirect('/profile');
        }

        $session = $this->session($request);

        try {
            // Anti-IDOR: id SELALU dari sesi, tak pernah dari request.
            $codes = $this->twoFactor()->regenerateRecoveryCodes(
                (string) $user['id'],
                $fields['password'],
                $fields['code']
            );
            $session?->set(TwoFactorService::SESSION_RECOVERY, $codes);
            $this->flash('success', 'Kode pemulihan baru dibuat.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/profile');
    }

    // ==================================================================
    // Seam & dependensi
    // ==================================================================

    /**
     * User yang sedang login (seam; default membaca session).
     */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /**
     * Set flash message (seam; default memakai session).
     */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }

    /**
     * Render view (seam pengujian: `view()` butuh konteks HTTP).
     */
    protected function renderView(string $template, array $vars): Response
    {
        return view($template, $vars);
    }

    /**
     * Session request (seam: `Request::session()` butuh koneksi HTTP).
     *
     * `null` hanya terjadi saat mediator dipanggil **tanpa** konteks HTTP
     * (CLI/unit test tanpa seam); pada request nyata Webman selalu ada.
     */
    protected function session(Request $request): ?Session
    {
        if (!request()) {
            return null;
        }

        return $request->session();
    }

    /**
     * Payload kartu 2FA untuk view profil.
     *
     * `setup` dihitung dari session (`2fa_setup`) selama belum kedaluwarsa dan
     * 2FA belum aktif; secret hanya dikirim lewat payload halaman ini (bukan
     * flash). `recovery_codes` diambil sekali lalu dihapus dari session.
     *
     * @return array{enabled:bool,recovery_remaining:int,setup:null|array{secret:string,uri:string,qr_svg:string},recovery_codes:null|array<int,string>}
     */
    private function twoFactorPayload(array $user, ?Session $session): array
    {
        $enabled = UserStore::totpEnabled($user);

        $setup = null;
        $rawSetup = $session?->get(TwoFactorService::SESSION_SETUP);
        if (is_array($rawSetup)) {
            $secret = is_string($rawSetup['secret'] ?? null) ? trim($rawSetup['secret']) : '';
            $createdAt = is_int($rawSetup['created_at'] ?? null) ? $rawSetup['created_at'] : 0;
            if ($enabled || $secret === ''
                || TwoFactorService::isExpired($createdAt, TwoFactorService::SETUP_TTL)) {
                $session?->delete(TwoFactorService::SESSION_SETUP);
            } else {
                $uri = TwoFactorService::provisioningUri($secret, (string) ($user['username'] ?? ''));
                try {
                    $setup = [
                        'secret' => $secret,
                        'uri' => $uri,
                        'qr_svg' => $this->renderQr($uri),
                    ];
                } catch (Throwable) {
                    // Jangan biarkan halaman Profil 500 terus selama TTL setup:
                    // buang sesi setup agar user bisa memulai ulang.
                    $session?->delete(TwoFactorService::SESSION_SETUP);
                    $this->flash('error', 'Gagal membuat QR 2FA. Silakan coba aktifkan lagi.');
                }
            }
        }

        $recovery = $session?->pull(TwoFactorService::SESSION_RECOVERY);
        $recoveryCodes = null;
        if (is_array($recovery)) {
            $recoveryCodes = [];
            foreach ($recovery as $code) {
                if (is_scalar($code)) {
                    $recoveryCodes[] = (string) $code;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'recovery_remaining' => $this->twoFactor()->recoveryRemaining($user),
            'setup' => $setup,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * Ambil & validasi field `current_password`+`code` untuk aksi 2FA.
     *
     * Input array (`code[]=…`) ditolak sebagai "Kode verifikasi tidak valid."
     * tanpa memanggil service (tanpa mutasi).
     *
     * @return array{password:string,code:string}|null
     */
    private function twoFactorFields(Request $request): ?array
    {
        $password = $request->post('current_password', '');
        $code = $request->post('code', '');

        if (!is_scalar($password)) {
            $this->flash('error', 'Password tidak valid.');

            return null;
        }
        if (!is_scalar($code)) {
            $this->flash('error', 'Kode verifikasi tidak valid.');

            return null;
        }

        return ['password' => (string) $password, 'code' => (string) $code];
    }

    /**
     * Respons "belum login": 401 JSON untuk klien API, redirect `/login` untuk
     * browser — sejalan dengan `AuthMiddleware::unauthenticated()`.
     */
    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 401, 'msg' => 'Unauthorized'])->withStatus(401);
        }

        return redirect('/login');
    }

    private function users(): UserStore
    {
        return $this->users ?? new UserStore();
    }

    /**
     * Render QR SVG (seam pengujian: kegagalan library harus tertangkap).
     */
    protected function renderQr(string $uri): string
    {
        return TwoFactorService::qrSvg($uri);
    }

    private function twoFactor(): TwoFactorService
    {
        return $this->twoFactor ?? new TwoFactorService($this->users());
    }
}
