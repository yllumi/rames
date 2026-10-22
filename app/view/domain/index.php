<?php
/**
 * Halaman `/domains` — dua tab tanpa JS:
 *  - **Semua Domain** (`?tab=all`): tabel gabungan domain app + domain parkir
 *    berikut status & aksi SSL per baris (satu-satunya tabel ber-`data-ssl-row`);
 *  - **Domain Parkir** (`?tab=parking`, admin saja): CRUD entri parkir.
 *
 * Kontrak payload dari `DomainController::index()` (jangan diubah dari view):
 *   $rows         — baris terdekorasi: kind(app|parked), id, domain, app_id,
 *                   app_name, source, role(serve|redirect|parkir), is_custom,
 *                   mode, ssl_status, ssl_message, ssl_expires_at, ssl_error,
 *                   can_ssl, form_url, ajax_url (tanpa objek app);
 *   $domains      — entri parkir untuk tab CRUD (`[]` untuk non-admin);
 *   $isAdmin      — bool;
 *   $tab          — `all`|`parking` (non-admin selalu `all`);
 *   $active       — `'domains'`;
 *   $maxHtmlBytes — batas isi HTML domain parkir (byte);
 *   $appDomain    — domain dasar dashboard (petunjuk, boleh kosong);
 *   $errors       — array pesan galat siap tampil;
 *   $sslSupported — bool (ada domain publik ⇒ Let's Encrypt bisa dipakai).
 *
 * View hanya merender. Menyembunyikan tab/aksi bukan pengamanan: controller
 * tetap menyaring baris app (`AppAccess::visible()`), membuang baris parkir
 * untuk non-admin, dan menolak aksi tanpa hak (404).
 *
 * Tabel "Semua Domain" memakai `public/js/ssl.js` (`data-ssl-*`) untuk
 * memperbarui status tanpa reload. Tab "Domain Parkir" sengaja **tanpa**
 * `data-ssl-row` agar tidak ikut dipetakan polling (kunci barisnya berbeda).
 */
