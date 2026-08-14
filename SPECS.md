# SPECS.md — Rames (Deploy Dashboard)

## 1. Overview

Dashboard manajemen deployment sederhana (mirip cPanel) untuk mengelola:
- Site (project) yang di-deploy dari repo Git yang sudah berisi `docker-compose.yml`
- Container yang dihasilkan oleh tiap site
- Reverse proxy Nginx yang mengarahkan subdomain ke container yang sesuai

**Fase saat ini (Phase 1):** dashboard dan "agent" (logic eksekusi Docker/Nginx) dibangun dan dijalankan di **satu server yang sama**, dibungkus dengan Docker Compose. Arsitektur tetap disiapkan agar logic eksekusi bisa diekstrak menjadi agent HTTP terpisah di fase berikutnya (multi-server) tanpa merombak business logic dashboard.

## 2. Goals (Phase 1)

- [ ] Login dashboard dengan kredensial sederhana (username/password) dari `database/auth.json`
- [ ] User bisa membuat site baru dengan menyuplai URL repo Git yang sudah punya `docker-compose.yml`
- [ ] Sistem clone repo, build & jalankan `docker compose` untuk site tersebut
- [ ] Sistem mendeteksi port yang dipakai di `docker-compose.yml`, mendeteksi konflik dengan site lain, dan memungkinkan user mengedit port host sebelum build
- [ ] Sistem mencatat daftar container yang dihasilkan tiap site, ditampilkan di halaman detail site
- [ ] Site otomatis bisa diakses lewat `namasite.namadomain.com`, dengan `namadomain.com` diatur lewat `.env`
- [ ] Sistem generate & reload config Nginx otomatis setiap ada site baru/berubah
- [ ] Multi user untuk login dashboard, tanpa konsep role/permission (semua user punya akses yang sama)
- [ ] SSL otomatis per subdomain menggunakan Let's Encrypt

## 3. Non-Goals (Phase 1)

- Multi-server / agent sebagai service HTTP terpisah
- Role & permission antar user — semua user yang login punya hak akses sama (lihat Goals untuk multi user tanpa role)
- Auto-scaling, health-check lanjutan, monitoring resource
- Rollback otomatis / versioning deployment
- Podman — Phase 1 tetap pakai Docker Engine yang sudah familiar; migrasi ke rootless Podman jadi pertimbangan keamanan di fase lanjutan

## 4. Tech Stack

| Komponen | Pilihan | Catatan |
|---|---|---|
| Backend/dashboard | Webman (PHP) | Sesuai stack yang sudah dikuasai user |
| Container runtime | Docker + Docker Compose | Dieksekusi lewat shell (`proc_open`/`exec`), bukan Docker API SDK, untuk kesederhanaan Phase 1 |
| Reverse proxy | Nginx (jalan sebagai container dalam compose stack yang sama) | Config di-generate ke direktori yang di-mount, reload via `docker exec nginx nginx -s reload` |
| Storage data | File JSON (`database/auth.json`, `database/sites.json`) | Bukan RDBMS dulu — cukup untuk skala Phase 1 |
| Parsing YAML | Library YAML parser PHP (mis. `symfony/yaml`) | Untuk membaca & menulis ulang `docker-compose.yml` |

## 5. Arsitektur Phase 1

Nginx **tidak** dijalankan sebagai container — sistem memakai instalasi Nginx yang sudah ada langsung di host server (sesuai kebiasaan setup manual user sebelumnya). Dashboard hanya menulis file config ke direktori Nginx di host dan memicu reload; Nginx sendiri tetap dikelola sebagai service level-sistem (systemd), bukan bagian dari compose stack.

```
┌───────────────────────────────────────────────────────────┐
│                        Host Server                          │
│                                                             │
│   ┌────────────────────┐        ┌─────────────────────┐   │
│   │  Nginx (native,     │◀───────│  DNS *.example.com    │   │
│   │  systemd service)   │        └─────────────────────┘   │
│   │  :80 / :443         │                                  │
│   └─────────┬───────────┘                                  │
│             │ proxy_pass ke host_port tiap site             │
│             ▼                                               │
│   ┌────────────────────┐   ┌─────────────────────┐          │
│   │  Site A containers   │   │  Site B containers   │  ...   │
│   │  (docker compose)    │   │  (docker compose)     │        │
│   └────────────────────┘   └─────────────────────┘          │
│             ▲                        ▲                       │
│             └───────────┬────────────┘                       │
│                          │ docker.sock                        │
│                 ┌─────────────────────┐                       │
│                 │  Dashboard (Webman)   │                     │
│                 │  container            │                     │
│                 │  - Auth                │                     │
│                 │  - Site CRUD           │                     │
│                 │  - Deployer (internal  │                     │
│                 │    module, akan        │                     │
│                 │    diekstrak jadi      │                     │
│                 │    agent nanti)        │                     │
│                 └───────────┬─────────┘                       │
│                              │ tulis file config                │
│                              ▼                                 │
│                 /etc/nginx/sites-available/{name}.conf         │
│                 (mounted volume ke dashboard container)         │
└───────────────────────────────────────────────────────────┘
```

