<?php
$pageTitle = 'Profil';
$active = 'profile';

// Halaman self-service: hanya merender data yang disiapkan ProfileController
// (mediator). Fallback variabel menjaga view tetap bisa di-smoke-render tanpa
// controller; tanpa query/logika bisnis di sini dan semua output di-escape e().
$user = is_array($user ?? null) ? $user : (current_user() ?? []);
$email = (string) ($email ?? (string) ($user['email'] ?? ''));
$username = (string) ($user['username'] ?? '');
$isAdminUser = is_admin($user);
// Admin global tidak memakai kredit (helper mengembalikan null); member bisa
// dapat null juga bila fitur billing dimatikan — keduanya ditangani di bawah.
$balance = $balance ?? current_credit_balance();

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Profil', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Profil</h1>
    <p class="text-muted mb-0">
      Kelola email notifikasi akun Anda. Username &amp; peran hanya dapat diubah oleh admin.
    </p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-white py-2 fw-semibold">Edit profil</div>
      <div class="card-body">
        <form method="post" action="/profile/email" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-md-8">
            <label class="form-label" for="profile-email">Email</label>
            <input type="email" class="form-control" name="email" id="profile-email"
                   value="<?= e($email) ?>" maxlength="50" autocomplete="email" placeholder="nama@contoh.com">
            <div class="form-text">
              Dipakai untuk notifikasi dan <strong>wajib untuk top-up kredit</strong>. Maksimal 50 karakter.
            </div>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-white py-2 fw-semibold">Akun</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4 text-muted fw-normal small">Username</dt>
          <dd class="col-sm-8 mono"><?= e($username) ?></dd>
          <dt class="col-sm-4 text-muted fw-normal small">Peran</dt>
          <dd class="col-sm-8">
            <?php // Label peran global: AppAccess::label() hanya untuk role per-app, jadi pakai is_admin(). ?>
            <span class="badge <?= $isAdminUser ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= e($isAdminUser ? 'Admin' : 'Member') ?></span>
          </dd>
          <?php if (!$isAdminUser): ?>
            <dt class="col-sm-4 text-muted fw-normal small">Saldo kredit</dt>
            <dd class="col-sm-8">
              <?php if ($balance !== null): ?>
                <span class="fw-semibold"><?= e(format_credits($balance)) ?></span>
                <a class="small ms-1" href="/credits">Kelola kredit</a>
              <?php else: ?>
                <span class="text-muted small">Fitur kredit nonaktif.</span>
              <?php endif; ?>
            </dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
