<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\UserStore;
use support\Request;

/**
 * Autentikasi dashboard (SPECS.md §6).
 *
 * Catatan: dilarang memanggil session()/request() di konstruktor (lihat
 * copilot-instructions) — semua state diakses di method action.
 */
class AuthController
{
    public function loginForm(Request $request)
    {
        if (current_user()) {
            return redirect('/apps');
        }
        return view('auth/login');
    }

    public function login(Request $request)
    {
        $username = trim((string) $request->post('username', ''));
        $password = (string) $request->post('password', '');
        $redirectTo = (string) $request->post('redirect', '/apps');

        $user = (new UserStore())->verify($username, $password);
        if ($user === null) {
            flash_set('error', 'Username atau password salah.');
            return redirect('/login');
        }

        // Regenerasi session id untuk hindari session fixation
        $session = $request->session();
        $session->refresh();
        $session->set('user', $user);

        return redirect($this->safeRedirect($redirectTo));
    }

    public function logout(Request $request)
    {
        $session = $request->session();
        $session->delete('user');
        $session->flush();
        return redirect('/login');
    }

    private function safeRedirect(string $to): string
    {
        // Cegah open redirect: hanya path internal yang diizinkan
        if ($to !== '' && str_starts_with($to, '/') && !str_starts_with($to, '//')) {
            return $to;
        }
        return '/apps';
    }
}
