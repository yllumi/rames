## Ringkasan

OpenCode adalah agen koding AI open source yang dijalankan sebagai **server**:
perintah `opencode serve` menyajikan UI web (dan API/WebSocket/SSE) di dalam
container, dan Rames meneruskan domain/subdomain app ke port `4096`. Jadi
antarmuka opencode dibuka langsung lewat domain app — tidak ada desktop/VNC.

Ciri yang perlu dipahami sebelum memakai (perilaku khas template ini):

- **Satu direktori project di host di-mount READ-WRITE** ke `/workspace`.
  opencode membaca **dan mengubah** project tersebut (menulis berkas, menjalankan
  perintah, commit bila diminta).
- **Basic auth wajib di seluruh permukaan** UI/API: username
  `OPENCODE_SERVER_USERNAME` (bawaan `opencode`) dan password
  `OPENCODE_SERVER_PASSWORD` yang dibuat otomatis saat create.
- **git dipasang saat start** (image resmi tidak membawanya), dengan hak turun ke
  pemilik direktori project supaya berkas tetap milik user host.
- Konfigurasi project opencode dibaca dari `.opencode/` dan `opencode.json`
  **bila ada** — opencode tidak dijamin membuatnya (lihat bagian `.opencode/`).
- State/konfigurasi opencode (config, `opencode.db`, `auth.json`) disimpan di
  **named volume**, bukan di dalam project.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `opencode` |
| Image | `ghcr.io/anomalyco/opencode:latest` (repo berpindah dari `sst/opencode`; tag `ghcr.io/sst/opencode` **tidak** ada) |
| Port container | `4096` (UI web + API; di-proxy ke domain app) |
| Named volume | `opencode-home` → `/home/opencode` (config, `opencode.db`, `auth.json`, cache) |
| Bind mount | `${OPENCODE_PROJECTS_DIR}` → `/workspace` (project host, read-write; long syntax + `create_host_path: false` ⇒ path wajib sudah ada — **termasuk** di dalam direktori app, lihat catatan di bawah) |
| Healthcheck | `GET /global/health` dengan basic auth (dibaca dari env, tanpa password literal) |
| Init | `init: true` + `stop_grace_period: 5s` (image tanpa init; SIGTERM kurang dihormati) |

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `OPENCODE_SERVER_PASSWORD` | Auto (`generate: secret`) | Password basic auth seluruh UI/API. Lihat nilai di tab Environment. |
| `OPENCODE_SERVER_USERNAME` | Default `opencode` | Username basic auth. |
| `OPENCODE_PROJECTS_DIR` | **Wajib** | Path **absolut** di host ke direktori project. Nilainya **bergantung lokasi instalasi Rames**: instalasi produksi umumnya `<root>/apps/<nama-app>` (mis. `/srv/rames/apps/kuma`); instalasi dev bisa di tempat lain. Wajib sudah ada saat deploy. |
| `OPENCODE_RUN_AS` | Opsional | `uid:gid` tujuan dalam bentuk **kanonik** dengan tiap bagian `0..4294967295` (mis. `1000:1000`, `33:33`; `0033:33`, `4294967296:0`, `abc`, `33:33:33`, `-1:0`, `33` ditolak); kosong = deteksi otomatis dari pemilik direktori project. Nilai yang tidak lolos bentuk/rentang **diabaikan** (dicatat `WARN` di log container) dan deteksi otomatis dipakai — container tidak dibuat gagal karenanya. Nilai bentuk-sah yang tak bisa dipakai (chown/kepemilikan/uji tulis volume HOME gagal) ⇒ `WARN` + opencode jalan sebagai root. |
| `TZ` | Default `Asia/Jakarta` | Zona waktu container. |
| `ANTHROPIC_API_KEY` | Opsional (secret) | Kredensial provider model. |
| `OPENAI_API_KEY` | Opsional (secret) | Kredensial provider model. |
| `OPENROUTER_API_KEY` | Opsional (secret) | Kredensial provider model (OpenRouter). |
| `GIT_USER_NAME` | Opsional | `git config --global user.name` di dalam container. |
| `GIT_USER_EMAIL` | Opsional | `git config --global user.email` di dalam container. |

