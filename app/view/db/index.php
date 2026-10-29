<?php
$pageTitle = 'Database';
$active = 'database';
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Database</h1>
    <p class="text-muted mb-0">Kelola MySQL/MariaDB di dalam container lewat <strong>Adminer</strong>.</p>
  </div>
</div>

<?php if (!($isAdmin ?? false)): ?>
  <div class="alert alert-info py-2 small" role="alert">
    Hanya container database dari <strong>app milik Anda</strong> dan <strong>app yang dibagikan</strong> kepada Anda
    yang ditampilkan di sini. Kolom <strong>Server</strong> menandai container yang berjalan di
    <strong>server agent</strong> (bukan server dashboard ini).
  </div>
<?php endif; ?>

<?php if ($engineError): ?>
  <div class="alert alert-danger" role="alert"><?= e($engineError) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Container Database</h2>
    <span class="text-muted small"><?= count($rows) ?> container</span>
  </div>
  <?php if (empty($rows)): ?>
    <div class="card-body text-muted small">
      <?php if ($isAdmin ?? false): ?>
        Tidak ada container MySQL/MariaDB yang terdeteksi.
      <?php else: ?>
        Tidak ada container database pada app milik Anda / yang dibagikan ke Anda.
      <?php endif; ?>
      Deteksi otomatis berdasarkan nama image (<span class="mono">mysql</span>,
      <span class="mono">mariadb</span>, <span class="mono">percona</span>) atau
      environment <span class="mono">MYSQL_*</span> / <span class="mono">MARIADB_*</span>.
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Container</th>
          <th>Image</th>
          <th>Status</th>
          <th>Server</th>
          <th>Kepemilikan</th>
          <?php if ($isAdmin ?? false): ?><th>Owner</th><?php endif; ?>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <?php
          // Asal server baris ini (multi-server F3d): controller mengisi
          // `server_id`/`server_name` hanya untuk baris dari server agent; baris
          // server lokal (Engine host ini) tidak punya kedua field tersebut.
          $__srvId = trim((string) ($r['server_id'] ?? ''));
          $__srvName = trim((string) ($r['server_name'] ?? ''));
          $__isAgent = $__srvId !== '' && $__srvId !== 'local';
        ?>
        <tr>
          <td><span class="mono"><?= e($r['container_name']) ?></span></td>
          <td class="small"><?= e($r['image']) ?></td>
          <td><span class="badge badge-<?= e($r['state'] ?? 'unknown') ?>"><?= e($r['state'] ?? 'unknown') ?></span></td>
          <td class="small">
            <?php if ($__isAgent): ?>
              <span class="badge badge-deploying" title="Container berjalan di server agent, bukan di host dashboard">agent</span>
              <span class="mono d-block"><?= e($__srvName !== '' ? $__srvName : $__srvId) ?></span>
            <?php else: ?>
              <span class="text-muted" title="Container berjalan di host dashboard (server lokal)">lokal</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['owned']): ?>
              <a class="badge text-bg-primary text-decoration-none" href="/apps/<?= e($r['app_id']) ?>"><?= e($r['app_name']) ?></a>
            <?php else: ?>
              <span class="badge text-bg-secondary">eksternal</span>
            <?php endif; ?>
          </td>
          <?php if ($isAdmin ?? false): ?>
          <?php $__owner = (string) (($ownerNamesByApp ?? [])[(string) ($r['app_id'] ?? '')] ?? ''); ?>
          <td class="small mono"><?= $__owner !== '' ? e($__owner) : '<span class="text-muted">-</span>' ?></td>
          <?php endif; ?>
          <td class="text-end">
            <?php if ($r['can_manage'] ?? false): ?>
              <a class="btn btn-outline-primary btn-sm" href="/database/<?= e(rawurlencode($r['container_name'])) ?>/adminer" target="_blank" rel="noopener">Kelola &rarr;</a>
            <?php else: ?>
              <span class="text-muted small">tanpa hak</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
  Adminer disajikan dari helper internal Rames (tanpa port publik), tetap di balik login dan hak akses app.
  Untuk container di <strong>server agent</strong>, dashboard meneruskan permintaan Adminer ke helper
  <em>di server itu</em> (helper tetap tanpa port publik).
  Untuk impor/ekspor <em>dump</em> berukuran besar, pakai fitur
  <strong>Volume/backup</strong> atau tab <strong>Terminal</strong> &mdash; proxy Adminer punya batas
  waktu (&le;30&nbsp;detik) dan ukuran respons.
</p>

<?php include app_path() . '/view/partials/footer.php'; ?>
