<?php
// Derivasi lokal agar partial aman dirender mandiri (tanpa detail-setup.php).
$isImported = (bool) ($isImported ?? false);
?>
  <!-- ============ Tab: Hapus App ============ -->
  <?php if ($canDelete): ?>
  <div class="tab-pane fade" id="tab-delete" role="tabpanel" aria-labelledby="tab-delete-btn">
    <section class="card mb-4 border-danger">
      <div class="card-header">
        <h2 class="h6 mb-0 text-danger"><?= $isImported ? 'Lepas dari dashboard' : 'Danger Zone' ?></h2>
      </div>
      <div class="card-body">
<?php if ($isImported): ?>
        <p class="text-muted small mb-3">
          Melepas app dari dashboard. Container, volume, dan berkas proyek TIDAK dihapus — hentikan/hapus sendiri di terminal proyek.
        </p>
<?php else: ?>
        <p class="text-muted small mb-3">
          Menghapus app akan menghentikan &amp; menghapus container, config Nginx, dan
          direktori lokal. Pilih mode di dialog konfirmasi:
          <strong>pertahankan volume</strong> (data database tetap ada dan dipakai ulang
          bila app dibuat ulang dengan nama sama) atau <strong>hapus total</strong>
          (termasuk semua volume — data hilang permanen).
        </p>
<?php endif; ?>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
        <?php else: ?>
          <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal"><?= $isImported ? 'Lepas dari dashboard' : '✕ Delete app' ?></button>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
