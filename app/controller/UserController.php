<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\CreditAccount;
use app\library\Deploy\ServerResolver;
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

        $store = $this->users();
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
            // Pilihan server untuk modal "Akses server": **hanya** `id`/`name`
            // (lewat `ServerResolver::option()` yang sudah membuang
            // token/PEM/base_url/notes). Daftar ini dipakai admin untuk
            // mengatur grant `server_select` + allowlist per user.
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * Pilihan server aman untuk modal `/users` — **hanya `id`/`name`**, diambil
     * lewat `ServerResolver::option()` (satu-satunya bentuk opsi server yang
     * diizinkan keluar). Token/PEM/base_url/notes/domain tidak pernah dikirim.
     *
     * Registry tak terbaca ⇒ daftar kosong (halaman Users tetap terbuka;
     * grant server hanya bisa diatur saat registry terbaca).
     *
     * @return array<int,array{id:string,name:string}>
     */
    private function serverOptions(): array
    {
        try {
            $servers = $this->servers()->all();
        } catch (\Throwable) {
            return [];
        }

        $options = [];
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $option = ServerResolver::option($server);
            $options[] = ['id' => $option['id'], 'name' => $option['name']];
        }

        return $options;
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
            $store = $this->users();
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

        $store = $this->users();
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
        $store = $this->users();
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
        $store = $this->users();
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
        $store = $this->users();
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
     * Atur hak **memilih server** user (POST /users/{id}/servers) — admin-only.
     *
     * `server_select` = checkbox (`'1'` = aktif). `server_ids[]` = allowlist
     * opsional; id yang tidak ada di registry **dibuang** (validasi terhadap
     * `ServerResolver::all()`), duplikat/kosong dinormalkan `UserStore`.
     * Checkbox tidak dicentang + allowlist kosong ⇒ grant dimatikan. Semua
     * perubahan disimpan dalam satu tulisan atomik `UserStore::setServerAccess()`.
     *
     * Tidak ada token/PEM/secret yang dikirim ke view/response — konteks hanya
     * flash + redirect (pola aksi admin lain di halaman ini).
     */
    public function setServerAccess(Request $request, string $id)
    {
        if (!$this->guardAdmin()) {
            return redirect('/apps');
        }

        $store = $this->users();
        $user = $store->findById($id);
        if ($user === null) {
            flash_set('error', 'User tidak ditemukan.');
            return redirect('/users');
        }

        $select = (string) $request->post('server_select', '') === '1';

        $rawIds = $request->post('server_ids', []);
        $rawIds = is_array($rawIds) ? $rawIds : [];

        $known = [];
        try {
            foreach ($this->servers()->all() as $server) {
                $serverId = is_array($server) ? (string) ($server['id'] ?? '') : '';
                if ($serverId !== '') {
                    $known[$serverId] = true;
                }
            }
        } catch (\Throwable $e) {
            // Registry tak terbaca: tolak perubahan (fail-closed). Menyimpan
            // allowlist kosong akan justru membuka **semua** server.
            flash_set('error', 'Registry server tidak terbaca: ' . $e->getMessage());
            return redirect('/users');
        }

        $ids = [];
        $dropped = 0;
        $submittedIds = 0;
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId)) {
                $dropped++;
                continue;
            }
            $serverId = trim((string) $rawId);
            if ($serverId === '') {
                continue;
            }
            $submittedIds++;
            if (!isset($known[$serverId])) {
                $dropped++;
                continue;
            }
            $ids[$serverId] = $serverId;
        }

        // Fail-closed: grant aktif + request MENGIRIM id (non-kosong) tetapi
        // semuanya tidak valid ⇒ tolak. Menyimpan `[]` berarti "semua server",
        // kebalikan dari maksud admin yang membatasi allowlist.
        if ($select && $submittedIds > 0 && $ids === []) {
            flash_set(
                'error',
                sprintf(
                    'Tidak ada id server yang valid (%d id tidak dikenal) — akses server user "%s" tidak diubah.',
                    $dropped,
                    (string) ($user['username'] ?? $id)
                )
            );
            return redirect('/users');
        }

        try {
            $store->setServerAccess($id, $select, array_values($ids));
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            return redirect('/users');
        }

        flash_set('success', sprintf(
            'Akses server user "%s" disimpan (%s%s).',
            (string) ($user['username'] ?? $id),
            $select
                ? ($ids === [] ? 'boleh memilih semua server' : count($ids) . ' server diizinkan')
                : 'pemilihan server dimatikan',
            $dropped > 0 ? ", {$dropped} id tidak dikenal diabaikan" : ''
        ));

        return redirect('/users');
    }

    /**
     * Store user (seam uji: `UserStore($path)` temp). Tanpa cache properti —
     * aman untuk worker Webman persistent.
     */
    protected function users(): UserStore
    {
        return new UserStore();
    }

    /**
     * Registry server (seam uji: `ServerResolver(new ServerStore($path))`).
     */
    protected function servers(): ServerResolver
    {
        return new ServerResolver();
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
