<?php
/**
 * Form server (`/servers/create` & `/servers/{id}`) — admin-only (middleware &
 * controller menolak non-admin dengan 404; nav hanya lapisan kedua).
 *
 * Kontrak payload dari `ServerController::createForm()`/`editForm()`/
 * `invalidForm()` (jangan diubah dari sisi view):
 *   $server — `null` (create) atau dokumen server; pada mode edit `token` SELALU
 *             `''` (rahasia tidak pernah dirender; kosong saat submit = tidak
 *             diubah) + `has_token`, sedangkan `tls_cert_pem` terisi karena
 *             sertifikat self-signed bersifat publik (plan §11.5). Setelah
 *             validasi gagal, `$server` berisi input lama (bisa tanpa `id`).
 *   $errors — array pesan galat siap tampil;
 *   $isEdit — bool.
 *
 * Field form yang dibaca controller: name, kind, base_url, token, tls_cert_pem,
 * domain, notes, enabled, dan `port_range_start`/`port_range_end`.
 *
 * Blok bantu (bukan field) untuk server agent: perintah siap-salin pembaca
 * `AGENT_TOKEN` + `AGENT_TLS_CERT` dari container agent di server tujuan
 * (`docker exec rames-agent …`) — token tidak pernah dirender ulang dashboard,
 * jadi admin butuh jalan mengambilnya tanpa menyalin ulang dari terminal lama.
 *
 * Dua jebakan kontrak yang dijaga di sini:
 *  1. `enabled` tidak dicentang = key absen ⇒ controller memakai default `true`.
 *     Karena itu ada hidden `enabled=0` SEBELUM checkbox. Khusus baris **lokal**
 *     field ini terkunci: checkbox dirender **tanpa** `name` (tidak dikirim),
 *     sebab mengirim `enabled=0` untuk server yang tersimpan `true` akan ditolak
 *     controller sebagai "field terlarang" (LOCAL_LOCKED).
 *  2. Field terkunci server lokal (name/kind/base_url/token/PEM/enabled/port
 *     range) dirender sebagai tampilan read-only **tanpa** `name`: form hanya
 *     mengirim `domain` + `notes`, jadi tidak ada nilai terkunci yang bisa
 *     ditolak. Penjaga sebenarnya tetap di controller.
 */
$isEdit = (bool) ($isEdit ?? false);
$pageTitle = $isEdit ? 'Ubah Server' : 'Tambah Server';
$active = 'servers';

$server = is_array($server ?? null) ? $server : null;
$errors = is_array($errors ?? null) ? array_values($errors) : [];

$isLocal = $server !== null
    && (strtolower(trim((string) ($server['kind'] ?? ''))) === 'local' || (string) ($server['id'] ?? '') === 'local');

$serverId = (string) ($server['id'] ?? '');
$action = $isEdit && $serverId !== '' ? '/servers/' . rawurlencode($serverId) : '/servers';

$name = (string) ($server['name'] ?? '');
$baseUrl = (string) ($server['base_url'] ?? '');
$pem = (string) ($server['tls_cert_pem'] ?? '');
$fingerprint = (string) ($server['tls_fingerprint'] ?? '');
$domain = (string) ($server['domain'] ?? '');
$notes = (string) ($server['notes'] ?? '');
$hasToken = !empty($server['has_token']);

// `enabled` bisa berbentuk bool (dokumen tersimpan) atau teks '0'/'1' (input lama
// yang dirender ulang setelah validasi gagal) — samakan dengan aturan controller.
$enabled = !in_array(strtolower(trim((string) ($server['enabled'] ?? '1'))), ['', '0', 'false', 'no', 'off', 'tidak'], true);

// Rentang port: dokumen menyimpan array {start,end} (bentuk kanonik).
$range = is_array($server['port_range'] ?? null) ? $server['port_range'] : [];
$rangeStart = trim((string) ($range['start'] ?? ''));
$rangeEnd = trim((string) ($range['end'] ?? ''));
$rangeText = $rangeStart !== '' || $rangeEnd !== '' ? $rangeStart . '–' . $rangeEnd : '';

// Domain dasar dashboard: hanya dipakai sebagai placeholder/petunjuk (bukan logika bisnis).
$defaultDomain = trim((string) config('deploy.app_domain', ''));