Kredensial provider lain (mis. Google/Gemini) bisa ditambahkan lewat tab
**Terminal** app ke `~/.local/share/opencode/auth.json`, atau dengan login
provider dari UI opencode.

## Mengarahkan ke project lain

`OPENCODE_PROJECTS_DIR` boleh diganti **kapan pun**:

1. Buka app → tab **Environment**.
2. Ubah `OPENCODE_PROJECTS_DIR` menjadi path **absolut** baru di host. Nilainya
   bergantung lokasi instalasi: instalasi produksi umumnya
   `<root>/apps/<nama-app>` (mis. `/srv/rames/apps/kuma`), sedangkan instalasi dev
   bisa memakai direktori lain (mis. `/home/user/projects/kuma`).
3. Apply/simpan env. Rames menjalankan `docker compose up -d` **tanpa build**,
   sehingga container di-recreate dan bind mount ikut berubah.

Catatan:

- Path **wajib sudah ada** di host saat deploy. Bind mount di template memakai
  `create_host_path: false`, sehingga `docker compose up` **gagal** (dan tidak
  membuat direktori baru) bila path belum ada; Rames menambahkan hint daftar bind
  mount yang belum siap pada pesan errornya. Karena deklarasi `create_host_path:
  false` **dihormati** dashboard, **tidak ada** direktori yang dibuat otomatis —
  termasuk bila path-nya berada **di dalam** direktori app (`/srv/rames/apps/<nama-app>/…`).
  Nilai yang dikosongkan juga ditolak oleh compose.
- Path harus berada di host tempat container berjalan (server lokal atau server
  agent yang sesuai) — bukan path di mesin Anda.
- Project **wajib writable** oleh proses opencode (lihat bagian kepemilikan).
- Jangan menunjuk direktori yang berisi rahasia/kredensial.

## Cara memakai

1. Buka domain/subdomain app. Browser akan meminta HTTP basic auth: masukkan
   username (`OPENCODE_SERVER_USERNAME`, bawaan `opencode`) dan password
   `OPENCODE_SERVER_PASSWORD` dari tab **Environment**.
2. Isi kredensial provider model: lewat env (`ANTHROPIC_API_KEY` dkk, lalu apply
   env), lewat login provider di UI, atau lewat tab **Terminal**
   (`~/.local/share/opencode/auth.json`).
3. Pilih/konfirmasi project (`/workspace`) dan mulai sesi. Setiap perubahan yang
   dilakukan opencode ditulis ke direktori project di host.
4. opencode membaca konfigurasi project dari `.opencode/` **bila ada** (lihat
   bagian `.opencode/` di bawah) — jangan mengandalkan direktori itu dibuat
   otomatis.

## `.opencode/` di dalam project

opencode mengenal konfigurasi tingkat-project di dalam direktori project:
`.opencode/agents`, `.opencode/commands`, `.opencode/plugins`, `.opencode/skills`,
`.opencode/themes`, `.opencode/tools`, dan berkas `opencode.json`. Direktori dan
berkas itu **dibaca bila ada** — itu **konfigurasi opsional milik project**, bukan
sesuatu yang dijamin **dibuat** oleh opencode.

Yang teramati pada pengujian template ini:

- Pada image tag `latest` (versi 1.19.1), setelah server start + beberapa sesi API,
  `ls -a /workspace` **tidak** menampilkan `.opencode` — jadi jangan mengandalkan
  direktori itu muncul.
