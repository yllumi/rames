<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\OwnershipMigrator;
use app\library\Auth\TwoFactorService;
use app\library\Auth\UserStore;
use support\Request;
use Throwable;
use Webman\Http\Response;
use Workerman\Protocols\Http\Session;

/**
 * Autentikasi dashboard (docs/SPECS.md §6).
 *
 * Alur login dua langkah (2FA TOTP):
 *  1. `POST /login` — username+password diverifikasi. User **tanpa** 2FA
 *     langsung mendapat session `user` (perilaku lama). User ber-2FA hanya
 *     mendapat session `2fa_pending` (tanpa `user`) lalu diarahkan ke
 *     `/login/2fa`; status 2FA tidak pernah dibocorkan sebelum password valid.
 *  2. `POST /login/2fa` — kode TOTP/kode pemulihan diverifikasi
 *     ({@see TwoFactorService::verifyLoginCode()}). Sukses ⇒ session
 *     dirotasi, `user` diisi dari store, migrasi kepemilikan app dijalankan
 *     (best-effort), lalu redirect ke tujuan awal (hanya path internal).
 *
 * Batas percobaan kode dibatasi {@see TwoFactorService::MAX_ATTEMPTS}; sesi
 * pending kedaluwarsa setelah {@see TwoFactorService::PENDING_TTL} detik.
 *
 * Catatan: dilarang memanggil session()/request() di konstruktor (lihat
 * copilot-instructions) — semua state diakses di method action.
 */
class AuthController
{
    /** Seam pengujian: `null` ⇒ store bawaan dibangun per-request. */
    private ?UserStore $users;

    /** Seam pengujian: `null` ⇒ service bawaan (memakai store seam) per-request. */
    private ?TwoFactorService $twoFactor;

    public function __construct(?UserStore $users = null, ?TwoFactorService $twoFactor = null)
    {
        $this->users = $users;
        $this->twoFactor = $twoFactor;
    }

    public function loginForm(Request $request): Response
    {
        if ($this->currentUser() !== null) {
            return redirect('/apps');
        }

        return $this->renderView('auth/login', []);
    }

    public function login(Request $request): Response
    {
        $username = trim((string) $request->post('username', ''));
        $password = (string) $request->post('password', '');
        $redirectTo = (string) $request->post('redirect', '/apps');

        $user = $this->users()->verify($username, $password);
        if ($user === null) {
            $this->flash('error', 'Username atau password salah.');

            return redirect('/login');
        }

        $session = $this->session($request);
        $userId = (string) ($user['id'] ?? '');

        // Langkah kedua: JANGAN set `user` sebelum kode verifikasi lolos.
        if (UserStore::totpEnabled($user)) {
            $session = $this->rotateSession($request);
            $session->delete('user');
            $session->set(TwoFactorService::SESSION_PENDING, [
                'user_id' => $userId,
                'created_at' => time(),
                'attempts' => 0,
                'redirect' => $this->safeRedirect($redirectTo),
            ]);

            return redirect('/login/2fa');
        }

        // Rotasi id session untuk hindari session fixation.
        $session = $this->rotateSession($request);
        $session->delete(TwoFactorService::SESSION_PENDING);
        $session->set('user', $user);

        // Migrasi app lama yang belum punya owner (idempoten, no-op bila bersih) —
        // dijalankan di sini supaya hanya sekali per login, bukan tiap request.
        $this->migrateOwnership();

        return redirect($this->safeRedirect($redirectTo));
    }

    /**
     * `GET /login/2fa` — form kode verifikasi langkah kedua.
     *
     * Payload view persis: `['username' => string, 'attempts_remaining' => int]`.
     */
    public function twoFactorForm(Request $request): Response
    {
        if ($this->currentUser() !== null) {
            return redirect('/apps');
        }

        $session = $this->session($request);
        $pending = $this->validPending($session);
        if ($pending === null) {
            $session->delete(TwoFactorService::SESSION_PENDING);
            $this->flash('error', 'Sesi verifikasi tidak valid. Silakan login ulang.');

            return redirect('/login');
        }

        $user = $this->users()->findPublicById($pending['user_id']);
        if ($user === null) {
            $session->delete(TwoFactorService::SESSION_PENDING);
            $this->flash('error', 'Sesi verifikasi tidak valid. Silakan login ulang.');

            return redirect('/login');
        }

        return $this->renderView('auth/two-factor', [
            'username' => (string) ($user['username'] ?? ''),
            'attempts_remaining' => max(0, TwoFactorService::MAX_ATTEMPTS - $pending['attempts']),
        ]);
    }

