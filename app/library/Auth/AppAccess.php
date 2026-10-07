<?php
declare(strict_types=1);

namespace app\library\Auth;

/**
 * Otorisasi kepemilikan & sharing app — SATU-SATUNYA pintu pemeriksaan hak.
 *
 * Setiap controller (App, Terminal, Database, SSL, Volume, Network) dan view
 * wajib memakai kelas ini; jangan menulis ulang logika `owner_id`/`members` di
 * tempat lain agar tidak ada endpoint yang terlewat.
 *
 * Model (database/apps.json):
 *   "owner_id": "<userId>",                        // pemilik app
 *   "members": { "<userId>": { "role": "viewer|operator|owner", ... } }
 *
 * Role efektif user terhadap sebuah app (dari tertinggi):
 *   admin    — role user global (auth.json); punya kuasa penuh ke semua app
 *   owner    — `owner_id` app, atau member dengan role owner (co-owner)
 *   operator — boleh mengoperasikan app (deploy, terminal, domain, dst.)
 *   viewer   — read-only
 *   null     — tidak punya akses (controller merespons 404)
 *
 * Ability khusus **admin global**: `limits` — mengatur batas maksimum CPU &
 * memori (MB) per service sebuah app, disimpan ke override compose. Hanya admin
 * yang boleh mengubah batas resource host ini; owner/operator/viewer tidak
 * memilikinya. Penolakan tetap lewat satu pintu:
 * `AppAccess::require('limits', $app, $user)` → `AppAccessDenied` → respons
 * **404** (bukan 403, agar keberadaan app milik user lain tidak bocor).
 *
 * Ability backup volume (backup harian ke S3 via restic):
 *   backup  — `ROLE_OPERATOR`: menjalankan backup volume milik app
 *             (owner & operator boleh; viewer tidak).
 *   restore — `ROLE_OWNER`: memulihkan volume dari snapshot — destruktif,
 *             jadi eksklusif owner (operator/viewer tidak).
 * Keduanya lewat pintu yang sama (`AppAccess::require()`), dan penolakan tetap
 * **404** (bukan 403). Volume **yatim** (project tak ada di `apps.json`) tidak
 * punya baris app sehingga hanya admin global yang boleh melihat/memulihkannya
 * — controller wajib memetakan volume → app lebih dulu, baru memanggil kelas ini.
 *
 * Kelas ini stateless (tanpa properti) — aman untuk Webman persistent worker.
 * User yang diperiksa adalah array dari session (`current_user()`), yang sudah
 * memuat field `role` hasil resolusi UserStore.
 */
