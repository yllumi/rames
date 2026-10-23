<?php
/**
 * Panel monitoring resource (SPECS §8d) — dipakai halaman `/monitor`. Semua angka
 * diisi `public/js/monitor.js` dari endpoint JSON (metrik host dari `/proc`,
 * stats container dari Engine).
 *
 * Penanda `data-m="<kunci>"` adalah kontrak dengan `public/js/monitor.js`;
 * kunci yang sama boleh muncul lebih dari sekali (diisi semua).
 *
 * Variabel yang harus diset pemanggil:
 * - $monitorPanelId      string id unik panel (mis. `monitor-panel`)
 * - $monitorEndpoint     string endpoint JSON sumber data lengkap
 * - $monitorHostEndpoint string endpoint JSON metrik host saja (polling)
 * - $monitorPollMs       int interval polling host (ms, 0 = tanpa polling)
 * - $monitorEmptyNote    string pesan saat tidak ada container (opsional)
 * - $isAdmin             bool admin (opsional) — dipakai `data-is-admin`: non-admin
 *                        hanya melihat baris server tempat ia punya container
 *                        (nama server lain tidak pernah dibocorkan ke halaman)
 *
 * Kartu "Semua Server" (multi-server F3c) HANYA berisi rangka + `<template>`:
 * seluruh angka diisi `public/js/monitor.js` dari `data.servers[]`
 * (`MonitorController::overview()`), jadi tidak ada data server yang dibakar ke
 * HTML dan satu sumber render (JS). Rangka di sini tetap penting sebagai kontrak
 * markup: badge `online`/`offline`/`unknown` sudah ada di template sehingga
 * server `offline` ditampilkan sebagai offline (bukan error) tanpa perlu
 * merakit class dari nilai server (anti-XSS: status dipetakan ke tiga varian baku).
 */
