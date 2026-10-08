## Ringkasan

Adminer adalah alat manajemen database berbasis web yang dikemas dalam satu file
PHP. Ia menyediakan antarmuka untuk menjelajah tabel, menjalankan query, serta
mengelola skema pada MySQL/MariaDB, PostgreSQL, SQLite, dan lainnya. App ini
**stateless**: tidak ada volume dan tidak ada data yang disimpan di sisi server.

> **Peran template ini**: untuk database **remote/arbitrer** (mis. PostgreSQL,
> SQLite, MS SQL, atau server database di luar Rames) lewat domain app. Container
> DB yang dikelola dashboard Rames sudah dibuka dari halaman **`/database`**
> (Adminer internal tanpa port publik) — template ini **tidak** diperlukan untuk
> itu.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `adminer` |
| Image | `adminer:6` |
| Port container | `8080` (di-proxy ke domain) |
| Named volume | tidak ada (stateless) |

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `ADMINER_DEFAULT_SERVER` | Default `host.docker.internal` | Nilai awal kolom Server di halaman login. |
| `ADMINER_DESIGN` | Default `pepa-linha` | Tema tampilan (brade, dracula, nette, pepa-linha, dll). |
| `TZ` | Default `Asia/Jakarta` | Timezone container. |

## Setelah deploy

1. Buka domain app ini.
2. Pilih **driver** database (MySQL, PostgreSQL, SQLite, …) pada kolom *System*.
3. Isi **Server** (terisi otomatis dari `ADMINER_DEFAULT_SERVER`), lalu **Username**
   dan **Password** milik database tujuan.
4. Klik **Login** untuk masuk dan mulai menjelajah tabel atau menjalankan query.

## Menghubungkan ke database

- **DB app Rames lain** — buka detail app ini (dan app DB), lalu attach
  **keduanya** ke *shared network* yang sama lewat tab **Network**. Setelah itu
  isi kolom Server dengan nama container DB, mis. `namaapp-mariadb-1`.
- **DB di host** — pastikan port DB dipublish ke host (`ports:` pada compose app
  DB), lalu biarkan Server = `host.docker.internal` (template sudah menambahkan
  `extra_hosts` agar host bisa dijangkau dari container).
- **DB remote** — isi Server dengan hostname/IP server database dan pastikan
  port-nya terbuka dari host Rames.

## Akses & domain

- Domain/subdomain app diatur di Rames (vhost + `proxy_pass` ke port container `8080`).
- Aktifkan SSL lewat tab **SSL** pada detail app.

## Catatan

- **Stateless**: tidak menyimpan data apa pun; **Hapus App** + purge tidak
  menghapus data database mana pun (data tetap ada di server DB tujuan).
- Plugin tambahan dapat diaktifkan dengan menambahkan `ADMINER_PLUGINS` **dan**
  file plugin-nya, dengan mengubah compose serta mengunggah file lewat tab
  **Compose**, lalu pilih **Deploy Ulang**.
- Dokumentasi resmi: <https://hub.docker.com/_/adminer>
