# Ops · Ansible — provisi server agent Rames

Direktori ini adalah **pembungkus idempoten** untuk server agent Rames
(`RAMES_MODE=agent`: tempat app benar-benar berjalan — Docker Engine + Nginx
native + certbot). Ansible **tidak menggantikan** installer yang sudah ada:

| Urusan | Pemilik sebenarnya | Peran Ansible |
|---|---|---|
| Dependensi host (Docker Engine, compose v2.6+, nginx, certbot, git, openssl) | `host/install.sh --deps-only` | memanggil, sekali, dengan `become` |
| Rahasia agent, sertifikat TLS self-signed, `secrets/agent.env`, `docker compose up -d --build` | `agent/install.sh` | memanggil dengan argumen dari inventaris |
| Aturan firewall | operator / role ini (hanya **menambah** rule yang belum ada) | `ufw allow …` per IP + `80,443/tcp` |

Tidak ada daftar paket yang diduplikasi di sini. Kalau `host/install.sh`
berubah, hasil role ikut berubah.

Semua modul adalah **`ansible.builtin.*`** — tanpa collection pihak ketiga,
tanpa `requirements.yml`, tanpa `build:`/`container_name` (role ini tidak
menyentuh compose app). Repo ini **tidak** memuat Ansible: ia berjalan di
control node operator, bukan di image Rames.

> **Dua jalur pemakaian.** (1) Manual dari mesin operator — butuh `ansible-core`
> di venv (§1). (2) **Dari dashboard** (`/servers` → Provision, SPECS §8k): control
> plane menjalankan helper container `docker run --rm` dengan image `ANSIBLE_IMAGE`
> (default `alpine/ansible`, **pin tag di produksi**) — tanpa venv dan tanpa
> Ansible di host. Keduanya memakai role & playbook yang sama di direktori ini.

---

## 1. Prasyarat (control node, BUKAN server agent)

1. **`ansible-core` ≥ 2.16** di mesin operator (di luar image Rames):

   ```bash
   python3 -m venv ~/.venvs/rames-ansible
   ~/.venvs/rames-ansible/bin/pip install 'ansible-core>=2.16'
   ```

   Collection **tidak** diperlukan. Jangan pasang Ansible di dalam container
   `rames-webman` — image itu untuk dashboard, bukan operator.

2. **Akses SSH** ke tiap server agent sebagai user dengan `become` (root,
   atau user sudo). `host_key_checking=True` (lihat `ansible.cfg`): terima
   host key sekali secara sadar, jangan mematikannya.

3. **`git` sudah terpasang di server agent.** Ini satu-satunya prasyarat yang
   tidak bisa disiapkan role ini sendiri: `repo.yml` meng-clone repo **sebelum**
   `deps.yml` memasang paket (role butuh repo untuk bisa memanggil
   `host/install.sh`). Preflight berhenti dengan pesan jelas bila `git` absen:

   ```bash
   apt-get update && apt-get install -y git
   ```

4. **DNS wildcard** `*.<APP_DOMAIN>` → IP server agent (dipakai app), dan
   **port 80/443 terbuka untuk umum** (lihat GOTCHA no. 52 di bawah).

5. Inventaris nyata (rahasia lingkungan) **tidak** di-commit:

   ```bash
   cd ops/ansible
   cp inventories/agents/hosts.example.yml inventories/agents/hosts.yml
   # sunting hosts.yml: IP, user, domain, email, IP control plane
   ```

## 2. Struktur

```
ops/ansible/
├── ansible.cfg                  # inventory, roles_path, host_key_checking, forks
├── inventories/agents/
│   └── hosts.example.yml        # contoh (hosts.yml di-gitignore)
├── group_vars/all.yml           # repo/ref/dir, port, saklar global
├── group_vars/agents.yml        # domain/email/rentang port/bind/ip/ssl
├── roles/rames_agent/
│   ├── defaults/main.yml
│   └── tasks/{main,preflight,repo,deps,firewall,install,onboarding,verify}.yml
└── playbooks/{provision-agent,verify-agent,upgrade-agents}.yml
```

Urutan `main.yml` bersifat tetap: **preflight → repo → deps → firewall →
install → verify**.

