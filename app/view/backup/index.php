<?php
$pageTitle = 'Backup Volume';
$active = 'backups';

// Halaman ini HANYA merender kerangka: daftar volume + status dimuat via AJAX
// dari `GET /api/backups/status` oleh `public/js/backup.js`. Tidak ada logika
// bisnis, query, atau panggilan Docker di sini (PLAN_VOLUME_BACKUP.md §5.4).
$enabled = $enabled ?? true;
$policy = (string) ($policy ?? 'stop');
$isAdmin = $isAdmin ?? is_admin();
// Data kartu "Database dashboard (SQLite)" (null untuk non-admin / bila tak ada).
$db = $db ?? null;

$policyLabel = $policy === 'skip'
    ? 'manual saja — volume non-DB tidak dijadwalkan otomatis'
    : 'snapshot volume non-DB: stop → snapshot → start (harian)';
$policyClass = $policy === 'skip' ? 'text-bg-secondary' : 'text-bg-info';

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Backup', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Backup Volume</h1>
    <p class="text-muted mb-0">
      Backup <strong>harian</strong> volume app ke object storage (S3) via <span class="mono">restic</span>
      — inkremental, dedup, terenkripsi. Volume container <strong>database</strong> di-backup dengan
      <strong>dump logis</strong> (container tetap hidup); volume lain di-<strong>snapshot</strong> dari
      filesystem (container dihentikan sementara). Status tiap volume dimuat otomatis di tabel di bawah.
    </p>
    <p class="form-text small text-muted mb-0 mt-2">
      Kolom <strong>Berkala</strong>: volume yang belum pernah dibackup nonaktif secara default —
      aktifkan untuk ikut backup harian.
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <span id="backup-running" class="badge text-bg-warning d-none">
      <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Backup berjalan …
    </span>
    <?php if ($isAdmin): ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="backup-refresh-status">Segarkan status</button>
      <a class="btn btn-outline-secondary btn-sm" href="/backups/guide">📖 Panduan setup</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="/volumes">Volumes</a>
  </div>
</div>

<?php if (!$enabled): ?>
  <div class="alert alert-warning" role="alert">
    <strong>Fitur backup volume dimatikan.</strong> Variabel
    <span class="mono">VOLUME_BACKUP_ENABLED=false</span> menonaktifkan penjadwalan harian &amp; tombol
    backup. Nyalakan kembali lewat konfigurasi environment untuk mengaktifkan aksi backup.
  </div>
<?php endif; ?>

<div class="alert alert-info py-2 small d-flex flex-wrap align-items-center gap-2" role="alert">
  <span class="badge <?= e($policyClass) ?>">policy: <?= e($policy) ?></span>
  <span>Kebijakan snapshot aktif — <?= e($policyLabel) ?>.</span>
</div>

<?php if (!$isAdmin): ?>
  <div class="alert alert-info py-2 small" role="alert">
    Anda hanya melihat volume dari app yang boleh Anda akses. Aksi <strong>Backup</strong> butuh hak
    <em>operator</em> dan <strong>Restore</strong> butuh hak <em>owner</em>. Tombol adalah lapisan kedua —
    server tetap menolak aksi yang tidak diizinkan.
  </div>
<?php endif; ?>

<div id="backup-error" class="alert alert-danger d-none" role="alert"></div>
<div id="backup-last-run" class="alert alert-secondary py-2 small d-none" role="status"></div>

