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

## Instalasi & Menjalankan

```bash
# 1. Siapkan environment
cp .env.example .env
#    Atur APP_DOMAIN, APP_PORT, SESSION_SECRET, dst.

# 2. Build & jalankan container (dashboard berjalan sebagai root di dalam container)
docker compose up -d --build

# 3. Provisioning user admin pertama (cukup sekali)
docker exec -it rames-webman php webman make:admin
#    username default: admin — password di-generate & dicetak

# 4. Buka dashboard
#    http://localhost:{APP_PORT}     (default 8000; contoh .env: 8123)
```

### Setelah Create/Delete Site

Watcher reload otomatis (SPECS §8.3) belum dipasang, jadi setelah membuat/menghapus site jalankan sekali:

```bash
sudo systemctl reload nginx
```

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
| `NGINX_RELOAD_STATUS_FILE` | `{proyek}/nginx-status/last-reload.json` | Status reload yang ditulis watcher host |
| `DOCKER_SOCKET` | `/var/run/docker.sock` | Socket Docker Engine |
| `DEPLOY_TIMEOUT` | `600` | Timeout operasi docker compose (detik) |
| `DNS_1` / `DNS_2` | `8.8.8.8` / `1.1.1.1` | DNS untuk container (diperlukan jika resolv.conf host bermasalah) |
| `ADMIN_EMAIL` | — | Cadangan untuk SSL (iterasi berikutnya) |

## Struktur Direktori (Ringkas)

```
app/
├── command/MakeAdmin.php        # php webman make:admin (provisioning user awal)
├── controller/                  # AuthController, SiteController, UserController
├── library/                     # SELURUH logika bisnis (controller hanya mediator)
│   ├── Auth/UserStore.php
│   ├── Deploy/                  # DeployerInterface, LocalDeployer, DeployerFactory
│   ├── Docker/                  # ComposeParser, DockerClient, DockerComposeRunner, PortManager
│   ├── Git/GitService.php
│   ├── Nginx/                   # NginxConfigGenerator, NginxStatusReader
│   ├── Storage/                 # JsonStore (flock), SiteStore
│   └── Support/ProcessRunner.php
├── middleware/                  # AuthMiddleware, CsrfMiddleware
└── view/                        # template Raw Webman (.html)
cli/deploy.php                   # background worker deploy/rebuild
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
6. Reload Nginx host (manual / watcher).

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

- SSL otomatis per subdomain (Let's Encrypt) — struktur `needs_ssl`/`ssl_status` sudah disiapkan
- Watcher host (`systemd` + `inotifywait`) untuk reload Nginx otomatis
- Ekstrak `DeployerInterface` menjadi agent HTTP terpisah (multi-server)
- Role & permission antar user
- Log viewer real-time per container
- Migrasi JSON → SQLite/RDBMS bila skala bertambah
