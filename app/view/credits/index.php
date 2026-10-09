<?php
$pageTitle = 'Kredit';
$active = 'credits';

// Halaman ini hanya merender data yang sudah disiapkan CreditController (mediator).
// Tidak ada query/logika bisnis di sini; semua nilai di-escape dengan e().
$enabled = $enabled ?? true;
$isAdmin = $isAdmin ?? is_admin();
$user = $user ?? current_user();
$email = (string) ($email ?? '');
$balance = (float) ($balance ?? 0.0);
$ledger = is_array($ledger ?? null) ? $ledger : [];
$usage = is_array($usage ?? null) ? $usage : [];
$pendingOrders = is_array($pendingOrders ?? null) ? $pendingOrders : [];
$allPendingOrders = is_array($allPendingOrders ?? null) ? $allPendingOrders : [];
$users = is_array($users ?? null) ? $users : [];
$balances = is_array($balances ?? null) ? $balances : [];
$names = is_array($names ?? null) ? $names : [];
$topupEnabled = (bool) ($topupEnabled ?? false);
$topupIssues = array_values(array_filter((array) ($topupIssues ?? []), 'is_string'));
$methods = is_array($methods ?? null) ? $methods : [];
$topupMin = (int) ($topupMin ?? 10000);
$topupMax = (int) ($topupMax ?? 5000000);
$idrPerCredit = (float) ($idrPerCredit ?? 1.0);
// Kurs bisa pecahan (`BILLING_TOPUP_IDR_PER_CREDIT` float) → jangan paksa 0 desimal.
$kursLabel = static fn (float $rate): string => number_format($rate, $rate == (int) $rate ? 0 : 2, ',', '.');
$minDepositDays = (int) ($minDepositDays ?? 30);
$periodLabel = (string) ($periodLabel ?? '');
$focusOrder = is_array($focusOrder ?? null) ? $focusOrder : null;

$ledgerLabels = [
    'deposit' => 'Deposit',
    'topup' => 'Top-up',
    'charge' => 'Penagihan',
    'adjust' => 'Penyesuaian',
];
$orderLabels = [
    'pending' => ['Menunggu pembayaran', 'text-bg-warning'],
    'paid' => ['Berhasil', 'text-bg-success'],
    'failed' => ['Gagal', 'text-bg-danger'],
    'expired' => ['Kedaluwarsa', 'text-bg-secondary'],
];

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Kredit', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Kredit</h1>
    <p class="text-muted mb-0">
      Saldo kredit dipakai untuk pemakaian CPU/RAM app Anda. Isi lewat
      <strong>top-up online</strong> atau minta <strong>deposit manual</strong> ke admin.
      Pemakaian dihitung per jam dan ditagih pada awal periode berikutnya (<?= e($periodLabel) ?>).
    </p>
  </div>
</div>

<?php if (!$enabled): ?>
  <div class="alert alert-warning" role="alert">
    <strong>Fitur kredit nonaktif.</strong> Variabel <span class="mono">BILLING_ENABLED=false</span> mematikan
    meteran &amp; penagihan. Saldo yang sudah tercatat tetap ditampilkan, dan deposit manual admin tetap bisa dicatat.
  </div>
<?php endif; ?>

