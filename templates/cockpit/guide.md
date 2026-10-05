## Ringkasan

Cockpit CMS Core adalah CMS headless ringan dengan penyimpanan SQLite bawaan.
Cocok untuk mengelola konten (koleksi, halaman, aset) dan mengeksposnya sebagai
API, tanpa perlu menyiapkan database eksternal. Instalasi awal dilakukan
langsung dari browser.

## Yang disiapkan template

| Item | Nilai |
|------|-------|
| Service | `cockpit` |
| Image | `cockpithq/cockpit:core-latest` |
| Port container | `80` (di-proxy Rames ke domain/subdomain app) |
| Named volume | `cockpit-storage` → `/var/www/html/storage` |

Tidak ada service lain (tanpa database eksternal).

## Variabel environment

Template ini tidak mendeklarasikan variabel environment apa pun — tidak ada yang
perlu diisi saat create.

## Setelah deploy

1. Tunggu container `cockpit` berstatus berjalan.
2. Buka domain/subdomain app di browser.
3. Rames mengarahkan ke halaman instalasi — selesaikan lewat path `/install`:
   buat akun admin pertama (username + password), lalu simpan.
4. Setelah instalasi selesai, halaman `/install` yang sama dipakai untuk login.

> Tidak ada akun default — simpan kredensial admin pertama dengan aman.

## Akses & domain

- Port container yang di-proxy Rames ke domain: `80`.
- Path penting: `/install` (instalasi awal sekaligus login).
- API/headless tersedia setelah login, di bawah domain yang sama.

## Catatan

- Seluruh data (SQLite + aset) berada di named volume `cockpit-storage` pada
  `/var/www/html/storage`, sehingga bertahan saat container dibuat ulang.
- Sertakan named volume tersebut pada strategi backup Rames.
- Dokumentasi resmi: [Cockpit Documentation](https://getcockpit.com/documentation/)
