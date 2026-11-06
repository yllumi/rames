<?php
/**
 * Halaman dokumentasi OpenAPI (`GET /api-docs`) — wajib login. API key dibuat
 * dan dicabut **per user** di halaman Profil (`/profile`).
 *
 * Seluruh isi dirender dari array dokumen `OpenApiSpec::build()` yang dikirim
 * `ApiDocsController::page()` — daftar endpoint/field TIDAK diduplikasi di sini.
 * Semua output di-escape `e()`; contoh JSON tetap di-escape (bukan innerHTML).
 * Tanpa JS/AJAX: halaman statis, aman di-smoke-render tanpa controller.
 */
$pageTitle = 'API';
$active = 'api';

$spec = is_array($spec ?? null) ? $spec : [];
$specPath = is_scalar($specPath ?? null) ? (string) $specPath : '/api-docs/openapi.json';
$profilePath = is_scalar($profilePath ?? null) ? (string) $profilePath : '/profile';
$origin = is_scalar($origin ?? null) ? (string) $origin : '';
// Tab server-side (`?tab=endpoint|mcp`) — deep-link & refresh mendarat di tab
// yang sama. Nilai tak dikenal jatuh ke tab pertama (perilaku /nginx).
$tab = is_scalar($tab ?? null) ? (string) $tab : 'endpoint';
if (!in_array($tab, ['endpoint', 'mcp'], true)) {
    $tab = 'endpoint';
}

// Server MCP: metadata dari `OpenApiSpec::mcp()` (extension `x-rames-mcp`) —
// daftar tool/env tidak diduplikasi di view.
$mcp = is_array($spec['x-rames-mcp'] ?? null) ? $spec['x-rames-mcp'] : [];
$mcpTools = is_array($mcp['tools'] ?? null) ? $mcp['tools'] : [];
$mcpEnv = is_array($mcp['env'] ?? null) ? $mcp['env'] : [];
$mcpScript = is_scalar($mcp['script'] ?? null) ? (string) $mcp['script'] : 'tools/mcp/rames_mcp.py';
$mcpName = is_scalar($mcp['name'] ?? null) ? (string) $mcp['name'] : 'rames';
$mcpVersion = is_scalar($mcp['version'] ?? null) ? (string) $mcp['version'] : '';
$mcpDocs = is_scalar($mcp['docs'] ?? null) ? (string) $mcp['docs'] : 'tools/mcp/README.md';
$mcpCommand = is_scalar($mcp['command'] ?? null) ? (string) $mcp['command'] : 'python3';
$mcpUrl = $origin !== '' ? $origin : '<alamat-dashboard-anda>';
$mcpAbsPath = '/ABSOLUTE/PATH/rames/' . $mcpScript;

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

$mcpVscodeSnippet = $jsonOf([
    'servers' => [
        $mcpName => [
            'type' => 'stdio',
            'command' => $mcpCommand,
            'args' => [$mcpAbsPath],
            'env' => [
                'RAMES_URL' => $mcpUrl,
                'RAMES_API_KEY' => '${input:rames-api-key}',
            ],
        ],
    ],
    'inputs' => [
        [
            'type' => 'promptString',
            'id' => 'rames-api-key',
            'description' => 'API key Rames (Profil → API key)',
            'password' => true,
        ],
    ],
]);

$mcpDesktopSnippet = $jsonOf([
    'mcpServers' => [
        $mcpName => [
            'command' => $mcpCommand,
            'args' => [$mcpAbsPath],
            'env' => [
                'RAMES_URL' => $mcpUrl,
                'RAMES_API_KEY' => 'rmk_...',
            ],
        ],
    ],
]);

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
    <strong>API key per user</strong> — bukan sesi login dan bukan token CSRF.
    Key dibuat/dicabut sendiri oleh setiap user di <a href="<?= e($profilePath) ?>">halaman Profil</a>
    dan bertindak sebagai akun pemiliknya, sehingga hak akses app
    (<span class="mono">AppAccess</span>), kepemilikan, dan gerbang kredit tetap berlaku:
    API tidak bisa melakukan hal yang tidak boleh dilakukan user tersebut di UI.
    Halaman dokumentasi ini — termasuk berkas <span class="mono"><?= e($specPath) ?></span> —
    hanya dapat diakses <strong>setelah login</strong> dashboard.
  </p>