<div id="backup-page"
     data-enabled="<?= $enabled ? '1' : '0' ?>"
     data-is-admin="<?= $isAdmin ? '1' : '0' ?>"
     data-interval="20000">

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="backup-tab-active" data-bs-toggle="tab"
              data-bs-target="#pane-active" type="button" role="tab"
              aria-controls="pane-active" aria-selected="true">Volume aktif</button>
    </li>
    <?php if ($isAdmin): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="backup-tab-archive" data-bs-toggle="tab"
                data-bs-target="#pane-archive" type="button" role="tab"
                aria-controls="pane-archive" aria-selected="false">Arsip
          <span class="badge text-bg-secondary d-none" id="archive-count">0</span>
        </button>
      </li>
    <?php endif; ?>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="pane-active" role="tabpanel"
         aria-labelledby="backup-tab-active" tabindex="0">

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Volume</th>
            <th>Project</th>
            <th>App</th>
            <th>Strategi</th>
            <th>Status container</th>
            <th>Backup terakhir</th>
            <th class="text-end">Snapshot</th>
            <th>Berkala</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody id="backup-rows">
          <tr><td colspan="9" class="text-muted small">Memuat …</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small me-auto" id="backup-footer">Memuat status backup …</span>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="backup-refresh">Muat ulang</button>
    </div>
  </div>

  <noscript>
    <div class="alert alert-warning mt-3 mb-0" role="alert">
      JavaScript dinonaktifkan — tabel volume &amp; aksi backup tidak dapat dimuat. Status mentah tersedia di
      <span class="mono">GET /api/backups/status</span>.
    </div>
  </noscript>

  <!-- Form run tersembunyi: satu sumber token CSRF untuk tombol "Backup sekarang".
       Di-submit lewat fetch agar respons JSON bisa ditampilkan tanpa navigasi. -->
  <form id="backup-run-form" method="post" action="/backups/run" class="d-none" aria-hidden="true">
    <?= csrf_field() ?>
    <input type="hidden" name="volume" id="backup-run-volume" value="">
  </form>

    </div><!-- /#pane-active -->

    <?php if ($isAdmin): ?>
      <div class="tab-pane fade" id="pane-archive" role="tabpanel"
           aria-labelledby="backup-tab-archive" tabindex="0">
        <div class="card">
          <div class="card-header bg-white py-2">
            <span class="small text-muted">
              Riwayat volume yang pernah ter-backup namun volumenya sudah tidak ada di Docker.
            </span>
          </div>
          <div class="card-body py-2 d-none" id="backup-archive-empty">
            <span class="text-muted small">Belum ada volume arsip.</span>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Volume (asli)</th>
                  <th>Project</th>
                  <th>App (asli)</th>
                  <th>Strategi</th>
                  <th>Backup terakhir</th>
                  <th class="text-end">Snapshot</th>
                  <th class="text-end">Ukuran</th>
                  <th class="text-end">Aksi</th>
                </tr>
              </thead>
              <tbody id="backup-archive-rows">
                <tr><td colspan="8" class="text-muted small">Memuat …</td></tr>
              </tbody>
            </table>
          </div>
          <div class="card-footer">
            <span class="text-muted small">
              Volume di tab ini sudah tidak ada di Docker. Restore akan membuat volume <strong>baru</strong>.
            </span>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div><!-- /.tab-content -->

  <?php if (is_array($db) && $db !== []): ?>
    <?php
      // Kartu admin-only: status/unduh/restore DB dashboard (SQLite).
      // Hanya perhitungan format tampilan di sini — tanpa logika bisnis.
      $dbFmt = static function (int $bytes): string {
          $units = ['B', 'KB', 'MB', 'GB', 'TB'];
          $value = (float) $bytes;
          $unit = 0;
          while ($value >= 1024 && $unit < count($units) - 1) {
              $value /= 1024;
              $unit++;
          }
          return ($unit === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$unit];
      };
      $dbState = is_array($db['state'] ?? null) ? $db['state'] : [];
      $dbSnapshots = is_array($db['snapshots'] ?? null) ? $db['snapshots'] : [];
      $dbRecent = array_slice($dbSnapshots, 0, 10);
      $dbKeep = is_array($db['keep'] ?? null) ? $db['keep'] : [];
      $dbAvailable = (bool) ($db['available'] ?? false);
      $dbHour = (int) ($db['hour'] ?? 0);
    ?>
    <div class="card mt-3" id="db-backup">
      <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <strong>Database dashboard (SQLite)</strong>
          <span class="text-muted small d-block">
            Basis data dashboard disimpan di named volume <span class="mono">rames</span>
            (<span class="mono">/var/lib/rames</span>); snapshot berkala harian pukul
            <span class="mono"><?= e((string) $dbHour) ?>:00</span>.
          </span>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <form method="post" action="/backups/db/run" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-sm">Backup sekarang</button>
          </form>
          <form method="post" action="/backups/db/prune" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-secondary btn-sm">Prune sekarang</button>
          </form>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$dbAvailable): ?>
          <div class="alert alert-danger py-2 small" role="alert">
            <strong>Status database tidak terbaca.</strong>
            <?php if (!empty($db['error'])): ?><span class="mono"><?= e((string) $db['error']) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($dbState['error'])): ?>
          <div class="alert alert-danger py-2 small" role="alert">
            <strong>Error terakhir:</strong> <span class="mono"><?= e((string) $dbState['error']) ?></span>
          </div>
        <?php endif; ?>

        <?php if ((string) ($dbState['last_skip_reason'] ?? '') === 'busy'): ?>
          <div class="alert alert-warning py-2 small" role="alert">
            Snapshot terakhir <strong>dilewati</strong>: backup lain sedang berjalan (busy).
          </div>
        <?php endif; ?>

        <dl class="row small mb-0">
          <dt class="col-sm-3">Path DB</dt>
          <dd class="col-sm-9 mono text-break"><?= e((string) ($db['path'] ?? '')) ?></dd>

          <dt class="col-sm-3">Ukuran DB</dt>
          <dd class="col-sm-9"><?= e($dbFmt((int) ($db['db_bytes'] ?? 0))) ?></dd>

          <dt class="col-sm-3">Snapshot terakhir</dt>
          <dd class="col-sm-9">
            <?php if (!empty($db['latest']) && is_array($db['latest'])): ?>
              <span class="mono"><?= e((string) ($db['latest']['file'] ?? '')) ?></span>
              — <?= e((string) ($db['latest']['at'] ?? '')) ?>
              (<?= e($dbFmt((int) ($db['latest']['bytes'] ?? 0))) ?>)
            <?php else: ?>
              <span class="text-muted">Belum ada snapshot.</span>
            <?php endif; ?>
          </dd>

          <dt class="col-sm-3">Jumlah snapshot</dt>
          <dd class="col-sm-9">
            <?= e((string) (int) ($db['count'] ?? 0)) ?> snapshot · total
            <?= e($dbFmt((int) ($db['total_bytes'] ?? 0))) ?>
          </dd>

          <dt class="col-sm-3">Kebijakan retensi</dt>
          <dd class="col-sm-9">
            daily <span class="mono"><?= e((string) (int) ($dbKeep['daily'] ?? 0)) ?></span>,
            weekly <span class="mono"><?= e((string) (int) ($dbKeep['weekly'] ?? 0)) ?></span>,
            monthly <span class="mono"><?= e((string) (int) ($dbKeep['monthly'] ?? 0)) ?></span>
          </dd>

          <dt class="col-sm-3">Status terjadwal</dt>
          <dd class="col-sm-9">
            <?php if (!empty($db['enabled'])): ?>
              <span class="badge text-bg-success">aktif</span> — harian pukul
              <span class="mono"><?= e((string) $dbHour) ?>:00</span>
              (<?= !empty($db['due']) ? 'menunggu jadwal hari ini' : 'sudah berjalan hari ini' ?>)
            <?php else: ?>
              <span class="badge text-bg-secondary">tidak aktif</span> — penjadwalan harian dimatikan
            <?php endif; ?>
          </dd>

          <?php if (!empty($dbState['last_restore_from'])): ?>
            <dt class="col-sm-3">Restore terakhir</dt>
            <dd class="col-sm-9">
              <span class="mono"><?= e((string) $dbState['last_restore_from']) ?></span>
              — <?= e((string) ($dbState['last_restore_at'] ?? '')) ?>
            </dd>
          <?php endif; ?>
        </dl>
      </div>

      <div class="card-body border-top pt-3">
        <h6 class="mb-2">
          Snapshot terbaru <span class="text-muted small">(<?= e((string) count($dbRecent)) ?> dari
          <?= e((string) (int) ($db['count'] ?? 0)) ?>)</span>
        </h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Berkas</th>
                <th>Waktu</th>
                <th class="text-end">Ukuran</th>
                <th class="text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($dbRecent === []): ?>
                <tr><td colspan="4" class="text-muted small">Belum ada snapshot.</td></tr>
              <?php else: ?>
                <?php foreach ($dbRecent as $dbRow): ?>
                  <?php if (!is_array($dbRow)) { continue; } ?>
                  <?php $dbName = (string) ($dbRow['file'] ?? ''); ?>
                  <tr>
                    <td class="mono small text-break"><?= e($dbName) ?></td>
                    <td class="small"><?= e((string) ($dbRow['at'] ?? '')) ?></td>
                    <td class="text-end small"><?= e($dbFmt((int) ($dbRow['bytes'] ?? 0))) ?></td>
                    <td class="text-end">
                      <a class="btn btn-outline-secondary btn-sm"
                         href="/backups/db/download?file=<?= e(rawurlencode($dbName)) ?>">Unduh</a>
                      <form method="post" action="/backups/db/restore"
                            class="d-inline-flex align-items-center gap-1 ms-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="file" value="<?= e($dbName) ?>">
                        <input type="text" name="confirm" class="form-control form-control-sm"
                               style="width:7rem" placeholder="RESTORE" required
                               autocomplete="off" autocapitalize="off" spellcheck="false">
                        <button type="submit" class="btn btn-danger btn-sm">Restore</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="form-text small mb-0 mt-2">
          <strong>Restore bersifat destruktif:</strong> DB saat ini ditimpa oleh snapshot (safety
          snapshot dibuat lebih dulu). Ketik <span class="mono">RESTORE</span> untuk konfirmasi, lalu
          jalankan <span class="mono">php start.php reload</span> agar worker memakai DB baru.
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal detail snapshot (read-only) -->
<div class="modal fade" id="snapshots-modal" tabindex="-1" aria-labelledby="snapshots-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="snapshots-modal-label">Snapshot — <span class="mono" id="snapshots-volume">—</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div id="snapshots-loading" class="text-muted small">Memuat snapshot …</div>
        <div id="snapshots-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
        <div id="snapshots-empty" class="text-muted small d-none">Belum ada snapshot untuk volume ini.</div>
        <div class="table-responsive d-none" id="snapshots-table-wrap">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Id</th>
                <th>Waktu</th>
                <th class="text-end">Ukuran</th>
                <th>Tag</th>
              </tr>
            </thead>
            <tbody id="snapshots-rows"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal konfirmasi Restore (destruktif) -->