## 3. Kontrak variabel

Nama di bawah ini **wajib** (dipakai lintas playbook & dokumentasi):

| Variabel | Bawaan | Arti |
|---|---|---|
| `rames_repo_url` | `https://github.com/yllumi/rames.git` | repo yang di-clone |
| `rames_ref` | `main` | ref/branch yang dipakai (clone pertama & upgrade) |
| `rames_install_dir` | `/opt/rames-agent` | checkout agent (BUKAN direktori dashboard) |
| `rames_agent_control_plane_ips` | `[]` | IP dashboard yang boleh menyentuh port agent (wajib bila firewall dikelola) |
| `rames_agent_port` | `8443` | port HTTPS agent **di host** (publish) |
| `rames_agent_domain` | `''` | `APP_DOMAIN`; wajib pada instalasi pertama |
| `rames_agent_email` | `''` | `ADMIN_EMAIL` (wajib agar `ssl_issue` jalan) |
| `rames_agent_port_range_start` | `30000` | rentang host port container app |
| `rames_agent_port_range_end` | `30999` | idem |
| `rames_agent_bind` | `0.0.0.0:8443` | `AGENT_BIND` — listen **di dalam container** |
| `rames_agent_ip` | `''` | IPv4 untuk SAN & verifikasi (kosong = deteksi installer / `ansible_host`) |
| `rames_agent_ssl_challenge` | `''` | `http` \| `dns-cloudflare` (kosong = pertahankan) |
| `rames_agent_ssl_ca_server` | `''` | `production` \| `staging` (kosong = pertahankan) |
| `rames_agent_manage_deps` | `true` | jalankan `host/install.sh --deps-only` |
| `rames_agent_manage_firewall` | `true` | kelola ufw (tambah rule saja) |
| `rames_agent_run_installer` | `auto` | `auto` = hanya bila `secrets/agent.env` belum ada; `true` = selalu; `false` = tidak pernah |
| `rames_agent_assert_identity` | `true` | tolak bila nilai inventaris ≠ `secrets/agent.env` |
| `rames_agent_allow_other_os` | `false` | izinkan OS non-Debian (tidak diuji) |
| `rames_agent_onboarding_out` | `''` | path di **control node** untuk berkas onboarding dari `--onboarding-file` (kosong = tidak diambil) |

**String kosong = "pertahankan nilai lama"** (perilaku idempoten
`agent/install.sh`: opsi yang tidak diberikan tidak menimpa env).

## 4. Contoh perintah

Jalankan **dari `ops/ansible/`** agar `ansible.cfg` terbaca:

```bash
cd ops/ansible

# 0. inventaris benar?
ansible-inventory --list
ansible agents -m ping

# 1. verifikasi READ-ONLY (langkah pertama runbook adopsi)
ansible-playbook playbooks/verify-agent.yml --limit agent-01

# 2. dry-run provisi (lihat catatan `--check` di §8: tugas async dipagari)
ansible-playbook playbooks/provision-agent.yml --limit agent-01 --check --diff

# 3. provisi satu server
ansible-playbook playbooks/provision-agent.yml --limit agent-01

# 4. upgrade checkout + installer (serial 1, toleransi gagal 0)
ansible-playbook playbooks/upgrade-agents.yml --limit agent-01

# 5. adopsi host yang SUDAH terpasang (tanpa menyentuh apa pun)
ansible-playbook playbooks/provision-agent.yml --limit agent-01 \
  -e rames_agent_run_installer=false \
  -e rames_agent_manage_deps=false \
  -e rames_agent_manage_firewall=false
```

Syntax-check (tanpa menyentuh server):

```bash
ansible-playbook playbooks/provision-agent.yml --syntax-check -i inventories/agents/hosts.example.yml
ansible-playbook playbooks/verify-agent.yml   --syntax-check -i inventories/agents/hosts.example.yml
ansible-playbook playbooks/upgrade-agents.yml --syntax-check -i inventories/agents/hosts.example.yml
```

Lint (opsional, di control node):

```bash
pip install ansible-lint && ansible-lint   # dijalankan dari ops/ansible/
```

