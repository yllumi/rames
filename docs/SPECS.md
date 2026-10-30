# SPECS.md — Rames (Deploy Dashboard)

> **Status & cara membaca.** Dokumen ini adalah **sumber kebenaran kebutuhan produk** — apa yang harus benar, batas keamanan, dan keputusan yang mengikat — bukan catatan riwayat implementasi. `WAJIB`/`DILARANG`/`terbukti` bersifat normatif; **belum ada** berarti rencana yang belum boleh diklaim sebagai perilaku.
>
> Detail internal (kelas, signature, urutan langkah, jebakan) ada di `./ARCHITECTURE.md` dan `./ARCHITECTURE/**`. Daftar env lengkap: `../DETAILS.md#konfigurasi-env`; struktur direktori ringkas: `../DETAILS.md#struktur-direktori-ringkas`. Pemasangan: `../README.md` (dashboard) dan `../agent/README.md` (agent). Nomor section (`§6.1`, `§7.2b`, `§8j`, …) **dipertahankan** karena dirujuk kode, uji, dan dokumen lain; section yang tidak dirujuk boleh diringkas, nomornya tidak berubah.

## 1. Overview

Rames adalah dashboard deployment multi-server (mirip cPanel) untuk app berbasis Docker Compose. Ada dua peran runtime dari **kode & image yang sama**: **control plane** (`RAMES_MODE=dashboard`: UI, login, registry server, timer, billing) dan **agent** (`RAMES_MODE=agent`: eksekutor Docker/Nginx di server tempat app berjalan, tanpa UI). Server tempat dashboard berjalan adalah server **`local`** bawaan; app lama otomatis menjadi miliknya, sehingga instalasi single-server berperilaku seperti sebelumnya.

Setiap app memiliki container, port host, vhost Nginx, domain/subdomain, status SSL, environment, batas resource, dan data backup. Operasi panjang (deploy/rebuild/rollback/backup) berjalan **asinkron** karena batas timeout HTTP 30 detik.

## 2. Goals (Phase 1)

Baseline produk yang sudah menjadi kontrak (bukan daftar status):

- Login multi-user dengan role global `admin`/`member`; app dimiliki user dan dapat dibagikan.
- Create app dari **tiga sumber**: clone repo Git, paste/upload compose, atau template galeri.
- Deteksi port compose, resolusi konflik dalam rentang port server, dan pemilihan port yang di-proxy ke domain.
- Inventaris container, rebuild/deploy ulang, stop/start, delete dua mode, rollback Git, dan deploy key untuk repo privat.
- Subdomain otomatis, custom domain, rute proxy terstruktur, dan config Nginx yang diregenerasi otomatis.
- SSL Let's Encrypt per domain dengan renewal otomatis di host.
- Environment terkelola (import `.env.example`, upload `.env`), batas CPU/RAM per service, file manager, terminal, log, dan Adminer.
- Monitoring ringkas per server, self-update dashboard, branding, dan tema terang/gelap.
- Backup volume app ke S3 (restic), backup basis data dashboard, dan billing kredit untuk member.
- Multi-server: registry, job async, pemilihan server, scope data per server.

Sisa pekerjaan aktif **hanya** di §12; section lain adalah aturan yang berlaku sekarang.

## 2a. Goals Multi-Server

- **Satu kode/image, dua peran runtime** (`dashboard|agent`); bukan fork, perbaikan bug berlaku di keduanya.
- **Satu domain dasar per server** (`servers.domain`): app memakai `{label}.{domain server}`; app `local` memakai `APP_DOMAIN` bila `servers.domain` kosong.
- **Registry server admin-only**; **pemilihan server** dapat diberikan per user (`server_select` + allowlist `server_ids`).
- **Job async**: deploy/rebuild/rollback/backup/op tingkat server berjalan sebagai job dengan `job_id` + log offset; control plane polling. Create app menyiapkan record lebih dulu, lalu deploy-nya menjadi job.
- **Preflight sebelum efek samping**: ketersediaan direktori app di agent diperiksa (`POST /apps/check`) sebagai saran; penegakan tetap di jalur job.
- **Keunikan per server** (bukan global): nama app, host port, nama container, project compose, subdomain, custom domain.
- **TLS self-signed + pinning**: sertifikat agent menjadi trust anchor; fingerprint tidak cocok ⇒ gagal tegas, bukan `verify=false`.
- **Kepemilikan data disjoint**: metadata milik control plane; state eksekusi & job milik agent; billing/backup registry milik control plane (§7.14).
- **Op tingkat server**: tarik image template, self-update agen, restore arsip volume dijalankan di server yang benar.
- **Kompatibilitas single-server**: tanpa agent, seluruh perilaku lama tetap berlaku.

## 3. Non-Goals (Phase 1)

Di luar cakupan saat ini (beberapa menjadi target §12): permission granular per-resource (model saat ini 4 role tetap, §7.7); auto-scaling/health-check cerdas, monitoring historis/alerting, dan log viewer SSE + buffer historis; refund/void top-up, rate limiting khusus top-up, rekonsiliasi billing berbasis riwayat Docker (`StartedAt`); snippet Nginx mentah (hanya rute terstruktur, §8.2a); Podman/rootless; cluster/HA control plane atau Kubernetes.

## 4. Tech Stack

| Komponen | Pilihan | Catatan |
|---|---|---|
| Backend | Webman (PHP 8.1+) | Worker persistent, `controller_reuse=false`; tanpa state properti controller |
| Container | Docker Engine + Compose v2 | Mutasi via CLI `docker compose`; baca state via Docker Engine API (Guzzle) |
| Reverse proxy | **Nginx native di host** | Bukan container; dashboard menulis config ke direktori ter-mount, host me-reload |
| Data | SQLite `rames.sqlite` di named volume `rames` | Dokumen JSON di kolom `data` + kolom turunan terindeks; `BEGIN IMMEDIATE` |
| Cache runtime | Berkas JSON `runtime/**` | Bukan sumber kebenaran; boleh dibangun ulang |
| YAML | `symfony/yaml` | Membaca/menulis compose & override |
| Test | PHPUnit 10 (`failOnWarning`, `failOnRisky`) | Warning menggagalkan build |

**Konvensi teknis & kualitas (WAJIB).** Semua file PHP memakai `declare(strict_types=1)`. View memakai template native dengan escape output (`e()`) dan token CSRF pada setiap form mutasi. **DILARANG** menambah framework frontend/CDN baru; aset produk dibundel lokal. Test proporsional dengan cakupan (Tingkat Verifikasi V1/V2/V3 — lihat `.github/copilot-instructions.md`): `composer test` penuh (PHPUnit 10, warning = gagal) wajib untuk perubahan logika/otorisasi/kontrak; perubahan **tampilan murni** cukup `php -l`, `node --check`, smoke render view, dan test view terkait. Smoke render view wajib untuk template yang berubah, dan uji Compose pada direktori temp dengan `docker compose … config --quiet` (jangan mencetak secret). Setelah mengubah kode/proses, reload worker (`php start.php reload`) agar kelas baru termuat pada worker persistent.

## 5. Arsitektur

Nginx **tidak** berjalan sebagai container. Dashboard container menulis file config ke direktori host yang di-mount; watcher host melakukan `nginx -t` lalu reload. Dashboard tidak butuh SSH/sudo ke host. Mount `/var/run/docker.sock` adalah risiko yang disengaja untuk instalasi single-admin dan alasan utama isolasi lewat agent.

### 5.1 Dua peran runtime

- Mode `agent` menggerbangi route/middleware/proses: route dashboard tidak didaftarkan, hanya route mesin dengan `AgentAuthMiddleware`. Tanpa `AGENT_TOKEN`, route agent tidak didaftarkan sama sekali (fail-closed ⇒ semua 404).
- Seluruh route agent wajib token, termasuk `GET /healthz` dan `GET /version`; tidak ada route publik di agent.
- Agent memakai library yang sama (`LocalDeployer`, `EnvManager`, `NginxConfigGenerator`, `cli/deploy.php`) sehingga tidak ada duplikasi logika deploy.
- Deployer ditentukan dokumen server pemilik app; `server_id` tak dikenal **melempar**, tidak pernah fallback ke server lokal.

### 5.2 Job async & materialisasi artefak

- `POST /jobs` membalas `job_id` + `log_offset`; worker detached menjalankan `cli/deploy.php`; control plane polling status/log.
- Artefak job (override port/compose/file pendukung) dirakit control plane dari metadata lalu diproyeksikan. `compose_content` dan `host_ports` adalah metadata **khusus control plane** (tidak diproyeksikan); agent menulis artefaknya sendiri dari payload.
- Metadata **push** (control plane → agent); state eksekusi **pull** (agent → control plane). Agent hilang/dipasang ulang ⇒ proyeksi dibangun ulang pada job berikutnya.
- Basis data agent adalah data turunan (tidak ikut backup DB dashboard); yang wajib bertahan di server app adalah volume app (§8h).

### 5.3 Titik gerbang kredit

Gerbang kredit (`BillingGate`) adalah **lapisan biaya**, bukan otorisasi. Setiap aksi yang membuat, menyalakan, atau menciptakan ulang container wajib melewatinya **setelah** otorisasi dan **sebelum** efek samping: create, start, deploy/rebuild, rollback, apply env/compose/network/names/limits, dan menyalakan backup berkala. Urutan: `AppAccess` (404 bila tidak berhak) → `BillingGate` (402/redirect bila saldo kurang) → efek samping. Aktor `admin` dan app milik `admin` **exempt**; yang dinilai adalah aktor `member` lalu penanggung biaya/owner bila berbeda.

## 6. Autentikasi

### 6.1 Sumber data

Store `auth` di SQLite (`auth_users`; dahulu `database/auth.json`), satu baris per user: `id`, `username`, `password_hash` (bcrypt `password_hash()`, bukan plaintext), `role`, `email` opsional, grant server.

- **Role global**: `admin` melihat semua app + mengelola user; `member` hanya app miliknya dan yang dibagikan. Dokumen lama tanpa `role`: user pertama admin, sisanya member (tanpa migrasi tulis).
- **Satu pintu otorisasi app**: `AppAccess` (`roleFor`, `can`, `require`, `visible`); tidak ada pengecekan `owner_id` yang ditulis ulang.
- **Role app**: `owner > operator > viewer`; admin global menang. **Ability** (minimum role): `view`, `logs` = viewer; `operate`, `deploy`, `stop`, `env`, `compose`, `network`, `domain`, `routes`, `ssl`, `terminal`, `files`, `database`, `backup` = operator; `restore`, `delete`, `sharing` = owner; `limits` = **admin-only**.
- **Akses tidak sah = 404**, bukan 403. UI boleh menyembunyikan tombol; server tetap menolak.
- **Grant pemilihan server** (`server_select` + `server_ids`) disetel admin di `/users` lewat `POST /users/{id}/servers` (atomik). Grant hanya menambah pemilihan/visibilitas server — **bukan** ability app/backup, **bukan** metrik host server tanpa app terlihat. `server_ids` kosong = semua; `local` selalu boleh dipilih. Allowlist yang seluruhnya id tak dikenal ⇒ perubahan **ditolak** (tidak boleh melebar); id asing dicampur id valid ⇒ id asing dibuang; registry tak terbaca ⇒ fail-closed. Kelola registry tetap admin-only.
- **Self-service**: ganti password sendiri (minimal 6 karakter, verifikasi password lama) dan ubah email sendiri (prasyarat top-up Duitku). Target selalu user sesi; `id`/`user_id` request diabaikan (anti-IDOR).
- `/users` admin-only: tambah/hapus user, ubah role, atur grant. Menghapus user mengalihkan app miliknya ke admin penghapus; admin terakhir tidak bisa dihapus/diturunkan.

