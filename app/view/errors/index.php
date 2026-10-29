<?php
$pageTitle = 'Error Log';
$active = 'errors';

/**
 * Halaman `/errors` (admin-only) — daftar grup error yang dikirim reporter
 * klien (`POST /api/errors`) maupun error server.
 *
 * Kontrak data dari `ErrorController::index()` (BEKU):
 *   $groups  — list {id,fingerprint,kind,status,message,location,route,
 *              http_status,app_id,app_name,server_id,server_name,user_id,
 *              username,first_seen,last_seen,count,samples}
 *   $filters — {status:'open'|'resolved'|'ignored'|'all',
 *               kind:'client'|'server'|'all', q:'', app_id:''}
 *   $counts  — {open:n,resolved:n,ignored:n}
 *   $total, $page, $pages, $apps, $active
 *
 * View hanya merender dan membentuk tautan filter/pagination; penyaringan dan
 * otorisasi tetap di controller/library (halaman ini tidak query apa pun).
 */
$__text = static function (mixed $value): string {
    if ($value === null || $value === false) {
        return '';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }

    return '';
};

$__trunc = static function (string $text, int $max = 140): string {
    if ($max < 1 || $text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') . '…' : $text;
    }

    return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
};

$__date = static function (mixed $value) use ($__text): string {
    $raw = trim($__text($value));
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d H:i', $ts);
};

$groups = is_array($groups ?? null) ? array_values(array_filter($groups, 'is_array')) : [];
$filters = is_array($filters ?? null) ? $filters : [];
$counts = is_array($counts ?? null) ? $counts : [];
$apps = is_array($apps ?? null) ? array_values(array_filter($apps, 'is_array')) : [];

$statusFilter = strtolower($__text($filters['status'] ?? 'open'));
if (!in_array($statusFilter, ['open', 'resolved', 'ignored', 'all'], true)) {
    $statusFilter = 'open';
}
$kindFilter = strtolower($__text($filters['kind'] ?? 'all'));
if (!in_array($kindFilter, ['client', 'server', 'all'], true)) {
    $kindFilter = 'all';
}
$qFilter = $__text($filters['q'] ?? '');
$appFilter = $__text($filters['app_id'] ?? '');

$total = is_numeric($total ?? null) ? max(0, (int) $total) : count($groups);
$page = is_numeric($page ?? null) ? max(1, (int) $page) : 1;
$pages = is_numeric($pages ?? null) ? max(1, (int) $pages) : 1;
$page = min($page, $pages);

$openCount = is_numeric($counts['open'] ?? null) ? max(0, (int) $counts['open']) : 0;
$resolvedCount = is_numeric($counts['resolved'] ?? null) ? max(0, (int) $counts['resolved']) : 0;
$ignoredCount = is_numeric($counts['ignored'] ?? null) ? max(0, (int) $counts['ignored']) : 0;

$__statusLabel = ['open' => 'open', 'resolved' => 'resolved', 'ignored' => 'ignored'];
$__statusClass = [
    'open' => 'text-bg-danger',
    'resolved' => 'text-bg-success',
    'ignored' => 'text-bg-secondary',
];
$__kindClass = ['client' => 'text-bg-info', 'server' => 'text-bg-warning'];

$__hasFilter = $statusFilter !== 'open' || $kindFilter !== 'all' || $qFilter !== '' || $appFilter !== '';

