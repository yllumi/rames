<?php
$pageTitle = $connected ? 'DB · ' . $container : 'Koneksi DB · ' . $container;
$active = 'database';
$c = rawurlencode($container);

$u = function (string $mode, string $db = '', string $table = '', int $page = 1) use ($c) {
    $q = http_build_query(array_filter([
        'db' => $db,
        'table' => $table,
        'mode' => $mode,
        'page' => $page > 1 ? $page : null,
    ]));
    return '/database/' . $c . ($q !== '' ? '?' . $q : '');
};

// Kolom yang bisa diedit/insert (bukan auto-increment / generated).
$editableCols = [];
if (!empty($columns)) {
    $editableCols = array_values(array_filter($columns, static function (array $col): bool {
        $extra = strtolower($col['extra'] ?? '');
        return !str_contains($extra, 'auto_increment') && !str_contains($extra, 'generated');
    }));
}
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <h1 class="h4 mb-0 mono"><?= e($container) ?></h1>
    <span class="badge badge-<?= e($state) ?>"><?= e($state) ?></span>
    <span class="text-muted small"><?= e($image) ?></span>
    <?php if ($app !== null): ?>
      <a class="text-muted small text-decoration-none" href="/apps/<?= e($app['id']) ?>">&larr; <?= e($app['name']) ?></a>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="/database">&larr; Daftar Database</a>
    <?php if ($connected): ?>
      <form method="post" action="/database/<?= e($c) ?>/disconnect" onsubmit="return confirm('Putuskan koneksi database ini?');">
        <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Putuskan</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$connected): ?>
  <!-- ============ Form koneksi ============ -->
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><h2 class="h6 mb-0">Hubungkan ke Server Database</h2></div>
        <div class="card-body">
          <?php if ($hasDetected): ?>
            <div class="alert alert-success py-2 small">
              <strong>Kredensial terdeteksi otomatis</strong> dari environment container/app:
              user <span class="mono"><?= e($detectedUser) ?></span><?= $detectedDatabase ? ' &middot; database <span class="mono">' . e($detectedDatabase) . '</span>' : '' ?>.
              Biarkan kolom <em>username</em> kosong untuk memakai kredensial terdeteksi.
            </div>
          <?php else: ?>
            <div class="alert alert-info py-2 small">
              Kredensial tidak terdeteksi otomatis. Isi username &amp; password secara manual.
            </div>
          <?php endif; ?>

          <form method="post" action="/database/<?= e($c) ?>/connect">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label small">Username</label>
              <input type="text" name="username" class="form-control mono" placeholder="<?= $hasDetected ? e($detectedUser) : 'root' ?>" value="">
              <?php if ($hasDetected): ?><div class="form-text">Kosongkan untuk memakai <span class="mono"><?= e($detectedUser) ?></span> (terdeteksi).</div><?php endif; ?>
            </div>
            <div class="mb-3">
              <label class="form-label small">Password</label>
              <input type="password" name="password" class="form-control" autocomplete="off" placeholder="<?= $hasDetected ? 'otomatis terdeteksi' : 'password' ?>">
            </div>
            <div class="mb-3">
              <label class="form-label small">Database awal (opsional)</label>
              <input type="text" name="database" class="form-control mono" placeholder="<?= $hasDetected && $detectedDatabase ? e($detectedDatabase) : 'opsional' ?>">
            </div>
            <button class="btn btn-primary">Hubungkan</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <!-- ============ Manager ============ -->
  <div class="row g-3">
    <!-- Sidebar -->
    <div class="col-lg-3">
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0">Database</h2>
          <span class="text-muted small"><?= count($databases) ?></span>
        </div>
        <div class="list-group list-group-flush" style="max-height:280px; overflow-y:auto;">
          <?php foreach ($databases as $d): ?>
            <a class="list-group-item list-group-item-action small mono <?= $db === $d ? 'active' : '' ?>" href="<?= e($u('browse', $d)) ?>"><?= e($d) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($db !== ''): ?>
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h2 class="h6 mb-0">Tabel</h2>
          <span class="text-muted small"><?= count($tables) ?></span>
        </div>
        <div class="list-group list-group-flush" style="max-height:320px; overflow-y:auto;">
          <?php foreach ($tables as $t): ?>
            <a class="list-group-item list-group-item-action small d-flex justify-content-between align-items-center <?= ($table === $t['name'] && $mode === 'browse') ? 'active' : '' ?>"
               href="<?= e($u('browse', $db, $t['name'])) ?>">
              <span class="mono"><?= e($t['name']) ?></span>
              <span class="badge text-bg-<?= $t['type'] === 'VIEW' ? 'secondary' : 'light' ?>"><?= e($t['type']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Aksi</h2></div>
        <div class="list-group list-group-flush">
          <a class="list-group-item list-group-item-action small <?= $mode === 'sql' ? 'active' : '' ?>" href="<?= e($u('sql', $db, $table)) ?>">&#9002; SQL Editor</a>
          <a class="list-group-item list-group-item-action small <?= $mode === 'structure' ? 'active' : '' ?>" href="<?= e($u('structure', $db, $table)) ?>">&#9776; Struktur</a>
          <a class="list-group-item list-group-item-action small <?= $mode === 'users' ? 'active' : '' ?>" href="<?= e($u('users', $db, $table)) ?>">&#128101; Users &amp; Hak Akses</a>
          <a class="list-group-item list-group-item-action small <?= $mode === 'export' ? 'active' : '' ?>" href="<?= e($u('export', $db, $table)) ?>">&#8681; Export</a>
          <a class="list-group-item list-group-item-action small <?= $mode === 'import' ? 'active' : '' ?>" href="<?= e($u('import', $db, $table)) ?>">&#8680; Import</a>
        </div>
      </div>

      <div class="card">
        <div class="card-body py-2 small text-muted">
          Terhubung sebagai <span class="mono"><?= e($profileUser) ?></span>
        </div>
      </div>
    </div>

    <!-- Konten -->
    <div class="col-lg-9">
      <?php if ($mode === 'sql'): ?>
        <?php include app_path() . '/view/db/partials/sql.php'; ?>
      <?php elseif ($mode === 'structure'): ?>
        <?php include app_path() . '/view/db/partials/structure.php'; ?>
      <?php elseif ($mode === 'users'): ?>
        <?php include app_path() . '/view/db/partials/users.php'; ?>
      <?php elseif ($mode === 'export'): ?>
        <?php include app_path() . '/view/db/partials/export.php'; ?>
      <?php elseif ($mode === 'import'): ?>
        <?php include app_path() . '/view/db/partials/import.php'; ?>
      <?php else: ?>
        <?php include app_path() . '/view/db/partials/browse.php'; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
