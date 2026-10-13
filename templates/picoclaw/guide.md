## Ringkasan

PicoClaw adalah asisten AI personal ultra-ringan dari [Sipeed](https://sipeed.com):
satu binary Go dengan konsumsi RAM di bawah 10 MB dan boot dalam milidetik
(klaim resmi proyek). Ia **bukan** fork OpenClaw — proyeknya mandiri, diinspirasi
NanoBot, dan diposisikan sebagai alternatif ringan dari OpenClaw.

Yang dijalankan template ini adalah varian **`launcher`** resmi:

- **Web console** di port `18800` untuk konfigurasi model/provider, channel, dan
  pemantauan;
- **gateway chat** yang dijalankan otomatis oleh launcher dan diproksi Web
  console (termasuk WebSocket), sehingga cukup satu port yang dibuka.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `picoclaw` |
| Image | `ghcr.io/sipeed/picoclaw:launcher` (registry resmi paralel: `docker.io/sipeed/picoclaw:launcher`) |
| Port container | `18800` (Web console — di-proxy ke domain app) |
| Named volume | `picoclaw-data` → `/root/.picoclaw` (config, workspace, sesi, skills, password Web console) |
| Named volume | `picoclaw-ssh` → `/root/.ssh` (kunci enkripsi kredensial) |
| Hardening | `init: true`, `cap_drop: NET_RAW/NET_ADMIN`, `no-new-privileges: true` |
| Healthcheck | `GET /api/auth/status` via `wget --spider` (endpoint publik launcher) |

Port gateway `18790` **tidak** dipublish: Web console sudah mem-proksi gateway di
dalam container, jadi mengeksposnya hanya menambah permukaan serangan. Bila Anda
benar-benar butuh channel mode webhook dari jaringan luar, tambahkan port itu di
tab **Compose** (lalu Deploy Ulang) **dan** set `PICOCLAW_GATEWAY_HOST=0.0.0.0`
supaya gateway mengikat seluruh antarmuka container.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `TZ` | Default `Asia/Jakarta` | Zona waktu (jadwal cron/reminder asisten & log). |

Kredensial model/provider **tidak** diisi lewat form env template ini — isi
lewat **Web console** (langkah 2 di bawah) atau dengan menyunting
`/root/.picoclaw/config.json` lewat tab **Terminal** app.

## Setelah deploy

1. **Buat password Web console SEGERA** — buka domain app; kunjungan pertama
   mengarahkan ke `/launcher-setup`. Password disimpan (bcrypt) di
   `launcher-auth.db` di dalam volume `picoclaw-data`. **Tidak ada** env var
   untuk men-set password (mis. `PICOCLAW_LAUNCHER_TOKEN` sudah tidak
   didukung versi sekarang, meskipun masih disebut di sebagian dokumentasi
   lama), jadi password hanya bisa dibuat dari UI.
   - ⚠️ **Selama password belum di-set, endpoint setup terbuka tanpa auth dan
     tanpa rate-limit: siapa pun yang bisa membuka domain app ini dapat
     menyetel passwordnya sendiri dan mengambil alih Web console** (mengubah
     config, menyuntik API key, mengendalikan gateway). Karena itu jangan
     menunda langkah ini — sebaiknya lakukan sebelum domain dibagikan/diumumkan.
   - Lupa password? Hapus `launcher-auth.db` di volume `picoclaw-data`, **restart
     container** (store dibuka sekali saat startup, jadi menghapus berkasnya
     saja tidak mengosongkan state yang sedang berjalan), lalu buka
     `/launcher-setup` lagi.
2. **Konfigurasi model/provider** di Web console: isi API key provider
   (OpenAI/Anthropic/OpenRouter/dll) dan pilih model default. Onboarding awal
   sudah dijalankan otomatis oleh launcher, jadi container tetap hidup tanpa
   langkah manual.
3. Opsional: sambungkan channel (Telegram/Discord/Feishu, dst.) — token channel
   dimasukkan di Web console, bukan lewat compose.

## Akses & domain

- Port container yang di-proxy Rames: `18800`.
- Web console (termasuk WebSocket chat) diakses lewat domain/subdomain app;
  dashboard Rames sudah meneruskan WebSocket.
- Endpoint publik launcher yang dipakai healthcheck: `/api/auth/status`.

## Catatan

- **Pra-1.0**: README resmi PicoClaw memperingatkan jangan memakainya di
  produksi sebelum v1.0; perilaku/format config masih bisa berubah antar rilis.
  Tag `launcher` mengikuti rilis stabil terbaru — untuk menyematkan versi,
  ganti tag di tab **Compose** menjadi mis. `v0.3.1-launcher`.
- **Volume wajib ikut backup**: `picoclaw-data` (config/workspace/sesi/skills +
  password Web console) dan `picoclaw-ssh` (kunci enkripsi kredensial). Bila
  `picoclaw-ssh` hilang, kredensial yang tersimpan sebagai `enc://` tidak bisa
  didekripsi lagi.
- **Jangan membuka Web console tanpa password.** Password dashboard adalah
  **satu-satunya** lapisan auth: allowlist CIDR launcher (`allowed_cidrs`)
  bawaan **kosong**, sehingga filter itu tidak aktif sama sekali — bukan karena
  request reverse proxy dianggap loopback. Bila nanti Anda mengisinya sendiri,
  perhatikan bahwa request yang datang lewat Nginx host (mode bridge) berasal
  dari IP gateway non-loopback, sehingga bisa tertolak `403` — sesuaikan
  `allowed_cidrs` dengan jaringan itu.
- **Menyegarkan image**: image ini tag bergerak; tombol **Tarik image** di
  **Config → tab Template** menariknya dari registry, lalu tekan **Deploy Ulang**
  pada app agar container memakai image baru.
- Dokumentasi resmi: [PicoClaw Docker](https://docs.picoclaw.io/docs/docker/) ·
  [Repo sipeed/picoclaw](https://github.com/sipeed/picoclaw)
