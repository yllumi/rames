  <!-- ============ Tab: Hapus App ============ -->
  <?php if ($canDelete): ?>
  <div class="tab-pane fade" id="tab-delete" role="tabpanel" aria-labelledby="tab-delete-btn">
    <section class="card mb-4 border-danger">
      <div class="card-header">
        <h2 class="h6 mb-0 text-danger">Danger Zone</h2>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Menghapus app akan menghentikan &amp; menghapus container, config Nginx, dan
          direktori lokal. Pilih mode di dialog konfirmasi:
          <strong>pertahankan volume</strong> (data database tetap ada dan dipakai ulang
          bila app dibuat ulang dengan nama sama) atau <strong>hapus total</strong>
          (termasuk semua volume — data hilang permanen).
        </p>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
        <?php else: ?>
          <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">✕ Delete app</button>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