<?php $__infoHtml = (string) ob_get_clean(); $__infoId = 'api-info'; $__infoTitle = 'Tentang halaman API'; ?>
<?php include app_path() . '/view/partials/page-info-modal.php'; ?>

<ul class="nav nav-tabs mb-3" id="api-docs-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= $tab === 'endpoint' ? ' active' : '' ?>" id="api-docs-tab-endpoint" data-bs-toggle="tab"
            data-bs-target="#pane-endpoint" data-tab="endpoint" type="button" role="tab"
            aria-controls="pane-endpoint" aria-selected="<?= $tab === 'endpoint' ? 'true' : 'false' ?>">Endpoint</button>
  </li>
  <?php if ($mcpTools !== []): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= $tab === 'mcp' ? ' active' : '' ?>" id="api-docs-tab-mcp" data-bs-toggle="tab"
            data-bs-target="#pane-mcp" data-tab="mcp" type="button" role="tab"
            aria-controls="pane-mcp" aria-selected="<?= $tab === 'mcp' ? 'true' : 'false' ?>">MCP
      <span class="badge text-bg-secondary ms-1"><?= e((string) count($mcpTools)) ?></span></button>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content" id="api-docs-tab-content">
<div class="tab-pane fade<?= $tab === 'endpoint' ? ' show active' : '' ?>" id="pane-endpoint" role="tabpanel" aria-labelledby="api-docs-tab-endpoint">
<div class="alert alert-info" role="alert">
  <strong>API key per user.</strong> Buat atau cabut key Anda di
  <a href="<?= e($profilePath) ?>" class="alert-link">halaman Profil</a> — key hanya ditampilkan
  <strong>sekali</strong> saat dibuat. Satu key aktif per user; generate ulang langsung mematikan key lama.
</div>

<div class="card mb-3">
  <div class="card-header bg-body py-2 fw-semibold">Autentikasi</div>
  <div class="card-body">
    <p class="mb-2">
      Sertakan header berikut pada setiap request (key dari
      <a href="<?= e($profilePath) ?>">Profil → API key</a>):
      <span class="mono">Authorization: Bearer &lt;api-key&gt;</span>
    </p>
    <pre class="bg-body-tertiary border rounded p-3 small mb-2"><code>curl -X POST 'https://&lt;dashboard-host&gt;/api/v1/apps/contoh-app/build' \
  -H 'Authorization: Bearer $RAMES_API_KEY'</code></pre>
    <ul class="small text-muted mb-0">
      <li><strong>401</strong> — API key tidak ada/salah, sudah dicabut, atau user pemiliknya sudah dihapus.</li>
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
                data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>" aria-expanded="false" aria-controls="<?= e($collapseId) ?>">
          <span class="badge <?= e($methodBadge((string) $method)) ?>"><?= e(strtoupper((string) $method)) ?></span>
          <span class="mono fw-semibold"><?= e((string) $path) ?></span>
          <?php if ($anonymous): ?><span class="badge text-bg-secondary">tanpa token</span><?php endif; ?>
          <?php if ($summary !== ''): ?><span class="text-muted small"><?= e($summary) ?></span><?php endif; ?>
          <span class="flex-grow-1"></span>
          <span class="ep-chevron" aria-hidden="true"><?= svg_icon('chevron-down') ?></span>
        </button>
      </div>
      <div class="collapse" id="<?= e($collapseId) ?>">
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

</div><!-- /pane-endpoint -->

<?php if ($mcpTools !== []): ?>
<div class="tab-pane fade<?= $tab === 'mcp' ? ' show active' : '' ?>" id="pane-mcp" role="tabpanel" aria-labelledby="api-docs-tab-mcp">
<?php if ($mcpTools !== []): ?>
<h2 class="h5 mt-4 mb-3">MCP (Model Context Protocol)<?php $__infoTarget = 'mcp-info'; include app_path() . '/view/partials/page-info-button.php'; ?></h2>

