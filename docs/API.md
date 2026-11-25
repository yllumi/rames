# API Eksternal Rames — Panduan Implementasi

> Dokumen ini untuk **implementator** (manusia maupun AI) yang membangun integrasi
> ke dashboard Rames: CI/CD, CLI internal, bot, atau layanan lain.
> Spesifikasi produk: [`SPECS.md`](./SPECS.md) §7.19 · Arsitektur: [`ARCHITECTURE/05-10-fitur-lain.md`](./ARCHITECTURE/05-10-fitur-lain.md) §5.45 ·
> Kontrak mesin (sumber kebenaran): `GET /api-docs/openapi.json` (`app/library/Api/OpenApiSpec.php`).

Ringkas: **base URL dashboard + API key per user**, JSON, autentikasi bearer,
path `/api/v1/**` **tanpa** cookie/sesi/CSRF. Semua aksi bertindak sebagai
**user pemilik key** — otorisasi app (`AppAccess`) dan gerbang kredit berlaku
sama seperti di UI.

---

## 1. Mulai Cepat

```bash
# 1) Buat API key: login dashboard → Profil (/profile) → API key → Buat API key
#    Plaintext (rmk_...) hanya tampil SEKALI.

# 2) Baca status app
curl -sS https://dashboard.example.com/api/v1/apps/app-saya \
  -H "Authorization: Bearer rmk_xxxxxxxxxxxxxxxx" \
  -H "Accept: application/json"

# 3) Build ulang (asinkron)
curl -sS -X POST https://dashboard.example.com/api/v1/apps/app-saya/build \
  -H "Authorization: Bearer rmk_xxxxxxxxxxxxxxxx" \
  -H "Accept: application/json"

# 4) Set custom domain utama
curl -sS -X PUT https://dashboard.example.com/api/v1/apps/app-saya/domain \
  -H "Authorization: Bearer rmk_xxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{"domain":"app.example.com"}'

# 5) Aktifkan SSL domain itu (masuk antrean, 202)
curl -sS -X POST https://dashboard.example.com/api/v1/apps/app-saya/domains/app.example.com/ssl \
  -H "Authorization: Bearer rmk_xxxxxxxxxxxxxxxx" \
  -H "Accept: application/json"

# 6) Poll status domain sampai ssl.status bukan "pending"
curl -sS https://dashboard.example.com/api/v1/apps/app-saya/domains \
  -H "Authorization: Bearer rmk_xxxxxxxxxxxxxxxx" \
  -H "Accept: application/json"
```

---

## 2. Aturan Dasar (baca dulu — ini penyebab 90% kesalahan integrasi)

| # | Aturan | Konsekuensi bila dilanggar |
|---|---|---|
| 1 | **Jangan** menulis garis miring di akhir (`/api/v1/apps/x/`). | 404 HTML polos (bukan JSON). |
| 2 | Base URL **tanpa** `/` di akhir; gabungkan dengan path yang sudah diawali `/api/v1/…`. | URL `//api/v1/...` → 404. |
| 3 | Autentikasi **hanya** header `Authorization: Bearer <key>` (skema case-insensitive). Tidak ada cookie, tidak ada token CSRF, tidak ada token global di env. | 401. |
| 4 | `{slug}` adalah **field `name`** app (bukan id internal), huruf kecil/angka/strip, ≤63 karakter; server menormalkan trim + lowercase. | 404, atau 409 bila nama dipakai beberapa app. |
| 5 | App tak ada **dan** app tak boleh diakses ⇒ **404 yang identik**. Jangan menampilkan pesan "tidak berhak" ke user akhir — itu membocorkan keberadaan app. | — |
| 6 | `402` = kredit tidak cukup (hanya pada aksi build untuk role `member`). | — |
| 7 | `202` = **pekerjaan sudah diantrekan**, bukan selesai. Wajib poll. | Menganggap selesai terlalu dini. |
| 8 | App tidak punya host port terpublish ⇒ tidak ada domain yang bisa diproxy/di-SSL (`served:false`). | `PUT`/`POST`/`PATCH` domain & aktivasi SSL ⇒ 422. |
| 9 | Body mutasi domain: `application/json` **atau** `application/x-www-form-urlencoded`. JSON rusak ⇒ `422 invalid_json`; tipe salah ⇒ `422 invalid_field`. | — |
| 10 | Tidak ada rate limiting, tetapi **jangan** poll lebih sering dari ~2 detik. | Beban server. |

