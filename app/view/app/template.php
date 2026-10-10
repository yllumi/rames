<?php $pageTitle = 'Deploy dari Template'; $active = 'apps'; ?>
<?php
// Form deploy satu langkah dari template (SPECS.md §7.2b).
// Port/primary/prefix container TIDAK ditanyakan: host port dicari yang bebas,
// primary diambil dari template, prefix nama container = nama app.
$formEnv = $form_env ?? [];
$formError = $form_error ?? null;
// Subdomain opsional (label, bukan FQDN) — diisi otomatis dari nama app oleh JS
// di bawah; nilai dari server (bila ada) tidak pernah ditimpa.
$formSubdomain = $form_subdomain ?? '';

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Template', 'href' => '/apps/create?mode=template'],
    ['label' => $template['title'], 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">
      <?php if (($template['icon'] ?? '') !== ''): ?><span class="me-1"><?= e($template['icon']) ?></span><?php endif; ?>
      <?= e($template['title']) ?>
    </h1>
    <p class="text-muted small mb-0">
      image <span class="mono"><?= e($template['image'] !== '' ? $template['image'] : '-') ?></span>
      <?php if (($template['primary']['service'] ?? '') !== ''): ?>
      &middot; domain &rarr; <span class="mono"><?= e($template['primary']['service'] . ':' . $template['primary']['port']) ?></span>
      <?php endif; ?>
    </p>
  </div>
  <?php if (($guide_html ?? '') !== ''): ?>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#template-guide-modal">📖 Panduan</button>
  </div>
  <?php endif; ?>
</div>

<?php if (($template['description'] ?? '') !== ''): ?>
<p class="text-muted small"><?= e($template['description']) ?></p>
<?php endif; ?>

<?php if ($formError): ?>
<div class="alert alert-warning" role="alert">
  <strong>Deploy tidak dijalankan:</strong> <?= e($formError) ?>
</div>
<?php endif; ?>

<form method="post" action="/apps/create/template/<?= e($template['slug']) ?>" id="template-deploy-form">
  <?= csrf_field() ?>

  <div class="card mb-3 form-card">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label" for="template-app-name">Nama app (slug)</label>
        <input type="text" class="form-control" id="template-app-name" name="name" value="<?= e($form_name) ?>"
               placeholder="myapp" required style="max-width:360px;">
        <div class="form-text">
          Hanya huruf kecil a-z, angka, dan strip (-). Dipakai sebagai nama project compose, nama direktori,
          dan prefix nama container (<span class="mono">{nama}-{service}</span>).
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="template-subdomain">Subdomain <span class="text-muted small">(opsional)</span></label>
        <input type="text" class="form-control mono" id="template-subdomain" name="subdomain" value="<?= e($formSubdomain) ?>"
               placeholder="myapp-7k2x9p" pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" maxlength="63" autocomplete="off"
               title="Huruf kecil a-z, angka, dan strip (-) saja; tidak boleh diawali/diakhiri strip; maksimal 63 karakter."
               style="max-width:360px;">
        <div class="form-text">
          Kosongkan = pakai nama app. Huruf kecil a-z, angka, dan strip (-) saja; harus unik antar app.
          Dipakai untuk vhost Nginx &amp; sertifikat SSL (<span class="mono">{subdomain}.<?= e((string) config('deploy.app_domain')) ?></span>).
          Terisi otomatis saat Anda mengetik nama app — boleh diubah.
        </div>
      </div>

      <?php if (($template['ports'] ?? []) !== []): ?>
      <div class="alert alert-secondary small mb-0" role="alert">
        Port container template: <span class="mono"><?= e(implode(', ', array_map('strval', $template['ports']))) ?></span>.
        Host port dicari otomatis yang masih bebas (rentang <span class="mono"><?= e((string) config('deploy.port_range.start')) ?>–<?= e((string) config('deploy.port_range.end')) ?></span>) —
        setelah app jalan, port bisa dilihat di halaman detail app.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (($template['env'] ?? []) !== []): ?>
  <div class="card mb-3 form-card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <h2 class="h6 mb-1">Variabel environment</h2>
          <p class="text-muted small mb-0">
            Nilai ditulis ke file env milik app (<span class="mono">database/env/&lt;nama app&gt;.env</span>, chmod 0600)
            dan di-inject ke seluruh service. Kosongkan untuk memakai nilai default / dibuat otomatis.
          </p>
        </div>
      </div>

      <?php foreach ($template['env'] as $spec): ?>
      <?php $rawPosted = $formEnv[$spec['key']] ?? ''; $posted = is_scalar($rawPosted) ? (string) $rawPosted : ''; ?>
      <div class="mb-3">
        <label class="form-label" for="env-<?= e($spec['key']) ?>">
          <?= e($spec['label']) ?>
          <span class="text-muted small mono"><?= e($spec['key']) ?></span>
          <?php if ($spec['required']): ?>
          <span class="badge text-bg-danger">wajib</span>
          <?php endif; ?>
        </label>
        <input type="<?= $spec['secret'] ? 'password' : 'text' ?>"
               class="form-control mono" id="env-<?= e($spec['key']) ?>"
               name="env[<?= e($spec['key']) ?>]"
               value="<?= e($posted) ?>"
               <?= $spec['secret'] ? 'autocomplete="new-password"' : '' ?>
               <?= $spec['required'] ? 'required' : '' ?>
               style="max-width:480px;">
        <div class="form-text">
          <?php if (($spec['help'] ?? '') !== ''): ?><?= e($spec['help']) ?><?php endif; ?>
          <?php if ($spec['generate']): ?>
          Kosongkan untuk <strong>dibuat otomatis</strong> (nilai acak) — nilainya bisa dilihat/diubah di tab Environment app.
          <?php elseif ($spec['default'] !== null): ?>
          Default: <span class="mono"><?= e((string) $spec['default']) ?></span>.
          <?php elseif ($spec['required']): ?>
          Wajib diisi (tidak ada nilai default).
          <?php else: ?>
          Opsional.
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // Batas CPU/memori opsional saat create dari template — owner/member boleh
  // memilih (D2=a) dalam plafon billing; admin bebas plafon. Gerbang kartu
  // memakai `canManage` dari AppController, bukan `is_admin()` langsung.
  $limitsCtx = is_array($resourceLimits ?? null) ? $resourceLimits : [];
  $limitsServices = [];
  foreach ((array) ($limitsCtx['services'] ?? []) as $limitsService) {
      $limitsService = trim((string) $limitsService);
      if ($limitsService !== '' && !in_array($limitsService, $limitsServices, true)) {
          $limitsServices[] = $limitsService;
      }
  }
  $limitsRepo = is_array($limitsCtx['repo'] ?? null) ? $limitsCtx['repo'] : [];
  // Plafon skala slider (dari controller). Aman bila konteks absen: pakai default.
  $limitsScaleMaxCpus = (float) (($limitsScaleMax ?? [])['cpus'] ?? 4.0);
  $limitsScaleMaxMemory = (int) (($limitsScaleMax ?? [])['memory_mb'] ?? 8192);
  $limitsCanManage = (bool) ($limitsCtx['canManage'] ?? false);
  // Konteks billing baca-saja (estimasi & saldo) — penegak tetap Pricing/BillingGate.
  $billingCtx = is_array($billing ?? null) ? $billing : [];
  $billingMember = !empty($billingCtx['enabled']) && !empty($billingCtx['member']);
  $billingCaps = is_array($billingCtx['caps'] ?? null) ? $billingCtx['caps'] : [];
  $billingDefaults = is_array($billingCtx['defaults'] ?? null) ? $billingCtx['defaults'] : [];
  $billingRates = is_array($billingCtx['rates'] ?? null) ? $billingCtx['rates'] : [];
  ?>
  <?php if ($limitsCanManage && $limitsServices !== []): ?>
  <div class="card mb-3 form-card">
    <div class="card-body">
      <h2 class="h6 mb-1">Batas Sumber Daya (opsional)</h2>
      <?php if ($billingMember): ?>
      <p class="text-muted small mb-3">
        Pilih CPU &amp; memori per service dengan penggeser. Posisi paling kiri = nilai default akun
        (<span class="mono"><?= e((string) ($billingDefaults['cpus'] ?? '')) ?> core</span> /
        <span class="mono"><?= e((string) ($billingDefaults['memory_mb'] ?? '')) ?> MB</span> per service).
        Skala CPU: 0.5 core lalu naik 1 core (0.5 · 1 · 2 · 3 …); memori: 512 MB lalu naik 1 GB (512 · 1024 · 2048 …).
        Plafon: maksimum <span class="mono"><?= e((string) ($billingCaps['cpus'] ?? '')) ?> core</span> &amp;
        <span class="mono"><?= e((string) ($billingCaps['memory_mb'] ?? '')) ?> MB</span> per service.
      </p>
      <?php else: ?>
      <p class="text-muted small mb-3">
        Geser paling kiri = ikut pengaturan compose repo / tanpa batas.
        Skala CPU: 0.5 core lalu naik 1 core (0.5 · 1 · 2 · 3 …); memori: 512 MB lalu naik 1 GB (512 · 1024 · 2048 …),
        batas atas = kapasitas host. Hanya admin dapat mengubah.
      </p>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th style="width:30%;">Service</th>
              <th>CPU (core)</th>
              <th>Memori (MB)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($limitsServices as $limitsService): ?>
            <?php
              $limitRepo = is_array($limitsRepo[$limitsService] ?? null) ? $limitsRepo[$limitsService] : [];
              $cpuRepo = $limitRepo['cpus'] ?? null;
              $memRepo = $limitRepo['memory_mb'] ?? null;
              $limitsId = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $limitsService);
            ?>
            <tr>
              <td><span class="mono"><?= e($limitsService) ?></span></td>
              <td>
                <?php
                  // Slider: nilai nyata disimpan di input hidden bernama `limits[...]` oleh
                  // public/js/limits-scale.js; posisi 0 = tanpa batas / default akun.
                  $cpuScale = \app\library\Deploy\ResourceLimits::cpuScale($limitsScaleMaxCpus, $cpuRepo === null ? null : (float) $cpuRepo);
                  // Prefill di luar skala (mis. > plafon / nilai tak sah) jatuh ke posisi
                  // "tanpa batas / default" agar posisi slider, label, dan nilai kirim konsisten.
                  $cpuValue = $cpuScale['value'];
                  $cpuUnset = $billingMember
                      ? 'default (' . (string) ($billingDefaults['cpus'] ?? '') . ' core)'
                      : 'tanpa batas';
                ?>
                <div class="limit-slider" data-limit-group style="max-width:220px;">
                  <input type="range" class="form-range" min="0" max="<?= count($cpuScale['stops']) - 1 ?>" step="1"
                         value="<?= $cpuScale['index'] ?>"
                         id="limit-cpus-<?= e($limitsId) ?>-slider"
                         data-limit-slider
                         data-limit-target="limit-cpus-<?= e($limitsId) ?>"
                         data-limit-stops="<?= e((string) json_encode($cpuScale['stops'])) ?>"
                         data-limit-unit="core"
                         data-limit-unset="<?= e($cpuUnset) ?>"
                         aria-label="CPU (core) service <?= e($limitsService) ?>">
                  <input type="hidden" id="limit-cpus-<?= e($limitsId) ?>"
                         name="limits[<?= e($limitsService) ?>][cpus]"
                         value="<?= $cpuValue !== null ? e((string) $cpuValue) : '' ?>">
                  <div class="form-text mb-0"><span class="mono" data-limit-display><?= $cpuValue !== null ? e((string) $cpuValue) . ' core' : e($cpuUnset) ?></span></div>
                  <?php if ($cpuValue !== null && $cpuRepo !== null): ?>
                  <div class="form-text mb-0" data-limit-repo-note>nilai dari compose repo</div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php
                  $memScale = \app\library\Deploy\ResourceLimits::memoryScale($limitsScaleMaxMemory, $memRepo === null ? null : (int) $memRepo);
                  $memValue = $memScale['value'];
                  $memUnset = $billingMember
                      ? 'default (' . (string) ($billingDefaults['memory_mb'] ?? '') . ' MB)'
                      : 'tanpa batas';
                ?>
                <div class="limit-slider" data-limit-group style="max-width:220px;">
                  <input type="range" class="form-range" min="0" max="<?= count($memScale['stops']) - 1 ?>" step="1"
                         value="<?= $memScale['index'] ?>"
                         id="limit-memory-<?= e($limitsId) ?>-slider"
                         data-limit-slider
                         data-limit-target="limit-memory-<?= e($limitsId) ?>"
                         data-limit-stops="<?= e((string) json_encode($memScale['stops'])) ?>"
                         data-limit-unit="MB"
                         data-limit-unset="<?= e($memUnset) ?>"
                         aria-label="Memori (MB) service <?= e($limitsService) ?>">
                  <input type="hidden" id="limit-memory-<?= e($limitsId) ?>"
                         name="limits[<?= e($limitsService) ?>][memory_mb]"
                         value="<?= $memValue !== null ? e((string) $memValue) : '' ?>">
                  <div class="form-text mb-0"><span class="mono" data-limit-display><?= $memValue !== null ? e((string) $memValue) . ' MB' : e($memUnset) ?></span></div>
                  <?php if ($memValue !== null && $memRepo !== null): ?>
                  <div class="form-text mb-0" data-limit-repo-note>nilai dari compose repo</div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <script src="/js/limits-scale.js?v=1"></script>
      <?php if ($billingMember): ?>
      <div class="border rounded p-3 mt-3" id="limit-estimate"
           data-cpu-rate="<?= e((string) ($billingRates['cpu'] ?? '')) ?>"
           data-ram-rate="<?= e((string) ($billingRates['ram'] ?? '')) ?>"
           data-default-cpus="<?= e((string) ($billingDefaults['cpus'] ?? '')) ?>"
           data-default-memory="<?= e((string) ($billingDefaults['memory_mb'] ?? '')) ?>"
           data-days="<?= e((string) ($billingCtx['days'] ?? 30)) ?>"
           data-balance="<?= e((string) ($billingCtx['balance'] ?? 0)) ?>">
        <h3 class="h6 mb-2">Estimasi biaya kredit</h3>
        <div class="row g-2 small mb-2">
          <div class="col-sm-4">
            Per jam<br>
            <strong class="mono" id="limit-estimate-hourly"><?= e((string) ($billingCtx['estimate_hourly_text'] ?? '0.00')) ?></strong> kredit
          </div>
          <div class="col-sm-4">
            <?= (int) ($billingCtx['days'] ?? 30) ?> hari<br>
            <strong class="mono" id="limit-estimate-month"><?= e((string) ($billingCtx['estimate_month_text'] ?? '0.00')) ?></strong> kredit
          </div>
          <div class="col-sm-4">
            Deposit minimum<br>
            <strong class="mono" id="limit-estimate-required"><?= e((string) ($billingCtx['required_text'] ?? '0.00')) ?></strong> kredit
          </div>
        </div>
        <p class="small mb-2">
          Saldo Anda saat ini: <strong class="mono" id="limit-estimate-balance"><?= e((string) ($billingCtx['balance_text'] ?? '0.00')) ?></strong> kredit.
        </p>
        <div class="alert alert-warning py-2 small mb-2<?= !empty($billingCtx['sufficient']) ? ' d-none' : '' ?>" id="limit-estimate-warning" role="alert">
          Saldo kredit Anda <strong>kurang</strong> dari deposit minimum — permintaan deploy akan ditolak server.
          <?php if (!empty($billingCtx['topup_enabled'])): ?>
          Lakukan top-up di <a href="/credits">halaman Kredit</a>.
          <?php else: ?>
          Hubungi admin untuk menambah saldo di <a href="/credits">halaman Kredit</a>.
          <?php endif; ?>
        </div>
        <p class="text-muted small mb-0">
          Tarif <span class="mono"><?= e((string) ($billingRates['cpu'] ?? '')) ?></span> kredit/core-jam +
          <span class="mono"><?= e((string) ($billingRates['ram'] ?? '')) ?></span> kredit/GB-jam.
          Angka di layar hanya bantuan — perhitungan penegak tetap di server (<span class="mono">Pricing</span>/<span class="mono">BillingGate</span>).
        </p>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="alert alert-secondary small" role="alert">
    Deploy dijalankan langsung (tanpa halaman konfirmasi port): host port diresolusi otomatis,
    prefix nama container = nama app, dan app dibuat sebagai app mode <strong>compose</strong> —
    sumbernya bisa diubah kapan saja lewat tab <strong>Compose</strong> di detail app, lalu <em>Deploy Ulang</em>.
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary" id="deploy-btn">
      <span class="spinner-border spinner-border-sm d-none" id="deploy-btn-spinner" role="status" aria-hidden="true"></span>
      Deploy
    </button>
    <a class="btn btn-outline-secondary" href="/apps/create?mode=template">Batal</a>
  </div>