<?php ob_start(); ?>
  <p class="text-muted mb-0">
    Server MCP memungkinkan asisten AI (VS Code/Copilot, Claude Desktop, dsb.) memanggil API
    ini sebagai <strong>tool</strong> — bukan menyalin perintah curl. Transport-nya
    <span class="mono">stdio</span>: klien menjalankan skrip
    <span class="mono"><?= e($mcpScript) ?></span> sebagai subprocess.
    Server hanya membungkus dua endpoint di halaman ini dan memakai
    <strong>API key per user</strong> yang sama: key dibuat di
    <a href="<?= e($profilePath) ?>">Profil</a>, dan setiap tool bertindak sebagai pemilik key
    (otorisasi app &amp; kredit tetap berlaku). Tanpa dependensi tambahan — cukup Python 3.9+.
  </p>
<?php $__infoHtml = (string) ob_get_clean(); $__infoId = 'mcp-info'; $__infoTitle = 'Tentang MCP'; ?>

<div class="card mb-3">
  <div class="card-header bg-body py-2 fw-semibold d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span>Server <span class="mono"><?= e($mcpName) ?></span></span>
    <span class="d-flex flex-wrap gap-2">
      <span class="badge text-bg-secondary">stdio</span>
      <?php if ($mcpVersion !== ''): ?><span class="badge text-bg-light border mono">v<?= e($mcpVersion) ?></span><?php endif; ?>
      <span class="badge text-bg-light border"><?= e((string) count($mcpTools)) ?> tool</span>
    </span>
  </div>
  <div class="card-body">
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Tool</th><th>Endpoint</th><th>Kegunaan</th></tr></thead>
        <tbody>
        <?php foreach ($mcpTools as $tool): ?>
          <?php if (!is_array($tool)) { continue; } ?>
          <tr>
            <td class="mono"><?= e((string) ($tool['name'] ?? '')) ?></td>
            <td class="mono small">
              <span class="badge <?= e($methodBadge((string) ($tool['method'] ?? 'GET'))) ?>"><?= e(strtoupper((string) ($tool['method'] ?? 'GET'))) ?></span>
              <?= e((string) ($tool['path'] ?? '')) ?>
            </td>
            <td class="small"><?= e((string) ($tool['description'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="fw-semibold small mb-1">Environment</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <tbody>
            <?php foreach ($mcpEnv as $envName => $envNote): ?>
              <tr>
                <td class="mono small text-nowrap"><?= e((string) $envName) ?></td>
                <td class="small"><?= e((string) $envNote) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="fw-semibold small mb-1">Catatan</div>
        <ul class="small text-muted mb-0">
          <li><span class="mono">args</span> memakai <strong>path absolut</strong> skrip: ganti
            <span class="mono">/ABSOLUTE/PATH/rames/</span> dengan lokasi repo Anda.</li>
          <li><span class="mono">RAMES_URL</span> tanpa garis miring di akhir
            (<span class="mono">//api/...</span> = 404).</li>
          <li>Build berjalan asinkron: panggil <span class="mono"><?= e($mcpName) ?>_app_status</span>
            berulang sampai <span class="mono">status</span> bukan <span class="mono">deploying</span>.</li>
          <li>Key yang dicabut/di-generate ulang ⇒ tool membalas 401.</li>
          <li>Panduan lengkap: <span class="mono"><?= e($mcpDocs) ?></span>.</li>
        </ul>
      </div>
    </div>

    <hr class="my-3">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
      <div class="fw-semibold small">VS Code / Copilot — <span class="mono">.vscode/mcp.json</span></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-target="#mcp-vscode-snippet">Salin</button>
    </div>
    <pre class="bg-body-tertiary border rounded p-3 small mb-3"><code id="mcp-vscode-snippet"><?= e($mcpVscodeSnippet) ?></code></pre>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
      <div class="fw-semibold small">Claude Desktop — <span class="mono">claude_desktop_config.json</span></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-copy-target="#mcp-desktop-snippet">Salin</button>
    </div>
    <pre class="bg-body-tertiary border rounded p-3 small mb-0"><code id="mcp-desktop-snippet"><?= e($mcpDesktopSnippet) ?></code></pre>
  </div>
</div>
<?php endif; ?>

</div><!-- /pane-mcp -->
<?php endif; ?>
</div><!-- /tab-content -->

<?php include app_path() . '/view/partials/page-info-modal.php'; ?>

<script src="/js/copy-text.js"></script>
<script src="/js/api-docs.js"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>
