<?php if (!$isBusy && !$isImported && !$isCompose && !empty($abilities['deploy'])): ?>
<div class="modal fade" id="branch-change-modal" tabindex="-1"
     aria-labelledby="branch-change-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="/apps/<?= e($app['id']) ?>/branch" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="branch-change-title">Ganti Branch</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="app-branch">Nama branch baru</label>
        <input class="form-control" type="text" id="app-branch" name="branch"
               value="<?= e($app['branch'] ?? 'main') ?>" required
               pattern="[a-zA-Z0-9._/-]+" title="Gunakan nama branch Git yang valid">
        <div class="form-text">Jika build gagal, sistem akan mencoba memulihkan branch dan versi aktif sebelumnya.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Ganti &amp; Deploy</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
