<?php
/**
 * Halaman dokumentasi OpenAPI (`GET /api-docs`). Wajib login; detail
 * konfigurasi token hanya ditampilkan untuk admin (`$canManageApi`).
 *
 * Seluruh isi dirender dari array dokumen `OpenApiSpec::build()` yang dikirim
 * `ApiDocsController::page()` — daftar endpoint/field TIDAK diduplikasi di sini.
 * Semua output di-escape `e()`; contoh JSON tetap di-escape (bukan innerHTML).
 * Tanpa JS/AJAX: halaman statis, aman di-smoke-render tanpa controller.
 */
$pageTitle = 'API';
$active = 'api';

$spec = is_array($spec ?? null) ? $spec : [];
$apiEnabled = (bool) ($apiEnabled ?? false);
$tokenUser = is_scalar($tokenUser ?? null) ? (string) $tokenUser : 'admin';
$specPath = is_scalar($specPath ?? null) ? (string) $specPath : '/api-docs/openapi.json';
$canManageApi = (bool) ($canManageApi ?? false);
$info = is_array($spec['info'] ?? null) ? $spec['info'] : [];
$paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
$schemas = is_array($spec['components']['schemas'] ?? null) ? $spec['components']['schemas'] : [];

$apiTitle = is_scalar($info['title'] ?? null) ? (string) $info['title'] : 'Rames API';
$apiVersion = is_scalar($info['version'] ?? null) ? (string) $info['version'] : '';
$apiDescription = is_scalar($info['description'] ?? null) ? (string) $info['description'] : '';

/** Ringkas tipe skema (ref/type/const/oneOf) untuk tabel. */
$typeOf = static function (mixed $schema) use (&$typeOf): string {
    if (!is_array($schema)) {
        return '';
    }
    if (isset($schema['$ref']) && is_scalar($schema['$ref'])) {
        return basename((string) $schema['$ref']);
    }
    if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
        $parts = [];
        foreach ($schema['oneOf'] as $part) {
            $parts[] = $typeOf($part);
        }

        return implode(' | ', array_filter($parts, static fn (string $v): bool => $v !== ''));
    }
    if (array_key_exists('const', $schema) && is_scalar($schema['const'])) {
        return 'const ' . (string) $schema['const'];
    }
    $type = $schema['type'] ?? '';
    if (is_array($type)) {
        return implode('|', array_map(static fn ($v): string => (string) $v, $type));
    }
    $label = is_scalar($type) ? (string) $type : '';
    if (isset($schema['items']) && is_array($schema['items'])) {
        $item = $typeOf($schema['items']);

        return 'array<' . $item . '>';
    }
    if (isset($schema['additionalProperties']) && $schema['additionalProperties'] === true) {
        return 'object<string,mixed>';
    }

    return $label;
};

/** Contoh JSON yang aman ditampilkan (selalu di-escape saat dicetak). */
$jsonOf = static function (mixed $value): string {
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $encoded === false ? '' : $encoded;
};

