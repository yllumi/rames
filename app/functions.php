<?php
declare(strict_types=1);

/**
 * Helper global dashboard deployer.
 */

use app\library\Auth\AppAccess;
use app\library\Auth\UserStore;
use app\library\Billing\CreditAccount;
use app\library\Branding\BrandingManager;
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
     * Subdomain/FQDN default untuk server lokal, atau FQDN efektif sebuah app.
     *
     * Multi-server (misi `ms-f1-domain`, plan §9 keputusan 4): domain dasar
     * diambil dari **server pemilik app** (`servers.domain`), bukan lagi selalu
     * `APP_DOMAIN` global. Tiga bentuk argumen:
     *  - tanpa argumen ⇒ domain dasar server **lokal** (dipakai UI
     *    dashboard/global yang tidak terikat app tertentu);
     *  - string (nama/label) ⇒ `{label}.{domain server lokal}` (perilaku lama,
     *    dipakai view yang hanya punya nama app);
     *  - array app ⇒ FQDN efektif app itu, memakai domain servernya
     *    (setara `app_subdomain_of()`).
     *
     * @param string|array<string,mixed>|null $value nama/label, dokumen app, atau null
     */
    function app_subdomain(string|array|null $value = null, ?\app\library\Deploy\ServerResolver $resolver = null): string
    {
        if (is_array($value)) {
            return app_subdomain_of($value, $resolver);
        }

        $domain = \app\library\Deploy\ServerResolver::domainForAppOrLocal([], $resolver);

        return $value === null ? $domain : $value . '.' . $domain;
    }
}

if (!function_exists('app_subdomain_of')) {
    /**
     * FQDN subdomain **efektif** sebuah app.
     *
     * Field `subdomain` di `apps.json` menyimpan **label** slug (mis.
     * `blog-x7k2`) — FQDN dirakit `{label}.{domain server app}`. Bila field
     * kosong / tidak ada, dipakai `{name}.{domain server app}` (perilaku lama,
     * tanpa migrasi). Nilai lama yang sudah berupa FQDN penuh (mengandung titik)
     * dihormati apa adanya supaya data lama tetap berfungsi.
     *
     * Domain dasarnya milik **server pemilik app** (plan §9 keputusan 4);
     * app di server lokal tetap memakai `APP_DOMAIN` selama `servers.domain`
     * server lokal kosong — jadi tidak ada perubahan perilaku di sana.
     *
     * Ini satu-satunya jalur yang boleh menurunkan subdomain — jangan menebak
     * dari `name` langsung.
     *
     * @param array<string,mixed> $app
     */
    function app_subdomain_of(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): string
    {
        $sub = strtolower(trim((string) ($app['subdomain'] ?? '')));
        $domain = \app\library\Deploy\ServerResolver::domainForAppOrLocal($app, $resolver);

        if ($sub === '') {
            return (string) ($app['name'] ?? '') . '.' . $domain;
        }

        return str_contains($sub, '.') ? $sub : $sub . '.' . $domain;
    }
}

if (!function_exists('app_subdomain_label')) {
    /**
     * Label subdomain efektif app (tanpa domain dasar server) — dipakai UI untuk
     * menampilkan/mengedit field `subdomain`. Fallback ke `name`.
     *
     * @param array<string,mixed> $app
     */
    function app_subdomain_label(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): string
    {
        $sub = strtolower(trim((string) ($app['subdomain'] ?? '')));
        if ($sub === '') {
            return (string) ($app['name'] ?? '');
        }
        if (!str_contains($sub, '.')) {
            return $sub;
        }

        $suffix = '.' . \app\library\Deploy\ServerResolver::domainForAppOrLocal($app, $resolver);

        return str_ends_with($sub, $suffix) ? substr($sub, 0, -strlen($suffix)) : $sub;
    }
}

if (!function_exists('app_subdomain_is_custom')) {
    /**
     * Apakah app memakai subdomain eksplisit — field `subdomain` terisi dan
     * hasilnya berbeda dari default `{name}.{domain server app}`.
     *
     * @param array<string,mixed> $app
     */
    function app_subdomain_is_custom(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): bool
    {
        if (trim((string) ($app['subdomain'] ?? '')) === '') {
            return false;
        }

        $domain = \app\library\Deploy\ServerResolver::domainForAppOrLocal($app, $resolver);

        return app_subdomain_of($app, $resolver) !== (string) ($app['name'] ?? '') . '.' . $domain;
    }
}

