<?php $pageTitle = 'Nginx'; $active = 'nginx'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Nginx</h1>
    <p class="text-muted mb-0">
      Status &amp; reload <strong>Nginx host</strong> — berlaku global untuk semua app.
      Reload dijalankan lewat helper container via Docker socket (validasi
      <span class="mono">nginx -t</span> lalu reload tanpa downtime).
    </p>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/apps">&larr; Apps</a>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="h6 mb-0">Status Reload</h2>
    <?php if (is_admin()): ?>
    <form method="post" action="/nginx/reload" class="d-inline">
      <?= csrf_field() ?>
      <button class="btn btn-outline-secondary btn-sm" title="Validasi config lalu reload nginx host">↻ Reload Nginx</button>
    </form>
    <?php else: ?>
    <span class="text-muted small">Reload hanya untuk admin (berlaku untuk seluruh host).</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($status): ?>
      <?php if (!empty($status['ok'])): ?>
        <div class="alert alert-success mb-0">Reload Nginx terakhir berhasil (<?= e($status['updated_at'] ?? '?') ?>).</div>
      <?php else: ?>
        <div class="alert alert-danger mb-0">Reload Nginx terakhir GAGAL: <?= e($status['error'] ?? 'unknown') ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="text-muted small mb-0">Belum ada status reload. Klik <strong>Reload Nginx</strong> untuk memuat &amp; mengaktifkan config terbaru (mis. setelah deploy, set custom domain, atau aktifkan SSL).</div>
    <?php endif; ?>
  </div>
</div>

