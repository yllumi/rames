<?php
/**
 * Form domain parkir (`/domains/create` & `/domains/{id}/edit`) — admin-only
 * (controller menolak non-admin dengan 404; nav hanya lapisan kedua).
 *
 * Kontrak payload dari `DomainController::createForm()`/`editForm()`/
 * `invalidForm()` (jangan diubah dari sisi view):
 *   $domain       — `null` (create) atau entri parkir (edit);
 *   $input        — nilai form: create `domain,mode,html,redirect_to,
 *                   redirect_code,add_www`; edit seluruh field entri + `html`;
 *   $errors       — array pesan galat siap tampil;
 *   $isEdit       — bool;
 *   $active       — `'domains'`;
 *   $maxHtmlBytes — batas isi HTML (byte, default 1 MiB);
 *   $appDomain    — domain dasar dashboard (petunjuk, boleh kosong);
 *   $wwwSuggestion— saran varian `www.` (create saja; kosong = tidak tersedia).
 *
 * Field yang dibaca controller: domain, mode, html, html_file,
 * redirect_to, redirect_code, add_www (create saja). Semua validasi bisnis
 * tetap di `ParkedDomainValidator`/`ParkedDomainManager`; JS di sini hanya
 * toggle tampilan, penghitung byte, dan penolakan file > batas di sisi klien.
 */
$isEdit = (bool) ($isEdit ?? false);
$pageTitle = $isEdit ? 'Edit Domain Parkir' : 'Tambah Domain Parkir';
$active = 'domains';

$input = is_array($input ?? null) ? $input : [];
$domain = is_array($domain ?? null) ? $domain : null;
$errors = is_array($errors ?? null) ? array_values(array_filter($errors, 'is_scalar')) : [];
$appDomain = is_scalar($appDomain ?? null) ? trim((string) $appDomain) : '';
$wwwSuggestion = is_scalar($wwwSuggestion ?? null) ? trim((string) $wwwSuggestion) : '';
$maxHtmlBytes = is_numeric($maxHtmlBytes ?? null) ? (int) $maxHtmlBytes : 1048576;
if ($maxHtmlBytes <= 0) {
    $maxHtmlBytes = 1048576;
}

// Normalisasi defensif: render harus tetap utuh bila payload tidak lengkap.
$__text = static function (mixed $value): string {
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_bool($value)) {
        return $value ? '1' : '';
    }

    return '';
};
$__sizeLabel = static function (int $bytes): string {
    if ($bytes === 1048576) {
        return '1 MiB';
    }
    if ($bytes < 1024) {
        return $bytes . ' byte';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KiB';
    }

    return number_format($bytes / 1048576, 1, ',', '.') . ' MiB';
};

$maxHtmlLabel = $__sizeLabel($maxHtmlBytes);

$domainValue = $__text($input['domain'] ?? ($domain['domain'] ?? ''));
$mode = strtolower(trim($__text($input['mode'] ?? ($domain['mode'] ?? 'html'))));
if ($mode !== 'html' && $mode !== 'redirect') {
    $mode = 'html';
}
$html = $__text($input['html'] ?? '');
$redirectTo = $__text($input['redirect_to'] ?? ($domain['redirect_to'] ?? ''));
$redirectCode = (int) $__text($input['redirect_code'] ?? ($domain['redirect_code'] ?? 301));
if ($redirectCode !== 301 && $redirectCode !== 302) {
    $redirectCode = 301;
}
$addWwwChecked = !empty($input['add_www']);
$entryId = $__text($domain['id'] ?? ($input['id'] ?? ''));
$action = $isEdit && $entryId !== '' ? '/domains/' . rawurlencode($entryId) : '/domains';
$htmlBytes = strlen($html);

