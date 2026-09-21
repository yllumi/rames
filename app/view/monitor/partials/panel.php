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
 */
$monitorPanelId = $monitorPanelId ?? 'monitor-panel';
$monitorEndpoint = $monitorEndpoint ?? '/api/monitor/overview';
$monitorHostEndpoint = $monitorHostEndpoint ?? '/api/monitor/host';
$monitorPollMs = (int) ($monitorPollMs ?? 0);
$monitorEmptyNote = $monitorEmptyNote ?? 'Tidak ada container yang bisa ditampilkan.';
?>
<div class="monitor-panel" id="<?= e($monitorPanelId) ?>" data-monitor-panel
     data-endpoint="<?= e($monitorEndpoint) ?>" data-host-endpoint="<?= e($monitorHostEndpoint) ?>"
     data-poll-ms="<?= $monitorPollMs ?>"
     data-empty-note="<?= e($monitorEmptyNote) ?>">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-muted small" data-m="status">Belum dimuat.</div>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-m="refresh"
            title="Ambil ulang metrik dari Docker Engine & /proc host">↻ Refresh</button>
  </div>

  <div class="alert alert-warning py-2 small d-none" role="alert" data-m="error"></div>

  <div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
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

    <div class="col-6 col-xl-3">
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

    <div class="col-6 col-xl-3">
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

    <div class="col-6 col-xl-3">
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
