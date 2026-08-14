<p align="center">
  <img src="https://image.web.id/images/logo-rames.png" alt="Rames Logo" width="140">
</p>

# Rames — Deploy Dashboard

**Rames** — dashboard manajemen deployment berbasis Docker (mirip cPanel sederhana) untuk mengelola **site** yang di-deploy dari repo Git berisi `docker-compose.yml`. Dibangun dengan **Webman (PHP 8.1+)** dan berjalan di dalam **container Docker**; reverse proxy memakai **Nginx native di host**.

> Spesifikasi kebutuhan: [`SPECS.md`](./SPECS.md) · Struktur & cara kerja: [`ARCHITECTURE.md`](./ARCHITECTURE.md)

## Fitur

- **Autentikasi** — login/logout berbasis session, password bcrypt, multi-user tanpa role (semua user punya akses penuh).
- **Manage Users** — tambah/hapus user, ganti password.
- **Site Management** — buat site dari URL repo Git, deteksi & edit host port, deteksi konflik port dengan saran port otomatis.
- **Deploy otomatis** — clone repo → parse `docker-compose.yml` → tulis override port → `docker compose up -d --build` → kumpulkan info container → generate config Nginx.
- **Background worker** — deploy/rebuild berjalan async (proses terpisah) dengan status yang bisa di-*poll* dari UI.
- **Reverse proxy** — tiap site otomatis mendapat subdomain `{name}.{APP_DOMAIN}`.
- **Custom domain** — site bisa diberi satu custom domain; subdomain bawaan redirect (301) ke custom domain.
- **SSL otomatis (Let's Encrypt)** — aktifkan SSL per domain (subdomain/custom domain) lewat halaman SSL; certbot dijalankan di dashboard, blok `listen 443 ssl` di-render sendiri.
- **Reload Nginx dari dashboard** — tombol "Reload Nginx" + auto-reload setelah set custom domain, deploy/rebuild, dan aktivasi SSL (via Docker socket).
- **Container management** — daftar container per site, aksi Rebuild / Stop / Start / Delete.
- **Keamanan dasar** — CSRF token, eksekusi command bebas injection (`array` + `bypass_shell`), validasi input ketat, JSON dengan file locking (`flock`).

## Tech Stack

| Komponen | Pilihan |
|---|---|
| Backend | Webman (PHP 8.1+) — HTTP server non-blocking |
| Container runtime | Docker + Docker Compose |
| Reverse proxy | Nginx native di host (dashboard hanya menulis file config) |
| Storage | File JSON (`database/auth.json`, `database/sites.json`) + `flock` |
| Parsing YAML | `symfony/yaml` |
| Docker Engine API | `guzzlehttp/guzzle` (hand-rolled via unix socket) |
| Environment | `vlucas/phpdotenv` |

## Prasyarat (Host)

- Docker Engine + Docker Compose plugin **v2.6+** (memakai tag YAML `!reset`)
- Nginx terinstall, direktori `sites-available/` & `sites-enabled/` ada
- DNS wildcard `*.{APP_DOMAIN}` diarahkan ke IP server (prasyarat; bukan tanggung jawab dashboard)
- Port 80/443 host terbuka untuk traffic site
- (Disarankan) daemon Docker mengizinkan `--privileged` — dipakai fitur "Reload Nginx dari dashboard"

## Instalasi & Menjalankan

Panduan langkah demi langkah. Jalankan semua perintah dari direktori project (folder berisi `docker-compose.yml`), dan pastikan [Prasyarat](#prasyarat-host) sudah terpenuhi.

### Langkah 1 — Siapkan environment (`.env`)

```bash
cp .env.example .env
```

Edit file `.env`. Minimal ubah 3 nilai berikut:

| Variabel | Contoh | Keterangan |
|---|---|---|
| `APP_DOMAIN` | `example.com` | Domain dasar; site `{name}` otomatis dapat subdomain `{name}.{APP_DOMAIN}` |
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

### Langkah 3 — Buat user admin pertama (cukup sekali)

```bash
docker exec -it rames-webman php webman make:admin
```

Perintah ini mencetak **username** (default `admin`) dan **password acak**. Simpan keduanya — dipakai untuk login. (Opsional: tentukan sendiri, mis. `docker exec -it rames-webman php webman make:admin admin passwordku`.)

### Langkah 4 — Buka dashboard & login

- Buka `http://localhost:{APP_PORT}` (contoh: `http://localhost:8000`).
- Login dengan kredensial dari Langkah 3.

### Langkah 5 — Deploy site pertama

1. Klik **+ Create Site**, isi **nama** (slug), **URL repo Git** (repo harus berisi `docker-compose.yml`), dan **branch**.
2. Ikuti wizard: dashboard mendeteksi service & port, tampilkan halaman konfirmasi → klik submit.
3. Deploy berjalan di background; status site berubah `deploying → running`.
4. Config Nginx ditulis dan **nginx host di-reload otomatis** (lihat [Reload Nginx host](#reload-nginx-host-dari-dashboard)).

Buka `http://{site}.{APP_DOMAIN}` di browser. Pastikan DNS subdomain mengarah ke server (untuk pengujian lokal: [Pengujian Lokal](#pengujian-lokal-subdomain)).

### Langkah 6 — (Opsional) Custom domain & SSL

1. Buka halaman detail site → kartu **Custom Domain** → isi domain (mis. `app.example.org`) → **Set**.
2. Klik **Aktifkan SSL** untuk menerbitkan sertifikat Let's Encrypt (prasyarat: DNS domain mengarah ke server ini, port 80 publik terbuka, `ADMIN_EMAIL` terisi).

### Reload Nginx host (dari dashboard)

Dashboard menulis config Nginx lalu me-reload nginx host secara **otomatis** setelah: set/hapus custom domain, deploy/rebuild, dan aktivasi SSL. Ada juga tombol **↻ Reload Nginx** di halaman detail site untuk reload manual.

> Reload memakai helper container `--pid host --privileged` via Docker socket (butuh daemon Docker yang mengizinkan `--privileged`). Bila mekanisme ini tidak tersedia, pasang watcher host (SPECS §8.3) atau reload manual: `sudo systemctl reload nginx`.

### Pengujian Lokal (subdomain)

`/etc/hosts` **tidak mendukung wildcard**. Untuk membuka `{site}.{APP_DOMAIN}` di browser:

- **Opsional cepat** — tambahkan entry eksplisit per site di `/etc/hosts`:
  ```
  127.0.0.1 helloworld.dockerdeploy.local
  ```
- **Wildcard otomatis** — pasang `dnsmasq` dengan `address=/{APP_DOMAIN}/127.0.0.1` (lihat `ARCHITECTURE.md`/catatan pengujian).

## Konfigurasi (.env)

| Variabel | Default | Keterangan |
|---|---|---|
| `APP_DOMAIN` | `example.com` | Domain dasar; site dapat subdomain `{name}.{APP_DOMAIN}` |
| `APP_PORT` | `8000` | Port akses dashboard (host) |
| `SESSION_SECRET` | `change-me` | Secret session — **wajib ganti** |
| `PORT_RANGE_START` / `PORT_RANGE_END` | `30000` / `30999` | Rentang host port untuk container site |
| `NGINX_CONF_PATH` / `NGINX_ENABLED_PATH` | `/etc/nginx/sites-available` / `sites-enabled` | Direktori config Nginx host (di-mount ke container) |
| `NGINX_RELOAD_STATUS_FILE` | `{proyek}/nginx-status/last-reload.json` | Status reload yang ditulis watcher host / dashboard |
| `NGINX_HTTP_CONF` | `/etc/nginx/nginx.conf` | Config utama nginx host (dipakai helper reload) |
| `NGINX_BIN` | `/usr/sbin/nginx` | Binary nginx host (dipakai helper reload) |
| `NGINX_RELOAD_IMAGE` | `alpine` | Image helper reload nginx (cukup `sh` + `chroot`) |
| `DOCKER_SOCKET` | `/var/run/docker.sock` | Socket Docker Engine |
| `DEPLOY_TIMEOUT` | `600` | Timeout operasi docker compose (detik) |
| `DNS_1` / `DNS_2` | `8.8.8.8` / `1.1.1.1` | DNS untuk container (diperlukan jika resolv.conf host bermasalah) |
| `ADMIN_EMAIL` | — | Email untuk SSL Let's Encrypt (wajib saat mengaktifkan SSL) |
| `SSL_CHALLENGE` | `http` | Mode challenge: `http` (webroot) atau `dns-cloudflare` |
| `SSL_CA_SERVER` | `production` | CA Let's Encrypt: `production` / `staging` (staging untuk uji) |
| `SSL_WEBROOT` | `{proyek}/webroot` | Webroot HTTP-01 challenge |
| `LETSENCRYPT_PATH` | `/etc/letsencrypt` | Direktori sertifikat (di-mount dari host) |
| `CLOUDFLARE_CREDS` | — | File kredensial DNS Cloudflare (saat `SSL_CHALLENGE=dns-cloudflare`) |

## Struktur Direktori (Ringkas)

```
app/
├── command/MakeAdmin.php        # php webman make:admin (provisioning user awal)
├── controller/                  # AuthController, SiteController, SslController, NginxController, UserController
├── library/                     # SELURUH logika bisnis (controller hanya mediator)
│   ├── Auth/UserStore.php
│   ├── Deploy/                  # DeployerInterface, LocalDeployer, DeployerFactory
│   ├── Docker/                  # ComposeParser, DockerClient, DockerComposeRunner, PortManager
│   ├── Git/GitService.php
│   ├── Nginx/                   # NginxConfigGenerator, NginxStatusReader, NginxReloader
│   ├── SSL/SslIssuer.php
│   ├── Storage/                 # JsonStore (flock), SiteStore
│   └── Support/ProcessRunner.php
├── middleware/                  # AuthMiddleware, CsrfMiddleware
└── view/                        # template Raw Webman (.html)
cli/deploy.php                   # background worker deploy/rebuild
cli/ssl.php                      # background worker SSL (Let's Encrypt)
config/                          # konfigurasi Webman + config/deploy.php
database/                        # auth.json, sites.json (runtime, gitignored)
sites/                           # hasil clone tiap site (gitignored)
nginx-status/                    # status reload nginx (gitignored)
public/css/app.css               # stylesheet dashboard
```

## Alur Create Site

1. Isi form: nama site (slug), URL repo Git, branch.
2. Dashboard clone repo → cek `docker-compose.yml` → parse service & port.
3. Deteksi konflik port → halaman konfirmasi (edit host port + pilih primary service).
4. Submit → tulis `docker-compose.override.yml` + `docker-compose.override.ports.yml` → simpan site (status `deploying`) → spawn worker background.
5. Worker: `docker compose up -d --build` → kumpulkan info container → generate config Nginx → status `running`.
6. Config Nginx ditulis & nginx host di-reload otomatis oleh dashboard.

## Keamanan

- Password di-hash bcrypt (`password_hash`/`password_verify`), tidak pernah plaintext.
- Semua request yang mengubah state divalidasi token CSRF.
- Eksekusi command memakai bentuk **array + `bypass_shell`** (tanpa shell → bebas command injection).
- Validasi input: slug site `[a-z0-9-]`, URL repo http/https, branch, port integer 1–65535.
- File JSON ditulis dengan `flock` (anti race condition).
- Mount `docker.sock` adalah risiko yang **disengaja** untuk Phase 1 — dashboard selalu di balik autentikasi.
- Direktori Nginx yang di-mount dibatasi hanya `sites-available/` + `sites-enabled/`.

## Troubleshooting Umum

- **`Permission denied` saat menulis `/etc/nginx`** — dashboard harus berjalan sebagai root (container). Jangan jalankan `php webman start` di host saat container aktif; file runtime akan dimiliki root.
- **`Could not resolve host` saat clone** — resolv.conf host rusak; container memakai `dns:` eksplisit (atur `DNS_1`/`DNS_2`).
- **Port "sudah terpakai"** — dashboard hanya mendeteksi port milik site-nya sendiri; port yang dipakai container luar bisa diedit di halaman konfirmasi.
- **Subdomain tidak kebuka** — pastikan DNS resolve (hosts/dnsmasq) dan `sudo systemctl reload nginx`.

## Roadmap (Iterasi Berikutnya)

- Watcher host (`systemd` + `inotifywait`) untuk reload Nginx otomatis — sementara digantikan reload dari dashboard (lihat "Reload Nginx host")
- SSL multi-domain dalam satu sertifikat (SAN)
- Ekstrak `DeployerInterface` menjadi agent HTTP terpisah (multi-server)
- Role & permission antar user
- Log viewer real-time per container
- Migrasi JSON → SQLite/RDBMS bila skala bertambah
