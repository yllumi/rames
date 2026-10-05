## Ringkasan

NocoDB adalah workspace "Airtable open source": mengubah database relasional
menjadi spreadsheet kolaboratif. Template ini menjalankan NocoDB dengan
metadata internal SQLite yang persisten; template **tidak** menyertakan atau
membuat database target.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `nocodb` |
| Image | `nocodb/nocodb:latest` |
| Port container | `8080` (di-proxy ke domain) |
| Named volume | `nocodb-data` → `/usr/app/data` |

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `NC_AUTH_JWT_SECRET` | Auto (`generate: secret`) | Menandatangani token autentikasi NocoDB. Dibuat otomatis bila dikosongkan. |

## Setelah deploy

1. Buka domain app — NocoDB menampilkan halaman masuk/pendaftaran.
2. Buat akun admin pertama (sign up), lalu buka dashboard di `/dashboard`.
3. Tambahkan **database eksternal** (MySQL/MariaDB) sebagai data source dari UI
   NocoDB. Untuk database di app Compose Rames lain, kedua app harus terhubung
   lewat shared external network; jika tidak, gunakan hostname dan port yang
   dapat dijangkau dari container NocoDB.

## Akses & domain

- Port container yang di-proxy Rames: `8080`.
- Dashboard: `/dashboard`.
- API NocoDB tersedia di bawah domain yang sama setelah dibuat API token.

## Catatan

- Metadata internal NocoDB (SQLite) tersimpan di volume `nocodb-data`. Backup
  volume ini agar tabel/definisi tidak hilang.
- Gunakan user database dengan privilege minimum dan jangan mengekspos port
  database ke publik tanpa alasan.
- Dokumentasi resmi:
  [NocoDB — Connect to Data Source](https://nocodb.com/docs/product/integrations/data-sources/connect-to-data-source)
