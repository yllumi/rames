  <!-- ============ Tab: Container ============ -->
  <div class="tab-pane fade" id="tab-containers" role="tabpanel" aria-labelledby="tab-containers-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Containers</h2>
    <span class="text-muted small"><?= count($containers) ?> container</span>
  </div>
  <?php if (empty($containers)): ?>
    <div class="card-body text-muted small">Belum ada data container.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Service</th><th>Container</th><th>Image</th><th>Port</th><th>Status</th><th class="text-end">Aksi</th></tr>
    </thead>
    <tbody>
      <?php foreach ($containers as $c): ?>
      <?php $cRunning = ($c['status'] ?? '') === 'running'; ?>
      <tr>
        <td><strong><?= e($c['service_name'] ?? '-') ?></strong><?= ($app['primary_service'] ?? '') === ($c['service_name'] ?? '') ? ' <span class="text-muted small">(primary)</span>' : '' ?></td>
        <td><?= e($c['container_name'] ?? '-') ?></td>
        <td class="small"><?= e($c['image'] ?? '-') ?></td>
        <td class="small">
          <?php $cPorts = \app\library\Docker\AppPorts::forContainer($c); ?>
          <?php if ($cPorts === []): ?>
            <span class="text-muted">-</span>
          <?php else: ?>
            <?php foreach ($cPorts as $p): ?>
              <div class="text-nowrap">
                <span class="mono"><?= e((string) ($p['host'] ?? '')) ?></span><span class="text-muted">:<?= e((string) ($p['container'] ?? '?')) ?></span>
                <?php if ($proxiedPort > 0 && (int) ($p['container'] ?? 0) === $proxiedPort): ?>
                  <span class="badge text-bg-primary ms-1" title="Port ini menerima trafik domain app (di-proxy Nginx)">di-proxy</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-<?= e($c['status'] ?? 'unknown') ?>"><?= e($c['status'] ?? 'unknown') ?></span></td>
        <td class="text-end text-nowrap">
          <?php
          $cName = (string) ($c['container_name'] ?? '');
          $hasContainerAction = $canLogs || ($cRunning && ($canTerminal || $canFiles));
          ?>
          <?php if (!$hasContainerAction): ?>
            <span class="text-muted small">-</span>
          <?php else: ?>
            <?php if ($canLogs): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm log-btn"
                      data-container="<?= e($cName) ?>" data-bs-toggle="modal" data-bs-target="#log-modal"
                      title="Lihat log container ini (docker logs)">⧉ Log</button>
            <?php endif; ?>
            <?php if ($cRunning && $canFiles): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm files-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Jelajahi &amp; kelola berkas di dalam container ini">📁 Files</button>
            <?php endif; ?>
            <?php if ($cRunning && $canTerminal): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm terminal-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Buka shell interaktif (docker exec -it, shell otomatis: bash bila ada; user root)">⌁ Terminal</button>
              <button type="button" class="btn btn-outline-primary btn-sm run-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Jalankan perintah satu kali (docker exec ... sh -c)">> Run</button>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  </section>

  <!-- Nama container (override container_name) -->
  <?php if ($canCompose): ?>
  <section class="card mb-4">
    <div class="card-header">
      <h2 class="h6 mb-0">Nama container</h2>
    </div>
    <form method="post" action="/apps/<?= e($app['id']) ?>/container-names">
      <?= csrf_field() ?>
      <div class="card-body">
        <label class="form-label" for="container-prefix">Prefix nama container <span class="text-muted small">(opsional)</span></label>
        <input type="text" class="form-control form-control-sm mono" id="container-prefix" name="container_prefix"
               value="<?= e((string) ($app['container_prefix'] ?? '')) ?>"
               maxlength="<?= e((string) \app\library\Deploy\ContainerNames::MAX_PREFIX_LENGTH) ?>"
               placeholder="kosong = <?= e($app['name'] . '_<service>_1') ?>" style="max-width:320px;">
        <div class="form-text">
          Setiap service memakai nama container <span class="mono">{prefix}-{service}</span>. Kosongkan untuk kembali
          ke nama default compose (<span class="mono">&lt;app&gt;_&lt;service&gt;_1</span>). Nama <strong>unik se-host</strong> —
          dicek ke container lain sebelum disimpan. Menyimpan akan <strong>menciptakan ulang</strong> container
          (isi filesystem container hilang, named volume tetap).
        </div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
        <?php endif; ?>
      </div>
    </form>
  </section>
  <?php endif; ?>
  </div>
