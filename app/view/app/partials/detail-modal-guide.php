<?php if (($guideHtml ?? '') !== ''): ?>
<!-- Modal panduan: HTML hasil sanitasi Markdown::toHtml() — sengaja dirender tanpa e(). -->
<div class="modal fade" id="app-guide-modal" tabindex="-1" aria-labelledby="app-guide-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 mb-0" id="app-guide-title">Panduan: <?= e($app['name']) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="guide-content"><?= $guideHtml ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
