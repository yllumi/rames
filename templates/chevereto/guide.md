## Ringkasan

Chevereto adalah aplikasi berbagi gambar & video (image hosting) yang bisa
dipasang di server sendiri. Template ini menjalankan **Chevereto V4 free
edition** (`chevereto/chevereto:4.5`, Apache + PHP 8.2 di dalam container)
bersama **MariaDB 11.4** internal dan **Redis** untuk cache & sesi.

## Yang disiapkan template

| Service | Image | Port container | Named volume → mount |
|---------|-------|----------------|----------------------|
| `chevereto` | `chevereto/chevereto:4.5` | `8080` (di-proxy ke domain) | `chevereto-images` → `/var/www/html/images` |
| `database` | `mariadb:11.4` | — (internal) | `mariadb-data` → `/var/lib/mysql` |
| `redis` | `redis:8-alpine` | — (internal) | `redis-data` → `/data` |

`chevereto` menunggu `database` dan `redis` sehat sebelum dijalankan.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `CHEVERETO_HOSTNAME` | **Wajib diisi** | Hostname **tanpa** skema, mis. `photos.example.com`. Harus **sama** dengan domain/subdomain app di Rames. |
| `CHEVERETO_HTTPS` | Default `1` | `1` = HTTPS (semua request HTTP dialihkan ke https), `0` = hanya HTTP. |
| `MARIADB_PASSWORD` | Auto (`generate: secret`) | Password user database `chevereto`. |
| `MARIADB_ROOT_PASSWORD` | Auto (`generate: secret`) | Password root MariaDB. |
| `REDIS_PASSWORD` | Auto (`generate: secret`) | Password Redis (cache + sesi). |
| `CHEVERETO_ENCRYPTION_KEY` | Opsional | Base64 **tepat 32 byte** (`openssl rand -base64 32`). Kosong = enkripsi rahasia di database dinonaktifkan. |

Nama database dan user internal dipatok `chevereto` oleh compose. Nilai env
disimpan di `database/env/<nama app>.env` (bukan di repo) dan bisa diubah di tab
**Environment** app; perubahan berlaku setelah **Deploy Ulang**.

> `CHEVERETO_ENCRYPTION_KEY` bukan nilai acak bebas: Chevereto menolak boot bila
> nilainya bukan base64 32 byte. Jangan mengganti kuncinya setelah rahasia
> (SMTP, kunci API) tersimpan — rahasia lama tidak akan terbaca lagi. Mengisi
> kunci pada instalasi yang **sudah** berjalan juga butuh proses sekali jalan
> (`encrypt-secrets`, lihat [dokumentasi
> enkripsi](https://v4-docs.chevereto.com/application/reference/encryption.html)).

## Setelah deploy

1. Buka domain app → Chevereto menampilkan **installer** (`/install`).
   Hostname dan kredensial database sudah terisi dari environment; buat akun
   admin pertama di sana.
2. Setelah installer selesai, panel admin ada di `/dashboard`.
3. Naikkan batas unggah Nginx host bila perlu (lihat bagian berikut).

## Batas unggah (penting)

Ada dua lapis batas yang berbeda:

- **PHP di dalam container** — sudah disetel template ini ke `512M`
  (`CHEVERETO_MAX_POST_SIZE`, `CHEVERETO_MAX_UPLOAD_FILE_SIZE`,
  `CHEVERETO_MAX_UPLOAD_SIZE`).
- **Nginx host** — vhost yang dibuat Rames **tidak** menyetel
  `client_max_body_size`, sehingga berlaku default Nginx **1 MB**. Unggahan di
  atas itu ditolak `413` **sebelum** sampai ke Chevereto, apa pun nilai
  `CHEVERETO_MAX_*`.

Untuk galeri yang dipakai sungguhan, admin host perlu menaikkan batas itu di
`/etc/nginx/nginx.conf` (blok `http { ... }`, berlaku untuk semua vhost), lalu
reload Nginx:

```nginx
client_max_body_size 512m;
```

Nilai Nginx harus **≥** nilai `CHEVERETO_MAX_*` agar tidak terpotong di tengah.

## Akses & domain

- Port container yang di-proxy Rames: `8080`.
- Situs publik: `/`. Panel admin: `/dashboard`. API: `/api`.
- Halaman installer (hanya sebelum instalasi): `/install`.
- Untuk SSL: aktifkan SSL domain app di Rames **sebelum** installer. Dengan
  `CHEVERETO_HTTPS=1` semua request HTTP dialihkan ke
  `https://<CHEVERETO_HOSTNAME>/…`, jadi tanpa sertifikat halaman installer tidak
  bisa dibuka; isi `CHEVERETO_HTTPS=0` bila app memang hanya dilayani lewat HTTP.

## Database

MariaDB tidak membuka port ke host. Kelola lewat halaman **/database** app ini
(Adminer): dashboard menyambung sebagai user aplikasi `chevereto` yang punya
`ALL PRIVILEGES` pada database `chevereto` — cukup untuk mengelola tabel,
import, dan dump.

## Memperbarui

Aplikasi **tidak** disimpan di volume (`CHEVERETO_SERVICING=docker`), jadi
pembaruan dilakukan dengan mengganti image:

1. Tab **Template** → **Pull image** (atau `docker compose pull` manual), lalu
2. **Deploy Ulang** app.

Data di `chevereto-images`, `mariadb-data`, dan `redis-data` tidak tersentuh.
Bila panel admin meminta langkah upgrade setelah versi baru jalan, jalankan dari
`/dashboard`.

## Catatan

- Unggahan pengguna + aset situs (avatar, logo) ada di volume
  `chevereto-images`; data relasional di `mariadb-data`. Keduanya perlu ikut
  di-backup.
- Direktori `importing/` (bulk importer Chevereto) **tidak** di-mount, jadi
  berkas yang ditaruh di sana hilang saat container dibuat ulang. Untuk impor
  massal gunakan penyimpanan eksternal atau salin ulang berkasnya.
- Sesi login & cache disimpan di Redis; menghapus volume `redis-data`
  memaksa semua pengguna login ulang (data galeri tidak terpengaruh).
- Dokumentasi resmi: [Chevereto V4 Docs](https://v4-docs.chevereto.com/) ·
  [Docker](https://github.com/chevereto/docker)
