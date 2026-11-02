# 5. Alur Utama — Autentikasi & Profil

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

## 5.5 Autentikasi

0. **Provisioning user pertama** (host, sekali): `host/create-admin.sh` menunggu `GET /healthz` dari dalam container balas `"ok":true` (bootstrap compose selesai) lalu `php webman make:admin [username] [password]` — lihat GOTCHA §3 ("container `running`" ≠ siap) & SPECS §6.3.
1. `/login` → `UserStore::verify` (`password_verify`, bcrypt).
2. Sukses → regenerasi session id → set `user` di session. **Bila 2FA aktif**, sesi hanya berisi penanda `2fa_pending` (tanpa `user`) dan user diarahkan ke `/login/2fa` — lihat §5.5b.
3. Semua route (kecuali `PUBLIC_PATHS`: `/`, `/login`, `/login/2fa`, `/healthz`, `/branding/logo`, callback pembayaran) dilindungi `AuthMiddleware`.
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
- Route `POST /profile/password` WAJIB tetap di bawah `CsrfMiddleware` + `AuthMiddleware` dan **tidak boleh** ditambahkan ke pengecualian CSRF (`CsrfMiddleware` hanya mengecualikan proxy Adminer + `POST /payments/duitku/callback`). Dikunci `tests/ProfilePasswordRouteGuardTest.php` (route terdaftar tepat sekali + POST tanpa `_token` ditolak **sebelum** controller + path bukan publik); jumlah definisi route dashboard dipantau `tests/AgentModeTest.php` (151 definisi / 157 pasangan method+path).

**Pengujian**: `tests/UserStorePasswordTest.php` (9 tes), `tests/ProfileControllerTest.php` (termasuk ubah password), `tests/ProfilePasswordRouteGuardTest.php`, `tests/AgentModeTest.php`.

## 5.5b Login Dua Langkah & 2FA Self-Service (TOTP)

> Terkait: SPECS §6.4. Route (`config/route.php`): `GET|POST /login/2fa` (**publik**), `POST /profile/2fa/setup`, `/profile/2fa/enable`, `/profile/2fa/disable`, `/profile/2fa/recovery` (login + CSRF). Kelas: `TwoFactorService`, `Totp`, `QrSvg`, `UserStore` (§4.3).

**Alur login (langkah kedua).**

1. `POST /login` (`AuthController::login()`) memverifikasi password seperti biasa lalu bercabang dari `UserStore::totpEnabled($user)` pada bentuk **publik** (flag `totp_enabled`, tanpa secret):
   - 2FA nonaktif ⇒ jalur lama tidak berubah (rotasi id session + `session['user']` + migrasi kepemilikan).
   - 2FA aktif ⇒ rotasi id session, hapus `user`, isi session `2fa_pending` = `{user_id, created_at, attempts:0, redirect}` (`redirect` sudah disaring `safeRedirect()` — hanya path internal), lalu redirect `/login/2fa`. **Belum ada** user login di titik ini.
2. `GET /login/2fa` (`twoFactorForm()`) memvalidasi pending lewat `validPending()`: harus array, `user_id` non-kosong, `created_at` belum lewat `PENDING_TTL` (600 detik) dan `attempts < MAX_ATTEMPTS` (5). Tidak valid ⇒ hapus pending + flash + redirect `/login`. Valid ⇒ render `auth/two-factor` dengan payload `['username' => string, 'attempts_remaining' => int]`.
3. `POST /login/2fa` (`twoFactorVerify()`) → `TwoFactorService::verifyLoginCode()`: **TOTP dulu** (`UserStore::verifyTotp()`, anti-replay), lalu **kode pemulihan** (`UserStore::consumeRecoveryCode()`, hash dihapus saat berhasil). Sukses ⇒ pending dihapus, user dibaca ulang (`findPublicById()`; user yang dihapus di tengah jalan ⇒ sesi dianggap tidak valid), rotasi id session, `session['user']`, migrasi kepemilikan, redirect ke `redirect` pending. Gagal ⇒ `attempts+1`; mencapai 5 ⇒ pending dihapus + redirect `/login`; selain itu flash generik + kembali ke `/login/2fa`.

