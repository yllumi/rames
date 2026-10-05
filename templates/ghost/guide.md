## Ringkasan

Ghost adalah platform blog/CMS modern. Template ini menjalankan Ghost 6
(`ghost:6-alpine`) bersama MySQL 8.0 internal sebagai penyimpanan datanya.
Cocok untuk blog profesional dengan editor, newsletter, dan tema kustom.

## Yang disiapkan template

| Service | Image | Port container | Named volume → mount |
|---------|-------|----------------|----------------------|
| `ghost` | `ghost:6-alpine` | `2368` (di-proxy ke domain) | `ghost-content` → `/var/lib/ghost/content` |
| `db` | `mysql:8.0` | — (internal) | `mysql-data` → `/var/lib/mysql` |

`ghost` menunggu `db` sehat (healthcheck `mysqladmin ping`) sebelum dijalankan.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `GHOST_URL` | **Wajib diisi** | URL lengkap Ghost, mis. `https://blog.example.com`. Harus **sama persis** dengan domain/subdomain app di Rames. |
| `MYSQL_ROOT_PASSWORD` | Auto (`generate: secret`) | Password root MySQL. Dibuat acak bila dikosongkan. |
| `MYSQL_PASSWORD` | Auto (`generate: secret`) | Password user database `ghost`. Dibuat acak bila dikosongkan. |

Nama database dan user internal dipatok `ghost` oleh compose.

## Setelah deploy

1. Pastikan `GHOST_URL` sudah sama dengan domain app — bila tidak, tautan,
   gambar, dan email akan salah alamat.
2. Buka domain app, lalu lanjutkan ke `/ghost` untuk membuat akun pemilik
   (owner) pertama.
3. Setelah akun dibuat, panel admin tersedia di `/ghost`.

> Jangan ubah `GHOST_URL` setelah situs dipakai — URL ikut tertanam di data.

## Akses & domain

- Port container yang di-proxy Rames: `2368`.
- Situs publik: `/`. Panel admin: `/ghost`. API internal: `/ghost/api/`.

## Catatan

- Konten Ghost tersimpan di volume `ghost-content`; data MySQL di `mysql-data`.
  Keduanya persisten dan perlu ikut di-backup.
- MySQL hanya dapat dijangkau antar-container (tanpa port host).
- Dokumentasi resmi: [Ghost Docker](https://ghost.org/docs/install/docker/)