### 6.2 Alur

1. `GET /login` → kirim username/password.
2. Verifikasi bcrypt terhadap store `auth`; gagal ⇒ pesan generik.
3. Sukses ⇒ session Webman; middleware auth menyinkronkan role terbaru dari store pada request berikutnya.
4. Semua route dashboard selain path publik eksak (`/login`, `/healthz`, `/branding/logo`, callback pembayaran) dilindungi auth.
5. Logout menghapus session.

### 6.3 Provisioning awal

- Entry pertama dibuat `php webman make:admin [username] [password]` (password kosong dibuat acak & dicetak sekali); user pertama otomatis admin.
- Jalur host memakai `host/create-admin.sh`, yang **menunggu readiness** `GET /healthz` dari dalam container sebelum `make:admin` (bootstrap compose bisa `running` sebelum vendor/skema siap).
- `php webman app:assign-owner` menugaskan app lama tanpa owner ke admin pertama (idempoten).

## 7. App Management

### 7.1 Sumber data

Store `apps` di SQLite (`apps_apps`; dahulu `database/apps.json`). Satu baris = satu app; dokumen JSON di kolom `data`, kolom turunan terindeks (`name`, `owner_id`, `status`, `source`, `subdomain`), urutan di `ord`.

Field yang mengikat: `name` (slug; nama project compose, direktori `apps/{name}`, subdomain default; unik **per server**); `server_id` (kosong = `local`; menentukan lokasi eksekusi); `source` (`git|compose`; menentukan rebuild & rollback); `owner_id` + `members` (`viewer|operator|owner`); `subdomain` (**label** maks 63, bukan FQDN; kosong = nama app; nilai lama FQDN penuh dihormati); `repo_url`, `branch`, `local_path`, `auth_method` (`none|ssh`), `ssh_key`; `primary_service`/`primary_port` (port **container** yang di-proxy); `container_prefix`; `status`/`stage`/`message`/`error`/`containers[]` (termasuk `host_port`, `ports[]` tanpa duplikat dual-stack); `limits` (`service => {cpus, memory_mb}`; `null` = nilai compose repo); `env`, `compose_files`; `nginx_routes` (maks 20 `{path,target}`); `host_ports` (rencana port, tidak diproyeksikan); `compose_content` (master compose app agent, tidak diproyeksikan); `custom_domain` + `custom_ssl_*`; `ssl_*` subdomain; `deploy_history`; `template` (penanda asal-usul, bukan re-sync).

**App tanpa port terpublish** (mis. server database) tidak dibuatkan vhost/subdomain; `primary` sengaja kosong, bukan template rusak.

### 7.2 Alur "Create App"

Tiga mode di `/apps/create`, berurutan **Template**, **Clone repo Git** (default), **Compose (paste/upload)**. Template memakai `templates/<slug>/`; Git meng-clone repo ber-`docker-compose.yml`; Compose menerima file prebuilt tanpa repo.

**Pemilih server tujuan** ada di dalam setiap form masuk wizard; hanya dirender bila user berhak (`ServerAccess::canSelectServer()`) **dan** ada >1 pilihan. Langkah konfirmasi meneruskan `server_id` lewat hidden input dan memvalidasi ulang fail-fast; `?server_id=` hanya preselect. User tanpa grant hanya melihat catatan read-only server lokal. Non-grant yang mengirim `server_id` agent ditolak sebelum id dicari — **tidak pernah** fallback ke server lokal.

**Batas resource saat create**: semua user login boleh mengisi slider CPU/RAM; member dibatasi `BILLING_MAX_CPUS`/`BILLING_MAX_MEMORY_MB`, admin memakai kapasitas host. Server tetap sumber kebenaran validasi.

**Alur Git/Compose**: validasi + otorisasi + gerbang kredit + preflight server → clone/materialisasi `apps/{name}` → parse compose → resolusi host port dalam rentang server & deteksi konflik per server → tentukan primary (kosong bila tanpa `ports:`) → cek keunikan nama/container/project/domain **di server itu** → tulis override port/nama/limits/env → simpan app → spawn worker deploy (async) → UI memantau `deploying → build → collect → nginx → running`. Kegagalan sebelum record dibuat membersihkan artefak (tanpa state setengah jadi).

### 7.2a Mode "Compose (paste / upload)"

- File compose + pendukung ditulis ke `apps/{name}` **tanpa repo Git**; `source: compose`, `repo_url`/`branch`/`auth_method` = `null`. `build:` ditolak.
- Tab Compose (operator+) menyimpan = validasi → regenerasi override port (port lama dipertahankan bila bebas, konflik digeser) → worker `apply`.
- **Rebuild/Deploy Ulang** = `docker compose up -d` **tanpa** `--build`; tidak ada `git pull`.
- **Rollback tidak tersedia** (tanpa checkpoint Git); `deploy_history` tetap dicatat dengan `sha` kosong.
- **Bind mount otomatis**: direktori source dibuat di dalam direktori app, kecuali `bind: {create_host_path: false}` (dihormati ⇒ deploy gagal bila path salah). Source berupa **file**, path di luar direktori app, dan `create_host_path:false` hanya dilaporkan + petunjuk pada error. `${PWD}` = direktori app; `${VAR}` dari managed env.

### 7.2b Mode "Template" (galeri app siap-pakai)

Template adalah aset repo (dikelola lewat git, bukan UI): `templates/<slug>/` berisi `template.yml`, `docker-compose.yml`, opsional `files/` dan `guide.md`. `guide.md` adalah **metadata katalog** (dirender aman ke HTML, tidak disalin ke app; gagal baca non-fatal).

**Aturan template** (ditegakkan `TemplateCatalog`, ditolak sebelum deploy): service **wajib** `image:` dan **DILARANG** `build:`; **DILARANG** `container_name`, `name:` level atas, dan `deploy.replicas`/`scale` > 1; `ports:` opsional (tanpa port ⇒ app tanpa vhost/subdomain); setiap `${VAR}` tanpa default wajib dideklarasikan di `env[]` (variabel sistem seperti `${PWD}` dikecualikan); file pendukung wajib relatif & aman (segmen `[A-Za-z0-9._-]+`, tanpa `..`) dan tidak boleh memakai nama override dashboard; template rusak **tetap tampil** dengan status/pesan error dan tombol deploy nonaktif; template pihak ketiga tanpa `guide.md` tetap dapat di-deploy.

**Nilai env** (`TemplateCatalog::resolveEnv()`): form → `default` → auto-generate (`generate: secret`, hex 48) → tolak bila `required`. Hasil ditulis ke managed env + override env + dokumen app.

**Alur deploy**: galeri → modal (Detail | Panduan) → form nama + env + batas resource + server → `POST /apps/create/template/{slug}` → materialisasi `apps/{name}` → jalur compose biasa (§7.2a). Hasilnya app compose normal (stop/start/deploy ulang/domain/SSL/env/network/terminal/log/database), **tanpa rollback**.

### 7.3 Halaman Detail App

Menampilkan identitas app, server, subdomain/link, sumber (repo/compose), daftar port (badge `di-proxy` pada `primary_port`), container + status, badge role, dan aksi sesuai ability.

- App tanpa port: baris subdomain “tidak dipakai”; tab Domain & SSL menjelaskan domain/SSL tidak berlaku.
- Tab **Compose** (operator+), **Environment**, **Network**, **Domain & SSL**, **Sumber Daya** (read-only non-admin), **Akses** (owner+), **Riwayat Deployment** + Rollback.
- **Log container** (viewer+): modal, jumlah baris, auto-refresh; polling berhenti saat modal ditutup. **Log deploy** (viewer+): tahap worker + output build live, on-demand.
- **Batalkan build** (operator+): menghentikan worker + grup prosesnya dan memulihkan status; untuk app agent, job agent dibatalkan lebih dulu.
- **File manager** (operator+, §7.9) hanya untuk container berjalan.

### 7.4 Delete App

Dua mode: **pertahankan volume** (default; `docker compose down` tanpa `-v`, volume terpilih dapat dihapus terpisah, volume dipakai ulang bila app dibuat lagi dengan nama sama) dan **hapus total** (`down -v`, semua named/anonymous volume).

Urutan: lepaskan container asing dari network project (tanpa menghentikan/menghapus) → down sesuai mode → hapus config Nginx + reload → hapus direktori app → hapus record. Kegagalan pelepasan network dicatat sebagai peringatan, tidak menggagalkan delete. `/volumes` (admin) boleh mem-purge volume yatim; volume app aktif ditolak.

### 7.5 Rollback App ke Versi Sebelumnya

Hanya app **git**: `deploy_history` menyimpan checkpoint deploy sukses; rollback memakai commit sukses sebelumnya lalu rebuild. App **compose** tidak punya rollback/versi (pakai tab Compose + Deploy Ulang). Rollback melewati gerbang kredit dan otorisasi `deploy`; di server agent dijalankan sebagai job di server pemilik app, tidak pernah di control plane.

### 7.6 Environment Variables per App

- `env` di dokumen app. `EnvManager` menulis `{database_path}/env/{name}.env` (di luar repo, 0600) dan `docker-compose.override.env.yml` (entri **terakhir** `compose_files`).
- Nilai di-inject **literal** ke **setiap** service; validasi kunci `^[A-Za-z_][A-Za-z0-9_]*$`, tanpa duplikat, nilai tanpa baris baru/NUL, batas panjang/jumlah; pesan error hanya menyebut nama kunci.
- **Import `.env.example`** hanya mengisi kunci yang belum ada; tidak auto-recreate; user meninjau lalu **Simpan & Terapkan**.
- **Upload `.env`**: satu file, `merge` (default) atau `replace`; file kosong ditolak; validasi ketat (UTF-8, batas ukuran, karakter kontrol, kunci duplikat); **tidak auto-recreate** dan tidak melewati gerbang kredit. Simpan biasa (`POST /apps/{id}/env`) melakukan recreate + gerbang kredit.
- **Server agent**: simpan & upload env **berjalan** untuk app yang berjalan di server agent — simpan menerbitkan job `apply_env` (container diciptakan ulang di server itu, tanpa build) dan **control plane tidak menulis artefak app agent** (tidak ada `apps/<name>`/`database/env/*` di host ini; nilai env hanya ada di metadata app + job ter-token). Upload `.env` di app agent bersifat **review-only**: variabel masuk metadata, penerapan menunggu **Simpan & Terapkan**. **Import `.env.example` hanya untuk app yang checkout-nya ada di host ini** — untuk app server agent tombolnya tidak ditampilkan dan permintaan langsung ditolak dengan pesan yang menyebut tindakan pengganti (unggah `.env` atau isi manual).
- App agent yang belum pernah ter-materialisasi di servernya ⇒ job `apply_env` gagal (status `error`, env tetap tersimpan, pemulihan lewat **Rebuild**); tidak ada perubahan env yang diterapkan diam-diam di host lain.
- Saat create, kartu Environment membaca `.env.example` atau referensi `${VAR}` compose; variabel wajib ditandai; isian ikut ter-inject. Hanya `.env.example` yang dibaca, tidak pernah `.env` asli repo.
- Viewer tidak melihat nilai; operator+ boleh. Env dibersihkan saat app dihapus.

