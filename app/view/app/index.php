<?php $pageTitle = 'Apps'; $active = 'apps'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$__scope = $scope ?? 'all';
$__counts = $counts ?? ['mine' => 0, 'shared' => 0, 'all' => 0];
$__serverFilter = (string) ($serverFilter ?? '');
$__serverOptions = $serverOptions ?? [];
$__isAdmin = (bool) ($isAdmin ?? false);

// Filter daftar app (SPECS.md §8e / §7.7). Nilai sudah **efektif** dari
// controller (trim; `name`/`domain` lowercase) — kosong = filter tidak aktif.
// View hanya merender, tidak menormalkan ulang.
$__filters = is_array($filters ?? null) ? $filters : [];
$__filterValues = [
    'name' => (string) ($__filters['name'] ?? ''),
    'domain' => (string) ($__filters['domain'] ?? ''),
    'owner' => (string) ($__filters['owner'] ?? ''),
];
$__filterActive = (bool) ($filterActive ?? false);
$__activeFilterCount = count(array_filter($__filterValues, static fn (string $v): bool => $v !== ''));
// Peta `id => username` untuk pilihan owner; hanya terisi bagi admin.
$__ownerOptions = is_array($ownerOptions ?? null) ? $ownerOptions : [];

// Suffix query yang **mempertahankan** filter aktif pada tautan tab (dan
// dipakai `Reset` untuk membuang filter sambil menyisakan scope + server).
$__serverSuffix = $__serverFilter !== '' ? '&server=' . rawurlencode($__serverFilter) : '';
$__filterSuffix = '';
foreach ($__filterValues as $__filterKey => $__filterValue) {
    if ($__filterValue !== '') {
        $__filterSuffix .= '&' . $__filterKey . '=' . rawurlencode($__filterValue);
    }
}
$__resetUrl = '/apps?scope=' . rawurlencode((string) $__scope) . $__serverSuffix;
$__suffix = $__serverSuffix . $__filterSuffix;
$__tab = static function (string $key, string $label, int $count) use ($__scope, $__suffix): string {
    $cls = $__scope === $key ? 'nav-link active' : 'nav-link';
    return '<li class="nav-item"><a class="' . $cls . '" href="/apps?scope=' . e($key) . e($__suffix) . '">'
        . e($label) . ' <span class="badge text-bg-secondary">' . $count . '</span></a></li>';
};
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Apps<?php if ($__filterActive): ?> <span class="badge text-bg-secondary align-middle"><?= (int) $__activeFilterCount ?> filter aktif</span><?php endif; ?></h1>
    <p class="text-muted mb-0">Kelola project Docker yang di-deploy dari repo Git.</p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <?php if ($__serverOptions !== []): ?>
      <form class="d-flex align-items-center gap-2" method="get" action="/apps">
        <input type="hidden" name="scope" value="<?= e($__scope) ?>">
        <?php foreach ($__filterValues as $__filterKey => $__filterValue): ?>
          <?php if ($__filterValue !== ''): ?>
            <input type="hidden" name="<?= e($__filterKey) ?>" value="<?= e($__filterValue) ?>">
          <?php endif; ?>
        <?php endforeach; ?>
        <label class="text-muted small mb-0" for="server-filter">Server</label>
        <select class="form-select form-select-sm" id="server-filter" name="server">
          <option value="">Semua server</option>
          <?php foreach ($__serverOptions as $__option): ?>
            <option value="<?= e($__option['id']) ?>"<?= $__serverFilter === (string) $__option['id'] ? ' selected' : '' ?>><?= e($__option['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button>
      </form>
    <?php endif; ?>
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get" action="/apps">
      <input type="hidden" name="scope" value="<?= e($__scope) ?>">
      <?php if ($__serverFilter !== ''): ?>
        <input type="hidden" name="server" value="<?= e($__serverFilter) ?>">
      <?php endif; ?>
      <div>
        <label class="text-muted small mb-0" for="app-filter-name">Nama</label>
        <input class="form-control form-control-sm" type="text" id="app-filter-name" name="name" value="<?= e($__filterValues['name']) ?>" autocomplete="off">
      </div>
      <div>
        <label class="text-muted small mb-0" for="app-filter-domain">Domain</label>
        <input class="form-control form-control-sm" type="text" id="app-filter-domain" name="domain" value="<?= e($__filterValues['domain']) ?>" autocomplete="off">
      </div>
      <?php if ($__isAdmin): ?>
        <div>
          <label class="text-muted small mb-0" for="app-filter-owner">Owner</label>
          <select class="form-select form-select-sm" id="app-filter-owner" name="owner">
            <option value="">Semua owner</option>
            <?php foreach ($__ownerOptions as $__ownerId => $__ownerName): ?>
              <option value="<?= e($__ownerId) ?>"<?= $__filterValues['owner'] === (string) $__ownerId ? ' selected' : '' ?>><?= e($__ownerName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" type="submit">Terapkan</button>
        <?php if ($__filterActive || $__serverFilter !== ''): ?>
          <a class="btn btn-link btn-sm text-decoration-none px-1" href="<?= e($__resetUrl) ?>">Reset</a>
        <?php endif; ?>
      </div>
    </form>
    <a class="btn btn-primary" href="/apps/create">+ Create App</a>
  </div>
</div>

<ul class="nav nav-pills mb-3">
  <?= $__tab('all', ($isAdmin ?? false) ? 'Semua App (admin)' : 'Semua', (int) $__counts['all']) ?>
  <?= $__tab('mine', 'Milik Saya', (int) $__counts['mine']) ?>
  <?= $__tab('shared', 'Dibagikan ke Saya', (int) $__counts['shared']) ?>
</ul>

<?php if (empty($apps)): ?>
  <div class="card empty">
    <div class="empty-icon">◈</div>
    <?php if ($__filterActive): ?>
      <p class="text-muted mb-0">Tidak ada app yang cocok dengan filter. Coba longgarkan kata kunci atau <a href="<?= e($__resetUrl) ?>">Reset filter</a>.</p>
    <?php elseif ($__serverFilter !== ''): ?>
      <p class="text-muted mb-0">Tidak ada app di server yang dipilih. Pilih <strong>Semua server</strong> untuk melihat semuanya.</p>
    <?php elseif ($__scope === 'shared'): ?>
      <p class="text-muted mb-0">Belum ada app yang dibagikan ke Anda. Minta pemilik app menambahkan Anda di tab <strong>Akses</strong>.</p>
    <?php elseif ($__scope === 'mine'): ?>
      <p class="text-muted mb-0">Anda belum memiliki app. Klik <strong>Create App</strong> untuk deploy project dari repo Git.</p>
    <?php else: ?>
      <p class="text-muted mb-0">Belum ada app. Klik <strong>Create App</strong> untuk deploy project dari repo Git (wajib punya <code>docker-compose.yml</code>).</p>
    <?php endif; ?>
  </div>
<?php else: ?>
<p class="text-muted small mb-2">Menampilkan <?= (int) count($apps) ?> app<?= $__filterActive ? ' yang cocok dengan filter' : '' ?>.</p>
<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>Name</th>
        <th>Server</th>
        <th>Domain</th>
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
        // Asal server (multi-server): nama server, bukan `server_id` mentah.
        $__serverKind = (string) ($app['server_kind'] ?? 'local');
        $__serverName = trim((string) ($app['server_name'] ?? ''));
      ?>
      <tr>
        <td>
          <a class="mono fw-semibold text-decoration-none me-1" href="/apps/<?= e($__id) ?>"><?= e($app['name']) ?></a>
          <?php if ($__isOwner): ?>
            <!-- <span class="badge text-bg-primary ms-1">Milik Saya</span> -->
          <?php else: ?>
            <span class="badge p-1 text-bg-light border" title="Anda punya akses <?= e(app_role_label($__role)) ?> ke app ini">Dibagikan · <?= e(app_role_label($__role)) ?></span>
          <?php endif; ?>
        </td>
        <td class="small">
          <?php if ($__serverKind === 'agent'): ?>
            <span class="badge badge-deploying" title="App berjalan di server agent, bukan di host dashboard">agent</span>
            <span class="mono d-block"><?= e($__serverName) ?></span>
          <?php else: ?>
            <span class="text-muted" title="App berjalan di host dashboard (server lokal)">lokal</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (\app\library\Docker\AppPorts::hasHostPort($app)): ?>
            <a class="mono text-decoration-none text-nowrap" href="http://<?= e($app['subdomain']) ?>" target="_blank" rel="noopener"><?= e($app['subdomain']) ?> ↗</a>
          <?php else: ?>
            <span class="text-muted small" title="App tidak mem-publish port host — tidak ada vhost/subdomain">tanpa domain</span>
          <?php endif; ?>
          <br>
          <?php $__cd = (string) ($app['custom_domain'] ?? ''); ?>
          <?php if ($__cd !== ''): ?>
            <?php $__cdSsl = (($app['custom_ssl_status'] ?? 'disabled') === 'active'); ?>
            <a class="mono text-decoration-none text-nowrap fw-bold text-primary" href="<?= $__cdSsl ? 'https' : 'http' ?>://<?= e($__cd) ?>" target="_blank" rel="noopener"><?= e($__cd) ?> ↗</a>
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
