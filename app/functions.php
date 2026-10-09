<?php
declare(strict_types=1);

/**
 * Helper global dashboard deployer.
 */

use app\library\Auth\AppAccess;
use app\library\Auth\UserStore;
use app\library\Billing\CreditAccount;
use support\Request;

if (!function_exists('e')) {
    /**
     * HTML escape helper.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('current_user')) {
    /**
     * User yang sedang login dari session, atau null.
     */
    function current_user(): ?array
    {
        return session()->get('user');
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Token CSRF yang disimpan di session (dibuat jika belum ada).
     */
    function csrf_token(): string
    {
        $s = session();
        $token = $s->get('csrf_token');
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $s->set('csrf_token', $token);
        }
        return $token;
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden input CSRF untuk form.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('flash_set')) {
    /**
     * Set pesan flash sekali tampil (type: success|error|info).
     */
    function flash_set(string $type, string $message): void
    {
        session()->set('flash', ['type' => $type, 'message' => $message]);
    }
}

if (!function_exists('flash_pull')) {
    /**
     * Ambil & hapus pesan flash.
     */
    function flash_pull(): ?array
    {
        return session()->pull('flash');
    }
}

if (!function_exists('app_subdomain')) {
    /**
     * Subdomain default sebuah app = {name}.{APP_DOMAIN}.
     */
    function app_subdomain(string $name): string
    {
        return $name . '.' . config('deploy.app_domain', 'example.com');
    }
}

if (!function_exists('app_subdomain_of')) {
    /**
     * FQDN subdomain **efektif** sebuah app.
     *
     * Field `subdomain` di `apps.json` menyimpan **label** slug (mis.
     * `blog-x7k2`) — FQDN dirakit `{label}.{APP_DOMAIN}`. Bila field kosong /
     * tidak ada, dipakai `{name}.{APP_DOMAIN}` (perilaku lama, tanpa migrasi).
     * Nilai lama yang sudah berupa FQDN penuh (mengandung titik) dihormati apa
     * adanya supaya data lama tetap berfungsi.
     *
     * Ini satu-satunya jalur yang boleh menurunkan subdomain — jangan menebak
     * dari `name` langsung.
     */
    function app_subdomain_of(array $app): string
    {
        $sub = strtolower(trim((string) ($app['subdomain'] ?? '')));
        if ($sub === '') {
            return app_subdomain((string) ($app['name'] ?? ''));
        }
        return str_contains($sub, '.') ? $sub : app_subdomain($sub);
    }
}

if (!function_exists('app_subdomain_label')) {
    /**
     * Label subdomain efektif app (tanpa APP_DOMAIN) — dipakai UI untuk
     * menampilkan/mengedit field `subdomain`. Fallback ke `name`.
     */
    function app_subdomain_label(array $app): string
    {
        $sub = strtolower(trim((string) ($app['subdomain'] ?? '')));
        if ($sub === '') {
            return (string) ($app['name'] ?? '');
        }
        if (!str_contains($sub, '.')) {
            return $sub;
        }
        $suffix = '.' . strtolower((string) config('deploy.app_domain', 'example.com'));
        return str_ends_with($sub, $suffix) ? substr($sub, 0, -strlen($suffix)) : $sub;
    }
}

if (!function_exists('app_subdomain_is_custom')) {
    /**
     * Apakah app memakai subdomain eksplisit — field `subdomain` terisi dan
     * hasilnya berbeda dari default `{name}.{APP_DOMAIN}`.
     */
    function app_subdomain_is_custom(array $app): bool
    {
        if (trim((string) ($app['subdomain'] ?? '')) === '') {
            return false;
        }
        return app_subdomain_of($app) !== app_subdomain((string) ($app['name'] ?? ''));
    }
}

if (!function_exists('app_subdomain_valid')) {
    /**
     * Validasi format label subdomain: slug `[a-z0-9-]`, diawali & diakhiri
     * alfanumerik, maksimal 63 karakter (batas label DNS).
     */
    function app_subdomain_valid(string $label): bool
    {
        return $label !== ''
            && strlen($label) <= 63
            && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) === 1;
    }
}

