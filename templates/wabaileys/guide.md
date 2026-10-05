## Ringkasan

Wabaileys adalah WhatsApp API multi-session berbasis Baileys (image
`yllumi/wabaileys`): kirim/terima pesan lewat REST API dan kelola banyak akun
dari dashboard web (buat/hapus session, scan QR, status koneksi, health).
Kredensial WhatsApp tersimpan di volume sehingga tidak perlu scan QR ulang
setelah restart.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `wabaileys` |
| Image | `yllumi/wabaileys:latest` |
| Port container | `8990` (di-proxy ke domain; statis di kode app) |
| Named volume | `wabaileys-data` → `/app/data` |

Healthcheck: `curl` ke `GET /health` (endpoint publik tanpa kredensial).

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `HTTP_AUTH_USERNAME` | Default `admin` | Username HTTP Basic Auth untuk dashboard & endpoint manajemen. |
| `HTTP_AUTH_PASSWORD` | Auto (`generate: secret`) | Password dashboard. Jangan dikosongkan — image memakai `admin/admin` bila kosong. |
| `APP_KEY` | Auto (`generate: secret`) | Kunci header `X-Api-Key` untuk endpoint kirim pesan. |
| `MAX_SESSIONS` | Default `15` | Batas session (1 GB ± 10–15, 2 GB ± 30–40, 4 GB ± 80–100). |
| `TZ` | Default `Asia/Jakarta` | Timezone. |

## Setelah deploy

1. Buka domain app dan login dengan HTTP Basic Auth memakai
   `HTTP_AUTH_USERNAME` / `HTTP_AUTH_PASSWORD` (lihat tab Environment).
2. Buat session baru dari dashboard, lalu **scan QR** memakai WhatsApp di HP.
3. Kirim pesan lewat `POST /{session}/send` dengan header `X-Api-Key: <APP_KEY>`.

> `APP_KEY` dari environment selalu menang setelah restart. Endpoint
> `/generate-appkey` hanya mengubah kunci selama app berjalan — untuk rotasi
> permanen, ubah lewat tab Environment.

## Akses & domain

- Port container yang di-proxy Rames: `8990`.
- Dashboard web & endpoint manajemen: di root `/` (HTTP Basic Auth).
- Endpoint kirim pesan: `POST /{session}/send`. Health publik: `/health`.

## Catatan

- Kredensial Baileys per session, `sessions_registry.json`, dan app key hasil
  rotasi tersimpan di volume `wabaileys-data` (`/app/data`).
- Dokumentasi resmi: [wabaileys di Docker Hub](https://hub.docker.com/r/yllumi/wabaileys)
