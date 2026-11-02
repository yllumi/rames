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

// Kartu 2FA. ProfileController::index() selalu mengirim `twoFactor`, tetapi
// view ini harus tetap bisa di-smoke-render tanpa controller sehingga tiap key
// dinormalisasi dengan fallback aman (tanpa efek samping / tanpa mutasi).
$twoFactor = is_array($twoFactor ?? null) ? $twoFactor : [];
$totpEnabled = is_bool($twoFactor['enabled'] ?? null) ? $twoFactor['enabled'] : false;
$totpRemaining = is_numeric($twoFactor['recovery_remaining'] ?? null) ? max(0, (int) $twoFactor['recovery_remaining']) : 0;

$totpSetup = null;
$rawSetup = $twoFactor['setup'] ?? null;
if (is_array($rawSetup)) {
    $totpSecret = is_scalar($rawSetup['secret'] ?? null) ? (string) $rawSetup['secret'] : '';
    $totpQrSvg = is_scalar($rawSetup['qr_svg'] ?? null) ? (string) $rawSetup['qr_svg'] : '';
    if ($totpSecret !== '' || $totpQrSvg !== '') {
        $totpSetup = ['secret' => $totpSecret, 'qr_svg' => $totpQrSvg];
    }
}

$totpCodes = null;
if (is_array($twoFactor['recovery_codes'] ?? null)) {
    $totpCodes = [];
    foreach ($twoFactor['recovery_codes'] as $rawTotpCode) {
        if (is_scalar($rawTotpCode)) {
            $totpCodes[] = (string) $rawTotpCode;
        }
    }
    if ($totpCodes === []) {
        $totpCodes = null;
    }
}

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
      Kelola email notifikasi dan password akun Anda. Username &amp; peran hanya dapat diubah oleh admin.
    </p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header bg-body py-2 fw-semibold">Edit profil</div>
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
      <div class="card-header bg-body py-2 fw-semibold">Akun</div>
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

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header bg-body py-2 fw-semibold">Ubah password</div>
      <div class="card-body">
        <?php // Password tidak pernah dirender balik: tidak ada atribut value di form ini. ?>
        <form method="post" action="/profile/password" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-12">
            <label class="form-label" for="profile-current-password">Password saat ini</label>
            <input type="password" class="form-control" name="current_password" id="profile-current-password"
                   required autocomplete="current-password">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="profile-new-password">Password baru</label>
            <input type="password" class="form-control" name="new_password" id="profile-new-password"
                   required minlength="6" autocomplete="new-password" aria-describedby="profile-new-password-hint">
            <div class="form-text" id="profile-new-password-hint">Minimal 6 karakter.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="profile-new-password-confirm">Ulangi password baru</label>
            <input type="password" class="form-control" name="new_password_confirm" id="profile-new-password-confirm"
                   required minlength="6" autocomplete="new-password">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-sm">Perbarui password</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header bg-body py-2 fw-semibold">Tema tampilan</div>
      <div class="card-body">
        <?php // Murni klien: tanpa form action & tanpa CSRF — theme.js menyimpan ke localStorage. ?>
        <fieldset>
          <legend class="visually-hidden">Pilih tema tampilan</legend>
          <div class="vstack gap-2">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="theme-preference" id="theme-preference-light"
                     value="light" data-theme-choice="light">
              <label class="form-check-label" for="theme-preference-light">Terang</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="theme-preference" id="theme-preference-dark"
                     value="dark" data-theme-choice="dark">
              <label class="form-check-label" for="theme-preference-dark">Gelap</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="theme-preference" id="theme-preference-system"
                     value="system" data-theme-choice="system" checked>
              <label class="form-check-label" for="theme-preference-system">Ikut sistem</label>
            </div>
          </div>
        </fieldset>
        <div class="form-text mt-2">Preferensi disimpan di browser ini, bukan di akun.</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card">
      <div class="card-header bg-body py-2 fw-semibold d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>Verifikasi dua langkah (2FA)</span>
        <span class="badge <?= $totpEnabled ? 'text-bg-success' : 'text-bg-light border' ?>">
          <?= e($totpEnabled ? 'Aktif' : 'Nonaktif') ?>
        </span>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Kode TOTP dari aplikasi authenticator (Google Authenticator, Aegis, Microsoft Authenticator, dsb.)
          menjadi langkah kedua saat login, sehingga password saja tidak cukup.
          <?php if ($totpEnabled): ?>
            <span class="d-block mt-1">
              Kode pemulihan tersisa: <strong><?= e((string) $totpRemaining) ?></strong>
            </span>
          <?php endif; ?>
        </p>

        <?php // Kode pemulihan hanya dikirim sekali lewat session lalu dihapus controller. ?>
        <?php if ($totpCodes !== null): ?>
          <div class="alert alert-warning" role="alert">
            <p class="fw-semibold mb-1">Simpan kode pemulihan ini sekarang.</p>
            <p class="small mb-2">
              Kode hanya ditampilkan <strong>sekali</strong> dan tidak bisa dilihat lagi.
              Simpan di tempat aman, misalnya password manager — tiap kode bisa dipakai sekali
              bila aplikasi authenticator tidak tersedia.
            </p>
            <pre class="mb-0 border rounded-2 bg-body-tertiary p-3 mono" style="user-select: all;"><?= e(implode("\n", $totpCodes)) ?></pre>
          </div>
        <?php endif; ?>

        <?php if ($totpEnabled): ?>
          <div class="row g-4">
            <div class="col-lg-6">
              <h2 class="h6 mb-2">Nonaktifkan 2FA</h2>
              <p class="text-danger small mb-2">
                Setelah dinonaktifkan, akun hanya dilindungi password. Pastikan Anda yakin.
              </p>
              <form method="post" action="/profile/2fa/disable" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                  <label class="form-label" for="totp-disable-password">Password saat ini</label>
                  <input type="password" class="form-control" name="current_password" id="totp-disable-password"
                         required autocomplete="current-password">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="totp-disable-code">Kode verifikasi</label>
                  <input type="text" class="form-control" name="code" id="totp-disable-code"
                         required autocomplete="one-time-code" maxlength="20"
                         placeholder="123456">
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-outline-danger btn-sm">Nonaktifkan 2FA</button>
                </div>
              </form>
            </div>
            <div class="col-lg-6">
              <h2 class="h6 mb-2">Kode pemulihan</h2>
              <p class="text-muted small mb-2">
                Buat ulang seluruh kode pemulihan? Kode lama langsung tidak berlaku dan kode baru
                hanya ditampilkan sekali setelah dibuat.
              </p>
              <form method="post" action="/profile/2fa/recovery" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                  <label class="form-label" for="totp-recovery-password">Password saat ini</label>
                  <input type="password" class="form-control" name="current_password" id="totp-recovery-password"
                         required autocomplete="current-password">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="totp-recovery-code">Kode verifikasi</label>
                  <input type="text" class="form-control" name="code" id="totp-recovery-code"
                         required autocomplete="one-time-code" maxlength="20"
                         placeholder="123456">
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-outline-secondary btn-sm">Buat kode pemulihan baru</button>
                </div>
              </form>
            </div>
          </div>
        <?php elseif ($totpSetup !== null): ?>
          <div class="row g-4">
            <div class="col-md-5">
              <?php if (($totpSetup['qr_svg'] ?? '') !== ''): ?>
                <?php // Satu-satunya output tak di-escape: SVG QR tepercaya dari server (TwoFactorService::qrSvg). ?>
                <div class="d-inline-block border rounded-3 bg-white p-2 mb-2" style="max-width: 260px;">
                  <?= $totpSetup['qr_svg'] ?>
                </div>
                <div class="form-text">Pindai QR ini dengan aplikasi authenticator.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-7">
              <div class="mb-3">
                <div class="form-text mb-1">atau masukkan kunci manual</div>
                <code class="d-block border rounded-2 bg-body-tertiary p-2 mono"
                      style="user-select: all; word-break: break-all;"><?= e($totpSetup['secret']) ?></code>
              </div>
              <form method="post" action="/profile/2fa/enable" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-sm-6">
                  <label class="form-label" for="totp-enable-code">Kode verifikasi</label>
                  <input type="text" class="form-control" name="code" id="totp-enable-code"
                         required inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                         autofocus placeholder="123456" aria-describedby="totp-enable-hint">
                </div>
                <div class="col-sm-6 d-flex align-items-end">
                  <button type="submit" class="btn btn-primary btn-sm">Konfirmasi &amp; aktifkan</button>
                </div>
                <div class="col-12">
                  <div class="form-text" id="totp-enable-hint">
                    Masukkan 6 digit yang tampil di aplikasi authenticator setelah QR dipindai.
                  </div>
                </div>
              </form>
            </div>
          </div>
        <?php else: ?>
          <form method="post" action="/profile/2fa/setup" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-6">
              <label class="form-label" for="totp-setup-password">Password saat ini</label>
              <input type="password" class="form-control" name="current_password" id="totp-setup-password"
                     required autocomplete="current-password" aria-describedby="totp-setup-hint">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-sm">Aktifkan 2FA</button>
            </div>
            <div class="col-12">
              <div class="form-text" id="totp-setup-hint">
                Password diminta untuk mengonfirmasi bahwa perubahan ini dilakukan oleh Anda sendiri.
                Setelah itu Anda akan diminta memindai QR dan memasukkan 6 digit kode.
              </div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