$methodBadge = static function (string $method): string {
    return match (strtoupper($method)) {
        'GET' => 'text-bg-success',
        'POST' => 'text-bg-primary',
        'PUT', 'PATCH' => 'text-bg-warning',
        'DELETE' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
};

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'API', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">API<?php $__infoTarget = 'api-info'; include app_path() . '/view/partials/page-info-button.php'; ?></h1>
    <p class="text-muted mb-0">
      Dokumentasi OpenAPI untuk otomatisasi eksternal (CI/CD, tooling internal).
      <?php if ($apiVersion !== ''): ?><span class="mono">v<?= e($apiVersion) ?></span><?php endif; ?>
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($specPath) ?>" target="_blank" rel="noopener">OpenAPI JSON</a>
  </div>
</div>

<?php ob_start(); ?>
  <p class="text-muted mb-0">
    API ini dipakai klien mesin (bukan browser dashboard), jadi autentikasinya memakai
    <strong>bearer token</strong> — bukan sesi login dan bukan token CSRF.
    Token dipetakan ke satu user dashboard sehingga hak akses app
    (<span class="mono">AppAccess</span>), kepemilikan, dan gerbang kredit tetap berlaku:
    API tidak bisa melakukan hal yang tidak boleh dilakukan user tersebut di UI.
    Halaman dokumentasi ini — termasuk berkas <span class="mono"><?= e($specPath) ?></span> —
    hanya dapat diakses <strong>setelah login</strong> dashboard.
  </p>
<?php $__infoHtml = (string) ob_get_clean(); $__infoId = 'api-info'; $__infoTitle = 'Tentang halaman API'; ?>
<?php include app_path() . '/view/partials/page-info-modal.php'; ?>

<?php if ($canManageApi): ?>
  <?php if (!$apiEnabled): ?>
    <div class="alert alert-warning" role="alert">
      <strong>API belum aktif.</strong> Isi <span class="mono">RAMES_API_TOKEN</span> (dan opsional
      <span class="mono">RAMES_API_TOKEN_USER</span>) di <span class="mono">.env</span> dashboard lalu buat ulang container
      (<span class="mono">docker compose up -d</span>). Selama token kosong, semua path
      <span class="mono">/api/v1/*</span> menjawab <strong>404</strong> (fail-closed).
    </div>
  <?php else: ?>
    <div class="alert alert-info" role="alert">
      <strong>API aktif.</strong> Token dipetakan ke user <span class="mono"><?= e($tokenUser) ?></span> —
      otorisasi app &amp; kredit mengikuti user itu. Token bersifat rahasia: jangan disimpan di repo,
      log, atau tangkapan layar.
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="alert alert-secondary" role="alert">
    Konfigurasi token API (aktif/nonaktif &amp; user aktor) hanya ditampilkan untuk admin.
    Hubungi admin dashboard bila Anda membutuhkan token untuk otomatisasi.
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header bg-body py-2 fw-semibold">Autentikasi</div>
  <div class="card-body">
    <p class="mb-2">
      Sertakan header berikut pada setiap request:
      <span class="mono">Authorization: Bearer &lt;RAMES_API_TOKEN&gt;</span>
    </p>
    <pre class="bg-body-tertiary border rounded p-3 small mb-2"><code>curl -X POST 'https://&lt;dashboard-host&gt;/api/v1/apps/contoh-app/build' \
  -H 'Authorization: Bearer $RAMES_API_TOKEN'</code></pre>
    <ul class="small text-muted mb-0">
      <li><strong>401</strong> — token tidak ada/salah, atau user aktor tidak ditemukan.</li>
      <li><strong>404</strong> — app tidak ada <em>atau</em> tidak boleh diakses aktor (sengaja tidak dibedakan
        agar keberadaan app user lain tidak bocor).</li>
      <li><strong>409</strong> — app sedang deploy, atau nama app ambigu di beberapa server.</li>
      <li><strong>402 / 422</strong> — kredit tidak cukup / build ditolak (app impor, direktori app hilang).</li>
    </ul>
  </div>
</div>

<?php if ($apiDescription !== ''): ?>
  <div class="card mb-3">
    <div class="card-header bg-body py-2 fw-semibold"><?= e($apiTitle) ?></div>
    <div class="card-body">
      <?php foreach (preg_split('/\n{2,}/', $apiDescription) ?: [] as $paragraph): ?>
        <?php if (trim($paragraph) !== ''): ?>
          <p class="mb-2 small"><?= e(trim($paragraph)) ?></p>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<h2 class="h5 mt-4 mb-3">Endpoint</h2>
<?php foreach ($paths as $path => $operations): ?>
  <?php if (!is_array($operations)) { continue; } ?>
  <?php foreach ($operations as $method => $op): ?>
    <?php if (!is_array($op)) { continue; } ?>
    <?php
      $summary = is_scalar($op['summary'] ?? null) ? (string) $op['summary'] : '';
      $description = is_scalar($op['description'] ?? null) ? (string) $op['description'] : '';
      $parameters = is_array($op['parameters'] ?? null) ? $op['parameters'] : [];
      $responses = is_array($op['responses'] ?? null) ? $op['responses'] : [];
      $anonymous = ($op['security'] ?? null) === [];
      // Id unik & aman untuk target collapse Bootstrap (method + path unik).
      $collapseId = 'ep-' . substr(md5(strtoupper((string) $method) . ' ' . (string) $path), 0, 10);
    ?>
    <div class="card mb-3">
      <div class="card-header bg-body p-0">
        <button type="button" class="btn btn-link text-reset text-decoration-none text-start w-100 d-flex flex-wrap align-items-center gap-2 px-3 py-2 ep-toggle"
                data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>" aria-expanded="true" aria-controls="<?= e($collapseId) ?>">
          <span class="badge <?= e($methodBadge((string) $method)) ?>"><?= e(strtoupper((string) $method)) ?></span>
          <span class="mono fw-semibold"><?= e((string) $path) ?></span>
          <?php if ($anonymous): ?><span class="badge text-bg-secondary">tanpa token</span><?php endif; ?>
          <?php if ($summary !== ''): ?><span class="text-muted small"><?= e($summary) ?></span><?php endif; ?>
          <span class="flex-grow-1"></span>
          <span class="ep-chevron" aria-hidden="true"><?= svg_icon('chevron-down') ?></span>
        </button>
      </div>
      <div class="collapse show" id="<?= e($collapseId) ?>">
      <div class="card-body">
        <?php if ($description !== ''): ?>
          <p class="small mb-3"><?= e($description) ?></p>
        <?php endif; ?>

        <?php if ($parameters !== []): ?>
          <div class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr><th>Parameter</th><th>Di</th><th>Tipe</th><th>Deskripsi</th></tr>
              </thead>
              <tbody>
              <?php foreach ($parameters as $parameter): ?>
                <?php if (!is_array($parameter)) { continue; } ?>
                <tr>
                  <td class="mono"><?= e((string) ($parameter['name'] ?? '')) ?></td>
                  <td><?= e((string) ($parameter['in'] ?? '')) ?></td>
                  <td class="mono small"><?= e($typeOf(is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [])) ?></td>
                  <td class="small"><?= e((string) ($parameter['description'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="fw-semibold small mb-2">Respons</div>
        <?php foreach ($responses as $status => $response): ?>
          <?php if (!is_array($response)) { continue; } ?>
          <?php
            $statusText = (string) $status;
            $statusClass = str_starts_with($statusText, '2') ? 'text-bg-success'
                : (str_starts_with($statusText, '4') ? 'text-bg-warning' : 'text-bg-danger');
            $example = $response['content']['application/json']['example'] ?? null;
          ?>
          <div class="mb-2">
            <span class="badge <?= e($statusClass) ?>"><?= e($statusText) ?></span>
            <span class="small"><?= e((string) ($response['description'] ?? '')) ?></span>
            <?php if (is_array($example)): ?>
              <pre class="bg-body-tertiary border rounded p-2 small mt-2 mb-0"><code><?= e($jsonOf($example)) ?></code></pre>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<h2 class="h5 mt-4 mb-3">Skema data</h2>
<?php foreach ($schemas as $schemaName => $schema): ?>
  <?php if (!is_array($schema)) { continue; } ?>
  <?php $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : []; ?>
  <div class="card mb-3">
    <div class="card-header bg-body py-2 fw-semibold mono"><?= e((string) $schemaName) ?></div>
    <div class="card-body">
      <?php if ($properties === []): ?>
        <p class="text-muted small mb-0">Tanpa properti tetap.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr><th>Field</th><th>Tipe</th><th>Deskripsi</th></tr>
            </thead>
            <tbody>
            <?php foreach ($properties as $field => $fieldSchema): ?>
              <tr>
                <td class="mono"><?= e((string) $field) ?></td>
                <td class="mono small"><?= e($typeOf($fieldSchema)) ?></td>
                <td class="small"><?= e(is_array($fieldSchema) ? (string) ($fieldSchema['description'] ?? '') : '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
