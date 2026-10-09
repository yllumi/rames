<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use app\library\Deploy\ResourceLimits;

/**
 * Gerbang kredit — satu-satunya pintu penegakan sebelum aksi yang menyalakan
 * container (create/deploy, rebuild, rollback, start, simpan env/network/nama).
 *
 * Aturan: lewati bila `billing_enabled` mati atau user ber-role global `admin`
 * (admin gratis); selain itu `balance < required` ⇒ lempar
 * `InsufficientCredits` (blokir fungsional — **bukan** otorisasi; otorisasi
 * tetap milik `AppAccess`).
 *
 * Stateless: saldo selalu dibaca segar dari berkas, tidak di-cache di properti
 * statik/lintas-request (worker Webman persistent).
 */
class BillingGate
{
    private CreditAccount $accounts;

    /** @var array{cpu:float,ram:float}|array<string,float> */
    private array $rates;

    private int $days;

    /**
     * @param array{cpu:float,ram:float}|array<string,float>|null $rates
     */
    public function __construct(?CreditAccount $accounts = null, ?array $rates = null)
    {
        $this->accounts = $accounts ?? new CreditAccount();
        $this->rates = $rates ?? Pricing::rates();
        $this->days = (int) config('deploy.billing_min_deposit_days', 30);
    }

    public function isEnabled(): bool
    {
        return (bool) config('deploy.billing_enabled', true);
    }

    /**
     * Deposit minimum untuk `$limits` (estimasi `BILLING_MIN_DEPOSIT_DAYS` hari).
     *
     * @param array<int|string,mixed> $limits
     */
    public function requiredFor(array $limits): float
    {
        return Pricing::requiredDeposit($limits, $this->rates, $this->days);
    }

    /**
     * Gerbang untuk app yang sudah ada (limit dibaca dari field `limits`).
     */
    public function assertCanStart(array $app, ?array $user): void
    {
        $this->assertCanCreate(ResourceLimits::of($app), $user);
    }

    /**
     * Gerbang untuk app yang akan dibuat (limit dari pilihan form create).
     *
     * @param array<int|string,mixed> $limits
     */
    public function assertCanCreate(array $limits, ?array $user): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if ($user === null) {
            // Tidak ada user di konteks ini (belum login) — biarkan AuthMiddleware
            // yang menolak; gerbang kredit bukan lapisan autentikasi.
            return;
        }
        // `is_admin()` (app/functions.php) memakai aturan yang sama: role yang
        // sudah di-resolve AuthMiddleware dari auth.json.
        if (($user['role'] ?? '') === UserStore::ROLE_ADMIN) {
            return;
        }

        $userId = (string) ($user['id'] ?? '');
        $required = $this->requiredFor($limits);
        $balance = $userId === '' ? 0.0 : $this->accounts->balance($userId);

        if ($balance < $required) {
            throw new InsufficientCredits($balance, $required);
        }
    }
}