<?php if ($focusOrder !== null): ?>
  <?php
    $focusStatus = (string) ($focusOrder['status'] ?? 'pending');
    [$statusLabel, $statusClass] = $orderLabels[$focusStatus] ?? ['Tidak diketahui', 'text-bg-secondary'];
  ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <span class="badge <?= e($statusClass) ?> fs-6"><?= e($statusLabel) ?></span>
      <div class="me-auto">
        <div class="fw-semibold">Order top-up <span class="mono"><?= e($focusOrder['id'] ?? '') ?></span></div>
        <div class="text-muted small">
          Nominal Rp<?= e(number_format((float) ($focusOrder['amount_idr'] ?? 0), 0, ',', '.')) ?>
          · <?= e(format_credits((float) ($focusOrder['credits'] ?? 0.0))) ?> kredit
          <?php if (($focusOrder['method'] ?? '') !== ''): ?>
            · metode <?= e(strtoupper((string) $focusOrder['method'])) ?>
          <?php endif; ?>
        </div>
        <?php if ($focusStatus === 'pending'): ?>
          <div class="form-text mb-0">Penyelesaian pembayaran diproses otomatis setelah gateway mengonfirmasi. Saldo bertambah setelah pembayaran terverifikasi.</div>
        <?php elseif ($focusStatus === 'paid'): ?>
          <div class="form-text mb-0">Kredit sudah masuk ke saldo Anda.</div>
        <?php endif; ?>
      </div>
      <a class="btn btn-outline-secondary btn-sm" href="/credits">Kembali ke Kredit</a>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Saldo kredit</div>
        <div class="display-6 fw-semibold"><?= e(format_credits($balance)) ?></div>
        <div class="form-text mb-3">
          kredit tersedia<?php if ($idrPerCredit > 0): ?>
            · ≈ Rp<?= e(number_format($balance * $idrPerCredit, 0, ',', '.')) ?>
            <br>Kurs: Rp<?= e($kursLabel($idrPerCredit)) ?> = 1 kredit
          <?php endif; ?>
        </div>
        <?php if ($topupEnabled): ?>
          <a class="btn btn-primary btn-sm" href="#topup-form">Top-up online</a>
        <?php else: ?>
          <span class="text-muted small">Top-up online tidak tersedia — hubungi admin untuk deposit.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header bg-white py-2 d-flex align-items-center">
        <span class="fw-semibold">Pemakaian berjalan</span>
        <span class="text-muted small ms-2">per app milik Anda, sejak awal periode</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>App</th>
              <th class="text-end">Jam akrual</th>
              <th class="text-end">Kredit terakumulasi</th>
              <th class="text-end">Per jam</th>
              <th class="text-end">Estimasi / bulan</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($usage === []): ?>
              <tr><td colspan="5" class="text-muted small">Belum ada pemakaian tercatat.</td></tr>
            <?php else: ?>
              <?php foreach ($usage as $appId => $row): ?>
                <tr>
                  <td><a href="/apps/<?= e($appId) ?>"><?= e($row['name'] ?? $appId) ?></a></td>
                  <td class="text-end"><?= e(number_format((float) ($row['seconds_pending'] ?? 0) / 3600, 2, ',', '.')) ?></td>
                  <td class="text-end"><?= e(format_credits((float) ($row['credits_pending'] ?? 0.0))) ?></td>
                  <td class="text-end"><?= e(format_credits((float) ($row['hourly'] ?? 0.0))) ?></td>
                  <td class="text-end"><?= e(format_credits((float) ($row['estimate_month'] ?? 0.0))) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted small">
        Estimasi bulanan memakai tarif saat ini dan jumlah hari di periode <?= e($periodLabel) ?>.
      </div>
    </div>
  </div>
</div>

