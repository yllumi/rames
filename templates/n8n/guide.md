## Ringkasan

n8n adalah platform otomasi workflow self-hosted dengan editor visual berbasis
web. Template ini menjalankan n8n dengan database internal (SQLite default) dan
penyimpanan persisten di named volume.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `n8n` |
| Image | `docker.n8n.io/n8nio/n8n:latest` |
| Port container | `5678` (di-proxy ke domain) |
| Named volume | `n8n-data` → `/home/node/.n8n` |

## Variabel environment

| Key | Status | Keterangan |
|-----|--------|------------|
| `N8N_ENCRYPTION_KEY` | Auto (`generate: secret`) | Kunci enkripsi kredensial tersimpan. **Gunakan nilai sama** bila memakai volume lama. |
| `GENERIC_TIMEZONE` | Default `Asia/Jakarta` | Timezone instans. |
| `N8N_SECURE_COOKIE` | Default `false` | Isi `true` bila app hanya diakses via HTTPS. |

## Setelah deploy

1. Buka domain app.
2. n8n menampilkan wizard pembuatan **akun owner** (nama, email, password).
   Isi dan simpan; tidak ada akun default.
3. Setelah login, editor workflow tersedia di `/`.

> Simpan `N8N_ENCRYPTION_KEY`: bila hilang/diubah, kredensial tersimpan tidak
> bisa didekripsi lagi.

## Akses & domain

- Port container yang di-proxy Rames: `5678`.
- Editor workflow: `/`. Webhook produksi: `/webhook/...`.

## Catatan

- Data n8n (database, kredensial, workflow) berada di volume `n8n-data`.
  Sertakan pada backup.
- Dokumentasi resmi: [n8n Docker](https://docs.n8n.io/hosting/installation/docker/)