### Header

```http
Authorization: Bearer rmk_...
Accept: application/json
Content-Type: application/json      # hanya untuk request ber-body
```

### Envelope respons

Sukses selalu `code: 0`:

```json
{ "code": 0, "data": { } }
```

Gagal selalu memuat `code` (= status HTTP), `error` (pesan siap tampil,
bahasa Indonesia), dan `reason` (kode mesin, **stabil** untuk percabangan
program):

```json
{ "code": 422, "error": "Custom domain harus FQDN publik yang valid (mis. example.org).", "reason": "validation_failed" }
```

### Tabel `HTTP status` → `reason`

| HTTP | `reason` | Arti / tindakan |
|---|---|---|
| 401 | `unauthorized` | Key tidak ada / salah / sudah dicabut / user dihapus. Perbaiki kredensial; **jangan** retry. |
| 402 | `insufficient_credits` | Saldo kredit kurang (aksi build). Top-up dulu. |
| 404 | `not_found` | App tidak ada **atau** key tidak berhak. Perlakukan sama. |
| 404 | `alias_not_found` | Domain tambahan (alias) tidak terdaftar pada app itu. |
| 404 | `domain_not_found` | Domain bukan milik app (aktivasi SSL). |
| 409 | `ambiguous_app` | Nama app dipakai >1 app yang terlihat (multi-server). Belum ada pemilih server di API. |
| 409 | `app_busy` | App sedang `deploying`. Tunggu sampai selesai. |
| 422 | `build_rejected` | Build ditolak (mis. app hasil impor compose). |
| 422 | `validation_failed` | Input ditolak aturan bisnis; **tidak ada** perubahan data. Jangan retry tanpa mengubah input. |
| 422 | `invalid_json` | Body bukan objek JSON yang valid. |
| 422 | `invalid_field` | Field ada tetapi bertipe salah (array/objek/bool). |
| 503 | `ssl_queue_failed` | Antrean SSL gagal ditulis; slot ditandai `failed`. Coba lagi nanti. |

`error` **boleh berubah** (perbaikan bahasa); `reason` dianggap **kontrak**.

### Otorisasi & ability

| Endpoint | Ability minimum |
|---|---|
| `GET /api/v1/apps/{slug}` | `view` (viewer+) |
| `POST /api/v1/apps/{slug}/build` | `deploy` (operator+) |
| `GET /api/v1/apps/{slug}/domains` | `view` (viewer+) |
| `PUT`/`DELETE /api/v1/apps/{slug}/domain` | `domain` (operator+) |
| `POST /api/v1/apps/{slug}/domains` | `domain` (operator+) |
| `PATCH`/`DELETE /api/v1/apps/{slug}/domains/{domain}` | `domain` (operator+) |
| `POST /api/v1/apps/{slug}/domains/{domain}/ssl` | `ssl` (operator+) |

Role app: `viewer` < `operator` < `owner`; admin global lolos semua. Ability
dinilai `AppAccess` — **bukan** properti key. Key yang dicabut/di-generate ulang
langsung mati (401).

---

## 3. Referensi Endpoint

Semua path relatif terhadap base URL dashboard.

### 3.1 `GET /api/v1/apps/{slug}` — status app

Dipakai untuk polling setelah build. Tidak memuat secret.