<?php if ($pendingOrders !== []): ?>
  <div class="card mb-4">
    <div class="card-header bg-white py-2 fw-semibold">Order top-up menunggu pembayaran</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>Order</th><th>Dibuat</th><th class="text-end">Nominal</th><th class="text-end">Kredit</th><th>Metode</th><th class="text-end">Aksi</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pendingOrders as $order): ?>
            <tr>
              <td class="mono small"><?= e($order['id'] ?? '') ?></td>
              <td class="small text-muted"><?= e($order['created_at'] ?? '') ?></td>
              <td class="text-end">Rp<?= e(number_format((float) ($order['amount_idr'] ?? 0), 0, ',', '.')) ?></td>
              <td class="text-end"><?= e(format_credits((float) ($order['credits'] ?? 0.0))) ?></td>
              <td class="small"><?= e(strtoupper((string) ($order['method'] ?? ''))) ?></td>
              <td class="text-end">
                <?php if (($order['payment_url'] ?? '') !== ''): ?>
                  <a class="btn btn-outline-primary btn-sm" href="<?= e($order['payment_url']) ?>">Lanjutkan pembayaran</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary btn-sm" href="/credits/topup/return?order=<?= e(urlencode((string) ($order['id'] ?? ''))) ?>">Cek status</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header bg-white py-2 fw-semibold">Riwayat transaksi</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Jenis</th>
          <th>Keterangan</th>
          <th class="text-end">Perubahan</th>
          <th class="text-end">Saldo setelah</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($ledger === []): ?>
          <tr><td colspan="5" class="text-muted small">Belum ada transaksi.</td></tr>
        <?php else: ?>
          <?php foreach ($ledger as $entry): ?>
            <?php
              $type = (string) ($entry['type'] ?? '');
              $amount = (float) ($entry['amount'] ?? 0.0);
              $by = (string) ($entry['by'] ?? '');
              $note = (string) ($entry['note'] ?? '');
              $period = (string) ($entry['period'] ?? '');
            ?>
            <tr>
              <td class="small text-muted text-nowrap"><?= e($entry['at'] ?? '') ?></td>
              <td><span class="badge text-bg-light border"><?= e($ledgerLabels[$type] ?? $type) ?></span></td>
              <td class="small">
                <?= e($note) ?>
                <?php if ($period !== ''): ?> <span class="text-muted">(<?= e($period) ?>)</span><?php endif; ?>
                <?php if ($isAdmin && $by !== ''): ?> <span class="text-muted">· oleh <?= e($names[$by] ?? $by) ?></span><?php endif; ?>
              </td>
              <td class="text-end <?= $amount < 0 ? 'text-danger' : 'text-success' ?>">
                <?= e($amount < 0 ? '−' : '+') ?><?= e(format_credits(abs($amount))) ?>
                <?php
                  // Nominal rupiah asli (top-up) lebih dipercaya daripada kredit × kurs
                  // saat ini; entri topup lama tanpa `idr` tidak ditebak sama sekali.
                  $entryIdr = is_numeric($entry['idr'] ?? null) ? abs((float) $entry['idr']) : null;
                  if ($entryIdr === null && $type !== 'topup' && $idrPerCredit > 0) {
                      $entryIdr = abs($amount) * $idrPerCredit;
                  }
                ?>
                <?php if ($entryIdr !== null && $entryIdr > 0): ?>
                  <div class="text-muted small fw-normal">≈ Rp<?= e(number_format($entryIdr, 0, ',', '.')) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= e(format_credits((float) ($entry['balance_after'] ?? 0.0))) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($topupEnabled): ?>
  <div class="card mb-4" id="topup-form">
    <div class="card-header bg-white py-2 fw-semibold">Top-up online</div>
    <div class="card-body">
      <?php if (trim($email) === ''): ?>
        <div class="alert alert-warning py-2 small" role="alert">
          <strong>Email belum diisi.</strong> Top-up memerlukan email (dipakai gateway pembayaran).
          Isi email pada form di bawah sebelum melanjutkan.
        </div>
      <?php endif; ?>
      <form method="post" action="/credits/topup" id="credits-topup" class="row g-3"
            data-methods-url="/api/credits/methods"
            data-min="<?= e((string) $topupMin) ?>"
            data-max="<?= e((string) $topupMax) ?>"
            data-per-credit="<?= e((string) $idrPerCredit) ?>">
        <?= csrf_field() ?>
        <div class="col-md-6">
          <label class="form-label" for="topup-amount">Nominal (Rp)</label>
          <input type="number" class="form-control" name="amount_idr" id="topup-amount"
                 min="<?= e((string) $topupMin) ?>" max="<?= e((string) $topupMax) ?>" step="1000"
                 value="<?= e((string) $topupMin) ?>" required>
          <div class="form-text">
            Minimum Rp<?= e(number_format($topupMin, 0, ',', '.')) ?><?php if ($topupMax > 0): ?>,
            maksimum Rp<?= e(number_format($topupMax, 0, ',', '.')) ?><?php endif; ?>.
            <strong>Kurs: Rp<?= e($kursLabel(max($idrPerCredit, 0.01))) ?> = 1 kredit.</strong>
            Perkiraan kredit: <span id="topup-credits"><?= e(format_credits($topupMin / max($idrPerCredit, 0.01))) ?></span>.
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="topup-method">Metode pembayaran</label>
          <select class="form-select" name="method" id="topup-method" required>
            <?php if ($methods === []): ?>
              <option value="" disabled selected>Memuat metode …</option>
            <?php else: ?>
              <?php foreach ($methods as $code): ?>
                <option value="<?= e($code) ?>"><?= e($code) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <div class="form-text" id="topup-method-hint">Daftar metode dimuat dari gateway saat halaman dibuka.</div>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary btn-sm"<?= trim($email) === '' ? ' disabled' : '' ?>>Lanjut ke pembayaran</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header bg-white py-2 fw-semibold">Email notifikasi</div>
  <div class="card-body">
    <form method="post" action="/credits/email" class="row g-3">
      <?= csrf_field() ?>
      <div class="col-md-8">
        <label class="form-label" for="credits-email">Email Anda</label>
        <input type="email" class="form-control" name="email" id="credits-email"
               value="<?= e($email) ?>" maxlength="50" autocomplete="email" placeholder="nama@contoh.com">
        <div class="form-text">Wajib untuk top-up online. Kosongkan lalu simpan untuk menghapus email.</div>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-primary btn-sm">Simpan email</button>
      </div>
    </form>
  </div>
