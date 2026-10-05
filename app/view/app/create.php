<?php $pageTitle = 'Create App'; $active = 'apps'; ?>
<?php
// Tiga mode create app:
//   git      — clone repo yang berisi docker-compose.yml (default)
//   compose  — paste/upload docker-compose.yml + file pendukung (tanpa repo Git),
//              untuk app dengan image prebuilt (tanpa build context).
//   template — galeri template app siap-pakai (compose prebuilt yang sudah
//              disiapkan di folder `templates/<slug>/`, SPECS.md §7.2b).
$mode = in_array(($mode ?? 'git'), ['compose', 'template'], true) ? (string) $mode : 'git';
$templates = $templates ?? [];

// State tambahan: saat clone repo private gagal, form dirender ulang bersama
// public key deploy key supaya user bisa menambahkannya ke repo lalu coba lagi.
$sshPubkey = $sshPubkey ?? null;
$authMethod = $auth_method ?? 'none';
$previewError = $preview_error ?? null;
// Nilai form dipertahankan saat re-render setelah Analisis Repo gagal.
$formName = $form_name ?? '';
$formRepoUrl = $form_repo_url ?? '';
$formBranch = $form_branch ?? 'main';
// Nilai form mode compose dipertahankan saat validasi gagal.
$formCompose = $form_compose ?? '';
$composeError = $compose_error ?? null;
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Create App</h1>
    <p class="text-muted mb-0">Pilih sumber app: clone repo Git, paste/upload <code>docker-compose.yml</code>, atau pakai template siap-pakai.</p>
  </div>
</div>

<nav aria-label="Mode create app">
<ul class="nav nav-pills tab-scroll mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $mode === 'template' ? 'active' : '' ?>" href="/apps/create?mode=template" <?= $mode === 'template' ? 'aria-current="page"' : '' ?>>Template</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $mode === 'git' ? 'active' : '' ?>" href="/apps/create" <?= $mode === 'git' ? 'aria-current="page"' : '' ?>>Clone repo Git</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $mode === 'compose' ? 'active' : '' ?>" href="/apps/create?mode=compose" <?= $mode === 'compose' ? 'aria-current="page"' : '' ?>>Compose (paste / upload)</a>
  </li>
</ul>
</nav>

<?php if ($mode === 'compose'): ?>
<div class="card form-card">
  <div class="card-body p-4">
    <p class="text-muted small">
      Mode ini untuk mendeploy app yang <strong>tidak butuh build image custom</strong> — seluruh service
      memakai <code>image:</code> prebuilt (mis. <code>nginx:alpine</code>). Sistem menulis file ke
      <code>apps/{nama}</code>, mendeteksi port, lalu menampilkan konfirmasi sebelum deploy.
    </p>

    <?php if ($composeError): ?>
    <div class="alert alert-warning" role="alert">
      <strong>Compose ditolak:</strong> <?= e($composeError) ?>
    </div>
    <?php endif; ?>

    <form method="post" action="/apps/create/compose" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="compose-name">Nama app (slug)</label>
        <input type="text" class="form-control" id="compose-name" name="name" placeholder="myapp" value="<?= e($formName) ?>" required>
        <div class="form-text">Hanya huruf kecil a-z, angka, dan strip (-). Dipakai sebagai subdomain, nama project compose, dan nama direktori.</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="compose-content">Isi <code>docker-compose.yml</code></label>
        <textarea class="form-control mono" id="compose-content" name="compose" rows="14" placeholder="services:&#10;  web:&#10;    image: nginx:alpine&#10;    ports:&#10;      - &quot;8080:80&quot;"><?= e($formCompose) ?></textarea>
        <div class="form-text">
          Tempel isi compose di sini, <em>atau</em> unggah filenya pada kolom di bawah (pilih salah satu).
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="compose-files">Upload file (opsional)</label>
        <input type="file" class="form-control" id="compose-files" name="files[]" multiple>
        <div class="form-text">
          Unggah <code>docker-compose.yml</code> (bila tidak menempel isinya di atas) beserta file pendukung
          lain yang di-bind mount ke container (mis. <code>nginx.conf</code>). Maks 1 MB per file.
          File override yang dikelola dashboard (<code>docker-compose.override*</code>) tidak boleh diunggah.
        </div>
      </div>

      <div class="alert alert-secondary small" role="alert">
        Service <strong>wajib</strong> punya <code>image:</code> dan <strong>tidak boleh</strong> memakai
        <code>build:</code> — mode ini tidak punya source/build context. Untuk app yang di-build dari source,
        gunakan mode <a href="/apps/create">Clone repo Git</a>.
        Setelah dibuat, compose masih bisa diubah lewat tab <strong>Compose</strong> di halaman detail app.
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Lanjut ke Konfirmasi</button>
        <a class="btn btn-outline-secondary" href="/apps">Batal</a>
      </div>
    </form>
  </div>
