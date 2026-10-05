## Ringkasan

Outline adalah wiki / knowledge base kolaboratif. Template ini menjalankan
Outline dengan PostgreSQL dan Redis internal. **Wajib** memiliki domain publik
dan Identity Provider OIDC — Outline tidak menyediakan login lokal atau password.

## Yang disiapkan template

| Service | Image | Port container | Named volume → mount |
|---------|-------|----------------|----------------------|
| `outline` | `docker.getoutline.com/outlinewiki/outline:latest` | `3000` (di-proxy) | `outline-data` → `/var/lib/outline/data` |
| `postgres` | `postgres:18` | — internal | `postgres-data` → `/var/lib/postgresql` |
| `redis` | `redis:8-alpine` | — internal | — |

`outline` menunggu `postgres` dan `redis` sehat sebelum dijalankan.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `URL` | **Wajib diisi** | URL HTTPS publik Outline; harus sama dengan domain/subdomain app di Rames. |
| `POSTGRES_PASSWORD` | Auto (`generate: secret`) | Password database PostgreSQL (format hex). |
| `SECRET_KEY` | Auto (`generate: secret`) | Secret internal Outline. |
| `UTILS_SECRET` | Auto (`generate: secret`) | Secret utilitas Outline. |
| `OIDC_ISSUER_URL` | **Wajib diisi** | URL issuer IdP yang menyediakan `/.well-known/openid-configuration`. |
| `OIDC_CLIENT_ID` | **Wajib diisi** | Client ID aplikasi Outline yang dibuat di IdP. |
| `OIDC_CLIENT_SECRET` | **Wajib diisi** | Client secret aplikasi Outline di IdP (disimpan sebagai secret). |

## Setelah deploy

1. Daftarkan aplikasi OIDC di Identity Provider Anda.
2. Isi `URL` dan tiga nilai OIDC di atas saat create app.
3. Buka domain app — Outline mengarahkan ke login OIDC. Login baru tersedia
   setelah provider OIDC dikonfigurasi dengan benar.

> Tanpa OIDC yang valid, tidak ada cara login ke Outline.

## Akses & domain

- Port container yang di-proxy Rames: `3000`.
- `FORCE_HTTPS=true` dan `PROXY_HEADERS_TRUSTED=true` sudah di-set di compose.
- Penyimpanan file memakai lokal (`FILE_STORAGE=local`) di dalam volume.

## Catatan

- Dokumen tersimpan di volume `outline-data`; database PostgreSQL di
  `postgres-data`. Keduanya perlu ikut di-backup.
- Dokumentasi resmi: [Outline Docker](https://docs.getoutline.com/s/hosting/doc/docker-7pfeLP5a8t) ·
  [OIDC](https://docs.getoutline.com/s/hosting/doc/oidc-8CPBm6uC0I)