### 7.6a Nama Container per App

`container_prefix` (`^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`, maks 20) mengubah nama default menjadi `{prefix}-{service}`, ditulis ke `docker-compose.override.names.yml`. Keunikan nama container diperiksa **per server** sebelum simpan. Simpan = tulis override → recreate tanpa build → gerbang kredit. Kosong = nama default compose (tanpa migrasi).

### 7.6b Batas Resource (CPU & Memori) per Service

- `limits` per service `{cpus, memory_mb}`; `null`/absen = tidak diatur dashboard. Ditulis ke `docker-compose.override.limits.yml`.
- **Mengubah limit app yang sudah ada = admin-only** (ability `limits`). Di form create semua user login boleh memilih; member dibatasi `BILLING_MAX_*`, admin memakai kapasitas host. Nilai tetap divalidasi server.
- Service tanpa entri limits memakai default billing (`BILLING_DEFAULT_CPUS`/`BILLING_DEFAULT_MEMORY_MB`) agar tidak ada “tanpa limit = gratis”.
- **Keluarga field wajib meniru base compose** (legacy `cpus`/`mem_limit` vs modern `deploy.resources.limits`); mencampur nilai berbeda ditolak. Gaya dideteksi ulang setiap `sync()`.
- Mengosongkan seluruh batas menghapus override tanpa mem-parse base (tetap berhasil walau base rusak); menetapkan batas aktif fail-fast bila base tak terbaca.
- **Urutan kanonik `compose_files`** dimiliki `ComposeSource::orderFiles()`: base → `docker-compose.override.yml` → `.override.ports.yml` → `.override.names.yml` → `.override.limits.yml` → `.override.networks.yml` → `.override.env.yml` (env selalu terakhir agar menang).
- **App di server agent**: control plane tidak menyimpan base compose app remote, sehingga daftar service + nilai base dibaca dari server itu (`GET /apps/{id}/services`); `limits` disimpan sebagai **metadata** (tanpa berkas override di control plane) lalu diterapkan job `apply_limits` di agent (`up -d` tanpa build). Agent lama yang belum punya endpoint ⇒ simpan **ditolak** dengan pesan "Perbarui agent …", tab Sumber Daya memakai service dari metadata + peringatan (bukan galat halaman). Kontrak form/view sama dengan app lokal.
- Batasan: tanpa reservation; limit bukan kuota host; belum ada `!reset` untuk menghapus limit bawaan repo; `--compatibility` dilarang (merusak penamaan); nama service numerik murni belum didukung (fail-fast).

### 7.6c Entitlement Build `network.host` (BuildKit)

Build memakai BuildKit/buildx; build yang meminta jaringan host ditolak kecuali diizinkan eksplisit. Dua sisi disiapkan dashboard: **permintaan** lewat override transien `docker-compose.override.build.yml` (`build.entitlements: [network.host]` untuk service ber-`build:` yang belum mendeklarasikannya; file **tidak** masuk `compose_files`), dan **grant** lewat `docker compose build --print` → `docker buildx bake -f <definisi> --allow network.host` → `docker compose up -d` (tanpa `--build`). Build gagal ⇒ `up` tidak dijalankan; definisi sementara dibersihkan. Berlaku sama untuk server lokal & agent (build selalu di host pemilik app). Ini memulihkan perilaku legacy builder, bukan pelonggaran baru.

### 7.7 Kepemilikan & Sharing App

- App dimiliki satu `owner_id`; `members` memberi role. Role efektif: admin > owner > operator > viewer > tanpa akses.
- Matriks ringkas: viewer = detail/log; operator = deploy/stop/env/compose/network/domain/routes/ssl/terminal/files/database/backup; owner = + restore/delete/sharing; admin = semua + `limits` + operasi global.
- **Satu pintu** `AppAccess`; penolakan **404**. Endpoint `/api/*` menerima JSON `{code:404}`, halaman menerima halaman 404. Tombol tersembunyi bukan pengaman.
- **Penyaringan resource global**: `/apps`, `/database`, `/domains`, `/volumes`, `/networks`, `/monitor` disaring ke app yang boleh diakses; baris parkir, volume yatim, container eksternal, dan operasi global hanya admin. Nilai env hanya untuk operator+.
- Transfer owner: owner baru menggantikan `owner_id`, owner lama tetap co-owner. Hapus user memindahkan app & memberships. App tanpa owner dimigrasikan idempoten (`OwnershipMigrator`/`app:assign-owner`).

### 7.8 Self-Update Dashboard

Panel di **Config → tab Update Rames** (`/nginx`), **admin-only** (non-admin 404). Preflight fail-fast: repo git & branch ada, `git` tersedia, helper ada, repo bersih, tidak ada update berjalan, **kepemilikan gitdir sehat**, dan repo dapat ditulis uid:gid helper. Helper menjalankan `git fetch` → checkout force branch → `merge --ff-only` → `composer install` bila perlu → `docker compose up -d --build --force-recreate <service>` → tunggu `/healthz` versi baru. Gagal ⇒ **rollback otomatis** ke SHA lama + rebuild + tunggu sehat. Gate kepemilikan dijalankan helper sebelum perintah git; kegagalan izin vs jaringan diklasifikasikan dan pesan menyertakan `uid:gid` + `chown`.

**Update agen tidak boleh me-recreate service lain.** Helper memakai **berkas compose container itu sendiri** (`COMPOSE_FILE` dari label `com.docker.compose.project.config_files`; fallback `<root>/docker-compose.agent.yml` untuk project `rames-agent`, selain itu `docker-compose.yml`) bersama `COMPOSE_PROJECT_NAME` — daftar berkas wajib ada & tidak kosong, jika tidak update ditolak **sebelum** helper dijalankan. Recreate meneruskan nilai interpolasi efektif (`AGENT_PORT_PUBLISH`, `NGINX_*`, `DOCKER_*`, `RAMES_DB_DIR`, `DNS_*`) sehingga port/wiring tidak kembali ke default; env rahasia (`AGENT_TOKEN`, `AGENT_TLS_*`, `ADMIN_*`, `APP_DOMAIN`, `PORT_RANGE_*`) tidak pernah diteruskan ke helper. Health check mengikuti protokol listener (HTTPS untuk mode agent, probe `curl -k` internal).

UI memantau status + ekor log via polling; run yang ditinggalkan helper ditutup `error`. Cek pembaruan memakai `git ls-remote` (tidak menulis `.git`) dan cache; Cek Pembaruan untuk semua user, update/rollback admin-only. Update ditolak bila ada perubahan belum di-commit; rollback manual hanya tepat setelah update sukses. `GET /healthz` publik tanpa session, hanya `{ok, service, sha, branch, time}`. Self-update **agen** dari `/servers` (admin-only 404) memakai gerbang versi yang sama; hanya `RAMES_MODE=agent` yang boleh menerima op `self_update`.

**Instalasi yang seluruhnya dijalankan sebagai root**: default **menolak** repo milik root, karena helper update berjalan `--user <uid>:<gid>` sebagai pemilik direktori repo. Opt-in eksplisit `UPDATE_ALLOW_ROOT=true` (dashboard `.env`, agent `secrets/agent.env`; env dibaca saat container dibuat ⇒ **recreate**) mengizinkan helper berjalan `--user 0:0`, tetapi **hanya** bila direktori repo **dan** `.git` sama-sama milik root — kepemilikan campuran (`.git` uid lain) tetap ditolak dengan jalan keluar `sudo chown -R 0:0 <root>` atau mematikan opt-in. `preflight()` dan `start()` sama-sama fail-closed **sebelum** efek samping; tidak ada jalur yang memaksa uid > 0.

### 7.9 File Manager Container

Tujuan: jelajah, unggah multi-file, unduh, edit teks, buat file/folder, rename, pindah, hapus, ekstrak arsip — pada **container app yang berjalan**, bukan volume Docker langsung. Ability `files` = **operator+** (penolakan 404); tombol hanya untuk container `running`, endpoint tetap menolak (409 bila berhenti).

Kontrak **10 rute** (`list`, `read`, `download`, `write`, `mkdir`, `rename`, `move`, `delete`, `upload`, `extract`); pembuatan file baru memakai `write` (file belum ada) + probe `read`, tanpa rute tambahan. Batas: unggah/unduh **64 MiB per file**; edit teks **2 MiB** (413 di atasnya; biner/non-UTF-8 415); daftar maks 5000 entri; transfer byte via berkas temp (bukan memori PHP). Ekstrak hanya `.zip`/`.tar.gz`/`.tgz` di host dashboard dengan proteksi zip-slip. Path wajib absolut & dinormalisasi (`PathGuard`); nama entri tanpa `/` dan tidak diawali `-`; nama container dari request tidak dipercaya. Semua `docker exec` berjalan sebagai **root** agar tidak `permission denied` pada bind mount host (konsekuensi diterima; batas ability `files` + audit). Error tak terduga → 500 generik (detail ke log); parameter non-skalar → 400.

### 7.10 Database (Adminer via helper + proxy)

Hanya MySQL/MariaDB (deteksi container). `/database` menampilkan container DB milik app yang boleh diakses; container eksternal hanya admin; kolom server per baris.

Adminer berjalan sebagai **helper container** di network internal `rames-helpers` (**internal: true**), **tanpa port publik**; dashboard mem-proxy request dan tidak ada cookie Adminer di browser (jar di sesi server / header internal untuk agent). Batas proxy: timeout **≤30 detik** dan respons/body **64 MiB** (pelanggaran 413); tidak ada streaming inkremental — dump/import besar lewat backup volume (§8h) atau terminal.

Otorisasi **sebelum** efek samping; container dari request divalidasi milik app; app tanpa hak → **404**. App agent ⇒ proxy ke agent server pemilik container; tidak ada parameter `?server=`. Pengecualian CSRF **path-presisi** hanya untuk proxy Adminer (`#^/database/[^/]+/adminer(/|$)#`); route lain wajib token. HTML Adminer tidak disunting; prefix lewat `X-Forwarded-Prefix`. Sesi basi GET dipulihkan sekali; sesi basi mutasi → 409. Kredensial DB tidak pernah di argv; berkas temp respons 0700/0600 + TTL. Header `X-Forwarded-*` dari klien dibuang; path ter-encode `%2e`/`%2f`/`%5c` ditolak.

### 7.11 Subdomain App (label terpisah dari nama)

`subdomain` menyimpan **label** (bukan FQDN): huruf kecil/angka/`-`, diawali & diakhiri alfanumerik, maks **63**. FQDN efektif = `{label}.{domain server}`; kosong = `{name}.{domain server}` (perilaku lama); nilai lama FQDN penuh tetap dihormati. Satu jalur resolusi `app_subdomain_of()` dipakai Nginx, controller, SSL, CLI. Ability `domain` = operator+; app tanpa hak → 404. Simpan/hapus menulis ulang config Nginx dan reload; konflik diperiksa **per server**, termasuk custom domain & domain parkir. App tanpa host port tidak punya vhost/subdomain; endpoint set menolak dengan pesan prasyarat.

### 7.12 Kredit, Deposit & Penagihan Resource (role `member`)

**Siapa ditagih.** Hanya app milik owner `member`. Aktor `admin` dan app milik `admin` **bebas kredit**; bila aktor berbeda dari owner, penagihan mengikuti **owner app** (pesan 402 khusus bila saldo owner kurang).