Hasil saat ini: **Passed, profile `production`, 0 failure**. Tiga nama
kontrak (`rames_repo_url`, `rames_ref`, `rames_install_dir`) sengaja memakai
`# noqa: var-naming[no-role-prefix]` di `roles/rames_agent/defaults/main.yml`
karena namanya ditetapkan lintas playbook & dokumentasi.

## 5. Dampak pada host produksi

Server agent **adalah** host produksi app: ia menjalankan container app user,
Nginx host, dan certbot. Perlakukan setiap operasi sebagai perubahan produksi.

| Operasi | Efek di host | Perkiraan gangguan |
|---|---|---|
| reload Nginx (`NginxReloader` dari agent) | reload worker nginx, koneksi in-flight diselesaikan | **detik** |
| `agent/install.sh` → `docker compose up -d --build` | container agent di-**recreate** (`rames-agent`); saat start ia menjalankan composer install/migrasi | **20–90 detik** tanpa dashboard untuk server itu |
| Job deploy/SSL yang sedang berjalan saat recreate | job async (`cli/deploy.php`) hidup di dalam container agent → **terputus** dan harus diulang | sesuai durasi job |
| Container **app** milik user | **TIDAK** ikut mati — project compose berbeda (`app-*`), agent hanya mengendalikan lewat Docker API | — |
| `host/install.sh --deps-only` | `apt-get update` + instal/upgrade paket host (nginx, certbot, Docker), `systemctl enable --now docker` + nginx | detik–menit; nginx **tidak** di-restart paksa, tetapi upgrade paket bisa memicu restart service |
| `ufw allow …` (hanya menambah) | rule baru berlaku seketika, koneksi berjalan tidak diputus | instan |
| `git update` di `rames_install_dir` | berkas checkout berganti; **tidak** berefek sampai container di-recreate | — |
| `--force` installer (`AGENT_TOKEN` + TLS baru) | **jangan** dipakai dari Ansible: server harus di-onboard ulang di dashboard | — |

Karena recreate memutus job, jalankan `provision-agent.yml`/`upgrade-agents.yml`
di luar jam deploy. `serial: 1` + `any_errors_fatal` pada `upgrade-agents.yml`
memastikan armada tidak ter-upgrade setengah jalan.

## 6. Runbook adopsi host yang sudah terpasang

Host lama (dipasang manual, mungkin sudah punya app berjalan) **jangan** langsung
diprov-paksa. Urutannya:

1. **`verify-agent.yml`** — read-only: OS, `git`, direktori bukan dashboard,
   token ada, `GET /version` dengan CA self-signed. Tidak menulis apa pun.
2. **`provision-agent.yml --check --diff`** — lihat rencana: apakah clone akan
   terjadi, aturan ufw mana yang akan ditambah, argumen installer apa yang akan
   dipakai.
3. **Adopsi read-only** — pastikan role tidak mengubah host lebih dulu:

   ```bash
   ansible-playbook playbooks/provision-agent.yml --limit agent-01 \
     -e rames_agent_run_installer=false \
     -e rames_agent_manage_deps=false \
     -e rames_agent_manage_firewall=false
   ```

4. **Samakan inventaris dengan kenyataan host.** Preflight + parse
   `secrets/agent.env` akan berhenti bila `APP_DOMAIN`, `PORT_RANGE_*`, atau
   `AGENT_BIND` di inventaris berbeda dari yang terpasang (pesan hanya
   menyebut **nama kunci**, bukan nilainya). Ini sengaja: perbedaan berarti
   identitas server akan berubah, bukan sekadar "menyegarkan".
5. **Aktifkan bertahap**: `rames_agent_manage_firewall=true` lebih dulu (aman,
   hanya menambah rule), lalu `rames_agent_manage_deps=true`, terakhir
   `rames_agent_run_installer=true` (recreate container — lihat tabel dampak).

## 7. Larangan (keras)

* **Jangan** memakai `--force` pada `agent/install.sh` dari Ansible
  (`--force` = token + sertifikat baru + force-recreate; role ini tidak pernah
  mengirimkannya). Rotasi rahasia = tindakan operator yang disengaja, manual.
