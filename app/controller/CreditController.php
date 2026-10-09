<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\BillingPeriod;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\DuitkuClient;
use app\library\Billing\Invoicer;
use app\library\Billing\TopUpOrder;
use app\library\Billing\TopUpService;
use app\library\Billing\UsageMeter;
use app\library\Storage\AppStore;
use support\Request;
use Throwable;
use Webman\Http\Response;

/**
 * Halaman & aksi kredit (SPECS.md §7.12, plan §5.4/§5.7).
 *
 * Controller ini **mediator murni**: seluruh logika bisnis (saldo, ledger,
 * meteran, penagihan, top-up Duitku) berada di `app\library\Billing\*`. Di sini
 * hanya: baca data segar, panggil library, dan petakan hasil ke respons
 * (`view()`/`json()`/`redirect()`).
 *
 * Invarian yang ditegakkan:
 *  - **admin-only → 404** (`deposit`, `charge`) tanpa membocorkan keberadaannya;
 *  - `setEmail` **hanya** mengubah email user login sendiri (userId tak pernah
 *    diterima dari request — anti IDOR);
 *  - `topupReturn` hanya membaca order **milik user login** (404 bila bukan);
 *  - `methods` **tidak** memanggil jaringan saat halaman dirender; panggilan
 *    `DuitkuClient::paymentMethods()` hanya terjadi pada endpoint JSON ini;
 *  - **tanpa** state properti lintas-request: dependensi yang di-inject hanyalah
 *    seam pengujian (store berpath temp), sisanya dibangun per-request.
 *
 * `controller_reuse=false` (config/app.php) → instance controller baru tiap
 * request, sehingga tidak ada state yang bocor antar request.
 */
class CreditController
{
    private ?BillingStore $store;
    private ?UserStore $users;
    private ?AppStore $apps;
    private ?DuitkuClient $duitku;
    private ?Invoicer $invoicer;

    /**
     * Dependensi opsional = **seam pengujian** (pola `PaymentController`).
     * `null` ⇒ dibangun bawaan per-request (tanpa memo lintas-request).
     */
    public function __construct(
        ?BillingStore $store = null,
        ?UserStore $users = null,
        ?AppStore $apps = null,
        ?DuitkuClient $duitku = null,
        ?Invoicer $invoicer = null,
    ) {
        $this->store = $store;
        $this->users = $users;
        $this->apps = $apps;
        $this->duitku = $duitku;
        $this->invoicer = $invoicer;
    }

    // ==================================================================
    // Halaman
    // ==================================================================

    /**
     * `GET /credits` — ringkasan saldo, pemakaian berjalan, ledger, dan form.
     * Admin melihat seluruh user + aksi deposit/penagihan manual.
     */
    public function index(Request $request): Response
    {
        return view('credits/index', $this->viewData(null));
    }

    /**
     * `GET /credits/topup/return` — status order milik user login.
     *
     * `resultCode`/status dari query **tidak dipercaya**; kebenaran berasal dari
     * state order tersimpan (opsional di-refresh sekali lewat `reconcile()`,
     * yang sudah membatasi frekuensi). Order user lain / tidak ada ⇒ 404.
     */
    public function topupReturn(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->notFound();
        }

        $orderId = trim((string) $request->get('order', ''));
        if ($orderId === '') {
            return $this->notFound();
        }

        try {
            $order = $this->orders()->findForUser($orderId, (string) $user['id']);
        } catch (Throwable) {
            $order = null;
        }
        if ($order === null) {
            return $this->notFound();
        }

        if (($order['status'] ?? '') === 'pending') {
            try {
                // Kelasnya sudah membatasi frekuensi (BILLING_DUITKU_STATUS_MIN_INTERVAL).
                $order = $this->topups()->reconcile($orderId);
            } catch (Throwable) {
                // Biarkan state terakhir yang terbaca dipakai untuk render.
            }
        }