    /**
     * `POST /login/2fa` — verifikasi kode TOTP/kode pemulihan.
     */
    public function twoFactorVerify(Request $request): Response
    {
        $session = $this->session($request);
        $pending = $this->validPending($session);
        if ($pending === null) {
            $session->delete(TwoFactorService::SESSION_PENDING);
            $this->flash('error', 'Sesi verifikasi tidak valid. Silakan login ulang.');

            return redirect('/login');
        }

        $userId = $pending['user_id'];
        $user = $this->users()->findPublicById($userId);
        if ($user === null) {
            $session->delete(TwoFactorService::SESSION_PENDING);
            $this->flash('error', 'Sesi verifikasi tidak valid. Silakan login ulang.');

            return redirect('/login');
        }

        $rawCode = $request->post('code', '');
        if (!is_scalar($rawCode)) {
            $this->flash('error', 'Kode verifikasi salah.');

            return redirect('/login/2fa');
        }

        if ($this->twoFactor()->verifyLoginCode($userId, (string) $rawCode)) {
            $fresh = $this->users()->findPublicById($userId);
            if ($fresh === null) {
                // User dihapus di antara verifikasi & login: jangan isi session
                // dengan null, perlakukan sebagai sesi tidak valid.
                $this->flash('error', 'Sesi verifikasi tidak valid. Silakan login ulang.');

                return redirect('/login');
            }

            // Rotasi id session hanya setelah kode lolos (cegah fixation pada
            // id yang dipakai menampung `2fa_pending`).
            $session = $this->rotateSession($request);
            $session->delete(TwoFactorService::SESSION_PENDING);
            $session->set('user', $fresh);
            $this->migrateOwnership();

            return redirect($this->safeRedirect($pending['redirect']));
        }

        $attempts = $pending['attempts'] + 1;
        if ($attempts >= TwoFactorService::MAX_ATTEMPTS) {
            $session->delete(TwoFactorService::SESSION_PENDING);
            $this->flash('error', 'Terlalu banyak percobaan. Silakan login ulang.');

            return redirect('/login');
        }

        $session->set(TwoFactorService::SESSION_PENDING, [
            'user_id' => $userId,
            'created_at' => $pending['created_at'],
            'attempts' => $attempts,
            'redirect' => $pending['redirect'],
        ]);
        $this->flash('error', 'Kode verifikasi salah.');

        return redirect('/login/2fa');
    }

    public function logout(Request $request): Response
    {
        $session = $this->session($request);
        $session->delete('user');
        $session->flush();
        return redirect('/login');
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Sesi pending yang masih sah: ada, berbentuk array, belum kedaluwarsa,
     * dan punya `user_id` non-kosong.
     *
     * @return array{user_id:string,created_at:int,attempts:int,redirect:string}|null
     */
    private function validPending(Session $session): ?array
    {
        $raw = $session->get(TwoFactorService::SESSION_PENDING);
        if (!is_array($raw)) {
            return null;
        }

        $userId = $raw['user_id'] ?? '';
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        $createdAt = $raw['created_at'] ?? 0;
        if (!is_int($createdAt) || TwoFactorService::isExpired($createdAt, TwoFactorService::PENDING_TTL)) {
            return null;
        }

        $attempts = $raw['attempts'] ?? 0;
        if (!is_int($attempts) || $attempts < 0 || $attempts >= TwoFactorService::MAX_ATTEMPTS) {
            return null;
        }

        $redirect = $raw['redirect'] ?? '/apps';

        return [
            'user_id' => $userId,
            'created_at' => $createdAt,
            'attempts' => $attempts,
            'redirect' => $this->safeRedirect(is_string($redirect) ? $redirect : '/apps'),
        ];
    }

    /**
     * Migrasi kepemilikan app lama — best-effort, tidak boleh menggagalkan login.
     *
     * Seam pengujian: test menimpanya agar tidak menyentuh store app nyata.
     */
    protected function migrateOwnership(): void
    {
        try {
            (new OwnershipMigrator())->run();
        } catch (Throwable $e) {
            // migrasi bersifat best-effort; login tetap lanjut
        }
    }

    private function safeRedirect(string $to): string
    {
        // Cegah open redirect: hanya path internal yang diizinkan. Backslash dan
        // karakter kontrol ditolak karena browser menormalkan `/\evil.com` atau
        // `"/\t/evil.com"` menjadi `//evil.com`.
        if ($to !== '' && str_starts_with($to, '/')
            && !str_starts_with($to, '//')
            && !str_contains($to, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $to) !== 1
            && strlen($to) <= 2048) {
            return $to;
        }
        return '/apps';
    }

    // ==================================================================
    // Seam
    // ==================================================================

    /**
     * Session request (seam: `Request::session()` butuh koneksi HTTP).
     */
    protected function session(Request $request): Session
    {
        return $request->session();
    }

    /**
     * Rotasi id session (mitigasi session fixation) — seam pengujian.
     *
     * `Session::refresh()` di Workerman hanya memperbarui timestamp, **tidak**
     * mengganti id; karena itu dipakai `Request::sessionRegenerateId(true)`
     * yang membuat id baru, memindahkan data, dan menghapus sesi lama.
     */
    protected function rotateSession(Request $request): Session
    {
        $request->sessionRegenerateId(true);

        return $request->session();
    }

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
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        return view($template, $vars);
    }

    private function users(): UserStore
    {
        return $this->users ??= new UserStore();
    }

    private function twoFactor(): TwoFactorService
    {
        return $this->twoFactor ??= new TwoFactorService($this->users());
    }
}
