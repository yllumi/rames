<?php $pageTitle = 'Apps';
$active = 'apps'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$__scope = $scope ?? 'all';
$__counts = $counts ?? ['mine' => 0, 'shared' => 0, 'all' => 0];
$__serverFilter = (string) ($serverFilter ?? '');
// Opsi filter server (`id`/`name`) dari controller — hanya terisi bila user
// boleh memilih server (`ServerAccess::canSelectServer()`: admin atau member
// bergrant `server_select`). View tidak menambah gate sendiri.
$__serverOptions = $serverOptions ?? [];
$__isAdmin = (bool) ($isAdmin ?? false);
// Tombol "Impor dari Docker" hanya untuk admin (controller mengirim `canImport`).
$__canImport = (bool) ($canImport ?? false);

// Filter daftar app (docs/SPECS.md §8e / §7.7). Nilai sudah **efektif** dari
// controller (trim; `name`/`domain` lowercase) — kosong = filter tidak aktif.
// View hanya merender, tidak menormalkan ulang.
$__filters = is_array($filters ?? null) ? $filters : [];
$__filterValues = [
  'name' => (string) ($__filters['name'] ?? ''),
  'domain' => (string) ($__filters['domain'] ?? ''),
  'owner' => (string) ($__filters['owner'] ?? ''),
];
$__filterActive = (bool) ($filterActive ?? false);
$__activeFilterCount = count(array_filter($__filterValues, static fn(string $v): bool => $v !== ''));
// Peta `id => username` untuk pilihan owner; hanya terisi bagi admin.
$__ownerOptions = is_array($ownerOptions ?? null) ? $ownerOptions : [];

// Lebar kolom toolbar filter menyesuaikan jumlah field yang benar-benar dirender
// (Server hanya bila ada pilihan, Owner hanya untuk admin): pada `md` tiap
// kombinasi mengisi **12/12** kolom (tanpa celah di kanan), dan pada `xl`
// menyisakan ruang (8-9 kolom) sehingga tombol Terapkan/Reset muat sebaris.
$__filterFieldCount = ($__serverOptions !== [] ? 1 : 0) + 2 + ($__isAdmin ? 1 : 0);
$__filterCol = match ($__filterFieldCount) {
  2 => 'col-6 col-md-6 col-xl-4',
  3 => 'col-6 col-md-4 col-xl-3',
  default => 'col-6 col-md-3 col-xl-2',
};

// Suffix query yang **mempertahankan** filter aktif pada tautan tab (dan
// dipakai `Reset` untuk membuang filter sambil menyisakan scope + server).
$__serverSuffix = $__serverFilter !== '' ? '&server=' . rawurlencode($__serverFilter) : '';
$__filterSuffix = '';
foreach ($__filterValues as $__filterKey => $__filterValue) {
  if ($__filterValue !== '') {
    $__filterSuffix .= '&' . $__filterKey . '=' . rawurlencode($__filterValue);
  }
}
$__resetUrl = '/apps?scope=' . rawurlencode((string) $__scope) . $__serverSuffix;
$__suffix = $__serverSuffix . $__filterSuffix;
$__tab = static function (string $key, string $label, int $count) use ($__scope, $__suffix): string {
  $cls = $__scope === $key ? 'nav-link active' : 'nav-link';
  return '<li class="nav-item"><a class="' . $cls . '" href="/apps?scope=' . e($key) . e($__suffix) . '">'
    . e($label) . ' <span class="badge text-bg-secondary">' . $count . '</span></a></li>';
};
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Apps<?php if ($__filterActive): ?> <span class="badge text-bg-secondary align-middle"><?= (int) $__activeFilterCount ?> filter aktif</span><?php endif; ?></h1>
    <p class="text-muted mb-0">Kelola project Docker yang di-deploy dari repo Git.</p>
  </div>
  <?php // Pemilih mode tampilan hanya berguna bila ada app; tanpa app cukup tombol Create. 
  ?>
  <?php if (!empty($apps)): ?>
    <div class="d-flex align-items-center gap-2">
<?php if ($__canImport): ?>
      <a class="btn btn-outline-secondary" href="/apps/import">Impor dari Docker</a>