**Saldo & kebijakan.** Saldo boleh **negatif** (float 2 desimal). `BILLING_PAYMENT_POLICY=stop` (default): saat penagihan periode menemukan saldo negatif, app owner dihentikan; policy lain = blokir. `adjust` negatif manual tidak langsung menghentikan app (auto-stop saat penutupan periode). Pengalihan app berjalan dari member (ditagih) ke admin (exempt) **menghentikan app** agar tidak ada pemakaian gratis; aturan sama saat user dihapus.

**Tarif.** Global `BILLING_RATE_CPU_PER_CORE_HOUR`, `BILLING_RATE_RAM_PER_GB_HOUR`, `BILLING_RATE_BACKUP_PER_GB_MONTH`, floor `BILLING_BACKUP_MIN_GB`. **Overlay per server** `rates {cpu,ram,backup}`: hanya nilai numerik > 0 yang dipakai, invalid dibuang; overlay hanya mengubah harga, **bukan** hak akses; plafon `BILLING_MAX_*` tetap global. Harga dihitung **saat tick** dari limits saat itu (bukan retroaktif); perubahan limits prorata. App tanpa limits memakai default billing (anti-lubang harga). Biaya backup = `GB_ditagih × tarif × (detik/detik_periode)`; `GB_ditagih = max(floor, Σ bytes snapshot)`; app tanpa snapshot tidak ditagih; volume yatim tidak punya owner member (diserap operator).

**Meteran.** Akrual **inkremental per tick** (`BILLING_SAMPLE_SECONDS`, default 300; 0 = tanpa timer), bukan hitung ulang. Syarat: app `running` **dan** container teramati hidup di **server pemilik app**; `running/paused/restarting` dihitung hidup, `created/exited/dead` tidak. Satu pembacaan per server per tick; galat satu server tidak menggagalkan server lain; token disanitasi dari log. Server tak terjangkau ⇒ **seluruh app server itu dilewati** pada tick itu (tanpa akrual, alasan dicatat); interval terlewat **tidak ditagih kemudian** (under-count, bukan over-charge). `usage` menyimpan snapshot `name`/`owner_id`/`server_id`; `period` = `YYYY-MM` (`Asia/Jakarta`); ledger dipangkas `BILLING_LEDGER_KEEP`.

**Gerbang kredit.** Lihat §5.3. Ditolak 402 JSON (AJAX) atau redirect + flash `/credits` (halaman). Gerbang **bukan** otorisasi.

**Top-up & deposit.** Deposit/adjust manual admin (batas `BILLING_ADMIN_DEPOSIT_MAX`). Top-up Duitku: order `pending → paid|failed|expired`, kurs dibekukan saat order dibuat, cap `BILLING_TOPUP_MAX_PENDING` (pengganti rate limiter). Webhook publik **eksak** `POST /payments/duitku/callback`, HMAC-SHA256 `hash_equals`, kecocokan amount, idempotensi, tanpa session, respons polos; URL wajib publik **https absolut** dengan path persis dan didaftarkan di dashboard Duitku (`ALLOW_HTTP` hanya uji lokal). `BILLING_DUITKU_API_KEY` tidak pernah di log/UI/JSON; diagnostik nonaktif hanya admin. Email user wajib untuk top-up (deposit manual tetap jalan). **Belum ada**: refund/void, rate limiting top-up, rekonsiliasi `StartedAt`. Input non-skalar ditolak (400/flash); deposit/charge admin-only (404); return hanya membaca order user login.

### 7.13 Tarik Image Template (tab Template halaman Config)

Jalur **eksplisit** menyegarkan image prebuilt template (tanpa ini `docker compose up` memakai tag lokal basi). Admin-only (404). Preflight: template ada & valid, punya image, tidak ada run berjalan. Worker detached menarik **semua** image; satu gagal tidak menghentikan yang lain tetapi run berakhir `error`. UI polling 2 detik + log; run yang ditinggalkan ditutup `error`. Pull **tidak** menyentuh app/container/`apps.json`; setelah pull, app perlu **Deploy Ulang** (UI mengingatkan). Menghapus template dari galeri juga mengeluarkan image-nya dari daftar pull; app lama tetap berjalan.

### 7.14 Registry Server & Scope per Server (multi-server)

**Registry.** `/servers` dan seluruh aksinya (tambah/ubah/hapus, token/PEM, Test connection) **admin-only** ⇒ non-admin **404**. Baris server memuat `id`, `name`, `kind` (`local|agent`), `base_url`, `token`, `tls_cert_pem`, `enabled`, `domain`, `port_range`, `notes`, `rates`, `status`, `agent_version`, `capabilities`, `last_seen_at`. Token/PEM adalah rahasia: tidak pernah ditampilkan ulang; form menyediakan perintah siap-salin. Baris `local` **mengunci identitas** (`name`, `kind`, `base_url`, `token`, `tls_cert_pem`, `enabled`, `port_range`); hanya `domain`, `notes`, `rates` dapat diubah. `port_range` lokal bersumber dari `.env`; rentang agent tidak boleh tumpang tindih dengan lokal.

**Otorisasi dua kuasa.** Kelola registry = admin-only; pilih server = admin atau grant `server_select`. Grant tidak membuka registry, app/backup user lain, atau metrik host server tanpa app terlihat. `ServerAccess::visibleServers()`: admin semua; non-admin = union server yang menaungi app yang boleh dilihat **dan** server yang boleh dipilih karena grant; dipakai `/apps`, `/network`, `/volumes`, `/backups`, `/monitor`.

**Onboarding.** `install.agent.sh` (satu perintah, idempoten) menyiapkan paket dasar, clone ke `/opt/rames-agent` (default), dependensi host, lalu `agent/install.sh` yang membuat token + TLS self-signed dan mencetak blok onboarding. Bootstrap **tidak mencetak secret**. Direktori berpenanda dashboard (`.env`, `database/apps.json`, `apps/` berisi) **ditolak**; checkout Rames tanpa penanda dipakai ulang dengan peringatan. Test connection memakai **pinning PEM** (bukan `verify=false`); fingerprint tidak cocok ⇒ gagal tegas. Sukses menyimpan `agent_version`/`capabilities`/`last_seen_at`.

**Port.** Agent `8443` **tidak boleh publik** — hanya allowlist ke IP control plane. Port 80/443 server app wajib terbuka untuk **semua** sumber (ACME HTTP-01).

**Job async & preflight.** `POST /jobs` (job_id + log_offset), polling status/log. `POST /apps/check` read-only sebelum record app dibuat; agent lama tanpa endpoint (404) dilewati; respons tak terbaca ⇒ fail-closed; direktori tanpa penanda kepemilikan tidak pernah dihapus otomatis.

**Keunikan per server**: nama app, host port, nama container, project compose, subdomain, custom domain. Dua server boleh memakai nilai sama.

**Kepemilikan data**:

| Bidang | Kebenaran |
|---|---|
| Metadata app (owner, members, name, domain, env, compose_files, limits, routes, prefix, primary, server_id, …) | control plane; proyeksi agent ditimpa payload |
| `compose_content`, `host_ports` | control plane; tidak diproyeksikan |
| State eksekusi (`status`, `stage`, `containers`, `deploy_history`, `ssl_status`) | agent (hanya agent mengamati); control plane menyalin |
| Job registry | agent |
| Billing, registry/riwayat backup | control plane |

**Operasi runtime per server.** Log container, terminal, file manager, database/Adminer, volume, network, dan monitoring app agent dibaca/dijalankan di **server pemilik app** lewat agent; Engine host control plane tidak pernah dipakai sebagai pengganti. Agent hilang/dipasang ulang ⇒ proyeksi/artefak dibangun ulang pada job berikutnya; control plane hilang ⇒ agent tetap menyimpan proyeksi, tetapi regenerasi dari control plane adalah arah normal.

**App yatim** (baris server dihapus): seluruh operasi ditolak fail-fast (tanpa fallback lokal), halaman detail tetap terbuka agar tab Hapus App dapat dijangkau. **Pengecualian**: `POST /apps/{id}/delete` tetap menuntaskan pembersihan lokal; teardown remote dilewati dan dilaporkan (harus dibersihkan manual di server itu). Teardown app agent membawa penanda `forget`: worker membuang proyeksi, direktori app, deploy key, dan managed env di server itu.

### 7.15 Halaman `/volumes` & `/apps` Sadar-Server

`/apps` menampilkan kolom Server (badge agent/lokal) + filter `?server=<id>`; pilihan filter hanya dari `ServerAccess::visibleServers()`. Grant server tidak memperluas baris app (tetap `AppAccess::visible()`). `/volumes` menampilkan baris dari setiap server yang boleh dilihat + kolom Server; volume agent dibaca per server sehingga volume yatim ikut tampil; `orphaned` diputuskan control plane dari `apps.json` server itu. Non-admin tidak menghubungi server agent tanpa app terlihat; admin selalu menghubungi semua server agent. Server tak terjangkau ⇒ pesan per server, halaman tetap tampil.

**Purge volume** admin-only; volume server agent ditolak **sebelum efek samping** (tidak ada salah atribusi); `purge_orphans` hanya Engine host lokal dan melaporkan yatim agent eksplisit. Baris agent `can_purge=false` dan `—` untuk driver/mountpoint/ukuran. **Kontrak keamanan**: id server tak dikenal ≡ bukan milik user ⇒ **404** dengan respons sama, **tanpa** panggilan agent dan **tanpa** fallback lokal.

### 7.16 Branding — Nama & Logo Dashboard (tab Branding halaman Config)

Admin dapat mengganti nama brand (default `Rames`) dan logo (default `/logo-small.png`) tanpa mengubah kode. Nama: wajib setelah trim, 1–32 karakter, tanpa karakter kontrol. Logo: **PNG/JPEG/WebP**, maks 512 KiB, dimensi ≤4096 px; **SVG & GIF ditolak** (vektor XSS); jenis diverifikasi dari isi (`getimagesize`), bukan nama/MIME klien; unggahan opsional. Endpoint mutasi **admin-only ⇒ 404**, CSRF, selalu PRG + flash. `GET /branding/logo` publik **path eksak** (dibutuhkan login), sumber berkas hanya yang ditunjuk store, `nosniff` + ETag/304. Store `branding` (`branding_settings`); berkas logo di volume `rames` sehingga bertahan saat recreate, tetapi **tidak** ikut snapshot DB (§8i) — backup host/volume diperlukan. Helper `brand_name()`/`brand_logo_url()`/`brand_logo_is_custom()` fail-safe tanpa cache statik.

### 7.17 Tema Tampilan (mode gelap)

Mode `light`, `dark`, `system` (default `system`); nilai ter-resolve (`light|dark`) masuk `data-bs-theme`, `data-theme-mode` hanya untuk ikon. Disimpan **hanya** di `localStorage['rames.theme']` — **bukan** akun/server; tidak ada route/field/migrasi; preferensi per browser dan hilang bila data situs dibersihkan. Dua kontrol sinkron (tombol siklus di footer sidebar & radio di `/profile`), tab lain disinkronkan lewat event `storage`. **Anti-flash**: partial `theme-boot.php` di `<head>` sebelum stylesheet; `theme.js` menangani siklus, `matchMedia`, dan perubahan sistem.

### 7.18 Rekam Error (Error Log self-hosted)