if (!function_exists('app_subdomain_valid')) {
    /**
     * Validasi format label subdomain: slug `[a-z0-9-]`, diawali & diakhiri
     * alfanumerik, maksimal 63 karakter (batas label DNS).
     *
     * Menerima juga dokumen app (label efektifnya yang divalidasi) — batas ini
     * murni soal bentuk label, jadi hasilnya identik untuk semua server.
     *
     * @param string|array<string,mixed>|null $value label atau dokumen app
     */
    function app_subdomain_valid(string|array|null $value, ?\app\library\Deploy\ServerResolver $resolver = null): bool
    {
        if (is_array($value)) {
            $value = app_subdomain_label($value, $resolver);
        }

        $label = is_string($value) ? $value : '';

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

if (!function_exists('error_badge_count')) {
    /**
     * Jumlah grup error berstatus `open` untuk badge nav sidebar.
     *
     * Hanya admin (halaman `/errors` admin-only). **Tanpa cache/state**:
     * worker Webman persistent membuat nilai statik basi setelah grup
     * diselesaikan/dihapus. Fail-safe: kegagalan baca store (DB sibuk/rusak)
     * tidak boleh merusak render halaman mana pun.
     */
    function error_badge_count(): int
    {
        if (!is_admin()) {
            return 0;
        }

        try {
            return (new \app\library\Errors\ErrorQuery())->openCount();
        } catch (\Throwable) {
            return 0;
        }
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
     * Saldo kredit user login untuk badge nav sidebar (docs/SPECS.md §7.12).
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
     * Ringkasan status pembaruan dashboard untuk badge nav sidebar (docs/SPECS.md §7.8).
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

if (!function_exists('brand_name')) {
    /**
     * Nama brand dashboard aktif (rebranding), default `Rames`.
     *
     * **Fail-safe**: kegagalan baca store (SQLite rusak/terkunci) tidak boleh
     * merusak halaman login/UI — fallback ke default. Tanpa memo/statik
     * lintas-request: worker Webman persistent membuat nilai statik basi setelah
     * brand diganti.
     */
    function brand_name(): string
    {
        try {
            return (new BrandingManager())->name();
        } catch (\Throwable) {
            return 'Rames';
        }
    }
}

if (!function_exists('brand_logo_url')) {
    /**
     * URL logo brand aktif: `/branding/logo?v=<etag>` bila logo kustom, atau
     * `/logo-small.png` (bawaan). Fail-safe seperti {@see brand_name()}.
     */
    function brand_logo_url(): string
    {
        try {
            return (new BrandingManager())->logoUrl();
        } catch (\Throwable) {
            return BrandingManager::DEFAULT_LOGO_URL;
        }
    }
}

if (!function_exists('brand_logo_is_custom')) {
    /**
     * Apakah logo brand aktif kustom (bukan `logo-small.png` bawaan).
     * Fail-safe seperti {@see brand_name()}.
     */
    function brand_logo_is_custom(): bool
    {
        try {
            return (bool) (new BrandingManager())->current()['logo_custom'];
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('template_logo_map')) {
    /**
     * Peta slug template → ekstensi berkas logo di `public/images/templates/`.
     *
     * Diturunkan dari isi direktori NYATA (bukan daftar slug/ekstensi hardcode)
     * supaya template yang mendapat logo .svg/.png/.webp langsung terdeteksi.
     * Hanya ekstensi whitelist (case-insensitive) dengan slug aman yang diterima,
     * dan satu berkas per slug (yang pertama ditemukan menang).
     *
     * Dipakai galeri create-app, judul form deploy template, dan tab **Template**
     * halaman Config — satu sumber agar ketiganya menampilkan logo yang sama.
     * Path default dihitung relatif berkas ini (tanpa helper framework) supaya
     * smoke render di luar HTTP tetap dapat memakainya; `$dir` disediakan agar
     * aturan pemetaan bisa diuji dengan direktori temp.
     *
     * @param string|null $dir direktori aset (default `public/images/templates`)
     * @return array<string,string>
     */
    function template_logo_map(?string $dir = null): array
    {
        $dir ??= dirname(__DIR__) . '/public/images/templates';

        $map = [];
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            // Slug mengikuti aturan yang sama dengan `TemplateCatalog::isValidSlug()`
            // (huruf kecil/angka, dash hanya di tengah) supaya berkas seperti
            // `-foo.png` tidak pernah masuk peta.
            if (in_array(strtolower($extension), ['svg', 'png', 'webp'], true)
                && preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $slug) === 1
                && is_file($file)
                && !isset($map[$slug])) {
                $map[$slug] = $extension;
            }
        }

        return $map;
    }
}

if (!function_exists('icon_paths')) {
    /**
     * Peta nama ikon → isi `<svg>` (viewBox 24×24, garis `currentColor`).
     *
     * **Sumber geometri ikon halaman** (di luar rail menu, yang ikonnya masih
     * inline di `partials/header.php`): dipakai `svg_icon()` untuk view PHP
     * dan `partials/icon-sprite.php` (sprite `<symbol id="ico-*">` di footer)
     * yang dipakai ulang `ramesIcon()` di `public/js/icons.js`.
     *
     * DILARANG memakai emoji/glif sebagai ikon di view maupun JS — tambahkan
     * entri di sini lalu pakai `svg_icon('nama')` / `ramesIcon('nama')`.
     *
     * @return array<string,string>
     */
    function icon_paths(): array
    {
        return [
            'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off' => '<path d="M10.7 5.3A11 11 0 0 1 12 5c6.4 0 10 7 10 7a18.4 18.4 0 0 1-2.9 3.8"/><path d="M6.7 6.7A18.2 18.2 0 0 0 2 12s3.6 7 10 7a10.9 10.9 0 0 0 5.3-1.4"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><line x1="3" y1="3" x2="21" y2="21"/>',
            'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'play' => '<polygon points="7 4 20 12 7 20 7 4"/>',
            'stop' => '<rect x="5" y="5" width="14" height="14" rx="2"/>',
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'folder-plus' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="9.5" y1="13.5" x2="14.5" y2="13.5"/>',
            'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/>',
            'file-plus' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/><line x1="12" y1="12" x2="12" y2="17"/><line x1="9.5" y1="14.5" x2="14.5" y2="14.5"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
            'upload' => '<path d="M12 16V4"/><polyline points="7 9 12 4 17 9"/><path d="M4 20h16"/>',
            'download' => '<path d="M12 4v12"/><polyline points="7 11 12 16 17 11"/><path d="M4 20h16"/>',
            'arrow-up' => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="6 11 12 5 18 11"/>',
            'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><line x1="10" y1="13" x2="14" y2="13"/>',
            'package' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><polyline points="3 8 12 13 21 8"/><line x1="12" y1="13" x2="12" y2="21"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
            'x' => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
            'external-link' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
            'refresh' => '<path d="M20.5 12a8.5 8.5 0 1 1-2.5-6"/><polyline points="21 3 21 9 15 9"/>',
            'rotate-left' => '<polyline points="3 4 3 10 9 10"/><path d="M3.6 15a9 9 0 1 0 2.2-9.6L3 10"/>',
            'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
            'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'rename' => '<path d="M4 7h13"/><polyline points="14 4 17 7 14 10"/><path d="M20 17H7"/><polyline points="10 14 7 17 10 20"/>',
            'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/>',
            'move' => '<polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M20 12c0 1.7-3.6 3-8 3s-8-1.3-8-3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/>',
            'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'message' => '<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'workflow' => '<rect x="3" y="3" width="6" height="6" rx="1.5"/><rect x="15" y="15" width="6" height="6" rx="1.5"/><path d="M9 6h4a2 2 0 0 1 2 2v4"/>',
            'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        ];
    }
}

if (!function_exists('svg_icon')) {
    /**
     * Ikon garis inline (SVG) untuk view — memakai sprite `#ico-*` yang
     * dirender `partials/icon-sprite.php`. `$class` tambahan, mis. `icon-lg`.
     * Nama tak dikenal ⇒ string kosong supaya render halaman tidak pecah.
     */
    function svg_icon(string $name, string $class = ''): string
    {
        if (!isset(icon_paths()[$name])) {
            return '';
        }

        $classes = 'icon' . ($class !== '' ? ' ' . $class : '');

        return '<svg class="' . e($classes) . '" width="1em" height="1em" aria-hidden="true" focusable="false">'
            . '<use href="#ico-' . e($name) . '"></use></svg>';
    }
}

if (!function_exists('template_icon')) {
    /**
     * Ikon galeri/tab/heading template.
     *
     * `template.yml` menyimpan **nama ikon** (`icon_paths()`), bukan emoji.
     * Nilai kosong atau nama tak dikenal (mis. template pihak ketiga yang masih
     * memakai emoji) ⇒ ikon `package` supaya tidak ada emoji di dashboard.
     */
    function template_icon(string $icon, string $class = ''): string
    {
        return svg_icon(isset(icon_paths()[$icon]) ? $icon : 'package', $class);
    }
}
