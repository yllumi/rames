<?php /** Struktur tabel: kolom + index. */ ?>
<?php if ($table === ''): ?>
  <div class="card"><div class="card-body text-muted small">Pilih tabel untuk melihat strukturnya.</div></div>
<?php else: ?>
<section class="card mb-3">
  <div class="card-header"><h2 class="h6 mb-0">Kolom — <?= e($db) ?>.<?= e($table) ?></h2></div>
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
      <thead class="table-light">
        <tr><th>Kolom</th><th>Tipe</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>
      </thead>
      <tbody>
        <?php foreach ($columns as $col): ?>
          <tr>
            <td class="mono"><?= e($col['field']) ?></td>
            <td class="small mono"><?= e($col['type']) ?><?= $col['collation'] !== null ? '<br><span class="text-muted">' . e($col['collation']) . '</span>' : '' ?></td>
            <td class="small"><?= $col['null'] ? 'YES' : 'NO' ?></td>
            <td class="small"><?= e($col['key']) ?></td>
            <td class="small mono"><?= $col['default'] !== null ? e($col['default']) : '<span class="text-muted fst-italic">NULL</span>' ?></td>
            <td class="small"><?= e($col['extra']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <div class="card-header"><h2 class="h6 mb-0">Index</h2></div>
  <?php if (empty($indexes)): ?>
    <div class="card-body text-muted small">Tidak ada index.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
      <thead class="table-light"><tr><th>Key</th><th>Kolom</th><th>Unique</th><th>Tipe</th></tr></thead>
      <tbody>
        <?php foreach ($indexes as $ix): ?>
          <tr>
            <td class="mono"><?= e($ix['key_name']) ?></td>
            <td class="mono small"><?= e($ix['column']) ?></td>
            <td class="small"><?= $ix['unique'] ? 'Ya' : 'Tidak' ?></td>
            <td class="small"><?= e($ix['type']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