**Reload Nginx tanpa exec langsung ke host:** karena dashboard jalan di dalam container sedangkan Nginx native di host, dashboard **tidak** langsung menjalankan perintah `nginx -s reload` di host (itu butuh akses shell ke luar container). Pendekatan yang dipakai:

- Direktori config Nginx di host (mis. `/etc/nginx/sites-available/`) di-mount sebagai **volume** ke dashboard container — dashboard cukup punya izin **tulis file** di situ, bukan izin eksekusi command host
- Di host, jalankan **watcher script** kecil (systemd service, pakai `inotifywait`) yang memantau perubahan di direktori tsb, lalu otomatis jalankan `nginx -t && nginx -s reload` begitu ada file baru/berubah
- Dashboard tidak pernah butuh sudo/SSH ke host — cukup tulis file, watcher yang urus sisanya

Ini menghindari kebutuhan expose SSH atau sudo dari dalam container ke host, sekaligus tetap memakai Nginx yang sudah ada di server.

**Catatan penting soal `docker.sock`:** Phase 1 tetap membutuhkan dashboard container mount `/var/run/docker.sock` untuk mengeksekusi `docker compose` bagi tiap site. Ini adalah *known risk* yang disengaja diterima untuk fase awal (single admin, tidak ada input publik ke dashboard). Validasi input tetap wajib diterapkan ketat (lihat bagian Security). Isolasi lebih baik (agent terpisah, rootless Podman) direncanakan untuk fase berikutnya, bukan Phase 1.

Struktur kode disiapkan dengan interface `DeployerInterface` (clone, build, up, get containers, generate nginx config) supaya implementasi bisa diganti dari "eksekusi lokal" menjadi "panggil agent HTTP" tanpa mengubah controller/business logic dashboard.

## 6. Autentikasi

### 6.1 Sumber data
File: `database/auth.json`

```json
[
  {
    "id": "u1",
    "username": "admin",
    "password_hash": "$2y$10$..."
  },
  {
    "id": "u2",
    "username": "budi",
    "password_hash": "$2y$10$..."
  }
]
```

- Password disimpan sebagai hash (`password_hash()` PHP, bcrypt), **bukan plaintext**.
- Login membandingkan input dengan `password_verify()` terhadap entry yang `username`-nya cocok.
- **Multi user, tanpa role/permission**: semua user yang terdaftar di `auth.json` punya akses penuh yang sama ke seluruh fitur dashboard (create/delete site apa pun, lihat semua data) — tidak ada konsep admin vs. member atau pembatasan per-site.
- Dashboard menyediakan halaman sederhana "Manage Users" (tambah/hapus user, ganti password) yang bisa diakses oleh user mana pun yang sudah login — karena tidak ada role, semua user setara termasuk kemampuan menambah/menghapus user lain.

### 6.2 Alur
1. User akses `/login`, isi username & password
2. Sistem baca `auth.json`, cari entry dengan `username` yang cocok, verifikasi password
3. Jika valid, buat session (Webman session bawaan) yang menyimpan `id`/`username` user tsb
4. Semua route dashboard selain `/login` dilindungi middleware auth
5. Logout menghapus session

### 6.3 Provisioning awal
Karena belum ada installer resmi di Phase 1, entry pertama di `auth.json` dibuat manual lewat script/console command (mis. `php webman make:admin`) yang generate username/password default dan menuliskannya ke file — dijalankan sekali saat setup. User tambahan berikutnya dibuat lewat halaman "Manage Users" di dashboard.

## 7. Site Management

### 7.1 Sumber data
File: `database/sites.json` — array of site object.