// Token wajib hanya bila belum ada token tersimpan (mode edit: kosong = tidak diubah).
$tokenRequired = !$hasToken;

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Servers', 'href' => '/servers'],
    ['label' => $isEdit ? 'Ubah Server' : 'Tambah Server', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1"><?= $isEdit ? 'Ubah Server' : 'Tambah Server' ?><?= $isEdit && $name !== '' ? ': ' . e($name) : '' ?></h1>
    <p class="text-muted mb-0">
      <?php if ($isLocal): ?>
        Baris <strong>server lokal</strong> = dashboard ini sendiri. Hanya <strong>Domain</strong> dan
        <strong>Catatan</strong> yang bisa diubah di sini; field identitas/agent dikunci.
      <?php else: ?>
        Server agent dihubungi lewat HTTPS dengan <strong>pinning sertifikat</strong>: tempel URL, token, dan
        <strong>PEM sertifikat</strong> dari keluaran <span class="mono">agent/install.sh</span>. Fingerprint
        dihitung dashboard dari PEM — tidak ada nilai yang diketik manual.
      <?php endif; ?>
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="/servers">← Kembali ke Servers</a>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert">
  <strong>Perubahan tidak disimpan:</strong>
  <ul class="mb-0 small">
    <?php foreach ($errors as $__error): ?>
      <li><?= e((string) $__error) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($isLocal): ?>
<section class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Identitas server lokal</h2>
    <span class="badge badge-stopped" data-locked="1">terkunci</span>
  </div>
  <div class="card-body">
    <p class="text-muted small">
      Nilai berikut datang dari <span class="mono">.env</span>/config dashboard dan tidak bisa diubah dari halaman
      ini — mengirim perubahannya akan ditolak server. Yang bisa disesuaikan: <strong>Domain</strong> &amp;
      <strong>Catatan</strong>.
    </p>
    <dl class="row mb-0 small" data-locked="1">
      <dt class="col-sm-4 text-muted fw-normal">Nama</dt>
      <dd class="col-sm-8 mono"><?= e($name !== '' ? $name : 'local') ?></dd>
      <dt class="col-sm-4 text-muted fw-normal">Jenis</dt>
      <dd class="col-sm-8"><span class="badge badge-stopped">lokal</span></dd>
      <dt class="col-sm-4 text-muted fw-normal">URL agent</dt>
      <dd class="col-sm-8 text-muted">tidak ada (dashboard ini sendiri)</dd>
      <dt class="col-sm-4 text-muted fw-normal">Token agent</dt>
      <dd class="col-sm-8 text-muted">tidak dipakai</dd>
      <dt class="col-sm-4 text-muted fw-normal">Sertifikat TLS (PEM)</dt>
      <dd class="col-sm-8 text-muted">tidak dipakai</dd>
      <dt class="col-sm-4 text-muted fw-normal">Fingerprint sertifikat</dt>
      <dd class="col-sm-8 text-muted">tidak dipakai</dd>
      <dt class="col-sm-4 text-muted fw-normal">Rentang port host</dt>
      <dd class="col-sm-8">
        <?php if ($rangeText !== ''): ?>
          <span class="mono"><?= e($rangeText) ?></span>
        <?php else: ?>
          <span class="text-muted">ikut config (<span class="mono">PORT_RANGE_START/END</span>)</span>
        <?php endif; ?>
      </dd>
      <dt class="col-sm-4 text-muted fw-normal">Aktif</dt>
      <dd class="col-sm-8 mb-0"><?= $enabled ? 'ya' : 'tidak' ?></dd>
    </dl>
  </div>
</section>
<?php endif; ?>

<?php if (!$isLocal): ?>
<section class="card mb-3">
  <div class="card-header">
    <h2 class="h6 mb-0">Ambil token &amp; sertifikat dari server tujuan</h2>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-2">
      Jalankan perintah ini <strong>di server tujuan</strong> (tempat agent berjalan) — nilainya dibaca dari
      container agent yang sudah hidup, jadi tidak perlu menyalin ulang dari terminal instalasi. Akses daemon
      Docker tetap diperlukan (root atau anggota grup <span class="mono">docker</span>); bila user Anda bukan
      keduanya, jalankan dengan <span class="mono">sudo</span>:
    </p>
    <textarea class="form-control mono" id="agent-onboarding-cmd" rows="2" readonly spellcheck="false"
              onclick="this.select()">docker exec rames-agent sh -lc 'printf "TOKEN=%s\n\n" "$AGENT_TOKEN"; cat "$AGENT_TLS_CERT"'</textarea>
    <div class="mt-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyAgentOnboardingCmd()">Salin perintah</button>
    </div>
    <ul class="text-muted small mt-3 mb-0">
      <li>
        Kalau container agent tidak berjalan, jalankan ulang <span class="mono">./agent/install.sh</span> dari
        direktori paket agent di server itu (butuh akses daemon Docker) — blok onboarding
        (URL + token + fingerprint + PEM) dicetak lagi dan token &amp; sertifikat yang sudah ada dipertahankan
        (tanpa <span class="mono">--force</span> tidak ada rotasi).
      </li>
      <li>
        Tempel <strong>seluruh</strong> blok
        <span class="mono">-----BEGIN CERTIFICATE----- … -----END CERTIFICATE-----</span> pada kolom PEM di bawah;
        fingerprint dihitung dashboard dari isi itu.
      </li>
      <li>
        Token &amp; PEM adalah kredensial: jangan ditempel ke chat, tiket, atau commit.
      </li>
    </ul>
  </div>
</section>
<?php endif; ?>

<div class="card form-card">
  <div class="card-body p-4">
    <form method="post" action="<?= e($action) ?>">
      <?= csrf_field() ?>

      <?php if (!$isLocal): ?>
      <div class="mb-3">
        <label class="form-label" for="server-name">Nama server</label>
        <input type="text" class="form-control mono" id="server-name" name="name" value="<?= e($name) ?>"
               required maxlength="32" pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" autocomplete="off"
               title="Huruf kecil a-z, angka, dan strip (-); maksimal 32 karakter" placeholder="vps-jakarta">
        <div class="form-text">Slug unik (huruf kecil/angka/strip) sebagai label server di seluruh dashboard.</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="server-kind">Jenis</label>
        <select class="form-select" id="server-kind" name="kind">
          <option value="agent" selected>Agent — server lain yang dikendalikan lewat agent HTTP</option>
        </select>
        <div class="form-text">
          Server <span class="mono">local</span> (dashboard ini) tidak bisa dibuat dari form — ia sudah ada
          dan dikelola dari <span class="mono">.env</span>/config.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="server-base-url">URL agent</label>
        <input type="url" class="form-control mono" id="server-base-url" name="base_url" value="<?= e($baseUrl) ?>"
               required autocomplete="off" spellcheck="false" placeholder="https://10.0.0.12:8443">
        <div class="form-text">
          Alamat HTTPS agent (port sendiri, bawaan <span class="mono">8443</span>) — dari blok onboarding
          <span class="mono">agent/install.sh</span>. Wajib <span class="mono">https://</span> tanpa path/query.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="server-token">Token agent</label>
        <input type="password" class="form-control mono" id="server-token" name="token" value=""
               autocomplete="new-password" spellcheck="false" <?= $tokenRequired ? 'required' : '' ?>
               placeholder="<?= $hasToken ? 'Tersimpan — biarkan kosong bila tidak diubah' : 'agt_… (dari agent/install.sh)' ?>">
        <div class="form-text">
          <?php if ($hasToken): ?>
            Token sudah tersimpan dan tidak pernah ditampilkan ulang. Biarkan kosong untuk mempertahankannya,
            atau tempel token baru untuk menggantinya.
          <?php else: ?>
            Salin dari keluaran <span class="mono">agent/install.sh</span> di server tujuan — token hanya
            ditampilkan sekali di sana.
          <?php endif; ?>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="server-pem">Sertifikat TLS agent (PEM)</label>
        <textarea class="form-control mono" id="server-pem" name="tls_cert_pem" rows="6" spellcheck="false"
                  <?= $isEdit ? '' : 'required' ?> placeholder="-----BEGIN CERTIFICATE-----"><?= e($pem) ?></textarea>
        <div class="form-text">
          Salin blok <span class="mono">-----BEGIN CERTIFICATE----- … -----END CERTIFICATE-----</span> dari
          <span class="mono">agent/install.sh</span>. Sertifikat agent <strong>self-signed</strong>: inilah pin TLS
          yang dipakai dashboard, jadi tidak ada CA publik yang perlu dipasang.
          <?= $isEdit ? 'Biarkan kosong untuk mempertahankan sertifikat yang tersimpan.' : '' ?>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="server-fingerprint">Fingerprint sertifikat (SHA-256)</label>
        <input type="text" class="form-control mono" id="server-fingerprint" value="<?= e($fingerprint) ?>" readonly
               aria-describedby="server-fingerprint-help" placeholder="dihitung dari PEM saat disimpan">
        <div class="form-text" id="server-fingerprint-help">
          Dihitung <strong>otomatis oleh dashboard</strong> dari PEM di atas saat disimpan — bukan nilai yang
          diketik. Bandingkan dengan fingerprint yang dicetak <span class="mono">agent/install.sh</span> di server
          tujuan untuk memastikan tidak ada salah salin<?= $fingerprint === '' ? ' (terisi setelah PEM disimpan)' : '' ?>.
        </div>
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label" for="server-domain">Domain dasar <span class="text-muted small">(opsional)</span></label>
        <input type="text" class="form-control mono" id="server-domain" name="domain" value="<?= e($domain) ?>"
               autocomplete="off" spellcheck="false" placeholder="<?= e($defaultDomain) ?>">
        <div class="form-text">
          Domain dasar subdomain app di server ini (<span class="mono">{subdomain}.{domain}</span>).
          Kosongkan agar mengikuti <span class="mono">APP_DOMAIN</span> dashboard<?= $defaultDomain !== '' ? ' (' . e($defaultDomain) . ')' : '' ?>.
        </div>
      </div>

      <?php if (!$isLocal): ?>
      <div class="mb-3">
        <label class="form-label" for="server-port-start">Rentang port host <span class="text-muted small">(opsional)</span></label>
        <div class="d-flex align-items-center gap-2">
          <input type="number" class="form-control port-input mono" id="server-port-start" name="port_range_start"
                 value="<?= e($rangeStart) ?>" min="1" max="65535" inputmode="numeric" placeholder="31000">
          <span class="text-muted">–</span>
          <input type="number" class="form-control port-input mono" id="server-port-end" name="port_range_end"
                 value="<?= e($rangeEnd) ?>" min="1" max="65535" inputmode="numeric" placeholder="31999">
        </div>
        <div class="form-text">
          Port host yang boleh dipakai app di server ini (<span class="mono">start &lt; end</span>). Kosongkan
          keduanya agar memakai rentang dari konfigurasi dashboard. Rentang tidak boleh tumpang tindih dengan
          rentang server lokal.
        </div>
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label" for="server-notes">Catatan <span class="text-muted small">(opsional)</span></label>
        <textarea class="form-control" id="server-notes" name="notes" rows="3"
                  placeholder="mis. penyedia, lokasi, siapa yang punya akses"><?= e($notes) ?></textarea>
      </div>

      <div class="form-check mb-4">
        <?php if ($isLocal): ?>
          <?php /* Terkunci: tanpa `name` supaya tidak ikut terkirim (mengirim enabled=0 akan ditolak controller). */ ?>
          <input class="form-check-input" type="checkbox" id="server-enabled" <?= $enabled ? 'checked' : '' ?> disabled>
          <label class="form-check-label" for="server-enabled">
            Aktif <span class="badge badge-stopped ms-1">terkunci</span>
          </label>
          <div class="form-text">Status aktif server lokal mengikuti konfigurasi dashboard.</div>
        <?php else: ?>
          <?php /* Hidden WAJIB sebelum checkbox: tidak dicentang ⇒ kirim 0, dicentang ⇒ checkbox menang. */ ?>
          <input type="hidden" name="enabled" value="0">
          <input class="form-check-input" type="checkbox" name="enabled" value="1" id="server-enabled" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label" for="server-enabled">
            Aktif — server ini boleh dipakai untuk deploy &amp; operasi app
          </label>
          <div class="form-text">Centang dihapus = server dinonaktifkan (tidak ditawarkan saat membuat app baru).</div>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Simpan perubahan' : 'Tambah server' ?></button>
        <a class="btn btn-outline-secondary" href="/servers">Batal</a>
      </div>
    </form>
  </div>
</div>

<?php if (!$isLocal): ?>
<script>
function copyAgentOnboardingCmd() {
  var t = document.getElementById('agent-onboarding-cmd');
  if (!t) return;
  t.select();
  t.setSelectionRange(0, 99999);
  try { navigator.clipboard.writeText(t.value); } catch (e) {}
  try { document.execCommand('copy'); } catch (e) {}
}
</script>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