        return view('credits/index', $this->viewData($order));
    }

    // ==================================================================
    // Aksi
    // ==================================================================

    /**
     * `POST /credits/deposit` — deposit manual **admin-only** (non-admin → 404).
     */
    public function deposit(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->notFound();
        }

        $actor = (string) ($this->currentUser()['id'] ?? '');
        $userId = trim($this->postScalar($request, 'user_id'));
        $raw = trim($this->postScalar($request, 'amount'));
        $note = trim($this->postScalar($request, 'note'));

        if ($userId === '' || !is_numeric($raw)) {
            $this->flash('error', 'User tujuan dan nominal kredit wajib diisi dengan benar.');

            return redirect('/credits');
        }

        // Nominal positif = deposit; nominal negatif = pengurangan manual
        // (jenis `adjust`, satu-satunya jenis yang boleh negatif di CreditAccount).
        $amount = (float) $raw;
        $type = $amount < 0 ? 'adjust' : 'deposit';

        try {
            $this->accounts()->deposit($userId, $amount, $actor, $note, $type);
            $this->flash('success', $amount < 0 ? 'Pengurangan kredit berhasil dicatat.' : 'Deposit kredit berhasil dicatat.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/credits');
    }

    /**
     * `POST /credits/charge` — jalankan penagihan **admin-only** (non-admin → 404).
     *
     * `period` (`YYYY-MM`) opsional: bila diisi `runNow($period)`, selain itu
     * `runDue()` (periode tertunggak). Auto-stop (kebijakan `stop`) dijalankan
     * `Invoicer` lewat callback `AppStopper`.
     */
    public function charge(Request $request): Response
    {
        if (!$this->isAdmin()) {
            return $this->notFound();
        }

        $period = trim($this->postScalar($request, 'period'));

        try {
            $invoicer = $this->invoicer();
            $rows = $period !== '' ? $invoicer->runNow($period) : $invoicer->runDue();

            $users = [];
            $total = 0.0;
            $stopped = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $users[(string) ($row['user_id'] ?? '')] = true;
                $total += (float) ($row['amount'] ?? 0.0);
                $stopped += (int) ($row['apps_stopped'] ?? 0);
            }

            if ($users === []) {
                $this->flash('info', 'Tidak ada pemakaian tertunggak untuk ditagih.');
            } else {
                $message = 'Penagihan dijalankan: ' . count($users) . ' user, total '
                    . format_credits($total) . ' kredit.';
                if ($stopped > 0) {
                    $message .= ' ' . $stopped . ' app dihentikan karena saldo negatif.';
                }
                $this->flash('success', $message);
            }
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/credits');
    }

    /**
     * `POST /credits/email` — user login mana pun, **hanya email dirinya**.
     *
     * `userId` **tidak pernah** dibaca dari request (anti IDOR): id diambil dari
     * user login.
     */
    public function setEmail(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->notFound();
        }

        $rawEmail = $request->post('email', '');
        if (!is_scalar($rawEmail)) {
            // Input berbentuk array bukan "hapus email" — tolak sebagai tidak valid.
            $this->flash('error', 'Email tidak valid.');

            return redirect('/credits');
        }
        $email = (string) $rawEmail;

        try {
            $this->users()->setEmail((string) $user['id'], $email);
            $this->flash('success', trim($email) === '' ? 'Email dihapus.' : 'Email diperbarui.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return redirect('/credits');
    }

    /**
     * `POST /credits/topup` — mulai top-up Duitku.
     *
     * Fitur/kredensial tidak lengkap ⇒ 404. Sukses ⇒ 302 ke URL pembayaran
     * Duitku; gagal ⇒ flash pesan ramah (sudah tanpa kredensial) + kembali.
     */
    public function topup(Request $request): Response
    {
        if (!$this->topupConfigured()) {
            return $this->notFound();
        }

        $user = $this->currentUser();
        if ($user === null) {
            return $this->notFound();
        }

        $amountRaw = trim($this->postScalar($request, 'amount_idr'));
        $amount = is_numeric($amountRaw) ? (int) $amountRaw : 0;
        $method = strtoupper(trim($this->postScalar($request, 'method')));

        if ($amount <= 0 || preg_match('/^[A-Z0-9]{2}$/', $method) !== 1) {
            $this->flash('error', 'Nominal dan metode pembayaran tidak valid.');

            return redirect('/credits');
        }

        try {
            $result = $this->topups()->start((string) $user['id'], $amount, $method);
            $url = trim((string) ($result['payment_url'] ?? ''));
            if ($url === '') {
                $this->flash('error', 'Gateway pembayaran tidak mengembalikan URL pembayaran.');

                return redirect('/credits');
            }

            return redirect($url);
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());

            return redirect('/credits');
        }
    }

    /**
     * `GET /api/credits/methods` — JSON daftar metode pembayaran (login wajib, GET).
     *
     * Satu-satunya jalur yang memanggil `DuitkuClient::paymentMethods()` (jaringan).
     * Fitur mati / kredensial tidak lengkap ⇒ `{code:0,data:[]}` tanpa jaringan.
     */
    public function methods(Request $request): Response
    {
        if (!$this->topupConfigured()) {
            return json(['code' => 0, 'data' => []]);
        }

        try {
            $raw = $this->duitku()->paymentMethods((int) $request->get('amount', 0));
            $data = [];
            foreach ($raw as $method) {
                if (!is_array($method)) {
                    continue;
                }
                $data[] = [
                    'code' => (string) ($method['code'] ?? ''),
                    'name' => (string) ($method['name'] ?? ''),
                    'image' => (string) ($method['image'] ?? ''),
                    'fee' => (int) ($method['fee'] ?? 0),
                ];
            }

            return json(['code' => 0, 'data' => $data]);
        } catch (Throwable) {
            // Jangan bocorkan detail internal/gateway.
            return json(['code' => 1, 'msg' => 'Gagal memuat metode pembayaran.']);
        }
    }

    // ==================================================================
    // Data view
    // ==================================================================

    /**
     * Nilai POST yang dijamin skalar: input berbentuk array/objek dari klien
     * diperlakukan sebagai string kosong sehingga tidak memicu
     * `Array to string conversion` (yang akan menjadi error 500).
     */
    private function postScalar(Request $request, string $key, string $default = ''): string
    {
        $value = $request->post($key, $default);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Seluruh variabel view `credits/index` (dipakai `index()` & `topupReturn()`).
     *
     * Semua pembacaan dibungkus `try/catch` supaya halaman **tidak pernah** 500
     * hanya karena satu berkas runtime tak terbaca. **Tanpa** panggilan jaringan.
     *
     * @param array<string,mixed>|null $focusOrder order yang sedang ditampilkan
     * @return array<string,mixed>
     */
    private function viewData(?array $focusOrder): array
    {
        $user = $this->currentUser();
        $admin = $this->isAdmin();
        $userId = (string) ($user['id'] ?? '');

        $balance = 0.0;
        $ledger = [];
        $pendingOrders = [];
        $email = '';

        try {
            $balance = $this->accounts()->balance($userId);
            $ledger = $this->accounts()->ledger($userId, 20);
            $pendingOrders = $this->orders()->pendingForUser($userId);

            $found = $this->users()->findById($userId);
            if ($found !== null) {
                $email = $this->users()->emailOf($found);
            }
        } catch (Throwable) {
            // Fail-safe: render halaman dengan nilai default.
        }

        // Pemakaian berjalan HANYA untuk app milik user ini.
        $usage = [];
        try {
            foreach ($this->usage()->summary() as $appId => $row) {
                if ((string) ($row['owner_id'] ?? '') === $userId) {
                    $usage[(string) $appId] = $row;
                }
            }
        } catch (Throwable) {
            $usage = [];
        }

        // Peta id → username (hanya admin; dipakai tabel saldo/order & atribusi ledger).
        $users = [];
        $balances = [];
        $allPendingOrders = [];
        $names = [];
        if ($admin) {
            try {
                $users = $this->users()->listWithRoles();
                $balances = $this->accounts()->allBalances();
                $allPendingOrders = $this->orders()->allPending();
                foreach ($users as $entry) {
                    $names[(string) ($entry['id'] ?? '')] = (string) ($entry['username'] ?? '');
                }
            } catch (Throwable) {
                $users = [];
                $balances = [];
                $allPendingOrders = [];
                $names = [];
            }
        }

        return [
            'enabled' => (bool) config('deploy.billing_enabled', true),
            'isAdmin' => $admin,
            'user' => $user,
            'email' => $email,
            'balance' => $balance,
            'ledger' => $ledger,
            'usage' => $usage,
            'pendingOrders' => $pendingOrders,
            'allPendingOrders' => $allPendingOrders,
            'users' => $users,
            'balances' => $balances,
            'names' => $names,
            'topupEnabled' => $this->topupConfigured(),
            'topupIssues' => $admin ? $this->topupIssues() : [],
            'methods' => $this->methodCodes(),
            'topupMin' => (int) config('deploy.billing_topup_min_idr', 10000),
            'topupMax' => (int) config('deploy.billing_topup_max_idr', 5000000),
            'idrPerCredit' => (float) config('deploy.billing_topup_idr_per_credit', 1.0),
            'minDepositDays' => (int) config('deploy.billing_min_deposit_days', 30),
            'periodLabel' => BillingPeriod::label(BillingPeriod::current()),
            'focusOrder' => $focusOrder,
        ];
    }

    /**
     * Kode kanal statis dari config (allowlist yang juga dipakai library) —
     * **tanpa** panggilan jaringan; JS menggantinya dengan daftar asli saat load.
     *
     * @return array<int,string>
     */
    private function methodCodes(): array
    {
        $raw = (string) config('deploy.billing_duitku_methods', '');
        $client = $this->duitku();
        $codes = [];

        foreach (explode(',', $raw) as $code) {
            $code = strtoupper(trim($code));
            if (preg_match('/^[A-Z0-9]{2}$/', $code) !== 1) {
                continue;
            }
            if (!$client->isAllowedMethod($code)) {
                continue;
            }
            $codes[$code] = true;
        }

        return array_keys($codes);
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
     * Apakah user login admin global (seam).
     *
     * Perbandingan identik dengan helper `is_admin()` — ditulis di sini agar
     * mediator dapat diuji tanpa memuat `app/functions.php` (helper global).
     */
    protected function isAdmin(): bool
    {
        $user = $this->currentUser();

        return $user !== null && (string) ($user['role'] ?? '') === AppAccess::ROLE_ADMIN;
    }

    /**
     * Set flash message (seam; default memakai session).
     */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }

    /**
     * Top-up siap dipakai? (`BILLING_TOPUP_ENABLED` + kredensial lengkap.)
     * Tanpa jaringan.
     */
    private function topupConfigured(): bool
    {
        if ((bool) config('deploy.billing_topup_enabled', false) === false) {
            return false;
        }

        try {
            return $this->duitku()->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Alasan top-up belum siap — **hanya untuk admin** (ditampilkan di `/credits`).
     * Member cukup melihat form-nya muncul/tidak; menyebut nama env ke member
     * hanya membocorkan detail infrastruktur.
     *
     * @return array<int,string>
     */
    private function topupIssues(): array
    {
        if (!(bool) config('deploy.billing_topup_enabled', false)) {
            return ['Top-up online nonaktif: set `BILLING_TOPUP_ENABLED=true` di .env lalu restart dashboard.'];
        }

        try {
            $issues = $this->duitku()->configurationIssues();
        } catch (Throwable $e) {
            return ['Konfigurasi Duitku tidak dapat dibaca: ' . $e->getMessage()];
        }

        return $issues === [] ? [] : $issues;
    }

    /**
     * Respons 404 polos (bukan 403) — pola `BackupController::notFound()`.
     */
    private function notFound(): Response
    {
        return json(['code' => 404, 'msg' => 'Not Found'])->withStatus(404);
    }

    private function store(): BillingStore
    {
        return $this->store ?? new BillingStore();
    }

    private function users(): UserStore
    {
        return $this->users ?? new UserStore();
    }

    private function apps(): AppStore
    {
        return $this->apps ?? new AppStore();
    }

    private function duitku(): DuitkuClient
    {
        return $this->duitku ?? new DuitkuClient();
    }

    private function accounts(): CreditAccount
    {
        return new CreditAccount($this->store(), $this->users());
    }

    private function usage(): UsageMeter
    {
        return new UsageMeter($this->apps(), $this->store(), $this->users());
    }

    private function orders(): TopUpOrder
    {
        return new TopUpOrder($this->store());
    }

    private function topups(): TopUpService
    {
        return new TopUpService($this->store(), null, $this->duitku(), $this->users());
    }

    /**
     * Penagihan manual dengan kebijakan auto-stop yang sama seperti scheduler
     * (`BillingRunner`): saldo negatif ⇒ hentikan app owner.
     */
    private function invoicer(): Invoicer
    {
        if ($this->invoicer !== null) {
            return $this->invoicer;
        }

        return new Invoicer(
            $this->apps(),
            $this->store(),
            $this->users(),
            static function (string $userId, array $apps = []): int {
                return (new AppStopper())->stopOwnedBy($userId, $apps);
            }
        );
    }
}
