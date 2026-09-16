<?php $pageTitle = 'Apps'; $active = 'apps'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$__scope = $scope ?? 'all';
$__counts = $counts ?? ['mine' => 0, 'shared' => 0, 'all' => 0];
$__tab = static function (string $key, string $label, int $count) use ($__scope): string {
    $cls = $__scope === $key ? 'nav-link active' : 'nav-link';
    return '<li class="nav-item"><a class="' . $cls . '" href="/apps?scope=' . e($key) . '">'
        . e($label) . ' <span class="badge text-bg-secondary">' . $count . '</span></a></li>';
};
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Apps</h1>
    <p class="text-muted mb-0">Kelola project Docker yang di-deploy dari repo Git.</p>
  </div>
  <a class="btn btn-primary" href="/apps/create">+ Create App</a>
</div>

<ul class="nav nav-pills mb-3">
  <?= $__tab('all', ($isAdmin ?? false) ? 'Semua App (admin)' : 'Semua', (int) $__counts['all']) ?>
  <?= $__tab('mine', 'Milik Saya', (int) $__counts['mine']) ?>
  <?= $__tab('shared', 'Dibagikan ke Saya', (int) $__counts['shared']) ?>
</ul>

<?php if (empty($apps)): ?>
  <div class="card empty">
    <div class="empty-icon">◈</div>
    <?php if ($__scope === 'shared'): ?>
      <p class="text-muted mb-0">Belum ada app yang dibagikan ke Anda. Minta pemilik app menambahkan Anda di tab <strong>Akses</strong>.</p>
    <?php elseif ($__scope === 'mine'): ?>
      <p class="text-muted mb-0">Anda belum memiliki app. Klik <strong>Create App</strong> untuk deploy project dari repo Git.</p>
    <?php else: ?>
      <p class="text-muted mb-0">Belum ada app. Klik <strong>Create App</strong> untuk deploy project dari repo Git (wajib punya <code>docker-compose.yml</code>).</p>
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>Name</th>
        <th>Subdomain</th>
        <th>Custom Domain</th>
        <?php if ($isAdmin ?? false): ?><th>Owner</th><?php endif; ?>
        <th>Status</th>
        <th>Containers</th>
        <th>Updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($apps as $app): ?>
      <?php
        $__id = (string) $app['id'];
        $__isOwner = (bool) (($ownedIds ?? [])[$__id] ?? false);
        $__role = (string) (($roles ?? [])[$__id] ?? '');
        $__ownerName = (string) (($ownerNames ?? [])[(string) ($app['owner_id'] ?? '')] ?? '');
      ?>
      <tr>
        <td>
          <a class="mono fw-semibold text-decoration-none" href="/apps/<?= e($__id) ?>"><?= e($app['name']) ?></a>
          <?php if ($__isOwner): ?>
            <!-- <span class="badge text-bg-primary ms-1">Milik Saya</span> -->
          <?php else: ?>
            <span class="badge text-bg-light border ms-1" title="Anda punya akses <?= e(app_role_label($__role)) ?> ke app ini">Dibagikan · <?= e(app_role_label($__role)) ?></span>
          <?php endif; ?>
        </td>
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
        <?php if ($isAdmin ?? false): ?>
        <td class="small">
          <?php if ($__ownerName !== ''): ?>
            <span class="mono"><?= e($__ownerName) ?></span>
          <?php else: ?>
            <span class="text-muted">(belum ada owner)</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <td>
          <span class="badge badge-<?= e($app['status'] ?? 'unknown') ?>"
            title="<?= !empty($app['message']) ? e($app['message']) : '' ?>">
            <?= e($app['status'] ?? 'unknown') ?>
          </span>
        </td>
        <td class="mono text-muted"><?= count($app['containers'] ?? []) ?></td>
        <td class="small text-muted"><?= e($app['updated_at'] ?? '') ?></td>
        <td><a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($__id) ?>">Detail</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