$pageTitle = 'Domains';
$active = 'domains';

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
$__date = static function (mixed $value) use ($__text): string {
    $raw = trim($__text($value));
    if ($raw === '') {
        return '-';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d H:i', $ts);
};

$rows = is_array($rows ?? null) ? array_values(array_filter($rows, 'is_array')) : [];
$domains = is_array($domains ?? null) ? array_values(array_filter($domains, 'is_array')) : [];
$isAdmin = !empty($isAdmin);
$errors = is_array($errors ?? null) ? array_values(array_filter($errors, 'is_scalar')) : [];
$appDomain = is_scalar($appDomain ?? null) ? trim((string) $appDomain) : '';
$sslSupported = !isset($sslSupported) || (bool) $sslSupported;
$maxHtmlBytes = is_numeric($maxHtmlBytes ?? null) ? (int) $maxHtmlBytes : 1048576;
if ($maxHtmlBytes <= 0) {
    $maxHtmlBytes = 1048576;
}
$maxHtmlLabel = $maxHtmlBytes === 1048576
    ? '1 MiB'
    : number_format($maxHtmlBytes / 1024, 1, ',', '.') . ' KiB';

// Tab aktif: non-admin tidak pernah mendapat tab parkir (payload pun kosong).
$tab = is_scalar($tab ?? null) ? (string) $tab : 'all';
if (!$isAdmin || $tab !== 'parking') {
    $tab = 'all';
}

// Peta status → kelas badge dibaca JS dari `data-ssl-badge-map` (satu sumber,
// tidak diduplikasi di JS). `expired` disertakan agar payload lama tetap aman.
$sslBadges = [
    'active' => 'badge-running',
    'pending' => 'badge-deploying',
    'failed' => 'badge-error',
    'expired' => 'badge-error',
    'disabled' => 'badge-stopped',
];
$sslBadgeMapJson = json_encode($sslBadges);
if (!is_string($sslBadgeMapJson) || $sslBadgeMapJson === '') {
    $sslBadgeMapJson = '{}';
}

$roleLabels = ['serve' => 'Serve', 'redirect' => 'Redirect', 'parkir' => 'Parkir'];
$roleBadges = [
    'serve' => 'badge-running',
    'redirect' => 'badge-deploying',
    'parkir' => 'badge-stopped',
];

// Polling hanya dimulai otomatis bila sudah ada baris `pending` sejak render.
$anyPending = false;
foreach ($rows as $__pendingRow) {
    if ((string) ($__pendingRow['ssl_status'] ?? '') === 'pending') {
        $anyPending = true;
        break;
    }
}

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Domains', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <h1 class="h3 mb-1">Domains</h1>
  <p class="text-muted mb-0">
    Domain app (subdomain/custom) dan domain parkir dalam satu tabel, lengkap dengan status SSL.
  </p>
</div>

<nav aria-label="Bagian domain">
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="/domains?tab=all"<?= $tab === 'all' ? ' aria-current="page"' : '' ?>>Semua Domain</a>
    </li>
    <?php if ($isAdmin): ?>
      <li class="nav-item">
        <a class="nav-link <?= $tab === 'parking' ? 'active' : '' ?>" href="/domains?tab=parking"<?= $tab === 'parking' ? ' aria-current="page"' : '' ?>>Domain Parkir</a>
      </li>
    <?php endif; ?>
  </ul>
</nav>

<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert">
  <strong>Ada masalah pada domain parkir:</strong>
  <ul class="mb-0 small">
    <?php foreach ($errors as $__error): ?>
      <li><?= e((string) $__error) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($tab === 'all'): ?>
<section aria-labelledby="domains-all-heading">
  <h2 id="domains-all-heading" class="visually-hidden">Semua Domain</h2>

  <p class="text-muted small">
    SSL diterbitkan <strong>otomatis per domain</strong> (Let's Encrypt) untuk domain app maupun
    domain parkir. Klik <strong>Aktifkan SSL</strong> pada baris yang belum aktif; status
    <strong>proses ...</strong> akan diperbarui otomatis tanpa memuat ulang halaman.
    Prasyarat: record <strong>DNS A/AAAA</strong> domain sudah mengarah ke server ini.
  </p>

  <?php if (!$sslSupported): ?>
    <div class="alert alert-warning" role="alert">
      <strong>SSL dinonaktifkan.</strong> APP_DOMAIN
      (<span class="mono"><?= e($appDomain !== '' ? $appDomain : 'tidak diset') ?></span>) bukan domain publik,
      sehingga Let's Encrypt tidak bisa menerbitkan sertifikat. Set <code>APP_DOMAIN</code> ke domain publik
      (mis. <code>example.com</code>) untuk mengaktifkan fitur ini.
    </div>
  <?php endif; ?>

  <div class="card">
    <?php if ($rows === []): ?>
      <div class="card-body">
        <p class="mb-1 fw-semibold">Belum ada domain.</p>
        <p class="text-muted small mb-0">
          Deploy app dengan port terpublish agar subdomain/custom domain tampil di sini<?php if ($isAdmin): ?>,
          atau tambahkan <a href="/domains?tab=parking">domain parkir</a><?php endif; ?>.
        </p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <?php /* Peta status → kelas badge dibaca JS (satu sumber, tanpa duplikasi di JS). */ ?>
        <table class="table table-hover align-middle mb-0"
               data-ssl-badge-map="<?= e($sslBadgeMapJson) ?>"
               data-ssl-supported="<?= $sslSupported ? '1' : '0' ?>"
               data-ssl-status-url="/api/domains/status"<?= $anyPending ? ' data-ssl-autostart="1"' : '' ?>>
          <caption class="visually-hidden">Semua domain app dan parkir beserta status SSL dan aksinya</caption>
          <thead>
            <tr>
              <th scope="col">Domain</th>
              <th scope="col">Sumber</th>
              <th scope="col">Peran</th>
              <th scope="col">Status SSL</th>
              <th scope="col">Kedaluwarsa</th>
              <th scope="col" class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $__row): ?>
              <?php
                $__kind = strtolower(trim($__text($__row['kind'] ?? 'app')));
                if ($__kind !== 'parked') {
                    $__kind = 'app';
                }
                $__id = $__text($__row['id'] ?? '');
                $__appId = $__text($__row['app_id'] ?? '');
                $__domain = $__text($__row['domain'] ?? '');
                $__defaultRole = $__kind === 'parked' ? 'parkir' : 'serve';
                $__role = strtolower(trim($__text($__row['role'] ?? $__defaultRole)));
                if (!isset($roleLabels[$__role])) {
                    $__role = $__defaultRole;
                }
                $__key = $__kind . ':' . $__id . ':' . $__role;
                $__source = $__text($__row['source'] ?? '');
                $__isCustom = !empty($__row['is_custom']);
                $__st = strtolower(trim($__text($__row['ssl_status'] ?? 'disabled')));
                if ($__st === '') {
                    $__st = 'disabled';
                }
                $__message = trim($__text($__row['ssl_message'] ?? ''));
                $__error = trim($__text($__row['ssl_error'] ?? ''));
                $__expires = trim($__text($__row['ssl_expires_at'] ?? ''));
                $__canSsl = !empty($__row['can_ssl']);
                $__formUrl = $__text($__row['form_url'] ?? '');
                $__ajaxUrl = $__text($__row['ajax_url'] ?? '');
                $__noteState = $__st === 'pending' || $__st === 'active';
                // Form dirender sebagai fallback tanpa JS; JS mencegat `submit`.
                // Visibilitas awal mengikuti state mesin JS (`can_ssl` + dukungan SSL).
                $__formVisible = $__canSsl && $sslSupported && !$__noteState
                    && $__formUrl !== '' && $__ajaxUrl !== '';
                $__dashVisible = $__canSsl && !$sslSupported && !$__noteState;
                $__noHakVisible = !$__canSsl && !$__noteState;
                $__errorVisible = $__canSsl && $sslSupported && $__st === 'failed' && $__error !== '';
              ?>
              <tr data-ssl-row
                  data-domain-key="<?= e($__key) ?>"
                  data-app-id="<?= e($__appId) ?>"
                  data-domain="<?= e($__domain) ?>"
                  data-ssl-status="<?= e($__st) ?>"
                  data-ssl-can="<?= $__canSsl ? '1' : '0' ?>">
                <td>
                  <span class="mono text-break"><?= e($__domain !== '' ? $__domain : '-') ?></span>
                  <?php if ($__isCustom): ?><span class="text-muted small">(custom)</span><?php endif; ?>
                </td>
                <td class="small">
                  <?php if ($__kind === 'app' && $__appId !== ''): ?>
                    <a href="/apps/<?= e(rawurlencode($__appId)) ?>"><?= e($__source !== '' ? $__source : 'App') ?></a>
                  <?php else: ?>
                    <span class="text-muted"><?= e($__source !== '' ? $__source : ($__kind === 'parked' ? 'Domain parkir' : '-')) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= e($roleBadges[$__role] ?? 'badge-stopped') ?>"><?= e($roleLabels[$__role]) ?></span>
                </td>
                <td>
                  <span class="badge <?= e($sslBadges[$__st] ?? 'badge-stopped') ?>" data-ssl-badge><?= e($__st) ?></span>
                  <div class="text-muted small<?= $__message !== '' ? '' : ' d-none' ?>" data-ssl-message><?= e($__message) ?></div>
                </td>
                <td class="small text-nowrap">
                  <span data-ssl-expires<?= $__expires === '' ? ' class="text-muted"' : '' ?>><?= $__expires === '' ? '-' : e($__expires) ?></span>
                </td>
                <td class="text-end text-nowrap">
                  <?php /* Catatan & penanda non-form — default tersembunyi, JS yang menampilkan. */ ?>
                  <span class="text-muted small<?= $__noHakVisible ? '' : ' d-none' ?>" data-ssl-nohak="ability">tanpa hak</span>
                  <span class="text-muted small<?= $__dashVisible ? '' : ' d-none' ?>" data-ssl-nohak="unsupported">&mdash;</span>
                  <span class="text-muted small<?= $__st === 'pending' ? '' : ' d-none' ?>" data-ssl-note="pending">proses ...</span>
                  <span class="text-muted small<?= $__st === 'active' ? '' : ' d-none' ?>" data-ssl-note="active">aktif</span>
                  <?php if ($__canSsl && $__formUrl !== '' && $__ajaxUrl !== ''): ?>
                    <?php /* Fallback tanpa JS: form POST biasa; JS memakai `data-ssl-url`. */ ?>
                    <form method="post" action="<?= e($__formUrl) ?>" class="d-inline<?= $__formVisible ? '' : ' d-none' ?>"
                          data-ssl-form data-ssl-url="<?= e($__ajaxUrl) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="domain" value="<?= e($__domain) ?>">
                      <button type="submit" class="btn btn-<?= $__st === 'failed' ? 'outline-danger' : 'primary' ?> btn-sm" data-ssl-submit><?= $__st === 'failed' ? '↻ Retry SSL' : 'Aktifkan SSL' ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if ($__canSsl && $sslSupported): ?>
                    <div class="text-danger small mt-1<?= $__errorVisible ? '' : ' d-none' ?>" data-ssl-error><?= e($__error) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php /* Status baris disegarkan lewat polling JSON `/api/domains/status`. */ ?>
  <?php if ($rows !== []): ?>
    <script src="/js/ssl.js?v=2"></script>
  <?php endif; ?>
</section>
<?php else: ?>
<section aria-labelledby="domains-parking-heading">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h2 id="domains-parking-heading" class="h5 mb-1">Domain Parkir</h2>
      <p class="text-muted small mb-0">
        FQDN di luar app: halaman statis (maksimum <?= e($maxHtmlLabel) ?>) atau redirect 301/302.
        Status &amp; aktivasi SSL dikelola di tab <a href="/domains?tab=all">Semua Domain</a>.
      </p>
    </div>
    <a class="btn btn-primary" href="/domains/create">+ Tambah Domain</a>
  </div>

  <?php if ($appDomain !== ''): ?>
    <p class="small text-muted">
      Contoh penamaan: <span class="mono">parkir.<?= e($appDomain) ?></span> &mdash; tetap arahkan
      DNS-nya ke server ini, bukan ke subdomain app.
    </p>
  <?php endif; ?>

  <div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h3 class="h6 mb-0">Daftar Domain Parkir</h3>
      <span class="text-muted small"><?= count($domains) ?> domain</span>
    </div>

    <?php if ($domains === []): ?>
      <div class="card-body">
        <p class="mb-1 fw-semibold">Belum ada domain parkir.</p>
        <p class="text-muted small mb-3">
          Tambahkan FQDN yang sudah diarahkan ke server ini, lalu pilih mode <strong>HTML</strong> (halaman
          statis) atau <strong>Redirect</strong> (alihkan ke URL lain). Varian <span class="mono">www</span>
          dibuat sebagai entri terpisah.
        </p>
        <a class="btn btn-primary btn-sm" href="/domains/create">+ Tambah Domain</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <caption class="visually-hidden">Daftar domain parkir beserta mode, tujuan, status SSL, dan aksinya</caption>
          <thead>
            <tr>
              <th scope="col">Domain</th>
              <th scope="col">Mode</th>
              <th scope="col">Tujuan</th>
              <th scope="col">Kode</th>
              <th scope="col">Diperbarui</th>
              <th scope="col">Status SSL</th>
              <th scope="col" class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($domains as $__domain): ?>
              <?php
                $__id = $__text($__domain['id'] ?? '');
                $__name = $__text($__domain['domain'] ?? '');
                $__mode = strtolower(trim($__text($__domain['mode'] ?? '')));
                $__isRedirect = $__mode === 'redirect';
                $__target = $__text($__domain['redirect_to'] ?? '');
                $__code = $__text($__domain['redirect_code'] ?? '');
                $__updated = $__date($__domain['updated_at'] ?? ($__domain['created_at'] ?? ''));
                $__parkedSsl = strtolower(trim($__text($__domain['ssl_status'] ?? 'disabled')));
                if ($__parkedSsl === '') {
                    $__parkedSsl = 'disabled';
                }
              ?>
              <tr data-domain-row>
                <td>
                  <span class="mono text-break"><?= e($__name !== '' ? $__name : '-') ?></span>
                </td>
                <td>
                  <span class="badge <?= $__isRedirect ? 'badge-deploying' : 'badge-running text-uppercase' ?>">
                    <?= $__isRedirect ? 'Redirect' : 'HTML' ?>
                  </span>
                </td>
                <td>
                  <?php if ($__isRedirect): ?>
                    <?php if ($__target !== ''): ?>
                      <span class="mono text-break small"><?= e($__target) ?></span>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">Halaman statis</span>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?php if ($__isRedirect): ?>
                    <span class="mono"><?= e($__code !== '' ? $__code : '-') ?></span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted text-nowrap"><?= e($__updated) ?></td>
                <td class="small">
                  <?php /* Teks saja — tanpa `data-ssl-*` agar tidak ikut polling tab Semua Domain. */ ?>
                  <span class="badge <?= e($sslBadges[$__parkedSsl] ?? 'badge-stopped') ?>"><?= e($__parkedSsl) ?></span>
                </td>
                <td class="text-end text-nowrap">
                  <?php if ($__id !== ''): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="/domains/<?= e(rawurlencode($__id)) ?>/edit">Edit</a>
                    <form method="post" action="/domains/<?= e(rawurlencode($__id)) ?>/delete" class="d-inline"
                          onsubmit="return confirm('Hapus domain parkir ini? Halaman/berkas konten dan config Nginx-nya ikut dihapus.');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-outline-danger btn-sm"
                              aria-label="Hapus domain parkir <?= e($__name !== '' ? $__name : $__id) ?>">Hapus</button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
