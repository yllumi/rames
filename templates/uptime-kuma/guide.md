## Ringkasan

Uptime Kuma adalah alat monitoring uptime (HTTP/TCP/ping) dengan dashboard web
dan notifikasi. Cocok untuk memantau ketersediaan situs/service dalam satu
dashboard.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `uptime-kuma` |
| Image | `louislam/uptime-kuma:1` |
| Port container | `3001` (di-proxy ke domain) |
| Named volume | `uptime-kuma-data` → `/app/data` |

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `TZ` | Default `Asia/Jakarta` | Timezone instans. |

## Setelah deploy

1. Buka domain app.
2. Uptime Kuma meminta pembuatan **akun admin pertama** (username + password).
   Isi dan simpan; tidak ada akun default.
3. Setelah login, tambahkan monitor pertama lewat tombol **+ Add New Monitor**.

## Akses & domain

- Port container yang di-proxy Rames: `3001`.
- Dashboard di root `/`.

## Catatan

- Seluruh data (monitor, histori heartbeat, akun, notifikasi) tersimpan di volume
  `uptime-kuma-data` (`/app/data`) — wajib ikut di-backup.
- Dokumentasi resmi: [Uptime Kuma Wiki](https://github.com/louislam/uptime-kuma/wiki)
