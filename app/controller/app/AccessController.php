<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Auth\AppAccess;
use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;

/**
 * Kepemilikan & sharing app (owner + members, §7).
 *
 * Route: `POST /apps/{id}/members`, `/members/{userId}/remove`, `/owner`.
 * Semua cek hak lewat `AppAccess`; akses tidak sah = 404.
 */
class AccessController extends AppControllerBase
{
    /**
     * Tambah / ubah akses seorang user ke app (POST /apps/{id}/members).
     * Hanya owner app (atau admin) yang boleh — ability 'sharing'.
     */
    public function addMember(Request $request, string $id)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        $userId = trim((string) $request->post('user_id', ''));
        $role = strtolower(trim((string) $request->post('role', AppAccess::ROLE_VIEWER)));

        try {
            if (!in_array($role, AppAccess::ASSIGNABLE_ROLES, true)) {
                throw new RuntimeException('Role tidak valid.');
            }
            $target = (new UserStore())->findPublicById($userId);
            if ($target === null) {
                throw new RuntimeException('User tidak ditemukan.');
            }
            $store->addMember($id, $userId, $role, (string) (current_user()['id'] ?? ''));
            flash_set('success', 'Akses "' . $target['username'] . '" diset sebagai ' . AppAccess::label($role) . '.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    /**
     * Cabut akses seorang member (POST /apps/{id}/members/{userId}/remove).
     */
    public function removeMember(Request $request, string $id, string $userId)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        try {
            $current = $store->find($id);
            if ($current !== null && (string) ($current['owner_id'] ?? '') === $userId) {
                throw new RuntimeException('Owner app tidak bisa dicabut. Pindahkan kepemilikan (transfer owner) lebih dulu.');
            }
            if ((string) (current_user()['id'] ?? '') === $userId) {
                throw new RuntimeException('Tidak bisa mencabut akses Anda sendiri.');
            }
            $store->removeMember($id, $userId);
            flash_set('success', 'Akses user dicabut.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    /**
     * Pindahkan kepemilikan app ke user lain (POST /apps/{id}/owner).
     * Owner lama tetap terdaftar sebagai co-owner (role owner) agar tidak
     * kehilangan akses mendadak.
     */
    public function transferOwner(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'sharing');

        $userId = trim((string) $request->post('user_id', ''));

        try {
            $users = new UserStore();
            $target = $users->findPublicById($userId);
            if ($target === null) {
                throw new RuntimeException('User tidak ditemukan.');
            }

            $previousOwner = $users->findPublicById((string) ($app['owner_id'] ?? ''));

            $store->transferOwner($id, $userId, (string) (current_user()['id'] ?? ''));
            flash_set('success', 'Kepemilikan app dipindahkan ke "' . $target['username'] . '". Anda tetap terdaftar sebagai co-owner.');

            // Pemilik lama ditagih, pemilik baru bebas tagihan (admin) & app masih
            // hidup ⇒ app akan berjalan tanpa akrual. Hentikan supaya tidak ada
            // pemakaian gratis; admin bisa menyalakannya kembali.
            $previousOwnerBillable = $previousOwner !== null && !$users->isAdmin($previousOwner);
            $newOwnerExempt = $users->isAdmin($target);
            if ((bool) config('deploy.billing_enabled', true)
                && AppStopper::shouldStopOnTransfer($app, $previousOwnerBillable, $newOwnerExempt)
            ) {
                $updated = $store->find($id) ?? $app;
                if ((new AppStopper())->stopForBillingEscape($updated, $previousOwnerBillable, $newOwnerExempt)) {
                    flash_set('info', 'App dihentikan otomatis karena pemilik barunya bebas tagihan kredit — nyalakan kembali bila diperlukan.');
                }
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }
}
