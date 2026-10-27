# 5. Alur Utama — Autentikasi & Profil

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

## 5.5 Autentikasi

0. **Provisioning user pertama** (host, sekali): `host/create-admin.sh` menunggu `GET /healthz` dari dalam container balas `"ok":true` (bootstrap compose selesai) lalu `php webman make:admin [username] [password]` — lihat GOTCHA §3 ("container `running`" ≠ siap) & SPECS §6.3.
1. `/login` → `UserStore::verify` (`password_verify`, bcrypt).
2. Sukses → regenerasi session id → set `user` di session.
3. Semua route (kecuali `/login`) dilindungi `AuthMiddleware`.
4. Logout → hapus `user` + flush session.

## 5.5a Halaman Profil & Ubah Password Self-Service

> Terkait: SPECS §6.1 & §7.12. Route di `config/route.php` (blok Profil): `GET /profile`, `POST /profile/email`, `POST /profile/password`. Bukan bagian billing, walau dokumentasi halaman ada di SPECS §7.12.

1. `GET /profile` → `ProfileController::index()`, payload `['user' => array, 'email' => string]` (`''` bila belum diatur) untuk `app/view/profile/index.php`.
2. `POST /profile/email` → `setEmail()`: format & batas 50 karakter didelegasikan ke `UserStore::setEmail()`; input non-skalar (`email[]=…`) → flash + redirect, email lama **tidak** dihapus; tanpa state properti lintas-request.
3. `POST /profile/password` → `changePassword()`, target **selalu** `current_user()['id']`:
   - tiga field wajib skalar (`current_password[]=…`) → flash `Password tidak valid.`;
   - `UserStore::verifyPassword($id, $current)` (`password_verify`, constant-time) gagal → `Password saat ini salah.` (pesan seragam untuk id/hash tak valid);
   - `new_password !== new_password_confirm` → `Konfirmasi password baru tidak cocok.`;
   - `UserStore::changePassword($id, $new)` menegakkan `strlen >= 6` (melempar `InvalidArgumentException`; pesannya diteruskan ke flash `Password minimal 6 karakter.`);
   - sukses → flash `Password diperbarui.` + `redirect('/profile')`; sesi tidak disentuh ⇒ user **tetap login**.
4. View `profile/index.php`: ketiga input `type="password"` **tanpa** atribut `value` (tidak pernah dirender balik), `autocomplete` `current-password`/`new-password`, `minlength="6"` + hint "Minimal 6 karakter.".

**WAJIB / GOTCHA ubah password:**
- `verifyPassword()` WAJIB memakai `password_verify()` dan **mengembalikan `false` tanpa exception** untuk id tak dikenal, `password_hash` kosong/non-string (dokumen legacy), atau password kosong. DILARANG membandingkan hash mentah (`===`) atau mencetak password/hash ke log, flash, maupun respons.
- Password **tidak di-trim** (spasi signifikan) dan **tidak pernah** dirender balik ke form — jangan menambahkan `value="<?= … ?>"` pada input password.
- Target user ubah password **selalu** dari sesi; `id`/`user_id`/`username` di body/query **diabaikan** (anti-IDOR). Jangan pernah membaca id target dari request.
- Route `POST /profile/password` WAJIB tetap di bawah `CsrfMiddleware` + `AuthMiddleware` dan **tidak boleh** ditambahkan ke pengecualian CSRF (`CsrfMiddleware` hanya mengecualikan proxy Adminer + `POST /payments/duitku/callback`). Dikunci `tests/ProfilePasswordRouteGuardTest.php` (route terdaftar tepat sekali + POST tanpa `_token` ditolak **sebelum** controller + path bukan publik); jumlah definisi route dashboard dipantau `tests/AgentModeTest.php` (131 definisi / 137 pasangan method+path).

**Pengujian**: `tests/UserStorePasswordTest.php` (9 tes), `tests/ProfileControllerTest.php` (termasuk ubah password), `tests/ProfilePasswordRouteGuardTest.php`, `tests/AgentModeTest.php`.
