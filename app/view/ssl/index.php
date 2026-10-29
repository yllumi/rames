<?php $pageTitle = 'SSL'; $active = 'ssl'; ?>
<?php
$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'SSL', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$badges = [
    'active' => 'badge-running',
    'pending' => 'badge-deploying',
    'failed' => 'badge-error',
    'expired' => 'badge-error',
    'disabled' => 'badge-stopped',
];
// Ada baris `pending` sejak render? Dipakai untuk atribut `data-ssl-autostart`
// (JS langsung memulai polling tanpa menunggu aksi user).
$anyPending = false;
foreach ($rows as $row) {
    if ((string) ($row['ssl_status'] ?? 'disabled') === 'pending') {
        $anyPending = true;
        break;
    }
}
?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">SSL / Let's Encrypt</h1>
    <p class="text-muted mb-0">Sertifikat TLS otomatis per domain (subdomain atau custom domain app).</p>
  </div>
</div>

<?php if (!$sslSupported): ?>
  <div class="alert alert-warning" role="alert">
    <strong>SSL dinonaktifkan.</strong> APP_DOMAIN (<span class="mono"><?= e(config('deploy.app_domain')) ?></span>) bukan domain publik,
    sehingga Let's Encrypt tidak bisa menerbitkan sertifikat. Set <code>APP_DOMAIN</code> ke domain publik (mis. <code>example.com</code>)
    untuk mengaktifkan fitur ini.
  </div>
<?php endif; ?>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="card-body text-muted small">Belum ada app/domain.</div>
  <?php else: ?>
  <div class="table-responsive">
  <?php /* Peta status → kelas badge dibaca JS dari atribut ini (satu sumber, tanpa duplikasi di JS). */ ?>
  <table class="table table-hover align-middle mb-0" data-ssl-badge-map="<?= e(json_encode($badges)) ?>" data-ssl-supported="<?= $sslSupported ? '1' : '0' ?>"<?= $anyPending ? ' data-ssl-autostart="1"' : '' ?>>
    <thead>
      <tr>
        <th>Domain</th>
        <th>App</th>
        <?php if ($isAdmin ?? false): ?><th>Owner</th><?php endif; ?>
        <th>Status SSL</th>
        <th>Kedaluwarsa</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $st = (string) ($r['ssl_status'] ?? 'disabled');
          $appId = (string) ($r['app']['id'] ?? '');
          $canSsl = app_can('ssl', $r['app']);
          // State awal server-rendered harus sama dengan state yang dihitung JS
          // dari `/api/ssl/status`, dan **sama persis** dengan tampilan sebelum
          // mode AJAX (catatan status menang atas form/penanda).
          $message = (string) ($r['ssl_message'] ?? '');
          $error = (string) ($r['ssl_error'] ?? '');
          $expires = (string) ($r['ssl_expires_at'] ?? '');
          // Posisi antrean SSL (opsional, hanya saat `pending`). Field BEKU dari
          // controller: `ssl_queue_position` (1-based) & `ssl_queue_size`
          // (queued+running). Tanpa keduanya, catatan tetap `proses ...`.
          $queuePosition = (int) ($r['ssl_queue_position'] ?? 0);
          $queueSize = (int) ($r['ssl_queue_size'] ?? 0);
          $pendingNote = $st === 'pending' && $queuePosition > 0 && $queueSize > 0
              ? 'antrean #' . $queuePosition . ' dari ' . $queueSize
              : 'proses ...';
          $noteState = $st === 'pending' || $st === 'active';
          $formVisible = $canSsl && $sslSupported && !$noteState;
          $dashVisible = $canSsl && !$sslSupported && !$noteState;
          $nohakVisible = !$canSsl && !$noteState;
          // Galat hanya dirender untuk baris yang boleh menekan Aktifkan/Retry
          // (ability `ssl`) dan tanpa `ssl_error` tak pernah masuk DOM.
          $errorVisible = $canSsl && $sslSupported && $st === 'failed' && $error !== '';
          $ownerName = (string) (($ownerNames ?? [])[(string) ($r['app']['owner_id'] ?? '')] ?? '');
        ?>
        <tr data-ssl-row data-app-id="<?= e($appId) ?>" data-domain="<?= e($r['domain']) ?>" data-ssl-status="<?= e($st) ?>" data-ssl-can="<?= $canSsl ? '1' : '0' ?>">
          <td>
            <span class="mono"><?= e($r['domain']) ?></span>
            <?php if (!empty($r['is_custom'])): ?><span class="text-muted small">(custom)</span><?php endif; ?>
          </td>
          <td>
            <a href="/apps/<?= e($r['app']['id']) ?>"><?= e($r['app']['name']) ?></a>
            <span class="text-muted small">(<?= e($r['app']['status'] ?? 'unknown') ?>)</span>
          </td>
          <?php if ($isAdmin ?? false): ?>
          <td class="small mono"><?= $ownerName !== '' ? e($ownerName) : '<span class="text-muted">-</span>' ?></td>
          <?php endif; ?>
          <td>
            <span class="badge <?= e($badges[$st] ?? 'badge-stopped') ?>" data-ssl-badge><?= e($st) ?></span>
            <div class="text-muted small<?= $message !== '' ? '' : ' d-none' ?>" data-ssl-message><?= e($message) ?></div>
          </td>
          <td class="small"><span data-ssl-expires<?= $expires === '' ? ' class="text-muted"' : '' ?>><?= $expires === '' ? '-' : e($expires) ?></span></td>
          <td>
            <?php /* Catatan & penanda non-form — default tersembunyi, JS yang menampilkan. */ ?>
            <span class="text-muted small<?= $nohakVisible ? '' : ' d-none' ?>" data-ssl-nohak="ability">tanpa hak</span>
            <span class="text-muted small<?= $dashVisible ? '' : ' d-none' ?>" data-ssl-nohak="unsupported">&mdash;</span>
            <span class="text-muted small<?= $st === 'pending' ? '' : ' d-none' ?>" data-ssl-note="pending"><?= e($pendingNote) ?></span>
            <span class="text-muted small<?= $st === 'active' ? '' : ' d-none' ?>" data-ssl-note="active">aktif</span>
            <?php if ($canSsl): ?>
              <?php /* Fallback tanpa JS: form POST biasa. JS mencegat `submit` dan memakai `data-ssl-url`. */ ?>
              <form method="post" action="/ssl/<?= e($appId) ?>/enable" class="d-inline<?= $formVisible ? '' : ' d-none' ?>" data-ssl-form data-ssl-url="/api/ssl/<?= e($appId) ?>/enable">
                <?= csrf_field() ?>
                <button class="btn btn-<?= $st === 'failed' ? 'outline-danger' : 'primary' ?> btn-sm" data-ssl-submit><?= $st === 'failed' ? '↻ Retry SSL' : 'Aktifkan SSL' ?></button>
              </form>
            <?php endif; ?>
            <?php if ($canSsl && $sslSupported): ?>
              <div class="text-danger small mt-1<?= $errorVisible ? '' : ' d-none' ?>" data-ssl-error><?= e($error) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php /* Status baris disegarkan lewat polling JSON `/api/ssl/status` (public/js/ssl.js). */ ?>
<script src="/js/ssl.js?v=3"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>
