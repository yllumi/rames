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

<ul class="nav nav-pills tab-scroll mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <a class="nav-link <?= $mode === 'git' ? 'active' : '' ?>" href="/apps/create">Clone repo Git</a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link <?= $mode === 'compose' ? 'active' : '' ?>" href="/apps/create?mode=compose">Compose (paste / upload)</a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link <?= $mode === 'template' ? 'active' : '' ?>" href="/apps/create?mode=template">Template</a>
  </li>
</ul>

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
    variabel environment-nya). Pilih satu kartu → isi nama app &amp; variabel → <strong>Deploy</strong>.
    Host port otomatis dicari yang bebas, nama container otomatis berprefix nama app, dan app tetap
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
<div class="row g-3">
  <?php foreach ($templates as $t): ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="card h-100 form-card">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <h2 class="h6 mb-0">
            <?php if (!empty($t['icon'])): ?><span class="me-1"><?= e($t['icon']) ?></span><?php endif; ?>
            <?= e($t['title']) ?>
          </h2>
          <span class="badge text-bg-secondary"><?= e($t['category']) ?></span>
        </div>

        <?php if (($t['description'] ?? '') !== ''): ?>
        <p class="text-muted small"><?= e($t['description']) ?></p>
        <?php endif; ?>

        <?php if (!$t['valid']): ?>
        <div class="alert alert-warning small mb-2" role="alert">
          <strong>Template rusak:</strong> <?= e((string) $t['error']) ?>
        </div>
        <?php endif; ?>

        <ul class="list-unstyled small text-muted mb-2">
          <?php if (($t['image'] ?? '') !== ''): ?>
          <li>Image: <span class="mono"><?= e($t['image']) ?></span></li>
          <?php endif; ?>
          <?php if (($t['ports'] ?? []) !== []): ?>
          <li>Port: <span class="mono"><?= e(implode(', ', array_map('strval', $t['ports']))) ?></span></li>
          <?php endif; ?>
          <?php if (($t['primary']['service'] ?? '') !== ''): ?>
          <li>Domain → <span class="mono"><?= e($t['primary']['service'] . ':' . $t['primary']['port']) ?></span></li>
          <?php endif; ?>
          <li><?= count($t['env']) ?> variabel environment</li>
          <?php if (($t['files'] ?? []) !== []): ?>
          <li><?= count($t['files']) ?> file pendukung</li>
          <?php endif; ?>
        </ul>

        <div class="mt-auto d-flex gap-2 align-items-center">
          <?php if ($t['valid']): ?>
          <a class="btn btn-primary btn-sm" href="/apps/create/template/<?= e($t['slug']) ?>">Pilih &amp; Deploy</a>
          <?php else: ?>
          <button type="button" class="btn btn-secondary btn-sm" disabled>Perbaiki template dulu</button>
          <?php endif; ?>
          <?php if (($t['docs_url'] ?? '') !== ''): ?>
          <a class="btn btn-outline-secondary btn-sm" href="<?= e($t['docs_url']) ?>" target="_blank" rel="noopener">Dokumentasi</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
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