- Pada varian **v2** pernah teramati opencode menulis `.opencode/.gitignore` di
  root project — klaim ini **belum diverifikasi ulang** (percobaan dengan tag
  `2.0.30` tanpa sesi model **tidak** menghasilkan `.opencode/`). Karena itu,
  **disarankan** menambahkan `.opencode/` ke `.gitignore` project (aman untuk
  kedua kemungkinan: bila tidak pernah dibuat, entri itu tidak berefek; bila
  dibuat, isinya tidak ikut ter-commit).

Jika Anda ingin konfigurasi project, buat sendiri direktori `.opencode/` (atau
`opencode.json`) di dalam project — dari host maupun dari tab Terminal app
(direktorinya sama).

## Kepemilikan berkas & git

Container **start sebagai root** (agar bisa `apk add` dan `chown` volume), lalu
skrip entrypoint **menurunkan hak**:

1. Target uid:gid = `OPENCODE_RUN_AS` bila diisi **dan** lolos bentuk kanonik
   `^(0|[1-9][0-9]{0,9}):(0|[1-9][0-9]{0,9})$` **beserta** rentang
   `0..4294967295`; kalau tidak `stat -c '%u:%g' /workspace` (pemilik direktori
   project di host). Nilai yang tidak sah (mis. `abc`, `33:33:33`, `-1:0`, `33`,
   `0033:33`, `4294967296:0`) **diabaikan** dengan `WARN` di log container, lalu
   deteksi otomatis yang dipakai — container tidak dibuat gagal karenanya.
   Nilai ber-spasi di ujung (mis. ` 33:33 `) **tidak** dianggap salah: compose
   men-*trim* nilai env sebelum container menerimanya, jadi yang sampai ke skrip
   adalah `33:33` yang sah.
2. Gerbang sebelum hak diturunkan: volume `opencode-home` di-`chown` ke target,
   kepemilikan `/home/opencode` harus **sama dengan** target, dan target harus
   bisa **menulis** `/home/opencode`; ketiganya lulus → `git config --global
   --add safe.directory '*'` dijalankan **sebagai user itu** (mencegah error
   `detected dubious ownership` saat opencode menjalankan git). Bila salah satu
   gagal, hak **tidak** diturunkan (langkah 4 dilewati; lihat *Fallback*).
3. Bila `GIT_USER_NAME`/`GIT_USER_EMAIL` diisi, identitas commit di-set global
   untuk user tersebut.
4. `opencode serve` dijalankan sebagai uid:gid itu (via `su-exec`), dengan `HOME`
   di-set **ulang eksplisit** ke `/home/opencode` (`HOME=/home/opencode` di
   compose) — `su-exec` menimpa `HOME` dari entri passwd, sehingga untuk uid
   tanpa entri passwd (mis. `33`) `HOME` menjadi `/` dan state opencode bisa
   tertulis di dalam project.

Hasilnya: berkas yang dibuat opencode di project host dimiliki **uid:gid pemilik
project** (bukan root), sehingga operasi git di host (commit, checkout, `git gc`)
tidak rusak karena kepemilikan campuran.

Fallback: bila deteksi gagal (mount tidak ada), owner direktori = root,
`su-exec` tidak terpasang, atau **gerbang pemakaian target** gagal (chown,
kepemilikan, atau uji tulis `/home/opencode`; termasuk uid:gid bentuk-sah yang
tak ada padanannya), opencode **jalan sebagai root** dan berkas baru di project
dimiliki root. Saat itu terjadi, log container mencatat
`WARN: berjalan sebagai root — berkas baru di /workspace akan dimiliki root`
(atau, bila nilainya bentuk-sah tetapi tak bisa dipakai,
`WARN: uid:gid=… tidak dipakai (chown/kepemilikan/uji tulis /home/opencode gagal) — berjalan sebagai root`).
Perbaikannya: set `OPENCODE_RUN_AS` ke `uid:gid` yang diinginkan (mis.
`1000:1000`) atau ubah kepemilikan direktori project di host
(`chown -R user:group /path/project`).