</div>

<?php elseif ($mode === 'template'): ?>
<div class="mb-3">
  <p class="text-muted small mb-0">
    Template adalah app <strong>docker-ready</strong> yang sudah disiapkan (image prebuilt, port, dan
    variabel environment-nya). Host port otomatis dicari yang bebas, nama container otomatis berprefix nama app, dan app tetap
    muncul di daftar seperti app biasa (bisa Stop/Rebuild/Delete, atur domain, terminal, dll).
  </p>
</div>

<?php if ($templates === []): ?>
<div class="card form-card">
  <div class="card-body p-4">
    <div class="alert alert-secondary mb-0" role="alert">
      Belum ada template di folder <code>templates/</code>. Tambahkan satu direktori per template berisi
      <code>template.yml</code> (metadata), <code>docker-compose.yml</code> (image prebuilt, tanpa <code>build:</code>),
      dan folder <code>files/</code> bila ada file pendukung yang di-bind mount.
    </div>
  </div>
</div>
<?php else: ?>
<?php
// Peta logo diturunkan sekali per render dari isi direktori nyata (bukan daftar
// ekstensi hardcode) supaya template baru ber-logo .svg/.png/.webp langsung
// terdeteksi. Hanya ekstensi whitelist (case-insensitive) yang diterima.
$logoMap = [];
foreach (glob(dirname(__DIR__, 3) . '/public/images/templates/*') ?: [] as $logoFile) {
    $logoSlug = pathinfo($logoFile, PATHINFO_FILENAME);
    $logoExt = pathinfo($logoFile, PATHINFO_EXTENSION);
    if (in_array(strtolower($logoExt), ['svg', 'png', 'webp'], true)
        && preg_match('/\A[a-z0-9-]+\z/D', $logoSlug) === 1
        && is_file($logoFile)
        && !isset($logoMap[$logoSlug])) {
        $logoMap[$logoSlug] = $logoExt;
    }
}
?>
<div class="template-grid">
  <?php $templateIndex = 0; foreach ($templates as $t): $modalId = 'template-detail-' . $templateIndex++; ?>
  <?php $slug = is_string($t['slug'] ?? null) ? $t['slug'] : ''; $logoExtension = preg_match('/\A[a-z0-9-]+\z/D', $slug) === 1 ? ($logoMap[$slug] ?? '') : ''; $hasLogo = $logoExtension !== ''; ?>
  <button type="button" class="template-tile" data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>" aria-haspopup="dialog" aria-controls="<?= e($modalId) ?>" aria-label="Detail template <?= e($t['title']) ?>">
    <span class="template-tile-media">
      <?php if ($hasLogo): ?>
      <img class="template-tile-logo" src="/images/templates/<?= e($slug) ?>.<?= e($logoExtension) ?>" alt="<?= e($t['title']) ?>" loading="lazy">
      <?php else: ?>
      <span class="template-tile-fallback" aria-hidden="true"><?= ($t['icon'] ?? '') !== '' ? e($t['icon']) : '◈' ?></span>
      <?php endif; ?>
    </span>
    <span class="template-tile-name"><?= e($t['title']) ?></span>
    <span class="template-tile-category"><?= e($t['category']) ?></span>
  </button>
  <?php endforeach; ?>
