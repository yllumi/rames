## Ringkasan

Template ini menjalankan server MariaDB 11.4 (LTS) lengkap dengan pembuatan
database awal otomatis. Ditujukan sebagai server database yang dikelola dari
dashboard, bukan aplikasi web — **tidak ada vhost/subdomain**.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `mariadb` |
| Image | `mariadb:11.4` |
| Port container | `3306` — **tidak dipublikasikan ke host** |
| Named volume | `mariadb-data` → `/var/lib/mysql` |

Karena tidak ada `ports:`, app ini tidak dibuatkan domain/subdomain.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `MARIADB_ROOT_PASSWORD` | Auto (`generate: secret`) | Password root, dipakai halaman `/database`. Dibuat acak bila kosong — lihat tab Environment app. |
| `MARIADB_DATABASE` | Default `app` | Database yang dibuat otomatis saat pertama dijalankan. |
| `TZ` | Default `Asia/Jakarta` | Timezone server; memengaruhi `NOW()`/`CURRENT_TIMESTAMP`. |

`MARIADB_AUTO_UPGRADE=1` di-set di compose untuk upgrade system tables saat tag
image dinaikkan (mis. 11.4 → 11.8).

## Setelah deploy

1. Buka halaman **`/database`** di Rames dan pilih app ini.
2. Rames menyambung ke server memakai kredensial root dari tab Environment.
3. Di sana Anda dapat membuat database/user, mengatur GRANT, serta import/export
   dump.

> Jangan menambahkan `MARIADB_USER`/`MARIADB_PASSWORD` ke compose: panel
> `/database` akan kehilangan hak admin. Buat user aplikasi dari tab Pengguna.

## Akses & domain

- Tanpa vhost/subdomain dan tanpa port host — akses hanya lewat halaman
  `/database` Rames.
- Bila DB harus dijangkau dari host/app lain, tambahkan blok `ports:` di compose
  lalu Deploy Ulang; host port bentrok digeser otomatis oleh dashboard.

## Catatan

- Semua data + system tables berada di volume `mariadb-data` (`/var/lib/mysql`).
- Volume di-scope per app, aman dipakai untuk banyak app sekaligus.
- Dokumentasi resmi: [MariaDB di Docker Hub](https://hub.docker.com/_/mariadb)
