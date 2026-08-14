<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\UserStore;
use support\Request;

/**
 * Manage Users (SPECS.md §6.1) — semua user yang login punya akses penuh,
 * termasuk menambah/menghapus user lain (tanpa role/permission).
 */
class UserController
{
    public function index(Request $request)
    {
        return view('user/index', [
            'users' => (new UserStore())->all(),
            'currentUser' => current_user(),
        ]);
    }

    public function create(Request $request)
    {
        $username = (string) $request->post('username', '');
        $password = (string) $request->post('password', '');

        try {
            (new UserStore())->create($username, $password);
            flash_set('success', "User \"{$username}\" berhasil dibuat.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/users');
    }

    public function delete(Request $request, string $id)
    {
        $store = new UserStore();
        $user = $store->findById($id);

        if ($user === null) {
            flash_set('error', 'User tidak ditemukan.');
            return redirect('/users');
        }

        // cegah menghapus diri sendiri (agar selalu ada user yang bisa login)
        if (($user['id'] ?? '') === (current_user()['id'] ?? null)) {
            flash_set('error', 'Tidak bisa menghapus user yang sedang login.');
            return redirect('/users');
        }

        $store->delete($id);
        flash_set('success', "User \"{$user['username']}\" dihapus.");
        return redirect('/users');
    }

    public function changePassword(Request $request, string $id)
    {
        $password = (string) $request->post('password', '');
        $store = new UserStore();
        $user = $store->findById($id);

        if ($user === null) {
            flash_set('error', 'User tidak ditemukan.');
            return redirect('/users');
        }

        try {
            $store->changePassword($id, $password);
            flash_set('success', "Password user \"{$user['username']}\" diganti.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/users');
    }
}
