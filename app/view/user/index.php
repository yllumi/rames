<?php $pageTitle = 'Manage Users'; $active = 'users'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Users</h1>
    <p class="text-muted mb-0">
      <strong>Admin</strong> mengelola user &amp; melihat semua app. <strong>Member</strong> hanya melihat
      app miliknya sendiri dan app yang dibagikan kepadanya (diatur di tab <strong>Akses</strong> tiap app).
      Total <?= (int) ($totalApps ?? 0) ?> app terdaftar.
    </p>
  </div>
  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
    + Tambah User
  </button>
</div>

<section class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Daftar User</h2>
    <span class="text-muted small"><?= count($users) ?> user</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr><th>Username</th><th>Email</th><th>Role</th><th>Saldo kredit</th><th>App</th><th>Dibuat</th><th>Aksi</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
        <?php
          $__self = ($currentUser['id'] ?? '') === ($user['id'] ?? '');
          $__role = ($user['role'] ?? '') === 'admin' ? 'admin' : 'member';
          $__email = trim((string) ($user['email'] ?? ''));
          $__balance = (float) (($balances ?? [])[$user['id'] ?? ''] ?? 0.0);
        ?>
        <tr>
          <td>
            <span class="avatar sm"><?= e(strtoupper(substr($user['username'] ?? '?', 0, 1))) ?></span>
            <strong><?= e($user['username']) ?></strong>
            <?php if ($__self): ?>
              <span class="text-muted small">(Anda)</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($__email !== ''): ?>
              <span class="mono"><?= e($__email) ?></span>
            <?php else: ?>
              <span class="text-muted">&mdash;</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $__role === 'admin' ? 'text-bg-primary' : 'text-bg-secondary' ?>">
              <?= $__role === 'admin' ? 'Admin' : 'Member' ?>
            </span>
          </td>
          <td class="small mono"><?= e(\app\library\Billing\Pricing::format($__balance)) ?></td>
          <td class="small text-muted">
            <?= (int) (($ownerCounts ?? [])[$user['id'] ?? ''] ?? 0) ?> dimiliki
          </td>
          <td class="small text-muted"><?= e($user['created_at'] ?? '') ?></td>
          <td>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                    data-user-id="<?= e($user['id']) ?>"
                    data-username="<?= e($user['username']) ?>"
                    data-role="<?= e($user['role'] ?? '') ?>"
                    data-email="<?= e($__email) ?>"
                    data-self="<?= $__self ? '1' : '0' ?>">Edit</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Modal tambah user -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="/users">
      <?= csrf_field() ?>
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addUserModalLabel">Tambah User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="addUsername">Username</label>
            <input type="text" class="form-control" id="addUsername" name="username" required pattern="[a-zA-Z0-9_]{3,32}" title="3–32 karakter, hanya huruf, angka, underscore">
          </div>
          <div class="mb-3">
            <label class="form-label" for="addPassword">Password</label>
            <input type="password" class="form-control" id="addPassword" name="password" minlength="6" required autocomplete="new-password">
          </div>
          <div class="mb-3">
            <label class="form-label" for="addRole">Role</label>
            <select class="form-select" id="addRole" name="role">
              <option value="member" selected>Member — hanya app miliknya / yang dibagikan</option>
              <option value="admin">Admin — kelola user &amp; lihat semua app</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Tambah User</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal edit user -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editUserModalLabel">Edit User — <span id="editUserUsername" class="fw-bold"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="" id="editRoleForm" class="mb-3">
          <?= csrf_field() ?>
          <div class="mb-2">
            <label class="form-label" for="editRole">Ganti Role</label>
            <select class="form-select" id="editRole" name="role">
              <option value="member">Member — hanya app miliknya / yang dibagikan</option>
              <option value="admin">Admin — kelola user &amp; lihat semua app</option>
            </select>
            <div class="form-text d-none" id="editRoleSelfNote">Tidak bisa mengubah role sendiri.</div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" id="editRoleSubmit">Simpan Role</button>
        </form>

        <hr>

        <form method="post" action="" id="editPasswordForm" class="mb-3">
          <?= csrf_field() ?>
          <div class="mb-2">
            <label class="form-label" for="editPassword">Ganti Password</label>
            <input type="password" class="form-control" id="editPassword" name="password" minlength="6" required autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Ganti Password</button>
        </form>

        <hr>

        <form method="post" action="" id="editEmailForm" class="mb-3">
          <?= csrf_field() ?>
          <div class="mb-2">
            <label class="form-label" for="editEmail">Email (top-up kredit)</label>
            <input type="email" class="form-control" id="editEmail" name="email" maxlength="254" autocomplete="email">
            <div class="form-text">
              Kosongkan lalu simpan untuk menghapus email. Email wajib untuk top-up mandiri via payment gateway.
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Simpan Email</button>
        </form>

        <hr>

        <form method="post" action="" id="editDeleteForm">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-outline-danger btn-sm">Hapus User</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('editUserModal');
  if (!modal) return;

  var usernameEl = document.getElementById('editUserUsername');
  var roleForm = document.getElementById('editRoleForm');
  var roleSelect = document.getElementById('editRole');
  var roleSelfNote = document.getElementById('editRoleSelfNote');
  var roleSubmit = document.getElementById('editRoleSubmit');
  var passwordForm = document.getElementById('editPasswordForm');
  var passwordInput = document.getElementById('editPassword');
  var emailForm = document.getElementById('editEmailForm');
  var emailInput = document.getElementById('editEmail');
  var deleteForm = document.getElementById('editDeleteForm');

  modal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    if (!btn || !btn.dataset) return;

    var id = btn.dataset.userId || '';
    var username = btn.dataset.username || '';
    var role = btn.dataset.role === 'admin' ? 'admin' : 'member';
    var isSelf = btn.dataset.self === '1';

    usernameEl.textContent = username;

    roleForm.setAttribute('action', '/users/' + encodeURIComponent(id) + '/role');
    roleSelect.value = role;
    roleSelect.disabled = isSelf;
    roleSelfNote.classList.toggle('d-none', !isSelf);
    roleSubmit.classList.toggle('d-none', isSelf);

    passwordForm.setAttribute('action', '/users/' + encodeURIComponent(id) + '/password');
    passwordInput.value = '';

    if (emailForm && emailInput) {
      emailForm.setAttribute('action', '/users/' + encodeURIComponent(id) + '/email');
      emailInput.value = btn.dataset.email || '';
    }

    deleteForm.setAttribute('action', '/users/' + encodeURIComponent(id) + '/delete');
    deleteForm.classList.toggle('d-none', isSelf);

    var safeName = String(username).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    deleteForm.setAttribute(
      'onsubmit',
      "return confirm('Hapus user " + safeName + "? Semua app miliknya akan dialihkan ke Anda.');"
    );
  });
})();
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
