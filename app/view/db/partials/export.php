<?php /** Export dump. */ ?>
<section class="card">
  <div class="card-header"><h2 class="h6 mb-0">Export Database</h2></div>
  <div class="card-body">
    <p class="text-muted small">Dump dijalankan via <span class="mono">mysqldump</span>/<span class="mono">mariadb-dump</span> di dalam container (single-transaction + routines + triggers).</p>
    <form method="post" action="/database/<?= e($c) ?>/export">
      <?= csrf_field() ?>
      <input type="hidden" name="table" value="<?= e($table) ?>">
      <input type="hidden" name="mode" value="export">
      <div class="mb-3">
        <label class="form-label small">Database</label>
        <select name="db" class="form-select mono">
          <option value="">— Semua database —</option>
          <?php foreach ($databases as $d): ?>
            <option value="<?= e($d) ?>" <?= $d === $db ? 'selected' : '' ?>><?= e($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary">Unduh .sql</button>
    </form>
  </div>
</section>