**Alur setup & kelola di `/profile`** (`ProfileController`; target **selalu** `current_user()['id']` — id dari request diabaikan):

1. `GET /profile` → `index()` mengirim `twoFactor` = `{enabled, recovery_remaining, setup, recovery_codes}` (`twoFactorPayload()`). `setup` = `{secret, uri, qr_svg}` dari session `2fa_setup`, **hanya** bila belum lewat `SETUP_TTL` (900 detik) dan 2FA belum aktif; `recovery_codes` diambil dengan `pull()` ⇒ **sekali tampil** lalu hilang dari session.
2. `POST /profile/2fa/setup` (`startTotpSetup()`, field `current_password`) → `TwoFactorService::beginSetup()` memverifikasi password dan menolak bila 2FA sudah aktif → secret 160-bit base32 + URI `otpauth://` (issuer `brand_name()`, fallback `Rames`) + QR SVG → session `2fa_setup` = `{secret, created_at}` (QR dirender ulang dari secret saat halaman profil dibuka).
3. `POST /profile/2fa/enable` (`enableTotp()`, field `code`) → secret **diambil dari session**, bukan request; `completeSetup()` memverifikasi kode lalu `UserStore::enableTotp()` menulis secret ternormalisasi base32 + `totp_enabled_at` + `totp_last_step=0` + 10 hash bcrypt kode pemulihan; plaintext kode masuk session `2fa_recovery_once`. Kode salah ⇒ session setup **dipertahankan** (boleh diulang sampai TTL habis).
4. `POST /profile/2fa/disable` (`disableTotp()`, field `current_password` + `code`) → `TwoFactorService::disable()` menuntut password benar **dan** kode TOTP/kode pemulihan valid (recovery **tidak** dikonsumsi; verifikasi TOTP tetap menerapkan anti-replay) → `UserStore::disableTotp()` menghapus keempat field 2FA; session setup/recovery dibersihkan.
5. `POST /profile/2fa/recovery` (`regenerateTotpRecovery()`) → syarat sama seperti penonaktifan → `UserStore::replaceRecoveryCodes()` menimpa seluruh hash + plaintext baru ke session sekali tampil.

**Jalur darurat (host, bukan HTTP).** `php webman 2fa:reset <username>` (`app/command/ResetTwoFactor`, terpasang otomatis dari `app/command/`) memanggil `UserStore::disableTotp()` sehingga keempat field 2FA terhapus — **password & role tidak berubah**; user yang memang tidak ber-2FA = no-op sukses, username tak dikenal = gagal (exit code non-nol). Dipakai saat authenticator **dan** seluruh kode pemulihan hilang; setelah itu user login satu langkah lalu mengaktifkan ulang 2FA dari `/profile`. **WAJIB**: ini operasi host di container dashboard — **bukan** route HTTP/aksi UI, jangan pernah diekspos ke `config/route.php` atau tombol `/users`. Seam `users()` pada command ada khusus agar `tests/ResetTwoFactorCommandTest.php` memakai SQLite temp, bukan data runtime.

**Data & session**: kunci session `2fa_pending` / `2fa_setup` / `2fa_recovery_once` (konstanta `TwoFactorService`); field dokumen user `totp_secret`, `totp_enabled_at`, `totp_last_step`, `totp_recovery_codes` di store `auth` — tanpa migrasi skema (kolom `data` adalah sumber kebenaran, §4.5); bentuk publik hanya membawa `totp_enabled`.

**GOTCHA / WAJIB 2FA:**