Merekam error yang **tidak tertangani** di sisi user sebagai bahan bug fix, sepenuhnya self-hosted: tanpa SaaS, tanpa telemetri keluar, tanpa dependency baru. Dua sumber: (a) error JS browser (`window.onerror`, `unhandledrejection`) dari `public/js/error-report.js` → `POST /api/errors`; (b) exception server 5xx dari `app/exception/Handler.php` → reporter server. Error yang sudah ditangani (validasi 4xx, flash, 419, `BusinessException`) **tidak** direkam; halaman login tidak memuat reporter (hanya user login); mode `agent` tidak mencatat.

**Pengelompokan & retensi.** Event dikelompokkan per **fingerprint** (normalisasi pesan + frame aplikasi teratas, fallback route) agar satu bug tidak menjadi ribuan baris. Grup menyimpan `status` (`open|resolved|ignored`), jumlah kejadian, sampel terbatas, waktu pertama/terakhir, route, status HTTP, app/server/user, dan jejak penyelesaian. Event baru pada grup `resolved` membuka kembali grup; grup `ignored` tetap diabaikan. Plafon: **20 sampel event/grup**, **5.000 event total**, retensi **30 hari** (dapat dikonfigurasi), prune oportunistik maksimal sekali per jam.

**Akses & privasi.** Halaman `/errors`, detail, API baca, ubah status, dan hapus **admin-only**; non-admin menerima **404** (bukan 403) dan badge nav hanya dihitung untuk admin. Laporan klien wajib login + CSRF (tanpa pengecualian middleware), body ≤16 KiB, kuota 20 laporan/menit/user. Sebelum disimpan, pesan/stack/context diredaksi: header otorisasi/cookie, blok kunci privat, URL ber-userinfo, pasangan kunci sensitif, dan pola token. Yang **tidak pernah** disimpan: IP, cookie, body request, nilai environment, atau `_token`; `app_id` hanya dipertahankan bila user berhak melihat app tersebut; user-agent dipotong 512 byte. Log mentah webman (`runtime/logs/webman.log`) tetap ditulis apa adanya oleh handler lama — redaksi hanya berlaku untuk salinan yang masuk DB.

**Control plane & fase 2.** Fitur berjalan di dashboard (control plane); server agent belum mengirim error-nya ke control plane. Belum ada tombol "Laporkan masalah" manual, notifikasi email/webhook, maupun uji HTTP hidup/JS browser di CI. Rincian alur dan batasan: §5.43, §8.

## 8. Reverse Proxy / Subdomain Routing

### 8.1 Domain dasar

Satu domain dasar per server (`servers.domain`). App memakai `{label}.{domain server}`; server `local` memakai `APP_DOMAIN` bila `servers.domain` kosong. DNS wildcard/record subdomain wajib mengarah ke server yang tepat.

### 8.2 Template Nginx config

Satu app = satu berkas config di direktori host ter-mount; dashboard menulis atomik, host me-reload. **Serve block** (HTTP 80 & HTTPS 443) memuat header proxy (`Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`) dan penerusan WebSocket (`Upgrade`/`Connection`, `proxy_http_version 1.1`). **Redirect block** (subdomain → custom domain) dan blok ACME tidak memuat WebSocket. `location /.well-known/acme-challenge/` selalu dirender di blok 80 (termasuk blok redirect) untuk renewal. `listen 443 ssl` + redirect 80→HTTPS dirender bila SSL aktif; path cert per domain. Target `proxy_pass` = `127.0.0.1:{host_port primary}`.

### 8.2a Rute proxy tambahan per app

- `nginx_routes` = maks 20 rute **terstruktur** `{path, target}`; **DILARANG** snippet Nginx mentah (`include`/`server`/`listen`/`server_name` dari user).
- Validasi path: absolut, karakter terbatas, ≤200, bukan `/`, bukan `/.well-known`, tanpa duplikat, tanpa segmen `..`. Target: `http(s)://host[:port][/path]`, tanpa userinfo/query/fragment/`..`. Pesan error menyebut `Baris N:`.
- Dirender sebagai `location ^~ {path}` **setelah** `location /`, hanya di serve block (80 & 443), dan ikut setiap regenerasi (deploy/rebuild/rollback/SSL). Ability `routes` = operator+ ⇒ 404. App tanpa host port ditolak lebih dulu.
- **Transaksi Simpan & Terapkan + rollback**: snapshot rute lama → simpan → tulis config → `nginx -t` + reload. Gagal ⇒ pulihkan rute + config lama; host tidak di-reload. `applyRoutes()` tidak pernah melempar; konsumen **wajib** menampilkan `error`, bukan hanya `rolled_back`.
- **GOTCHA**: hostname target harus resolvable dari host Nginx; jangan menghapus blok ACME; guard hanya mencakup jalur rute (domain/SSL menulis config langsung); belum ada lock per-app (lost update mungkin); crash antara persist & reload dapat meninggalkan state/config tidak sinkron.

### 8.3 Mekanisme reload

Watcher host (systemd + `inotifywait`) memantau direktori config: `nginx -t && nginx -s reload`. Config invalid **memblokir reload seluruh host**; karena itu penulisan harus atomik dan alur transaksional wajib rollback. Watcher berjalan dengan sudoers terbatas untuk reload, bukan root penuh.

### 8.4 Reload dari dashboard (via Docker socket) — pelengkap/fallback watcher

Dashboard dapat memicu reload lewat helper yang memakai Docker socket di network namespace host (bukan `docker exec` ke container Nginx). Ini pelengkap watcher; dashboard tetap tidak mengeksekusi shell host.

## 8a. SSL Otomatis (Let's Encrypt)

Certbot dijalankan di **dashboard/agent** (root) oleh worker; dashboard tetap satu-satunya penulis config Nginx (blok 443 dirender sendiri). Status per slot: `disabled|pending|active|failed`. Bila `custom_domain` ada, app tampil sebagai **dua baris**: custom domain (serve, slot `custom_ssl`) dan subdomain (redirect, slot `ssl`). Challenge `http` (webroot HTTP-01, default) atau `dns-cloudflare`; `SSL_CA_SERVER=staging` untuk uji. Sukses ⇒ `active` + expiry + regenerate + reload; gagal ⇒ `failed` + pesan `Detail:` certbot (bukan hanya ringkasan stderr), app tetap HTTP. Renewal `certbot renew` 2×/hari via systemd timer host, reload hanya bila sertifikat benar-benar diperbarui.

**Antrean aktivasi (satu job pada satu waktu).** Permintaan SSL (app & domain parkir) tidak dijalankan langsung: ia masuk antrean global (tabel `ssl_jobs`) yang dieksekusi **satu per satu** lintas domain — sebab certbot memakai lock global dan Let's Encrypt punya rate limit. Permintaan ganda untuk domain/slot yang sama tidak menduplikasi job; baris domain menampilkan posisi antrean ("antrean #N dari M") selama status `pending`. Galat **transien** (lock, timeout, jaringan, 5xx) diulang otomatis maksimal 3 percobaan dengan jeda 30 dtk lalu 120 dtk; galat permanen (rate limit, `Invalid response`, DNS/NXDOMAIN, webroot, `ADMIN_EMAIL` belum diisi) langsung `failed` + pesan certbot. Antrean dijalankan proses timer **dashboard-only** — deploy pertama yang memuat proses baru ini membutuhkan **restart** dashboard, bukan reload. Riwayat job `done`/`failed` disimpan 7 hari.

**Prasyarat**: DNS mengarah ke server; port 80 & 443 terbuka **untuk semua sumber** (validasi HTTP-01 dari IP acak); `ADMIN_EMAIL` wajib di **setiap** server yang menerbitkan; `LETSENCRYPT_PATH` ter-mount; watcher Nginx aktif. Domain non-publik (`.local`, IP) tidak bisa di-SSL; app tanpa port terpublish tidak muncul di daftar domain. UI `/domains` memakai AJAX (`GET /api/domains/status`, `POST /api/ssl/{id}/enable`) + polling hanya saat ada `pending`, dijeda saat tab tidak terlihat; fallback form POST tetap ada. Ringkasan galat ≤80 karakter; teks penuh di modal Log (`textContent`). Webroot tak traversable ditolak sebelum certbot (hemat rate limit).

## 8b. Custom Domain per App

Satu `custom_domain` (FQDN publik) per app. Subdomain bawaan tetap aktif tetapi **redirect 301** ke custom domain; keduanya proxy ke host port primary. Field: `custom_domain` + `custom_ssl_status/stage/message/error/expires_at`; slot SSL subdomain (`ssl_*`) tetap terpisah. Set/ganti: validasi FQDN publik, bukan subdomain app itu sendiri, **unik per server** (termasuk subdomain/custom/parked). Ganti/hapus me-revoke cert lama best-effort; app lama tanpa field aman. Target redirect memakai `https` hanya bila SSL custom sudah `active`; sebelum itu `http`. Blok ACME dirender di setiap server block (termasuk redirect) sebelum `return 301`.

## 8c. Log Viewer Real-time per Container

Panel log di detail app: pilih container (dari `containers[]`), mode tail (default 200 baris) dan follow/auto-refresh. Implementasi saat ini polling `docker logs --tail/--since` (Engine API) dari endpoint auth; bisa dilihat sejak role **viewer**. Ada batas baris tampil + tombol clear (hanya buffer UI). **Belum ada** streaming SSE + buffer historis (§12).

## 8d. Monitoring Resource Container & Total VM

Halaman global `/monitor`: ringkasan host + tabel container per **server**. Host dibaca dari `/proc` (`stat`, `meminfo`, `loadavg`, `uptime`, `cpuinfo`), storage `statvfs` pada `DISK_PATH`, dan total volume terpakai; nilai tak terbaca = **N/A**, bukan error. Container: `State`, `StartedAt`, `RestartCount`, health; CPU% dan memori (dikurangi page cache), pids; idle = 0%, data tak ada = N/A; stats diambil paralel. Data **tidak persisten**; polling hanya selama halaman terbuka (dijeda saat tab tidak terlihat).

**Hak akses**: admin melihat semua container termasuk eksternal; non-admin hanya container app yang boleh diakses. Angka host server agent **tidak dikirim** bila user tidak melihat satu pun app di server itu (fail-closed; server tak dihubungi). Satu server mati ⇒ entri offline + error tersanitasi, halaman tetap 200. Batasan: deteksi dini, bukan monitoring historis/alerting (§12).

### 8d.1 Metrik per-App di Tab Info (CPU, Memori, Storage)

Tab **Info** di detail app (`/apps/{id}`) menunjukkan pemakaian **satu app** — pelengkap halaman `/monitor` (§8d) yang bersudut pandang host/server:

- **Kartu CPU & Memori**: nilai terkini + grafik **rolling 5 menit** (maks 60 titik × 5 detik). Grafik dirakit **di browser** dari snapshot berkala; server tidak menyimpan histori sehingga tidak ada tabel metrik baru dan grafik hilang saat halaman ditutup (§12). Polling berhenti saat tab disembunyikan dan saat halaman ditinggalkan — tanpa permintaan latar belakang.
- **Kartu Storage**: total ukuran app = **volume project** + **writable layer container** milik compose project app. **Image bersama tidak dihitung**; menambahkannya membuat "ukuran app" menyesatkan karena satu image dipakai banyak app. Ukuran yang tidak bisa dihitung Engine (nilai negatif) dilaporkan `N/A` dan **tidak** dijumlahkan.
- **Nilai jujur**: CPU/memori yang memang tak terbaca (app tanpa container, seluruh container berhenti) = **N/A**, bukan `0%` yang menyesatkan; jumlah container (`containers`/`running`) tetap dilaporkan sebagai angka.
- **Hak akses**: ability `view` (Viewer ke atas) — membaca metrik tidak mengubah apa pun. App tak dikenal atau user tanpa hak ⇒ **404** (bukan 403), konsisten dengan seluruh detail app.
- **Multi-server**: app di server agent diukur **di server itu** (statistik container + ukuran disk server itu, dengan penyaringan per app); agent versi lama yang belum mendukung ukuran disk ⇒ storage "—" + keterangan, bukan galat. Server app hilang/rusak ⇒ tab tetap terbuka dengan data kosong + pesan generik (bukan 500).
- **Batas**: tanpa histori tersimpan/alerting (§12); ukuran storage diperbarui jauh lebih jarang daripada CPU/memori karena biaya membacanya mahal (detail teknis di dokumentasi arsitektur).

