## Ringkasan

Situs HTML statis yang disajikan BusyBox httpd (image `yllumi/httpd:latest`,
berbasis Alpine) dari docroot `/www`. Cocok untuk halaman statis, landing page,
atau bundel HTML/CSS/JS hasil build yang tidak butuh PHP/runtime server. Tidak
ada database dan tidak ada akun/kredensial bawaan.

Docroot `/www` disimpan di named volume `static-www`, sehingga berkas situs
bertahan saat container dibuat ulang (redeploy, restart, atau upgrade image).

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `web` |
| Image | `yllumi/httpd:latest` (BusyBox httpd, Alpine) |
| Port container | `80` (di-proxy Rames ke domain/subdomain app) |
| Docroot / working dir | `/www` |
| Named volume | `static-www` → `/www` |
| Healthcheck | bawaan image (`wget` ke `http://127.0.0.1/`, interval 30s) |

Tidak ada service lain (tanpa database).

## Variabel environment

Template ini tidak mendeklarasikan variabel environment apa pun — tidak ada yang
perlu diisi saat create.

## Setelah deploy

1. Tunggu container `web` berstatus berjalan.
2. Container start dengan `/www` **kosong**: `GET /` mengembalikan
   **404 Not Found** — build BusyBox httpd pada image ini **tidak** membuat
   daftar direktori otomatis. Healthcheck bawaan image juga ikut gagal selama
   `index.html` belum ada, jadi container tampil `unhealthy` sampai berkas
   pertama diunggah.
3. Unggah berkas situs ke `/www` dengan salah satu cara:
   - tab **Berkas** app — file manager Rames yang berjalan sebagai root di dalam
     container: buat folder, unggah, atau ubah berkas langsung;
   - tab **Terminal** app — shell Alpine `/bin/sh` (`vi`, `wget`, `printf`, dst.)
     untuk menulis ke `/www`.
4. Buka domain/subdomain app lagi — `GET /` menjawab `200 OK` dengan isi
   `index.html`, dan healthcheck image kembali lolos (`healthy`).
5. Bila situs punya CSS/JS/gambar, unggah juga ke `/www` (mis. `/www/assets/`)
   dan rujuk dengan path relatif/absolut biasa.

> Tidak ada akun default dan tidak ada kredensial yang perlu disimpan.

## Akses & domain

- Port container yang di-proxy Rames ke domain: `80`.
- Port host yang dipublikasikan **bukan** 80: dashboard mengalokasikan host port
  dari rentang `PORT_RANGE_*` (default `30000`–`30999`) dan me-reverse-proxy ke
  domain/subdomain app. Port container tetap `80` dan bisa diubah lewat tab
  **Compose** app (mis. bila nanti diganti ke web server lain).
- Semua berkas di `/www` dapat diakses publik di bawah domain app — jangan
  menaruh berkas rahasia (kredensial, dump database) di sana.

## Catatan

- Berkas yang diunggah lewat tab **Berkas** dimiliki `root`, dan httpd juga
  berjalan sebagai `root`, jadi tidak ada masalah permission. Tulisan lewat
  tab Terminal pun sama.
- **Healthcheck bawaan image menembak `http://127.0.0.1/`** (`wget`), sehingga
  container berstatus `unhealthy` selama `index.html` belum ada — ini bukan
  tanda deploy gagal, hanya menunggu berkas pertama diunggah. Statusnya
  `healthy` setelah `/www/index.html` tersedia.
- Direktori tanpa `index.html` tetap menjawab 404 (tanpa daftar direktori), dan
  URL direktori tanpa `/` diakhiri lebih dulu 302 ke versi ber-`/`, baru 404 —
  jadi setiap direktori yang ingin dibuka lewat URL harus punya berkasnya
  sendiri.
- Named volume `static-www` di-scope per app (nama project compose = nama app),
  sehingga aman dipakai banyak app sekaligus. **Sertakan volume ini pada
  strategi backup Rames** — seluruh isi situs hanya ada di volume tersebut.
- BusyBox httpd **tidak** mendukung rewrite/alias/SPA fallback. Aplikasi
  single-page dengan history router (mis. `/detail/42` harus membuka
  `index.html`) tidak akan berfungsi; gunakan hash router (`#/detail/42`) atau
  sediakan berkas statis per jalur. Untuk kebutuhan rewrite, ganti service ke
  web server lain lewat tab Compose.
- httpd template ini hanya menyajikan docroot `/www`, jadi permintaan ke path di
  luar `/www` tidak membocorkan berkas container.
- Dokumentasi BusyBox (termasuk applet `httpd` beserta opsi `-h`/`-p`):
  [BusyBox Documentation](https://busybox.net/downloads/BusyBox.html)
