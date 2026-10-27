# 1. Ringkasan

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

Rames (Phase 1) adalah aplikasi **Webman (PHP 8.1+)** yang berjalan **di dalam container Docker sebagai root**, mengelola:

- **App** — project yang di-deploy dari repo Git berisi `docker-compose.yml`
- **Container** — hasil `docker compose` tiap app (dijalankan pada daemon Docker *host* lewat socket)
- **Reverse proxy** — config Nginx ditulis ke direktori host (di-mount), Nginx native di host mem-forward subdomain ke port app

Arsitektur disiapkan agar logika eksekusi (`DeployerInterface`) bisa diekstrak menjadi **agent HTTP terpisah** di fase berikutnya (multi-server) tanpa merombak controller/business logic.

**Status multi-server (F0–F4 mendarat & terverifikasi; sisa eksplisit di §8):** ekstraksi itu sudah dilakukan. Kode & image yang **sama** kini berjalan dalam dua peran runtime (`config('deploy.mode')`, dari `RAMES_MODE`): **control plane** (`dashboard` — UI, login, registry server, timer) dan **agent** (eksekutor di server tempat app berjalan, tanpa UI, listener TLS sendiri). Control plane memilih deployer per app lewat `DeployerFactory::createForApp()` → `LocalDeployer` (server `local`) atau `AgentDeployer` (HTTP ke agent, job async). Alur lengkapnya di §5.21–§5.26; **preflight create app** — ketersediaan direktori `apps/<name>` di server agent lewat `POST /apps/check` — dijalankan **sebelum** record app dibuat (§5.22, GOTCHA §8 no. 36).
