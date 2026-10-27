# Rames — paket AGENT (server #2..N)

Server **agent** = server tempat app benar-benar berjalan (Docker Engine + Nginx
native + certbot). Ia dikendalikan dashboard lewat HTTPS di port agent sendiri.
Kode & image-nya **sama** dengan dashboard; yang berbeda hanya mode runtime
(`RAMES_MODE=agent`): route dashboard tidak didaftarkan, timer dashboard tidak
dipasang, dan listener-nya TLS di port agent sendiri (`config/process.php`).

Berkas di direktori ini:

| Berkas | Isi |
|---|---|
| `install.sh` | installer idempoten: rahasia, sertifikat TLS, `docker compose up -d --build`, blok onboarding |
| `../docker-compose.agent.yml` | paket compose **terpisah** (project `rames-agent`, container `rames-agent`, volume `rames-agent-data`) |

Project & nama container berbeda dari dashboard (`rames` / `rames-webman`), jadi
menjalankan paket ini di host yang sama dengan dashboard **aman** — jangan pernah
`docker compose down` project lain dari sini.

## Prasyarat (disiapkan manual, di luar dashboard)

1. Docker Engine + plugin `docker compose` **v2.6+** (Compose override port
   memakai tag `!reset`). User yang menjalankan installer harus bisa bicara
   dengan daemon (grup `docker`, atau jalankan installer sebagai root).
