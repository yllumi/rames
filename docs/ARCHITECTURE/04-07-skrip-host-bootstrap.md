# 4.7 Skrip Host & Bootstrap

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

Skrip shell **repo** (bukan `app/library/`, bukan data runtime) — dua lapis dengan satu arah delegasi:

| Berkas | Peran |
|---|---|
| `install.sh` (root) | **Bootstrap one-liner dashboard** (`curl … \| sudo bash`): cek Ubuntu/Debian + arsitektur, pasang paket dasar untuk clone (`ca-certificates`, `curl`, `git`, `gnupg`, `lsb-release`, `openssl`), clone `--depth=1` ke `/opt/rames` atau pakai ulang checkout, lalu `exec host/install.sh` dengan argumen diteruskan utuh. **Tidak** memasang Docker/Nginx/certbot sendiri (§5.39). |
| `install.agent.sh` (root) | **Bootstrap one-liner server agent** (peran `RAMES_MODE=agent`, §5.21): guard OS/arsitektur, paket dasar, clone ke `/opt/rames-agent` (`--dir`/`--ref`/`--repo`), tolak direktori instalasi dashboard, prompt identitas idempoten, `host/install.sh --deps-only`, lalu `exec agent/install.sh` dengan flag agent diteruskan utuh. **Mandiri** — tidak `source` berkas repo (skrip dijalankan sebelum clone) dan tidak mencetak secret (§5.41). |
| `host/install.sh` | **Installer host**: dependensi host (nginx, inotify-tools, certbot + plugin Cloudflare), Docker Engine + Compose v2 (repo resmi → fallback distro), wizard/validasi `.env`, direktori & include Nginx, `docker compose up -d --build`, delegasi `host/create-admin.sh`, unit systemd (watcher, certbot, volume backup), ringkasan (§5.39). Mode **`--deps-only`**: hanya dependensi host lalu keluar **sebelum** wizard `.env`, preflight, `compose up`, dan unit systemd (dipakai `install.agent.sh`, §5.41); `--deps-only --env-only` ditolak. |
| `host/create-admin.sh` | Provisioning user admin pertama — menunggu `GET /healthz` siap sebelum `php webman make:admin` (§5.5 langkah 0). |
| `host/nginx-reload-watcher.sh` + `host/systemd/dashboard-nginx-watcher.*` | Watcher reload Nginx (§8.3). |
| `host/certbot-renew.sh` + `host/systemd/certbot-renew.*` | Renewal sertifikat Let's Encrypt (§8a). |
| `host/backup.sh` + `host/systemd/volume-backup.*` | Timer backup volume harian (§5.15). |
| `host/restic-setup.md` | Panduan setup restic yang dirender di UI `/backups/guide` (§5.15). |

> **WAJIB — bootstrap tidak menduplikasi logika installer.** Root `install.sh` hanya menyiapkan prasyarat clone + delegasi; seluruh keputusan paket, wizard `.env`, dan orkestrasi service ada di `host/install.sh`. Menambah langkah instalasi di root membuat langkah itu hilang pada alur "checkout yang sudah ada" (`sudo ./host/install.sh`). Hal yang sama berlaku untuk `install.agent.sh`: ia hanya menyiapkan prasyarat + delegasi ke `agent/install.sh` (§5.41).

> **WAJIB — kedua bootstrap root (`install.sh`, `install.agent.sh`) mandiri.** **Gejala**: `curl … | bash` gagal `No such file or directory` saat memanggil helper bersama, atau bootstrap memakai versi helper dari checkout lama. **Sebab**: skrip dijalankan **sebelum** repo ada, sehingga tidak boleh `source`/`require` berkas repo apa pun. **Penangkal**: duplikasi boilerplate (guard OS/arsitektur/root, prompt fd, clone) **disengaja**; berbagi kode hanya boleh lewat repo-setelah-clone (`host/install.sh`, `agent/install.sh`).
