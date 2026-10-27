# GOTCHA tema tampilan (mode gelap, §5.42)

> Bagian dari [dokumentasi arsitektur](../ARCHITECTURE.md) · Spesifikasi: [SPECS.md](../SPECS.md) · Panduan operasional: [DETAILS.md](../../DETAILS.md)

Lima jebakan dari fitur murni-klien ini — bukan bagian daftar multi-server di atas; format tetap gejala → sebab → penangkal.

> **T1. WAJIB — skrip boot tema berada di `<head>` SEBELUM stylesheet.** **Gejala**: halaman sempat tampil terang (atau gelap) satu frame lalu berubah — *flash of incorrect theme*, terutama pada dark mode. **Sebab**: atribut `data-bs-theme` disetel setelah CSS/komponen sudah dilukis. **Penangkal**: `app/view/partials/theme-boot.php` **wajib** di-include di `<head>` **sebelum** `<link rel="stylesheet">` pada ketiga halaman (`partials/header.php`, `auth/login.php`, `index/hello.php`); jangan memindahkannya ke footer atau menggabungkannya ke `theme.js` (skrip footer terlambat).

> **T2. DILARANG — memakai nilai `system` pada `data-bs-theme`.** **Gejala**: tema tidak berubah di mode `system`, atau komponen Bootstrap kembali ke default terang walau OS gelap. **Sebab**: Bootstrap 5.3 hanya mengenal `light|dark`; nilai lain diabaikan. **Penangkal**: `data-bs-theme` **selalu** nilai ter-resolve (`resolve(mode)`); `system` hanya boleh ada di `data-theme-mode` (pemilih ikon) dan di `localStorage`.

> **T3. GOTCHA — `bg-white`/`bg-light` Bootstrap mengunci warna terang di tema gelap.** **Gejala**: header kartu/panel tetap putih menyilaukan saat dark mode. **Sebab**: utilitas itu warna **literal**, bukan variabel tema. **Penangkal**: pakai `bg-body`/`bg-body-tertiary` (mengikuti `--bs-body-bg`/`--bs-tertiary-bg`); bila menemukan `bg-white`/`bg-light` baru di view, ganti — jangan menambal dengan override CSS per halaman.

> **T4. GOTCHA — kartu Bootstrap di dark memakai `--bs-card-bg = --bs-body-bg` sehingga menyatu dengan latar.** **Gejala**: batas kartu hilang di tema gelap (kartu tampak menyatu dengan halaman). **Sebab**: default Bootstrap dark menyetel `--bs-card-bg` sama dengan body. **Penangkal**: blok `[data-bs-theme="dark"]` di `public/css/app.css` **wajib** menyetel `--bs-card-bg: var(--bs-secondary-bg)`; jangan menghapus override ini saat merapikan token.

> **T5. CATATAN — media logo template sengaja tetap terang di kedua tema.** **Gejala**: `.template-tile-media` terlihat sebagai kotak terang di dark mode dan bisa dilaporkan sebagai "tema tidak konsisten". **Sebab**: logo SVG/PNG transparan umumnya berwarna gelap sehingga tak terbaca di atas latar gelap. **Penangkal**: pertahankan `--template-media-bg` (`#f8fafb` light, `#e7edf0` dark) dan **jangan** mengubahnya menjadi `--bs-body-bg`; ini pengecualian yang disengaja (SPECS §7.17), sama seperti teks non-kode log/terminal yang tetap gelap.
