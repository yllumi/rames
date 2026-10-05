## Ringkasan

OpenClaw adalah gateway asisten AI self-hosted dengan Control UI berbasis web.
Rames meneruskan WebSocket sehingga antarmuka real-time berfungsi. State,
konfigurasi, workspace, dan kunci profil autentikasi disimpan di volume
persisten.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `openclaw-gateway` |
| Image | `ghcr.io/openclaw/openclaw:latest` |
| Port container | `18789` (di-proxy ke domain) |
| Named volume | `openclaw-state` → `/home/node/.openclaw` |
| Named volume | `openclaw-auth-profile` → `/home/node/.config/openclaw` |

Gateway dijalankan dengan `--bind lan --port 18789` agar port yang dipublikasikan
dapat dijangkau Nginx host. Hardening: `cap_drop` `NET_RAW`/`NET_ADMIN` dan
`no-new-privileges: true`.

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `OPENCLAW_GATEWAY_TOKEN` | Auto (`generate: secret`) | Token masuk Control UI; lihat nilainya di tab Environment app. |
| `TZ` | Default `Asia/Jakarta` | Timezone. |
| `OPENAI_API_KEY` | Opsional | API key provider OpenAI. Kosong → onboarding lewat Terminal. |
| `ANTHROPIC_API_KEY` | Opsional | API key provider Anthropic. Kosong → onboarding lewat Terminal. |

## Setelah deploy

1. Buka domain app, lalu masukkan `OPENCLAW_GATEWAY_TOKEN` (tab **Environment**)
   saat Control UI meminta.
2. Bila tidak mengisi API key provider, jalankan onboarding interaktif lewat tab
   **Terminal** app.
3. Sebagian pengaturan belum otomatis (mis. `gateway.controlUi.allowedOrigins`
   untuk origin publik dan penambahan channel) dan dikonfigurasi lewat tab
   Terminal.

## Akses & domain

- Port container yang di-proxy Rames: `18789`.
- Control UI diakses lewat domain app (termasuk WebSocket).
- Endpoint tanpa auth: `/healthz`, `/startupz`, `/readyz`.

## Catatan

- PERINGATAN KEAMANAN: jangan membuka Control UI ke publik tanpa token akses;
  tinjau ulang hardening dan eksposur OpenClaw.
- Volume `openclaw-state` (state/config/workspace) dan `openclaw-auth-profile`
  (kunci profil) wajib persisten dan ikut di-backup.
- Dokumentasi resmi: [OpenClaw Docker](https://docs.openclaw.ai/install/docker)