<?php
// ---------------------------------------------------------------------------
// Panel self-update dashboard (SPECS.md §7.8). Ditaruh di halaman operasional
// host ini supaya tidak menambah menu nav baru; badge "update" di nav mengarah
// ke sini.
// ---------------------------------------------------------------------------
// $update null = fitur dimatikan / panel gagal dibaca. Normalisasi dulu supaya
// blok perhitungan di bawah tidak pernah mengakses array pada null.
$update = is_array($update ?? null) ? $update : null;
$run = is_array($update['run'] ?? null) ? $update['run'] : [];
$check = is_array($update['check'] ?? null) ? $update['check'] : [];
$pf = is_array($update['preflight'] ?? null) ? $update['preflight'] : ['ok' => false, 'errors' => [], 'warnings' => []];
$head = is_array($update['head'] ?? null) ? $update['head'] : null;
$shortSha = static function (?string $sha): string {
    $sha = trim((string) $sha);
    return $sha === '' ? '—' : substr($sha, 0, 7);
};
$stageLabels = [
    'starting' => 'Menyiapkan',
    'preflight' => 'Prasyarat',
    'fetch' => 'Ambil versi',
    'merge' => 'Terapkan versi',
    'composer' => 'Dependensi Composer',
    'build' => 'Build & recreate',
    'health' => 'Verifikasi sehat',
    'rolling_back' => 'Rollback',
    'finished' => 'Selesai',
];
$resultBadges = [
    'success' => ['text-bg-success', 'Berhasil'],
    'rolled_back' => ['text-bg-warning', 'Dibatalkan (rollback otomatis)'],
    'error' => ['text-bg-danger', 'Gagal'],
];
$runBadge = $resultBadges[$run['result'] ?? ''] ?? ['text-bg-info', !empty($update['running']) ? 'Berjalan…' : 'Menunggu'];
// Rollback hanya masuk akal tepat setelah update yang berhasil.
$rollbackSha = ($run['mode'] ?? '') === 'update' && ($run['result'] ?? null) === 'success' ? (string) ($run['old_sha'] ?? '') : '';
$canUpdate = is_admin() && !empty($pf['ok']) && !empty($check['update_available']);
?>
<?php if ($update): ?>
<div class="card mt-3" id="update-panel"
     data-running="<?= !empty($update['running']) ? '1' : '0' ?>"
     data-run-id="<?= e((string) ($run['id'] ?? '')) ?>">
  <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="h6 mb-0">Versi Dashboard <span class="text-muted fw-normal small">(self-update)</span></h2>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="update-check-btn"
              title="Bandingkan commit lokal dengan branch di remote (git ls-remote)">⟳ Cek Pembaruan</button>
      <?php if (is_admin()): ?>
      <button type="button" class="btn btn-outline-danger btn-sm" id="update-rollback-btn"
              data-sha="<?= e($shortSha($rollbackSha)) ?>"
              <?= $rollbackSha !== '' && !empty($pf['ok']) ? '' : 'disabled' ?>
              title="Kembalikan ke versi sebelum update terakhir">↶ Rollback<?= $rollbackSha !== '' && !empty($pf['ok']) ? ' ke ' . e($shortSha($rollbackSha)) : '' ?></button>
      <button type="button" class="btn btn-primary btn-sm" id="update-start-btn"
              <?= $canUpdate ? '' : 'disabled' ?>
              title="<?= $canUpdate ? 'Pull + rebuild + recreate dashboard' : 'Tidak tersedia: belum ada pembaruan / repo tidak bersih / bukan admin' ?>">⬆ Update Sekarang</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <?php if (!empty($update['failed'])): ?>
      <div class="alert alert-danger mb-0">Gagal membaca status update: <?= e((string) $update['failed']) ?></div>
    <?php else: ?>
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="small text-muted">Versi aktif</div>
          <div class="mono"><?= e($update['branch'] !== '' ? (string) $update['branch'] : '?') ?> @ <?= e($shortSha($check['local_sha'])) ?></div>
          <?php if ($head): ?>
            <div class="small text-muted mt-1"><?= e((string) ($head['date'] ?? '')) ?></div>
            <div class="small"><?= e((string) ($head['subject'] ?? '')) ?></div>
          <?php endif; ?>
        </div>
        <div class="col-lg-6">
          <div class="small text-muted">Status pembaruan</div>
          <?php if (empty($check['checked_at'])): ?>
            <div class="small">Belum pernah dicek — tekan <strong>Cek Pembaruan</strong>.</div>
          <?php elseif (empty($check['ok'])): ?>
            <div class="small text-danger">Pengecekan gagal: <?= e((string) ($check['error'] ?? 'tidak diketahui')) ?></div>
          <?php elseif (!empty($check['update_available'])): ?>
            <div class="small">
              <span class="badge text-bg-warning">ada pembaruan</span>
              remote <span class="mono"><?= e($shortSha($check['remote_sha'])) ?></span>
              <?php if (!empty($check['compare_url'])): ?>
                — <a href="<?= e((string) $check['compare_url']) ?>" target="_blank" rel="noopener">lihat perubahan</a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="small"><span class="badge text-bg-success">terbaru</span> sudah sama dengan remote</div>
          <?php endif; ?>
          <div class="small text-muted mt-1">Terakhir dicek: <?= e((string) ($check['checked_at'] ?? '—')) ?></div>
        </div>
      </div>

      <?php if (!empty($check['tracked_changes'])): ?>
      <div class="alert alert-danger mt-3 mb-0 small">
        <strong>Ada perubahan yang belum di-commit</strong> — update selalu ditolak selama repo kotor (update tidak boleh menimpa pekerjaan lokal):
        <div class="mono mt-1"><?php foreach (array_slice((array) $check['tracked_changes'], 0, 10) as $changed): ?><?= e((string) $changed) ?><br><?php endforeach; ?></div>
      </div>
      <?php endif; ?>

      <?php if (empty($pf['ok'])): ?>
      <div class="alert alert-warning mt-3 mb-0 small">
        <strong>Update belum bisa dijalankan:</strong>
        <ul class="mb-0"><?php foreach ((array) $pf['errors'] as $err): ?><li><?= e((string) $err) ?></li><?php endforeach; ?></ul>
      </div>
      <?php endif; ?>
      <?php if (!empty($pf['warnings'])): ?>
      <div class="alert alert-secondary mt-3 mb-0 small">
        <?php foreach ((array) $pf['warnings'] as $warning): ?><div><?= e((string) $warning) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div id="update-run-box" class="mt-3 <?= ($run['id'] ?? null) === null && empty($update['running']) ? 'd-none' : '' ?>">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
          <div class="d-flex align-items-center gap-2">
            <span class="badge <?= e($runBadge[0]) ?>" id="update-run-result"><?= e($runBadge[1]) ?></span>
            <span class="small" id="update-run-message"><?= e((string) ($run['message'] ?? '')) ?></span>
          </div>
          <span class="small text-muted" id="update-run-stage"><?= ($run['stage'] ?? '') !== '' ? 'Tahap: ' . e($stageLabels[$run['stage']] ?? (string) $run['stage']) : '' ?></span>
        </div>
        <?php if (!empty($update['running'])): ?>
        <div class="small text-muted mt-1">Update berjalan — dashboard akan terputus sebentar saat container di-recreate. Halaman ini menyambung kembali otomatis.</div>
        <?php endif; ?>
        <pre id="update-log" class="mono small mt-2 mb-0" style="max-height:280px;overflow:auto;background:#0b1020;color:#d7e3ff;padding:.75rem;border-radius:.5rem;"><?= e((string) ($update['log_tail'] ?? '')) ?></pre>
      </div>

      <?php if (!empty($run['started_at'])): ?>
      <div class="small text-muted mt-2">
        Run terakhir: <span class="mono"><?= e((string) ($run['id'] ?? '')) ?></span>
        oleh <?= e((string) ($run['actor'] ?? '?')) ?>
        (<?= e((string) $run['started_at']) ?><?= !empty($run['finished_at']) ? ' → ' . e((string) $run['finished_at']) : '' ?>)
      </div>
      <?php endif; ?>

      <?php if (!empty($update['logs'])): ?>
      <details class="mt-2">
        <summary class="small text-muted">Riwayat log update (<?= count((array) $update['logs']) ?>)</summary>
        <div class="small mono mt-1">
          <?php foreach ((array) $update['logs'] as $log): ?>
            <div><?= e((string) $log['name']) ?> <span class="text-muted">(<?= number_format(((int) $log['size']) / 1024, 1) ?> KB · <?= e((string) $log['modified_at']) ?>)</span></div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>

      <div class="small text-muted mt-3">
        Update = <span class="mono">git pull</span> + <span class="mono">docker compose up -d --build</span> pada container dashboard,
        dijalankan helper container terpisah (di luar lifecycle dashboard) sebagai pemilik berkas repo.
        Bila versi baru tidak sehat dalam batas waktu, kode dikembalikan otomatis ke versi sebelumnya.
      </div>
    <?php endif; ?>
  </div>
</div>
<div id="update-flash"></div>
<script src="/js/update.js?v=1"></script>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