</div>

<?php if ($isAdmin): ?>
  <hr class="my-4">
  <h2 class="h5 mb-3">Admin</h2>

  <?php if (!$topupEnabled): ?>
    <div class="alert alert-info" role="alert">
      <strong>Top-up online belum aktif</strong> — form top-up disembunyikan dan endpoint-nya menjawab 404.
      Perbaiki salah satu hal berikut lalu <span class="mono">restart</span> dashboard:
      <ul class="mb-0 mt-2">
        <?php if ($topupIssues === []): ?>
          <li>Konfigurasi terbaca lengkap; periksa kembali setelah restart.</li>
        <?php else: ?>
          <?php foreach ($topupIssues as $issue): ?>
            <li><span class="mono"><?= e($issue) ?></span></li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header bg-white py-2 fw-semibold">Deposit manual</div>
        <div class="card-body">
          <form method="post" action="/credits/deposit" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-5">
              <label class="form-label" for="deposit-user">User</label>
              <select class="form-select" name="user_id" id="deposit-user" required>
                <?php foreach ($users as $u): ?>
                  <option value="<?= e($u['id'] ?? '') ?>">
                    <?= e($u['username'] ?? '') ?><?= ($u['role'] ?? '') === 'admin' ? ' (admin)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="deposit-amount">Nominal</label>
              <input type="text" inputmode="decimal" class="form-control" name="amount" id="deposit-amount"
                     placeholder="mis. 50000" required>
              <div class="form-text">Negatif (mis. <span class="mono">-5000</span>) untuk mengurangi saldo.
                Pengurangan manual <strong>tidak</strong> langsung menghentikan app — auto-stop berjalan
                saat penagihan periode berikutnya (kebijakan <span class="mono">stop</span>).</div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="deposit-note">Catatan</label>
              <input type="text" class="form-control" name="note" id="deposit-note" maxlength="200"
                     placeholder="mis. transfer bank">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary btn-sm">Catat deposit</button>
              <span class="form-text ms-2">Nominal negatif diizinkan untuk penyesuaian (lihat catatan).</span>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header bg-white py-2 fw-semibold">Penagihan manual</div>
        <div class="card-body">
          <form method="post" action="/credits/charge" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-12">
              <label class="form-label" for="charge-period">Periode (opsional)</label>
              <input type="month" class="form-control" name="period" id="charge-period"
                     placeholder="YYYY-MM">
              <div class="form-text">Kosongkan untuk menagih semua periode tertunggak, atau isi untuk menutup periode tertentu.</div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-outline-danger btn-sm">Jalankan penagihan sekarang</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header bg-white py-2 fw-semibold">Saldo semua user</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>User</th><th>Role</th><th>Email</th><th class="text-end">Saldo</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php $uid = (string) ($u['id'] ?? ''); ?>
            <tr>
              <td><?= e($u['username'] ?? '') ?></td>
              <td><span class="badge text-bg-light border"><?= e($u['role'] ?? '') ?></span></td>
              <td class="small text-muted"><?= e($u['email'] ?? '') ?></td>
              <td class="text-end"><?= e(format_credits((float) ($balances[$uid] ?? 0.0))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header bg-white py-2 fw-semibold">Order top-up pending (semua user)</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr><th>Order</th><th>User</th><th>Dibuat</th><th class="text-end">Nominal</th><th class="text-end">Kredit</th><th>Metode</th></tr>
        </thead>
        <tbody>
          <?php if ($allPendingOrders === []): ?>
            <tr><td colspan="6" class="text-muted small">Tidak ada order pending.</td></tr>
          <?php else: ?>
            <?php foreach ($allPendingOrders as $order): ?>
              <?php $orderUser = (string) ($order['user_id'] ?? ''); ?>
              <tr>
                <td class="mono small"><?= e($order['id'] ?? '') ?></td>
                <td class="small"><?= e($names[$orderUser] ?? $orderUser) ?></td>
                <td class="small text-muted"><?= e($order['created_at'] ?? '') ?></td>
                <td class="text-end">Rp<?= e(number_format((float) ($order['amount_idr'] ?? 0), 0, ',', '.')) ?></td>
                <td class="text-end"><?= e(format_credits((float) ($order['credits'] ?? 0.0))) ?></td>
                <td class="small"><?= e(strtoupper((string) ($order['method'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script src="/js/credits.js?v=1"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>
