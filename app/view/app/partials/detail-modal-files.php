<?php if ($canFiles && !empty($containers)): ?>
<!-- Modal file manager container: jelajah berkas, unggah (multi + progres), unduh,
     edit teks, buat folder, rename, hapus, ekstrak arsip. Logika di /js/app-files.js;
     semua data dari server dirender via textContent (anti-XSS). -->
<div class="modal fade" id="files-modal" tabindex="-1" aria-labelledby="files-title" aria-hidden="true"
     data-app="<?= e($app['id']) ?>" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="height:90vh;">
    <div class="modal-content h-100">
      <div class="modal-header py-2 gap-2 flex-wrap">
        <h5 class="modal-title small mb-0" id="files-title">📁 Files</h5>
        <span class="text-muted small mono" id="files-container"></span>
        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="files-refresh"
                  title="Muat ulang daftar" aria-label="Muat ulang daftar berkas">↻ Refresh</button>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="files-toolbar" tabindex="-1">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="files-up"
                  title="Naik ke folder induk" aria-label="Naik ke folder induk" disabled>⬆ Naik</button>
          <nav aria-label="Lokasi folder" class="flex-grow-1" style="min-width:0;">
            <ol class="breadcrumb mb-0 small flex-nowrap" id="files-breadcrumb" style="overflow-x:auto;"></ol>
          </nav>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="files-mkdir"
                    title="Buat folder baru" aria-label="Buat folder baru">📁+ Buat folder</button>
            <button type="button" class="btn btn-outline-primary btn-sm" id="files-upload-btn"
                    title="Unggah satu atau beberapa berkas" aria-label="Unggah berkas">⬆ Unggah</button>
            <input type="file" id="files-upload-input" class="d-none" multiple
                   aria-label="Pilih berkas untuk diunggah">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="files-extract-btn"
                    title="Ekstrak arsip .zip / .tar.gz di folder ini" aria-label="Ekstrak arsip">🗜 Ekstrak</button>
          </div>
        </div>
        <p class="text-muted small mb-2">Batas <strong>64 MB</strong> per berkas. Arsip <span class="mono">.zip</span> /
          <span class="mono">.tar.gz</span> bisa diekstrak langsung di container.</p>
        <div id="files-alert" class="alert alert-danger py-2 small d-none" role="alert"></div>
        <div id="files-uploads" class="mb-2"></div>

        <div id="files-list-pane" style="max-height:85%; overflow:auto;">
          <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
              <thead>
                <tr><th>Nama</th><th>Ukuran</th><th>Diubah</th><th>Mode</th><th class="text-end">Aksi</th></tr>
              </thead>
              <tbody id="files-entries"></tbody>
            </table>
          </div>
          <div id="files-empty" class="text-muted small py-3 text-center d-none">Folder ini kosong.</div>
          <div id="files-truncated" class="text-warning small mt-2 d-none">
            Daftar dipotong (terlalu banyak entri) — sebagian berkas tidak ditampilkan.
          </div>
        </div>

        <div id="files-edit-pane" class="d-none">
          <label class="form-label small" for="files-editor">Edit <span class="mono" id="files-edit-name"></span></label>
          <textarea id="files-editor" class="form-control form-control-sm mono" rows="18" spellcheck="false"
                    aria-describedby="files-edit-meta" style="white-space:pre; overflow:auto;"></textarea>
          <div class="d-flex justify-content-between align-items-center mt-2 gap-2 flex-wrap">
            <span class="text-muted small" id="files-edit-meta"></span>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="files-edit-cancel">Batal</button>
              <button type="button" class="btn btn-primary btn-sm" id="files-edit-save">
                <span id="files-edit-spinner" class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>Simpan
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-1">
        <span class="text-muted small me-auto" id="files-status" aria-live="polite"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
