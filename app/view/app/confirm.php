<?php $pageTitle = 'Confirm App'; $active = 'apps'; ?>
<?php $pendingSource = ($pending['source'] ?? 'git') === 'compose' ? 'compose' : 'git'; ?>
<?php
// Pemilih server tujuan (multi-server: SPECS.md §2a, ARCHITECTURE.md §5.22).
// Payload dari `AppController::serverFormContext()`: `servers`,
// `selectedServerId`, `canSelectServer`. View hanya merender — server tetap
// sumber kebenaran (`confirmCreate()` memvalidasi ulang `server_id`).
// Nilai teks aman dari payload: hanya scalar yang dipakai (array/objek
// diabaikan) supaya view tidak pernah warning karena data registry rusak.
$wizardText = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';

$wizardServers = is_array($servers ?? null) ? array_values(array_filter($servers, 'is_array')) : [];
$wizardSelectedServerId = $wizardText($selectedServerId ?? '');
if ($wizardSelectedServerId === '') {
    $wizardSelectedServerId = \app\library\Storage\ServerStore::LOCAL_ID;
}
// Halaman konfirmasi tidak merender selektor server, tetapi flag ini dipakai
// sebagai penjaga ringkasan read-only: bila `false`, identitas server non-lokal
// dari payload tidak boleh tampil (defense-in-depth, misi Frontend UI).
$wizardCanSelectServer = (bool) ($canSelectServer ?? false);

// Navigasi wizard mempertahankan pilihan server (SPECS.md §2a). Parameter
// `server_id` hanya ditambahkan bila server terpilih BUKAN server lokal **dan**
// user memang boleh memilih server (`$wizardCanSelectServer`) — supaya URL
// default/non-admin tetap bersih (absen = server lokal) dan payload di luar
// kontrak tidak membocorkan id server agent lewat `href`.
$wizardNavServerParam = ($wizardCanSelectServer
    && $wizardSelectedServerId !== ''
    && strtolower($wizardSelectedServerId) !== \app\library\Storage\ServerStore::LOCAL_ID)
    ? 'server_id=' . rawurlencode($wizardSelectedServerId)
    : '';
