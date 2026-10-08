## Ringkasan

Template ini membuat wadah LEMP lengkap — **PHP 8.4 + PHP-FPM**, **Nginx**, dan
**MySQL 8.4** — dalam satu app. Cocok untuk menaruh aplikasi PHP Anda sendiri
secara manual, gaya shared hosting: cukup salin file ke dalam wadah lewat tab
Terminal, tanpa proses build.

| Service | Image | Port container | Named volume → mount |
|---------|-------|----------------|----------------------|
| `web` | `serversideup/php:8.4-fpm-nginx` | `8080` (di-proxy ke domain) | `app-code` → `/var/www/html` |
| `mysql` | `mysql:8.4` | `3306` (internal, tidak dipublikasikan) | `mysql-data` → `/var/lib/mysql` |

`web` menunggu `mysql` sehat sebelum dijalankan.

## Di mana kode disimpan

- Seluruh kode berada di named volume **`app-code`**, di-mount ke
  **`/var/www/html`**.
- **Docroot publik adalah `/var/www/html/public`.** Hanya isi folder `public/`
  yang dapat diakses dari browser; file di luarnya (termasuk `vendor/`,
  konfigurasi, dan file `.env`) aman dari akses langsung.
- Volume ini **per app**: app lain dengan nama berbeda memakai volume terpisah.

## Cara menaruh aplikasi

1. Buka halaman app ini, lalu tab **Terminal**. Terminal berjalan sebagai user
   default container (**`www-data`**), sehingga file yang Anda buat di sana
   otomatis dimiliki user yang benar.
2. Buat struktur aplikasi, misalnya `mkdir -p /var/www/html/public` lalu tulis
   `index.php` di dalamnya.
3. Volume baru dimulai **kosong** — sebelum ada file, membuka domain akan
   menampilkan **404/blank**. Ini **normal**, bukan kegagalan deploy.

> **PERINGATAN kepemilikan file.** File yang ditaruh sebagai **root** (mis. hasil
> `docker cp`, atau perintah yang dijalankan sebagai root) menjadi milik `root`
> dan **tidak bisa** ditulis/diubah oleh `www-data`. Perbaikannya, jalankan
> sebagai root: `chown -R www-data:www-data /var/www/html`.

## Database

| Item | Nilai |
|------|-------|
| Host | `mysql` (**bukan** `127.0.0.1`) |
| Port | `3306` |
| Database awal | `app` |
| User | `root` |
| Password | tab **Environment** app (nilai `MYSQL_ROOT_PASSWORD`) |

Nilai-nilai ini juga tersedia untuk aplikasi sebagai `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD`. Contoh koneksi PDO:

```php
<?php
$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT')
        . ';dbname=' . getenv('DB_DATABASE') . ';charset=utf8mb4',
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

> Untuk produksi, sebaiknya **jangan** memakai `root` untuk aplikasi. Buat
> **user terbatas** dari **Adminer** di halaman `/database` (login `root` dengan
> password dari tab **Environment**; bila kredensial tak terdeteksi, form login
> Adminer muncul dengan Server ter-prefill), lalu ubah
> `DB_USERNAME`/`DB_PASSWORD` di tab **Environment** dan lakukan **Deploy Ulang**.

## Import/export dump & kelola database

Kelola server MySQL (buat database/user, GRANT, import & export dump) lewat
**Adminer** di halaman **`/database`**: Rames menyalakan helper Adminer internal
(tanpa port publik) dan menyambung sebagai **root**; bila kredensial tidak
terdeteksi, login lewat form Adminer dengan Server ter-prefill. Port `3306`
sengaja **tidak dipublikasikan ke host**. Untuk dump/import **besar**, pakai tab
**Terminal** atau fitur **Volume/backup** — proxy Adminer dibatasi ≤30 detik &
ukuran respons.

## HTTPS

TLS ditangani Rames/Nginx di host, termasuk saat app dipakai lewat domain atau
subdomain. Nginx host meneruskan header **`Host`**, **`X-Real-IP`**,
**`X-Forwarded-For`**, dan **`X-Forwarded-Proto`** ke container.

Agar aplikasi mendeteksi HTTPS dengan benar, aplikasi harus **mempercayai proxy
header** (mis. mengaktifkan *trust proxy* pada framework yang dipakai) dan
membaca skema dari `X-Forwarded-Proto`. Jangan mengandalkan `SSL_MODE=mixed`
image — mode itu terbukti **tidak** mengubah `$_SERVER['HTTPS']` (HTTP tetap
200 tanpa redirect), jadi deteksi skema harus lewat header proxy.

## Konfigurasi PHP

Field PHP yang tersedia di form (ubah lewat tab **Environment** → **Deploy Ulang**):

| Key | Default | Keterangan |
|-----|---------|------------|
| `PHP_MEMORY_LIMIT` | `256M` | Nilai `memory_limit`. |
| `PHP_DISPLAY_ERRORS` | `Off` | `On` untuk debug, `Off` untuk produksi. |
| `TZ` | `Asia/Jakarta` | Zona waktu container + `PHP_DATE_TIMEZONE`. |

Sisanya adalah **konstanta di `docker-compose.yml`**, bukan field form:
`PHP_OPCACHE_ENABLE=1` (opcache aktif untuk performa), `NGINX_WEBROOT`,
`APP_BASE_DIR`, dan seluruh `DB_*`. Untuk mengubahnya, sunting lewat tab
**Compose** lalu **Deploy Ulang**.

Batas upload/post/client-body bawaan image sudah **100M** dan tidak perlu diubah.

## Backup

Volume `app-code` (kode aplikasi) dan `mysql-data` (data MySQL) tercakup di
halaman **Volume** pada dashboard (backup & restore). Ingat bahwa `app-code`
adalah **sumber aplikasi** Anda — sertakan selalu pada backup.

## Batasan

- App yang dibuat dari template berjalan dalam **mode compose** → **tidak ada
  rollback** otomatis. Uji perubahan pada instance terpisah sebelum produksi.
- PHP berjalan di versi **8.4**; pastikan aplikasi lama Anda kompatibel.
- Satu stack = **satu container web**. Template ini bukan untuk banyak vhost
  dalam satu container — buat app terpisah bila perlu beberapa situs.
