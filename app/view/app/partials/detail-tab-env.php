  <!-- ============ Tab: Environment ============ -->
  <div class="tab-pane fade" id="tab-env" role="tabpanel" aria-labelledby="tab-env-btn">
    <?php
    $envVars = is_array($app['env'] ?? null) ? $app['env'] : [];
    $envExampleExists = is_file((string) config('deploy.apps_path') . '/' . $app['name'] . '/.env.example');
    ?>

    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Environment Variables</h2>
    <?php if ($envExampleExists && $canEnv): ?>
      <form method="post" action="/apps/<?= e($app['id']) ?>/env/import" class="d-inline">
        <?= csrf_field() ?>
        <button class="btn btn-outline-secondary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>⇩ Import dari .env.example</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="">
    <?php if (!$canEnv): ?>
      <p class="text-muted small mb-0 p-3">
        Environment variable app ini hanya bisa dilihat user dengan hak <strong>Operator</strong> ke atas
        (nilainya bisa berisi kredensial database/API).
      </p>
    <?php else: ?>
    <form method="post" action="/apps/<?= e($app['id']) ?>/env" id="env-form">
      <?= csrf_field() ?>
      <?php if (empty($envVars)): ?>
        <p class="text-muted small mb-3">Belum ada environment variable. Tambahkan variabel untuk aplikasi app (mis. kredensial database), lalu klik <strong>Simpan &amp; Terapkan</strong>.</p>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table align-middle mb-3" id="env-table">
          <thead>
            <tr>
              <th class="w-40">Key</th>
              <th>Value</th>
              <th class="text-end" style="width:70px;">Hapus</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 0; foreach ($envVars as $k => $v): ?>
            <tr data-env-row>
              <td><input type="text" name="env[<?= $i ?>][key]" value="<?= e((string) $k) ?>" class="form-control form-control-sm mono" placeholder="APP_KEY" required></td>
              <td>
                <div class="input-group input-group-sm">
                  <input type="password" name="env[<?= $i ?>][value]" value="<?= e((string) $v) ?>" class="form-control mono env-value" placeholder="nilai" autocomplete="off">
                  <button type="button" class="btn btn-outline-secondary env-reveal" tabindex="-1" title="Tampilkan / sembunyikan nilai">👁</button>
                </div>
              </td>
              <td class="text-end">
                <input class="form-check-input" type="checkbox" name="env_delete[]" value="<?= e((string) $k) ?>" title="Hapus variabel ini">
              </td>
            </tr>
            <?php $i++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="p-3">
        <p class="text-muted small mb-3">
          Nilai ter-mask; klik 👁 untuk melihat. Perubahan diterapkan dengan menciptakan
          ulang container yang env-nya berubah (tanpa rebuild source). Variabel tersedia
          untuk substitusi <code>${VAR}</code> di <span class="mono">docker-compose.yml</span>
          dan di-inject ke environment <strong>semua container</strong> app.
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="env-add-row">＋ Tambah variabel</button>
          <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
          <?php if ($isBusy): ?>
            <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  var table = document.getElementById('env-table');
  var addBtn = document.getElementById('env-add-row');
  if (!table || !addBtn) return;
  var rowIndex = <?= (int) count($envVars) ?>;
  addBtn.addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.setAttribute('data-env-row', '');
    tr.innerHTML =
      '<td><input type="text" name="env[' + rowIndex + '][key]" class="form-control form-control-sm mono" placeholder="APP_KEY"></td>' +
      '<td><div class="input-group input-group-sm">' +
        '<input type="password" name="env[' + rowIndex + '][value]" class="form-control mono env-value" placeholder="nilai" autocomplete="off">' +
        '<button type="button" class="btn btn-outline-secondary env-reveal" tabindex="-1" title="Tampilkan / sembunyikan nilai">👁</button>' +
      '</div></td>' +
      '<td class="text-end"><input class="form-check-input" type="checkbox" name="env_delete[]" title="Hapus variabel ini"></td>';
    table.querySelector('tbody').appendChild(tr);
    rowIndex++;
  });

  // Reveal/sembunyikan nilai (delegasi — ikut berlaku untuk baris baru)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.env-reveal');
    if (!btn) return;
    var input = btn.parentElement.querySelector('.env-value');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
  });
})();
</script>
  </div>