// URL halaman daftar dengan filter aktif (parameter kosong/default dihilangkan).
$__pageUrl = static function (int $targetPage) use ($statusFilter, $kindFilter, $qFilter, $appFilter): string {
    $params = [];
    if ($statusFilter !== 'open') {
        $params['status'] = $statusFilter;
    }
    if ($kindFilter !== 'all') {
        $params['kind'] = $kindFilter;
    }
    if ($qFilter !== '') {
        $params['q'] = $qFilter;
    }
    if ($appFilter !== '') {
        $params['app_id'] = $appFilter;
    }
    if ($targetPage > 1) {
        $params['page'] = $targetPage;
    }

    return '/errors' . ($params === [] ? '' : '?' . http_build_query($params));
};

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Error Log', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Error Log</h1>
    <p class="text-muted mb-2">
      Error yang dikirim reporter klien (browser) dan error server, dikelompokkan per
      <span class="mono">fingerprint</span>. Halaman ini hanya untuk admin.
    </p>
    <div class="d-flex flex-wrap gap-2" aria-label="Ringkasan status error">
      <span class="badge text-bg-danger">open <?= e((string) $openCount) ?></span>
      <span class="badge text-bg-success">resolved <?= e((string) $resolvedCount) ?></span>
      <span class="badge text-bg-secondary">ignored <?= e((string) $ignoredCount) ?></span>
      <span class="badge text-bg-light text-dark">total <?= e((string) $total) ?> grup</span>
    </div>
  </div>
</div>

