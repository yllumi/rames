<?php
$pageTitle = 'Database';
$active = 'database';
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <h1 class="h3 mb-0">Database</h1>
  <span class="text-muted small">Kelola MySQL/MariaDB di dalam container (phpMyAdmin mini)</span>
</div>

<?php if ($engineError): ?>
  <div class="alert alert-danger" role="alert"><?= e($engineError) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Container Database</h2>
    <span class="text-muted small"><?= count($rows) ?> container</span>
  </div>
  <?php if (empty($rows)): ?>
    <div class="card-body text-muted small">
      Tidak ada container MySQL/MariaDB yang terdeteksi.
      Deteksi otomatis berdasarkan nama image (<span class="mono">mysql</span>,
      <span class="mono">mariadb</span>, <span class="mono">percona</span>) atau
      environment <span class="mono">MYSQL_*</span> / <span class="mono">MARIADB_*</span>.
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Container</th>
          <th>Image</th>
          <th>Status</th>
          <th>Kepemilikan</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="mono"><?= e($r['container_name']) ?></span></td>
          <td class="small"><?= e($r['image']) ?></td>
          <td><span class="badge badge-<?= e($r['state'] ?? 'unknown') ?>"><?= e($r['state'] ?? 'unknown') ?></span></td>
          <td>
            <?php if ($r['owned']): ?>
              <a class="badge text-bg-primary text-decoration-none" href="/apps/<?= e($r['app_id']) ?>"><?= e($r['app_name']) ?></a>
            <?php else: ?>
              <span class="badge text-bg-secondary">eksternal</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a class="btn btn-outline-primary btn-sm" href="/database/<?= e(rawurlencode($r['container_name'])) ?>">Kelola &rarr;</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
