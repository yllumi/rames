  <!-- ============ Tab: Container ============ -->
  <div class="tab-pane fade" id="tab-containers" role="tabpanel" aria-labelledby="tab-containers-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Containers</h2>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="text-muted small">
            <?php if ($isRemoteServer): ?>
              <span class="badge badge-deploying" title="Container berjalan di server agent, bukan di host dashboard ini">agent</span>
              <span class="mono"><?= e($appServerName) ?></span> ·
            <?php endif; ?>
            <?= count($containers) ?> container
          </span>
          <?php if ($canCompose && !$isImported): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-bs-toggle="collapse" data-bs-target="#port-edit"
                    aria-expanded="false" aria-controls="port-edit">Edit Port</button>
          <?php endif; ?>
          <?php if ($canCompose): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-bs-toggle="collapse" data-bs-target="#prefix-edit"
                    aria-expanded="false" aria-controls="prefix-edit">Edit Prefix</button>
          <?php endif; ?>
        </div>
      </div>
  <?php if ($isRemoteServer): ?>
  <div class="card-body border-bottom py-2 small text-muted">
    Log, terminal, dan tombol <strong>Run</strong> di bawah dijalankan di
    <strong>server agent <?= e($appServerName) ?></strong> (perintah satu-kali di sana
    dibatasi waktu jalur agent ±30 detik).
  </div>
  <?php endif; ?>
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
                      title="Lihat log container ini (docker logs)"><?= svg_icon('terminal') ?> Log</button>
            <?php endif; ?>
            <?php if ($cRunning && $canFiles): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm files-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Jelajahi &amp; kelola berkas di dalam container ini"><?= svg_icon('folder') ?> Files</button>
            <?php endif; ?>
            <?php if ($cRunning && $canTerminal): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm terminal-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Buka shell interaktif (docker exec -it, shell otomatis: bash bila ada; user root)<?= $isRemoteServer ? ' di server agent ' . e($appServerName) : '' ?>"><?= svg_icon('terminal') ?> Terminal</button>
              <button type="button" class="btn btn-outline-primary btn-sm run-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      data-server="<?= $isRemoteServer ? e($appServerName) : '' ?>"
                      title="Jalankan perintah satu kali (docker exec ... sh -c)<?= $isRemoteServer ? ' di server agent ' . e($appServerName) : '' ?>"><?= svg_icon('play') ?> Run</button>
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

  <?php
  // Kontrak InfoController::detail() — `$portMappings` bisa kosong/belum ada;
  // view wajib tetap render normal. `canManage` mencerminkan ability compose
  // di server; tombol tetap lapisan kedua (server sumber kebenaran).
  $portCtx = is_array($portMappings ?? null) ? $portMappings : [];
  $portRows = is_array($portCtx['rows'] ?? null) ? array_values(array_filter($portCtx['rows'], 'is_array')) : [];
  $portError = ($portCtx['error'] ?? null) !== null ? (string) $portCtx['error'] : null;
  ?>
  <!-- Port container (host port read-only → port container bisa diedit) -->
  <?php if ($canCompose && !$isImported): ?>
  <section class="card mb-4 collapse" id="port-edit">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <h2 class="h6 mb-0">Port container</h2>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-muted small">host port &rarr; port container</span>
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-toggle="collapse" data-bs-target="#port-edit"
                aria-expanded="true" aria-controls="port-edit">Tutup</button>
      </div>
    </div>
    <?php if ($portRows === []): ?>
    <div class="card-body">
      <?php if ($portError !== null): ?>
        <div class="alert alert-warning py-2 small" role="alert"><?= e($portError) ?></div>
      <?php endif; ?>
      <p class="text-muted small mb-0">App ini tidak memublikasikan port ke host (<span class="mono">ports:</span> tidak ada di compose), jadi tidak ada port container yang bisa diubah.</p>
    </div>
    <?php else: ?>
    <form method="post" action="/apps/<?= e($app['id']) ?>/ports">
      <?= csrf_field() ?>
      <div class="card-body">
        <?php if ($portError !== null): ?>
          <div class="alert alert-warning py-2 small" role="alert"><?= e($portError) ?></div>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table align-middle mb-2">
            <thead>
              <tr>
                <th style="width:30%;">Service</th>
                <th>Host port</th>
                <th>Port container</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($portRows as $portRow): ?>
              <?php
                $portService = (string) ($portRow['service'] ?? '');
                $portContainer = (int) ($portRow['container'] ?? 0);
                $portHost = $portRow['host'] ?? null;
                $portRaw = ($portRow['raw'] ?? null) !== null ? (string) $portRow['raw'] : '';
                // Baris tanpa host port tidak boleh mengirim field bernama
                // (`host === null` ⇒ tidak ada host port yang bisa dipetakan).
                $portEditable = (bool) ($portRow['editable'] ?? false) && $portHost !== null;
                $portIsProxied = $proxiedPort > 0 && $portContainer === (int) $proxiedPort;
              ?>
              <tr>
                <td><span class="mono"><?= e($portService) ?></span></td>
                <td class="small">
                  <?php if ($portHost === null): ?>
                    <span class="text-muted">&mdash;</span>
                  <?php else: ?>
                    <span class="mono"><?= e((string) $portHost) ?></span>
                    <?php if ($portIsProxied): ?>
                      <span class="badge text-bg-primary ms-1" title="Port ini menerima trafik domain app (di-proxy Nginx)">di-proxy</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($portEditable): ?>
                    <input type="number" min="1" max="65535" step="1" class="form-control form-control-sm mono" style="max-width:160px;"
                           name="ports[<?= e($portService) ?>][<?= e((string) $portHost) ?>]"
                           value="<?= $portContainer > 0 ? e((string) $portContainer) : '' ?>"
                           placeholder="port container">
                  <?php else: ?>
                    <?php if ($portContainer > 0): ?>
                      <span class="mono"><?= e((string) $portContainer) ?></span>
                    <?php elseif ($portRaw !== ''): ?>
                      <span class="mono"><?= e($portRaw) ?></span>
                    <?php else: ?>
                      <span class="text-muted">&mdash;</span>
                    <?php endif; ?>
                    <div class="form-text">diatur compose — tidak bisa diubah di sini</div>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0">
          Ubah port container bila aplikasi di dalam container listen di port berbeda dari compose.
          <strong>Host port tetap</strong> (terikat reverse proxy Nginx) dan tidak ikut berubah.
          Menyimpan akan <strong>menciptakan ulang</strong> container app (tanpa build); pastikan aplikasi
          benar-benar listen di port baru.
        </p>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
        <?php endif; ?>
        <?php if ($isRemoteServer): ?>
          <span class="text-muted small">Perubahan diterapkan di server agent <?= e($appServerName) ?>.</span>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Nama container (override container_name) -->
  <?php if ($canCompose): ?>
  <section class="card mb-4 collapse" id="prefix-edit">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <h2 class="h6 mb-0">Nama container</h2>
      <button type="button" class="btn btn-outline-secondary btn-sm"
              data-bs-toggle="collapse" data-bs-target="#prefix-edit"
              aria-expanded="true" aria-controls="prefix-edit">Tutup</button>
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
