<?php $pageTitle = 'Apps'; $active = 'apps'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Apps</h1>
    <p class="text-muted mb-0">Kelola project Docker yang di-deploy dari repo Git.</p>
  </div>
  <a class="btn btn-primary" href="/apps/create">+ Create App</a>
</div>

<?php if (empty($apps)): ?>
  <div class="card empty">
    <div class="empty-icon">◈</div>
    <p class="text-muted mb-0">Belum ada app. Klik <strong>Create App</strong> untuk deploy project dari repo Git (wajib punya <code>docker-compose.yml</code>).</p>
  </div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Name</th><th>Subdomain</th><th>Custom Domain</th><th>Status</th><th>Containers</th><th>Updated</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($apps as $app): ?>
      <tr>
        <td><a class="mono fw-semibold text-decoration-none" href="/apps/<?= e($app['id']) ?>"><?= e($app['name']) ?></a></td>
        <td><a class="mono text-decoration-none text-nowrap" href="http://<?= e($app['subdomain']) ?>" target="_blank" rel="noopener"><?= e($app['subdomain']) ?> ↗</a></td>
        <td>
          <?php $__cd = (string) ($app['custom_domain'] ?? ''); ?>
          <?php if ($__cd !== ''): ?>
            <?php $__cdSsl = (($app['custom_ssl_status'] ?? 'disabled') === 'active'); ?>
            <a class="mono text-decoration-none text-nowrap" href="<?= $__cdSsl ? 'https' : 'http' ?>://<?= e($__cd) ?>" target="_blank" rel="noopener"><?= e($__cd) ?> ↗</a>
          <?php else: ?>
            <span class="text-muted small">&mdash;</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge badge-<?= e($app['status'] ?? 'unknown') ?>"
            title="<?= !empty($app['message']) ? e($app['message']) : '' ?>">
            <?= e($app['status'] ?? 'unknown') ?>
          </span>
        </td>
        <td class="mono text-muted"><?= count($app['containers'] ?? []) ?></td>
        <td class="small text-muted"><?= e($app['updated_at'] ?? '') ?></td>
        <td><a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($app['id']) ?>">Detail</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
