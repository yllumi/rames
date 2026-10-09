<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\CreditAccount;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Manage Users (SPECS.md §6.1) — khusus ADMIN.
 *
 * User dibedakan dua hal:
 *  - role global (admin|member): admin mengelola user & melihat semua app.
 *  - kepemilikan app (owner_id/members di apps.json): dibagi per app.
 *
 * Saat user dihapus, seluruh app yang dimilikinya dialihkan ke admin yang
 * menghapus supaya tidak ada app tanpa pemilik.
 */
class UserController
{
    public function index(Request $request)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

        $store = new UserStore();
        $apps = (new AppStore())->all();

        $ownerCounts = [];
        foreach ($apps as $app) {
            $ownerId = (string) ($app['owner_id'] ?? '');
            if ($ownerId !== '') {
                $ownerCounts[$ownerId] = ($ownerCounts[$ownerId] ?? 0) + 1;
            }
        }

        return view('user/index', [
            'users' => $store->listWithRoles(),
            'currentUser' => current_user(),
            'ownerCounts' => $ownerCounts,
            'totalApps' => count($apps),
            // Saldo kredit semua user (read-only) — diisi `CreditAccount` (baca
            // `database/billing.json`); user tanpa entri = 0.
            'balances' => (new CreditAccount())->allBalances(),
        ]);
    }

    public function create(Request $request)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

        $username = (string) $request->post('username', '');
        $password = (string) $request->post('password', '');
        $role = (string) $request->post('role', UserStore::ROLE_MEMBER);

        try {
            $store = new UserStore();
            $user = $store->create($username, $password);
            if (in_array($role, [UserStore::ROLE_ADMIN, UserStore::ROLE_MEMBER], true) && $role !== $user['role']) {
                $store->changeRole((string) $user['id'], $role);
            }
            flash_set('success', "User \"{$username}\" berhasil dibuat.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/users');
    }

    public function delete(Request $request, string $id)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

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

        // jangan sampai admin terakhir hilang (tidak ada yang bisa kelola user)
        if ($store->roleOf($user) === UserStore::ROLE_ADMIN && $store->countAdmins() <= 1) {
            flash_set('error', 'Tidak bisa menghapus satu-satunya admin.');
            return redirect('/users');
        }

        // App milik user dialihkan ke admin yang menghapus — tidak ada app
        // yang kehilangan pemilik (kebijakan yang disepakati).
        $adminId = (string) (current_user()['id'] ?? '');
        $previousOwnerBillable = $store->roleOf($user) !== UserStore::ROLE_ADMIN;
        $appsBefore = (new AppStore())->ownedBy($id);
        $moved = (new AppStore())->transferAllFrom($id, $adminId, $adminId);

        $store->delete($id);

        // Bila pemilik lama pihak yang ditagih (member) dan pemilik baru bebas
        // tagihan (admin), app yang masih hidup dihentikan agar tidak berjalan
        // tanpa akrual — aturan sama dengan pengalihan kepemilikan manual.
        $stopped = 0;
        if ((bool) config('deploy.billing_enabled', true) && $previousOwnerBillable) {
            $stopped = (new AppStopper())->stopTransferredToExemptOwner($appsBefore, true, true);
        }

        flash_set(
            'success',
            "User \"{$user['username']}\" dihapus."
            . ($moved > 0 ? " {$moved} app dialihkan ke Anda." : '')
            . ($stopped > 0 ? " {$stopped} app dihentikan otomatis (kini bebas tagihan kredit)." : '')
        );
        return redirect('/users');
    }

    public function changePassword(Request $request, string $id)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

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

    /**
     * Ubah role global user (POST /users/{id}/role).
     */
    public function changeRole(Request $request, string $id)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

        $role = (string) $request->post('role', '');
        $store = new UserStore();
        $user = $store->findById($id);

        if ($user === null) {
            flash_set('error', 'User tidak ditemukan.');
            return redirect('/users');
        }

        try {
            if ($store->roleOf($user) === UserStore::ROLE_ADMIN
                && $role !== UserStore::ROLE_ADMIN
                && $store->countAdmins() <= 1
            ) {
                throw new \RuntimeException('Tidak bisa menurunkan satu-satunya admin.');
            }
            if (($user['id'] ?? '') === (current_user()['id'] ?? null) && $role !== UserStore::ROLE_ADMIN) {
                throw new \RuntimeException('Tidak bisa menurunkan role Anda sendiri.');
            }
            $store->changeRole($id, $role);
            flash_set('success', "Role user \"{$user['username']}\" diset ke {$role}.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/users');
    }

    /**
     * Set/hapus email user (POST /users/{id}/email) — admin-only.
     *
     * Email dipakai sebagai prasyarat top-up Duitku (SPECS §7.12). String
     * kosong menghapus email (lihat `UserStore::setEmail()`).
     */
    public function setEmail(Request $request, string $id)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

        $email = (string) $request->post('email', '');
        $store = new UserStore();
        $user = $store->findById($id);

        if ($user === null) {
            flash_set('error', 'User tidak ditemukan.');
            return redirect('/users');
        }

        try {
            $store->setEmail($id, $email);
            flash_set('success', trim($email) === ''
                ? "Email user \"{$user['username']}\" dihapus."
                : "Email user \"{$user['username']}\" diset ke " . trim($email) . '.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/users');
    }

    /**
     * Kelola user = khusus admin.
     */
    private function guardAdmin(): bool
    {
        if (is_admin()) {
            return true;
        }
        flash_set('error', 'Halaman kelola user hanya untuk admin.');
        return false;
    }
}
