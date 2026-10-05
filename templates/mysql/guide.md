## Ringkasan

Template ini menjalankan server MySQL 8.4 (LTS) lengkap dengan pembuatan
database awal otomatis. Ditujukan sebagai server database yang dikelola dari
dashboard, bukan aplikasi web — **tidak ada vhost/subdomain**.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `mysql` |
| Image | `mysql:8.4` |
| Port container | `3306` — **tidak dipublikasikan ke host** |
| Named volume | `mysql-data` → `/var/lib/mysql` |

Karena tidak ada `ports:`, app ini tidak dibuatkan domain/subdomain.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `MYSQL_ROOT_PASSWORD` | Auto (`generate: secret`) | Password root, dipakai halaman `/database`. Dibuat acak bila kosong — lihat tab Environment app. |
| `MYSQL_DATABASE` | Default `app` | Database yang dibuat otomatis saat pertama dijalankan. |
| `TZ` | Default `Asia/Jakarta` | Timezone server; memengaruhi `NOW()`/`CURRENT_TIMESTAMP` (log error tetap UTC). |

## Setelah deploy

1. Buka halaman **`/database`** di Rames dan pilih app ini.
2. Rames menyambung ke server memakai kredensial root dari tab Environment.
3. Di sana Anda dapat membuat database/user, mengatur GRANT, serta import/export
   dump.

> Jangan menambahkan `MYSQL_USER`/`MYSQL_PASSWORD` ke compose: panel `/database`
> akan kehilangan hak admin. Buat user aplikasi dari tab Pengguna.

## Akses & domain

- Tanpa vhost/subdomain dan tanpa port host — akses hanya lewat halaman
  `/database` Rames.
- Bila DB harus dijangkau dari host/app lain, tambahkan blok `ports:` di compose
  lalu Deploy Ulang; host port bentrok digeser otomatis oleh dashboard.

## Catatan

- Semua data + system tables berada di volume `mysql-data` (`/var/lib/mysql`).
- Volume di-scope per app, aman dipakai untuk banyak app sekaligus.
- Dokumentasi resmi: [MySQL di Docker Hub](https://hub.docker.com/_/mysql)