* **Jangan** menjalankan `ufw enable`, `ufw --force`, atau mengubah default
  policy dari role/playbook: bisa memutus SSH operator.
* **Jangan** menghapus atau mengganti aturan firewall yang sudah ada (role hanya
  menambah rule yang belum tercatat di `ufw show added`).
* **Jangan** checkout paksa / `git reset --hard` / `force: true`. Upgrade
  menolak bila `git status --porcelain` tidak kosong.
* **Jangan** mencetak `AGENT_TOKEN` maupun PEM ke log, tiket, atau chat.
  Task yang menyentuhnya ber-`no_log`; onboarding ke dashboard dilakukan dengan
  mengambil nilainya **manual** di server:

  ```bash
  sudo sed -n 's/^AGENT_TOKEN=/AGENT_TOKEN=/p' /opt/rames-agent/secrets/agent.env
  sudo cat /opt/rames-agent/secrets/agent/tls.crt
  ```

  Fingerprint dihitung ulang dashboard dari PEM, jadi tidak perlu diketik.
* **Jangan** mengarahkan `rames_install_dir` ke direktori dashboard
  (`rames` / container `rames-webman`). Preflight menolaknya (`.env` +
  `database/apps.json`).
* Ansible **bukan** pengganti installer: jangan menambahkan logika rahasia/TLS/
  compose di role ini.

## 8. Catatan operasional

### `--check` tidak sepenuhnya read-only tanpa penjaga async

Jebakan Ansible: tugas `ansible.builtin.command` yang memakai **`async`** tetap
**benar-benar dieksekusi** saat `--check` — Ansible baru menandainya `skipped`
*setelah* proses berjalan. Tanpa penjaga, `provision-agent.yml --check` akan
memasang paket lalu me-recreate container agent.

Karena itu `deps.yml` dan `install.yml` memagari tugas async-nya secara eksplisit:

```yaml
when:
  - <saklar aktif>
  - not ansible_check_mode
```

dan menyertakan task `debug` yang menjelaskan bahwa langkah itu **tidak**
disimulasikan. Konsekuensinya: `--check` memverifikasi **rencana role** (clone?
aturan ufw apa? argumen installer apa?) — bukan hasil `host/install.sh` /
`agent/install.sh`. `verify-agent.yml` juga tidak mendukung `--check` (butuh
berkas CA sementara).

### Berkas onboarding (`--onboarding-file`)

Setelah instalasi, dashboard butuh **URL agent, token, dan PEM sertifikat**.
Alih-alih mem-parsing stdout installer (yang memuat rahasia), role meminta
installer menulis berkas onboarding ke server lalu menyalinnya ke control node:

```yaml
rames_agent_onboarding_out: ~/.rames/onboarding/agent-01.txt   # DI LUAR REPO
```

Alurnya (role `tasks/onboarding.yml`, berjalan setelah `install`, sebelum
`verify`):

1. `agent/install.sh … --onboarding-file /tmp/rames-onboarding.txt`
   (ditambahkan otomatis hanya bila `rames_agent_onboarding_out` tidak kosong
   **dan** installer benar-benar dijalankan);
2. `slurp` berkas itu (`no_log`) → `copy` ke `rames_agent_onboarding_out`
   dengan mode **0600** di control node (`no_log`);
3. blok `always` menghapus berkas sementara di server — berhasil atau gagal;
4. role **hanya** menyebut path tujuan, **tidak pernah** mencetak isinya.

Bila berkas sementara tidak ada (mis. `rames_agent_run_installer=false` pada
host yang sudah terpasang), role berhenti dengan pesan jelas: berkas onboarding
hanya lahir saat installer dijalankan. Pilihan: jalankan installer
(`rames_agent_run_installer=true`, idempoten, token lama dipertahankan) atau
kosongkan `rames_agent_onboarding_out`.

Berkas itu **berisi rahasia** (token agent). Simpan di luar repo (mis.
`~/.rames/onboarding/`), jangan commit, jangan tempel ke tiket/chat, dan hapus
setelah server terdaftar di dashboard. Fingerprint dihitung ulang dashboard dari
PEM, jadi tidak perlu diketik manual.