## 8e. Search / Filter / Pagination Daftar App

`/apps` menerima `?name=`, `?domain=` (substring case-insensitive pada domain efektif/custom), `?owner=` (persis; **hanya admin**, diabaikan untuk non-admin), digabung AND dengan `?scope=` dan `?server=`. Filter berbasis query string (bisa dibagikan, back bekerja); tab mempertahankan filter; `counts` tab ikut menyempit. Satu pintu pencocokan `AppFilter` (statik, tanpa I/O/otorisasi); pengaman daftar tetap `AppAccess::visible()`. **Belum ada**: pagination (`SITES_PER_PAGE` belum dipakai) dan filter status/`?q=` bebas.

## 8f. Rate Limiting & Proteksi Brute-Force Login

**Status: belum diterapkan di kode.** Ini kebutuhan keamanan yang mengikat sebelum dashboard diekspos ke internet publik. Desain: catat login gagal per **IP + username** (fallback per IP); setelah `LOGIN_MAX_ATTEMPTS` (default 5) dalam jendela waktu, tolak `/login` POST selama `LOGIN_LOCKOUT_MINUTES` (default 15) dengan pesan generik. Cek lockout **sebelum** verifikasi; catat hasil setelah verifikasi; penyimpanan tidak memuat password. Jangan mengklaim proteksi ini ada sampai implementasi + uji tersedia.

## 8g. Backup Otomatis Data & Config

Skema lama salinan `.bak` per berkas untuk data domain **tidak berlaku**: data domain di SQLite dengan transaksi atomik dan backup berkala sendiri (§8i). Config Nginx diamankan lewat uji + rollback transaksional (`nginx -t` dulu), bukan salinan `nginx-status/backups/`. Env `BACKUP_ENABLED`/`BACKUP_RETENTION`/`BACKUP_PATH` sudah tidak ada. Cache runtime JSON tetap memakai salinan `.bak` (`JsonStore`), tetapi cache bukan sumber kebenaran. Backup DB dashboard memakai `DB_BACKUP_*` (§8i), **terpisah** dari backup volume (`VOLUME_BACKUP_*`/`RESTIC_*`/`AWS_*`, §8h).

## 8h. Backup Volume Harian ke S3 (restic)

Tujuan: backup **volume Docker milik app** ke object storage eksternal secara harian dengan restic (inkremental, dedup, enkripsi, retensi native), termasuk restore dari UI. Terpisah dari backup DB dashboard (§8i).

### 8h.1 Dua strategi konsistensi (keputusan D1)

**Strategi A — logical dump** untuk volume container DB hidup: dump ke staging `runtime/backup/staging/...`, lalu restic; restore = import dump ke container DB hidup, bukan menimpa volume. Container DB mati / dump dimatikan ⇒ fallback snapshot. **Strategi B — filesystem snapshot** untuk volume non-DB: harian `stop → snapshot → start`, serial per app; `VolumeStateGuard::assertStopped()` menolak snapshot bila masih ada container running yang me-mount volume (**tidak ada jalur paksa**); start ulang dijamin `finally`; policy `skip` mematikan snapshot otomatis (manual tetap bisa). Restore snapshot menimpa isi volume dan container wajib berhenti.

### 8h.2 Cakupan

Hanya **named volume** berlabel project compose; bind mount & anonymous volume di luar cakupan. Volume yatim tetap di-backup sampai retensi habis, tetapi hanya admin yang melihat/memulihkannya. Volume dashboard (`rames`, label `rames.role=dashboard-db`) **dikecualikan** dari target backup/restore/schedule dan `/volumes` (§8i.7) — snapshot `stop` akan mematikan proses yang menjalankan backup.

### 8h.3 Alur run

1. Ambil `run.lock` (`flock`) — run harian vs manual tidak tumpang tindih.
2. Enumerasi volume berlabel → petakan project → app, tandai `orphaned`.
3. Iterasi **serial per app**: pilih strategi A/B; B selalu start ulang di `finally`.
4. Retensi `restic forget --prune --keep-daily/weekly/monthly` **per volume** (tag `volume:<nama>`).
5. Tulis laporan status + log; staging dibersihkan di `finally`.

Kegagalan satu volume tidak menghentikan volume lain; status `ok`/`partial`/`failed`.

### 8h.4 Restore (sadar-strategi)

Strategi dibaca dari tag snapshot; absen ⇒ deteksi container DB. Dump → restore ke direktori sementara → import ke DB hidup. Snapshot → container berhenti → `restic restore --delete --include /data` → start ulang (tanpa `docker volume rm`). Fail-fast: validasi nama volume, id snapshot, keberadaan snapshot, dan kepemilikan **sebelum** efek samping. Restore arsip volume terhapus ke volume **baru**: §8h.11.

### 8h.5 Otorisasi

`backup` = operator; `restore` = owner (destruktif). Satu pintu `AppAccess`/`BackupAccess`; penolakan **404**. Seleksi berkala (`POST /backups/schedule`) = ability `backup` (owner/operator); volume yatim admin-only. Refresh status (`POST /backups/refresh`) = **admin global** (non-admin 404). Gerbang kredit **hanya saat menyalakan** seleksi, menilai aktor lalu penanggung biaya/owner (402 bila kurang); mematikan bebas; backfill otomatis tidak digerbang. Respons status disanitasi daftar-putih; baris volume disaring `BackupAccess::visible()`.

### 8h.6 Penjadwalan & helper container

**Satu-satunya clock** = timer host (`volume-backup.timer`, 02:30 ±delay) → `host/backup.sh` → `cli/backup.php run`; agent **tidak** memasang timer. Run terjadwal memproses volume lokal dan **fan-out** volume agent bertanda `scheduled` sebagai job `backup_run` ke server pemiliknya; exit code mengikuti hasil nyata per volume/server. restic dijalankan di **helper container** yang me-mount volume `:ro` (bukan dashboard), memakai image dashboard/override, dan menerima DNS eksplisit dari container dashboard (agar S3 resolve saat resolv.conf host rusak). `GET /api/backups/status` = cache + **satu** panggilan Engine read-only untuk status container live (timeout 5 dtk; gagal ⇒ cache); snapshot/backup terakhir tetap cache sampai run/refresh. Pekerjaan berat di-spawn detached.

### 8h.7 Prasyarat deploy (WAJIB)

Image dashboard memuat binary `restic`; service dashboard mempertahankan blok `dns:` di compose; kredensial S3/restic tersedia di server yang menjalankan backup (env server/berkas 0600), bukan di payload; panduan in-app `/backups/guide` admin-only (404) merender Markdown repo secara fail-safe; proses timer baru perlu **restart** worker (bukan reload) saat pertama dipasang.

### 8h.8 Aturan kredensial (DILARANG)

**DILARANG** menaruh kredensial di `RESTIC_REPOSITORY` (masuk argv/`ps`); kredensial `AWS_*` hanya lewat env-file sementara **0600**, passphrase restic hanya lewat `--password-file` (mount `:ro`). **DILARANG** menaruh kredensial di store app/SQLite, log, respons JSON, rekaman job, atau argv. **DILARANG** mengutip nilai env-file (`--env-file` tidak mengupas kutip; tulis `KEY=VALUE` mentah). **DILARANG** menaruh password DB di argv — kirim lewat stdin (baris pertama; tanpa newline). **DILARANG** menyediakan jalur paksa snapshot saat container hidup.

### 8h.9 Risiko & trade-off

Downtime singkat harian pada volume non-DB (stop→snapshot→start); mitigasi jadwal jam sepi, serial per app, timeout stop, start dijamin. Biaya/ukuran S3 dikendalikan retensi + lifecycle policy. Biaya backup dibebankan ke member pemilik app (tarif + floor), dihitung dari bytes snapshot tersimpan; registry bisa tertinggal satu run. Volume yatim tidak ditagih (diserap operator). Penghitungan snapshot live memakai timeout pendek agar S3 lambat tidak mengunci UI.

### 8h.10 Seleksi berkala per volume & status cache

Seleksi di store `backup` koleksi `volumes`; **default OFF (opt-in)**. Volume yang sudah punya snapshot di-backfill `scheduled=true` (idempotent; entri eksplisit tidak ditimpa). Menyalakan seleksi memanggil gerbang kredit (saldo owner) dan mengembalikan 402 bila kurang; seleksi tidak berubah. Tiap baris UI membawa `can_schedule`, `backup_gb` (termasuk floor), `backup_credits_month`. **GOTCHA**: app tanpa snapshot ⇒ `required = 0`, sehingga gerbang belum menghalangi; biaya mengikuti snapshot nyata — jangan andalkan gerbang sebagai jaminan saldo app baru. Run terjadwal murni membaca seleksi (tanpa panggilan Engine/restic tambahan); status cache + `container_state` live; `archived[]` admin-only.

### 8h.11 Riwayat volume ter-backup (arsip) & restore ke volume baru

Store `backup` koleksi `registry` menyimpan riwayat volume yang **pernah** di-backup (project, app, strategi, snapshot, bytes, timestamps). Dicatat tiap volume `ok`; backfill untuk volume lama yang sudah punya snapshot (idempotent). Arsip **admin-only** (404). Restore selalu ke **volume baru** (volume lama tidak disentuh); nama target divalidasi. Unduh `.sql` hanya untuk arsip strategi dump dan baru tersedia setelah snapshot id tercatat. Sinkronisasi/prune mengikuti isi repo restic; entri yang snapshot-nya habis dipangkas.

### 8h.12 Backup / Restore / Dump per Server

Volume hidup di server tempat app berjalan; repo restic & kredensial S3 milik **server itu**. Control plane tidak menjalankan restic untuk volume agent dan tidak mengirim kredensialnya. Alur: controller (otorisasi ability) → worker control plane → `BackupDispatcher` memilih server → job agent (`backup_run|restore|db_dump`) → worker di server itu. **Payload job hanya penanda** (`volume`, `snapshot`, `trigger`) + metadata app tanpa kredensial; tidak ada kredensial di payload/rekaman job/log/argv. `VolumeStateGuard` tetap berlaku di server itu; otorisasi tidak berubah; penolakan 404/422 tanpa efek samping. Penolakan lintas server (server tak dikenal/nonaktif/bukan agent, volume tak ditemukan, atau app terdaftar di server lain) ⇒ batal, **tanpa fallback**. Nama volume ada di >1 server ⇒ `server_id` wajib (tidak ditebak). Server yang melaporkan capabilities tanpa op data ⇒ 422 tanpa spawn; agent lama yang tak mengenal op ⇒ 501. Restore arsip volume agent didukung sebagai job `restore_archived` di server itu; membaca snapshot arsip/unduh `.sql` arsip volume agent dari control plane ⇒ **422** (pakai `GET /api/backups/snapshots?server_id=` atau job `db_dump`).

