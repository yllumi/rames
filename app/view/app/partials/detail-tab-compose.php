  <?php if ($isCompose && $canCompose): ?>
  <!-- ============ Tab: Compose (app mode compose — edit sumber tanpa Git) ============ -->
  <div class="tab-pane fade" id="tab-compose" role="tabpanel" aria-labelledby="tab-compose-btn">
    <section class="card mb-4">
      <div class="card-header">
        <h2 class="h6 mb-0">Compose <span class="mono"><?= e((string) ($compose['main_file'] ?? 'docker-compose.yml')) ?></span></h2>
      </div>
      <div class="card-body">
        <form method="post" action="/apps/<?= e($app['id']) ?>/compose" enctype="multipart/form-data" id="compose-form">
          <?= csrf_field() ?>

          <div class="mb-3">
            <textarea class="form-control mono" name="compose" rows="18" spellcheck="false" required><?= e((string) ($compose['content'] ?? '')) ?></textarea>
            <div class="form-text">
              Service wajib punya <code>image:</code> dan tidak boleh <code>build:</code> (mode ini tanpa build context).
              Host port dikelola dashboard: port service yang sudah ada dipertahankan, konflik dengan app lain digeser otomatis.
            </div>
          </div>

          <?php $composeFiles = array_values(array_filter(
              (array) ($compose['files'] ?? []),
              static fn (string $f): bool => $f !== (string) ($compose['main_file'] ?? '')
          )); ?>
          <?php if ($composeFiles !== []): ?>
          <div class="mb-3">
            <label class="form-label">File pendukung</label>
            <ul class="list-unstyled mb-1">
              <?php foreach ($composeFiles as $f): ?>
              <li class="d-flex align-items-center gap-2 small">
                <input class="form-check-input mt-0" type="checkbox" name="file_delete[]" value="<?= e($f) ?>" id="del-<?= e(md5($f)) ?>">
                <label class="mono mb-0" for="del-<?= e(md5($f)) ?>"><?= e($f) ?></label>
                <span class="text-muted">(centang untuk hapus)</span>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label" for="compose-add-files">Tambah / ganti file pendukung</label>
            <input type="file" class="form-control" id="compose-add-files" name="files[]" multiple>
            <div class="form-text">
              File dengan nama sama akan ditimpa. Maks 1 MB per file. Isi compose utama diubah lewat editor di atas
              (file <span class="mono"><?= e((string) ($compose['main_file'] ?? 'docker-compose.yml')) ?></span> tidak diunggah ulang).
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Deploy Ulang</button>
            <?php if ($isBusy): ?>
              <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
            <?php else: ?>
              <span class="text-muted small">Container diciptakan ulang di latar belakang (<span class="mono">up -d</span> tanpa build).</span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>
  </div>
  <?php endif; ?>
