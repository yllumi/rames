<?php
/**
 * Tab "Template" — katalog template app siap-pakai (`templates/<slug>/`,
 * SPECS.md §7.2b) + tombol **Tarik image** (`docker pull` di host, bukan deploy)
 * dengan progres & log live.
 *
 * Presentasi murni: seluruh nilai di-escape dengan `e()`. Aksi dijalankan AJAX
 * oleh `public/js/template-pull.js` (tombol `type="button"`, bukan form) dengan
 * token CSRF dari `meta[name="csrf-token"]` dikirim sebagai body `_token`.
 *
 * Kontrak data dari NginxController::index():
 *   $templates: array<int,array{slug,title,description,category,icon,docs_url,valid,error,images,apps}>
 *   $pull     : null | array{failed:string} | array{enabled,running,run,log_tail,logs}
 *   $pull['run']: {id,slug,title,stage,message,result,error,started_at,finished_at,
 *                  actor,images,results,log,pid}
 */
$templates = is_array($templates ?? null) ? $templates : [];
$pull = is_array($pull ?? null) ? $pull : null;

$pullFailed = is_string($pull['failed'] ?? null) ? trim((string) $pull['failed']) : '';
$pullRun = is_array($pull['run'] ?? null) ? $pull['run'] : [];
$pullRunning = !empty($pull['running']);
$pullLogTail = is_string($pull['log_tail'] ?? null) ? (string) $pull['log_tail'] : '';
$pullLogs = is_array($pull['logs'] ?? null) ? $pull['logs'] : [];
$pullError = is_string($pullRun['error'] ?? null) ? trim((string) $pullRun['error']) : '';

$__cleanStrings = static function (array $values): array {
    return array_values(array_filter(array_map(
        static fn ($value): string => is_string($value) ? trim($value) : '',
        $values
    ), static fn (string $value): bool => $value !== ''));
};

$pullImages = $__cleanStrings(array_values(is_array($pullRun['images'] ?? null) ? $pullRun['images'] : []));
$pullResults = array_values(array_filter(
    is_array($pullRun['results'] ?? null) ? $pullRun['results'] : [],
    static fn ($result): bool => is_array($result)
));

// Baris hasil per image: image yang sudah punya hasil dipakai apa adanya, sisanya
// tampil sebagai "Menunggu" (hasil urut sesuai `run.images`).
$__resultByImage = [];
foreach ($pullResults as $result) {
    if (is_string($result['image'] ?? null) && $result['image'] !== '') {
        $__resultByImage[(string) $result['image']] = $result;
    }
}
$pullRows = [];
foreach ($pullImages as $image) {
    $pullRows[] = ['image' => $image, 'result' => $__resultByImage[$image] ?? null];
}
foreach ($pullResults as $result) {
    $image = is_string($result['image'] ?? null) ? (string) $result['image'] : '';
    if ($image !== '' && !in_array($image, $pullImages, true)) {
        $pullRows[] = ['image' => $image, 'result' => $result];
    }
}

$pullResult = is_string($pullRun['result'] ?? null) ? (string) $pullRun['result'] : '';
$pullBadges = ['success' => ['text-bg-success', 'Berhasil'], 'error' => ['text-bg-danger', 'Gagal']];
$pullBadge = $pullBadges[$pullResult] ?? ['text-bg-info', $pullRunning ? 'Berjalan…' : 'Menunggu'];
$pullStageLabels = ['starting' => 'Menyiapkan', 'pulling' => 'Tarik image', 'finished' => 'Selesai'];
$pullStage = is_string($pullRun['stage'] ?? null) ? (string) $pullRun['stage'] : '';
$pullStageLabel = $pullStage !== '' ? ($pullStageLabels[$pullStage] ?? $pullStage) : '';
$pullShowPanel = $pullRunning || $pullRows !== [] || (string) ($pullRun['id'] ?? '') !== '' || $pullError !== '';

$pullMeta = '';
if (!empty($pullRun['started_at'])) {
    $pullMeta = 'Run ' . (string) ($pullRun['id'] ?? '') . ' oleh ' . (string) ($pullRun['actor'] ?? '?')
        . ' (' . (string) $pullRun['started_at']
        . (!empty($pullRun['finished_at']) ? ' → ' . (string) $pullRun['finished_at'] : '')
        . ')';
    if (is_string($pullRun['title'] ?? null) && $pullRun['title'] !== '') {
        $pullMeta = 'Template ' . (string) $pullRun['title'] . ' — ' . $pullMeta;
    }
}
?>
<div id="template-pull-flash" class="mb-0"></div>

<?php if ($pull === null): ?>
<div class="alert alert-secondary py-2 small mb-3" role="alert">
  Status tarik image tidak tersedia pada render ini — tombol <strong>Tarik image</strong> tetap bisa dipakai
  (progres muncul setelah aksi dijalankan).
