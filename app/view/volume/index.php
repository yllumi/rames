<?php $pageTitle = 'Volumes'; $active = 'volumes'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$orphanCount = 0;
foreach ($rows as $r) {
    if ($r['orphaned']) {
        $orphanCount++;
    }
}
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Volumes</h1>
    <p class="text-muted mb-0">
      Volume ber-label <span class="mono">docker compose</span>. Volume <strong>yatim</strong>
      ditinggalkan app yang dihapus dengan mode "pertahankan volume" — bisa dibersihkan di sini.
    </p>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/apps">&larr; Apps</a>
</div>

<?php if ($engineError): ?>
  <div class="alert alert-warning" role="alert"><?= e($engineError) ?></div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="card">
    <div class="card-body text-muted small">Tidak ada volume ber-label compose.</div>
  </div>
<?php else: ?>
<form method="post" action="/volumes/purge">
  <?= csrf_field() ?>
  <div class="card">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:32px"></th>
          <th>Volume</th>
          <th>Project</th>
          <th>Driver</th>
          <th>Mountpoint</th>
          <th>Dibuat</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <?php if ($r['orphaned']): ?>
              <input class="form-check-input" type="checkbox" name="volumes[]" value="<?= e($r['name']) ?>" id="vol-<?= e($r['name']) ?>">
            <?php endif; ?>
          </td>
          <td><span class="mono"><?= e($r['name']) ?></span></td>
          <td><span class="mono"><?= e($r['project']) ?></span></td>
          <td class="small"><?= e($r['driver']) ?></td>
          <td class="small text-muted"><?= e($r['mountpoint']) ?></td>
          <td class="small"><?= e($r['created_at']) ?></td>
          <td>
            <?php if ($r['orphaned']): ?>
              <span class="badge badge-error">yatim</span>
            <?php else: ?>
              <span class="badge badge-running">dipakai</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small me-auto"><?= count($rows) ?> volume (<?= $orphanCount ?> yatim)</span>
      <button type="submit" class="btn btn-danger btn-sm"
              onclick="return confirm('Hapus volume yang dipilih? Data pada volume itu hilang permanen.');">Hapus volume terpilih</button>
      <?php if ($orphanCount > 0): ?>
        <button type="submit" name="purge_orphans" value="1" class="btn btn-outline-danger btn-sm"
                onclick="return confirm('Hapus SEMUA volume yatim (<?= $orphanCount ?> volume)? Data pada volume itu hilang permanen.');">Purge semua yatim</button>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
