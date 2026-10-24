<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\UserStore;
use support\Request;
use Throwable;
use Webman\Http\Response;

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

    public function __construct(?UserStore $users = null)
    {
        $this->users = $users;
    }

    // ==================================================================
    // Halaman
    // ==================================================================

    /**
     * `GET /profile` — kirim user login + email terkini ke view.
     *
     * Payload minimal yang dijamin untuk view:
     * `['user' => array, 'email' => string]` (email selalu string, `''` bila
     * belum diatur). View `profile/index.php` mengatur `$pageTitle`/`$active`
     * sendiri.
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
}
