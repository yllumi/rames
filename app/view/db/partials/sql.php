<?php /** SQL editor + hasil query (dibaca dari $lastResult). */ ?>
<section class="card mb-3">
  <div class="card-header"><h2 class="h6 mb-0">SQL Editor</h2></div>
  <div class="card-body">
    <form method="post" action="/database/<?= e($c) ?>/query">
      <?= csrf_field() ?>
      <input type="hidden" name="db" value="<?= e($db) ?>">
      <input type="hidden" name="table" value="<?= e($table) ?>">
      <input type="hidden" name="mode" value="sql">
      <div class="mb-3">
        <textarea name="sql" rows="6" class="form-control mono" placeholder="SELECT * FROM ..."><?= e($lastSql ?? '') ?></textarea>
        <div class="form-text">Satu statement per eksekusi. Hasil SELECT dibatasi <?= e((int) config('deploy.db_max_rows', 500)) ?> baris.</div>
      </div>
      <button class="btn btn-primary">Jalankan</button>
    </form>
  </div>
</section>

<?php if ($lastResult !== null): ?>
<section class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Hasil</h2>
    <span class="text-muted small"><?= e($lastResult['elapsedMs'] ?? 0) ?> ms</span>
  </div>
  <div class="card-body">
    <?php if (!empty($lastResult['isSelect'])): ?>
      <?php if ($lastResult['rows'] === []): ?>
        <p class="text-muted small mb-0">Tidak ada baris yang dikembalikan.</p>
      <?php else: ?>
        <?php if (!empty($lastResult['truncated'])): ?>
          <div class="alert alert-warning py-2 small">Hasil dipotong (maks. <?= e((int) config('deploy.db_max_rows', 500)) ?> baris).</div>
        <?php endif; ?>
        <div class="table-responsive" style="max-height:480px; overflow:auto;">
          <table class="table table-sm table-striped align-top mb-0">
            <thead class="table-light">
              <tr>
                <?php foreach ($lastResult['columns'] as $col): ?><th class="mono"><?= e((string) $col) ?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lastResult['rows'] as $r): ?>
                <tr>
                  <?php foreach ($lastResult['columns'] as $col): $v = $r[$col] ?? null; ?>
                    <td class="small"><?= $v === null ? '<span class="text-muted fst-italic">NULL</span>' : e(mb_strimwidth((string) $v, 0, 300, '…')) ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="mb-0">Statement berhasil — <strong><?= e((int) $lastResult['affected']) ?></strong> baris terpengaruh.</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
