<!-- Modal one-shot run command (non-interaktif) -->
<div class="modal fade" id="run-modal" tabindex="-1" aria-labelledby="run-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="run-form" data-app="<?= e($app['id']) ?>">
        <div class="modal-header py-2">
          <h5 class="modal-title small mb-0 mono" id="run-title">Run command</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="run-container">
          <label class="form-label small" for="run-command">Perintah (dijalankan sebagai <code>sh -c</code> di dalam container):</label>
          <textarea id="run-command" class="form-control form-control-sm mono" rows="3"
                    placeholder="mis. ls -la /app&#10;php artisan migrate --force&#10;cat /etc/os-release"></textarea>
          <div id="run-error" class="alert alert-danger py-2 small mt-3 mb-0 d-none" role="alert"></div>
          <pre id="run-output" class="mt-3 mb-1 p-2 rounded border mono small d-none"
               style="max-height:320px; overflow:auto; background:#0d1117; color:#e6e6e6;"></pre>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <span id="run-exit" class="small text-muted"></span>
            <button type="submit" class="btn btn-primary btn-sm">
              <span id="run-spinner" class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
              Jalankan
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
