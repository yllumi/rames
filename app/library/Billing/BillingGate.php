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

    /** `$users` opsional (lazy): hanya dibaca untuk resolusi role legacy. */
    private ?UserStore $users;

    /**
     * `$users` bersifat **opsional aditif** (BC-safe) supaya resolusi role
     * (termasuk admin legacy tanpa field `role`) dapat diuji dengan
     * `UserStore($path)` temp tanpa menyentuh basis data nyata. Bila `null`,
     * `UserStore` default baru dibuat **hanya saat** resolusi role benar-benar
     * dibutuhkan (role eksplisit tidak menyentuh basis data).
     *
     * @param array{cpu:float,ram:float}|array<string,float>|null $rates
     */
    public function __construct(?CreditAccount $accounts = null, ?array $rates = null, ?UserStore $users = null)
    {
        $this->accounts = $accounts ?? new CreditAccount();
        $this->rates = $rates ?? Pricing::rates();
        $this->days = (int) config('deploy.billing_min_deposit_days', 30);
        $this->users = $users;
    }

    /**
     * Resolusi "bebas tagihan" tanpa memaksa membangun `CreditAccount`.
     *
     * Satu sumber kebenaran: role **eksplisit** (`admin`/`member`) dipakai apa
     * adanya; role **legacy** (tanpa field `role`, mis. user pertama pada
     * instalasi lama) di-resolve lewat `UserStore::isAdmin()` sehingga user
     * pertama tetap dikenali sebagai admin. Store yang tak terbaca ⇒ bentuk
     * paling ketat (hanya admin eksplisit yang bebas). `null` (tanpa konteks
     * login) ⇒ false — autentikasi urusan `AuthMiddleware`.
     */
    public static function roleIsExempt(?array $user, ?UserStore $users = null): bool
    {
        if ($user === null) {
            return false;
        }

        $role = (string) ($user['role'] ?? '');
        if ($role === UserStore::ROLE_ADMIN) {
            return true;
        }
        if ($role === UserStore::ROLE_MEMBER) {
            return false;
        }

        try {
            return ($users ?? new UserStore())->isAdmin($user);
        } catch (\Throwable) {
            // Basis data tak terbaca: jangan menggagalkan aksi karena masalah
            // store — jatuh ke bentuk paling ketat.
            return false;
        }
    }

    /**
     * Apakah `$user` **bebas tagihan** (admin gratis)?
     *
     * Delegasi ke {@see roleIsExempt()} dengan store yang di-inject saat
     * konstruksi (bila ada).
     */
    public function isExempt(?array $user): bool
    {
        return self::roleIsExempt($user, $this->users);
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
        // Satu sumber kebenaran "bebas tagihan": role admin **ter-resolve**
        // (`UserStore::isAdmin()`), bukan `$user['role']` mentah — admin legacy
        // tanpa field `role` tetap bebas kredit.
        if ($this->isExempt($user)) {
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