### 8h.13 Op Tingkat Server & Versi Agen

Tiga op agent yang tidak terikat app (mode `cli/deploy.php`, `argv[1]` = target): `template_pull` (tarik image di server itu; preflight gagal ⇒ tanpa `docker pull`; non-admin 404), `self_update` target `agent` (hanya `RAMES_MODE=agent`; preflight gagal ⇒ fail-closed; versi control plane terlalu tua ditolak di agen), dan `restore_archived` (restore volume arsip ke volume baru di server itu; payload wajib `snapshot` + `target_name`; `VolumeStateGuard` berlaku).

**Gerbang versi & kapabilitas** (`AgentVersionGate`): `GET /version` mengembalikan `agent_version`, `capabilities`, `min_control_plane_version`. Capabilities diutamakan atas string versi. Op yang tidak ada di capabilities ⇒ `unsupported` (suruh perbarui agen + Test connection). Agen di bawah versi minimum ⇒ semua op ditolak; `min_control_plane_version` lebih baru dari dashboard ⇒ ditolak (termasuk self-update agen). Pesan galat hanya nama + id server, tanpa token/base_url. **Self-update agen** (`POST /servers/{id}/agent-update`) admin-only (404); gerbang kompatibilitas dulu, lalu job; UI menampilkan versi/kompatibilitas + tombol Perbarui agen.

**Status update agen WAJIB terlihat di UI, bukan hanya di log server.** Setelah job `self_update` dibuat, halaman `/servers` memantau `GET /servers/{id}/agent-update/status?job&offset` (admin-only ⇒ 404; GET read-only tanpa CSRF) dan menampilkan tahap + pesan galat job — termasuk penolakan preflight di agen (mis. repo agen masih punya perubahan belum di-commit) yang sebelumnya hanya ada di `runtime/logs/deploy/agent.log` server agen. Respons 200 membawa `{job_id,status,stage,message,error,finished_at,log,offset,reason?}`; `status ∈ pending|running|done|error|unreachable`. **Klasifikasi wajib**: `unreachable` (200) **hanya** untuk galat transien — transport, HTTP 5xx agen, atau rekaman job hilang setelah agen di-recreate (`data.reason` ∈ `unreachable|server_error|not_found`) — supaya UI tetap menunggu; galat **deterministik** (token/PEM kosong, unauthorized, TLS gagal, `unsupported`, `bad_response`) dijawab **400 `{code:400,msg,reason}`** dan polling berhenti (tidak disamarkan sebagai restart). **Batas pemantauan**: polling 2 detik, maksimum 90 percobaan (±3 menit) dan 5 kegagalan jaringan berturut-turut, berhenti pada `done`/`error`/400/404; reload halaman membuang pemantauan (job tetap berjalan di agen). `done` hanya berarti job selesai, verifikasi versi baru tetap lewat Test connection.

**Unduh dump & terminal**: `GET /backups/dump` (agent) mengirim potongan `.sql` dengan header offset/EOF; `POST /terminal/run` (agent) perintah satu-kali non-interaktif (≤600 dtk), tanpa sesi tertinggal; unduh `.sql` di dashboard memilih server lewat satu pintu (ability `backup`). **Retensi staging**: `VOLUME_BACKUP_DUMP_KEEP`, `_MAX_AGE_DAYS`, `_DOWNLOAD_MAX_BYTES`; dijalankan di sisi yang membuat dump, tidak pernah menghapus di luar root staging. **Batasan yang disengaja (bukan bug)**: purge volume agent belum ada (purge lokal-only, baris agent `can_purge=false`); baris volume agent hanya `{name, project, app_id, orphaned}` sehingga driver/mountpoint/ukuran `—`; higienitas pesan galat worker/template pull/billing masih diketahui.

## 8i. Basis Data Dashboard (SQLite) & Backup Berkala

### 8i.1 Skema & penyimpanan

Satu berkas `rames.sqlite` di `<RAMES_DB_DIR>` (named volume `rames`, `/var/lib/rames`), memuat semua store logis; gitignored. Store → tabel: `apps.apps`, `auth.users`, `billing.{users,usage,orders}`, `backup.{volumes,registry}`, `servers.servers`, `parked.domains`, `branding.settings`; nama fisik `{store}_{koleksi}`. Kolom `id`, `ord`, `data` (dokumen JSON = sumber kebenaran), `updated_at`, + kolom turunan terindeks; field baru cukup di dokumen (tanpa migrasi kolom). PRAGMA `journal_mode=WAL`, `synchronous=NORMAL`, `foreign_keys=ON`, `busy_timeout=5000`; semua mutasi `BEGIN IMMEDIATE` (re-entrant per berkas). `SqliteStore` mempertahankan API `path/read/write/update`; pemakai tidak berubah.

### 8i.2 Cache runtime TETAP JSON

`runtime/update/check.json`, state jaringan Adminer, `runtime/backup/catalog.json`, `runtime/backup/status.json` + `runs/*.json`, dan `runtime/db-backup/state.json` tetap JSON (`JsonStore`). `database_path` (`keys/`, `env/`, `restic/password`) tidak berubah.

### 8i.3 Impor otomatis data lama + rollback

Sekali saat koneksi pertama (flag `legacy_imported_at`): `DB_IMPORT_LEGACY_JSON=true` ⇒ impor `database/{apps,auth,billing,backup}.json` ke store; store yang sudah berisi tidak ditimpa; berkas JSON lama tidak dihapus. Idempoten; `php cli/db.php import-json [--force]`; kegagalan impor tidak mematikan worker. Rollback: `php cli/db.php export-json [--dir=…]`.

### 8i.4 Backup berkala + retensi

Proses `db-backup` (tick 300 s): bila aktif, jam terlewati, dan belum ada run hari ini ⇒ `VACUUM INTO backup/rames-YYYYmmdd-HHMMSS.sqlite`. Mutex `flock` non-blocking: tumpang tindih dilewati (`busy`), bukan error. Retensi harian/pekan ISO/bulanan; snapshot terbaru selalu dipertahankan; nilai 0 = tingkat diabaikan; `pre-restore-*` tidak ikut retensi. Restore defensif **sebelum** efek samping: basename + di dalam direktori backup, `integrity_check`, tabel inti wajib ada, safety snapshot, ganti atomik, buang WAL/SHM lama, reset koneksi.

### 8i.5 UI & API (halaman `/backups`)

Kartu DB dashboard (admin-only): path/ukuran, snapshot terakhir, jumlah/total, retensi, status, error; tombol Backup sekarang/Prune; daftar snapshot + unduh + restore (wajib ketik `RESTORE`). Route `POST /backups/db/run|prune|restore`, `GET /backups/db/download` — semua admin-only 404.

### 8i.6 CLI

`php cli/db.php status | migrate | import-json [--force] | export-json [--dir=…] | integrity | backup [--reason=] | list | prune | restore <berkas> --yes`.

### 8i.7 Volume dashboard dikecualikan dari backup volume (§8h)

Volume `rames` berlabel `com.docker.compose.project` tetapi **bukan** volume app. `VolumeTargetMap::isDashboardVolume()` membuangnya dari target backup/restore/schedule dan `/volumes`/purge. Tanpa ini snapshot `stop` akan mematikan container dashboard (proses backup itu sendiri).

### 8i.8 Runbook deploy/upgrade (WAJIB)

1. **Recreate container** (`docker compose up -d --build`), bukan hanya restart/reload: env & mount volume baru berlaku saat recreate. `command:` menjalankan `migrate && import-json` **fail-fast** sebelum start.
2. Verifikasi `php cli/db.php status` dan `integrity` dari dalam container.
3. Restart worker saat proses `db-backup` baru pertama dipasang (`reload` tidak memuat proses baru).
4. Setelah restore snapshot: `php start.php reload` agar worker melepas handle PDO lama.
5. Rollback ke JSON: `export-json` lalu jalankan versi lama; restore snapshot DB membuat safety snapshot lebih dulu.

## 8j. Domain Parking (halaman /domains)

Tujuan: FQDN global di luar app — tanpa container/deploy — menyajikan halaman statis atau redirect lewat config Nginx sendiri. `www` adalah **entri terpisah**; `add_www` saat create membuat entri kedua dengan id/direktori sendiri.

### 8j.1 Non-goals fase 1

**Hanya server lokal**: `server_id` selain `local` ditolak fail-fast sebelum efek samping. **Tanpa wildcard** (`*.contoh.com` ditolak). Target redirect **tanpa query/fragment** (query & path permintaan asli dipertahankan via `$request_uri`). **Bukan non-goal**: SSL per entri parkir didukung (§8j.6a); blok ACME tetap hanya di blok 80.

### 8j.2 Data model

Store `parked` (tabel `parked_domains`), dokumen `{id, domain, mode, redirect_to, redirect_code, server_id:'local', created_by, timestamps, ssl_*}`. `id` wajib `pd_` + 12 hex karena masuk path/nama config. Konten HTML **tidak** di SQLite: `{PARKED_ROOT}/{id}/index.html`, ditulis atomik; `PARKED_ROOT` default `{proyek}/database/parked`, gitignored. Keunikan domain case-insensitive ditegakkan di dalam transaksi tulis; daftar diurutkan domain.

### 8j.3 Hak akses & route

`/domains` = satu halaman dua tab: **Semua Domain** (`?tab=all`) dan **Domain Parkir** (`?tab=parking`). Filter `?domain=` menyaring keduanya (substring case-insensitive) **setelah** otorisasi. `GET /domains` + `GET /api/domains/status` terbuka semua user login; baris app disaring `AppAccess::visible()` dengan `can_ssl`; baris parkir hanya admin; non-admin tidak pernah mendapat tab/aksi parkir.

CRUD parkir + aktivasi SSL parkir **admin-only ⇒ 404** dievaluasi sebelum membaca store. Endpoint: `GET /domains/create`, `POST /domains`, `GET/POST /domains/{id}`, `POST /domains/{id}/delete`, `POST /domains/parked/{id}/ssl`, `POST /api/domains/parked/{id}/enable`. `GET /ssl` = **302 → `/domains`**. Jalur SSL app: `POST /ssl/{id}/enable` (halaman) & `POST /api/ssl/{id}/enable` (AJAX, CSRF wajib); `GET /api/ssl/status` alias kompatibel baris app. Menu Domains tampil untuk user login; tombol/tab parkir hanya render admin (lapisan kedua, bukan pengaman).

### 8j.4 Validasi

**Domain**: trim/lowercase, buang titik akhir, ≤253, minimal 2 label, tiap label ≤63 pola DNS, TLD alfabet 2–63; tolak IP, localhost, wildcard, underscore, spasi, skema/path, `..`. **Mode**: hanya `html|redirect`. **HTML**: wajib non-kosong, ≤1 MiB; upload `html_file` menimpa textarea, ukuran diperiksa dua kali. **Target redirect**: wajib `http(s)://`, host FQDN publik/IPv4, port opsional 1–65535, path absolut opsional; tolak userinfo/query/fragment/spasi/kontrol/karakter pemecah quoting (kutip, backslash, `<`, `>`, `{`, `}`, `$`, `;`, backtick, `|`, `^`) dan `..` (termasuk `%2e%2e`); panjang ≤2048. **Kode redirect**: hanya 301/302; kosong = 301. Validasi **diulang saat render config** (pertahanan data store rusak).

