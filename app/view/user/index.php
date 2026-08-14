<?php $pageTitle = 'Manage Users'; $active = 'users'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Users</h1>
    <p class="text-muted mb-0">Semua user punya hak akses penuh (tanpa role/permission, SPECS.md §6.1).</p>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <section class="card">
      <div class="card-header"><h2 class="h6 mb-0">Tambah User</h2></div>
      <div class="card-body">
        <form method="post" action="/users">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input type="text" class="form-control" id="username" name="username" required pattern="[a-zA-Z0-9_]{3,32}" title="3–32 karakter, hanya huruf, angka, underscore">
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input type="password" class="form-control" id="password" name="password" minlength="6" required autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary">Tambah User</button>
        </form>
      </div>
    </section>
  </div>

  <div class="col-lg-8">
    <section class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Daftar User</h2>
        <span class="text-muted small"><?= count($users) ?> user</span>
      </div>
      <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr><th>Username</th><th>Dibuat</th><th>Ganti Password</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
          <tr>
            <td>
              <span class="avatar sm"><?= e(strtoupper(substr($user['username'] ?? '?', 0, 1))) ?></span>
              <strong><?= e($user['username']) ?></strong>
              <?php if (($currentUser['id'] ?? '') === ($user['id'] ?? '')): ?>
                <span class="text-muted small">(Anda)</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($user['created_at'] ?? '') ?></td>
            <td>
              <form method="post" action="/users/<?= e($user['id']) ?>/password">
                <?= csrf_field() ?>
                <div class="input-group input-group-sm" style="max-width:270px;">
                  <input type="password" class="form-control" name="password" placeholder="Password baru" minlength="6" required aria-label="Password baru">
                  <button class="btn btn-outline-secondary" type="submit">Ganti</button>
                </div>
              </form>
            </td>
            <td>
              <?php if (($currentUser['id'] ?? '') !== ($user['id'] ?? '')): ?>
              <form method="post" action="/users/<?= e($user['id']) ?>/delete"
                    onsubmit="return confirm('Hapus user <?= e($user['username']) ?>?');">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger btn-sm" type="submit">Hapus</button>
              </form>
              <?php else: ?>
                <span class="text-muted small">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>
  </div>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