</form>

<div id="deploy-error" class="alert alert-danger d-none mt-3" role="alert"></div>

<script>
// Estimasi biaya kredit saat user mengetik limit — lapisan bantuan saja.
// Perhitungan penegak tetap `Pricing`/`BillingGate` di PHP (server).
(function () {
  var panel = document.getElementById('limit-estimate');
  if (!panel) return;

  var cpuRate = parseFloat(panel.dataset.cpuRate) || 0;
  var ramRate = parseFloat(panel.dataset.ramRate) || 0;
  var defCpus = parseFloat(panel.dataset.defaultCpus) || 0;
  var defMem = parseFloat(panel.dataset.defaultMemory) || 0;
  var days = parseFloat(panel.dataset.days) || 30;
  var balance = parseFloat(panel.dataset.balance) || 0;

  var hourlyEl = document.getElementById('limit-estimate-hourly');
  var monthEl = document.getElementById('limit-estimate-month');
  var requiredEl = document.getElementById('limit-estimate-required');
  var warningEl = document.getElementById('limit-estimate-warning');

  var inputs = document.querySelectorAll('input[name^="limits["]');
  if (!inputs.length) return;

  function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2); }

  function sumHourly() {
    var rows = {};
    Array.prototype.forEach.call(inputs, function (input) {
      var m = /^limits\[(.+)\]\[(cpus|memory_mb)\]$/.exec(input.getAttribute('name') || '');
      if (!m) return;
      if (!rows[m[1]]) rows[m[1]] = {};
      rows[m[1]][m[2]] = input.value;
    });

    var total = 0;
    Object.keys(rows).forEach(function (service) {
      var row = rows[service];
      var cpus = parseFloat(row.cpus);
      var mem = parseFloat(row.memory_mb);
      if (isNaN(cpus)) cpus = defCpus;
      if (isNaN(mem)) mem = defMem;
      total += cpus * cpuRate + (mem / 1024) * ramRate;
    });
    return total;
  }

  function refresh() {
    var hourly = sumHourly();
    var month = hourly * 24 * days;
    panel.dataset.required = fmt(month);
    if (hourlyEl) hourlyEl.textContent = fmt(hourly);
    if (monthEl) monthEl.textContent = fmt(month);
    if (requiredEl) requiredEl.textContent = fmt(month);
    if (warningEl) warningEl.classList.toggle('d-none', balance >= month);
  }

  Array.prototype.forEach.call(inputs, function (input) {
    input.addEventListener('input', refresh);
    input.addEventListener('change', refresh);
  });
  refresh();
})();