```json
[
  {
    "id": "b3f1c2a4-...",
    "name": "myapp",
    "subdomain": "myapp.example.com",
    "repo_url": "https://github.com/user/myapp.git",
    "branch": "main",
    "local_path": "sites/myapp",
    "primary_service": "web",
    "status": "running",
    "auth_method": "none",       // none (publik) | ssh (deploy key per repo)
    "ssh_key": null,              // path relatif private key (mis. "keys/myapp") utk repo private
    "containers": [
      {
        "service_name": "web",
        "container_name": "myapp_web_1",
        "image": "myapp-web:latest",
        "internal_port": 8080,
        "host_port": 30001,
        "status": "running"
      },
      {
        "service_name": "worker",
        "container_name": "myapp_worker_1",
        "image": "myapp-worker:latest",
        "internal_port": null,
        "host_port": null,
        "status": "running"
      }
    ],
    "created_at": "2026-08-13T10:00:00+07:00",
    "updated_at": "2026-08-13T10:05:00+07:00"
  }
]
```

Field penting:
- `name` — slug unik, dipakai sebagai subdomain dan nama direktori lokal (`sites/{name}`)
- `primary_service` — nama service dalam `docker-compose.yml` yang menerima traffic dari subdomain (ditentukan user saat create, default: service pertama yang punya port exposed)
- `containers[].host_port` — port di host yang sudah final dipakai (setelah resolusi konflik), inilah yang dipakai Nginx sebagai target `proxy_pass`
- `auth_method` — metode akses repo: `none` (publik, anonim) atau `ssh` (deploy key per site)
- `ssh_key` — path relatif private key terhadap `database_path` (mis. `keys/myapp`), dipakai saat `git pull` Rebuild; hanya path yang disimpan, private key di file terpisah (`database/keys/`)

### 7.2 Alur "Create Site"

1. **Input form**: nama site (slug), URL repo Git, branch (default `main`)
2. **Validasi**: nama unik (cek `sites.json`), format slug valid (`a-z0-9-`), URL repo formatnya valid
3. **Clone repo** ke `sites/{name}` (`git clone --branch {branch} {repo_url} sites/{name}`). Untuk repo private, sistem **membangkitkan deploy key SSH per site** (keypair ed25519 di `database/keys/{name}`), menampilkan public key agar user menambahkannya sebagai Deploy Key repo (Settings → Deploy keys), lalu clone memakai `GIT_SSH_COMMAND` (`ssh -i {key} -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new`)
4. **Cek keberadaan** `docker-compose.yml` (atau `.yaml`) di root repo — jika tidak ada, tolak dan tampilkan error
5. **Parse** `docker-compose.yml`, ekstrak semua service beserta `ports:` mapping (`HOST:CONTAINER`)
6. **Deteksi konflik port**: bandingkan setiap host port dengan seluruh `host_port` yang sudah terpakai di `sites.json`
   - Jika konflik, sistem sarankan port alternatif dari range yang dikonfigurasi (`PORT_RANGE_START`–`PORT_RANGE_END` di `.env`)
7. **Tampilkan halaman konfirmasi** — user melihat daftar service & port yang terdeteksi, bisa mengedit host port manapun sebelum lanjut
8. **Tulis ulang port**: sistem menulis `docker-compose.override.yml` di direktori site (bukan mengubah `docker-compose.yml` asli) berisi override `ports:` sesuai hasil edit user — supaya file asli dari repo tetap bersih dan tidak konflik saat `git pull` update berikutnya
9. **Pilih primary service** — user pilih service mana yang akan menerima traffic subdomain (dropdown dari daftar service yang punya port exposed)
10. **Build & Up**: jalankan `docker compose -p {name} -f docker-compose.yml -f docker-compose.override.yml up -d --build`
11. **Kumpulkan info container**: jalankan `docker compose -p {name} ps --format json` untuk ambil nama container, status, image
12. **Generate config Nginx** untuk `{name}.{APP_DOMAIN}` yang proxy ke `127.0.0.1:{host_port primary_service}`
13. **Validasi config**: `docker exec nginx nginx -t` — jika gagal, rollback (site tetap dibuat tapi status `error`, tampilkan pesan error ke user)
14. **Reload Nginx**: `docker exec nginx nginx -s reload`
15. **Simpan** seluruh data site ke `sites.json` dengan `status: running`

### 7.3 Halaman Detail Site

Menampilkan:
- Info umum: nama, subdomain (dengan link langsung), repo URL, branch
- Daftar container: nama, image, status (running/stopped/exited), port mapping
- Aksi: Rebuild (pull ulang + up ulang), Stop, Start, Delete (hapus container + config nginx + file lokal)

