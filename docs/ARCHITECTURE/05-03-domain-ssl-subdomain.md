# 5. Alur Utama — Domain, SSL & Subdomain

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

## 5.6 Custom Domain & SSL per Domain

1. Halaman detail app → form **Set Custom Domain** (FQDN publik valid, unik di semua app, bukan subdomain sendiri). App **tanpa host port** ditolak lebih dulu dengan pesan jelas (tidak ada target `proxy_pass`) — subdomain & SSL-nya memang tidak berlaku (§5.1).
2. Set → update `apps.json` (`custom_domain` + `custom_ssl_status=disabled`) → tulis ulang config Nginx: subdomain menjadi block redirect `301` ke `http(s)://{custom_domain}`, custom domain melayani app (`LocalDeployer::renderNginxConfig`). Status SSL **per slot**: blok redirect subdomain memakai `ssl_status` (slot `ssl`) — bila `active`, generator merender 80→https + blok 443 dengan cert subdomain; target redirect tetap `https` **hanya** bila `custom_ssl_status=active` (cert subdomain tidak mengubah target).
3. Ganti/hapus custom domain → `SslIssuer::revoke()` mencabut sertifikat lama (no-op bila belum ada cert), lalu config ditulis ulang (subdomain kembali melayani app).
4. SSL custom domain → tombol di detail app & `/domains` (tab **Semua Domain**, dua baris bila custom domain aktif) → permintaan **masuk antrean global** (`ssl_jobs`; satu job dieksekusi pada satu waktu, §5.44) → `SslQueueWorker` menjalankan `cli/ssl.php <appId> <domain>`; worker menentukan slot `custom_ssl` (bila domain = `custom_domain`) atau `ssl` (subdomain), menjalankan certbot, lalu tulis ulang config Nginx. Subdomain redirect dapat di-SSL-kan **terpisah** dari custom domain — status tetap per slot. Baris `pending` menampilkan posisi antrean (SPECS §8a).

## 5.6a Rute proxy tambahan per app (Simpan & Terapkan + rollback)

> Terkait: SPECS §8.2a. Field `apps.json` `nginx_routes`; blok `location ^~ <path>` di-render `NginxConfigGenerator::render()` **hanya** di serve block (HTTP 80 & HTTPS 443).

1. Detail app → kartu **Rute Proxy Tambahan** (ability `routes` = operator ke atas; `AppController::findApp($id, 'routes')` → tanpa hak = **404**). App tanpa host port (`AppPorts::hasHostPort`, §5.1) ditolak lebih dulu dengan pesan prasyarat — **sebelum** efek samping apa pun.
2. `POST /apps/{id}/routes` → `AppController::saveRoutes`: textarea (`<path> <target>` per baris; baris `#` = komentar; kosong = `[]` = hapus semua) di-parse `NginxRoutes::parse()` (fail-fast). Kesalahan **per baris** (format maupun isi) menyebut **nomor baris textarea** (`Baris N: …`); kesalahan **lintas-entri** (duplikat `path`, batas `MAX`) berasal dari `normalize()` akhir tanpa nomor baris tetapi menyebut nilai path-nya.
3. `NginxConfigGuard::applyRoutes($id, $routes)` menjalankan transaksi "uji dulu":
   - snapshot `nginx_routes` lama (mentah);
   - `AppStore::update()` menyimpan rute baru;
   - `DeployerInterface::writeNginxConfig()` menulis ulang config app — rute dirender lewat `NginxRoutes::all()` di `LocalDeployer::renderNginxConfig()`;
   - `NginxReloader::reload()` (`nginx -t` + reload host, §5.6/§8.4).
4. **Bila gagal** (tulis config melempar, atau uji/reload menolak) → guard **rollback**: pulihkan `nginx_routes` ke nilai lama + tulis ulang config lama; host **tidak** di-reload. `applyRoutes()` **tidak pernah melempar** — selalu mengembalikan 4 kunci `{ok, reloaded, rolled_back, error}`:
   - `rolled_back=true` = rute lama berhasil dipulihkan di `apps.json`;
   - `rolled_back=false` + `error` memuat `PERINGATAN: rollback gagal: …` = pemulihan `apps.json` gagal → rute baru tetap tersimpan;
   - `rolled_back=true` + `error` memuat `PERINGATAN: config lama gagal ditulis ulang: … (periksa/Deploy Ulang)` = `apps.json` pulih tetapi config di disk bisa tetap versi baru.
   Controller menerjemahkan hasil menjadi flash + `redirect('/apps/{id}')`; konsumen **wajib** menampilkan `error`, bukan hanya membaca `rolled_back`. **Alasan**: `nginx -t` memvalidasi seluruh config host; config invalid yang tertinggal di disk memblokir reload nginx **seluruh host** (semua app + renewal SSL) karena watcher sengaja tidak reload saat validasi gagal (§8.3) — rollback wajib.
5. Regenerate lain (deploy/rebuild/rollback/SSL) juga memanggil `NginxRoutes::all()` di `renderNginxConfig()`, sehingga rute ikut ditulis ulang otomatis tanpa langkah tambahan.

