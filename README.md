<p align="center">
  <img src="https://image.web.id/images/logo-rames.png" alt="Rames Logo" width="140">
</p>

# Rames — Deploy Dashboard

**Rames** — dashboard manajemen deployment berbasis Docker (mirip cPanel sederhana) untuk mengelola **app** yang di-deploy dari repo Git berisi `docker-compose.yml`. Dibangun dengan **Webman (PHP 8.1+)** dan berjalan di dalam **container Docker**; reverse proxy memakai **Nginx native di host**.

> Spesifikasi kebutuhan: [`SPECS.md`](./SPECS.md) · Struktur & cara kerja: [`ARCHITECTURE.md`](./ARCHITECTURE.md)

## Fitur

- **Autentikasi** — login/logout berbasis session, password bcrypt, multi-user dengan role global (admin/member) + kepemilikan & sharing app per user (viewer/operator/owner; SPECS §7.7).
- **Manage Users** — tambah/hapus user, ganti password.
- **App Management** — buat app dari URL repo Git, deteksi & edit host port, deteksi konflik port dengan saran port otomatis.
- **Deploy otomatis** — clone repo → parse `docker-compose.yml` → tulis override port → `docker compose up -d --build` → kumpulkan info container → generate config Nginx.
- **Background worker** — deploy/rebuild berjalan async (proses terpisah) dengan status yang bisa di-*poll* dari UI.
- **Reverse proxy** — tiap app otomatis mendapat subdomain `{name}.{APP_DOMAIN}`; subdomain bisa **diganti** (label sendiri, terpisah dari nama app) lewat tab **Domain & SSL** (SPECS §7.11).
- **Custom domain** — app bisa diberi satu custom domain; subdomain bawaan redirect (301) ke custom domain.
- **SSL otomatis (Let's Encrypt)** — aktifkan SSL per domain (subdomain/custom domain) lewat halaman SSL; certbot dijalankan di dashboard, blok `listen 443 ssl` di-render sendiri.
- **Reload Nginx dari dashboard** — tombol "Reload Nginx" + auto-reload setelah set custom domain, deploy/rebuild, dan aktivasi SSL (via Docker socket).
- **Container management** — daftar container per app, aksi Rebuild / Stop / Start / Delete.
- **Database manager (Adminer)** — halaman `/database` membuka **Adminer** yang disajikan helper internal Rames **tanpa port publik** (di balik login + hak akses app, ability `database` = operator ke atas): daftar container MySQL/MariaDB, tombol **Kelola →**, kredensial terisi otomatis bila terdeteksi (kalau tidak, form login Adminer muncul dengan Server ter-prefill). Dump/import besar diarahkan ke **Volume/backup** atau tab **Terminal** (proxy dibatasi ≤30 detik & ukuran respons; SPECS §7.10).
- **File manager container** — jelajah berkas, unggah multi-berkas, unduh, edit teks, buat folder, rename, pindah, hapus, dan ekstrak `.zip`/`.tar.gz` **di dalam container app** dari dashboard (tab **Container**, tombol `📁 Files`; ability `files` = operator ke atas, hanya untuk container yang berjalan; operasi berjalan sebagai root di dalam container; SPECS §7.9).
- **Backup volume harian ke S3 (restic)** — volume Docker milik app di-backup harian ke object storage (S3) via **restic** (inkremental + dedup + enkripsi + retensi): container database didump logis (tanpa downtime), volume lain di-snapshot (stop → snapshot → start). Restore dari UI di halaman `/backups` (SPECS §8h). Volume yang sudah dihapus (app dihapus total) tetap dapat dipulihkan dari tab **Arsip** (admin) — restore ke volume baru atau unduh dump `.sql`.
- **Batas resource per service** — admin menetapkan batas maksimum CPU & memori tiap service app (hard limit per service, ditulis ke override compose); user lain melihat nilainya read-only.
- **Kredit, deposit & penagihan resource (role member)** — app milik user `member` ditagih pemakaian CPU/RAM per jam dari **saldo kredit**: meteran per tick, tagihan otomatis awal periode berikutnya, dan saldo negatif ⇒ app owner **dihentikan otomatis** (kebijakan `stop`). Kredit diisi **deposit manual admin** (halaman `/credits`) atau **top-up mandiri via Duitku** (inquiry → redirect → callback tervalidasi HMAC-SHA256; dibuka lewat **modal** di halaman Kredit; kanal pembayaran ditampilkan **terkelompok per jenis** — Virtual Account/E-Wallet/QRIS/Retail, urutan kanonik dari server). Form email notifikasi kini di halaman **Profil** (`/profile`). Admin **bebas kredit penuh** (aktor admin **tidak pernah** diblokir gerbang) dan **app milik admin bebas kredit** — tidak diakru, tidak ditagih, dan tidak dibatasi plafon CPU/RAM untuk siapa pun; app yang di-*share* tetap ditagih ke **owner**; owner/member memilih CPU/RAM saat create dalam plafon admin (plafon hanya ditegakkan untuk member). Gerbang kredit mengecualikan aktor exempt (admin) & pemilik exempt (app milik admin) lebih dulu, lalu menilai aktor `member` (wajib bersaldo) dan owner/pembayar bila berbeda — aturan yang sama juga ditegakkan di worker deploy async (identitas aktor diteruskan ke proses worker; urutan penilaian **aktor → pembayar/owner** dengan pengecualian exempt yang sama — admin/app milik admin — jadi controller & worker tidak berbeda putusan, sedangkan pemanggil lama tanpa aktor membuat worker menilai **hanya owner**); sehingga **member tanpa saldo tetap diblokir** untuk app miliknya, dan operator member tetap diblokir bila owner member kekurangan saldo. App berjalan yang dialihkan ke pemilik bebas tagihan (admin) **dihentikan otomatis** — termasuk saat user pemilik dihapus (app-nya dialihkan ke admin) — agar tidak ada pemakaian gratis; admin tetap bisa menyalakannya kembali (app itu **tetap** diakru & akan dihentikan lagi bila kebijakan `stop` berlaku, jadi bukan jalur gratis). Bila billing/top-up dimatikan, deposit manual tetap berfungsi; bila top-up belum aktif (mis. kredensial belum diisi), **admin** melihat alasannya di halaman Kredit (SPECS §7.12).
- **Keamanan dasar** — CSRF token, eksekusi command bebas injection (`array` + `bypass_shell`), validasi input ketat, data domain di SQLite (transaksi atomik), cache JSON dengan file locking (`flock`).
- **Basis data dashboard (SQLite) + backup berkala** — app/user/billing/seleksi+riwayat volume disimpan di satu berkas SQLite (`rames.sqlite`) dalam named volume **`rames`** (tidak hilang saat `git clean`/update). Snapshot `VACUUM INTO` harian + retensi, impor otomatis **sekali** dari `database/*.json` lama, dan restore dari UI/CLI (kartu **Database dashboard (SQLite)** di `/backups`, admin). Lihat [Basis data dashboard (SQLite)](#basis-data-dashboard-sqlite) (SPECS §8i).

## Tech Stack

| Komponen | Pilihan |
|---|---|
| Backend | Webman (PHP 8.1+) — HTTP server non-blocking |
| Container runtime | Docker + Docker Compose |
| Reverse proxy | Nginx native di host (dashboard hanya menulis file config) |
| Storage | **SQLite** (`rames.sqlite` di named volume `rames`) untuk data domain; **JSON + `flock`** untuk cache runtime |
| Parsing YAML | `symfony/yaml` |
| Docker Engine API | `guzzlehttp/guzzle` (hand-rolled via unix socket) |
| Environment | `vlucas/phpdotenv` |

## Prasyarat (Host)

- Docker Engine + Docker Compose plugin **v2.6+** (memakai tag YAML `!reset`)
- Nginx terinstall, direktori `sites-available/` & `sites-enabled/` ada
- DNS wildcard `*.{APP_DOMAIN}` diarahkan ke IP server (prasyarat; bukan tanggung jawab dashboard)
- Port 80/443 host terbuka untuk traffic app
- (Disarankan) daemon Docker mengizinkan `--privileged` — dipakai fitur "Reload Nginx dari dashboard"

## Instalasi & Menjalankan

Panduan langkah demi langkah. Jalankan semua perintah dari direktori project (folder berisi `docker-compose.yml`), dan pastikan [Prasyarat](#prasyarat-host) sudah terpenuhi.

### Instalasi otomatis (disarankan)

[`host/install.sh`](./host/install.sh) menyiapkan host & dashboard dalam satu perintah: menginstal prasyarat (nginx, inotify-tools, certbot + plugin, docker compose), membuat `.env`, memastikan direktori & include Nginx, membangun & menjalankan dashboard, membuat user admin pertama, lalu memasang **watcher reload Nginx** (`dashboard-nginx-watcher.service`) dan **timer renewal certbot** (`certbot-renew.timer`):

```bash
sudo ./host/install.sh --domain example.com --email admin@example.com
```

Idempoten — aman dijalankan ulang. Lihat `./host/install.sh --help` untuk opsi (mis. `--no-deps`, `--non-interactive`). Bila memakai installer, **Langkah 1–3 di bawah tidak perlu dijalankan manual** (`.env`, build, dan `make:admin` sudah ditangani; kredensial admin dicetak di akhir instalasi). Langkah manual berikut disediakan sebagai alternatif/pengecekan.

### Langkah 1 — Siapkan environment (`.env`)

```bash
cp .env.example .env
```

Edit file `.env`. Minimal ubah 3 nilai berikut:

| Variabel | Contoh | Keterangan |
|---|---|---|
| `APP_DOMAIN` | `example.com` | Domain dasar; app `{name}` otomatis dapat subdomain `{name}.{APP_DOMAIN}` |
| `SESSION_SECRET` | `(nilai acak)` | Secret session — **jangan biarkan `change-me`** |
| `ADMIN_EMAIL` | `admin@example.com` | Email untuk penerbitan SSL Let's Encrypt |

Boleh disesuaikan juga: `APP_PORT` (port dashboard di host, default `8000`), `PORT_RANGE_START`/`PORT_RANGE_END`, `NGINX_CONF_PATH`/`NGINX_ENABLED_PATH`, serta opsi SSL (`SSL_CHALLENGE`, `SSL_CA_SERVER`, `LETSENCRYPT_PATH`). Daftar lengkap: [Konfigurasi (.env)](#konfigurasi-env).

### Langkah 2 — Build & jalankan container

```bash
docker compose up -d --build
```

Periksa container **`rames-webman`** berjalan (status `Up`):

```bash
docker compose ps
```

> `docker compose up` membuat **named volume `rames`** (nama nyata di host: `rames`) yang di-mount ke `/var/lib/rames` → basis data SQLite dashboard + snapshot backup-nya. Sebelum server start, `command:` compose menjalankan `php cli/db.php migrate && php cli/db.php import-json` (migrasi skema + impor **sekali** `database/*.json` lama bila ada). Keduanya **fail-fast**: bila gagal, kontainer berhenti (`exit` non-zero) dan server **tidak** start. Lihat [Basis data dashboard (SQLite)](#basis-data-dashboard-sqlite).

### Langkah 3 — Buat user admin pertama (cukup sekali)

```bash
docker exec -it rames-webman php webman make:admin
```

Perintah ini mencetak **username** (default `admin`) dan **password acak**. Simpan keduanya — dipakai untuk login. (Opsional: tentukan sendiri, mis. `docker exec -it rames-webman php webman make:admin admin passwordku`.)

### Langkah 4 — Buka dashboard & login

- Buka `http://localhost:{APP_PORT}` (contoh: `http://localhost:8000`).
- Login dengan kredensial dari Langkah 3.

### Langkah 5 — Deploy app pertama

1. Klik **+ Create App**, isi **nama** (slug), **URL repo Git** (repo harus berisi `docker-compose.yml`), dan **branch**.
2. Ikuti wizard: dashboard mendeteksi service & port, tampilkan halaman konfirmasi → klik submit.
3. Deploy berjalan di background; status app berubah `deploying → running`.
4. Config Nginx ditulis dan **nginx host di-reload otomatis** (lihat [Reload Nginx host](#reload-nginx-host-dari-dashboard)).

Buka `http://{app}.{APP_DOMAIN}` di browser. Pastikan DNS subdomain mengarah ke server (untuk pengujian lokal: [Pengujian Lokal](#pengujian-lokal-subdomain)).

### Langkah 6 — (Opsional) Custom domain & SSL

1. Buka halaman detail app → kartu **Custom Domain** → isi domain (mis. `app.example.org`) → **Set**.
2. Klik **Aktifkan SSL** untuk menerbitkan sertifikat Let's Encrypt (prasyarat: DNS domain mengarah ke server ini, port 80 publik terbuka, `ADMIN_EMAIL` terisi).

### Reload Nginx host (dari dashboard)

Dashboard menulis config Nginx lalu me-reload nginx host secara **otomatis** setelah: set/hapus custom domain, deploy/rebuild, dan aktivasi SSL. Ada juga tombol **↻ Reload Nginx** di halaman detail app untuk reload manual. Halaman operasional host **`/nginx`** (menu **Config**, termasuk panel self-update) bersifat **admin-only**: menu tidak tampil untuk member, dan akses langsung ke `/nginx` ditolak **404**.

> Reload memakai helper container `--pid host --privileged` via Docker socket (butuh daemon Docker yang mengizinkan `--privileged`). Bila mekanisme ini tidak tersedia, pasang watcher host (SPECS §8.3) atau reload manual: `sudo systemctl reload nginx`.

### Mengubah kode dashboard (setelah edit)

Perubahan **kelas PHP** dashboard (`app/controller/**`, `app/library/**`, `app/model/**`, `config/*.php`) **WAJIB** disusul reload worker — worker Webman bersifat *persistent* dan memegang kelas lama di memori:

```bash
docker exec rames-webman php start.php reload
```

- Perubahan **view** (`app/view/**`) dan **CSS/JS statis** (`public/**`) umumnya **langsung** berlaku (di-include/di-serve per request); cukup **hard refresh** browser (`Ctrl+Shift+R`).
- **WAJIB**: bila mengubah `public/js/app-terminal.js` atau `public/js/app-files.js`, naikkan query `?v=N` di `app/view/app/partials/detail-scripts.php` — `?v=` adalah satu-satunya cache-buster untuk kedua berkas itu; tanpa menaikkannya browser bisa tetap memakai JS lama.
- **Jangan** `docker compose up -d --build` hanya untuk memuat ulang kode — rebuild hanya bila `Dockerfile`/dependency berubah. Alternatif restart penuh: `docker compose restart rames-webman` (koneksi terputus sesaat).
- Verifikasi cepat opsional: `docker exec rames-webman php vendor/bin/phpunit`.

### Backup Volume ke S3 (restic)

Halaman **`/backups`** mem-backup volume Docker milik app ke S3 **harian** via restic (timer host `volume-backup.timer`, 02:30). **Prasyarat & langkah:**

1. **Rebuild image dashboard** — fitur ini butuh binary `restic` di dalam image; pada instalasi lama **wajib** rebuild dulu:
   ```bash
   docker compose up -d --build
   docker exec rames-webman restic version   # bukti restic tersedia
   ```
2. **Isi kredensial** di `.env`: `RESTIC_REPOSITORY` (mis. `s3:https://s3.amazonaws.com/<bucket>/rames`), `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`.
3. **Siapkan passphrase restic** (file, bukan env): tulis passphrase ke `database/restic/password` lalu `chmod 0600 database/restic/password`.
4. **Pasang timer host** (bila belum): `sudo ./host/install.sh` memasang `volume-backup.{service,timer}`; jalankan backup pertama dari halaman `/backups` (tombol **Backup sekarang**).

> Panduan langkah demi langkah ini juga tersedia **di dalam UI**: buka **`/backups/guide`** (tombol **📖 Panduan setup** di halaman `/backups`; **khusus admin**) — dashboard merender `host/restic-setup.md`, termasuk prasyarat, izin bucket S3, file passphrase, `restic init`, timer host, dan troubleshooting.

> Fitur ini **terpisah** dari backup basis data dashboard (SQLite `rames.sqlite` di named volume `rames`, SPECS §8g/§8i) — jangan mencampur konfigurasinya (`VOLUME_BACKUP_*`/`RESTIC_*` vs `DB_BACKUP_*`).

**Segarkan status & seleksi berkala.** Tabel di `/backups` dimuat dari **cache** (tanpa memanggil Docker/restic tiap poll), jadi kolom **Status container**/**Snapshot** baru akurat setelah run atau setelah menekan **Segarkan status** (khusus admin; footer menampilkan waktu `cached_at`). Admin juga dapat memilih volume mana yang ikut backup **harian** lewat kolom **Berkala**. Default-nya **OFF** (opt-in): volume yang **belum pernah dibackup** nonaktif — aktifkan togglenya agar ikut backup harian; volume yang **sudah pernah dibackup** otomatis diaktifkan (backfill). Pilihannya tersimpan di store `backup` basis data SQLite dashboard (tabel `backup_volumes`, SPECS §8i). Mematikan **Berkala** hanya melewati run **harian** — tombol **Backup sekarang** tetap mem-backup volume tersebut. Catatan: menekan **Segarkan status** (admin) juga menjalankan backfill ini, sehingga volume ber-snapshot langsung aktif tanpa dicentang manual.

**Arsip volume (admin).** Snapshot restic tetap ada di S3 walau app beserta volumenya sudah dihapus. Tab **Arsip** di `/backups` (**khusus admin**) menampilkan riwayat volume yang pernah ter-backup dan memungkinkan **Lihat snapshot**, **Restore ke volume baru…** (volume Docker **baru** dibuat — volume lama tidak disentuh), atau **Unduh SQL** untuk volume yang di-backup sebagai dump DB. Riwayat volume yang snapshot-nya sudah habis otomatis dipangkas; pemangkasan **tidak** dijalankan saat repo tak terbaca/kosong, demi keamanan riwayat. Volume yang sudah punya snapshot **sebelum** fitur arsip aktif pun otomatis masuk riwayat (backfill saat **Segarkan status**/akhir run).

**Restore manual darurat (SSH).** Bila UI tidak bisa dipakai, worker CLI yang sama dapat dijalankan langsung di container dashboard. Restore **destruktif** (isi volume ditimpa; container app dihentikan sementara lalu dinyalakan kembali):

```bash
# Lihat snapshot dari UI (/backups → modal snapshot) atau riwayat: runtime/backup/runs/*.json
# Backup satu volume secara manual:
docker exec rames-webman php cli/backup.php run <nama-volume> manual

# Restore satu volume dari snapshot (appId `-` untuk volume yatim):
docker exec rames-webman php cli/backup.php restore <appId|-> <nama-volume> <snapshot-id>

# Restore volume ARSIP (sudah dihapus) ke volume BARU (admin; SPECS §8h.11):
docker exec rames-webman php cli/backup.php restore-archived <nama-volume> <snapshot-id> <nama-volume-baru>
```

Worker memvalidasi ulang kepemilikan volume terhadap store `apps` (basis data SQLite dashboard) sebelum restore (volume milik app lain ditolak). Log: `runtime/logs/backup/{project}.log` + `runtime/backup/status.json`.

> Catatan: backup **volume app** ini **terpisah** dari backup **basis data dashboard** (SQLite) di bawah — jangan mencampur konfigurasi (`VOLUME_BACKUP_*`/`RESTIC_*` vs `DB_BACKUP_*`).

### Basis data dashboard (SQLite)

Data dashboard (app, user, kredit/billing, seleksi + riwayat volume) disimpan di **satu berkas SQLite** `rames.sqlite` di dalam named volume **`rames`** (di container: `/var/lib/rames`), bukan lagi di `database/*.json`. Named volume memastikan data **tidak hilang** saat `git clean`/update kode. Berkas JSON lama di `database/` **tidak dihapus**; isinya diimpor **sekali** ke SQLite saat start pertama.

Kartu **Database dashboard (SQLite)** di halaman **`/backups`** (**khusus admin**) menampilkan path & ukuran DB, snapshot terakhir, retensi, status terjadwal, dan tombol **Backup sekarang** / **Prune sekarang**, serta daftar snapshot dengan aksi **Unduh** & **Restore** (ketik `RESTORE` untuk konfirmasi).

- **Backup berkala**: proses `db-backup` membuat snapshot `VACUUM INTO` harian (jam `DB_BACKUP_HOUR`, default `03:00`) + retensi harian/pekanan/bulanan. Backing/restore diserialkan mutex — bila ada backup lain berjalan, run ini **dilewati** (`busy`), bukan gagal.
- **Proses `db-backup` = proses baru**: saat pertama kali di-deploy (mis. setelah upgrade), **restart** kontainer — `docker exec rames-webman php start.php restart -d` (reload saja tidak menambah proses baru).

**Backup/restore manual (SSH):**

```bash
# Status DB (berkas, migrasi, jumlah baris, backup terakhir) + cek integritas:
docker exec rames-webman php cli/db.php status
docker exec rames-webman php cli/db.php integrity

# Buat snapshot manual / jalankan retensi / daftar snapshot:
docker exec rames-webman php cli/db.php backup --reason=manual
docker exec rames-webman php cli/db.php prune
docker exec rames-webman php cli/db.php list

# Pulihkan DB dari snapshot (menimpa DB aktif; safety snapshot dibuat lebih dulu):
docker exec rames-webman php cli/db.php restore rames-YYYYmmdd-HHMMSS.sqlite --yes
```

> **WAJIB — setelah restore, reload worker.** Worker Webman yang masih hidup memegang koneksi PDO ke **inode lama**; tanpa reload, worker bisa tetap membaca DB lama. Jalankan:
> ```bash
> docker exec rames-webman php start.php reload
> ```
> Tombol **Restore** di UI hanya menampilkan peringatan ini — ia **tidak** me-reload otomatis (proses yang merestore adalah worker itu sendiri).

**Rollback ke JSON (bila perlu).** Bila harus kembali ke format JSON lama, ekspor seluruh store SQLite ke `database/*.json`:

```bash
docker exec rames-webman php cli/db.php export-json            # ke direktori database default
docker exec rames-webman php cli/db.php export-json --dir=/tmp # ke direktori lain
```

> **Upgrade/recreate kontainer.** Untuk memuat perubahan env (`RAMES_DB_*`, `DB_BACKUP_*`) & named volume, jalankan `docker compose up -d --build` (recreate). Impor JSON lama hanya berjalan **sekali** (flag `kv.legacy_imported_at`); `docker exec rames-webman php cli/db.php import-json` bisa dipaksa dengan `--force`.

### Pengujian Lokal (subdomain)

`/etc/hosts` **tidak mendukung wildcard**. Untuk membuka `{app}.{APP_DOMAIN}` di browser:

- **Opsional cepat** — tambahkan entry eksplisit per app di `/etc/hosts`:
  ```
  127.0.0.1 helloworld.dockerdeploy.local
  ```
- **Wildcard otomatis** — pasang `dnsmasq` dengan `address=/{APP_DOMAIN}/127.0.0.1` (lihat `ARCHITECTURE.md`/catatan pengujian).

## Konfigurasi (.env)

| Variabel | Default | Keterangan |
|---|---|---|
| `APP_DOMAIN` | `example.com` | Domain dasar; app dapat subdomain `{name}.{APP_DOMAIN}` |
| `APP_PORT` | `8000` | Port akses dashboard (host) |
| `SESSION_SECRET` | `change-me` | Secret session — **wajib ganti** |
| `PORT_RANGE_START` / `PORT_RANGE_END` | `30000` / `30999` | Rentang host port untuk container app |
| `NGINX_CONF_PATH` / `NGINX_ENABLED_PATH` | `/etc/nginx/sites-available` / `sites-enabled` | Direktori config Nginx host (di-mount ke container) |
| `NGINX_RELOAD_STATUS_FILE` | `{proyek}/nginx-status/last-reload.json` | Status reload yang ditulis watcher host / dashboard |
| `NGINX_HTTP_CONF` | `/etc/nginx/nginx.conf` | Config utama nginx host (dipakai helper reload) |
| `NGINX_BIN` | `/usr/sbin/nginx` | Binary nginx host (dipakai helper reload) |
| `NGINX_RELOAD_IMAGE` | `alpine` | Image helper reload nginx (cukup `sh` + `chroot`) |
| `DOCKER_SOCKET` | `/var/run/docker.sock` | Socket Docker Engine |
| `DEPLOY_TIMEOUT` | `600` | Timeout operasi docker compose (detik) |
| `FILES_TIMEOUT` | `120` | Timeout perintah singkat file manager di dalam container (detik; SPECS §7.9) |
| `FILES_TRANSFER_TIMEOUT` | `600` | Timeout `docker cp` & ekstraksi arsip file manager (detik; SPECS §7.9) |
| `ADMINER_IMAGE` | `adminer:6` | Image helper Adminer untuk halaman `/database` (SPECS §7.10) |
| `ADMINER_CONTAINER` | `rames-adminer` | Nama container helper Adminer |
| `ADMINER_NETWORK` | `rames-helpers` | Network internal (tanpa port publik) yang dipakai dashboard + helper |
| `ADMINER_NETWORK_TTL` | `1800` | Umur attach helper ke network app (detik); idle ≥ TTL dilepas oportunistik, `0` = nonaktif. Network helper tak pernah dilepas (SPECS §7.10) |
| `ADMINER_WORKERS` | `8` | Worker `php -S` di helper (`PHP_CLI_SERVER_WORKERS`) |
| `ADMINER_PROXY_TIMEOUT` | `30` | Timeout HTTP proxy ke helper (detik; di-cap ≤30) |
| `ADMINER_PROXY_MAX_BYTES` | `67108864` | Batas ukuran respons/body proxy Adminer (byte, 64 MiB) |
| `ADMINER_PREFIX_BASE` | `/database` | Basis prefix URL publik Adminer per container |
| `DB_IMPORT_TIMEOUT` | `600` | Timeout restore dump logis (`DbDump`) pada Volume/backup (detik; SPECS §8h) — bukan halaman `/database` |
| `RAMES_DB_DIR` | `/var/lib/rames` | Direktori basis data SQLite dashboard (isi *named volume* `rames`; SPECS §8i) |
| `RAMES_DB_FILE` | (kosong) | Berkas SQLite eksplisit (kosong = `<RAMES_DB_DIR>/rames.sqlite`) |
| `RAMES_DB_BACKUP_DIR` | `/var/lib/rames/backup` | Direktori snapshot backup DB |
| `DB_BACKUP_ENABLED` | `true` | Aktifkan backup DB terjadwal (snapshot `VACUUM INTO` + retensi) |
| `DB_BACKUP_HOUR` | `3` | Jam snapshot harian (0–23, waktu container) |
| `DB_BACKUP_KEEP_DAILY` / `_WEEKLY` / `_MONTHLY` | `7` / `4` / `3` | Retensi snapshot harian/pekanan/bulanan (`0` = tingkat diabaikan; hanya snapshot terbaru dipertahankan bila semua `0`) |
| `DB_IMPORT_LEGACY_JSON` | `true` | Impor **sekali** `database/*.json` lama ke SQLite saat koneksi pertama |
| `DNS_1` / `DNS_2` | `8.8.8.8` / `1.1.1.1` | DNS untuk container (diperlukan jika resolv.conf host bermasalah) |
| `ADMIN_EMAIL` | — | Email untuk SSL Let's Encrypt (wajib saat mengaktifkan SSL) |
| `SSL_CHALLENGE` | `http` | Mode challenge: `http` (webroot) atau `dns-cloudflare` |
| `SSL_CA_SERVER` | `production` | CA Let's Encrypt: `production` / `staging` (staging untuk uji) |
| `SSL_WEBROOT` | `{proyek}/webroot` | Webroot HTTP-01 challenge |
| `LETSENCRYPT_PATH` | `/etc/letsencrypt` | Direktori sertifikat (di-mount dari host) |
| `CLOUDFLARE_CREDS` | — | File kredensial DNS Cloudflare (saat `SSL_CHALLENGE=dns-cloudflare`) |
| `VOLUME_BACKUP_ENABLED` | `true` | Aktifkan backup volume harian ke S3 (SPECS §8h) |
| `VOLUME_BACKUP_DB_DUMP_ENABLED` | `true` | Strategi A: dump logis untuk container DB (container tetap hidup) |
| `VOLUME_BACKUP_SNAPSHOT_POLICY` | `stop` | `stop` = volume non-DB di-backup harian via stop→snapshot→start; `skip` = manual saja |
| `VOLUME_BACKUP_REQUIRE_STOPPED` | `true` | Tolak snapshot bila masih ada container berjalan yang memakai volume |
| `VOLUME_BACKUP_STOP_TIMEOUT` / `VOLUME_BACKUP_DUMP_TIMEOUT` / `VOLUME_BACKUP_TIMEOUT` | `120` / `600` / `3600` | Timeout (detik): tunggu container berhenti / dump / satu run restic |
| `VOLUME_BACKUP_IMAGE` | (kosong) | Image helper restic (kosong = image container dashboard) |
| `VOLUME_BACKUP_KEEP_DAILY` / `_WEEKLY` / `_MONTHLY` | `7` / `4` / `3` | Retensi `restic forget --keep-*` |
| `RESTIC_REPOSITORY` | — | Repo restic, mis. `s3:https://s3.amazonaws.com/<bucket>/rames` — **tanpa** kredensial di dalamnya |
| `RESTIC_PASSWORD_FILE` | `{proyek}/database/restic/password` | File passphrase restic (chmod `0600`, gitignored) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` | — | Kredensial S3 (diteruskan ke helper restic via `--env-file`, bukan argv) |
| `BILLING_ENABLED` | `true` | Aktifkan meteran & gerbang kredit (SPECS §7.12) |
| `BILLING_RATE_CPU_PER_CORE_HOUR` / `BILLING_RATE_RAM_PER_GB_HOUR` | `100` / `20` | Tarif kredit per core-jam CPU / GB-jam RAM (= rupiah per jam pada kurs 1:1) |
| `BILLING_DEFAULT_CPUS` / `BILLING_DEFAULT_MEMORY_MB` | `0.5` / `512` | Basis limit service tanpa entri `limits` (anti-lubang harga) |
| `BILLING_MIN_DEPOSIT_DAYS` | `30` | Deposit minimum = estimasi biaya N hari (`0` = cukup saldo ≥ 0) |
| `BILLING_SAMPLE_SECONDS` | `300` | Resolusi meteran = interval tick proses billing (detik; `0` = tanpa timer) |
| `BILLING_INVOICE_DAY` | `1` | Tanggal penagihan periode (dijepit 1–28; catch-up bila dashboard sempat mati) |
| `BILLING_PAYMENT_POLICY` | `stop` | `stop` = saldo negatif → hentikan app owner; selain `stop` = `block` |
| `BILLING_MAX_CPUS` / `BILLING_MAX_MEMORY_MB` | `4` / `8192` | Plafon CPU/RAM yang boleh dipilih owner saat create |
| `BILLING_LEDGER_KEEP` | `200` | Jumlah entri ledger terakhir per user (`0`/negatif = tanpa batas) |
| `BILLING_LOG_PATH` | (kosong) | Direktori log billing (kosong = `runtime/logs/billing`) |
| `BILLING_ADMIN_DEPOSIT_MAX` | `10000000` | Batas nominal satu deposit/adjust manual admin (kredit) |
| `BILLING_TOPUP_ENABLED` | `false` | Aktifkan top-up mandiri via Duitku (butuh kredensial merchant di bawah) |
| `BILLING_TOPUP_IDR_PER_CREDIT` | `1` | Kurs konversi: Rp1 = 1 kredit (1:1) |
| `BILLING_TOPUP_MIN_IDR` / `BILLING_TOPUP_MAX_IDR` | `10000` / `5000000` | Batas nominal top-up (Rp; maksimum aman untuk semua kanal) |
| `BILLING_TOPUP_EXPIRY_MINUTES` | `0` | `0` = field `expiryPeriod` tidak dikirim (pakai default kanal Duitku) |
| `BILLING_TOPUP_MAX_PENDING` | `3` | Cap order top-up pending per user (pengganti rate limiter) |
| `BILLING_DUITKU_MODE` | `sandbox` | `sandbox` / `production` → base URL Duitku (kredensial terpisah) |
| `BILLING_DUITKU_MERCHANT_CODE` | — | Merchant/project code Duitku (bukan secret) |
| `BILLING_DUITKU_API_KEY` | — | **RAHASIA** (HMAC-SHA256 key) — jangan commit/log/tampilkan |
| `BILLING_DUITKU_CALLBACK_URL` | — | URL callback **https absolut** yang dipanggil Duitku |
| `BILLING_DUITKU_RETURN_URL` | — | Opsional; kosong ⇒ diturunkan dari origin callback URL + `/credits/topup/return` |
| `BILLING_DUITKU_ALLOW_HTTP` | `false` | `true` = izinkan callback `http://` — **khusus uji lokal**; produksi wajib https |
| `BILLING_DUITKU_METHOD_TTL` | `3600` | Cache daftar metode pembayaran (detik) |
| `BILLING_DUITKU_METHODS` | allowlist kanal | Fallback statis bila daftar metode online gagal (kanal kredit/paylater & account-link selalu dikecualikan) |
| `BILLING_DUITKU_TIMEOUT` | `15` | Timeout HTTP ke Duitku (detik; di-cap ≤30) |
| `BILLING_DUITKU_STATUS_MIN_INTERVAL` | `900` | Jeda minimum cek `transactionStatus` per order (detik; hindari blokir hit-rate) |
| `BILLING_DUITKU_STATUS_MAX_PER_TICK` | `20` | Maksimum order yang dicek statusnya per tick meteran |

## Struktur Direktori (Ringkas)

```
app/
├── command/MakeAdmin.php        # php webman make:admin (provisioning user awal)
├── controller/                  # AuthController, AppController, CreditController, PaymentController,
│                                #   FileController, SslController, NginxController, UserController
├── library/                     # SELURUH logika bisnis (controller hanya mediator)
│   ├── Auth/UserStore.php
│   ├── Billing/                 # kredit & penagihan + top-up Duitku: Pricing, BillingStore, CreditAccount,
│   │                            #   UsageMeter, Invoicer, BillingGate, BillingPeriod, InsufficientCredits,
│   │                            #   BillingRunner, AppStopper, DuitkuClient, DuitkuSignature, DuitkuError,
│   │                            #   TopUpOrder, TopUpService
│   ├── Deploy/                  # DeployerInterface, LocalDeployer, DeployerFactory
│   ├── Docker/                  # ComposeParser, DockerClient, DockerComposeRunner, PortManager, AppContainers
│   ├── Files/                   # file manager container: PathGuard, TextContent, ArchiveGuard,
│   │                            #   ListingParser, FileError, FilesInput, ContainerFiles
│   ├── Git/GitService.php
│   ├── Nginx/                   # NginxConfigGenerator, NginxStatusReader, NginxReloader
│   ├── SSL/SslIssuer.php
│   ├── Storage/                 # SqliteDatabase, SqliteStore, SchemaMigrations, JsonImporter, DbBackup,
│   │                            #   JsonStore (cache runtime, flock), AppStore
│   └── Support/ProcessRunner.php
├── middleware/                  # AuthMiddleware, CsrfMiddleware
├── process/BillingProcess.php   # timer billing (meteran + penagihan + top-up)
├── process/DbBackupProcess.php  # timer backup DB SQLite (snapshot + retensi)
└── view/                        # template Raw Webman (.html)
cli/deploy.php                   # background worker deploy/rebuild
cli/billing.php                  # worker CLI billing (status|sample|tick|invoice|expire)
cli/db.php                       # CLI basis data SQLite (status|migrate|import-json|export-json|integrity|backup|list|prune|restore)
cli/ssl.php                      # background worker SSL (Let's Encrypt)
config/                          # konfigurasi Webman + config/deploy.php
database/                        # berkas JSON warisan (diimpor sekali) + keys/env/restic (gitignored)
                                 #   DB SQLite: named volume `rames` → /var/lib/rames/rames.sqlite (§8i)
apps/                           # hasil clone tiap app (gitignored)
nginx-status/                    # status reload nginx (gitignored)
public/css/app.css               # stylesheet dashboard
public/js/app-files.js           # file manager container (modal di detail app)
runtime/                         # state runtime (gitignored): logs/, db-backup/, files-transfer/
```

## Alur Create App

1. Isi form: nama app (slug), URL repo Git, branch.
2. Dashboard clone repo → cek `docker-compose.yml` → parse service & port.
3. Deteksi konflik port → halaman konfirmasi (edit host port + pilih primary service).
4. Submit → tulis `docker-compose.override.yml` + `docker-compose.override.ports.yml` → simpan app (status `deploying`) → spawn worker background.
5. Worker: `docker compose up -d --build` → kumpulkan info container → generate config Nginx → status `running`.
6. Config Nginx ditulis & nginx host di-reload otomatis oleh dashboard.

## Keamanan

- Password di-hash bcrypt (`password_hash`/`password_verify`), tidak pernah plaintext.
- Semua request yang mengubah state divalidasi token CSRF.
- Eksekusi command memakai bentuk **array + `bypass_shell`** (tanpa shell → bebas command injection).
- Validasi input: slug app `[a-z0-9-]`, URL repo http/https, branch, port integer 1–65535.
- Data domain dashboard disimpan di **SQLite** — mutasi atomik lewat transaksi (`BEGIN IMMEDIATE`), berkas DB di named volume `rames` (gitignored bila di repo); cache runtime JSON ditulis dengan `flock` (anti race condition).
- Backup/restore basis data dashboard (`/backups/db/*`, CLI `cli/db.php`) **khusus admin** (non-admin → 404); restore validasi nama/path, `integrity_check`, dan tabel inti **sebelum** mengganti berkas + membuat safety snapshot.
- Mount `docker.sock` adalah risiko yang **disengaja** untuk Phase 1 — dashboard selalu di balik autentikasi.
- Direktori Nginx yang di-mount dibatasi hanya `sites-available/` + `sites-enabled/`.
- File manager container: ability `files` = operator ke atas (setara terminal/database); path & nama dinormalisasi (`PathGuard`) sebelum masuk `docker exec`/`docker cp` (argumen di-`escapeshellarg`), ekstraksi arsip di **host** dengan proteksi zip-slip, dan transfer byte lewat berkas temp — tidak memuat berkas besar ke memori PHP.
- **Callback pembayaran Duitku** (`POST /payments/duitku/callback`) adalah pengecualian CSRF **kedua & sempit** (path eksak, hanya POST; pengecualian Adminer tidak dilebarkan) — keamanannya dari verifikasi signature **HMAC-SHA256** (`hash_equals`) + kecocokan amount + idempotensi, tanpa session, respons polos tanpa data sensitif. `BILLING_DUITKU_API_KEY` hanya lewat `.env`/`config()` dan tidak pernah masuk log/view/JSON.

## Troubleshooting Umum

- **`Permission denied` saat menulis `/etc/nginx`** — dashboard harus berjalan sebagai root (container). Jangan jalankan `php webman start` di host saat container aktif; file runtime akan dimiliki root.
- **`Could not resolve host` saat clone** — resolv.conf host rusak; container memakai `dns:` eksplisit (atur `DNS_1`/`DNS_2`).
- **Port "sudah terpakai"** — dashboard hanya mendeteksi port milik app-nya sendiri; port yang dipakai container luar bisa diedit di halaman konfirmasi.
- **Subdomain tidak kebuka** — pastikan DNS resolve (hosts/dnsmasq) dan `sudo systemctl reload nginx`.
- **Unggah file manager gagal `413`** — batas 64 MiB per berkas (SPECS §7.9). Bila dashboard diakses lewat vhost Nginx, naikkan `client_max_body_size` vhost itu; perubahan `Dockerfile`/`config/server.php` juga baru berlaku setelah image dashboard di-build ulang & di-deploy ulang.
- **Tombol `📁 Files` tidak muncul / operasi file manager menolak `409`** — file manager hanya untuk container app yang **berjalan** dan user ber-ability `files` (operator ke atas). Jalankan app lebih dulu; periksa role user bila tombol tidak tampil.
- **Setelah restore DB, data masih terlihat lama** — worker Webman memegang koneksi PDO ke inode lama. Jalankan `docker exec rames-webman php start.php reload` (tombol Restore di UI tidak me-reload otomatis). Lihat [Basis data dashboard (SQLite)](#basis-data-dashboard-sqlite).
- **Backup DB harian tidak pernah jalan** — proses `db-backup` adalah proses **baru**: butuh `docker exec rames-webman php start.php restart -d` (bukan `reload`) sekali setelah deploy; pastikan `DB_BACKUP_ENABLED=true`.
- **`database/*.json` lama tidak terpakai** — impor ke SQLite hanya berjalan **sekali** (flag `kv.legacy_imported_at`, `DB_IMPORT_LEGACY_JSON=true`). Bila perlu impor ulang: `docker exec rames-webman php cli/db.php import-json --force` (store yang sudah berisi baris tidak ditimpa).

## Roadmap (Iterasi Berikutnya)

- Watcher host (`systemd` + `inotifywait`) untuk reload Nginx otomatis — sementara digantikan reload dari dashboard (lihat "Reload Nginx host")
- SSL multi-domain dalam satu sertifikat (SAN)
- Ekstrak `DeployerInterface` menjadi agent HTTP terpisah (multi-server)
- Role & permission antar user
- Log viewer real-time per container
- Penyimpanan data domain sudah di SQLite (named volume `rames`, backup berkala §8i); sisa: cache runtime tetap JSON, dan belum ada salinan off-site/S3 untuk snapshot DB dashboard
- Kredit & penagihan: refund/void top-up, rate limiting top-up, dan rekonsiliasi tagihan berbasis riwayat Docker (`StartedAt`) — menutup *under-count* saat dashboard mati (SPECS §7.12)