### 7.4 Delete Site

1. `docker compose -p {name} down -v`
2. Hapus config Nginx terkait, reload Nginx
3. Hapus direktori `sites/{name}` (opsional — bisa juga di-retain sebagai backup dengan konfirmasi user)
4. Hapus entry dari `sites.json`

## 8. Reverse Proxy / Subdomain Routing

### 8.1 Domain dasar
Dikonfigurasi lewat `.env`:
```
APP_DOMAIN=example.com
```
Site bernama `myapp` otomatis dapat subdomain `myapp.example.com`. DNS wildcard (`*.example.com`) diasumsikan sudah diarahkan oleh user ke IP server ini — di luar tanggung jawab sistem (dicatat sebagai prasyarat, bukan fitur).

### 8.2 Template Nginx config
Disimpan sebagai template, di-render per site langsung ke direktori Nginx **di host** (dimount ke dashboard container), mis. `/etc/nginx/sites-available/{name}.conf`, lalu di-symlink otomatis ke `sites-enabled/` (atau ditulis langsung ke `sites-enabled/` kalau setup host tidak memisahkan keduanya):

```nginx
server {
    listen 80;
    server_name {{name}}.{{APP_DOMAIN}};

    location / {
        proxy_pass http://127.0.0.1:{{host_port}};
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 8.3 Mekanisme reload

1. Dashboard menulis/menghapus file `.conf` di direktori yang di-mount dari host
2. Watcher service di host (systemd unit, mis. `dashboard-nginx-watcher.service`) mendeteksi perubahan lewat `inotifywait`, lalu:
   - Jalankan `nginx -t` — kalau gagal, log error dan **jangan** reload (config lama tetap aktif)
   - Kalau valid, jalankan `nginx -s reload` (zero-downtime, bukan restart)
3. Dashboard bisa polling status terakhir watcher (mis. baca file log/status sederhana) untuk menampilkan ke user apakah reload sukses atau gagal — berguna untuk feedback di UI setelah create/delete site

Pendekatan ini sengaja menghindari dashboard container butuh akses eksekusi command langsung di host (tidak perlu SSH/sudo dari container), cukup akses tulis file di volume yang di-mount.

## 8a. SSL Otomatis (Let's Encrypt)

Karena Nginx dikelola native di host (bukan container) dan menggunakan watcher untuk reload (lihat 8.3), penerbitan sertifikat juga dilakukan di sisi host, dipicu oleh dashboard lewat mekanisme yang sama (tulis file/trigger), bukan dashboard container yang langsung menjalankan `certbot`.

### Alur penerbitan sertifikat saat create/update site
1. Setelah config Nginx untuk `{name}.{APP_DOMAIN}` berhasil ditulis, divalidasi, dan direload (HTTP dulu, port 80) — subdomain harus sudah bisa diakses via HTTP sebelum certbot bisa validasi domain ownership (challenge butuh port 80 terbuka & mengarah ke server ini)
2. Dashboard menandai site dengan status `ssl_pending` dan menulis request ke **antrian sederhana** (mis. file `database/ssl-queue.json` atau baris ditambah dgn flag `needs_ssl: true` pada entry site)
3. Watcher/cron di host (service terpisah, mis. `dashboard-ssl-issuer.service` atau cron interval singkat) membaca antrian ini, lalu jalankan:
   ```
   certbot certonly --nginx -d {name}.{APP_DOMAIN} --non-interactive --agree-tos -m {admin_email}
   ```
4. Certbot juga menulis konfigurasi `listen 443 ssl` ke file config site tsb (certbot plugin nginx otomatis modifikasi block server) — dashboard **tidak** menimpa bagian SSL ini saat regenerate config, cukup bagian `proxy_pass`/upstream yang dikelola dashboard
5. Setelah sukses, watcher update status site di `sites.json` jadi `ssl_active` beserta `ssl_expires_at`; dashboard menampilkan status ini di halaman detail site
6. **Renewal**: dijadwalkan lewat cron/systemd timer standar bawaan certbot (`certbot renew`, biasanya jalan 2x sehari, cukup renew kalau mendekati expired) — di luar tanggung jawab dashboard, cukup pastikan saat instalasi certbot timer ini aktif

### Kegagalan penerbitan
- Kalau certbot gagal (mis. DNS belum propagate, port 80 diblokir firewall), site tetap `running` dengan status `ssl_failed` — site tetap bisa diakses via HTTP, tidak memblokir fungsi utama
- Dashboard tampilkan pesan error terakhir dan tombol "Retry SSL" yang menambahkan kembali ke antrian

### Prasyarat
- DNS wildcard/record subdomain harus sudah aktif mengarah ke IP server sebelum request SSL dicoba (sama seperti prasyarat routing biasa)
- Port 80 dan 443 harus terbuka di firewall host
- `certbot` dan plugin `python3-certbot-nginx` terinstall di host (bagian dari setup awal server, dicatat sebagai prasyarat instalasi, bukan diinstall otomatis oleh dashboard di Phase 1)

## 9. Environment Variables (`.env`)

```
APP_DOMAIN=example.com
APP_PORT=8000
SESSION_SECRET=change-me
PORT_RANGE_START=30000
PORT_RANGE_END=30999
SITES_PATH=/app/sites
NGINX_CONF_PATH=/etc/nginx/sites-available   # dimount dari host ke dashboard container
NGINX_RELOAD_STATUS_FILE=/app/nginx-status/last-reload.json  # ditulis watcher, dibaca dashboard
```

## 10. Struktur Direktori (usulan)

```
/dashboard
├── app/                      # source Webman
├── database/
│   ├── auth.json
│   ├── sites.json
│   └── keys/                 # deploy key SSH per site (private 0600) + known_hosts
├── sites/                    # hasil clone repo tiap site (gitignored)
│   └── {name}/
│       ├── docker-compose.yml           # asli dari repo user
│       └── docker-compose.override.yml  # hasil edit port oleh sistem
├── .env
├── docker-compose.yml        # compose untuk stack dashboard saja (nginx tidak ikut di-compose)
└── SPECS.md

