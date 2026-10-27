<p align="center">
  <img src="https://image.web.id/images/logo-rames.png" alt="Rames Logo" width="140">
</p>

# Rames — Deploy Dashboard

**Rames** adalah dashboard deployment berbasis Docker untuk mengelola app dari repo Git, file `docker-compose.yml`, atau template siap-pakai — lengkap dengan reverse proxy Nginx, SSL Let's Encrypt, monitoring, backup volume, dan multi-server (control plane + agent). Dibangun dengan **Webman (PHP 8.1+)** dan berjalan di dalam container Docker.

> Detail lengkap (instalasi manual, konfigurasi, multi-server, backup, keamanan): [`DETAILS.md`](./DETAILS.md) · Spesifikasi: [`SPECS.md`](./docs/SPECS.md) · Arsitektur: [`ARCHITECTURE.md`](./docs/ARCHITECTURE.md)

## Fitur Singkat

- **Multi-user & sharing** — role global admin/member, kepemilikan app, dan sharing per app (viewer/operator/owner).
- **Create & deploy app** — dari URL repo Git, compose paste/upload, atau template; build & `docker compose up` berjalan async.
- **Domain & SSL** — subdomain otomatis, custom domain, SSL Let's Encrypt per domain, serta domain parking.
- **Operasional container** — rebuild/stop/start/delete, log, terminal, file manager, dan batas CPU/RAM per service.
- **Database & backup** — Adminer tanpa port publik; backup volume harian ke S3 via restic (dump logis untuk database).
- **Monitoring** — resource container dan total VM, sadar-server.
- **Multi-server** — satu dashboard sebagai control plane mengelola banyak server agent; pilihan server untuk admin (dan member yang diberi izin).
- **Kredit & penagihan** — pemakaian CPU/RAM serta penyimpanan snapshot backup untuk role member, dengan top-up manual/Duitku.
- **Branding, tema, dan self-update** — ganti nama/logo, mode gelap, serta update dashboard dari UI.

## Prasyarat Minimum (Instalasi Satu Perintah)

- **OS:** Ubuntu 22.04/24.04 (utama) atau Debian (fallback); arsitektur `x86_64`/`aarch64`.
- **Akses:** user dengan `sudo`/root.
- **Jaringan:** port **80/443** dan port dashboard (default `8000`) bebas; rentang host port app default `30000–30999`.
- **DNS:** record `A`/`AAAA` untuk domain dashboard **dan** wildcard `*.<domain>` diarahkan ke IP server.
- **Dependensi host dipasang otomatis** oleh installer: Docker Engine + Compose v2 (BuildKit, Docker 23+), Nginx, certbot, `inotify-tools`.
- **Opsional:** kredensial Cloudflare (SSL DNS-01) dan bucket S3 + kredensial (backup restic).

## Instalasi Dashboard (Satu Perintah)

```bash
curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.sh -o /tmp/rames-install.sh \
  && sudo bash /tmp/rames-install.sh
```

Wizard akan menanyakan domain, email admin, port dashboard, dan rentang port app; kredensial admin dicetak **sekali** di akhir. Untuk provisioning non-interaktif:

```bash
curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.sh -o /tmp/rames-install.sh
sudo bash /tmp/rames-install.sh --non-interactive --domain example.com --email admin@example.com
```

Setelah selesai, buka `https://<domain>` (atau `http://<ip>:<APP_PORT>`) dan login.

> Instalasi manual langkah demi langkah, opsi installer, `.env`, dan update: [`DETAILS.md`](./DETAILS.md#instalasi--menjalankan).

## Instalasi Server Agent (Satu Perintah)

Di server tambahan (wildcard DNS `*.<domain>` sudah mengarah ke server itu):

```bash
curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.agent.sh -o /tmp/rames-install-agent.sh \
  && sudo bash /tmp/rames-install-agent.sh --domain agent.example.com --email ops@example.com
```

Default instalasi di `/opt/rames-agent`; agent **menolak** menimpa direktori dashboard. Setelah installer mencetak blok onboarding (URL, token, fingerprint, PEM), tambahkan server di dashboard lewat **Servers → Add Server**, lalu **Test connection**.

> Detail multi-server, firewall, dan rotasi token/sertifikat: [`DETAILS.md`](./DETAILS.md#menambah-server-kedua) · [`agent/README.md`](./agent/README.md).

## Dokumentasi Lain

- Konfigurasi `.env`, backup, keamanan, alur create app, dan struktur direktori: [`DETAILS.md`](./DETAILS.md)
- Spesifikasi kebutuhan: [`SPECS.md`](./docs/SPECS.md)
- Arsitektur dan keputusan desain: [`ARCHITECTURE.md`](./docs/ARCHITECTURE.md)
- Panduan server agent: [`agent/README.md`](./agent/README.md)

## Troubleshooting

Masalah umum (container gagal start, port bentrok, SSL gagal, subdomain lokal, update dashboard) beserta penyebab dan penangkalnya ada di [`DETAILS.md`](./DETAILS.md#troubleshooting-umum).
