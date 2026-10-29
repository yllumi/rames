<?php
$pageTitle = 'Detail Error';
$active = 'errors';

/**
 * Halaman `/errors/{id}` (admin-only) — metadata satu grup error + sampel event.
 *
 * Kontrak data dari `ErrorController::show()` (BEKU):
 *   $group  — {id,fingerprint,kind,status,message,location,route,http_status,
 *              app_id,app_name,server_id,server_name,user_id,username,
 *              first_seen,last_seen,count,samples}
 *   $events — list {id,occurred_at,kind,message,stack,context(array),user_agent}
 *
 * Aksi mutasi memakai form POST biasa: `/errors/{id}/status` (status) dan
 * `/errors/{id}/delete` (hapus). Semua nilai dirender dengan `e()`; stack dan
 * context hanya ditampilkan sebagai teks (tidak pernah disisipkan sebagai HTML).
 */
$__text = static function (mixed $value): string {
    if ($value === null || $value === false) {
        return '';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }

    return '';
};

$__date = static function (mixed $value) use ($__text): string {
    $raw = trim($__text($value));
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d H:i:s', $ts);
};

$__json = static function (mixed $value): string {
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $encoded === false ? '' : $encoded;
};

$group = is_array($group ?? null) ? $group : [];
$events = is_array($events ?? null) ? array_values(array_filter($events, 'is_array')) : [];

$groupId = $__text($group['id'] ?? '');
$kind = strtolower($__text($group['kind'] ?? 'client'));
if (!in_array($kind, ['client', 'server'], true)) {
    $kind = 'client';
}
$status = strtolower($__text($group['status'] ?? 'open'));
if (!in_array($status, ['open', 'resolved', 'ignored'], true)) {
    $status = 'open';
}

$__kindClass = ['client' => 'text-bg-info', 'server' => 'text-bg-warning'];
$__statusClass = [
    'open' => 'text-bg-danger',
    'resolved' => 'text-bg-success',
    'ignored' => 'text-bg-secondary',
];

$fingerprint = $__text($group['fingerprint'] ?? '');
$message = $__text($group['message'] ?? '');
$location = $__text($group['location'] ?? '');
$route = $__text($group['route'] ?? '');
$httpStatus = $__text($group['http_status'] ?? '');
$appId = $__text($group['app_id'] ?? '');
$appName = $__text($group['app_name'] ?? '');
$serverId = $__text($group['server_id'] ?? '');
$serverName = $__text($group['server_name'] ?? '');
$userId = $__text($group['user_id'] ?? '');
$username = $__text($group['username'] ?? '');
$count = is_numeric($group['count'] ?? null) ? max(0, (int) $group['count']) : 0;
$samples = is_numeric($group['samples'] ?? null) ? max(0, (int) $group['samples']) : count($events);

