<?php
$pageTitle = 'Monitor';
$active = 'monitor';
$isAdmin = $isAdmin ?? is_admin();
$appCount = $appCount ?? 0;

// Variabel panel monitoring (dipakai teks di bawah + partial panel)
$monitorPanelId = 'monitor-panel';
$monitorEndpoint = '/api/monitor/overview';
$monitorHostEndpoint = '/api/monitor/host';
$monitorPollMs = (int) ($pollMs ?? 0);
$monitorEmptyNote = 'Tidak ada container yang bisa ditampilkan.';
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Monitor</h1>
    <p class="text-muted mb-0">
      Pemakaian resource tiap container dan <strong>total VM</strong> (CPU, memori, load, uptime host).
      Metrik host dibaca dari <span class="mono">/proc</span> host, metrik container dari Docker Engine
      (<span class="mono">stats</span> + <span class="mono">inspect</span>).
      <?php if ($monitorPollMs > 0): ?>
        Kartu <strong>host</strong> diperbarui otomatis tiap <?= round($monitorPollMs / 1000) ?> detik
        selama halaman ini terbuka (hanya membaca <span class="mono">/proc</span>, tidak membebani
        Docker Engine); tabel container diperbarui lewat tombol <strong>Refresh</strong>.
      <?php else: ?>
        Data dimuat sekali saat halaman dibuka — diperbarui lewat tombol <strong>Refresh</strong>.
      <?php endif; ?>
    </p>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/apps">&larr; Apps</a>
</div>

<?php if (!$isAdmin): ?>
  <div class="alert alert-info py-2 small" role="alert">
    Anda melihat container dari <strong><?= (int) $appCount ?> app</strong> yang boleh Anda akses.
    Container di luar app dashboard (mis. container eksternal) hanya terlihat oleh admin.
  </div>
<?php else: ?>
  <div class="alert alert-info py-2 small" role="alert">
    Sebagai <strong>admin</strong>, panel ini menampilkan <strong>semua</strong> container di host —
    termasuk yang bukan app dashboard (ditandai <em>eksternal</em>).
  </div>
<?php endif; ?>

<?php
include app_path() . '/view/monitor/partials/panel.php';
?>

<script src="/js/monitor.js?v=1"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>