<section class="card mb-3">
  <div class="card-body">
    <form method="get" action="/errors" class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="error-filter-status">Status</label>
        <select class="form-select form-select-sm" id="error-filter-status" name="status">
          <option value="open"<?= $statusFilter === 'open' ? ' selected' : '' ?>>Open</option>
          <option value="resolved"<?= $statusFilter === 'resolved' ? ' selected' : '' ?>>Resolved</option>
          <option value="ignored"<?= $statusFilter === 'ignored' ? ' selected' : '' ?>>Ignored</option>
          <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>Semua status</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="error-filter-kind">Jenis</label>
        <select class="form-select form-select-sm" id="error-filter-kind" name="kind">
          <option value="all"<?= $kindFilter === 'all' ? ' selected' : '' ?>>Semua jenis</option>
          <option value="client"<?= $kindFilter === 'client' ? ' selected' : '' ?>>Client</option>
          <option value="server"<?= $kindFilter === 'server' ? ' selected' : '' ?>>Server</option>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label small mb-1" for="error-filter-app">App</label>
        <select class="form-select form-select-sm" id="error-filter-app" name="app_id">
          <option value="">Semua app</option>
          <?php foreach ($apps as $__app): ?>
            <?php
              $__appId = $__text($__app['id'] ?? '');
              $__appName = $__text($__app['name'] ?? '');
              if ($__appId === '') {
                  continue;
              }
            ?>
            <option value="<?= e($__appId) ?>"<?= $appFilter === $__appId ? ' selected' : '' ?>><?= e($__appName !== '' ? $__appName : $__appId) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="error-filter-q">Kata kunci</label>
        <input type="search" class="form-control form-control-sm" id="error-filter-q" name="q" value="<?= e($qFilter) ?>" maxlength="200" placeholder="pesan, route, atau fingerprint">
      </div>
      <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Terapkan</button>
        <a class="btn btn-outline-secondary btn-sm" href="/errors">Reset</a>
      </div>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h6 mb-0">Daftar grup error</h2>
    <span class="text-muted small"><?= e((string) $total) ?> grup · halaman <?= e((string) $page) ?> dari <?= e((string) $pages) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Terakhir</th>
          <th>Jenis</th>
          <th>Status</th>
          <th>Pesan</th>
          <th>Route / App / Server</th>
          <th>User</th>
          <th class="text-end">Kejadian</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($groups === []): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">
              <?php if ($__hasFilter): ?>
                <strong>Tidak ada error yang cocok dengan filter ini.</strong>
                <span class="d-block small mt-1">Coba longgarkan status/jenis atau kosongkan kata kunci.</span>
              <?php else: ?>
                <strong>Belum ada error yang tercatat.</strong>
                <span class="d-block small mt-1">Error JavaScript yang tidak tertangani di browser pengguna akan muncul di sini.</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($groups as $__group): ?>
          <?php
            $__groupId = $__text($__group['id'] ?? '');
            if ($__groupId === '') {
                continue;
            }
            $__status = strtolower($__text($__group['status'] ?? 'open'));
            $__status = isset($__statusClass[$__status]) ? $__status : 'open';
            $__kind = strtolower($__text($__group['kind'] ?? 'client'));
            $__kind = isset($__kindClass[$__kind]) ? $__kind : 'client';
            $__message = $__text($__group['message'] ?? '');
            $__location = $__text($__group['location'] ?? '');
            $__route = $__text($__group['route'] ?? '');
            $__appName = $__text($__group['app_name'] ?? '');
            $__serverName = $__text($__group['server_name'] ?? '');
            $__username = $__text($__group['username'] ?? '');
            $__userId = $__text($__group['user_id'] ?? '');
            $__count = is_numeric($__group['count'] ?? null) ? max(0, (int) $__group['count']) : 0;
            $__httpStatus = $__text($__group['http_status'] ?? '');
          ?>
          <tr>
            <td class="small text-nowrap"><?= e($__date($__group['last_seen'] ?? '')) ?></td>
            <td><span class="badge <?= e($__kindClass[$__kind]) ?>"><?= e($__kind) ?></span></td>
            <td><span class="badge <?= e($__statusClass[$__status]) ?>"><?= e($__statusLabel[$__status]) ?></span></td>
            <td class="small">
              <span class="d-block text-truncate" style="max-width: 360px" title="<?= e($__message) ?>"><?= e($__message !== '' ? $__trunc($__message) : '—') ?></span>
              <?php if ($__location !== ''): ?>
                <span class="d-block text-muted mono text-truncate" style="max-width: 360px" title="<?= e($__location) ?>"><?= e($__trunc($__location, 100)) ?></span>
              <?php endif; ?>
              <?php if ($__httpStatus !== ''): ?>
                <span class="badge text-bg-light text-dark mt-1">HTTP <?= e($__httpStatus) ?></span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($__route !== ''): ?>
                <span class="mono d-block text-truncate" style="max-width: 240px" title="<?= e($__route) ?>"><?= e($__route) ?></span>
              <?php endif; ?>
              <?php if ($__appName !== ''): ?>
                <span class="d-block"><?= e($__appName) ?></span>
              <?php endif; ?>
              <?php if ($__serverName !== ''): ?>
                <span class="d-block text-muted"><?= e($__serverName) ?></span>
              <?php endif; ?>
              <?php if ($__route === '' && $__appName === '' && $__serverName === ''): ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($__username !== '' ? $__username : ($__userId !== '' ? $__userId : '—')) ?></td>
            <td class="text-end"><span class="badge text-bg-secondary"><?= e((string) $__count) ?></span></td>
            <td class="text-end">
              <a class="btn btn-outline-secondary btn-sm" href="/errors/<?= e(rawurlencode($__groupId)) ?>">Detail</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
      <span class="text-muted small">Halaman <?= e((string) $page) ?> dari <?= e((string) $pages) ?></span>
      <nav aria-label="Navigasi halaman error">
        <ul class="pagination pagination-sm mb-0">
          <?php if ($page > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($__pageUrl($page - 1)) ?>">‹ Sebelumnya</a></li>
          <?php else: ?>
            <li class="page-item disabled"><span class="page-link">‹ Sebelumnya</span></li>
          <?php endif; ?>
          <li class="page-item disabled"><span class="page-link"><?= e((string) $page) ?> / <?= e((string) $pages) ?></span></li>
          <?php if ($page < $pages): ?>
            <li class="page-item"><a class="page-link" href="<?= e($__pageUrl($page + 1)) ?>">Berikutnya ›</a></li>
          <?php else: ?>
            <li class="page-item disabled"><span class="page-link">Berikutnya ›</span></li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</section>

<?php include app_path() . '/view/partials/footer.php'; ?>