```json
{
  "code": 0,
  "data": {
    "slug": "contoh-app",
    "id": "aff2e53c04e6a74c",
    "server_id": "local",
    "status": "running",
    "stage": null,
    "message": "Running",
    "domain": "contoh-app.example.org",
    "created_at": "2026-09-16T14:43:53+07:00",
    "updated_at": "2026-11-06T07:30:00+07:00",
    "containers": [
      { "service": "web", "name": "contoh-app", "status": "running",
        "ports": [{ "host": 30000, "container": 8787 }] }
    ],
    "last_deploy": {
      "sha": "c49156078a6f9ec7624d07a14a546e438b0e8450",
      "short": "c491560",
      "action": "rebuild",
      "status": "success",
      "message": "",
      "created_at": "2026-09-16T14:44:33+07:00"
    }
  }
}
```

`status`: `running`, `stopped`, `deploying`, `error`, dst. Saat `deploying`,
`stage` memuat tahap worker (`queued`, `git`, `build`, `compose`, `nginx`, …).
`last_deploy` boleh `null`.

### 3.2 `POST /api/v1/apps/{slug}/build` — build ulang

Sama persis dengan tombol **Rebuild**: `docker compose build` + recreate
**tanpa** `git pull`. App hasil impor compose ditolak `422 build_rejected`;
app yang sedang `deploying` ditolak `409 app_busy`.

Respons **202**:

```json
{
  "code": 0,
  "data": {
    "slug": "contoh-app",
    "id": "aff2e53c04e6a74c",
    "status": "deploying",
    "stage": "queued",
    "message": "Rebuild dimulai.",
    "poll_url": "/api/v1/apps/contoh-app"
  }
}
```

Pola polling yang disarankan:

```text
POST .../build → 202
loop tiap 3–5 detik (maks ~15 menit):
    GET .../{slug}
    bila data.status != "deploying" → selesai (cek data.last_deploy.status)
```

### 3.3 `GET /api/v1/apps/{slug}/domains` — status domain

Satu panggilan untuk **semua** domain app + status SSL per slot.

```json
{
  "code": 0,
  "data": {
    "slug": "contoh-app",
    "served": true,
    "subdomain": {
      "domain": "contoh-app.example.org",
      "role": "redirect",
      "ssl": { "status": "active", "stage": null, "message": null,
               "expires_at": "2027-02-01T00:00:00+00:00", "error_summary": "" }
    },
    "primary": {
      "domain": "contoh.example.com",
      "role": "serve",
      "ssl": { "status": "disabled", "stage": null, "message": null,
               "expires_at": null, "error_summary": "" }
    },
    "aliases": [
      {
        "domain": "alias.example.com",
        "target_host_port": 30002,
        "role": "serve",
        "ssl": { "status": "pending", "stage": "queued",
                 "message": "Menunggu antrean SSL ...", "expires_at": null,
                 "error_summary": "" }
      }
    ]
  }
}
```

Bacaan field:

| Field | Arti |
|---|---|
| `served` | `false` = app tidak mem-publish host port ⇒ Nginx tidak melayani domain apa pun. Operasi domain/SSL akan ditolak. |
| `subdomain` | FQDN bawaan `{label}.{domain server}`. `null` hanya bila domain server tak terbaca. |
| `subdomain.role` | `redirect` = dialihkan 301 ke `primary`; `serve` = melayani app (tidak ada `primary`). |
| `primary` | `custom_domain` utama, atau `null`. |
| `aliases[]` | Domain tambahan (maks 20), urutan simpan. `target_host_port` = `0` berarti primary service. |
| `ssl.status` | `disabled` (belum pernah diminta), `pending` (antrean/proses), `active` (sertifikat terpasang), `failed` (lihat `error_summary`, boleh diminta ulang). |
| `ssl.error_summary` | Ringkasan satu baris, mis. "DNS domain belum mengarah ke server ini". Kosong = tanpa galat. Detail penuh hanya di dashboard. |

### 3.4 `PUT /api/v1/apps/{slug}/domain` — set/ganti custom domain utama

```http
PUT /api/v1/apps/contoh-app/domain
Content-Type: application/json

{ "domain": "contoh.example.com" }
```

