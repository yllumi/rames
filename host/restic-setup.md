# Panduan Setup Backup Volume ke S3 (restic)

Halaman ini memandu menyiapkan backup volume Docker ke object storage S3 memakai
**restic** (inkremental + dedup + terenkripsi). Fitur ini **terpisah** dari backup
data dashboard (`database/*.json`) — jangan mencampur konfigurasinya.

> **Penting — ada DUA rahasia berbeda (jangan tertukar):**
> 1. **Kredensial S3** (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`) — di `.env`.
> 2. **Passphrase restic** — kunci enkripsi isi repo, disimpan di **file**
>    `database/restic/password`. **Bila passphrase ini hilang, semua backup tidak
>    bisa dipulihkan.**

---

## Prasyarat

- Image dashboard memuat binary `restic`. Pada instalasi lama, rebuild dulu:
  ```bash
  docker compose up -d --build
  docker exec rames-webman restic version   # bukti restic tersedia
  ```
- Service dashboard di `docker-compose.yml` tetap punya blok `dns:` (dipakai helper
  restic agar bisa me-resolve endpoint S3 bila `resolv.conf` host bermasalah).

## 1. Siapkan bucket & kredensial S3

- Sediakan bucket S3 dan satu **access key**. Beri izin pada bucket/prefix:
  `s3:ListBucket`, `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:GetBucketLocation`.
- Tentukan **prefix** untuk repo (mis. `rames`).

## 2. Isi variabel di `.env`

Tambahkan (contoh; ganti sesuai provider/region Anda):

```dotenv
RESTIC_REPOSITORY=s3:https://s3.amazonaws.com/<BUCKET>/rames
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-southeast-1
# RESTIC_PASSWORD_FILE biarkan kosong → otomatis {proyek}/database/restic/password
```

> **Jangan** menyematkan kredensial di dalam nilai `RESTIC_REPOSITORY` (nilai repo
> masuk argv proses helper dan terlihat di `ps`). Cukup endpoint + bucket + prefix.

## 3. Buat file passphrase restic

Passphrase **bukan** kredensial S3 — ini kunci enkripsi repo. Buat sekali dan
**simpan salinannya di tempat aman** (password manager):

```bash
mkdir -p database/restic && chmod 700 database/restic
openssl rand -base64 32 > database/restic/password
chmod 600 database/restic/password
```

Folder `database/restic/` sudah gitignored — jangan pernah di-commit.

## 4. Terapkan konfigurasi ke container

Perubahan `.env` butuh recreate container:

```bash
docker compose up -d
docker exec rames-webman sh -c 'echo "$RESTIC_REPOSITORY | $AWS_DEFAULT_REGION | ${AWS_ACCESS_KEY_ID:+key-ok}"'
```

## 5. Inisialisasi repo restic (sekali saja)

Repo harus di-`init` sebelum dipakai (tidak dilakukan otomatis):

```bash
docker exec -w "$PWD" rames-webman sh -c \
  'restic --repo "$RESTIC_REPOSITORY" --password-file "$PWD/database/restic/password" init'
```

Verifikasi koneksi (harus mencetak `no snapshots`/`0 snapshots`, bukan error):

```bash
docker exec -w "$PWD" rames-webman sh -c \
  'restic --repo "$RESTIC_REPOSITORY" --password-file "$PWD/database/restic/password" snapshots'
```

## 6. Pasang timer harian (host)

Timer host menjalankan backup tiap hari (02:30) lewat `host/backup.sh`:

```bash
cd ~/rames && sudo ./host/install.sh
systemctl list-timers | grep volume-backup
```

## 7. Jalankan & verifikasi

- **Backup sekarang** (per volume) atau seluruhnya:
  ```bash
  docker exec rames-webman php cli/backup.php run
  ```
- Buka halaman **Backup**, klik **Segarkan status** untuk memuat katalog, lalu cek
  snapshot/riwayat. Status run: `runtime/backup/status.json`, log
  `runtime/logs/backup/*.log`.

## 8. Memilih volume yang dibackup berkala

- Kolom **Berkala** di halaman Backup menentukan volume mana yang ikut run harian.
- **Default OFF** untuk volume yang **belum pernah** ter-backup (opt-in); volume
  yang sudah punya snapshot otomatis diaktifkan.
- Hanya **admin** yang bisa mengubah kolom ini.

---

## Troubleshooting

| Gejala | Sebab | Solusi |
|---|---|---|
| Halaman Backup macet di **"Memuat …"** | Halaman memuat dari **cache**; data live hanya saat run/Segarkan | Klik **Segarkan status** (admin) atau tunggu run berikutnya |
| `Access Denied` pada `Stat(<config/>)` | Kredensial salah/region salah, atau env-file berkutip (bug lama) | Pastikan `AWS_*` benar & tanpa kutip; jalankan ulang `docker compose up -d` |
| `Fatal: unable to open config file … repository does not exist` | Repo belum di-`init` | Jalankan langkah **5** (`restic init`) |
| `restic: not found` di dalam container | Image belum memuat restic | Rebuild: `docker compose up -d --build` |
| `Export gagal (exit 127): sh: --host=... not found` | Volume non-DB keliru terdeteksi sebagai container DB | Sudah diperbaiki (deteksi sadar-kapabilitas) — perbarui kode & **Segarkan status** |
| Helper restic gagal resolve endpoint S3 | Helper `docker run` tak mewarisi DNS dashboard | Pastikan blok `dns:` di service dashboard tetap ada |
| Restore **dump** (DB) gagal setelah app dihapus | Import dump butuh container DB hidup | Buat ulang app (nama sama) lalu restore, atau unduh SQL-nya |

## Keamanan

- **Jangan** commit `.env` atau `database/restic/password`.
- Kredensial S3 tidak pernah masuk argv/log/JSON (diteruskan ke helper lewat env-file
  sementara `0600`); passphrase hanya lewat `--password-file` yang di-mount `:ro`.
- Restore bersifat **destruktif** (isi volume ditimpa) dan hanya untuk pemilik app
  (ability `restore`); operasi arsip & refresh status khusus **admin**.
- Retensi snapshot diatur `VOLUME_BACKUP_KEEP_DAILY` / `_WEEKLY` / `_MONTHLY`.
