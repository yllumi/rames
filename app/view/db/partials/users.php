<?php /** Kelola user & hak akses. */ ?>
<section class="card mb-3">
  <div class="card-header"><h2 class="h6 mb-0">Users</h2></div>
  <?php if ($userError !== null): ?>
    <div class="card-body">
      <div class="alert alert-warning py-2 small mb-0">Tidak dapat membaca daftar user: <?= e($userError) ?>. (Butuh privilege <span class="mono">SELECT</span> pada <span class="mono">mysql.user</span>.)</div>
    </div>
  <?php elseif (empty($users)): ?>
    <div class="card-body text-muted small">Tidak ada user.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
      <thead class="table-light"><tr><th>User</th><th>Host</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($users as $urow): ?>
          <tr>
            <td class="mono"><?= e($urow['user']) ?></td>
            <td class="mono small"><?= e($urow['host']) ?></td>
            <td class="text-end">
              <form method="post" class="d-inline" action="/database/<?= e($c) ?>/user/delete"
                    onsubmit="return confirm('Hapus user <?= e($urow['user']) ?>@<?= e($urow['host']) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="user" value="<?= e($urow['user']) ?>">
                <input type="hidden" name="host" value="<?= e($urow['host']) ?>">
                <button class="btn btn-outline-danger btn-sm">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card mb-3">
  <div class="card-header"><h2 class="h6 mb-0">Buat User</h2></div>
  <div class="card-body">
    <form method="post" action="/database/<?= e($c) ?>/user/create" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="db" value="<?= e($db) ?>">
      <input type="hidden" name="table" value="<?= e($table) ?>">
      <input type="hidden" name="mode" value="users">
      <div class="col-md-4"><input type="text" name="user" class="form-control form-control-sm mono" placeholder="username" required></div>
      <div class="col-md-3"><input type="text" name="host" class="form-control form-control-sm mono" value="%" required></div>
      <div class="col-md-3"><input type="password" name="password" class="form-control form-control-sm" placeholder="password" autocomplete="off" required></div>
      <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Buat</button></div>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header"><h2 class="h6 mb-0">Privilege (Grant / Revoke)</h2></div>
  <div class="card-body">
    <?php if ($db === ''): ?>
      <div class="alert alert-info py-2 small">Pilih database terlebih dahulu (di sidebar) untuk menentukan scope <span class="mono">db.*</span>.</div>
    <?php else: ?>
      <div class="row g-4">
        <div class="col-md-6">
          <h3 class="h6">Beri Privilege</h3>
          <form method="post" action="/database/<?= e($c) ?>/user/grant">
            <?= csrf_field() ?>
            <input type="hidden" name="db" value="<?= e($db) ?>">
            <input type="hidden" name="table" value="<?= e($table) ?>">
            <input type="hidden" name="mode" value="users">
            <div class="mb-2"><input type="text" name="user" class="form-control form-control-sm mono" placeholder="username" required></div>
            <div class="mb-2"><input type="text" name="host" class="form-control form-control-sm mono" value="%" required></div>
            <div class="mb-2 border rounded p-2" style="max-height:180px; overflow-y:auto;">
              <?php foreach ($privileges as $p): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="privileges[]" value="<?= e($p) ?>" id="grant-<?= e(md5($p)) ?>"><label class="form-check-label small mono" for="grant-<?= e(md5($p)) ?>"><?= e($p) ?></label></div>
              <?php endforeach; ?>
            </div>
            <button class="btn btn-primary btn-sm">GRANT</button>
          </form>
        </div>
        <div class="col-md-6">
          <h3 class="h6">Cabut Privilege</h3>
          <form method="post" action="/database/<?= e($c) ?>/user/revoke">
            <?= csrf_field() ?>
            <input type="hidden" name="db" value="<?= e($db) ?>">
            <input type="hidden" name="table" value="<?= e($table) ?>">
            <input type="hidden" name="mode" value="users">
            <div class="mb-2"><input type="text" name="user" class="form-control form-control-sm mono" placeholder="username" required></div>
            <div class="mb-2"><input type="text" name="host" class="form-control form-control-sm mono" value="%" required></div>
            <div class="mb-2 border rounded p-2" style="max-height:180px; overflow-y:auto;">
              <?php foreach ($privileges as $p): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="privileges[]" value="<?= e($p) ?>" id="revoke-<?= e(md5($p)) ?>"><label class="form-check-label small mono" for="revoke-<?= e(md5($p)) ?>"><?= e($p) ?></label></div>
              <?php endforeach; ?>
            </div>
            <button class="btn btn-outline-danger btn-sm">REVOKE</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
