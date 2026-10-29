# ARCHITECTURE.md — Rames (Deploy Dashboard)

Dokumen ini menjelaskan **struktur kode** dan **cara kerja** Rames. Untuk spesifikasi kebutuhan produk lihat [`SPECS.md`](./SPECS.md); untuk instalasi, konfigurasi, dan operasional lihat [`DETAILS.md`](../DETAILS.md).

Dokumen dipecah per bagian di folder [`ARCHITECTURE/`](./ARCHITECTURE/): setiap file memuat satu bagian atau satu kelompok alur. **Nomor section** (mis. §5.18) yang dipakai di komentar kode, SPECS, dan DETAILS merujuk ke judul bagian pada file-file tersebut — pertahankan nomornya saat menyunting.

## Peta Dokumen

| Bagian | Cakupan | File |
|---|---|---|
| 1 | Ringkasan komponen inti dan dua peran runtime (`dashboard`/`agent`) | [`01-ringkasan.md`](./ARCHITECTURE/01-ringkasan.md) |
| 2 | Diagram arsitektur host, container, dan multi-server | [`02-diagram-arsitektur.md`](./ARCHITECTURE/02-diagram-arsitektur.md) |
| 3 | Mode menjalankan (`RAMES_MODE`) dan environment runtime | [`03-mode-menjalankan.md`](./ARCHITECTURE/03-mode-menjalankan.md) |
| 4.1 | Lapisan HTTP — `app/controller/` | [`04-01-layer-http-controller.md`](./ARCHITECTURE/04-01-layer-http-controller.md) |
| 4.2 | Middleware — `app/middleware/` | [`04-02-middleware.md`](./ARCHITECTURE/04-02-middleware.md) |
| 4.3 | Lapisan bisnis — `app/library/` | [`04-03-layer-bisnis-library.md`](./ARCHITECTURE/04-03-layer-bisnis-library.md) |
| 4.4 | Background worker — `cli/deploy.php` | [`04-04-background-worker.md`](./ARCHITECTURE/04-04-background-worker.md) |
| 4.5 | Storage (SQLite, JSON runtime, berkas) | [`04-05-storage.md`](./ARCHITECTURE/04-05-storage.md) |
| 4.6 | View & aset statis — `app/view/` + `public/` | [`04-06-view-dan-aset.md`](./ARCHITECTURE/04-06-view-dan-aset.md) |
| 4.7 | Skrip host & bootstrap | [`04-07-skrip-host-bootstrap.md`](./ARCHITECTURE/04-07-skrip-host-bootstrap.md) |
| 5.1–5.4 | Siklus hidup app: create (git/compose/template), rebuild, stop/start, delete | [`05-01-siklus-hidup-app.md`](./ARCHITECTURE/05-01-siklus-hidup-app.md) |
| 5.5–5.5a | Autentikasi dan profil (ubah password self-service) | [`05-02-autentikasi-profil.md`](./ARCHITECTURE/05-02-autentikasi-profil.md) |
| 5.6–5.6b | Custom domain, SSL per domain, rute proxy, subdomain | [`05-03-domain-ssl-subdomain.md`](./ARCHITECTURE/05-03-domain-ssl-subdomain.md) |
| 5.7–5.10 | Rollback, network, terminal, kepemilikan & sharing | [`05-04-rollback-network-terminal-sharing.md`](./ARCHITECTURE/05-04-rollback-network-terminal-sharing.md) |
| 5.11–5.12a | Log container/deploy, batalkan build, nama container, batas resource | [`05-05-log-deploy-container-limits.md`](./ARCHITECTURE/05-05-log-deploy-container-limits.md) |
| 5.13–5.15 | Monitoring (host & per-app), self-update dashboard, backup volume | [`05-06-monitoring-update-backup.md`](./ARCHITECTURE/05-06-monitoring-update-backup.md) |
| 5.16–5.20 | File manager, Adminer, billing, SQLite, tarik image template | [`05-07-files-database-billing-sqlite-template.md`](./ARCHITECTURE/05-07-files-database-billing-sqlite-template.md) |
| 5.21–5.26 | Multi-server inti: onboarding, create di agent, job async, artefak, registry | [`05-08-multiserver-core.md`](./ARCHITECTURE/05-08-multiserver-core.md) |
| 5.27–5.35 | Multi-server operasional: Nginx/SSL, log/terminal, file, monitor, DB/network/volume, backup, versi agen & status job update agen | [`05-09-multiserver-operasional.md`](./ARCHITECTURE/05-09-multiserver-operasional.md) |
| 5.36–5.44 | SSL AJAX, env per app, domain parking, installer, branding, tema, rekam error, antrean aktivasi SSL | [`05-10-fitur-lain.md`](./ARCHITECTURE/05-10-fitur-lain.md) |
| 6 | Keputusan teknis penting | [`06-keputusan-teknis.md`](./ARCHITECTURE/06-keputusan-teknis.md) |
| 7 | Keamanan | [`07-keamanan.md`](./ARCHITECTURE/07-keamanan.md) |
| 8 | Batasan dan future work | [`08-batasan-future-work.md`](./ARCHITECTURE/08-batasan-future-work.md) |
| GOTCHA-1 | Jebakan multi-server (F0–F4) | [`09-gotcha-multiserver.md`](./ARCHITECTURE/09-gotcha-multiserver.md) |
| GOTCHA-2 | Jebakan tema tampilan (mode gelap) | [`10-gotcha-tema.md`](./ARCHITECTURE/10-gotcha-tema.md) |

## Cara Menjaga Dokumen

- Perubahan perilaku wajib memperbarui file bagian yang tepat (bukan hanya index ini).
- Jangan mengubah nomor section yang sudah dirujuk kode/SPECS; tambahkan subsection baru bila perlu.
- Jebakan baru dicatat sebagai **GOTCHA/WAJIB/DILARANG** di file bagian terkait atau di `09-gotcha-multiserver.md`.
