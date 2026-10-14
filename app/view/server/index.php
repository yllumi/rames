<?php $pageTitle = 'Servers'; $active = 'servers'; ?>
<?php
/**
 * Halaman `/servers` — daftar server yang dikendalikan dashboard (multi-server F0).
 *
 * Kontrak payload dari `ServerController::index()` (jangan diubah dari sisi view):
 *   $servers — array dokumen server **tanpa** `token`/`tls_cert_pem`, ditambah
 *              penanda `has_token` (field: id, name, kind, base_url, domain,
 *              notes, enabled, port_range?, status, agent_version?,
 *              capabilities?, last_seen_at?, tls_fingerprint?);
 *   $localId — id baris server lokal (`'local'`);
 *   $active  — `'servers'` (penanda menu aktif).
 *
 * View hanya merender: tidak memanggil store/library dan tidak menghitung apa pun
 * yang bersifat bisnis. Pemetaan status → kelas badge di sini **sama** dengan yang
 * dipakai `public/js/server.js` saat tombol Test connection memperbarui baris
 * (jaga keduanya tetap sinkron).
 *
 * Halaman ini admin-only di sisi server (non-admin ⇒ 404); nav di header hanya
 * lapisan kedua.
 */
$localId = (string) ($localId ?? 'local');
$servers = is_array($servers ?? null) ? array_values(array_filter($servers, 'is_array')) : [];

// Baris server lokal selalu ada; "server agent" = sisanya (dipakai empty state).
$agentCount = 0;
foreach ($servers as $__s) {
    if (strtolower(trim((string) ($__s['kind'] ?? ''))) !== 'local') {
        $agentCount++;
    }
}
// Domain dasar efektif dashboard (fallback bila server ber-`domain` kosong).
$defaultDomain = trim((string) config('deploy.app_domain', ''));

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Servers', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Servers</h1>
    <p class="text-muted mb-0">
      Server yang dikendalikan dashboard ini. <strong>Server lokal</strong> adalah dashboard ini sendiri
      (dikelola dari <span class="mono">.env</span>/config), sedangkan <strong>server agent</strong> dihubungi
      lewat HTTPS dengan <strong>pinning sertifikat</strong> (URL + token + PEM).
      Hanya admin yang bisa mengelola daftar ini.
    </p>
  </div>
  <a class="btn btn-primary" href="/servers/create">+ Tambah Server</a>
</div>