(function () {
  var form = document.getElementById('template-deploy-form');
  var btn = document.getElementById('deploy-btn');
  var spinner = document.getElementById('deploy-btn-spinner');
  var errorBox = document.getElementById('deploy-error');

  if (!form) return;

  function showError(msg) {
    if (errorBox) {
      errorBox.textContent = msg;
      errorBox.classList.remove('d-none');
    }
    if (btn) btn.disabled = false;
    if (spinner) spinner.classList.add('d-none');
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (errorBox) errorBox.classList.add('d-none');
    if (btn) btn.disabled = true;
    if (spinner) spinner.classList.remove('d-none');

    fetch(form.action, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new FormData(form)
    }).then(function (r) {
      return r.json().then(function (d) {
        return { ok: r.ok, status: r.status, data: d };
      }).catch(function () {
        return { ok: r.ok, status: r.status, data: null };
      });
    }).then(function (res) {
      var d = res.data || {};
      if (res.ok && d.code === 0 && d.id) {
        window.location.href = '/apps/' + d.id;
        return;
      }
      var msg = d.error || d.msg;
      if (!msg) {
        msg = res.status === 419
          ? 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.'
          : 'Gagal memulai deploy. Silakan coba lagi.';
      }
      showError(msg);
      if (res.status === 419) setTimeout(function () { window.location.reload(); }, 1500);
    }).catch(function () {
      showError('Gagal terhubung ke server. Periksa koneksi lalu coba lagi.');
    });
  });
})();