<div class="modal fade" id="restore-modal" tabindex="-1" aria-labelledby="restore-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="restore-form" method="post" action="/backups/restore">
      <?= csrf_field() ?>
      <input type="hidden" name="volume" id="restore-volume" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="restore-modal-label">Restore volume</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2 small">Volume <span class="mono fw-semibold" id="restore-volume-label">—</span></p>
        <div class="alert alert-danger py-2 small" role="alert">
          <strong>Operasi destruktif.</strong> Isi volume akan <strong>ditimpa</strong> oleh snapshot yang
          dipilih. Container app akan <strong>dihentikan sementara</strong> selama restore lalu dinyalakan
          kembali setelah selesai. Data yang ada sekarang dan tidak ada di snapshot akan <strong>hilang</strong>.
        </div>
        <div class="mb-3">
          <label class="form-label" for="restore-snapshot">Snapshot</label>
          <select class="form-select form-select-sm" name="snapshot" id="restore-snapshot" required>
            <option value="">Memuat snapshot …</option>
          </select>
          <div class="form-text" id="restore-snapshot-note"></div>
        </div>
        <div class="mb-1">
          <label class="form-label" for="restore-confirm">
            Ketik nama volume <span class="mono" id="restore-confirm-hint"></span> untuk konfirmasi
          </label>
          <input type="text" class="form-control form-control-sm" name="confirm" id="restore-confirm"
                 autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="">
        </div>
        <div id="restore-error" class="alert alert-danger py-2 small d-none mt-3" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-danger btn-sm" id="restore-submit" disabled>Restore (timpa volume)</button>
      </div>
    </form>
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="backup-toasts" role="region" aria-label="Notifikasi" aria-live="polite" aria-atomic="true"></div>

