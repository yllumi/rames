<?php $pageTitle = 'Impor dari Docker';
$active = 'apps';

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Impor dari Docker', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
// Halaman impor project `docker compose` yang sudah berjalan di host ini.
// Seluruh data berasal dari controller (`ComposeDiscovery`); view hanya
// merender — tidak pernah memanggil Docker Engine sendiri.
$__projects = is_array($projects ?? null) ? $projects : [];
$__primaryChoices = is_array($primaryChoices ?? null) ? $primaryChoices : [];
$__engineError = trim((string) ($engineError ?? ''));
// Kelas badge status project (running/partial/stopped). `partial` = sebagian
// container hidup → pakai warna informatif (biru), bukan hijau.
$__stateClasses = [
  'running' => 'badge-running',
  'partial' => 'badge-deploying',
  'stopped' => 'badge-stopped',
];
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Impor project compose</h1>
    <p class="text-muted mb-0">Project <code>docker compose</code> yang sudah berjalan di host ini dan belum terdaftar di Rames.</p>
  </div>
</div>

<div class="alert alert-info small" role="alert">
  <strong>App impor tidak di-deploy atau di-rebuild oleh Rames.</strong>
  Berkas compose dan environment tetap dikelola dari terminal di direktori proyek
  (Rames tidak menjalankan <code>docker compose</code> untuk app ini). Rames menyediakan
  detail status, log, terminal, file manager, database, domain/SSL, serta stop/start.
  Container yang dibuat dengan <code>docker run</code> tanpa label compose tidak terdeteksi.
</div>

<?php if ($__engineError !== ''): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2 flex-wrap" role="alert">
    <div class="flex-grow-1"><strong>Docker Engine tidak dapat dibaca:</strong> <?= e($__engineError) ?></div>
    <a class="btn btn-outline-danger btn-sm" href="/apps/import">Coba lagi</a>
  </div>
<?php elseif ($__projects === []): ?>
  <div class="card empty">
    <div class="empty-icon">◈</div>
    <p class="text-muted mb-0">Tidak ada project <code>docker compose</code> yang terdeteksi di host ini. Jalankan <code>docker compose up -d</code> di direktori proyek, lalu muat ulang halaman ini.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Project</th>
            <th>Container</th>
            <th>Service</th>
            <th>Port</th>
            <th>Direktori</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($__projects as $__project): ?>
            <?php
            $__name = (string) ($__project['project'] ?? '');
            $__nameValid = (bool) ($__project['name_valid'] ?? false);
            $__conflict = (bool) ($__project['conflict'] ?? false);
            $__containers = (int) ($__project['containers'] ?? 0);
            $__running = (int) ($__project['running'] ?? 0);
            $__state = (string) ($__project['state'] ?? 'stopped');
            $__services = is_array($__project['services'] ?? null) ? $__project['services'] : [];
            $__ports = is_array($__project['ports'] ?? null) ? $__project['ports'] : [];
            $__workingDir = trim((string) ($__project['working_dir'] ?? ''));
            $__importable = (bool) ($__project['importable'] ?? false);
            $__choices = is_array($__primaryChoices[$__name] ?? null) ? $__primaryChoices[$__name] : [];

            // Sebab tombol Impor dinonaktifkan (dijelaskan ke user, bukan disembunyikan).
            $__reasons = [];
            if ($__conflict) {
                $__reasons[] = 'nama sudah dipakai app';
            }
            if (!$__nameValid) {
                $__reasons[] = 'nama tidak valid';
            }
            if ($__containers === 0) {
                $__reasons[] = 'tidak ada container';
            }
            ?>
            <tr>
              <td>
                <span class="mono fw-semibold"><?= e($__name) ?></span>
                <span class="badge <?= e($__stateClasses[$__state] ?? 'badge-stopped') ?> ms-1"><?= e($__state) ?></span>
              </td>
              <td class="mono text-muted" title="container berjalan / total container">
                <?= $__running ?>/<?= $__containers ?>
              </td>
              <td class="small">
                <?php if ($__services === []): ?>
                  <span class="text-muted">&mdash;</span>
                <?php else: ?>
                  <?= e(implode(', ', array_map('strval', $__services))) ?>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($__ports === []): ?>
                  <span class="text-muted">&mdash;</span>
                <?php else: ?>
                  <?php foreach ($__ports as $__port): ?>
                    <?php if (!is_array($__port)) {
                        continue;
                    } ?>
                    <div class="text-nowrap">
                      <span class="mono"><?= (int) ($__port['container'] ?? 0) ?></span>
                      <span class="text-muted">&rarr;</span> host <span class="mono"><?= (int) ($__port['host'] ?? 0) ?></span>
                      <?php if ((string) ($__port['service'] ?? '') !== ''): ?><span class="text-muted small">(<?= e((string) $__port['service']) ?>)</span><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($__workingDir === ''): ?>
                  <span class="text-muted">tidak diketahui</span>
                <?php else: ?>
                  <span class="mono text-break"><?= e($__workingDir) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$__importable): ?>
                  <?php foreach ($__reasons as $__reason): ?>
                    <span class="badge text-bg-light border me-1"><?= e($__reason) ?></span>
                  <?php endforeach; ?>
                  <button type="button" class="btn btn-primary btn-sm" disabled>Impor</button>
                <?php else: ?>
                  <form method="post" action="/apps/import" class="d-flex flex-wrap align-items-center gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="project" value="<?= e($__name) ?>">
                    <?php if ($__choices !== []): ?>
                      <select class="form-select form-select-sm" name="primary" aria-label="Port utama (vhost)">
                        <?php foreach ($__choices as $__choice): ?>
                          <option value="<?= e((string) ($__choice['key'] ?? '')) ?>">port <?= (int) ($__choice['port'] ?? 0) ?> &rarr; host <?= (int) ($__choice['host'] ?? 0) ?> (<?= e((string) ($__choice['service'] ?? '')) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input type="hidden" name="primary" value="">
                      <span class="text-muted small">tanpa port host — tidak ada vhost/domain</span>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm">Impor</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
