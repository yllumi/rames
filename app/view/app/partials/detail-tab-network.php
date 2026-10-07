  <!-- ============ Tab: Network (external network lintas-app) ============ -->
  <div class="tab-pane fade" id="tab-network" role="tabpanel" aria-labelledby="tab-network-btn">
    <?php
    $extNetworks = is_array($app['external_networks'] ?? null) ? $app['external_networks'] : [];
    ?>
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">External Networks</h2>
        <span class="text-muted small">shared network lintas-app (via compose external)</span>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Hubungkan app ini ke <strong>shared network</strong> agar container-nya bisa
          saling berkomunikasi dengan app lain. Buat network lewat halaman
          <a href="/networks">Networks</a>, lalu centang di sini dan klik
          <strong>Simpan &amp; Terapkan</strong> — container diciptakan ulang dan
          koneksi ini <strong>persisten</strong> (tidak hilang saat Rebuild/Rollback).
        </p>
        <?php if (!$canNetwork): ?>
          <?php if (empty($extNetworks)): ?>
            <p class="text-muted small mb-0">App ini tidak terhubung ke external network.</p>
          <?php else: ?>
            <div class="border rounded p-2 mb-0">
              <?php foreach ($extNetworks as $n): ?>
                <span class="badge text-bg-secondary me-1 mono"><?= e((string) $n) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
        <form method="post" action="/apps/<?= e($app['id']) ?>/network">
          <?= csrf_field() ?>
          <?php if (empty($availableNetworks)): ?>
            <div class="alert alert-info py-2 small mb-3">
              Tidak ada network eksternal yang tersedia (atau Docker Engine tidak dapat
              diakses). Buat shared network dulu di halaman <a href="/networks">Networks</a>.
            </div>
          <?php else: ?>
            <div class="border rounded p-2 mb-3" style="max-height:240px; overflow-y:auto;">
              <?php foreach ($availableNetworks as $n): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="external_networks[]" value="<?= e($n) ?>" id="ext-<?= e($n) ?>"
                         <?= in_array($n, $extNetworks, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small mono" for="ext-<?= e($n) ?>"><?= e($n) ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
            <?php if ($isBusy): ?>
              <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </section>
  </div>