Efek (identik UI): simpan `custom_domain`, revoke sertifikat domain lama
(best-effort), tulis ulang config Nginx di server pemilik app, **reload Nginx**,
lalu subdomain dialihkan 301 ke domain ini. Status SSL domain utama di-reset
`disabled` — aktifkan lagi lewat §3.9.

Respons **200**:

```json
{
  "code": 0,
  "data": {
    "changed": true,
    "applied": true,
    "reloaded": true,
    "domain": "contoh.example.com",
    "target_host_port": 0,
    "warnings": [],
    "domains": { "slug": "contoh-app", "served": true, "subdomain": {}, "primary": {}, "aliases": [] }
  }
}
```

- `changed:false` → nilai sama, **tanpa** efek samping (idempoten).
- `applied:false` → config Nginx **gagal** ditulis; data tetap tersimpan;
  baca `warnings[0]`. Tindakan: perbaiki penyebab lalu `POST /build` (Rebuild)
  atau ulangi permintaan.
- `reloaded:false` (saat `applied:true`) → config tertulis tetapi reload gagal;
  baca `warnings[0]`, ulangi `PUT`/`PATCH` yang sama (aman, idempoten) atau
  Rebuild.
- **DNS bukan tugas API**: arahkan record `A`/`AAAA` domain ke IP server
  pemilik app sebelum mengaktifkan SSL.

Validasi (tanpa efek samping): FQDN publik; bukan subdomain bawaan app; bukan
alias app itu sendiri; unik per server terhadap app lain (subdomain/utama/alias)
dan terhadap domain parkir.

### 3.5 `DELETE /api/v1/apps/{slug}/domain` — hapus custom domain utama

Revoke sertifikat (best-effort) → tulis config → reload; subdomain kembali
melayani app. **Idempoten**: app tanpa custom domain ⇒ `200` `changed:false`.
Bentuk respons sama dengan §3.4.

### 3.6 `POST /api/v1/apps/{slug}/domains` — tambah domain tambahan (alias)

```http
POST /api/v1/apps/contoh-app/domains
Content-Type: application/json

{ "domain": "alias.example.com", "target_host_port": 30002 }
```

- Aliases **melayani app langsung** (tanpa redirect 301) ⇒ app multi-site bisa
  merutekan berdasarkan header `Host`.
- `target_host_port` **opsional**; kosong/`0` = primary service app. Nilai lain
  harus port host yang benar-benar dipublikasikan app.
- Maks **20** alias per app; domain harus FQDN publik; tidak boleh sama dengan
  subdomain/domain utama app; unik per server (termasuk milik app lain &
  domain parkir).
- Perubahan langsung ditulis ke config Nginx + reload (sinkron).

Respons: `200` dengan bentuk §3.4 (`domain` = alias, `target_host_port` = nilai
final).

### 3.7 `PATCH /api/v1/apps/{slug}/domains/{domain}` — ubah target proxy alias

```http
PATCH /api/v1/apps/contoh-app/domains/alias.example.com
Content-Type: application/json

{ "target_host_port": 30000 }
```

`0`/field dihilangkan = kembali ke primary service. Nama domain adalah
identitas entri — **tidak bisa** diubah lewat PATCH (hapus lalu tambah).
Alias tak terdaftar ⇒ `404 alias_not_found`. Perubahan langsung ditulis ke
config Nginx + reload.

### 3.8 `DELETE /api/v1/apps/{slug}/domains/{domain}` — hapus satu alias

Hanya entri itu yang dibuang (alias lain tetap); sertifikatnya di-revoke
best-effort; config ditulis ulang + reload. Alias tak terdaftar ⇒
`404 alias_not_found`.

### 3.9 `POST /api/v1/apps/{slug}/domains/{domain}/ssl` — aktivasi SSL

Meminta sertifikat Let's Encrypt untuk **satu** domain milik app (subdomain
efektif, custom domain utama, atau alias). Penerbitan memakai **antrean global**
(certbot punya lock global + rate limit), jadi respons `202` berarti job sudah
diantrekan — bukan sertifikat terbit.

Respons **202**:

