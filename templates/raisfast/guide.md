## Ringkasan

RaisFast adalah headless CMS & backend-as-a-service (Rust, satu binary) dengan
blog, ecommerce, wallet, payment, dan multi-tenant SaaS bawaan. Data dan
metadata disimpan di SQLite pada named volume. Mode **headless**: antarmuka
admin berada di `/admin`, sedangkan root `/` memang 404.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `raisfast` |
| Image | `ghcr.io/raisfast/raisfast:latest` |
| Port container | `9898` (di-proxy ke domain) |
| Named volume | `raisfast-data` → `/app/storage` |

`APP_ENV=production` dan `CORS_ORIGINS` mengikuti `BASE_URL`. `/app/storage`
adalah `STORAGE_ROOT_DIR` default image: SQLite (`storage/db/raisfast.db`),
uploads, dan logs.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `BASE_URL` | **Wajib diisi** | URL publik app, mis. `https://cms.example.com`. Harus sama dengan domain/subdomain app. Dipakai untuk tautan media/RSS dan origin CORS. |
| `JWT_SECRET` | Auto (`generate: secret`) | Kunci penandatanganan token (hex 48 karakter, di atas minimum 32). Boleh diisi manual. |
| `APP_KEY` | **Wajib (secret)** | Base64 dari **tepat 32 byte** acak (44 karakter, berakhiran `=`). Hasilkan: `openssl rand -base64 32`. Dipakai untuk AES-256-GCM. **Tidak** di-generate otomatis. |

## Setelah deploy

1. Pastikan `BASE_URL` sudah sama dengan domain app.
2. Buka `https://<domain>/admin` untuk wizard setup dan buat akun admin.
3. Ketentuan akun pada wizard:
   - Username **terlarang**: `admin`, `administrator`, `root`, `system`,
     `official`, `support`, `staff`, `moderator`, `mod`, `help`, `info`, `mail`,
     `webmaster`, `security`, `billing`, `sales`, `owner`, `superuser`,
     `operator`.
   - Password minimal 8 karakter dengan kombinasi huruf besar, huruf kecil, dan
     angka.

> `APP_KEY` harus stabil selama masih ada data terenkripsi (mis. token API) yang
> dipakai — jangan diubah sembarangan.

## Akses & domain

- Port container yang di-proxy Rames: `9898`.
- UI admin: `/admin`. Dokumentasi API: `/api/docs`. Health: `/healthz`.
- Root `/` mengembalikan 404 (mode headless).

## Catatan

- Seluruh data (SQLite `storage/db/raisfast.db`, uploads, logs) berada di volume
  `raisfast-data` (`/app/storage`) — sertakan pada backup.
- Dokumentasi resmi: [RaisFast Docs](https://www.raisfast.com/en/docs)