$monitorPanelId = $monitorPanelId ?? 'monitor-panel';
$monitorEndpoint = $monitorEndpoint ?? '/api/monitor/overview';
$monitorHostEndpoint = $monitorHostEndpoint ?? '/api/monitor/host';
$monitorPollMs = (int) ($monitorPollMs ?? 0);
$monitorEmptyNote = $monitorEmptyNote ?? 'Tidak ada container yang bisa ditampilkan.';
$isAdmin = (bool) ($isAdmin ?? false);
?>
<div class="monitor-panel" id="<?= e($monitorPanelId) ?>" data-monitor-panel
     data-endpoint="<?= e($monitorEndpoint) ?>" data-host-endpoint="<?= e($monitorHostEndpoint) ?>"
     data-poll-ms="<?= $monitorPollMs ?>"
     data-is-admin="<?= $isAdmin ? '1' : '0' ?>"
     data-empty-note="<?= e($monitorEmptyNote) ?>">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-muted small" data-m="status">Belum dimuat.</div>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-m="refresh"
            title="Ambil ulang metrik dari Docker Engine & /proc host">↻ Refresh</button>
  </div>

  <div class="alert alert-warning py-2 small d-none" role="alert" data-m="error"></div>

  <div class="row row-cols-2 row-cols-lg-3 row-cols-xxl-5 g-3 mb-3">
    <div class="col">
      <div class="card h-100">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-baseline">
            <span class="text-muted small">CPU host</span>
            <span class="text-muted small" data-m="host-cpu-count">—</span>
          </div>
          <div class="h4 mb-1" data-m="host-cpu">—</div>
          <div class="progress" style="height:6px" role="progressbar" aria-label="Pemakaian CPU host">
            <div class="progress-bar bg-primary" data-m="host-cpu-bar" style="width:0"></div>
          </div>
          <div class="text-muted small mt-1">load <span data-m="host-load">—</span></div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card h-100">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-baseline">
            <span class="text-muted small">Memori host</span>
            <span class="text-muted small" data-m="host-mem-percent">—</span>
          </div>
          <div class="h4 mb-1" data-m="host-mem">—</div>
          <div class="progress" style="height:6px" role="progressbar" aria-label="Pemakaian memori host">
            <div class="progress-bar bg-primary" data-m="host-mem-bar" style="width:0"></div>
          </div>
          <div class="text-muted small mt-1" data-m="host-mem-sub">—</div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card h-100">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-baseline">
            <span class="text-muted small" title="Pemakaian filesystem tempat app & volume disimpan (storage server)">Storage server</span>
            <span class="text-muted small" data-m="host-disk-percent">—</span>
          </div>
          <div class="h4 mb-1" data-m="host-disk">—</div>
          <div class="progress" style="height:6px" role="progressbar" aria-label="Pemakaian storage server">
            <div class="progress-bar bg-primary" data-m="host-disk-bar" style="width:0"></div>
          </div>
          <div class="text-muted small mt-1" data-m="host-disk-sub">—</div>
          <div class="text-muted small font-monospace text-truncate" data-m="host-disk-path" title="Path yang diukur (filesystem host yang di-mount ke dashboard)">—</div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card h-100">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-baseline">
            <span class="text-muted small">Container</span>
            <span class="text-muted small" data-m="totals-cpu">—</span>
          </div>
          <div class="h4 mb-1" data-m="totals">—</div>
          <div class="text-muted small" data-m="totals-mem">—</div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card h-100">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-baseline">
            <span class="text-muted small">Uptime host</span>
            <span class="text-muted small" data-m="host-proc">—</span>
          </div>
          <div class="h4 mb-1" data-m="host-uptime">—</div>
          <div class="text-muted small">volume terpakai: <span data-m="disk">…</span></div>
          <div class="text-muted small" data-m="scope">—</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Semua server (multi-server). Tersembunyi sampai data tiba; hanya muncul
       bila ada lebih dari satu server (satu server sudah terwakili kartu host). -->
  <div class="card mb-3 d-none" data-m="servers-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h2 class="h6 mb-0">Semua Server</h2>
      <span class="text-muted small" data-m="servers-note">Memuat …</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Server</th>
            <th>Tipe</th>
            <th>Status</th>
            <th class="text-end" title="Jumlah container di server itu (dalam tanda kurung: yang berjalan)">Container</th>
            <th class="text-end" title="Total CPU seluruh container di server itu">CPU</th>
            <th title="Baris pertama: memori seluruh container; baris kedua: memori host server itu">Memori</th>
            <th class="text-end" title="Baris pertama: volume Docker terpakai (hanya server lokal). Baris kedua: storage server (terpakai / total · sisa).">Disk</th>
            <th>Diperbarui</th>
          </tr>
        </thead>
        <tbody data-m="servers">
          <tr><td colspan="8" class="text-muted small">Memuat …</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer text-muted small">
      Angka tiap server dibaca dari <strong>server itu sendiri</strong> — server <span class="badge badge-error">offline</span>
      tetap ditampilkan sebagai offline (metriknya tidak tersedia) dan tidak memengaruhi server lain.
      Metrik host yang dipoll berkala di atas hanya milik server lokal (server ini).
      <?php if ($isAdmin): ?>
        Uji koneksi &amp; perbaikan konfigurasi ada di halaman <a href="/servers">Servers</a>.
      <?php else: ?>
        Server lain (tempat Anda tidak punya app) tidak ditampilkan di sini.
      <?php endif; ?>
    </div>
    <template data-m="server-row">
      <tr data-ms="row">
        <td>
          <span data-ms="name">—</span>
          <span class="badge badge-stopped ms-1 d-none" data-ms="badge-self">server ini</span>
          <span class="badge badge-stopped ms-1 d-none" data-ms="badge-disabled">nonaktif</span>
          <div class="text-muted small d-none" data-ms="note"></div>
        </td>
        <td class="small"><span class="badge badge-deploying" data-ms="kind">agent</span></td>
        <td>
          <span class="badge badge-running d-none" data-ms="status-online">online</span>
          <span class="badge badge-error d-none" data-ms="status-offline">offline</span>
          <span class="badge badge-unknown d-none" data-ms="status-unknown">unknown</span>
        </td>
        <td class="text-end small" data-ms="containers">—</td>
        <td class="text-end small" data-ms="cpu">—</td>
        <td class="small">
          <div data-ms="mem">—</div>
          <div class="text-muted" data-ms="mem-host">—</div>
        </td>
        <td class="text-end small">
          <div data-ms="disk">—</div>
          <div class="text-muted" data-ms="disk-host">—</div>
        </td>
        <td class="small text-muted" data-ms="at">—</td>
      </tr>
    </template>
  </div>

  <div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h2 class="h6 mb-0">Resource per container</h2>
      <span class="text-muted small" data-m="scope">—</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>App / Project</th>
            <th>Container</th>
            <th>Status</th>
            <th>Uptime</th>
            <th class="text-end">Restart</th>
            <th class="text-end">CPU</th>
            <th>Memori</th>
            <th class="text-end">PID</th>
          </tr>
        </thead>
        <tbody data-m="rows">
          <tr><td colspan="8" class="text-muted small">Memuat …</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer text-muted small" data-m="footer">
      Memuat data …
    </div>
  </div>
</div>