- **WAJIB — rahasia 2FA tidak keluar dari kartu setup.** `publicUser()` membuang `totp_secret`/`totp_enabled_at`/`totp_last_step`/`totp_recovery_codes` dan hanya menyisakan flag `totp_enabled`. Secret hanya boleh muncul di payload `GET /profile` selama session setup masih sah. **DILARANG** menambahkannya ke `session['user']`, flash, log, JSON API, atau view lain.
- **WAJIB — `/login/2fa` ada di `PUBLIC_PATHS`, `/profile/2fa/*` TIDAK.** Langkah kedua berjalan **sebelum** `session['user']` ada; bila dilindungi auth, user ber-2FA terjebak redirect loop ke `/login`. Sebaliknya aksi `profile/2fa/*` wajib tetap di bawah `AuthMiddleware` + `CsrfMiddleware` tanpa pengecualian baru. Dikunci `tests/TwoFactorRouteGuardTest.php`.
- **WAJIB — kode pemulihan hanya dikonsumsi di alur login.** `verifyLoginCode()` memakai `consumeRecoveryCode()` (hash dihapus); `disable()`/`regenerateRecoveryCodes()` hanya **mencocokkan** (`matchesRecoveryCode()` + `password_verify`). Memakai `consumeRecoveryCode()` di jalur non-login menghabiskan kode pemulihan tanpa alasan.
- **DILARANG melonggarkan anti-replay.** `UserStore::verifyTotp()` menolak step yang cocok bila `step <= totp_last_step` (diperiksa ulang **di dalam** `update()` untuk request paralel). Menerima step yang sama = kode 30 detik dapat dipakai ulang. Jangan menggantinya dengan `>=` atau menghapus pemeriksaan.
- **DILARANG menambah QR eksternal/CDN atau mengirim secret ke pihak ketiga.** QR dirender lokal (`QrSvg` + `bacon/bacon-qr-code` v3 → SVG inline, tanpa GD/Imagick). Secret TOTP tidak boleh masuk URL gambar eksternal, layanan QR daring, atau `data:` base64 dari pihak luar.
- **GOTCHA — status 2FA tidak boleh bocor sebelum password valid.** Percabangan `login()` terjadi **setelah** `UserStore::verify()`. Jangan menambah pesan/redirect berbeda untuk username ber-2FA saat kredensial salah.
- **GOTCHA — TTL pending tidak diperpanjang percobaan gagal.** Saat `attempts` naik, `created_at` dipertahankan; menulis ulang dengan `time()` membuat penyerang dapat menyandera sesi pending tanpa batas.
- **GOTCHA — 2FA hanya membatasi tebakan kode, bukan tebakan password.** Rate limiting login SPECS §8f **belum diterapkan**; jangan mendokumentasikan 2FA sebagai proteksi brute-force.
- **WAJIB — rotasi id session lewat `Request::sessionRegenerateId(true)`, bukan `Session::refresh()`.** Di Workerman, `refresh()` hanya memperbarui timestamp dan **tidak** mengganti id; memakai `refresh()` membuat klaim "anti session fixation" palsu. `AuthController::rotateSession()` adalah satu-satunya jalur (dipanggil sebelum `2fa_pending` disimpan dan setelah kode lolos). Seam `rotateSession()` di test memungkinkan pemanggilan tanpa koneksi HTTP.

**Pengujian**: `tests/TotpTest.php` (vektor RFC 6238, base32, window, URI), `tests/UserStoreTotpTest.php` (anti-replay, kode pemulihan sekali pakai, `publicUser()` menyembunyikan rahasia), `tests/AuthControllerTwoFactorTest.php` (cabang login, TTL pending, batas percobaan, redirect), `tests/ProfileControllerTwoFactorTest.php` (setup/enable/disable/regenerate, payload sekali tampil), `tests/TwoFactorRouteGuardTest.php` (route publik `/login/2fa` + CSRF `profile/2fa/*`), `tests/ResetTwoFactorCommandTest.php` (command `2fa:reset`: 2FA mati & password/role tetap, no-op bila 2FA sudah mati, username tak dikenal gagal tanpa mutasi).