### GOTCHA no. 52 — dua kelompok port, aturan berbeda

`firewall.yml` menambahkan **dua** kelompok:

1. `ufw allow from <ip-control-plane> to any port <rames_agent_port> proto tcp`
   — port agent, **hanya** control plane (ini pintu eksekusi Docker).
2. `ufw allow 80,443/tcp` — **selalu publik**. Nginx host melayani app di sini
   dan certbot memvalidasi HTTP-01 dari IP acak Let's Encrypt. Allowlist IP di
   port 80 selalu berakhir `Timeout during connect (likely firewall problem)`
   saat tombol SSL ditekan, walau semua konfigurasi Rames benar.

Verifikasi dari mesin **luar** server (harus keluar kode HTTP, bukan timeout):

```bash
curl -sS -m 8 -o /dev/null -w '%{http_code}\n' http://<app>.<APP_DOMAIN>/
```

Bila port 80 memang tidak boleh publik: pakai `SSL_CHALLENGE=dns-cloudflare`
(`rames_agent_ssl_challenge`) + `CLOUDFLARE_CREDS`; **port 443 tetap wajib**.
Tanpa `ufw`, role hanya memperingatkan — iptables/cloud firewall jadi tugas
operator.

### `AGENT_PORT_PUBLISH` bila port agent ≠ 8443

Publish compose adalah `${AGENT_PORT_PUBLISH:-8443}:8443`, sedangkan
`AGENT_BIND` adalah alamat listen **di dalam** container (tetap 8443). Jadi bila
`rames_agent_port` ≠ 8443:

1. set `rames_agent_port: <port>` di inventaris (dipakai aturan firewall &
   verifikasi), dan
2. tambahkan **di wilayah operator** `secrets/agent.env` (di bawah penanda
   `# rames-installer:preserved`, di luar blok `# rames-installer:begin … end`
   supaya tidak ditimpa rerun):

   ```
   AGENT_PORT_PUBLISH=<port>
   ```

3. rerun `agent/install.sh` (recreate) — nilai di blok kelolaan bukan tempat
   untuk setelan ini.

### `no_log` dan diagnosa kegagalan

Task yang menjalankan `agent/install.sh` ber-`no_log` (stdout-nya memuat token
agent & PEM). Bila task itu gagal, Ansible hanya melaporkan "output has been
hidden". Cara mendiagnosa tanpa membocorkan rahasia: jalankan installer yang
sama **manual** di server (`cd /opt/rames-agent && sudo bash agent/install.sh
--domain …`), baca pesan galatnya, jangan menyalin blok onboarding ke tiket.

### Verifikasi `/version`

`verify.yml` memanggil `https://<rames_agent_ip|ansible_host>:<rames_agent_port>/version`
dengan `ca_path` = salinan sementara `secrets/agent/tls.crt` (0644, dihapus di
blok `always`) dan header `Authorization: Bearer <AGENT_TOKEN>`. Sertifikat
agent ber-SAN **IP**, jadi bila `ansible_host` berupa hostname isi
`rames_agent_ip` agar verifikasi TLS tidak gagal karena nama.
`verify-agent.yml` **tidak** mendukung `--check` (verifikasi butuh berkas CA
sementara di host); mode check hanya memperingatkan lalu melewatinya.

## 9. Batasan yang diketahui

* Role ini hanya menangani **satu** direktori agent per host dan tidak bisa
  memasang dashboard (`rames` / `rames-webman`) — itu `install.sh` interaktif
  dan di luar cakupan fase ini.
* `agent/install.sh` tidak menerima opsi `AGENT_PORT_PUBLISH` maupun
  `CLOUDFLARE_CREDS` lewat role ini (kredensial Cloudflare diisi manual di
  `secrets/agent.env`).
* Mode check tidak mensimulasikan isi installer: `command`/`ufw` dilewati, jadi
  `--check` membuktikan *rencana* role, bukan hasil `agent/install.sh`.
* Role tidak memverifikasi versi Rames yang diharapkan dashboard; negosiasi
  versi/capabilities tetap urusan `/version` + `AgentVersionGate`.