**Jebakan (ringkas; rinci di SPECS §8.2a)**: G1 `target` hostname wajib resolvable dari host Nginx; G2 `/.well-known` dicadangkan (jangan timpa blok ACME); G3 `setDomain`/`removeDomain`/`cli/ssl.php` (& deploy) **belum** lewat guard (rute hanya ter-render, tanpa rollback transaksional); G4 rollback best-effort — `rolled_back=true` **tidak** menjamin file config di disk bersih, dan kegagalan rollback `apps.json` (`rolled_back=false`) meninggalkan rute baru tersimpan; G5 tanpa lock per-app (dua simpan bersamaan bisa *lost update*); G7 `all()` best-effort vs `normalize()`/`parse()` fail-fast; G8 non-atomik terhadap crash (proses mati antara persist & reload → jalankan ulang/Deploy Ulang).

**Pengujian**: `tests/NginxRoutesTest.php`, `tests/NginxConfigGuardTest.php`, `tests/NginxConfigGeneratorTest.php`.

## 5.6b Ubah Subdomain app (label terpisah dari nama)

> Terkait: SPECS §7.11, §8.1. Field `apps.json.subdomain` menyimpan **label** slug (mis. `blog-x7k2`), bukan FQDN. FQDN efektif dirakit `app_subdomain_of($app)` = `{label}.{APP_DOMAIN}`, fallback `{name}.{APP_DOMAIN}` bila field kosong; nilai lama berupa FQDN penuh (mengandung titik) dihormati apa adanya (tanpa migrasi data).

1. Tab **Domain & SSL** di detail app (ability `domain` = operator+; `AppController::findApp($id, 'domain')` → tanpa hak = **404**) menyediakan input label subdomain + tombol simpan (AJAX ke `POST /apps/{id}/subdomain`; fallback form biasa = flash + redirect). App tanpa host port (`AppPorts::hasHostPort`, §5.1) ditolak lebih dulu dengan pesan prasyarat.
2. `AppController::setSubdomain` (controller mediator) membuat `SubdomainManager` + `NginxConfigGuard` lalu memanggil `SubdomainManager::change($appId, $label)`:
   - **validasi** format label (`app_subdomain_valid()`: `[a-z0-9-]`, awal/akhir alfanumerik, maks 63) + keunikan atas **FQDN efektif** semua app lain (termasuk app yang memakai fallback `name`) + bentrok `custom_domain` (`assertAvailable()`);
   - **idempoten**: FQDN efektif sama dengan lama → `noop`, tanpa efek samping;
   - `NginxConfigGuard::applySubdomain($appId, $label|null)`: snapshot `SUBDOMAIN_STATE_KEYS` → `AppStore::update()` menyimpan label + **reset status SSL** → `DeployerInterface::writeNginxConfig()` → `NginxReloader::reload()` (`nginx -t` + reload host).
3. **Bila gagal** → `rollbackState()` memulihkan `SUBDOMAIN_STATE_KEYS` ke nilai lama **dan** menulis ulang config lama (best-effort); reload **tidak** dipanggil bila config tidak sempat ditulis. `applySubdomain()` **tidak melempar** — selalu mengembalikan `{ok, reloaded, rolled_back, error}`.
4. **Respons**: JSON sukses `{code:0,noop,subdomain,previous,reloaded,message}`; gagal `{code:1,error,subdomain}`. Permintaan non-JSON → `flash_set` + `redirect('/apps/{id}')`.
5. **Reset SSL wajib**: sertifikat Let's Encrypt terikat nama domain lama → `ssl_status=disabled`, `ssl_stage`/`ssl_message`/`ssl_error`/`ssl_expires_at` = `null`, `needs_ssl=false`. Reset hanya slot **`ssl`**; `custom_ssl_*` tidak diubah (status per slot). Berkas sertifikat **tidak** dihapus/dipindah; user menerbitkan ulang lewat tab Semua Domain (subdomain redirect akan mendapat 443 setelah cert subdomain aktif).
6. **Alur create** (git §5.1 / compose §5.1b / template §5.1c) menerima field `subdomain` opsional: divalidasi format + keunikan (`assertSubdomainAvailable()`) **sebelum menyentuh disk**, disimpan di session `pending_app['subdomain']` hanya bila tidak kosong, lalu diteruskan `createAndDeploy()`. Halaman konfirmasi menampilkan FQDN efektif **read-only** (view tidak merender field `subdomain`); form create mem-prefill `{nama}-{6 alfanumerik acak}` dengan *dirty flag* + `pattern`/`maxlength=63` yang identik `app_subdomain_valid()`.

> **GOTCHA — sertifikat SSL lama tidak valid setelah subdomain berubah.** Gejala: app yang tadinya HTTPS kini gagal verifikasi/`ERR_CERT_COMMON_NAME_INVALID` setelah subdomain diganti. Sebab: sertifikat Let's Encrypt terikat pada **nama domain** (`{domain}/live/…`), bukan pada app; mengganti subdomain membuat cert lama tidak cocok. Penangkal: status SSL di-reset otomatis saat subdomain berubah — **terbitkan ulang** sertifikat lewat tab **Semua Domain** (`/domains`) atau kartu SSL di tab **Domain & SSL** detail app (berkas cert lama sengaja **tidak** dihapus agar rollback/domain lama tetap bisa dipakai).

**Pengujian**: `tests/SubdomainTest.php` (helper `app_subdomain_of`/`_label`/`_is_custom`/`_valid` + `SubdomainManager::assertAvailable()`/`change()`), `tests/NginxConfigGuardTest.php` (rollback `applySubdomain`).
