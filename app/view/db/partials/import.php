<?php /** Import restore. */ ?>
<section class="card">
  <div class="card-header"><h2 class="h6 mb-0">Import Database</h2></div>
  <div class="card-body">
    <p class="text-muted small">Restore file <span class="mono">.sql</span> ke database via client <span class="mono">mysql</span>/<span class="mono">mariadb</span> di dalam container.</p>
    <form method="post" action="/database/<?= e($c) ?>/import" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="table" value="<?= e($table) ?>">
      <input type="hidden" name="mode" value="import">
      <div class="mb-3">
        <label class="form-label small">Database tujuan</label>
        <select name="db" class="form-select mono" required>
          <option value="" disabled>— pilih database —</option>
          <?php foreach ($databases as $d): ?>
            <option value="<?= e($d) ?>" <?= $d === $db ? 'selected' : '' ?>><?= e($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label small">File .sql</label>
        <input type="file" name="sql" class="form-control" accept=".sql,.txt" required>
      </div>
      <button class="btn btn-primary" onclick="return confirm('Import akan MENIMPA isi database tujuan. Lanjutkan?');">Import</button>
    </form>
  </div>
</section>
