  <!-- ============ Tab: Sumber Daya (batas maksimum CPU/memori per service) ============ -->
  <?php
  // Kontrak AppController::limitsContext() — `$resourceLimits` bisa null/kosong
  // (mis. base compose belum terbaca); view wajib tetap render normal.
  $limitsCtx = is_array($resourceLimits ?? null) ? $resourceLimits : [];
  $limitsCanManage = (bool) ($limitsCtx['canManage'] ?? false);
  $limitsError = ($limitsCtx['error'] ?? null) !== null ? (string) $limitsCtx['error'] : null;
  $limitsSaved = is_array($limitsCtx['saved'] ?? null) ? $limitsCtx['saved'] : [];
  $limitsRepo = is_array($limitsCtx['repo'] ?? null) ? $limitsCtx['repo'] : [];
  $limitsServices = [];
  foreach ((array) ($limitsCtx['services'] ?? []) as $limitsService) {
      $limitsService = trim((string) $limitsService);
      if ($limitsService !== '' && !in_array($limitsService, $limitsServices, true)) {
          $limitsServices[] = $limitsService;
      }
  }
  $limitsMinMemory = \app\library\Deploy\ResourceLimits::MIN_MEMORY_MB;
  // Estimasi kredit baca-saja dari limit app (dihitung `Pricing` di server;
  // lihat AppController::billingEstimateFor()). `applies` mengikuti **pemilik
  // app** (penanggung biaya), bukan penonton: app milik admin ⇒ bebas kredit
  // (tanpa angka estimasi & tanpa plafon); app milik member ⇒ ditagih.
  $billingEstimate = is_array($billingEstimate ?? null) ? $billingEstimate : [];
  $billingEnabled = (bool) ($billingEstimate['enabled'] ?? false);
  $billingApplies = !empty($billingEstimate['applies']);
  ?>
  <div class="tab-pane fade" id="tab-limits" role="tabpanel" aria-labelledby="tab-limits-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Batas Sumber Daya</h2>
        <span class="text-muted small">maksimum CPU &amp; memori per service</span>
      </div>
      <div class="card-body">
        <?php if ($billingEnabled): ?>
          <?php if ($billingApplies): ?>
          <div class="alert alert-secondary py-2 small" role="alert">
            Estimasi biaya kredit: <strong class="mono"><?= e((string) ($billingEstimate['hourly_text'] ?? '0.00')) ?></strong> per jam
            &middot; <strong class="mono"><?= e((string) ($billingEstimate['month_text'] ?? '0.00')) ?></strong>
            untuk <?= (int) ($billingEstimate['days'] ?? 30) ?> hari.
            Batas CPU/memori adalah <strong>dasar penagihan kredit</strong> — mengubahnya mengubah biaya app.
          </div>
          <?php else: ?>
          <div class="alert alert-secondary py-2 small" role="alert">
            <strong>Bebas kredit</strong> — app milik admin tidak diakru/ditagih dan tidak dibatasi plafon CPU/RAM.
          </div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($limitsError !== null): ?>
          <div class="alert alert-warning py-2 small" role="alert"><?= e($limitsError) ?></div>
        <?php endif; ?>

        <?php if ($limitsServices === []): ?>
          <p class="text-muted small mb-0">Daftar service tidak tersedia (base compose belum terbaca) — batas resource belum bisa ditampilkan.</p>
        <?php elseif ($limitsCanManage): ?>
        <form method="post" action="/apps/<?= e($app['id']) ?>/limits" id="limits-form"<?= $isBusy ? ' onsubmit="return false;"' : '' ?>>
          <?= csrf_field() ?>
          <div class="table-responsive">
            <table class="table align-middle mb-2" id="limits-table">
              <thead>
                <tr>
                  <th style="width:30%;">Service</th>
                  <th>CPU (core)</th>
                  <th>Memori (MB)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($limitsServices as $limitsService): ?>
                <?php
                  $limitSaved = is_array($limitsSaved[$limitsService] ?? null) ? $limitsSaved[$limitsService] : [];
                  $limitRepo = is_array($limitsRepo[$limitsService] ?? null) ? $limitsRepo[$limitsService] : [];
                  $cpuSaved = $limitSaved['cpus'] ?? null;
                  $memSaved = $limitSaved['memory_mb'] ?? null;
                  $cpuRepo = $limitRepo['cpus'] ?? null;
                  $memRepo = $limitRepo['memory_mb'] ?? null;
                  $cpuValue = $cpuSaved !== null ? $cpuSaved : $cpuRepo;
                  $memValue = $memSaved !== null ? $memSaved : $memRepo;
                  $cpuFromRepo = $cpuSaved === null && $cpuRepo !== null;
                  $memFromRepo = $memSaved === null && $memRepo !== null;
                  $limitsId = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $limitsService);
                ?>
                <tr>
                  <td><span class="mono"><?= e($limitsService) ?></span></td>
                  <td>
                    <input type="number" step="0.1" min="0" class="form-control form-control-sm" style="max-width:160px;"
                           id="limit-cpus-<?= e($limitsId) ?>"
                           name="limits[<?= e($limitsService) ?>][cpus]"
                           value="<?= $cpuValue !== null ? e((string) $cpuValue) : '' ?>"
                           placeholder="<?= $cpuValue !== null ? e((string) $cpuValue) : 'tanpa batas' ?>">
                    <?php if ($cpuFromRepo): ?><div class="form-text">nilai dari compose repo</div><?php endif; ?>
                  </td>
                  <td>
                    <input type="number" step="1" min="<?= e((string) $limitsMinMemory) ?>" class="form-control form-control-sm" style="max-width:160px;"
                           id="limit-memory-<?= e($limitsId) ?>"
                           name="limits[<?= e($limitsService) ?>][memory_mb]"
                           value="<?= $memValue !== null ? e((string) $memValue) : '' ?>"
                           placeholder="<?= $memValue !== null ? e((string) $memValue) : 'tanpa batas' ?>">
                    <?php if ($memFromRepo): ?><div class="form-text">nilai dari compose repo</div><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="text-muted small mb-3">
            Kosongkan = ikut pengaturan compose repo / tanpa batas. Nilai dari compose repo tampil sebagai prefill
            (bisa diedit). Mengubah batas akan menciptakan ulang container app (named volume tetap).
          </p>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm"<?= $isBusy || $limitsError !== null ? ' disabled' : '' ?>>Simpan &amp; Terapkan</button>
            <?php if ($isBusy): ?>
              <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
            <?php endif; ?>
          </div>
        </form>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-2">
            <thead>
              <tr>
                <th style="width:30%;">Service</th>
                <th>CPU (core)</th>
                <th>Memori (MB)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($limitsServices as $limitsService): ?>
              <?php
                $limitSaved = is_array($limitsSaved[$limitsService] ?? null) ? $limitsSaved[$limitsService] : [];
                $limitRepo = is_array($limitsRepo[$limitsService] ?? null) ? $limitsRepo[$limitsService] : [];
                $cpuSaved = $limitSaved['cpus'] ?? null;
                $memSaved = $limitSaved['memory_mb'] ?? null;
                $cpuRepo = $limitRepo['cpus'] ?? null;
                $memRepo = $limitRepo['memory_mb'] ?? null;
                $cpuValue = $cpuSaved !== null ? $cpuSaved : $cpuRepo;
                $memValue = $memSaved !== null ? $memSaved : $memRepo;
                $cpuFromRepo = $cpuSaved === null && $cpuRepo !== null;
                $memFromRepo = $memSaved === null && $memRepo !== null;
              ?>
              <tr>
                <td><span class="mono"><?= e($limitsService) ?></span></td>
                <td>
                  <?php if ($cpuValue === null): ?>
                    <span class="text-muted">&mdash;</span>
                  <?php else: ?>
                    <span class="mono"><?= e((string) $cpuValue) ?></span>
                    <?php if ($cpuFromRepo): ?><span class="text-muted small">(dari compose repo)</span><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($memValue === null): ?>
                    <span class="text-muted">&mdash;</span>
                  <?php else: ?>
                    <span class="mono"><?= e((string) $memValue) ?></span> <span class="text-muted small">MB</span>
                    <?php if ($memFromRepo): ?><span class="text-muted small">(dari compose repo)</span><?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0">Hanya admin yang dapat mengubah batas resource.</p>
        <?php endif; ?>
      </div>
    </section>
  </div>