```json
{
  "code": 0,
  "data": {
    "slug": "contoh-app",
    "domain": "contoh.example.com",
    "slot": "custom_ssl",
    "status": "pending",
    "message": "SSL untuk contoh.example.com masuk antrean (posisi 1 dari 1).",
    "queued": true,
    "queue_position": 1,
    "queue_size": 1,
    "poll_url": "/api/v1/apps/contoh-app/domains"
  }
}
```

Respons **200** (idempoten) bila slot sudah `active`/`pending`:

```json
{ "code": 0, "data": { "slug": "contoh-app", "domain": "contoh.example.com",
  "slot": "custom_ssl", "status": "pending",
  "message": "SSL untuk contoh.example.com sudah pending.",
  "already": true, "poll_url": "/api/v1/apps/contoh-app/domains" } }
```

`slot`: `ssl` (subdomain), `custom_ssl` (domain utama), `alias:<domain>`
(domain tambahan).

Yang dijalankan worker setelah `202` (di server pemilik app): tulis config
Nginx blok ACME/443 → reload Nginx → certbot (app di server agent ⇒ job
`ssl_issue` di server itu). Poll `GET .../domains` sampai `ssl.status` menjadi
`active` atau `failed`.

Prasyarat domain: DNS `A`/`AAAA` sudah mengarah ke server pemilik app dan port
80 terjangkau dari internet; domain publik (bukan `.local`); app punya host
port; server agent wajib mengisi `APP_DOMAIN`-nya.

---

## 4. Resep Alur Kerja

### 4.1 Deploy lalu tunggu selesai (CI/CD)

```text
POST /api/v1/apps/{slug}/build
  ├─ 202 → poll GET /api/v1/apps/{slug} tiap 3–5 dtk
  ├─ 402 → saldo kredit kurang (berhenti)
  ├─ 409 → app sedang deploying (tunggu, coba lagi)
  ├─ 404 → slug salah / tidak berhak (berhenti)
  └─ 422 → build tidak diizinkan untuk app ini (berhenti)
Selesai bila data.status != "deploying"; periksa data.last_deploy.status
("success" | "error").
```

### 4.2 Pasang domain baru sampai HTTPS aktif

```text
1. PUT  /api/v1/apps/{slug}/domain          {"domain":"app.example.com"}
   → 200 applied:true,reloaded:true (bila false: lihat warnings, ulangi/Rebuild)
2. (di luar API) arahkan DNS A/AAAA app.example.com → IP server pemilik app
3. POST /api/v1/apps/{slug}/domains/app.example.com/ssl
   → 202 (atau 200 already:true)
4. Poll GET /api/v1/apps/{slug}/domains sampai primary.ssl.status ∈ {active, failed}
   → "active": HTTPS siap
   → "failed": baca primary.ssl.error_summary; setelah DNS diperbaiki,
     kirim ulang POST .../ssl (status failed boleh diulang)
```

### 4.3 Menambah alias multi-tenant

```text
POST /api/v1/apps/{slug}/domains {"domain":"tenant1.example.com","target_host_port":30002}
POST /api/v1/apps/{slug}/domains/tenant1.example.com/ssl
# bila perlu ubah tujuan proxy:
PATCH /api/v1/apps/{slug}/domains/tenant1.example.com {"target_host_port":30000}
# hapus:
DELETE /api/v1/apps/{slug}/domains/tenant1.example.com
```

### 4.4 Sinkronisasi status (dashboard eksternal)

Poll `GET /api/v1/apps/{slug}` dan `GET /api/v1/apps/{slug}/domains`
berpasangan; keduanya read-only (tidak menyentuh Docker/agent) dan aman
dipanggil berkala. Pisahkan jadwal: status app lebih sering daripada domain.

---

## 5. Catatan Multi-Server

- Slug hanya unik **per server**; bila nama yang sama dipakai >1 app yang
  terlihat oleh key ⇒ `409 ambiguous_app`. Belum ada parameter pemilih server —
  gunakan app dengan nama berbeda atau key milik user yang hanya melihat satu
  server.