<?php if ($agentCount === 0): ?>
<section class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Belum ada server agent</h2>
    <span class="text-muted small">hanya server lokal</span>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Dashboard ini masih berjalan di satu host. Untuk mengendalikan server lain, pasang <strong>agent</strong>
      di server tujuan lalu daftarkan di sini:
    </p>
    <ol class="small text-muted mb-3">
      <li class="mb-1">
        Di server baru (Docker Engine + Nginx native): <span class="mono">git clone &lt;repo&gt; /srv/rames-agent</span>
        lalu <span class="mono">cd /srv/rames-agent &amp;&amp; ./agent/install.sh</span>.
      </li>
      <li class="mb-1">
        Skrip itu mencetak blok onboarding — <strong>URL</strong> agent, <strong>token</strong> (tampil sekali), dan
        <strong>sertifikat TLS (PEM)</strong>. Salin seluruh blok tersebut.
      </li>
      <li class="mb-1">
        Klik <strong>+ Tambah Server</strong>, tempel URL/token/PEM, isi domain serta rentang port host, lalu simpan.
        Fingerprint sertifikat dihitung dashboard dari PEM — tidak diketik manual.
      </li>
      <li>
        Jalankan <strong>Test connection</strong> pada baris server itu. Status
        <span class="badge badge-running">online</span> berarti agent siap dipakai.
      </li>
    </ol>
    <div class="alert alert-warning py-2 small mb-0" role="alert">
      Sertifikat agent bersifat <strong>self-signed</strong> dan dipakai sebagai pin TLS (bukan CA publik).
      Bila sertifikatnya berganti — mis. agent dipasang ulang — Test connection akan gagal tegas dengan pesan
      verifikasi manual; tempel PEM baru di form <strong>Ubah</strong> server itu.
    </div>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Daftar Server</h2>
    <span class="text-muted small"><?= count($servers) ?> server (<?= (int) $agentCount ?> agent)</span>
  </div>
  <?php if ($servers === []): ?>
    <div class="card-body text-muted small">Belum ada server terdaftar.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Server</th>
          <th>Endpoint</th>
          <th>Status</th>
          <th>Rentang port</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($servers as $s): ?>
        <?php
          $__id = (string) ($s['id'] ?? '');
          $__name = trim((string) ($s['name'] ?? '')) !== '' ? (string) $s['name'] : $__id;
          $__isLocal = strtolower(trim((string) ($s['kind'] ?? ''))) === 'local' || $__id === $localId;
          $__enabled = !in_array(strtolower(trim((string) ($s['enabled'] ?? '1'))), ['', '0', 'false', 'no', 'off', 'tidak'], true);
          $__hasToken = !empty($s['has_token']);

          $__status = strtolower(trim((string) ($s['status'] ?? '')));
          $__status = $__status !== '' ? $__status : 'unknown';
          $__statusBadge = $__status === 'online' ? 'badge-running' : ($__status === 'offline' ? 'badge-error' : 'badge-unknown');

          $__fingerprint = (string) ($s['tls_fingerprint'] ?? '');
          $__fingerprintShort = $__fingerprint === ''
              ? ''
              : (strlen($__fingerprint) > 16 ? substr($__fingerprint, 0, 16) . '…' : $__fingerprint);

          $__range = is_array($s['port_range'] ?? null) ? $s['port_range'] : null;
          $__rangeText = $__range !== null
              ? trim((string) ($__range['start'] ?? '')) . '–' . trim((string) ($__range['end'] ?? ''))
              : '';

          $__version = trim((string) ($s['agent_version'] ?? ''));
          $__capCount = is_array($s['capabilities'] ?? null) ? count($s['capabilities']) : 0;
          $__seen = trim((string) ($s['last_seen_at'] ?? ''));
          $__domain = trim((string) ($s['domain'] ?? ''));
          $__baseUrl = trim((string) ($s['base_url'] ?? ''));
        ?>
        <tr>
          <td>
            <strong><?= e($__name) ?></strong>
            <?php if ($__isLocal): ?>
              <span class="badge badge-stopped ms-1">lokal</span>
            <?php else: ?>
              <span class="badge badge-deploying ms-1">agent</span>
            <?php endif; ?>
            <?php if (!$__enabled): ?><span class="badge badge-stopped ms-1">nonaktif</span><?php endif; ?>
            <?php if ($__hasToken): ?>
              <span class="badge badge-stopped ms-1" title="Token agent tersimpan — tidak pernah ditampilkan ulang">token tersimpan</span>
            <?php elseif (!$__isLocal): ?>
              <span class="badge badge-error ms-1">tanpa token</span>
            <?php endif; ?>
            <?php if ($__fingerprintShort !== ''): ?>
              <span class="text-muted small d-block mono" title="Fingerprint SHA-256 sertifikat agent: <?= e($__fingerprint) ?>">pin <?= e($__fingerprintShort) ?></span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($__isLocal): ?>
              <span class="text-muted">dashboard ini (server lokal) — tidak lewat jaringan</span>
            <?php elseif ($__baseUrl !== ''): ?>
              <span class="mono"><?= e($__baseUrl) ?></span>
            <?php else: ?>
              <span class="text-muted">&mdash;</span>
            <?php endif; ?>
            <span class="text-muted d-block">
              domain:
              <?php if ($__domain !== ''): ?>
                <span class="mono"><?= e($__domain) ?></span>
              <?php else: ?>
                ikut <span class="mono">APP_DOMAIN</span><?= $defaultDomain !== '' ? ' (' . e($defaultDomain) . ')' : '' ?>
              <?php endif; ?>
            </span>
          </td>
          <td>
            <span class="badge <?= e($__statusBadge) ?>" data-server-status="<?= e($__id) ?>"><?= e($__status) ?></span>
            <span class="text-muted small d-block">
              <?php if ($__version !== ''): ?><span class="mono"><?= e($__version) ?></span><?php endif; ?>
              <?php if ($__capCount > 0): ?> · <?= (int) $__capCount ?> <?= $__capCount === 1 ? 'capability' : 'capabilities' ?><?php endif; ?>
              <?php if ($__seen !== ''): ?> · terakhir <span class="mono"><?= e($__seen) ?></span><?php endif; ?>
            </span>
            <span class="text-muted small d-block" data-server-result="<?= e($__id) ?>" aria-live="polite"></span>
          </td>
          <td class="small">
            <?php if ($__rangeText !== ''): ?>
              <span class="mono"><?= e($__rangeText) ?></span>
            <?php else: ?>
              <span class="text-muted">ikut config</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <button type="button" class="btn btn-outline-secondary btn-sm js-server-test"
                    data-id="<?= e($__id) ?>"
                    title="Hubungi /healthz + /version agent ini">Test connection</button>
            <a class="btn btn-outline-secondary btn-sm" href="/servers/<?= e(rawurlencode($__id)) ?>">Ubah</a>
            <?php if (!$__isLocal): ?>
              <form method="post" action="/servers/<?= e(rawurlencode($__id)) ?>/delete" class="d-inline"
                    onsubmit="return confirm('Hapus server <?= e($__name) ?>? App yang dideploy di server itu tidak ikut dihapus, tetapi berhenti dikelola dashboard.');">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger btn-sm" title="Hapus server">✕</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<script src="/js/server.js?v=1"></script>

<?php include app_path() . '/view/partials/footer.php'; ?>