<?php endif; ?>
      <a class="btn btn-primary" href="/apps/create">+ Create App</a>
    </div>
  <?php else: ?>
<?php if ($__canImport): ?>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-outline-secondary" href="/apps/import">Impor dari Docker</a>
      <a class="btn btn-primary" href="/apps/create">+ Create App</a>
    </div>
<?php else: ?>
    <a class="btn btn-primary" href="/apps/create">+ Create App</a>
<?php endif; ?>
  <?php endif; ?>
</div>

<ul class="nav nav-pills mb-3">
  <?= $__tab('all', $__isAdmin ? 'Semua App (admin)' : 'Semua', (int) $__counts['all']) ?>
  <?= $__tab('mine', 'Milik Saya', (int) $__counts['mine']) ?>
  <?= $__tab('shared', 'Dibagikan ke Saya', (int) $__counts['shared']) ?>
</ul>

<?php // Satu form untuk SEMUA filter (server + nama + domain + owner) supaya tidak
// ada dua tombol filter berdampingan dan nilainya saling mempertahankan
// otomatis. Berbasis query string (bukan JS state) — URL tetap bisa dibagikan. 
?>
<form class="card mb-3" method="get" action="/apps" aria-label="Filter daftar app">
  <div class="card-body py-3">
    <input type="hidden" name="scope" value="<?= e($__scope) ?>">
    <?php // User tanpa grant pemilihan server tidak mendapat pemilih server
    // (`$serverOptions` kosong dari controller), tetapi `?server=` bisa
    // datang dari tautan yang dibagikan user lain. Bawa nilainya lewat
    // input tersembunyi supaya "Terapkan" tidak membuang filter server —
    // kondisional, agar tidak menduplikasi param saat select dirender. 
    ?>
    <?php if ($__serverFilter !== '' && $__serverOptions === []): ?>
      <input type="hidden" name="server" value="<?= e($__serverFilter) ?>">
    <?php endif; ?>
    <div class="row g-2 g-md-3 align-items-end">
      <?php if ($__serverOptions !== []): ?>
        <div class="<?= $__filterCol ?>">
          <label class="form-label small fw-semibold mb-1" for="server-filter">Server</label>
          <select class="form-select form-select-sm" id="server-filter" name="server">
            <option value="">Semua server</option>
            <?php foreach ($__serverOptions as $__option): ?>
              <option value="<?= e($__option['id']) ?>" <?= $__serverFilter === (string) $__option['id'] ? ' selected' : '' ?>><?= e($__option['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="<?= $__filterCol ?>">
        <label class="form-label small fw-semibold mb-1" for="app-filter-name">Nama</label>
        <input class="form-control form-control-sm" type="search" id="app-filter-name" name="name"
          value="<?= e($__filterValues['name']) ?>" placeholder="mis. renkeu" autocomplete="off">
      </div>
      <div class="<?= $__filterCol ?>">
        <label class="form-label small fw-semibold mb-1" for="app-filter-domain">Domain</label>
        <input class="form-control form-control-sm" type="search" id="app-filter-domain" name="domain"
          value="<?= e($__filterValues['domain']) ?>" placeholder="mis. example.org" autocomplete="off">
      </div>
      <?php if ($__isAdmin): ?>
        <div class="<?= $__filterCol ?>">
          <label class="form-label small fw-semibold mb-1" for="app-filter-owner">Owner</label>
          <select class="form-select form-select-sm" id="app-filter-owner" name="owner">
            <option value="">Semua owner</option>
            <?php foreach ($__ownerOptions as $__ownerId => $__ownerName): ?>
              <option value="<?= e($__ownerId) ?>" <?= $__filterValues['owner'] === (string) $__ownerId ? ' selected' : '' ?>><?= e($__ownerName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-12 col-xl-auto ms-xl-auto d-flex flex-wrap align-items-center gap-2">
        <button class="btn btn-primary btn-sm" type="submit">Terapkan</button>
        <?php if ($__filterActive || $__serverFilter !== ''): ?>
          <a class="btn btn-outline-secondary btn-sm" href="<?= e($__resetUrl) ?>">Reset</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<?php if (empty($apps)): ?>
  <div class="card empty">
    <div class="empty-icon">◈</div>
    <?php if ($__filterActive): ?>
      <p class="text-muted mb-0">Tidak ada app yang cocok dengan filter. Coba longgarkan kata kunci atau <a href="<?= e($__resetUrl) ?>">Reset filter</a>.</p>
    <?php elseif ($__serverFilter !== ''): ?>
      <p class="text-muted mb-0">Tidak ada app di server yang dipilih. Pilih <strong>Semua server</strong> untuk melihat semuanya.</p>
    <?php elseif ($__scope === 'shared'): ?>
      <p class="text-muted mb-0">Belum ada app yang dibagikan ke Anda. Minta pemilik app menambahkan Anda di tab <strong>Akses</strong>.</p>
    <?php elseif ($__scope === 'mine'): ?>
      <p class="text-muted mb-0">Anda belum memiliki app. Klik <strong>Create App</strong> untuk deploy project dari repo Git.</p>
    <?php else: ?>
      <p class="text-muted mb-0">Belum ada app. Klik <strong>Create App</strong> untuk deploy project dari repo Git (wajib punya <code>docker-compose.yml</code>).</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php
  // Data tampilan tiap app dihitung **sekali** di sini, lalu tabel & kartu
  // thumbnail hanya merender field yang sama (tanpa menduplikasi logika
  // badge/role/domain). Logo template memakai peta yang sama dengan galeri
  // create-app (`template_logo_map()`); template tanpa logo jatuh ke inisial.
  $__logoMap = template_logo_map();
  $__cards = [];
  foreach ($apps as $__app) {
    $__id = (string) $__app['id'];
    $__name = (string) $__app['name'];
    $__isOwner = (bool) (($ownedIds ?? [])[$__id] ?? false);
    $__role = (string) (($roles ?? [])[$__id] ?? '');
    $__ownerName = (string) (($ownerNames ?? [])[(string) ($__app['owner_id'] ?? '')] ?? '');
    // Asal server (multi-server): nama server, bukan `server_id` mentah.
    $__serverKind = (string) ($__app['server_kind'] ?? 'local');
    $__serverName = trim((string) ($__app['server_name'] ?? ''));

    // Slug template hanya dipakai bila bentuknya aman & logonya benar-benar ada.
    $__templateSlug = '';
    if (is_array($__app['template'] ?? null)) {
      $__candidate = (string) ($__app['template']['slug'] ?? '');
      if (
        preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $__candidate) === 1
        && isset($__logoMap[$__candidate])
      ) {
        $__templateSlug = $__candidate;
      }
    }

    $__cards[] = [
      'id' => $__id,
      'name' => $__name,
      'status' => (string) ($__app['status'] ?? 'unknown'),
      'message' => (string) ($__app['message'] ?? ''),
      'hasPort' => \app\library\Docker\AppPorts::hasHostPort($__app),
      'domain' => (string) ($__app['subdomain'] ?? ''),
      'customDomain' => (string) ($__app['custom_domain'] ?? ''),
      'customSsl' => (($__app['custom_ssl_status'] ?? 'disabled') === 'active'),
      'ownerName' => $__ownerName,
      'containers' => count($__app['containers'] ?? []),
      'updated' => (string) ($__app['updated_at'] ?? ''),
      'role' => $__role,
      'isOwner' => $__isOwner,
      'serverKind' => $__serverKind,
      'serverName' => $__serverName,
      'logoUrl' => $__templateSlug !== ''
        ? '/images/templates/' . $__templateSlug . '.' . $__logoMap[$__templateSlug]
        : '',
      'initial' => mb_strtoupper(mb_substr($__name, 0, 1)),
    ];
  }
  ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <p class="text-muted small mb-2">Menampilkan <?= (int) count($apps) ?> app<?= $__filterActive ? ' yang cocok dengan filter' : '' ?>.</p>
    <div class="btn-group" role="group" aria-label="Mode tampilan daftar app">
      <button type="button" class="btn btn-outline-secondary btn-sm active" data-apps-view-mode="rows" aria-pressed="true" title="Tampilan baris" aria-label="Tampilan baris">
        <svg viewBox="0 0 18 18" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
          <circle cx="2.5" cy="4.5" r="1" />
          <circle cx="2.5" cy="9" r="1" />
          <circle cx="2.5" cy="13.5" r="1" />
          <line x1="6" y1="4.5" x2="15.5" y2="4.5" />
          <line x1="6" y1="9" x2="15.5" y2="9" />
          <line x1="6" y1="13.5" x2="15.5" y2="13.5" />
        </svg>
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-apps-view-mode="thumbs" aria-pressed="false" title="Tampilan thumbnail" aria-label="Tampilan thumbnail">
        <svg viewBox="0 0 18 18" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
          <rect x="2.5" y="2.5" width="5.5" height="5.5" rx="1" />
          <rect x="10" y="2.5" width="5.5" height="5.5" rx="1" />
          <rect x="2.5" y="10" width="5.5" height="5.5" rx="1" />
          <rect x="10" y="10" width="5.5" height="5.5" rx="1" />
        </svg>
      </button>
    </div>
  </div>

  <div class="card" id="apps-rows">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Name</th>
            <th>Server</th>
            <th>Domain</th>
            <?php if ($__isAdmin): ?><th>Owner</th><?php endif; ?>
            <th>Status</th>
            <th>Containers</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($__cards as $__card): ?>
            <tr>
              <td>
                <a class="mono fw-semibold text-decoration-none me-1" href="/apps/<?= e($__card['id']) ?>"><?= e($__card['name']) ?></a>
                <?php if (!$__card['isOwner']): ?>
                  <span class="badge p-1 text-bg-light border" role="img" title="Anda punya akses <?= e(app_role_label($__card['role'])) ?> ke app ini" aria-label="Dibagikan · <?= e(app_role_label($__card['role'])) ?>"><svg viewBox="0 0 18 18" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                      <circle cx="13.5" cy="3.5" r="2.1" />
                      <circle cx="4.5" cy="9" r="2.1" />
                      <circle cx="13.5" cy="14.5" r="2.1" />
                      <line x1="6.4" y1="8.1" x2="11.7" y2="4.6" />
                      <line x1="6.4" y1="9.9" x2="11.7" y2="13.4" />
                    </svg></span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($__card['serverKind'] === 'agent'): ?>
                  <span class="badge badge-deploying" title="App berjalan di server agent, bukan di host dashboard">agent</span>
                  <span class="mono d-block text-nowrap"><?= e($__card['serverName']) ?></span>
                <?php else: ?>
                  <span class="text-muted" title="App berjalan di host dashboard (server lokal)">lokal</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($__card['hasPort']): ?>
                  <a class="mono text-decoration-none text-nowrap" href="http://<?= e($__card['domain']) ?>" target="_blank" rel="noopener"><?= e($__card['domain']) ?> ↗</a>
                <?php else: ?>
                  <span class="text-muted small" title="App tidak mem-publish port host — tidak ada vhost/subdomain">tanpa domain</span>
                <?php endif; ?>
                <br>
                <?php if ($__card['customDomain'] !== ''): ?>
                  <a class="mono text-decoration-none text-nowrap fw-bold text-primary" href="<?= $__card['customSsl'] ? 'https' : 'http' ?>://<?= e($__card['customDomain']) ?>" target="_blank" rel="noopener"><?= e($__card['customDomain']) ?> ↗</a>
                <?php else: ?>
                  <span class="text-muted small">&mdash;</span>
                <?php endif; ?>
              </td>
              <?php if ($__isAdmin): ?>
                <td class="small">
                  <?php if ($__card['ownerName'] !== ''): ?>
                    <span class="mono"><?= e($__card['ownerName']) ?></span>
                  <?php else: ?>
                    <span class="text-muted">(belum ada owner)</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td>
                <span class="badge badge-<?= e($__card['status']) ?>"
                  title="<?= $__card['message'] !== '' ? e($__card['message']) : '' ?>">
                  <?= e($__card['status']) ?>
                </span>
              </td>
              <td class="mono text-muted"><?= (int) $__card['containers'] ?></td>
              <td class="small text-muted"><?= e($__card['updated']) ?></td>
              <td><a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($__card['id']) ?>">Detail</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php // Wadah thumbnail: default tersembunyi (`hidden` + `d-none`) supaya aman
  // tanpa JS; `public/js/apps-view.js` menampilkannya sesuai preferensi. 
  ?>
  <div id="apps-thumbs" class="row g-3 d-none" hidden>
    <?php foreach ($__cards as $__card): ?>
      <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
        <div class="card h-100">
          <div class="card-body d-flex flex-column">
            <div class="d-flex align-items-center justify-content-center mb-3" style="height:72px;">
              <?php if ($__card['logoUrl'] !== ''): ?>
                <img src="<?= e($__card['logoUrl']) ?>" alt="" loading="lazy" style="max-height:72px; max-width:100%; object-fit:contain;">
              <?php else: ?>
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary-subtle text-secondary fw-bold" style="width:64px; height:64px; font-size:1.5rem;" aria-hidden="true"><?= e($__card['initial']) ?></span>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <a class="mono fw-semibold text-decoration-none" href="/apps/<?= e($__card['id']) ?>"><?= e($__card['name']) ?></a>
              <?php if (!$__card['isOwner']): ?>
                <span class="badge p-1 text-bg-light border" role="img" title="Anda punya akses <?= e(app_role_label($__card['role'])) ?> ke app ini" aria-label="Dibagikan · <?= e(app_role_label($__card['role'])) ?>"><svg viewBox="0 0 18 18" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="13.5" cy="3.5" r="2.1" />
                    <circle cx="4.5" cy="9" r="2.1" />
                    <circle cx="13.5" cy="14.5" r="2.1" />
                    <line x1="6.4" y1="8.1" x2="11.7" y2="4.6" />
                    <line x1="6.4" y1="9.9" x2="11.7" y2="13.4" />
                  </svg></span>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <span class="badge badge-<?= e($__card['status']) ?>"
                title="<?= $__card['message'] !== '' ? e($__card['message']) : '' ?>"><?= e($__card['status']) ?></span>
              <?php if ($__card['serverKind'] === 'agent'): ?>
                <span class="badge badge-deploying" title="App berjalan di server agent, bukan di host dashboard">agent</span>
                <?php if ($__card['serverName'] !== ''): ?>
                  <span class="mono small text-nowrap"><?= e($__card['serverName']) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted small" title="App berjalan di host dashboard (server lokal)">lokal</span>
              <?php endif; ?>
            </div>
            <div class="small mb-2">
              <?php if ($__card['hasPort']): ?>
                <a class="mono text-decoration-none text-nowrap" href="http://<?= e($__card['domain']) ?>" target="_blank" rel="noopener"><?= e($__card['domain']) ?> ↗</a>
              <?php else: ?>
                <span class="text-muted" title="App tidak mem-publish port host — tidak ada vhost/subdomain">tanpa domain</span>
              <?php endif; ?>
              <?php if ($__card['customDomain'] !== ''): ?>
                <br>
                <a class="mono text-decoration-none text-nowrap fw-bold text-primary" href="<?= $__card['customSsl'] ? 'https' : 'http' ?>://<?= e($__card['customDomain']) ?>" target="_blank" rel="noopener"><?= e($__card['customDomain']) ?> ↗</a>
              <?php endif; ?>
            </div>
            <?php if ($__isAdmin): ?>
              <div class="small mb-2">
                <span class="text-muted">Owner</span>:
                <?php if ($__card['ownerName'] !== ''): ?>
                  <span class="mono"><?= e($__card['ownerName']) ?></span>
                <?php else: ?>
                  <span class="text-muted">(belum ada owner)</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 small text-muted mt-auto">
              <span><?= (int) $__card['containers'] ?> container</span>
              <span><?= e($__card['updated']) ?></span>
            </div>
            <div class="mt-2">
              <a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($__card['id']) ?>">Detail</a>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php // Pemilih tampilan hanya ada di halaman Apps; halaman lain tidak memuatnya. 
?>
<script src="/js/apps-view.js?v=1"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>