- Operasi domain/SSL dijalankan **di server pemilik app** (`server_id`):
  config Nginx, reload, dan certbot tidak pernah dieksekusi di host kontrol
  untuk app remote. Jadi `applied`/`reloaded`/`status` yang dikembalikan sudah
  mencerminkan server yang benar.
- Keunikan domain berlaku **per server**; domain parkir bersifat global.

---

## 6. MCP (opsional, untuk agen AI)

Server MCP stdio `tools/mcp/rames_mcp.py` (Python ≥3.9, tanpa dependensi)
mengekspos dua tool yang memetakan langsung ke API:

| Tool | Endpoint |
|---|---|
| `rames_app_status` | `GET /api/v1/apps/{slug}` |
| `rames_build_app` | `POST /api/v1/apps/{slug}/build` |

Konfigurasi (`RAMES_URL`, `RAMES_API_KEY`, `RAMES_TIMEOUT` ≤30 dtk) dan salinan
siap-tempel untuk VS Code/Claude Desktop ada di halaman `/api-docs` (tab
**MCP**). Endpoint domain/SSL belum punya tool MCP — panggil REST langsung.

---

## 7. Checklist Implementasi untuk AI

- [ ] Base URL tanpa garis miring di akhir; path selalu `/api/v1/...` tanpa `/` di akhir.
- [ ] Header `Authorization: Bearer <key>` + `Accept: application/json` di **setiap** request.
- [ ] Anggap `404` = "tidak ada **atau** tidak berhak"; jangan tampilkan alasan berbeda.
- [ ] Percabangan memakai `reason` (stabil), bukan teks `error`.
- [ ] `202` → poll `poll_url`; jangan retry `POST` secara membabi buta (kecuali `409 app_busy`).
- [ ] `PUT`/`DELETE` domain idempoten: cek `changed` sebelum menganggap ada perubahan.
- [ ] Setelah mutasi domain, cek `applied` & `reloaded`; bila `false`, tampilkan `warnings[]` ke operator dan tawarkan Rebuild.
- [ ] Jangan pernah menganggap `202` dari SSL sebagai "HTTPS siap"; tunggu `ssl.status=active`.
- [ ] Jangan menaruh key di repo/log; key tampil sekali di `/profile` — gunakan secret manager.
- [ ] Domain di path **apa adanya** (titik tidak perlu di-encode), lowercase.
- [ ] Hormati batas: 20 alias/app, 63 karakter slug, timeout HTTP klien 30 dtk.
- [ ] Untuk upload/dump besar, gunakan fitur dashboard (Terminal/Backup) — API hanya untuk operasi ini.

---

## 8. Versi & Perubahan

| Versi dokumen | Perubahan |
|---|---|
| 1.0 | Status app + build ulang (fase awal). |
| 1.1 | Domain: status, custom domain utama (set/hapus), alias (tambah/ubah target/hapus). |
| 1.2 | Aktivasi SSL per domain (`POST …/domains/{domain}/ssl`) + field `reloaded` pada hasil mutasi domain. |

Perubahan **aditif** tidak menaikkan versi secara breaking; field baru boleh
muncul kapan saja — **abaikan field yang tidak dikenal**. Field yang dihapus
atau berubah arti akan diumumkan sebagai perubahan mayor.

---

## 9. Berkas Terkait

| Berkas | Isi |
|---|---|
| `GET /api-docs` | Halaman dokumentasi (wajib login) — daftar endpoint, skema, tab MCP. |
| `GET /api-docs/openapi.json` | Dokumen OpenAPI 3.1 (wajib login) — sumber kontrak mesin. |
| `docs/api.http` | Scratch lokal untuk REST Client (gitignored, tidak ikut repo) — jangan dijadikan acuan. |
| [`ARCHITECTURE/05-10-fitur-lain.md`](./ARCHITECTURE/05-10-fitur-lain.md) §5.45 | Detail implementasi & jebakan internal. |
| [`SPECS.md`](./SPECS.md) §7.19 | Kebutuhan produk API eksternal. |
