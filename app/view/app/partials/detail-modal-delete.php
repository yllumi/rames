<?php if (!$isBusy && $canDelete): ?>
<!-- Modal konfirmasi delete: pilih volume yang dipertahankan -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="/apps/<?= e($app['id']) ?>/delete" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">Hapus app <?= e($app['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Container, config Nginx, dan direktori lokal akan dihapus.
          <strong>Volume yang dicentang dipertahankan</strong> dan akan dipakai
          ulang otomatis bila app dibuat ulang dengan nama
          <span class="mono"><?= e($app['name']) ?></span>
          (data seperti database tidak hilang).
        </p>
        <?php if (empty($volumes)): ?>
          <div class="alert alert-info py-2 small mb-0">Tidak ada named volume terdeteksi untuk project ini (atau Docker Engine tidak dapat diakses).</div>
        <?php else: ?>
          <label class="form-label fw-semibold">Pilih volume yang dipertahankan:</label>
          <div class="border rounded p-2 mb-2" style="max-height:220px; overflow-y:auto;">
            <?php foreach ($volumes as $v): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="preserve_volumes[]" value="<?= e($v) ?>" id="vol-<?= e($v) ?>" checked>
                <label class="form-check-label small mono" for="vol-<?= e($v) ?>"><?= e($v) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="text-muted small mb-0">Volume yang <strong>tidak</strong> dicentang ikut dihapus.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" name="mode" value="preserve" class="btn btn-danger btn-sm">Hapus &amp; pertahankan volume</button>
        <button type="submit" name="mode" value="purge" class="btn btn-outline-danger btn-sm"
                onclick="return confirm('Hapus TOTAL app <?= e($app['name']) ?> termasuk SEMUA volume (data database dll. ikut terhapus permanen)? Tindakan ini tidak bisa dibatalkan.');">Hapus total (semua volume)</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
