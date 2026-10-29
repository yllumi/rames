<?php
// Partial tombol "Tentang halaman ini" — di-include **di dalam** `<h1>` halaman.
// Ikon info membuka modal penjelasan panjang (`partials/page-info-modal.php`);
// deskripsi di bawah judul cukup satu baris ringkas. Presentasi murni.
//
// Variabel (prefix `__` agar tidak bentrok dengan data halaman):
//   $__infoTarget — id modal tujuan (tanpa `#`), mis. `volumes-info`.
// Tanpa id, tidak ada yang bisa dibuka ⇒ partial tidak merender apa pun.
$__infoTarget = (string) ($__infoTarget ?? '');
?>
<?php if ($__infoTarget !== ''): ?>
<button type="button" class="btn btn-link btn-sm text-muted p-0 ms-1 align-text-top"
        style="vertical-align: text-top; line-height: 1;"
        data-bs-toggle="modal" data-bs-target="#<?= e($__infoTarget) ?>"
        title="Tentang halaman ini" aria-label="Tentang halaman ini">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor"
       stroke-width="1.4" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
    <circle cx="8" cy="8" r="6.4"></circle>
    <path d="M8 7.2v4.4" stroke-linecap="round"></path>
    <circle cx="8" cy="4.6" r="0.9" fill="currentColor" stroke="none"></circle>
  </svg>
</button><?php endif; ?>
