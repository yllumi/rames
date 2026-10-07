<!-- ============ Terminal container (docker exec) ============ -->

<!-- Modal terminal interaktif (xterm.js + SSE) -->
<div class="modal fade" id="terminal-modal" tabindex="-1" aria-labelledby="terminal-title" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title small mb-0 mono" id="terminal-title">Terminal</h5>
        <span id="terminal-status" class="small text-muted ms-2 me-auto"></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body p-0">
        <div id="terminal-host" style="height:420px; background:#101014;"></div>
      </div>
      <div class="modal-footer py-1">
        <span class="text-muted small me-auto">Ketik perintah shell di dalam terminal. Ketik <code>exit</code> untuk menutup sesi.</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