// Menempelkan parameter tanpa merusak query yang sudah ada (`?mode=…`); hasil
// tetap di-`e()` di atribut href, jadi `&` di sini menjadi `&amp;`.
$wizardNavUrl = static function (string $path) use ($wizardNavServerParam): string {
    if ($wizardNavServerParam === '') {
        return $path;
    }

    return $path . (str_contains($path, '?') ? '&' : '?') . $wizardNavServerParam;
};
// Ringkasan server terpilih (read-only di langkah konfirmasi).
$wizardSelectedServer = null;
foreach ($wizardServers as $wizardServer) {
    if ($wizardText($wizardServer['id'] ?? '') !== $wizardSelectedServerId) {
        continue;
    }
    $wizardIsLocal = strtolower($wizardText($wizardServer['kind'] ?? '')) === 'local';
    $wizardServerName = $wizardText($wizardServer['name'] ?? '');
    $wizardServerStatus = strtolower($wizardText($wizardServer['status'] ?? ''));
    $wizardServerStatus = $wizardServerStatus !== '' ? $wizardServerStatus : 'unknown';
    $wizardSelectedServer = [
        'name' => $wizardServerName !== '' ? $wizardServerName : $wizardSelectedServerId,
        'domain' => $wizardText($wizardServer['domain'] ?? ''),
        'local' => $wizardIsLocal,
        'status' => $wizardServerStatus,
        // Kelas badge baku repo (bukan dirakit dari data server).
        'badge' => $wizardServerStatus === 'online'
            ? 'badge-running'
            : ($wizardServerStatus === 'offline' ? 'badge-error' : 'badge-unknown'),
    ];
    break;
}
// Proyeksi server lokal (turunan murni, tanpa logika bisnis): nilai aman untuk
// ringkasan read-only saat identitas server terpilih tidak boleh/tidak bisa
// ditampilkan.
$wizardLocalServerProjection = [
    'name' => 'lokal',
    'domain' => '',
    'local' => true,
    'status' => 'unknown',
    'badge' => 'badge-unknown',
];
if ($wizardSelectedServer === null) {
    // Payload server absen/kosong (varian render lama): tetap aman — ringkasan
    // read-only tanpa nama server asing.
    $wizardSelectedServer = $wizardLocalServerProjection;
}
if (!$wizardCanSelectServer && !$wizardSelectedServer['local']) {
    // Payload di luar kontrak (`canSelectServer=false` + `selectedServerId`
    // menunjuk server agent): ringkasan konfirmasi tidak boleh memuat
    // nama/domain server non-lokal. Pertahanan berlapis di sisi view — server
    // tetap memvalidasi ulang `server_id` saat POST (`confirmCreate()`).
    $wizardSelectedServer = $wizardLocalServerProjection;
}
?>
<?php
$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Buat App', 'href' => $wizardNavUrl('/apps/create')],
    ['label' => 'Konfirmasi', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Konfirmasi Deploy</h1>
    <?php if ($pendingSource === 'compose'): ?>
      <p class="text-muted small mb-0">
        Sumber: <strong>file compose</strong> (tanpa repo Git) · compose: <span class="mono"><?= e($pending['compose_file']) ?></span> · <?= e($pending['local_path']) ?>
      </p>
    <?php else: ?>
      <p class="text-muted small mb-0">Repo: <span class="mono"><?= e($pending['repo_url']) ?></span> · branch <span class="mono"><?= e($pending['branch']) ?></span> · <?= e($pending['local_path']) ?> · compose: <span class="mono"><?= e($pending['compose_file']) ?></span></p>
    <?php endif; ?>
  </div>
</div>

<form method="post" action="/apps/create/confirm" id="deploy-confirm-form">
  <?= csrf_field() ?>

  <?php
  // Server yang dipilih di langkah sebelumnya diteruskan apa adanya: controller
  // membaca POST `server_id` (fallback `pending['server_id']`). Id `local`
  // sengaja tetap dikirim eksplisit — nilainya dinormalkan kembali ke server
  // lokal, jadi kontrak POST lama tidak berubah.
  ?>
  <input type="hidden" name="server_id" value="<?= e($wizardSelectedServerId) ?>">

  <!-- Nama container (override container_name) — opsional -->
  <div class="card mb-3">
    <div class="card-body">
      <label class="form-label" for="container-prefix">Prefix nama container <span class="text-muted small">(opsional)</span></label>
      <input type="text" class="form-control form-control-sm mono" id="container-prefix" name="container_prefix"
             maxlength="<?= e((string) \app\library\Deploy\ContainerNames::MAX_PREFIX_LENGTH) ?>"
             placeholder="kosong = <?= e($pending['name'] . '_<service>_1') ?>" style="max-width:320px;">
      <div class="form-text">
        Bila diisi, setiap service memakai nama container <span class="mono">{prefix}-{service}</span>
        (mis. <span class="mono"><?= e($pending['name'] . '-web') ?></span>) — bukan <span class="mono">&lt;app&gt;_&lt;service&gt;_1</span>.
        Nama container <strong>unik se-host</strong>: dicek ke container lain saat deploy, dan tidak bisa dipakai
        service yang memakai replica (<span class="mono">deploy.replicas</span>).
      </div>
    </div>
  </div>

  <?php
  // Ringkasan server tujuan (read-only) — server dipilih di langkah sebelumnya
  // dan dikirim lewat hidden input di atas; di sini hanya ditampilkan supaya
  // user tahu app akan dibuat di mana (SPECS §2a).
  ?>
  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6 mb-2">Server tujuan</h2>
      <dl class="row mb-2 small">
        <dt class="col-sm-3">Nama</dt>
        <dd class="col-sm-9 mb-1">
          <strong><?= e($wizardSelectedServer['name']) ?></strong>
          <?php if (!$wizardSelectedServer['local']): ?>
          <span class="badge <?= e($wizardSelectedServer['badge']) ?> ms-1"><?= e($wizardSelectedServer['status']) ?></span>
          <?php endif; ?>
        </dd>
        <dt class="col-sm-3">Jenis</dt>
        <dd class="col-sm-9 mb-1"><?= $wizardSelectedServer['local'] ? 'lokal (dashboard ini)' : 'agent (server terdaftar)' ?></dd>
        <dt class="col-sm-3">Domain</dt>
        <dd class="col-sm-9 mb-0 mono"><?= e($wizardSelectedServer['domain'] !== '' ? $wizardSelectedServer['domain'] : '-') ?></dd>
      </dl>
      <p class="text-muted small mb-0">
        Server tujuan diambil dari langkah sebelumnya — ubah lewat tombol <strong>Kembali</strong>.
      </p>
    </div>
  </div>

  <div class="card mb-3">
    <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>Service</th>
          <th>Container Port</th>
          <th>Host Port (edit)</th>
          <th>Di-proxy ke domain</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending['services'] as $svcName => $svc): ?>
        <?php $svcPorts = array_values((array) ($svc['ports'] ?? [])); ?>
        <?php if ($svcPorts === []): ?>
        <tr>
          <td>
            <strong class="mono"><?= e($svcName) ?></strong>
            <span class="text-muted small">(internal)</span>
          </td>
          <td class="mono text-muted">&mdash;</td>
          <td><span class="text-muted">&mdash;</span></td>
          <td><span class="text-muted">&mdash;</span></td>
        </tr>
        <?php else: ?>
        <?php foreach ($svcPorts as $i => $p): ?>
        <?php
          $cp = (string) ($p['container'] ?? '');
          $radioId = 'primary-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $svcName . '-' . $cp);
          $isChecked = ($pending['primary_service'] ?? '') === $svcName
              && (int) ($pending['primary_port'] ?? 0) === (int) $cp;
        ?>
        <tr>
          <td>
            <?php if ($i === 0): ?>
              <strong class="mono"><?= e($svcName) ?></strong>
            <?php else: ?>
              <span class="text-muted small ms-2">&crarr; port lain</span>
            <?php endif; ?>
          </td>
          <td class="mono text-muted"><?= e($cp !== '' ? $cp : '-') ?></td>
          <td>
            <input type="number" class="form-control form-control-sm port-input"
                   name="services[<?= e($svcName) ?>][ports][<?= e($cp !== '' ? $cp : (string) $i) ?>][host_port]"
                   value="<?= e($p['host'] ?? '') ?>" min="1" max="65535" required style="max-width:160px;">
          </td>
          <td>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="primary" value="<?= e($svcName . ':' . $cp) ?>" id="<?= e($radioId) ?>"
                     <?= $isChecked ? 'checked' : '' ?>>
              <label class="form-check-label" for="<?= e($radioId) ?>">Trafik domain</label>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php
  // Environment variable: template dideteksi di controller dari `.env.example`
  // repo (prioritas) atau referensi ${VAR} docker compose. Nilai default sudah
  // di-prefill; user bisa mengubahnya di sini sebelum deploy. `$envDraft` berisi
  // isian sebelumnya bila create gagal dan halaman ini dirender ulang.
  $envTpl = is_array($envTemplate ?? null) ? $envTemplate : [];
  $envSource = (string) ($envTpl['source'] ?? 'none');
  $envSpecs = is_array($envTpl['vars'] ?? null) ? $envTpl['vars'] : [];
  $envDraftVars = is_array($envDraft ?? null) ? $envDraft : [];
  ?>
  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6 mb-1">Environment Variables</h2>
      <p class="text-muted small mb-3">
        <?php if ($envSource === 'env-example'): ?>
          Key &amp; nilai contoh diambil dari <span class="mono">.env.example</span> repo — sesuaikan sebelum deploy.
          Variabel yang dipakai compose tanpa nilai default ditandai <strong>wajib</strong> (termasuk yang belum tercantum di contoh).
        <?php elseif ($envSource === 'compose'): ?>
          Variabel diambil dari referensi <span class="mono">${VAR}</span> di
          <span class="mono"><?= e($pending['compose_file']) ?></span>; nilai default compose sudah diisi.
        <?php else: ?>
          Repo tidak punya <span class="mono">.env.example</span> dan compose tidak memakai variabel
          <span class="mono">${VAR}</span>. Tambahkan variabel bila app memerlukannya.
        <?php endif; ?>
        Nilai ditulis ke file env app (<span class="mono">database/env/&lt;nama app&gt;.env</span>, chmod 0600)
        dan di-inject ke seluruh service. Kosongkan bila ingin memakai default compose.
      </p>

      <div class="table-responsive">
        <table class="table align-middle mb-3" id="create-env-table">
          <thead>
            <tr>
              <th style="width:42%;">Key</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($envSpecs as $envSpec): ?>
            <?php
              if (!is_array($envSpec)) {
                  continue;
              }
              $envKey = is_scalar($envSpec['key'] ?? null) ? (string) $envSpec['key'] : '';
              if ($envKey === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envKey) !== 1) {
                  continue;
              }
              $envRequired = !empty($envSpec['required']);
              $rawDraftValue = $envDraftVars[$envKey] ?? null;
              $envValue = is_scalar($rawDraftValue)
                  ? (string) $rawDraftValue
                  : (is_scalar($envSpec['value'] ?? null) ? (string) $envSpec['value'] : '');
              $envDefault = is_scalar($envSpec['default'] ?? null) ? (string) $envSpec['default'] : null;
            ?>
            <tr>
              <td>
                <span class="mono"><?= e($envKey) ?></span>
                <?php if ($envRequired): ?><span class="badge text-bg-danger ms-1">wajib</span><?php endif; ?>
                <?php if ($envDefault !== null): ?>
                <div class="form-text">default compose: <span class="mono"><?= e($envDefault) ?></span></div>
                <?php endif; ?>
              </td>
              <td>
                <div class="input-group input-group-sm" style="max-width:520px;">
                  <input type="password" name="env[<?= e($envKey) ?>]" value="<?= e($envValue) ?>"
                         class="form-control mono env-value" autocomplete="off"
                         placeholder="<?= $envRequired ? 'wajib diisi' : 'opsional' ?>"
                         <?= $envRequired ? 'required' : '' ?>>
                  <button type="button" class="btn btn-outline-secondary env-reveal" tabindex="-1" title="Tampilkan / sembunyikan nilai">👁</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <button type="button" class="btn btn-outline-secondary btn-sm" id="create-env-add-row">＋ Tambah variabel</button>
      <p class="form-text mb-0 mt-2">
        Variabel tambahan juga bisa diubah kapan saja di tab <strong>Environment</strong> halaman detail app.
      </p>
    </div>
  </div>

  <script>
  (function () {
    var table = document.getElementById('create-env-table');
    var addBtn = document.getElementById('create-env-add-row');
    if (!table || !addBtn) return;

    var rowIndex = 0;
    addBtn.addEventListener('click', function () {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input type="text" name="env_extra[' + rowIndex + '][key]" class="form-control form-control-sm mono" placeholder="APP_KEY"></td>' +
        '<td><div class="input-group input-group-sm" style="max-width:520px;">' +
          '<input type="password" name="env_extra[' + rowIndex + '][value]" class="form-control mono env-value" placeholder="nilai" autocomplete="off">' +
          '<button type="button" class="btn btn-outline-secondary env-reveal" tabindex="-1" title="Tampilkan / sembunyikan nilai">👁</button>' +
        '</div></td>';
      table.querySelector('tbody').appendChild(tr);
      rowIndex++;
    });

    // Reveal/sembunyikan nilai (delegasi — ikut berlaku untuk baris baru).
    table.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.env-reveal') : null;
      if (!btn) return;
      var group = btn.parentElement;
      var input = group ? group.querySelector('.env-value') : null;
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      btn.textContent = input.type === 'password' ? '👁' : '🙈';
    });
  })();
  </script>

  <?php
  // Batas CPU/memori opsional saat create — owner/member boleh memilih (D2=a),
  // dibatasi plafon billing (divalidasi `Pricing::assertWithinCaps()` di server).
  // Gerbang kartu memakai flag `canManage` dari AppController (bukan `is_admin()`
  // langsung) — admin tetap `canManage=true` dan tidak dibatasi plafon.
  $limitsCtx = is_array($resourceLimits ?? null) ? $resourceLimits : [];
  $limitsServices = [];
  foreach ((array) ($limitsCtx['services'] ?? []) as $limitsService) {
      $limitsService = trim((string) $limitsService);
      if ($limitsService !== '' && !in_array($limitsService, $limitsServices, true)) {
          $limitsServices[] = $limitsService;
      }
  }
  if ($limitsServices === []) {
      foreach (array_keys((array) ($pending['services'] ?? [])) as $limitsService) {
          $limitsService = trim((string) $limitsService);
          if ($limitsService !== '' && !in_array($limitsService, $limitsServices, true)) {
              $limitsServices[] = $limitsService;
          }
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
  <div class="card mb-3">
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
          Saldo kredit Anda <strong>kurang</strong> dari deposit minimum — permintaan create akan ditolak server.
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

  <?php
  // App tanpa port terpublish tidak dibuatkan vhost/subdomain (SPECS §7.2) —
  // jelaskan supaya user tidak mengira port-nya hilang.
  $pendingHasPorts = false;
  foreach ((array) ($pending['services'] ?? []) as $pendingSvc) {
      if (!empty($pendingSvc['ports'])) {
          $pendingHasPorts = true;
          break;
      }
  }
  ?>
  <?php if ($pendingHasPorts): ?>
  <p class="text-muted small">
    Port yang berkonflik sudah otomatis diganti dari rentang <code><?= e(config('deploy.port_range.start')) ?>–<?= e(config('deploy.port_range.end')) ?></code>. Sesuaikan bila perlu.
    Pilih <strong>satu</strong> port yang menerima trafik domain app (subdomain / custom domain) — port lain
    tetap dipublikasikan ke host port-nya masing-masing dan bisa diakses langsung via <span class="mono">http://&lt;host&gt;:&lt;port&gt;</span>.
  </p>
  <?php else: ?>
  <p class="text-muted small">
    Tidak ada service yang mem-publikasikan port (<span class="mono">ports:</span>), jadi app ini dibuat
    <strong>tanpa vhost &amp; subdomain</strong> — Nginx tidak di-proxy ke app dan port tidak diteruskan ke host.
    Tambahkan <span class="mono">ports:</span> pada compose lalu Deploy Ulang bila app perlu diakses lewat domain atau dari host.
  </p>
  <?php endif; ?>

  <?php
  // Ringkasan subdomain efektif (read-only). Sengaja TIDAK ada field POST:
  // confirmCreate() membaca session `pending_app`, sehingga field baru akan
  // diabaikan (menyesatkan). Subdomain bisa diubah setelah app dibuat di tab
  // Domain & SSL halaman detail.
  $confirmSubdomain = (string) ($subdomain ?? '');
  $confirmSubdomainLabel = (string) ($subdomain_label ?? '');
  $confirmSubdomainCustom = (bool) ($subdomain_custom ?? false);
  ?>
  <?php if ($pendingHasPorts && $confirmSubdomain !== ''): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6 mb-2">Subdomain</h2>
      <p class="mb-1">
        App akan diakses di
        <a class="mono text-decoration-none" href="http://<?= e($confirmSubdomain) ?>" target="_blank" rel="noopener"><?= e($confirmSubdomain) ?></a>
        <?php if ($confirmSubdomainCustom && $confirmSubdomainLabel !== ''): ?>
        <span class="badge text-bg-secondary ms-1">label: <?= e($confirmSubdomainLabel) ?></span>
        <?php else: ?>
        <span class="badge text-bg-secondary ms-1">dari nama app</span>
        <?php endif; ?>
      </p>
      <p class="text-muted small mb-0">
        Arahkan DNS (wildcard <span class="mono">*.<?= e((string) config('deploy.app_domain')) ?></span>) ke server ini.
        Subdomain bisa diubah setelah app dibuat di tab <strong>Domain &amp; SSL</strong> halaman detail app —
        sertifikat SSL perlu diterbitkan ulang bila domainnya berubah.
      </p>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary" id="deploy-btn">
      <span class="spinner-border spinner-border-sm d-none" id="deploy-btn-spinner" role="status" aria-hidden="true"></span>
      Deploy App
    </button>
    <a class="btn btn-outline-secondary" href="<?= e($wizardNavUrl($pendingSource === 'compose' ? '/apps/create?mode=compose' : '/apps/create')) ?>">Kembali</a>
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
  var form = document.getElementById('deploy-confirm-form');
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
        // menuju detail app — halaman itu otomatis mem-poll progres build
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
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
