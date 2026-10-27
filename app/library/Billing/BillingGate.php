<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use app\library\Deploy\ResourceLimits;
use app\library\Storage\AppStore;

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

    /** Kalkulator GB backup — DI opsional agar dapat diuji dengan store temp. */
    private ?BackupUsage $backup;

    /** Resolver tarif per server (lazy; tidak pernah menyimpan secret). */
    private ?ServerRates $serverRates;

    /**
     * `true` bila `$rates` diberikan eksplisit (seam uji/BC): tarif itu yang
     * dipakai untuk semua server, persis perilaku lama.
     */
    private bool $ratesExplicit;

    /**
     * `$users` bersifat **opsional aditif** (BC-safe) supaya resolusi role
     * (termasuk admin legacy tanpa field `role`) dapat diuji dengan
     * `UserStore($path)` temp tanpa menyentuh basis data nyata. Bila `null`,
     * `UserStore` default baru dibuat **hanya saat** resolusi role benar-benar
     * dibutuhkan (role eksplisit tidak menyentuh basis data).
     *
     * `$backup` juga opsional aditif: hanya dipakai `assertCanBackup()`; saat
     * `null`, `BackupUsage` default (registry nyata) baru dibuat lazy.
     *
     * `$serverRates` opsional aditif (parameter terakhir): tarif per server app
     * untuk deposit/gate. `$rates` eksplisit (biasanya hanya dari tes) tetap
     * menang — BC-safe; bila `null`, tarif per server dipakai (jatuh ke global
     * untuk server tanpa tarif).
     *
     * @param array{cpu:float,ram:float}|array<string,float>|null $rates
     */
    public function __construct(
        ?CreditAccount $accounts = null,
        ?array $rates = null,
        ?UserStore $users = null,
        ?BackupUsage $backup = null,
        ?ServerRates $serverRates = null,
    ) {
        $this->accounts = $accounts ?? new CreditAccount();
        $this->ratesExplicit = $rates !== null;
        $this->rates = $rates ?? Pricing::rates();
        $this->days = (int) config('deploy.billing_min_deposit_days', 30);
        $this->users = $users;
        $this->backup = $backup;
        $this->serverRates = $serverRates;
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
     * `$serverId` opsional: tarif server target dipakai bila `$rates` tidak
     * diberikan eksplisit; tanpa id / id tak dikenal ⇒ tarif global.
     *
     * @param array<int|string,mixed> $limits
     */
    public function requiredFor(array $limits, ?string $serverId = null): float
    {
        return Pricing::requiredDeposit($limits, $this->ratesFor($serverId), $this->days);
    }

    /**
     * Tarif CPU/RAM efektif: eksplisit (tes/BC) → tarif server → tarif global.
     *
     * @return array{cpu:float,ram:float}
     */
    private function ratesFor(?string $serverId): array
    {
        if ($this->ratesExplicit || $serverId === null || trim($serverId) === '') {
            return $this->rates;
        }

        return $this->serverRates()->ratesFor($serverId);
    }

    /**
     * Resolver tarif per server (lazy). `null` ⇒ default store dari config
     * (pemanggil produksi); tes yang butuh path temp menyuntiknya.
     */
    private function serverRates(): ServerRates
    {
        return $this->serverRates ??= new ServerRates();
    }

    /**
     * Gerbang untuk app yang sudah ada (limit dibaca dari field `limits` dan
     * tarif dari server app tersebut).
     */
    public function assertCanStart(array $app, ?array $user): void
    {
        $this->assertCanCreate(ResourceLimits::of($app), $user, AppStore::serverIdOf($app));
    }

    /**
     * Gerbang untuk menyalakan backup volume (berkala) milik app dengan
     * pemakaian snapshot `$gb` (GB **ditagih**, sudah termasuk floor per app).
     *
     * Semantik sama dengan {@see assertCanCreate()}: dilewati bila billing mati,
     * `$user` null (autentikasi bukan tanggung jawab gerbang), atau user exempt
     * (admin). Selain itu `balance < required` ⇒ lempar `InsufficientCredits`;
     * `required` = `BackupUsage::requiredDeposit($gb)` (estimasi satu bulan,
     * `BILLING_MIN_DEPOSIT_DAYS`).
     */
    public function assertCanBackup(float $gb, ?array $user, ?string $serverId = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if ($user === null) {
            return;
        }
        if ($this->isExempt($user)) {
            return;
        }

        $userId = (string) ($user['id'] ?? '');
        // Tarif backup **server target** (id kosong/tak dikenal ⇒ global).
        $required = $this->backupUsage()->requiredDeposit(
            $gb,
            $this->serverRates()->backupMonthlyFor((string) $serverId)
        );
        $balance = $userId === '' ? 0.0 : $this->accounts->balance($userId);

        if ($balance < $required) {
            throw new InsufficientCredits($balance, $required);
        }
    }

    /**
     * `BackupUsage` hidup (lazy): dibuat hanya saat `assertCanBackup()` benar-benar
     * membutuhkannya sehingga gerbang CPU/RAM tidak menyentuh store backup.
     */
    private function backupUsage(): BackupUsage
    {
        return $this->backup ??= new BackupUsage();
    }

    /**
     * Gerbang untuk app yang akan dibuat (limit dari pilihan form create).
     *
     * `$serverId` = server target pembuatan app; tarif server itu dipakai bila
     * `$rates` tidak diberikan eksplisit (tanpa id ⇒ tarif global).
     *
     * @param array<int|string,mixed> $limits
     */
    public function assertCanCreate(array $limits, ?array $user, ?string $serverId = null): void
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
        $required = $this->requiredFor($limits, $serverId);
        $balance = $userId === '' ? 0.0 : $this->accounts->balance($userId);

        if ($balance < $required) {
            throw new InsufficientCredits($balance, $required);
        }
    }
}
