  <?php if (!empty($dbContainers)): ?>
  <!-- ============ Tab: Database ============ -->
  <div class="tab-pane fade" id="tab-db" role="tabpanel" aria-labelledby="tab-db-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Database (MySQL/MariaDB)</h2>
        <a class="btn btn-outline-secondary btn-sm" href="/database">Semua database &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr><th>Container</th><th>Image</th><th>Status</th><th class="text-end">Aksi</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dbContainers as $dc): ?>
              <tr>
                <td><span class="mono"><?= e($dc['container_name']) ?></span></td>
                <td class="small"><?= e($dc['image']) ?></td>
                <td><span class="badge badge-<?= e($dc['state'] ?? 'unknown') ?>"><?= e($dc['state'] ?? 'unknown') ?></span></td>
                <td class="text-end">
                  <?php if ($canDb): ?>
                    <a class="btn btn-outline-primary btn-sm" href="/database/<?= e(rawurlencode($dc['container_name'])) ?>">Kelola DB &rarr;</a>
                  <?php else: ?>
                    <span class="text-muted small">tanpa hak</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
  <?php endif; ?>