// Status SSL entri parkir (hanya ditampilkan pada mode edit). Payload dari
// `DomainController::formVars()`: {status,message,error,expires_at,can_ssl,
// form_url,ajax_url}. Aktivasi tetap lewat route khusus admin; view ini
// sengaja **tanpa** AJAX/polling agar tidak beradu dengan tab Semua Domain.
$ssl = is_array($ssl ?? null) ? $ssl : [];
$sslStatus = strtolower(trim($__text($ssl['status'] ?? 'disabled')));
if ($sslStatus === '') {
    $sslStatus = 'disabled';
}
$sslMessage = trim($__text($ssl['message'] ?? ''));
$sslError = trim($__text($ssl['error'] ?? ''));
$sslExpiresRaw = trim($__text($ssl['expires_at'] ?? ''));
$sslExpires = $sslExpiresRaw === '' ? '-' : $sslExpiresRaw;
if ($sslExpiresRaw !== '') {
    $sslTs = strtotime($sslExpiresRaw);
    if ($sslTs !== false) {
        $sslExpires = date('Y-m-d H:i', $sslTs);
    }
}
$sslCan = !empty($ssl['can_ssl']);
$sslFormUrl = $__text($ssl['form_url'] ?? '');
$sslNoteState = $sslStatus === 'pending' || $sslStatus === 'active';
$sslFormVisible = $sslCan && !$sslNoteState && $sslFormUrl !== '';
$sslBadges = [
    'active' => 'badge-running',
    'pending' => 'badge-deploying',
    'failed' => 'badge-error',
    'expired' => 'badge-error',
    'disabled' => 'badge-stopped',
];

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Domain Parkir', 'href' => '/domains?tab=parking'],
    ['label' => $isEdit ? 'Edit Domain' : 'Tambah Domain', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">
      <?= $isEdit ? 'Edit Domain Parkir' : 'Tambah Domain Parkir' ?><?= $isEdit && $domainValue !== '' ? ': ' . e($domainValue) : '' ?>
    </h1>
    <p class="text-muted mb-0">
      Domain parkir adalah FQDN <strong>di luar app</strong> yang disajikan sebagai halaman statis atau
      dialihkan. Setelah dibuat, SSL bisa diaktifkan dari tab
      <a href="/domains?tab=all">Semua Domain</a> atau kartu SSL di bawah.
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="/domains?tab=parking">&larr; Kembali ke Domain Parkir</a>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert">
  <strong>Perubahan tidak disimpan:</strong>
  <ul class="mb-0 small">
    <?php foreach ($errors as $__error): ?>
      <li><?= e((string) $__error) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" id="domain-form">
      <?= csrf_field() ?>

      <div class="mb-4">
        <label class="form-label" for="domain-name">Domain</label>
        <input type="text" class="form-control mono" id="domain-name" name="domain"
               value="<?= e($domainValue) ?>" required maxlength="253" autocomplete="off"
               autocapitalize="none" spellcheck="false" placeholder="parkir.contoh.com"
               aria-describedby="domain-name-help">
        <div class="form-text" id="domain-name-help">
          Gunakan <strong>FQDN</strong> tanpa <span class="mono">http://</span>, tanpa path, dan tanpa wildcard
          (<span class="mono">*.contoh.com</span>). Varian <span class="mono">www</span> adalah entri terpisah
          &mdash; gunakan opsi di bawah saat menambah domain.
          <?php if ($appDomain !== ''): ?>
            Contoh: <span class="mono">parkir.<?= e($appDomain) ?></span>.
          <?php endif; ?>
        </div>
      </div>

      <fieldset class="mb-4">
        <legend class="form-label fs-6 mb-2">Mode</legend>
        <div class="form-check mb-1">
          <input class="form-check-input" type="radio" name="mode" id="domain-mode-html" value="html"
                 <?= $mode === 'html' ? 'checked' : '' ?>>
          <label class="form-check-label" for="domain-mode-html">
            <strong>Halaman statis (HTML)</strong> &mdash; sajikan halaman parkir
          </label>
        </div>
        <div class="form-check mb-0">
          <input class="form-check-input" type="radio" name="mode" id="domain-mode-redirect" value="redirect"
                 <?= $mode === 'redirect' ? 'checked' : '' ?>>
          <label class="form-check-label" for="domain-mode-redirect">
            <strong>Redirect</strong> &mdash; alihkan ke URL lain (301/302)
          </label>
        </div>
        <div class="form-text">Pilih salah satu; bagian form yang tidak aktif tidak ikut disimpan.</div>
      </fieldset>

      <noscript>
        <style>.domain-mode-panel[hidden] { display: block !important; }</style>
        <div class="alert alert-info py-2 small" role="note">
          JavaScript nonaktif: kedua bagian (HTML &amp; Redirect) ditampilkan. Server hanya memakai bagian
          sesuai mode yang dipilih.
        </div>
      </noscript>

      <div class="domain-mode-panel" data-domain-panel="html"<?= $mode === 'html' ? '' : ' hidden' ?>>
        <div class="mb-3">
          <label class="form-label" for="domain-html">Isi HTML</label>
          <textarea class="form-control mono" id="domain-html" name="html" rows="16" spellcheck="false"
                    data-max-bytes="<?= (int) $maxHtmlBytes ?>"
                    aria-describedby="domain-html-help domain-html-file-help"
                    placeholder="&lt;!doctype html&gt;&#10;&lt;html lang=&quot;id&quot;&gt;…"><?= e($html) ?></textarea>
          <div class="d-flex flex-wrap justify-content-between gap-2 form-text" id="domain-html-help">
            <span>Templat halaman parkir. Batas ukuran isi: <strong><?= e($maxHtmlLabel) ?></strong>.</span>
            <span id="domain-html-count" class="<?= $htmlBytes > $maxHtmlBytes ? 'text-danger' : '' ?>">
              <?= e($__sizeLabel($htmlBytes) . ' / ' . $maxHtmlLabel) ?>
            </span>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="domain-html-file">
            Atau unggah file HTML <span class="text-muted small">(opsional)</span>
          </label>
          <input type="file" class="form-control" id="domain-html-file" name="html_file"
                 accept=".html,.htm,text/html"
                 aria-describedby="domain-html-file-help domain-html-file-note domain-html-file-error">
          <div class="form-text" id="domain-html-file-help">
            Unggahan <strong>menimpa</strong> isi textarea HTML di atas saat disimpan. Maksimum
            <?= e($maxHtmlLabel) ?>. File yang lebih besar ditolak sebelum dikirim.
          </div>
          <div class="form-text" id="domain-html-file-note" aria-live="polite"></div>
          <div class="text-danger small mt-1 d-none" id="domain-html-file-error" role="alert"></div>
        </div>
      </div>

      <div class="domain-mode-panel" data-domain-panel="redirect"<?= $mode === 'redirect' ? '' : ' hidden' ?>>
        <div class="mb-3">
          <label class="form-label" for="domain-redirect-to">URL tujuan redirect</label>
          <input type="url" class="form-control mono" id="domain-redirect-to" name="redirect_to"
                 value="<?= e($redirectTo) ?>" autocomplete="off" spellcheck="false"
                 placeholder="https://tujuan.contoh.com"
                 aria-describedby="domain-redirect-to-help">
          <div class="form-text" id="domain-redirect-to-help">
            Gunakan URL <span class="mono">http://</span> atau <span class="mono">https://</span> tanpa query
            atau fragmen. <strong>Path &amp; query permintaan asli dipertahankan otomatis</strong>
            (<span class="mono">$request_uri</span>), jadi pengunjung
            <span class="mono">/produk?q=1</span> diarahkan ke tujuan dengan path/query yang sama.
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="domain-redirect-code">Kode redirect</label>
          <select class="form-select" id="domain-redirect-code" name="redirect_code"
                  aria-describedby="domain-redirect-code-help">
            <option value="301"<?= $redirectCode === 301 ? ' selected' : '' ?>>301 &mdash; permanen</option>
            <option value="302"<?= $redirectCode === 302 ? ' selected' : '' ?>>302 &mdash; sementara</option>
          </select>
          <div class="form-text" id="domain-redirect-code-help">
            301 untuk perpindahan permanen (browser/proxy boleh meng-cache); 302 untuk pengalihan sementara.
          </div>
        </div>
      </div>

      <?php if (!$isEdit): ?>
        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" name="add_www" value="1" id="domain-add-www"
                 <?= $wwwSuggestion === '' ? 'disabled' : '' ?>
                 <?= $addWwwChecked && $wwwSuggestion !== '' ? 'checked' : '' ?>
                 aria-describedby="domain-add-www-help">
          <label class="form-check-label" for="domain-add-www">
            Tambahkan juga varian
            <span class="mono" data-www-target><?= e($wwwSuggestion !== '' ? $wwwSuggestion : 'www.domain') ?></span>
          </label>
          <div class="form-text" id="domain-add-www-help">
            <?php if ($wwwSuggestion !== ''): ?>
              Varian <span class="mono">www</span> dibuat sebagai <strong>entri parkir terpisah</strong> dengan
              mode dan isi yang sama. Keduanya bisa diubah/dihapus sendiri-sendiri setelah dibuat.
            <?php else: ?>
              Isi <strong>Domain</strong> dengan FQDN (tanpa awalan <span class="mono">www.</span>) untuk
              mengaktifkan opsi ini.
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Domain' ?></button>
        <a class="btn btn-outline-secondary" href="/domains">Batal</a>
      </div>
    </form>
  </div>
</div>

<?php if ($isEdit): ?>
<section class="card mt-4" aria-labelledby="domain-ssl-heading">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h6 mb-0" id="domain-ssl-heading">SSL</h2>
    <span class="badge <?= e($sslBadges[$sslStatus] ?? 'badge-stopped') ?>"><?= e($sslStatus) ?></span>
  </div>
  <div class="card-body">
    <dl class="row small mb-0">
      <dt class="col-sm-3">Kedaluwarsa</dt>
      <dd class="col-sm-9<?= $sslExpires === '-' ? ' text-muted' : '' ?>"><?= e($sslExpires) ?></dd>
      <?php if ($sslMessage !== ''): ?>
        <dt class="col-sm-3">Keterangan</dt>
        <dd class="col-sm-9"><?= e($sslMessage) ?></dd>
      <?php endif; ?>
      <?php if ($sslError !== ''): ?>
        <dt class="col-sm-3">Galat</dt>
        <dd class="col-sm-9 text-danger"><?= e($sslError) ?></dd>
      <?php endif; ?>
    </dl>

    <?php if ($sslFormVisible): ?>
      <?php /* Fallback tanpa JS: POST biasa ke route admin; status tampil di halaman Domains. */ ?>
      <form method="post" action="<?= e($sslFormUrl) ?>" class="mt-3 d-flex flex-wrap align-items-center gap-2">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-<?= $sslStatus === 'failed' ? 'outline-danger' : 'primary' ?> btn-sm"><?= $sslStatus === 'failed' ? '↻ Retry SSL' : 'Aktifkan SSL' ?></button>
        <span class="text-muted small">Status diperbarui di halaman Domains.</span>
      </form>
    <?php else: ?>
      <p class="text-muted small mb-0 mt-3">Status diperbarui di halaman Domains.</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  'use strict';

  var form = document.getElementById('domain-form');
  if (!form) {
    return;
  }

  // ---- Mode: tampilkan hanya bagian yang relevan. Tanpa JS, <noscript> di
  // atas menampilkan keduanya dan server memilih berdasarkan radio `mode`.
  var radios = form.querySelectorAll('input[name="mode"]');
  var panels = form.querySelectorAll('[data-domain-panel]');

  function currentMode() {
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) {
        return radios[i].value;
      }
    }
    return 'html';
  }

  function syncMode() {
    var mode = currentMode();
    for (var i = 0; i < panels.length; i++) {
      var panel = panels[i];
      var active = panel.getAttribute('data-domain-panel') === mode;
      panel.hidden = !active;
      var fields = panel.querySelectorAll('input, textarea, select');
      for (var j = 0; j < fields.length; j++) {
        // Field di bagian non-aktif tidak dikirim; controller tetap sumber kebenaran.
        fields[j].disabled = !active;
      }
    }
  }

  for (var r = 0; r < radios.length; r++) {
    radios[r].addEventListener('change', syncMode);
  }
  if (!document.hidden) {
    syncMode();
  }

  // ---- Penghitung ukuran HTML (byte UTF-8) + penolakan file > batas.
  var textarea = document.getElementById('domain-html');
  var counter = document.getElementById('domain-html-count');
  var fileInput = document.getElementById('domain-html-file');
  var fileNote = document.getElementById('domain-html-file-note');
  var fileError = document.getElementById('domain-html-file-error');

  function maxBytes() {
    var raw = textarea ? textarea.getAttribute('data-max-bytes') : '';
    var n = parseInt(raw, 10);
    return n > 0 ? n : 1048576;
  }

  function byteLength(value) {
    if (window.TextEncoder) {
      return new TextEncoder().encode(value).length;
    }
    return unescape(encodeURIComponent(value)).length;
  }

  function sizeLabel(bytes) {
    if (bytes === 1048576) {
      return '1 MiB';
    }
    if (bytes < 1024) {
      return bytes + ' byte';
    }
    if (bytes < 1048576) {
      return (bytes / 1024).toFixed(1).replace('.', ',') + ' KiB';
    }
    return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MiB';
  }

  function updateCount() {
    if (!textarea || !counter) {
      return;
    }
    var bytes = byteLength(textarea.value);
    var max = maxBytes();
    counter.textContent = sizeLabel(bytes) + ' / ' + (max === 1048576 ? '1 MiB' : sizeLabel(max));
    counter.classList.toggle('text-danger', bytes > max);
  }

  if (textarea) {
    textarea.addEventListener('input', updateCount);
    if (!document.hidden) {
      updateCount();
    }
  }

  function clearFileError() {
    if (!fileInput || !fileError) {
      return;
    }
    fileError.textContent = '';
    fileError.classList.add('d-none');
    fileInput.classList.remove('is-invalid');
  }

  function showFileError(message) {
    if (!fileInput || !fileError) {
      return;
    }
    fileError.textContent = message;
    fileError.classList.remove('d-none');
    fileInput.classList.add('is-invalid');
  }

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var max = maxBytes();
      var file = fileInput.files && fileInput.files.length ? fileInput.files[0] : null;

      if (!file) {
        clearFileError();
        if (fileNote) {
          fileNote.textContent = '';
        }
        return;
      }

      if (file.size > max) {
        showFileError('File ' + file.name + ' berukuran ' + sizeLabel(file.size) + ', melebihi batas '
          + (max === 1048576 ? '1 MiB' : sizeLabel(max)) + '. Pilih file yang lebih kecil.');
        fileInput.value = '';
        if (fileNote) {
          fileNote.textContent = '';
        }
        fileInput.focus();
        return;
      }

      clearFileError();
      if (fileNote) {
        fileNote.textContent = 'File dipilih: ' + file.name + ' (' + sizeLabel(file.size)
          + '). Isi textarea akan ditimpa file ini saat disimpan.';
      }
    });
  }

  // Tidak ada polling/timer: semua pembaruan hanya dari event pengguna. Saat tab
  // kembali terlihat, segarkan penghitung agar tidak menampilkan angka basi.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      syncMode();
      updateCount();
    }
  });

  // ---- Saran varian www (create saja). Aturan tampilan mengikuti
  // `ParkedDomainValidator::suggestedWwwVariant()`; validasi tetap di server.
  var www = document.getElementById('domain-add-www');
  var wwwTarget = form.querySelector('[data-www-target]');
  var domainInput = document.getElementById('domain-name');

  if (www && wwwTarget && domainInput) {
    var syncWww = function () {
      var raw = domainInput.value.trim().toLowerCase();
      if (raw.charAt(raw.length - 1) === '.') {
        raw = raw.slice(0, -1);
      }
      var suggestion = (raw === '' || raw.indexOf('www.') === 0) ? '' : 'www.' + raw;

      www.disabled = suggestion === '';
      if (suggestion === '') {
        www.checked = false;
        wwwTarget.textContent = 'www.domain';
      } else {
        wwwTarget.textContent = suggestion;
      }
    };

    domainInput.addEventListener('input', syncWww);
  }
})();
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
