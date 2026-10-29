<?php
// Partial modal "Tentang halaman ini" (Bootstrap) — di-include **setelah**
// `.page-head` halaman, di luar elemen ber-`display:none` apa pun.
//
// Variabel (prefix `__` agar tidak bentrok dengan data halaman):
//   $__infoId    — id modal, mis. `volumes-info` (juga dasar id judul `<id>-title`);
//   $__infoTitle — judul modal, mis. `Tentang Volumes`;
//   $__infoHtml  — HTML **statis yang ditulis view pemanggil** (bukan data dari
//                  user/DB), jadi sengaja dirender tanpa `e()`. View menangkapnya
//                  lewat `ob_start()` agar paragraf lama pindah apa adanya.
// Id & judul selalu di-escape.
$__infoId = (string) ($__infoId ?? '');
$__infoTitle = (string) ($__infoTitle ?? 'Tentang halaman ini');
$__infoHtml = (string) ($__infoHtml ?? '');
?>
<?php if ($__infoId !== ''): ?>
<div class="modal fade" id="<?= e($__infoId) ?>" tabindex="-1" aria-labelledby="<?= e($__infoId) ?>-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h6" id="<?= e($__infoId) ?>-title"><?= e($__infoTitle) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?= $__infoHtml ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