## Keamanan

- **opencode adalah agen dengan akses shell di dalam container**, dan direktori
  project ter-mount **read-write**. Apa pun yang bisa dilakukan opencode di
  project, terjadi juga di host (lewat berkas project).
- **Jangan arahkan `OPENCODE_PROJECTS_DIR`** ke direktori berisi rahasia
  (mis. direktori berisi kunci SSH, `.env` produksi, kredensial cloud).
- **Jangan sharing app ini** ke user yang tidak dipercaya (owner/operator bisa
  mengubah `OPENCODE_PROJECTS_DIR` dan env lain), dan jangan membuka port 4096
  ke publik tanpa perlindungan tambahan.
- Password basic auth **wajib**: dibuat otomatis saat create. Tanpa password,
  server opencode berjalan **tanpa** perlindungan dan mencetak peringatan.
- Port `4096` dipublish ke host (dipakai Nginx host untuk proxy) dan dilindungi
  password; jangan hapus `OPENCODE_SERVER_PASSWORD` selama app hidup.

## Batas yang diketahui

- **Paket git dipasang tiap start** (`apk add --no-cache git bash su-exec`),
  sehingga container butuh **egress ke repo Alpine** setiap kali dibuat ulang.
  Bila egress gagal, container tetap hidup (fail-soft) tetapi **fitur git mati**;
  log berisi `WARN: gagal memasang git/bash/su-exec`.
- **Nginx host tidak menyetel `proxy_buffering off` / `proxy_read_timeout`
  khusus** untuk situs app, sehingga streaming **SSE** (`/global/event`) bisa
  terasa lambat/tertunda di balik proxy. **WebSocket tetap diteruskan** oleh
  konfigurasi proxy Rames, jadi UI tetap berfungsi.
- Image bawaan **tidak** memuat `git`/`bash`/`tini`; jangan menghapus langkah
  `apk add` bila butuh git di terminal opencode.
- State opencode ada di volume `opencode-home`; menghapus volume itu (mis. lewat
  halaman Volume) menghilangkan login provider dan riwayat sesi.
- `OPENCODE_PROJECTS_DIR` diganti ⇒ container di-recreate. Sesi opencode yang
  sedang berjalan di container akan berhenti (state-nya tetap di volume).
- **Nilai `OPENCODE_PROJECTS_DIR` kosong atau hilang membuat SELURUH perintah
  compose app ini gagal** (interpolasi wajib `${OPENCODE_PROJECTS_DIR:?…}` di
  template) — itu disengaja supaya deploy gagal cepat, bukan diam-diam memakai
  `/workspace` kosong. Konsekuensinya: perintah `docker compose` **manual** di
  direktori app harus menyertakan `--env-file database/env/<nama-app>.env`;
  dashboard selalu menyertakannya, dan penghapusan app tetap berjalan (teardown
  punya fallback lewat Docker Engine API).
- Path yang **ada tetapi tidak bisa diakses** (mis. mode `000`) tidak membuat
  deploy gagal: daemon menjalankan bind mount sebagai root, dan deteksi uid
  hanya membaca metadata pemilik. opencode yang berjalan sebagai user hasil
  deteksi bisa gagal membaca berkas project — pastikan direktori project bisa
  dibaca & ditulis oleh user tersebut.

## Menambah mount lain

Untuk me-mount direktori tambahan (mis. cache paket, direktori referensi):

1. Buka app → tab **Compose**.
2. Tambahkan entri di `volumes:` service `opencode`, mis.
   `- /srv/dataset:/dataset:ro`.
3. Simpan lalu **Deploy Ulang** (recreate container). Mount tambahan tidak
   mengubah kepemilikan berkas: yang di-`chown` adalah **volume `/home/opencode`**
   (state opencode), sedangkan `/workspace` hanya **dibaca** (`stat -c '%u:%g'`)
   untuk deteksi uid tujuan.