if (!function_exists('is_admin')) {
    /**
     * Apakah user (default: user login) adalah admin global.
     */
    function is_admin(?array $user = null): bool
    {
        $user ??= current_user();
        return ($user['role'] ?? '') === AppAccess::ROLE_ADMIN;
    }
}

if (!function_exists('app_role')) {
    /**
     * Role user terhadap app (owner|operator|viewer|admin) atau null bila tidak
     * punya akses. Selalu lewat AppAccess agar aturan hak hanya ada di satu tempat.
     */
    function app_role(array $app, ?array $user = null): ?string
    {
        return AppAccess::roleFor($app, $user ?? current_user());
    }
}

if (!function_exists('app_can')) {
    /**
     * Cek hak user pada app — dipakai view untuk menyembunyikan tombol/aksi.
     */
    function app_can(string $ability, array $app, ?array $user = null): bool
    {
        return AppAccess::can($ability, $app, $user ?? current_user());
    }
}

if (!function_exists('user_names')) {
    /**
     * Peta userId → username (untuk menampilkan pemilik app di daftar admin).
     *
     * @return array<string,string>
     */
    function user_names(): array
    {
        $names = [];
        foreach ((new UserStore())->listWithRoles() as $user) {
            $names[(string) ($user['id'] ?? '')] = (string) ($user['username'] ?? '');
        }
        return $names;
    }
}

if (!function_exists('app_role_label')) {
    /**
     * Label hak akses user pada app (Owner/Operator/Viewer/Admin).
     */
    function app_role_label(?string $role): string
    {
        return AppAccess::label($role ?? '');
    }
}

if (!function_exists('format_credits')) {
    /**
     * Format kredit dengan 2 desimal & pemisah ribuan gaya Indonesia
     * (mis. `1234.5` → `1.234,50`). Murni presentasi, tanpa I/O.
     */
    function format_credits(float $credits, int $decimals = 2): string
    {
        return number_format($credits, $decimals, ',', '.');
    }
}

if (!function_exists('current_credit_balance')) {
    /**
     * Saldo kredit user login untuk badge nav sidebar (SPECS.md §7.12).
     *
     * **Fail-safe**: mengembalikan `null` bila user tidak login, admin, fitur
     * billing nonaktif, atau pembacaan gagal — header tidak boleh gagal hanya
     * karena berkas billing rusak/tak ada. Tanpa memo/statik lintas-request
     * (worker Webman persistent → nilai statik akan basi).
     */
    function current_credit_balance(): ?float
    {
        $user = current_user();
        if ($user === null || is_admin($user)) {
            return null;
        }
        if (!(bool) config('deploy.billing_enabled', true)) {
            return null;
        }

        try {
            return (new CreditAccount())->balance((string) ($user['id'] ?? ''));
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('update_badge')) {
    /**
     * Ringkasan status pembaruan dashboard untuk badge nav sidebar (SPECS.md §7.8).
     *
     * Membaca HANYA berkas cache yang ditulis proses `update-check` / tombol
     * "Cek pembaruan" — tanpa jaringan sama sekali, supaya setiap render halaman
     * tetap murah. Sengaja tidak memakai cache statik (worker Webman persistent →
     * nilai statik akan basi lintas-request).
     *
     * @return array{show:bool,available:bool,error:?string,checked_at:?string}
     */
    function update_badge(): array
    {
        $hidden = ['show' => false, 'available' => false, 'error' => null, 'checked_at' => null];

        // Hanya admin yang bisa menindaklanjuti, jadi hanya admin yang diberi badge.
        if (!is_admin() || !(bool) config('deploy.update_enabled', true)) {
            return $hidden;
        }

        $file = (string) config('deploy.update_check_file', base_path() . '/runtime/update/check.json');
        if (!is_file($file)) {
            return ['show' => true, 'available' => false, 'error' => null, 'checked_at' => null];
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return ['show' => true, 'available' => false, 'error' => null, 'checked_at' => null];
        }

        return [
            'show' => true,
            'available' => (bool) ($data['update_available'] ?? false),
            'error' => isset($data['error']) && $data['error'] !== null ? (string) $data['error'] : null,
            'checked_at' => isset($data['checked_at']) ? (string) $data['checked_at'] : null,
        ];
    }
}
