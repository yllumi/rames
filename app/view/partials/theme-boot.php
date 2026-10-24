<?php
// Satu-satunya sumber boot tema (anti-flash) untuk semua halaman yang punya
// kontrol tema: `partials/header.php`, `auth/login.php`, dan `index/hello.php`.
// Wajib di-include di dalam <head> SEBELUM stylesheet agar tidak ada kedipan
// tema terang/gelap. Tidak mendeklarasikan variabel apa pun ke scope view.
?>
<meta name="color-scheme" content="light dark">
<script>
  // Terapkan tema sedini mungkin (sebelum stylesheet) agar tidak ada flash terang/gelap.
  (function () {
    try {
      var mode = localStorage.getItem('rames.theme');
      if (mode !== 'light' && mode !== 'dark' && mode !== 'system') { mode = 'system'; }
      var resolved = mode;
      if (mode === 'system') {
        resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
      }
      var root = document.documentElement;
      root.setAttribute('data-bs-theme', resolved);
      root.style.colorScheme = resolved;
      root.setAttribute('data-theme-mode', mode);
    } catch (e) {}
  })();
</script>