$appLabel = $appName !== '' ? $appName : $appId;
$serverLabel = $serverName !== '' ? $serverName : $serverId;
$userLabel = $username !== '' ? $username : $userId;
$statusUrl = '/errors/' . rawurlencode($groupId) . '/status';
$deleteUrl = '/errors/' . rawurlencode($groupId) . '/delete';

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Error Log', 'href' => '/errors'],
    ['label' => 'Detail', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <a class="small text-decoration-none" href="/errors">← Kembali ke Error Log</a>
    <h1 class="h3 mb-2 mt-1">Detail Error</h1>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="badge <?= e($__kindClass[$kind]) ?>"><?= e($kind) ?></span>
      <span class="badge <?= e($__statusClass[$status]) ?>"><?= e($status) ?></span>
      <span class="text-muted small"><?= e((string) $count) ?> kejadian · <?= e((string) $samples) ?> sampel</span>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-8">
    <section class="card mb-3">
      <div class="card-header">
        <h2 class="h6 mb-0">Pesan</h2>
      </div>
      <div class="card-body">
        <p class="mb-0" style="white-space: pre-wrap; word-break: break-word;"><?= e($message !== '' ? $message : '—') ?></p>
        <?php if ($location !== ''): ?>
          <p class="mono small text-muted mb-0 mt-2 text-break"><?= e($location) ?></p>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">Sampel event</h2>
        <span class="text-muted small"><?= e((string) count($events)) ?> event terbaru</span>
      </div>
      <div class="card-body">
        <?php if ($events === []): ?>
          <p class="text-muted mb-0">Belum ada sampel event tersimpan untuk grup ini.</p>
        <?php else: ?>
          <div class="accordion" id="error-events">
            <?php foreach ($events as $__i => $__event): ?>
              <?php
                $__eventId = $__text($__event['id'] ?? '');
                $__eventUid = 'err-event-' . $__i;
                $__eventStack = $__text($__event['stack'] ?? '');
                $__eventContext = $__event['context'] ?? [];
                $__eventContextJson = $__json($__eventContext);
                $__eventKind = strtolower($__text($__event['kind'] ?? $kind));
                if (!in_array($__eventKind, ['client', 'server'], true)) {
                    $__eventKind = $kind;
                }
                $__eventMessage = $__text($__event['message'] ?? '');
                $__userAgent = $__text($__event['user_agent'] ?? '');
                $__expanded = $__i === 0;
              ?>
              <div class="accordion-item">
                <h3 class="accordion-header" id="<?= e($__eventUid) ?>-head">
                  <button class="accordion-button<?= $__expanded ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= e($__eventUid) ?>" aria-expanded="<?= $__expanded ? 'true' : 'false' ?>" aria-controls="<?= e($__eventUid) ?>">
                    <span class="badge <?= e($__kindClass[$__eventKind]) ?> me-2"><?= e($__eventKind) ?></span>
                    <span class="text-truncate me-2" style="max-width: 420px"><?= e($__eventMessage !== '' ? $__eventMessage : 'Event #' . $__i) ?></span>
                    <span class="text-muted small ms-auto text-nowrap"><?= e($__date($__event['occurred_at'] ?? '')) ?></span>
                  </button>
                </h3>
                <div id="<?= e($__eventUid) ?>" class="accordion-collapse collapse<?= $__expanded ? ' show' : '' ?>" aria-labelledby="<?= e($__eventUid) ?>-head" data-bs-parent="#error-events">
                  <div class="accordion-body">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                      <span class="mono small text-muted">ID: <?= e($__eventId !== '' ? $__eventId : '—') ?></span>
                      <span class="small text-muted">· <?= e($__date($__event['occurred_at'] ?? '')) ?></span>
                    </div>
                    <?php if ($__eventMessage !== ''): ?>
                      <p class="small mb-3" style="white-space: pre-wrap; word-break: break-word;"><?= e($__eventMessage) ?></p>
                    <?php endif; ?>

                    <?php if ($__userAgent !== ''): ?>
                      <p class="small text-muted mb-3 text-break"><strong>User-agent:</strong> <?= e($__userAgent) ?></p>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                      <strong class="small">Stack</strong>
                      <?php if ($__eventStack !== ''): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-target="<?= e($__eventUid) ?>-stack">Salin stack</button>
                      <?php endif; ?>
                    </div>
                    <?php if ($__eventStack !== ''): ?>
                      <pre id="<?= e($__eventUid) ?>-stack" class="mono small mb-3 p-2 bg-body-tertiary border rounded" style="max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-word;"><?= e($__eventStack) ?></pre>
                    <?php else: ?>
                      <p class="small text-muted">Tidak ada stack.</p>
                    <?php endif; ?>

                    <strong class="small d-block mb-1">Context</strong>
                    <?php if ($__eventContextJson !== ''): ?>
                      <pre class="mono small mb-0 p-2 bg-body-tertiary border rounded" style="max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-word;"><?= e($__eventContextJson) ?></pre>
                    <?php else: ?>
                      <p class="small text-muted mb-0">Tidak ada context.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="col-12 col-xl-4">
    <section class="card mb-3">
      <div class="card-header">
        <h2 class="h6 mb-0">Metadata</h2>
      </div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-5 text-muted">Fingerprint</dt>
          <dd class="col-7 mono text-break mb-2"><?= e($fingerprint !== '' ? $fingerprint : '—') ?></dd>

          <dt class="col-5 text-muted">Status</dt>
          <dd class="col-7 mb-2"><span class="badge <?= e($__statusClass[$status]) ?>"><?= e($status) ?></span></dd>

          <dt class="col-5 text-muted">Pertama terlihat</dt>
          <dd class="col-7 mb-2"><?= e($__date($group['first_seen'] ?? '')) ?></dd>

          <dt class="col-5 text-muted">Terakhir terlihat</dt>
          <dd class="col-7 mb-2"><?= e($__date($group['last_seen'] ?? '')) ?></dd>

          <dt class="col-5 text-muted">Kejadian</dt>
          <dd class="col-7 mb-2"><?= e((string) $count) ?> (<?= e((string) $samples) ?> sampel)</dd>

          <dt class="col-5 text-muted">Route</dt>
          <dd class="col-7 mono text-break mb-2"><?= e($route !== '' ? $route : '—') ?></dd>

          <dt class="col-5 text-muted">App</dt>
          <dd class="col-7 text-break mb-2"><?= e($appLabel !== '' ? $appLabel : '—') ?></dd>

          <dt class="col-5 text-muted">Server</dt>
          <dd class="col-7 text-break mb-2"><?= e($serverLabel !== '' ? $serverLabel : '—') ?></dd>

          <dt class="col-5 text-muted">User</dt>
          <dd class="col-7 text-break mb-2"><?= e($userLabel !== '' ? $userLabel : '—') ?></dd>

          <dt class="col-5 text-muted">HTTP status</dt>
          <dd class="col-7 mb-2"><?= e($httpStatus !== '' ? $httpStatus : '—') ?></dd>

          <dt class="col-5 text-muted">Lokasi</dt>
          <dd class="col-7 mono text-break mb-0"><?= e($location !== '' ? $location : '—') ?></dd>
        </dl>
      </div>
    </section>

    <section class="card mb-3">
      <div class="card-header">
        <h2 class="h6 mb-0">Ubah status</h2>
      </div>
      <div class="card-body">
        <form method="post" action="<?= e($statusUrl) ?>">
          <?= csrf_field() ?>
          <label class="form-label small" for="error-status">Status grup</label>
          <select class="form-select form-select-sm mb-2" id="error-status" name="status">
            <option value="open"<?= $status === 'open' ? ' selected' : '' ?>>open</option>
            <option value="resolved"<?= $status === 'resolved' ? ' selected' : '' ?>>resolved</option>
            <option value="ignored"<?= $status === 'ignored' ? ' selected' : '' ?>>ignored</option>
          </select>
          <button type="submit" class="btn btn-primary btn-sm w-100">Simpan status</button>
        </form>
      </div>
    </section>

    <section class="card border-danger-subtle">
      <div class="card-header">
        <h2 class="h6 mb-0 text-danger">Hapus</h2>
      </div>
      <div class="card-body">
        <p class="small text-muted">Menghapus grup ini juga menghapus semua sampel event-nya. Tindakan ini tidak bisa dibatalkan.</p>
        <form method="post" action="<?= e($deleteUrl) ?>" onsubmit="return confirm('Hapus grup error ini beserta semua sampelnya? Tindakan ini tidak bisa dibatalkan.');">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">Hapus grup error</button>
        </form>
      </div>
    </section>
  </div>
</div>

<script>
  // Salin stack: clipboard API dengan fallback seleksi teks. Tidak mengirim
  // data ke mana pun dan tidak menyentuh form/input.
  (function () {
    'use strict';

    function fallbackCopy(pre) {
      var selection = window.getSelection ? window.getSelection() : null;
      if (!selection) return false;
      try {
        var range = document.createRange();
        range.selectNodeContents(pre);
        selection.removeAllRanges();
        selection.addRange(range);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        selection.removeAllRanges();
        return ok;
      } catch (e) {
        return false;
      }
    }

    function flash(button, label) {
      var original = button.getAttribute('data-label-original') || button.textContent;
      button.setAttribute('data-label-original', original);
      button.textContent = label;
      window.setTimeout(function () { button.textContent = original; }, 1500);
    }

    var buttons = document.querySelectorAll('[data-copy-target]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function () {
        var target = document.getElementById(this.getAttribute('data-copy-target'));
        if (!target) return;
        var text = target.textContent || '';
        var button = this;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () {
            flash(button, 'Tersalin');
          }).catch(function () {
            flash(button, fallbackCopy(target) ? 'Tersalin' : 'Gagal menyalin');
          });
          return;
        }
        flash(button, fallbackCopy(target) ? 'Tersalin' : 'Gagal menyalin');
      });
    }
  })();
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