<!-- Modal detail error backup (dibuka dari kolom "Backup terakhir"). Isi diisi
     via textContent oleh backup.js — jangan pernah innerHTML untuk pesan. -->
<div class="modal fade" id="backup-error-modal" tabindex="-1" aria-labelledby="backup-error-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="backup-error-modal-label">
          Detail error backup — <span class="mono" id="backup-error-volume">—</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><span id="backup-error-meta" class="text-muted small"></span></div>
        <pre class="mb-0 small" id="backup-error-message" style="white-space:pre-wrap; word-break:break-word;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal daftar snapshot volume ARSIP (admin-only; dimuat dari /api/backups/archive/snapshots). -->
<div class="modal fade" id="archive-snapshots-modal" tabindex="-1" aria-labelledby="archive-snapshots-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="archive-snapshots-modal-label">Snapshot arsip — <span class="mono" id="archive-snapshots-volume">—</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div id="archive-snapshots-loading" class="text-muted small">Memuat snapshot …</div>
        <div id="archive-snapshots-error" class="alert alert-danger py-2 small d-none" role="alert"></div>
        <div id="archive-snapshots-empty" class="text-muted small d-none">Belum ada snapshot untuk volume arsip ini.</div>
        <div class="table-responsive d-none" id="archive-snapshots-table-wrap">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Id</th>
                <th>Waktu</th>
                <th class="text-end">Ukuran</th>
                <th>Tag</th>
                <th class="text-end">Aksi</th>
              </tr>
            </thead>
            <tbody id="archive-snapshots-rows"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal restore volume ARSIP → volume Docker BARU (admin-only). -->