### 8j.5 Keunikan dua arah

**Parkir → app**: tolak bila domain sama dengan FQDN efektif app, `custom_domain` app mana pun, atau entri parkir lain (`excludeId` saat edit). **App → parkir**: pembuatan subdomain/custom domain menolak FQDN yang sudah diparkir. Tanpa ini dua `server_name` identik akan ditulis ke Nginx.

### 8j.6 Config Nginx

Satu entri = satu berkas `_parked_{id}.conf` + symlink enabled, ditulis atomik; prefiks `_parked_` tidak mungkin bentrok (nama app tidak boleh berawalan `_`). Mode `html`: `listen 80` + `server_name` + `root {PARKED_ROOT}/{id}` + `index` + `try_files`; SSL aktif ⇒ blok 80 hanya ACME + redirect https, blok 443 menyajikan konten. Mode `redirect`: `return {301|302} {target}$request_uri`; SSL aktif ⇒ kode/target sama di 80 & 443 (tanpa redirect ganda). ACME `location ^~ /.well-known/acme-challenge/` hanya di blok 80. `root` wajib absolut, bebas spasi/kutip/`;`/`{}`/`#`/`\`/`$` dan tanpa `..`. Config app & parkir tidak saling menimpa.

### 8j.6a SSL domain parkir (Let's Encrypt)

Status per entri `ssl_status/stage/message/error/expires_at` di dokumen JSON (tanpa migrasi). Aktivasi admin-only; validasi entri/domain publik; status `active`/`pending` ditolak sebagai info. Tulis `pending` + `queued` **sebelum** enqueue ke antrean SSL global (`ssl_jobs`, SPECS §8a); gagal enqueue ⇒ `failed` + Retry. Worker (`cli/parked-ssl.php`, dijalankan antrean satu per satu): validasi ulang → certbot (dilewati bila `active` & cert ada) → `active` + expiry → tulis config 443 → reload.

Cert hilang saat status `active` ⇒ render **HTTP-only** (tidak menggagalkan `nginx -t` host); status tetap, pulihkan dengan worker/cert; tombol Retry UI hanya untuk `failed`. Gagal terbit ⇒ `failed` + expiry `null`. Gagal tulis/reload **setelah** cert terbit ⇒ tetap `failed` tetapi expiry **dipertahankan**. Worker idempoten/reconcile: `active` + cert ada ⇒ tulis ulang config + reload tanpa certbot; `failed` + cert ada ⇒ `--keep-until-expiring` lalu tulis ulang. Rename/delete me-reset `ssl_*` dan me-revoke cert lama best-effort; kegagalan revoke hanya peringatan.

### 8j.7 Transaksi "uji dulu + rollback"

`create`: validasi + keunikan → simpan entri → tulis konten/config → reload sekali; gagal ⇒ hapus config/konten/entri, `reloaded=false`. `update`: snapshot entri + konten → persist → tulis konten/config → reload; gagal ⇒ pulihkan entri/konten/config lama tanpa reload. `delete`: hapus metadata → hapus config → reload; gagal ⇒ buat ulang entri/config; direktori konten dihapus **setelah** sukses. Reload tidak dipanggil bila tulis config gagal; `add_www` menulis dua config lalu reload sekali. `rolled_back=false` + `PERINGATAN: rollback gagal` ⇒ pemulihan gagal; `error` wajib ditampilkan bahkan saat `ok=true`. UI: validasi gagal ⇒ form 200 dengan input lama; AJAX menerima 422 `{code:1,error}`; sukses ⇒ flash + redirect.

### 8j.8 Prasyarat deploy (WAJIB)

DNS A/AAAA domain parkir mengarah ke server ini. `PARKED_ROOT` **absolut dan sama persis di host** (Nginx host membaca `root`); path relatif ⇒ 404 senyap; `$` ditolak. Direktori `0755`, `index.html` `0644`, seluruh komponen path traversable user nginx host. Host meng-include `sites-enabled/*.conf`. SSL parkir memakai prasyarat §8a (ADMIN_EMAIL, mount cert, webroot, port 80/443); domain non-publik ditolak sebelum spawn.

### 8j.9 Batasan backup (WAJIB dicatat)

Snapshot SQLite hanya memuat **metadata** parkir; konten `database/parked/{id}/index.html` **tidak** ikut. Restore DB saja ⇒ entri kembali tetapi mode `html` 404 tanpa peringatan. Backup host/berkas wajib mencakup `database/parked/`; pulihkan metadata + konten dari titik waktu yang sama, lalu reload worker, lalu uji satu domain html. Mode `redirect` tidak terpengaruh.

### 8j.10 Kriteria uji

Cakupan minimum: validasi domain/redirect/ukuran/kode, keunikan dua arah dengan app & entri lain, transaksi create/update/delete + rollback, render Nginx (html/redirect/SSL/ACME/fallback cert hilang/path berbahaya), SSL parkir (pending sebelum enqueue, gagal enqueue, reconcile, expiry dipertahankan), guard admin-only 404, filter tanpa menambah baris tak berhak, dan route `GET /ssl` redirect.

### 8j.11 Future work

Dukungan multi-server (kolom `server_id` sudah ada), backup konten terintegrasi, SSL wildcard, alias `www` otomatis, dan penyegaran `ssl_expires_at` setelah renewal — lihat §12.

## 9. Environment Variables (`.env`)

Daftar lengkap, default, dan komentar ada di **`../DETAILS.md#konfigurasi-env`**; jangan menduplikasi tabel di sini. Yang mengikat: **namespace terpisah dan DILARANG dicampur** — `VOLUME_BACKUP_*`/`RESTIC_*`/`AWS_*` (volume app) vs `DB_BACKUP_*`/`RAMES_DB_*` (SQLite dashboard) vs `BACKUP_*` (usang). **Rahasia tidak pernah di repo/log/UI**: `AGENT_TOKEN`, `BILLING_DUITKU_API_KEY`, kredensial S3, passphrase restic; token/PEM agent tidak ditampilkan ulang. Agent membaca env-nya sendiri (`secrets/agent.env`, 0600); control plane tidak mengirim kredensial lewat payload. `ADMIN_EMAIL` wajib di **setiap** server yang menerbitkan sertifikat. `APP_DOMAIN`/`PORT_RANGE_*` adalah sumber kebenaran server `local`; `servers.domain`/`port_range` untuk server agent. Perubahan env butuh **recreate** container (bukan restart) karena dibaca saat start. `RAMES_MODE=dashboard|agent`; tanpa `AGENT_TOKEN` di mode agent, route agent tidak didaftarkan (fail-closed).

## 10. Struktur Direktori

Struktur rinci ada di `./ARCHITECTURE.md` dan `../DETAILS.md#struktur-direktori-ringkas`. Invarian: data domain hanya di SQLite dalam named volume `rames`; berkas JSON lama hanya bukti impor (tidak dihapus). `database/keys/`, `database/env/`, `database/restic/password` tetap berkas terpisah dengan izin ketat. `apps/{name}` = artefak app (compose + override), bukan sumber kebenaran metadata. `runtime/**` = cache/status/log/transfer (boleh dibangun ulang, tidak ikut backup DB). `templates/<slug>/` = aset repo katalog; `database/parked/` = konten parkir (di luar snapshot DB). Unit systemd & script host (`host/`) dikelola installer; agent tidak memasang timer backup.

## 11. Security Considerations

- **Password** bcrypt; tidak pernah plaintext di store/log/UI. Session Webman; **CSRF wajib untuk semua mutasi** (pengecualian hanya callback pembayaran & proxy Adminer, keduanya path-presisi).
- **Otorisasi sebelum efek samping**; akses tidak sah = **404**; satu pintu `AppAccess`/`BackupAccess`/`ServerAccess`; tombol tersembunyi bukan pengaman.
- **DILARANG command shell string**: semua proses eksternal array + `bypass_shell` (`ProcessRunner`). Command yang exit code-nya dibaca wajib lewat `SigchldGuard`.
- **Spawn worker**: gunakan `DetachedWorker` (`pcntl_fork` + `pcntl_exec`, tanpa shell) karena `proc_open` + `proc_close` memblokir request sampai worker selesai. `proc_open` hanya untuk proses yang memang ditunggu.
- **SQL** hanya lewat `SqliteStore`/klien internal dengan parameter binding; tidak ada string SQL dari input.
- **Path traversal**: semua path dinormalisasi (`PathGuard`, `realpath`); nama entri bebas `/` dan awalan `-`; id parkir regex ketat; ekstrak arsip anti zip-slip.
- **Secret**: tidak ada di repo, log, UI, payload job, argv, atau respons JSON; berkas kredensial 0600; respons/temp Adminer 0600.
- **File manager** berjalan `docker exec -u 0` (konsekuensi diterima; batas ability `files` + audit); hanya container berjalan; bukan akses volume langsung.
- **Nginx**: dashboard hanya menulis direktori config terbatas; config invalid memblokir reload host ⇒ alur transaksional + rollback wajib; Nginx mentah dari user dilarang.
- **Domain parking**: konten HTML tepercaya admin; CRUD/SSL admin-only; validasi ulang saat render; id aman sebelum filesystem.
- **Callback publik**: HMAC-SHA256 + amount + idempotensi; tanpa data sensitif di respons; API key disanitasi.
- **Multi-server**: TLS pinning (bukan `verify=false`); token tidak ditampilkan ulang; port 8443 hanya untuk control plane; grant server tidak membuka data user lain; id server tak dikenal ≡ bukan milik user.
- **Billing**: gerbang kredit bukan otorisasi; admin exempt; transfer app ke admin menghentikan app agar tidak ada pemakaian gratis.
- **Rate-limit login**: belum diterapkan (§8f); jangan mengekspos dashboard ke internet publik tanpa itu.
- **Log/UI**: jangan mencetak environment worker/proses; sanitasi galat agent sebelum ditulis.

## 12. Future Work

Sisa pekerjaan **aktual** (yang sudah selesai tidak dicantumkan):

- **Gateway Docker generik `/docker/*`** di agent — saat ini operasi per-resource memakai route khusus.
- **Purge volume server agent** — belum ada op/job; `purge_orphans` lokal-only, baris agent `can_purge=false`.
- **Field volume agent** (driver/mountpoint/dibuat/ukuran) — kontrak `GET /backups/volumes` masih `{name, project, app_id, orphaned}`.
- **Higienitas pesan galat worker** — `apps.error`/log deploy, `log_tail` template pull, dan log billing `AppStopper` masih bisa memuat galat agent mentah.
- **Snippet Nginx mentah per app (Lapis B)** dan **lock per-app** untuk penyimpanan rute bersamaan; guard rollback belum mencakup jalur tulis config langsung (domain/SSL).
- **Monitoring historis/alerting** dan **log viewer SSE + buffer historis**.
- **Billing**: refund/void top-up, rate limiting khusus top-up, rekonsiliasi berbasis riwayat Docker `StartedAt` untuk menutup under-count saat dashboard mati.
- **Rootless Podman** sebagai pengganti Docker socket.
- **Domain parking**: dukungan multi-server, backup konten parkir terintegrasi, SSL wildcard, alias `www` otomatis, dan penyegaran `ssl_expires_at` setelah renewal.
- **Permission granular per-resource** (di luar 4 role tetap).
- **Rate limiting login** (§8f) dan **pagination daftar app** (`SITES_PER_PAGE`) — belum diimplementasikan, bukan fitur yang sudah ada.