</div>
<?php elseif ($pullFailed !== ''): ?>
<div class="alert alert-danger py-2 small mb-3" role="alert">
  Gagal membaca status tarik image: <?= e($pullFailed) ?>
</div>
<?php endif; ?>

<!-- Kotak status tarik image. Selalu ada di DOM (JS mengisinya); `d-none` saat
     belum ada run supaya daftar template tetap jadi fokus awal. -->
<div class="card mb-3<?= $pullShowPanel ? '' : ' d-none' ?>" id="template-pull-panel"
     data-running="<?= $pullRunning ? '1' : '0' ?>"
     data-run-id="<?= e((string) ($pullRun['id'] ?? '')) ?>">
  <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="h6 mb-0">Progres Tarik Image</h2>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge <?= e($pullBadge[0]) ?>" id="template-pull-badge"><?= e($pullBadge[1]) ?></span>
      <span class="small text-muted" id="template-pull-stage"><?= $pullStageLabel !== '' ? 'Tahap: ' . e($pullStageLabel) : '' ?></span>
    </div>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
      <div class="small" id="template-pull-message"><?= e((string) ($pullRun['message'] ?? '')) ?></div>
      <div class="small text-muted" id="template-pull-meta"><?= e($pullMeta) ?></div>
    </div>
    <?php if ($pullError !== ''): ?>
      <div class="alert alert-danger py-2 small mt-2 mb-0" role="alert" id="template-pull-error"><?= e($pullError) ?></div>
    <?php else: ?>
      <div class="alert alert-danger py-2 small mt-2 mb-0 d-none" role="alert" id="template-pull-error"></div>
    <?php endif; ?>

    <div class="table-responsive mt-2">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Image</th>
            <th>Hasil</th>
            <th class="text-end">Detik</th>
            <th>Pesan</th>
          </tr>
        </thead>
        <tbody id="template-pull-results">
        <?php if ($pullRows === []): ?>
          <tr><td colspan="4" class="text-muted small">Belum ada image yang ditarik.</td></tr>
        <?php else: ?>
          <?php foreach ($pullRows as $row): $result = $row['result']; ?>
          <tr>
            <td class="mono small"><?= e($row['image']) ?></td>
            <td>
              <?php if ($result === null): ?>
                <span class="badge text-bg-secondary">Menunggu</span>
              <?php elseif (($result['ok'] ?? null) === true): ?>
                <span class="badge text-bg-success">Berhasil</span>
              <?php elseif (($result['ok'] ?? null) === false): ?>
                <span class="badge text-bg-danger">Gagal</span>
              <?php else: ?>
                <span class="badge text-bg-secondary">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end small text-muted"><?= is_numeric($result['seconds'] ?? null) ? e(number_format((float) $result['seconds'], 1)) : '—' ?></td>
            <td class="small text-muted"><?= e((string) ($result['message'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <pre id="template-pull-log" class="mono small mt-2 mb-0" style="max-height:280px;overflow:auto;background:#0b1020;color:#d7e3ff;padding:.75rem;border-radius:.5rem;"><?= e($pullLogTail) ?></pre>

    <div class="small text-muted mt-2">
      Setelah image selesai ditarik, jalankan <strong>Deploy Ulang</strong> pada app yang memakai template ini
      agar container memakai image baru.
    </div>
  </div>
</div>

<div class="alert alert-info py-2 small mb-3" role="alert">
  Template dibaca dari folder <span class="mono">templates/&lt;slug&gt;/</span> yang ikut versi repo Rames
  (dikelola lewat git, bukan lewat UI). <strong>Tarik image</strong> hanya menjalankan
  <span class="mono">docker pull</span> untuk seluruh image template di host — belum mengubah app apa pun;
  app yang memakai template perlu <strong>Deploy Ulang</strong> agar container memakai image terbaru.
</div>

<?php if ($templates === []): ?>
<div class="card">
  <div class="card-body p-4">
    <p class="mb-1"><strong>Belum ada template</strong> di folder <span class="mono">templates/</span>.</p>
    <p class="text-muted small mb-0">
      Template ikut versi repo: satu direktori per template (<span class="mono">templates/&lt;slug&gt;/</span>) berisi
      <span class="mono">template.yml</span> (judul, deskripsi, kategori, deklarasi <span class="mono">env[]</span>) dan
      <span class="mono">docker-compose.yml</span> (image prebuilt, tanpa <span class="mono">build:</span>).
      Tambahkan lewat git, lalu muat ulang halaman ini.
    </p>
  </div>
</div>
<?php else: ?>
<?php
// Logo template (bila ada berkasnya) ditampilkan menggantikan emoji fallback —
// peta yang sama dengan galeri create-app (`template_logo_map()`).
$logoMap = template_logo_map();
?>
<div class="row g-3">
  <?php foreach ($templates as $t): ?>
  <?php
  $slug = is_string($t['slug'] ?? null) ? (string) $t['slug'] : '';
  $title = is_string($t['title'] ?? null) && $t['title'] !== '' ? (string) $t['title'] : $slug;
  $category = is_string($t['category'] ?? null) ? (string) $t['category'] : '';
  $description = is_string($t['description'] ?? null) ? (string) $t['description'] : '';
  $icon = is_string($t['icon'] ?? null) ? trim((string) $t['icon']) : '';
  $valid = !empty($t['valid']);
  $error = is_string($t['error'] ?? null) ? trim((string) $t['error']) : '';
  $images = $__cleanStrings(array_values(is_array($t['images'] ?? null) ? $t['images'] : []));
  $apps = array_values(array_filter(
      is_array($t['apps'] ?? null) ? $t['apps'] : [],
      static fn ($app): bool => is_array($app)
  ));
  $docsUrl = is_string($t['docs_url'] ?? null) ? trim((string) $t['docs_url']) : '';
  $docsOk = in_array(strtolower((string) parse_url($docsUrl, PHP_URL_SCHEME)), ['http', 'https'], true);
  ?>
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2">
          <?php $logoExtension = $logoMap[$slug] ?? ''; ?>
          <?php if ($logoExtension !== ''): ?>
          <img class="template-logo-card" src="/images/templates/<?= e($slug) ?>.<?= e($logoExtension) ?>" alt="<?= e($title) ?>" loading="lazy">
          <?php else: ?>
          <span class="fs-3" aria-hidden="true"><?= e($icon !== '' ? $icon : '📦') ?></span>
          <?php endif; ?>
          <div class="flex-grow-1" style="min-width:0;">
            <div class="d-flex align-items-center flex-wrap gap-2">
              <h3 class="h6 mb-0"><?= e($title) ?></h3>
              <?php if ($category !== ''): ?><span class="badge text-bg-secondary"><?= e($category) ?></span><?php endif; ?>
              <?php if ($slug !== ''): ?><span class="mono small text-muted"><?= e($slug) ?></span><?php endif; ?>
            </div>
            <?php if ($description !== ''): ?>
            <p class="text-muted small mb-0 mt-1"
               style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"><?= e($description) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$valid): ?>
        <div class="alert alert-warning py-2 small mt-3 mb-0" role="alert">
          <strong>Template tidak valid.</strong><?= $error !== '' ? ' ' . e($error) : '' ?>
        </div>
        <?php endif; ?>

        <div class="mt-3">
          <?php if ($images === []): ?>
            <div class="small text-muted">Image tidak terdeteksi dari compose template.</div>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach ($images as $image): ?>
                <span class="badge text-bg-light border mono fw-normal"><?= e($image) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="small text-muted mt-2">
          <?php if ($apps === []): ?>
            belum dipakai app
          <?php else: ?>
            Dipakai oleh:
            <?php foreach ($apps as $i => $app): ?><?= $i > 0 ? ', ' : '' ?><a href="/apps/<?= e((string) ($app['id'] ?? '')) ?>"><?= e((string) ($app['name'] ?? '')) ?></a><?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-footer d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <?php if ($docsOk): ?>
          <a class="small" href="<?= e($docsUrl) ?>" target="_blank" rel="noopener">Dokumentasi ↗</a>
        <?php else: ?>
          <span class="small text-muted">Tanpa tautan dokumentasi</span>
        <?php endif; ?>
        <?php if (is_admin()): ?>
          <button type="button" class="btn btn-outline-primary btn-sm js-template-pull"
                  data-slug="<?= e($slug) ?>" data-title="<?= e($title) ?>"
                  data-valid="<?= $valid ? '1' : '0' ?>"
                  <?= $valid ? '' : 'disabled' ?>
                  title="<?= $valid ? 'docker pull seluruh image template ini di host' : 'Template tidak valid — perbaiki template.yml/compose-nya dulu' ?>">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>⬇ Tarik image
          </button>
        <?php else: ?>
          <span class="small text-muted">Tarik image hanya untuk admin.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pullLogs !== []): ?>
<details class="mt-3">
  <summary class="small text-muted">Riwayat log pull (<?= count($pullLogs) ?>)</summary>
  <div class="small mono mt-1">
    <?php foreach ($pullLogs as $log): $log = is_array($log) ? $log : []; ?>
      <div><?= e((string) ($log['name'] ?? '')) ?> <span class="text-muted">(<?= number_format(((int) ($log['size'] ?? 0)) / 1024, 1) ?> KB · <?= e((string) ($log['modified_at'] ?? '')) ?>)</span></div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
