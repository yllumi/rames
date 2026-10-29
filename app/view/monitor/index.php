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

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Monitor', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Monitor<?php $__infoTarget = 'monitor-info'; include app_path() . '/view/partials/page-info-button.php'; ?></h1>
    <p class="text-muted mb-0">Pemakaian CPU, memori, storage, load, dan uptime host serta container.</p>
  </div>
</div>

<?php // Penjelasan panjang dipindah ke modal "Tentang halaman ini" (judul cukup satu baris). ?>
<?php ob_start(); ?>
  <p class="text-muted mb-0">
    Pemakaian resource tiap container dan <strong>total VM</strong> (CPU, memori, <strong>storage</strong>, load, uptime host).
    Metrik host dibaca dari <span class="mono">/proc</span> host dan <span class="mono">statvfs</span> filesystem host,
    metrik container dari Docker Engine (<span class="mono">stats</span> + <span class="mono">inspect</span>).
    <?php if ($monitorPollMs > 0): ?>
      Kartu <strong>host</strong> diperbarui otomatis tiap <?= round($monitorPollMs / 1000) ?> detik
      selama halaman ini terbuka (hanya membaca <span class="mono">/proc</span> &amp;
      <span class="mono">statvfs</span>, tidak membebani Docker Engine); tabel container diperbarui
      lewat tombol <strong>Refresh</strong>.
    <?php else: ?>
      Data dimuat sekali saat halaman dibuka — diperbarui lewat tombol <strong>Refresh</strong>.
    <?php endif; ?>
  </p>
<?php $__infoHtml = (string) ob_get_clean(); $__infoId = 'monitor-info'; $__infoTitle = 'Tentang Monitor'; ?>
<?php include app_path() . '/view/partials/page-info-modal.php'; ?>

<?php if (!$isAdmin): ?>
  <div class="alert alert-info py-2 small" role="alert">
    Anda melihat container dari <strong><?= (int) $appCount ?> app</strong> yang boleh Anda akses.
    Container di luar app dashboard (mis. container eksternal) hanya terlihat oleh admin.
  </div>
<?php else: ?>
  <div class="alert alert-info py-2 small" role="alert">
    Sebagai <strong>admin</strong>, panel ini menampilkan <strong>semua</strong> container di host —
    termasuk yang bukan app dashboard (ditandai <em>eksternal</em>).
    Bila dashboard mengendalikan lebih dari satu server, tabel <strong>Semua Server</strong>
    merangkum tiap server (metrik server agent dibaca dari server itu sendiri); server yang
    tidak dapat dihubungi tampil sebagai <strong>offline</strong> tanpa mengganggu server lain.
  </div>
<?php endif; ?>

<?php
include app_path() . '/view/monitor/partials/panel.php';
?>

<script src="/js/monitor.js?v=3"></script>
<?php include app_path() . '/view/partials/footer.php'; ?>