// Isi otomatis field subdomain dari nama app: `{nama}-{6 karakter acak}`.
// Hanya saat user mengetik nama (tidak ada prefill saat halaman dimuat) dan
// berhenti begitu field subdomain disentuh user (flag dirty per-form).
// Hasil selalu lolos `pattern` (batas server `app_subdomain_valid()`).
(function () {
  var name = document.getElementById('template-app-name');
  var sub = document.getElementById('template-subdomain');
  if (!name || !sub) return;

  var MAX_LENGTH = 63;
  var SUFFIX_LENGTH = 6;

  function randomSuffix() {
    var s = Math.random().toString(36).slice(2, 8);
    while (s.length < SUFFIX_LENGTH) { s += Math.floor(Math.random() * 36).toString(36); }
    return s.slice(0, SUFFIX_LENGTH);
  }

  function slugify(value) {
    return String(value || '').trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
  }

  // `{nama}-{rand6}`: bagian acak tidak pernah dipotong — nama yang dipangkas
  // lebih dulu (sisakan ruang '-' + 6 karakter) lalu strip ekor dibuang.
  function buildSubdomain(nameValue) {
    var base = slugify(nameValue);
    if (base === '') return '';
    var suffix = randomSuffix();
    base = base.slice(0, MAX_LENGTH - 1 - suffix.length).replace(/-+$/g, '');
    return base === '' ? suffix : base + '-' + suffix;
  }

  var dirty = sub.value !== '';
  name.addEventListener('input', function () {
    if (dirty) return;
    sub.value = buildSubdomain(name.value);
  });
  sub.addEventListener('input', function () { dirty = true; });
})();
</script>

<?php if (($guide_html ?? '') !== ''): ?>
<!-- Modal panduan: HTML hasil sanitasi Markdown::toHtml() — sengaja dirender tanpa e(). -->
<div class="modal fade" id="template-guide-modal" tabindex="-1" aria-labelledby="template-guide-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 mb-0" id="template-guide-title">Panduan: <?= e($template['title']) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="guide-content"><?= $guide_html ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
