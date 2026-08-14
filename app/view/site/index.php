<?php $pageTitle = 'Sites'; $active = 'sites'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Sites</h1>
    <p class="text-muted mb-0">Kelola project Docker yang di-deploy dari repo Git.</p>
  </div>
  <a class="btn btn-primary" href="/sites/create">+ Create Site</a>
</div>

<?php if (empty($sites)): ?>
  <div class="card empty">
    <div class="empty-icon">◈</div>
    <p class="text-muted mb-0">Belum ada site. Klik <strong>Create Site</strong> untuk deploy project dari repo Git (wajib punya <code>docker-compose.yml</code>).</p>
  </div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Name</th><th>Subdomain</th><th>Status</th><th>Containers</th><th>Updated</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($sites as $site): ?>
      <tr>
        <td><a class="mono fw-semibold text-decoration-none" href="/sites/<?= e($site['id']) ?>"><?= e($site['name']) ?></a></td>
        <td><a class="mono text-decoration-none" href="http://<?= e($site['subdomain']) ?>" target="_blank" rel="noopener"><?= e($site['subdomain']) ?> ↗</a></td>
        <td>
          <span class="badge badge-<?= e($site['status'] ?? 'unknown') ?>"
            title="<?= !empty($site['message']) ? e($site['message']) : '' ?>">
            <?= e($site['status'] ?? 'unknown') ?>
          </span>
        </td>
        <td class="mono text-muted"><?= count($site['containers'] ?? []) ?></td>
        <td class="small text-muted"><?= e($site['updated_at'] ?? '') ?></td>
        <td><a class="btn btn-outline-secondary btn-sm" href="/sites/<?= e($site['id']) ?>">Detail</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