# Di host (di luar direktori dashboard, dikelola terpisah):
/etc/nginx/sites-available/{name}.conf   # digenerate dashboard, dimount sbg volume
/etc/nginx/sites-enabled/{name}.conf     # symlink, dibuat watcher atau dashboard
/etc/systemd/system/dashboard-nginx-watcher.service   # watcher inotify + reload
```

## 11. Security Considerations (Phase 1)

- Password admin di-hash (bcrypt), tidak pernah disimpan/di-log plaintext
- Semua input user (nama site, repo URL, branch, port) disanitasi sebelum dipakai dalam perintah shell — **hindari command injection** (gunakan `escapeshellarg()`, jangan concatenate string mentah ke `exec()`)
- Validasi format port (integer, dalam range yang wajar) sebelum ditulis ke `docker-compose.override.yml`
- File JSON (`sites.json`, `auth.json`) ditulis dengan file locking (`flock`) untuk menghindari race condition saat ada dua request bersamaan
- Dashboard container yang mount `docker.sock` adalah titik sensitif — akses ke dashboard **harus** selalu di balik autentikasi, tidak boleh ada endpoint yang expose eksekusi shell tanpa lolos middleware auth
- Backup `docker-compose.yml`/`sites.json` sebelum overwrite, agar ada jejak jika perlu rollback manual
- Direktori Nginx host yang di-mount ke dashboard container dibatasi sesempit mungkin (hanya `sites-available/`, bukan seluruh `/etc/nginx`), agar dashboard tidak bisa menimpa `nginx.conf` utama atau config site lain di luar mekanisme yang disediakan
- Watcher service di host dijalankan dengan user yang punya izin reload Nginx (lewat `sudoers` khusus untuk `nginx -s reload` saja) — bukan root penuh, dan tidak menerima input dari dashboard secara langsung (dashboard cuma menulis file, bukan mengirim perintah)
- Deploy key SSH per repo disimpan privat (chmod 0600, gitignored); hanya public key yang ditampilkan ke user. `git` memakai `GIT_SSH_COMMAND` dengan `IdentitiesOnly=yes` & `StrictHostKeyChecking=accept-new` (host key tersimpan di file `known_hosts` sistem)

## 12. Future Work (di luar Phase 1)

- Ekstrak `DeployerInterface` implementation menjadi agent HTTP terpisah untuk dukungan multi-server
- Role & permission antar user (mis. admin vs. member dengan akses site terbatas)
- Migrasi dari JSON file ke SQLite/RDBMS jika jumlah site/user bertambah signifikan
- Rootless Podman sebagai pengganti Docker socket untuk mengurangi risiko root-escape
- Log viewer real-time per container
- Installer otomatis (`install.sh`, termasuk instalasi certbot) setelah fitur inti stabil