<div class="modal fade" id="archive-restore-modal" tabindex="-1" aria-labelledby="archive-restore-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="archive-restore-form" method="post" action="/backups/archive/restore">
      <?= csrf_field() ?>
      <input type="hidden" name="volume" id="archive-restore-volume" value="">
      <div class="modal-header">
        <h5 class="modal-title" id="archive-restore-modal-label">Restore volume arsip</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2 small">Volume arsip <span class="mono fw-semibold" id="archive-restore-volume-label">—</span></p>
        <div class="alert alert-info py-2 small" role="alert">
          Volume asal sudah <strong>tidak ada di Docker</strong>. Restore akan membuat volume Docker
          <strong>baru</strong> dengan nama yang Anda tentukan, lalu mengisinya dari snapshot.
        </div>
        <div class="mb-3">
          <label class="form-label" for="archive-restore-snapshot">Snapshot</label>
          <select class="form-select form-select-sm" name="snapshot" id="archive-restore-snapshot" required>
            <option value="">Memuat snapshot …</option>
          </select>
        </div>
        <div class="mb-1">
          <label class="form-label" for="archive-target-name">Nama volume baru</label>
          <input type="text" class="form-control form-control-sm" name="target_name" id="archive-target-name"
                 autocomplete="off" autocapitalize="off" spellcheck="false" required
                 pattern="[a-zA-Z0-9][a-zA-Z0-9_.-]*" placeholder="">
          <div class="form-text">
            Pola volume Docker: huruf/angka, boleh <span class="mono">_ . -</span>, harus diawali huruf/angka.
          </div>
        </div>
        <div id="archive-restore-error" class="alert alert-danger py-2 small d-none mt-3" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-danger btn-sm" id="archive-restore-submit" disabled>Restore ke volume baru</button>
      </div>
    </form>
  </div>
</div>

<script src="/js/backup.js?v=11"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>