2. `openssl` (token agent + sertifikat TLS self-signed).
3. Nginx native di host + `certbot` (dipakai saat app di server ini melayani
   domain & SSL). Keduanya boleh dipasang setelah agent hidup. **Paket
   `certbot` polos sudah cukup** — Rames memanggilnya dengan `--webroot`
   (HTTP-01) atau `--dns-cloudflare` (DNS-01); **plugin nginx
   (`python3-certbot-nginx`) tidak dipakai** (mode DNS-01 butuh plugin DNS
   Cloudflare, mis. `python3-certbot-dns-cloudflare` di Debian/Ubuntu). Lihat
   [Prasyarat SSL](#prasyarat-ssl-sertifikat-app-di-server-ini).
4. Wildcard DNS `*.<APP_DOMAIN>` mengarah ke IP server ini (keputusan 4).
5. **Port web publik 80 & 443 terbuka untuk umum** di firewall server ini.
   Ini prasyarat yang **berbeda** dari port agent (lihat
   [Firewall](#firewall-wajib)):
   HTTP-01 divalidasi Let's Encrypt dari IP acak, jadi allowlist IP di port 80
   **selalu** menggagalkan penerbitan sertifikat; port 443 dipakai app ber-SSL.
6. Repo ini di-checkout di server ini (mis. `/srv/rames-agent`).

Installer **tidak memasang paket apa pun** dan **tidak mengedit `nginx.conf`**
(hanya memperingatkan bila `sites-enabled/*` belum di-`include`).

### Prasyarat SSL (sertifikat app di server ini)

Penerbitan SSL app di server ini dijalankan **di server ini**, bukan di
dashboard: tombol SSL → `AgentDeployer::issueSsl()` (`app/library/Deploy/AgentDeployer.php:1542`)
→ job `ssl_issue` → `cli/deploy.php` mode `ssl_issue` → `SslIssuer` dengan
**konfigurasi server ini**.

- **Port web publik `80` & `443` terbuka di firewall server ini** — HTTP-01
  `--webroot` divalidasi Let's Encrypt **dari internet**, jadi allowlist IP di
  port 80 selalu berakhir `Timeout during connect (likely firewall problem)`
  (rincian & perintah: [Firewall](#firewall-wajib)). Port 443 dipakai app ber-SSL.
- **`ADMIN_EMAIL` WAJIB ada di setiap server yang menerbitkan sertifikat**,
  termasuk server agent. `SslIssuer::buildCommand()`
  (`app/library/SSL/SslIssuer.php:514-518`) menolak (`ADMIN_EMAIL belum diatur —
  wajib untuk penerbitan sertifikat Let's Encrypt.`) bila
  `config('deploy.admin_email')` kosong; env-nya `ADMIN_EMAIL`
  (`config/deploy.php:136`). Tanpa nilai itu job `ssl_issue` di server ini
  **selalu** gagal.
- **Sumbernya `secrets/agent.env` (0600) di server ini** — bukan `.env`
  dashboard, dan **bukan** blok `environment:` `docker-compose.agent.yml`
  (`environment` menang atas `env_file`, lihat "Rahasia & sumber konfigurasi").
- Kunci SSL lain yang juga tinggal di `secrets/agent.env`: `SSL_CHALLENGE`
  (`http` = webroot default | `dns-cloudflare`), `SSL_CA_SERVER`
  (`production` | `staging`), `CLOUDFLARE_CREDS` (path berkas berisi
  `dns_cloudflare_api_token = <token>` — **isi berkasnya**, jangan menaruh token
  di `agent.env`).

- **Webroot HTTP-01 (`SSL_WEBROOT`) wajib *traversable* oleh user nginx host**
  (bit `x` pada setiap komponen path) **dan** terlihat di dalam container
  (certbot menulis berkas dari dalam container, nginx host menyajikannya dari
  luar). Webroot di balik direktori `0700` (mis. instalasi di bawah `/root/...`)
  selalu berakhir `403`. `SslIssuer` kini menolaknya lebih dulu lewat preflight
  berbasis bit mode — jadi setup grup yang sah (`0750 root:www-data`) tetap lolos.
  Diagnosa lengkap & jalan keluar: [`docs/ARCHITECTURE/09-gotcha-multiserver.md`](../docs/ARCHITECTURE/09-gotcha-multiserver.md) GOTCHA no. 28.
- **Opsional — persist log debug certbot.** Set `CERTBOT_LOGS_DIR` (dan/atau
  `CERTBOT_WORK_DIR`) **di wilayah operator** `secrets/agent.env` ke path yang
  di-bind, mis. `CERTBOT_LOGS_DIR=<repo>/runtime/logs/letsencrypt`, supaya log
  diagnosa challenge tidak hilang saat container di-recreate (default
  `/var/log/letsencrypt` = lokal container). Nilai kosong/relatif/ber-NUL
  dinormalkan ke default. Perubahan env ⇒ **recreate** (`docker compose … up -d`),
  bukan `restart`.

**Cara 1 — lewat installer (disarankan).** Installer menulis kunci SSL itu ke
`secrets/agent.env` sekaligus lalu menjalankan compose:

```bash
./agent/install.sh --domain domain2.com --email ops@domain2.com \
  [--ssl-challenge http|dns-cloudflare] [--ssl-ca-server production|staging] \
  [--cloudflare-creds /path/cloudflare.ini]
```

Idempoten: nilai lama di `secrets/agent.env` **dipertahankan** bila opsinya tidak
diberikan. `--cloudflare-creds` wajib path **absolut** (mulai `/`) yang lolos
whitelist `assert_env_safe()` (huruf, angka, `. _ % + @ / : = -` — tanpa
spasi/kutip). Bila `--ssl-challenge dns-cloudflare` diberikan tanpa creds
installer hanya **memperingatkan**; `ADMIN_EMAIL` kosong juga hanya
**memperingatkan** (`ADMIN_EMAIL belum diatur — job SSL (ssl_issue) akan GAGAL
sampai diisi …`) — bukan menggagalkan pemasangan; jalankan ulang dengan `--email`.

**`SSL_WEBROOT` aman di wilayah operator.** Tambahkan baris `SSL_WEBROOT=…` **di
luar** blok kelolaan (di bawah `# rames-installer:preserved`): rerun
`./agent/install.sh` **tidak menghapusnya** (lihat [Rahasia & sumber
konfigurasi](#rahasia--sumber-konfigurasi)). Ini jalur perbaikan HTTP-01 `403`
**tanpa** mengubah compose — diagnosa lengkapnya di [`docs/ARCHITECTURE/09-gotcha-multiserver.md`](../docs/ARCHITECTURE/09-gotcha-multiserver.md) GOTCHA
no. 28.

**Cara 2 — manual** (mis. server sudah terpasang lebih dulu):

```bash
# di server agent, dari direktori paket agent
grep -n '^ADMIN_EMAIL=' secrets/agent.env   # cek dulu: hindari kunci ganda
printf 'ADMIN_EMAIL=ops@example.com\n' >> secrets/agent.env
chmod 600 secrets/agent.env
docker compose -f docker-compose.agent.yml -p rames-agent up -d
docker exec rames-agent sh -lc 'test -n "$ADMIN_EMAIL" && echo "ADMIN_EMAIL: terisi" || echo "ADMIN_EMAIL: KOSONG"'
```

Baris yang ditambahkan di akhir berkas jatuh ke **wilayah operator** dan tetap
terbaca `env_file`; rerun installer berikutnya menormalkannya (nilai dibaca lebih
dulu, tulis di blok kelolaan, salinan lama dibuang) sehingga tidak menumpuk.

**Perubahan env butuh *recreate*, bukan `restart`.** `env_file` disuntikkan saat
container **dibuat**, jadi `docker compose … restart` **tidak** menerapkan nilai
baru. Cukup jalankan ulang:

```bash
docker compose -f docker-compose.agent.yml -p rames-agent up -d
```

Compose memperlakukan **isi** `env_file` sebagai bagian konfigurasi service ⇒
container **di-recreate** (ID container berubah) dan variabel baru muncul di
dalamnya; `./agent/install.sh` juga berakhir dengan langkah ini. **Terbukti**
pada Compose **v5.5.1** (versi terpasang di mesin pengembangan proyek ini; syarat
minimum proyek tetap `v2.6+`, `agent/README.md` §Prasyarat) dengan project tiri
(`network_mode: none`, image `debian:bookworm-slim`): isi `agent.env` diubah →
`up -d` tanpa flag tambahan ⇒ container `Recreated`. `--force-recreate`
**hanya** untuk memastikan (atau pada Compose lama yang menganggap `env_file`
sekadar path, bukan isi) — menambahkannya tidak berbahaya:

```bash
docker compose -f docker-compose.agent.yml -p rames-agent up -d --force-recreate
```

**DILARANG** memakai `./agent/install.sh --force` hanya untuk mengubah env: opsi
itu **merotasi token + sertifikat TLS agent** sehingga server harus diperbarui
lagi di dashboard (`--force` memang menambahkan `--force-recreate` ke compose) —
pakai `docker compose … up -d --force-recreate`.

## Langkah

```bash
git clone <repo> /srv/rames-agent && cd /srv/rames-agent
./agent/install.sh --domain domain2.com --email ops@domain2.com --port-range 30000 30999
```

Apa yang terjadi:

1. prasyarat diperiksa & dilaporkan;
2. dibuat `secrets/agent.env` (0600) + sertifikat TLS self-signed
   `secrets/agent/tls.{crt,key}` (`CN=rames-agent`, SAN `IP:<ip-server>`
   `IP:127.0.0.1`, `DNS:<hostname>`, `DNS:localhost`, 825 hari);
3. `docker compose -f docker-compose.agent.yml -p rames-agent up -d --build`;
4. `GET https://127.0.0.1:8443/version` dipanggil (dengan header token) sebagai
   verifikasi;
5. **blok onboarding** dicetak sekali: URL, token, fingerprint SHA-256, versi
   agent (`Version : rames-agent <versi>`, mis. `rames-agent 0.1.0` — dibaca dari
   const `AgentController::VERSION` → `AgentVersionGate::CODEBASE_VERSION`), dan
   PEM sertifikat di antara penanda
   `-----BEGIN ONBOARDING PEM-----` / `-----END ONBOARDING PEM-----`.

Lalu di dashboard (admin): **Servers → Add Server** → isi name/URL/token +
**paste PEM** di blok onboarding (`APP_DOMAIN`, port range) → **Test connection**.
Dashboard menghitung fingerprint sendiri dari PEM itu (tidak ada peluang salah
ketik) dan menolak tegas bila fingerprint tidak cocok.

### Opsi installer

| Flag | Arti |
|---|---|
| `--domain <d>` | `APP_DOMAIN` server ini. Wajib pada instalasi pertama. |
| `--email <e>` | `ADMIN_EMAIL` — kontak penerbitan SSL Let's Encrypt **di server ini** (wajib sebelum job `ssl_issue` bisa jalan; lihat [Prasyarat SSL](#prasyarat-ssl-sertifikat-app-di-server-ini)). Nilai lama dipertahankan bila opsi tidak diberikan atau diberikan kosong (`--email ""`). |
| `--port-range <s> <e>` | rentang host port container app (default `30000 30999`). |
| `--bind <ip:port>` | `AGENT_BIND` — alamat listen agent **di dalam container** (default `0.0.0.0:8443`). **Wajib bentuk `IP:PORT`**: nilai tanpa `:` ditolak **fail-fast** (mis. `0.0.0.0/` — dulu lolos lalu membuat container agent **crash saat start** — `config/process.php` menuntut `:`); IPv4 cacat (`1.2.3`, `300.1.1.1`) & IPv6 tanpa kurung ditolak. Skema `http(s)://` **dan** garis miring penutup (`/`, boleh berulang) **ditoleransi** runtime — keduanya dibuang saat parsing `host:port`, nilainya tetap ditulis **apa adanya** ke `secrets/agent.env`, dan hanya memicu **satu** peringatan agar dirapikan (port untuk cek `AGENT_PORT_PUBLISH` = port **hasil parsing**: `0.0.0.0:9000/` ⇒ `9000`). Port selain 8443 hanya boleh bila `AGENT_PORT_PUBLISH` diset sama (lihat di bawah). |
| `--ip <ip>` | IP server ini untuk SAN sertifikat & URL onboarding (pakai bila deteksi otomatis gagal/salah). |
| `--ssl-challenge <http\|dns-cloudflare>` | `SSL_CHALLENGE` (default `http` = webroot HTTP-01). |
| `--ssl-ca-server <production\|staging>` | `SSL_CA_SERVER` (`staging` untuk uji — sertifikat tidak dipercaya). |
| `--cloudflare-creds <path>` | `CLOUDFLARE_CREDS` — path **absolut** (wajib mulai `/`, tanpa spasi/kutip) ini-file kredensial Cloudflare untuk `--dns-cloudflare`; harus **terbaca container agent** (paling aman di bawah path repo, karena repo di-mount di path yang sama). Tidak terbaca **di host** hanya **diperingatkan** — yang menentukan adalah keterbacaan **di dalam container** (path di container bisa berbeda, mis. berkas disalin/di-mount ke tempat lain). |
| `--force` | regenerasi token + sertifikat, lalu force-recreate container. **Perbarui server di dashboard setelahnya.** |
| `--help` | bantuan. |

Idempoten: dijalankan ulang tanpa `--force`, token/sertifikat/nilai yang sudah
ada di `secrets/agent.env` dipertahankan (dan blok onboarding dicetak ulang
dengan token yang sama).

Validasi **fail-fast sebelum efek samping apa pun**: `--domain` dinormalkan
(huruf kecil, tanpa skema/path/port, pola label FQDN), `--port-range` wajib
1..65535 dengan `start < end`, `--ip` wajib **IPv4** (nilainya masuk SAN
sertifikat), `--cloudflare-creds` wajib **path absolut**. Pesan galat menyebut
**asal nilai** — opsi CLI (`--email`, `--domain`, …) atau baris di
`secrets/agent.env` — beserta contoh perbaikannya.

## Firewall (wajib)

Ada **dua kelompok port** dengan kebijakan berlawanan — keduanya wajib diatur,
dan blok onboarding yang dicetak `agent/install.sh` mengingatkan keduanya:

| Port | Siapa yang boleh | Kalau salah |
|---|---|---|
| agent (default `8443`) | **hanya IP control plane** | pintu eksekusi Docker terbuka ke publik |
| web `80` + `443` | **semua** (jangan allowlist IP) | SSL gagal `Timeout during connect` / app tak bisa diakses |

### 1. Port agent — batasi ke IP control plane

Port agent (default `8443`) adalah pintu eksekusi Docker di server ini — **batasi
hanya untuk IP control plane**, jangan pernah dibuka ke publik:

```bash
sudo ufw allow from <ip-control-plane> to any port 8443 proto tcp
# atau
sudo iptables -A INPUT -p tcp --dport 8443 -s <ip-control-plane> -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 8443 -j DROP
```

### 2. Port web publik — buka ke semua

Nginx host melayani app di port `80`/`443`, dan certbot memvalidasi tantangan
**HTTP-01 dari IP acak milik Let's Encrypt** (bukan dari dashboard). Allowlist IP
di port 80 karena itu **pasti** membuat penerbitan sertifikat gagal
(`Timeout during connect (likely firewall problem)`, [GOTCHA multi-server
no. 52](../docs/ARCHITECTURE/09-gotcha-multiserver.md))
walaupun `SSL_WEBROOT`/nginx/DNS sudah benar:

```bash
sudo ufw allow 80,443/tcp
# atau
sudo iptables -A INPUT -p tcp -m multiport --dports 80,443 -j ACCEPT
```

Cloud Firewall (mis. DigitalOcean): tambahkan inbound **HTTP (80)** dan
**HTTPS (443)** dari `All IPv4` + `All IPv6`.

Verifikasi **dari mesin luar** server ini (harus keluar kode HTTP — `200`/`301`/
`404` sama-sama berarti port tembus; yang salah adalah *timeout*):

```bash
curl -sS -m 8 -o /dev/null -w '%{http_code}\n' http://<app>.<APP_DOMAIN>/
```

Bila port 80 memang tidak boleh publik, gunakan DNS-01
(`SSL_CHALLENGE=dns-cloudflare` + `CLOUDFLARE_CREDS`, lihat
[Prasyarat SSL](#prasyarat-ssl-sertifikat-app-di-server-ini)) — port 443 tetap
wajib terbuka agar app ber-SSL bisa diakses.

TLS diterminasi **di proses agent** (bukan Nginx host): sertifikat yang di-pin di
dashboard adalah sertifikat agent, bukan sertifikat Nginx (keputusan 6).

## Rahasia & sumber konfigurasi

- `secrets/agent.env` (**0600**, tidak masuk repo — `/secrets/` di `.gitignore`)
  adalah sumber tunggal identitas + rahasia agent: `AGENT_TOKEN`,
  `AGENT_TLS_CERT`, `AGENT_TLS_KEY`, `AGENT_BIND`, `APP_DOMAIN`,
  `PORT_RANGE_START/END`, `RAMES_MODE`, ditambah kunci SSL milik server ini
  (`ADMIN_EMAIL`, opsional `SSL_CHALLENGE`/`SSL_CA_SERVER`/`CLOUDFLARE_CREDS` —
  lihat [Prasyarat SSL](#prasyarat-ssl-sertifikat-app-di-server-ini)). Diinjeksi
  lewat `env_file: ./secrets/agent.env`.
- **Jangan** menambahkan key identitas itu ke blok `environment:` di
  `docker-compose.agent.yml`: Compose selalu memenangkan `environment` atas
  `env_file`, sehingga nilai placeholder (`example.com`, rentang port bawaan)
  akan menimpa nilai nyata dan agent diam-diam salah domain.
- **Dua wilayah di dalam berkas**, dipisahkan sentinel (selalu ditulis `agent/install.sh`):
  - **blok kelolaan** — `# rames-installer:begin` … `# rames-installer:end`. Isinya
    **hanya kunci terkelola**: `RAMES_MODE`, `AGENT_TOKEN`, `AGENT_BIND`,
    `AGENT_TLS_CERT`, `AGENT_TLS_KEY`, `APP_DOMAIN`, `PORT_RANGE_START`,
    `PORT_RANGE_END`, plus `ADMIN_EMAIL`/`SSL_CHALLENGE`/`SSL_CA_SERVER`/
    `CLOUDFLARE_CREDS` (ditulis bila opsinya ada / nilai lama ada). **DILARANG**
    menaruh penyetelan manual di dalam blok ini — ditimpa tiap rerun.
  - **wilayah operator** — baris setelah `# rames-installer:preserved`, disalin
    **verbatim** (komentar, baris kosong, `SSL_WEBROOT`, `LETSENCRYPT_PATH`, kunci
    kustom apa pun; nilai ber-spasi/kutip tidak pernah di-parse ulang). Penanda ini
    hanya muncul bila memang ada baris operator.

```ini
# rames-installer:begin (blok kelolaan — diperbarui tiap installer dijalankan)
RAMES_MODE=agent
AGENT_TOKEN=agt_…
AGENT_BIND=0.0.0.0:8443
AGENT_TLS_CERT=/srv/rames-agent/secrets/agent/tls.crt
AGENT_TLS_KEY=/srv/rames-agent/secrets/agent/tls.key
APP_DOMAIN=domain2.com
PORT_RANGE_START=30000
PORT_RANGE_END=30999
ADMIN_EMAIL=ops@domain2.com
# rames-installer:end

# rames-installer:preserved  ↓ baris di bawah TIDAK dikelola installer
# rames-installer:preserved    (mis. SSL_WEBROOT, LETSENCRYPT_PATH, kunci kustom)
SSL_WEBROOT=/srv/rames-agent/webroot
```

- **Rerun installer aman (idempoten)** — kunci terkelola diperbarui (opsi CLI
  menang; tanpa opsi = nilai lama dari berkas), **baris operator dipertahankan**,
  **tanpa kunci duplikat** (salinan kunci terkelola yang tertulis manual di wilayah
  operator dibuang), mode tetap `0600`, dan hasilnya **byte-identik** pada rerun
  berikutnya. Berkas **format lama (tanpa sentinel) dinormalisasi pada rerun
  pertama** — nilai operator tidak hilang, hanya berpindah ke wilayah operator,
  dan **komentar header installer versi lama dibuang** (pencocokan persis per baris
  — komentar operator yang mirip secara kebetulan tetap dipertahankan).
- **Jangan menyunting `secrets/agent.env` dengan editor Windows (Notepad/WordPad).**
  Berkas ber-CRLF membuat `sed` membaca `\r` sebagai bagian nilai (dulu rerun
  **gagal** dengan pesan menyesatkan). Installer kini menormalkan ke LF + hanya
  **memperingatkan**; tetap hindari editor Windows agar tidak terulang.
- **`SSL_WEBROOT` harus terlihat di DUA sisi**: container agent yang menulis berkas
  challenge (certbot) dan nginx host yang menyajikannya. Compose agent hanya
  mem-bind **`${PWD}:${PWD}`** (`docker-compose.agent.yml`), jadi direktori itu
  **wajib di dalam path repo** (default `{proyek}/webroot`); memindahkannya ke luar
  repo (mis. `/var/www/...`) menuntut **bind mount tambahan** di compose — wilayah
  Deploy & Docker, bukan pemasangan biasa.
- `RAMES_MODE=agent` ditanam literal di compose **dan** di `secrets/agent.env`
  supaya agent tidak pernah bisa start sebagai dashboard penuh.
- Jalur sertifikat ditulis sebagai path absolut host: karena `"${PWD}:${PWD}"`,
  path yang sama valid di host maupun di container.
- Rotasi: `./agent/install.sh --force` → token & sertifikat baru → perbarui di
  dashboard.

## Operasi harian

```bash
# status & log
docker compose -f docker-compose.agent.yml -p rames-agent ps
docker compose -f docker-compose.agent.yml -p rames-agent logs webman --tail 100

# versi (dengan token; -k karena self-signed, atau --cacert secrets/agent/tls.crt)
curl -sS --cacert secrets/agent/tls.crt -H "Authorization: Bearer <token>" https://<ip>:8443/version

# update kode agent (rebuild image yang sama)
git pull && ./agent/install.sh
```

Catatan: `AGENT_PORT_PUBLISH` (port publish di host) hanya dibaca Compose dari
environment/`.env` project, bukan dari `secrets/agent.env` — karena itu selalu
start lewat `./agent/install.sh`. Bila port agent diubah dari 8443, ubah **dua**
tempat agar konsisten: `--bind`/`AGENT_BIND` dan `AGENT_PORT_PUBLISH`.

Database SQLite agent (`/var/lib/rames`, volume `rames-agent-data`) hanya berisi
**proyeksi** app yang ditugaskan — bukan kebenaran, tidak ikut di-backup
dashboard (label volume `rames.role=agent-db`).
