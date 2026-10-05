## Ringkasan

WAHA (WhatsApp HTTP API) adalah server WhatsApp HTTP API + dashboard
(`devlikeapro/waha`). Kirim/terima pesan via REST API; QR/sesi tersimpan di
volume sehingga tidak perlu scan ulang setelah restart.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `waha` |
| Image | `devlikeapro/waha:gows-2026.9.1` (engine GOWS) |
| Port container | `3000` (di-proxy ke domain) |
| Named volume | `waha-sessions` → `/app/.sessions` |
| Named volume | `waha-media` → `/app/.media` |

`WHATSAPP_DEFAULT_ENGINE=GOWS` dan `WAHA_NAMESPACE=all`. Healthcheck memanggil
`/health` (dikecualikan dari API key).

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `WAHA_API_KEY` | Auto (`generate: secret`) | Kunci header `X-Api-Key` untuk semua request API dan login dashboard. |
| `WAHA_DASHBOARD_USERNAME` | Default `admin` | Username dashboard. |
| `WAHA_DASHBOARD_PASSWORD` | Auto (`generate: secret`) | Password dashboard (default `admin/admin` selalu diganti). |
| `TZ` | Default `Asia/Jakarta` | Timezone. |

## Setelah deploy

1. Buka domain app, lalu ke `/dashboard` dan login memakai kredensial dashboard
   dari tab Environment.
2. Mulai session WhatsApp dan scan QR. QR juga tercetak di log container (tombol
   **Log** di halaman detail app).
3. Kirim pesan lewat REST API dengan header `X-Api-Key: <WAHA_API_KEY>`.

> Bila `media.url` pada webhook perlu mengarah ke URL publik, tambahkan
> `WAHA_PUBLIC_URL=https://<subdomain app>` lewat tab Environment.

## Akses & domain

- Port container yang di-proxy Rames: `3000`.
- Dashboard: `/dashboard`. Swagger: `/`. Health: `/health`.

## Catatan

- Sesi WhatsApp di volume `waha-sessions`; media di `waha-media`
  (`WHATSAPP_FILES_LIFETIME=0` → media disimpan permanen).
- Ganti engine = ubah `image:` dan `WHATSAPP_DEFAULT_ENGINE` di tab Compose,
  lalu klik **Deploy Ulang**.
- Dokumentasi resmi: [WAHA Quick Start](https://waha.devlike.pro/docs/overview/quick-start/)
