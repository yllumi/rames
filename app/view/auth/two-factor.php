<?php
// Halaman langkah kedua login (2FA TOTP). Payload dari
// AuthController::twoFactorForm(): ['username' => string, 'attempts_remaining' => int].
// Fallback di bawah hanya menjaga view tetap bisa di-smoke-render tanpa
// controller; tidak ada logika bisnis/otorisasi di sini.
$__username = is_scalar($username ?? null) ? (string) $username : '';
$__attempts = is_numeric($attempts_remaining ?? null) ? max(0, (int) $attempts_remaining) : 0;
// Tampilkan peringatan hanya saat percobaan tersisa mulai menipis (1..4);
// sisa 0 sudah berujung redirect ke /login oleh controller.
$__showAttempts = $__attempts > 0 && $__attempts < 5;

// Identitas brand (rebranding) — helper global `app/functions.php`.
$__brandName = brand_name();
$__brandLogo = brand_logo_url();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include app_path() . '/view/partials/theme-boot.php'; ?>
<title>Verifikasi 2FA · <?= e($__brandName) ?></title>
<link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/css/app.css">
</head>
<body class="auth-body">
<div class="auth-glow"></div>
<div class="auth-card">
  <div class="card-body p-4 p-md-5">
    <div class="auth-brand mb-2">
      <img src="<?= e($__brandLogo) ?>" alt="<?= e($__brandName) ?>" width="160" height="160" class="rounded-3">
      <div><?= e($__brandName) ?></div>
    </div>
    <p class="sub">Masuk sebagai <strong><?= e($__username) ?></strong></p>
    <p class="text-muted small text-center mb-3">
      Buka aplikasi authenticator Anda, lalu masukkan 6 digit kode verifikasi
      (atau kode pemulihan) untuk melanjutkan.
    </p>
    <?php $__flash = flash_pull(); ?>
    <?php if ($__flash): ?>
      <?php
        $__type = $__flash['type'] ?? 'info';
        $__alertClass = $__type === 'error' ? 'alert-danger' : ($__type === 'success' ? 'alert-success' : 'alert-info');
      ?>
      <div class="alert <?= e($__alertClass) ?>" role="alert"><?= e($__flash['message'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($__showAttempts): ?>
      <p class="text-warning small text-center mb-3" role="status">Sisa percobaan: <?= e((string) $__attempts) ?></p>
    <?php endif; ?>
    <form method="post" action="/login/2fa">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="code">Kode verifikasi</label>
        <?php // `pattern` sengaja tidak membatasi ke digit saja: kode pemulihan berbentuk `XXXX-XXXX`. ?>
        <input type="text" class="form-control" id="code" name="code"
               autocomplete="one-time-code" autofocus required
               maxlength="20" pattern="[0-9A-Za-z\- ]{6,20}"
               placeholder="123456" aria-describedby="code-hint">
        <div class="form-text" id="code-hint">
          Bisa juga memakai kode pemulihan (format <code>XXXX-XXXX</code>) bila aplikasi authenticator tidak tersedia.
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100">Verifikasi</button>
    </form>
    <p class="text-center mt-3 mb-0">
      <a class="small" href="/login">Kembali ke halaman login</a>
    </p>
  </div>
</div>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