</div>

<?php $templateIndex = 0; foreach ($templates as $t): $modalId = 'template-detail-' . $templateIndex++; $docsUrl = is_string($t['docs_url'] ?? null) ? trim($t['docs_url']) : ''; $docsUrlParts = $docsUrl !== '' ? parse_url($docsUrl) : false; $docsUrlIsSafe = filter_var($docsUrl, FILTER_VALIDATE_URL) !== false && is_array($docsUrlParts) && in_array(strtolower((string) ($docsUrlParts['scheme'] ?? '')), ['http', 'https'], true); ?>
<div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>-title" aria-describedby="<?= e($modalId) ?>-description" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div class="me-3">
          <h2 class="modal-title h5 mb-1" id="<?= e($modalId) ?>-title">
            <span class="me-1" aria-hidden="true"><?= ($t['icon'] ?? '') !== '' ? e($t['icon']) : '◈' ?></span><?= e($t['title']) ?>
          </h2>
          <span class="badge text-bg-secondary"><?= e($t['category']) ?></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted" id="<?= e($modalId) ?>-description"><?= ($t['description'] ?? '') !== '' ? e($t['description']) : 'Tidak ada deskripsi.' ?></p>

        <?php if ($t['valid']): ?>
        <div class="alert alert-success py-2" role="status">Template valid dan siap di-deploy.</div>
        <?php else: ?>
        <div class="alert alert-danger" role="alert">
          <strong>Template tidak valid.</strong>
          <?php if (($t['error'] ?? '') !== ''): ?><div><?= e((string) $t['error']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3" id="<?= e($modalId) ?>-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="<?= e($modalId) ?>-tab-detail" data-bs-toggle="tab" data-bs-target="#<?= e($modalId) ?>-pane-detail" type="button" role="tab" aria-controls="<?= e($modalId) ?>-pane-detail" aria-selected="true">Detail</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="<?= e($modalId) ?>-tab-guide" data-bs-toggle="tab" data-bs-target="#<?= e($modalId) ?>-pane-guide" type="button" role="tab" aria-controls="<?= e($modalId) ?>-pane-guide" aria-selected="false">Panduan</button>
          </li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="<?= e($modalId) ?>-pane-detail" role="tabpanel" aria-labelledby="<?= e($modalId) ?>-tab-detail">
            <dl class="row small mb-0">
              <?php if (($t['image'] ?? '') !== ''): ?>
              <dt class="col-sm-4">Image</dt><dd class="col-sm-8 mono text-break"><?= e($t['image']) ?></dd>
              <?php endif; ?>
              <dt class="col-sm-4">Port</dt>
              <dd class="col-sm-8 mono"><?= ($t['ports'] ?? []) !== [] ? e(implode(', ', array_map('strval', $t['ports']))) : 'Tidak ada' ?></dd>
              <dt class="col-sm-4">Domain</dt>
              <dd class="col-sm-8 mono"><?= ($t['primary']['service'] ?? '') !== '' ? e($t['primary']['service'] . ':' . $t['primary']['port']) : 'Tidak disetel' ?></dd>
              <dt class="col-sm-4">Variabel environment</dt>
              <dd class="col-sm-8"><?= e((string) count($t['env'] ?? [])) ?></dd>
              <dt class="col-sm-4">File pendukung</dt>
              <dd class="col-sm-8">
                <?php if (($t['files'] ?? []) === []): ?>Tidak ada
                <?php else: ?>
                <ul class="mb-0 ps-3">
                  <?php foreach ($t['files'] as $file): ?><li class="mono text-break"><?= e($file) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
              </dd>
              <?php if (($t['docs_url'] ?? '') !== ''): ?>
              <dt class="col-sm-4">Dokumentasi</dt>
              <dd class="col-sm-8"><?php if ($docsUrlIsSafe): ?><a href="<?= e($docsUrl) ?>" target="_blank" rel="noopener">Buka dokumentasi</a><?php else: ?><?= e($docsUrl) ?><?php endif; ?></dd>
              <?php endif; ?>
            </dl>
          </div>
          <div class="tab-pane fade" id="<?= e($modalId) ?>-pane-guide" role="tabpanel" aria-labelledby="<?= e($modalId) ?>-tab-guide">
            <?php if ((string) ($t['guide_html'] ?? '') === ''): ?>
            <p class="text-muted small mb-0"><?= e('Panduan belum tersedia untuk template ini.') ?></p>
            <?php else: ?>
            <?php // HTML hasil sanitasi Markdown::toHtml() — sengaja dirender tanpa e(). ?>
            <div class="guide-content"><?= $t['guide_html'] ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <?php if ($t['valid']): ?>
        <a class="btn btn-primary" href="/apps/create/template/<?= e($t['slug']) ?>">Deploy</a>
        <?php else: ?>
        <button type="button" class="btn btn-primary" disabled>Deploy</button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php else: ?>
<div class="card form-card">
  <div class="card-body p-4">
    <p class="text-muted small">Sistem akan clone repo, memeriksa <code>docker-compose.yml</code>, mendeteksi port, lalu menampilkan konfirmasi sebelum deploy.</p>

    <?php if ($previewError): ?>
    <div class="alert alert-warning" role="alert">
      <strong>Analisis repo gagal:</strong> <?= e($previewError) ?>
    </div>
    <?php endif; ?>

    <?php if ($sshPubkey): ?>
    <div class="alert alert-warning" role="alert">
      <div class="fw-semibold mb-1">Tambahkan SSH deploy key ini ke repo Anda, lalu klik <em>Analisis Repo</em> lagi.</div>
      <div class="small mb-2">GitHub: <em>Settings → Deploy keys → Add deploy key</em> (read-only cukup). GitLab: <em>Settings → Repository → Deploy keys</em>. Kunci akan dipakai ulang otomatis.</div>
      <textarea id="ssh-pubkey-form" class="form-control mono form-control-sm" rows="3" readonly><?= e($sshPubkey) ?></textarea>
      <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="copyFormKey()">Salin public key</button>
    </div>
    <?php endif; ?>

    <form method="post" action="/apps/create">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="name">Nama app (slug)</label>
        <input type="text" class="form-control" id="name" name="name" placeholder="myapp" value="<?= e($formName) ?>" required>
        <div class="form-text">Hanya huruf kecil a-z, angka, dan strip (-). Dipakai sebagai subdomain &amp; nama direktori.</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="repo_url">URL repo Git</label>
        <input type="text" class="form-control" id="repo_url" name="repo_url" placeholder="https://github.com/user/myapp.git" value="<?= e($formRepoUrl) ?>" required>
        <div class="form-text">Repo publik: <code>https://...</code>. Repo private via SSH deploy key: <code>git@github.com:user/repo.git</code> atau <code>ssh://git@host/user/repo.git</code>.</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Akses repo</label>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="auth_method" id="auth-none" value="none" <?= $authMethod === 'none' ? 'checked' : '' ?>>
          <label class="form-check-label" for="auth-none">Publik — clone anonim</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="auth_method" id="auth-ssh" value="ssh" <?= $authMethod === 'ssh' ? 'checked' : '' ?>>
          <label class="form-check-label" for="auth-ssh">Private — SSH deploy key</label>
        </div>
        <div class="form-text">Repo private akan dibekali <strong>deploy key</strong> (pasangan kunci SSH yang dibuat sistem). Public key-nya ditampilkan di sini / halaman konfirmasi untuk ditambahkan ke repo (<em>Settings → Deploy keys</em>).</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="branch">Branch</label>
        <input type="text" class="form-control" id="branch" name="branch" value="<?= e($formBranch) ?>" required>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Analisis Repo</button>
        <a class="btn btn-outline-secondary" href="/apps">Batal</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function copyFormKey() {
  var t = document.getElementById('ssh-pubkey-form');
  if (!t) return;
  t.select();
  t.setSelectionRange(0, 99999);
  try { navigator.clipboard.writeText(t.value); } catch (e) {}
  try { document.execCommand('copy'); } catch (e) {}
}
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
