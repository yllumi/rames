<?php
$pageTitle = 'Sesi Adminer kedaluwarsa';
$active = 'database';
$c = (string) ($container ?? '');
$retry = (string) ($retryUrl ?? '');
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h1 class="h6 mb-0">Sesi Adminer kedaluwarsa</h1>
      </div>
      <div class="card-body">
        <p class="mb-3">
          Sesi Adminer untuk container <span class="mono"><?= e($c) ?></span> sudah tidak aktif karena
          dibiarkan <em>idle</em>.
        </p>
        <p class="mb-3">
          Aksi yang Anda kirim <strong>tidak dijalankan</strong> &mdash; tidak ada perubahan apa pun pada
          database Anda.
        </p>
        <p class="text-muted small mb-3">
          Demi keamanan, token Adminer terikat pada sesi login sehingga halaman ini tidak mengirim ulang
          aksi Anda. Buka ulang Adminer, lalu ulangi aksi tadi (mis. jalankan ulang <em>query</em> atau
          unggah ulang berkas).
        </p>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($retry !== ''): ?>
            <a class="btn btn-primary btn-sm" href="<?= e($retry) ?>" target="_blank" rel="noopener">Buka ulang Adminer</a>
          <?php endif; ?>
          <a class="btn btn-outline-secondary btn-sm" href="/database">Kembali ke daftar Database</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