final class AppAccess
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OWNER = 'owner';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';

    /** Role yang boleh di-assign sebagai member app (admin tidak di-assign). */
    public const ASSIGNABLE_ROLES = [self::ROLE_VIEWER, self::ROLE_OPERATOR, self::ROLE_OWNER];

    /**
     * Ability → role minimum yang dibutuhkan.
     *
     * @var array<string,string>
     */
    private const ABILITIES = [
        // read-only
        'view' => self::ROLE_VIEWER,
        'logs' => self::ROLE_VIEWER,
        // operasi app
        'operate' => self::ROLE_OPERATOR,
        'deploy' => self::ROLE_OPERATOR,
        'stop' => self::ROLE_OPERATOR,
        'env' => self::ROLE_OPERATOR,
        'compose' => self::ROLE_OPERATOR,
        'network' => self::ROLE_OPERATOR,
        'domain' => self::ROLE_OPERATOR,
        // `routes` — rute proxy tambahan per app (field apps.json `nginx_routes`).
        // Disimpan terstruktur (bukan snippet Nginx mentah) dan setara level
        // `domain`: operator+ boleh mengubahnya, viewer tidak.
        'routes' => self::ROLE_OPERATOR,
        'ssl' => self::ROLE_OPERATOR,
        'terminal' => self::ROLE_OPERATOR,
        // `files` — file manager container (jelajah, unggah, unduh, edit teks,
        // rename, hapus). Setara `terminal`: operator sudah memegang shell penuh
        // di container yang sama, jadi ability ini tidak menambah kuasa baru.
        'files' => self::ROLE_OPERATOR,
        'database' => self::ROLE_OPERATOR,
        // backup volume ke S3 via restic — `backup` = operator, `restore` = owner
        // (restore menimpa isi volume → destruktif, eksklusif owner).
        'backup' => self::ROLE_OPERATOR,
        'restore' => self::ROLE_OWNER,
        // eksklusif owner
        'delete' => self::ROLE_OWNER,
        'sharing' => self::ROLE_OWNER,
        // eksklusif admin global — `limits` = batas maksimum CPU/memori per
        // service app (ditulis ke override compose). Owner/operator/viewer
        // tidak boleh mengubah batas resource host.
        'limits' => self::ROLE_ADMIN,
    ];

    /** Peringkat role — dibandingkan untuk menentukan "minimal role". */
    private const RANK = [
        '' => 0,
        self::ROLE_VIEWER => 1,
        self::ROLE_OPERATOR => 2,
        self::ROLE_OWNER => 3,
        self::ROLE_ADMIN => 4,
    ];

    /**
     * Role efektif user terhadap app (null = tidak punya akses).
     */
    public static function roleFor(array $app, ?array $user): ?string
    {
        $userId = (string) ($user['id'] ?? '');
        if ($userId === '') {
            return null;
        }

        // Admin global: akses penuh ke semua app (termasuk app tanpa owner).
        if (((string) ($user['role'] ?? '')) === self::ROLE_ADMIN) {
            return self::ROLE_ADMIN;
        }

        if (((string) ($app['owner_id'] ?? '')) === $userId) {
            return self::ROLE_OWNER;
        }

        $role = self::memberRole($app, $userId);
        return $role !== '' ? $role : null;
    }

    /**
     * Role member app untuk user tertentu. Mendukung dua bentuk data:
     * `{ "<id>": "operator" }` dan `{ "<id>": { "role": "operator" } }`.
     */
    public static function memberRole(array $app, string $userId): string
    {
        if ($userId === '') {
            return '';
        }
        $members = $app['members'] ?? null;
        if (!is_array($members) || !array_key_exists($userId, $members)) {
            return '';
        }
        $entry = $members[$userId];
        $role = is_array($entry) ? (string) ($entry['role'] ?? '') : (string) $entry;

        return in_array($role, [self::ROLE_VIEWER, self::ROLE_OPERATOR, self::ROLE_OWNER], true) ? $role : '';
    }

    /**
     * Apakah user boleh melakukan `ability` pada app.
     */
    public static function can(string $ability, array $app, ?array $user): bool
    {
        $required = self::ABILITIES[$ability] ?? self::ROLE_OWNER;
        $role = self::roleFor($app, $user);
        if ($role === null) {
            return false;
        }

        return (self::RANK[$role] ?? 0) >= (self::RANK[$required] ?? 0);
    }

    /**
     * Versi `can()` yang melempar AppAccessDenied (untuk dipakai di controller).
     *
     * @throws AppAccessDenied
     */
    public static function require(string $ability, array $app, ?array $user): void
    {
        if (!self::can($ability, $app, $user)) {
            throw new AppAccessDenied($ability, (string) ($app['id'] ?? ''));
        }
    }

    /**
     * Saring daftar app menjadi hanya yang boleh dilihat user.
     *
     * @param array<int,array> $apps
     * @return array<int,array>
     */
    public static function visible(array $apps, ?array $user): array
    {
        return array_values(array_filter(
            $apps,
            static fn (array $app): bool => self::can('view', $app, $user)
        ));
    }

    /**
     * Label hak user pada app (untuk UI): Milik Saya / Dibagikan / Admin / -.
     */
    public static function label(string $role): string
    {
        return match ($role) {
            self::ROLE_ADMIN => 'Admin',
            self::ROLE_OWNER => 'Owner',
            self::ROLE_OPERATOR => 'Operator',
            self::ROLE_VIEWER => 'Viewer',
            default => '-',
        };
    }

    /**
     * Daftar ability yang dimiliki role (untuk gating tombol di view).
     *
     * @return array<string,bool>
     */
    public static function abilitiesFor(?string $role): array
    {
        $out = [];
        foreach (array_keys(self::ABILITIES) as $ability) {
            $required = self::ABILITIES[$ability];
            $out[$ability] = $role !== null
                && (self::RANK[$role] ?? 0) >= (self::RANK[$required] ?? 0);
        }
        return $out;
    }
}
