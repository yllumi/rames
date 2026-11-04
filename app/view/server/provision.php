<?php
/**
 * Form provisioning server agent lewat Ansible (opsi B) — admin-only.
 *
 * Kontrak payload dari `ProvisionController::form()`/`store()` (jangan diubah
 * dari sisi view):
 *   $defaults — nilai awal form (name '' … ssh_user 'root', ssh_port 22,
 *               port_range dari config, agent_port 8443, bind '0.0.0.0:8443',
 *               playbooks = daftar kunci playbook yang diizinkan);
 *   $values   — input lama setelah validasi gagal (field scalar; `private_key`
 *               TIDAK pernah ada — rahasia tidak dirender ulang), boleh kosong;
 *   $errors   — daftar pesan galat siap tampil;
 *   $active   — `'servers'` (penanda menu aktif).
 *
 * View hanya merender: validasi/normalisasi ada di `ProvisionInput`, job di
 * `ProvisionJobStore`, dan pengiriman form + polling status ditangani
 * `public/js/provision.js` (POST `/servers/provision`, GET
 * `/servers/provision/status?job=&offset=`, POST `/servers/provision/cancel`).
 *
 * CSRF: `csrf_field()` di dalam form (middleware hanya membaca body). Tombol
 * Batal mengirim `_token` dari field tersembunyi yang sama lewat `FormData`.
 */
$defaults = is_array($defaults ?? null) ? $defaults : [];
$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? array_values(array_filter($errors, 'is_scalar')) : [];
$active = 'servers';

$pageTitle = 'Provision Server';

/** Nilai field: input lama > default controller > fallback. */
$field = static function (string $key, string $fallback = '') use ($defaults, $values): string {
    $value = array_key_exists($key, $values) ? $values[$key] : ($defaults[$key] ?? $fallback);

    return is_scalar($value) ? (string) $value : $fallback;
};

$playbooks = is_array($defaults['playbooks'] ?? null) ? array_values($defaults['playbooks']) : ['provision-agent'];
$playbookLabels = [
    'provision-agent' => 'Pasang agent (provision-agent.yml)',
    'verify-agent' => 'Verifikasi agent (verify-agent.yml)',
];

$controlPlaneIps = $defaults['control_plane_ips'] ?? '';
if (is_array($controlPlaneIps)) {
    $controlPlaneIps = implode(', ', array_map(static fn ($ip): string => is_scalar($ip) ? trim((string) $ip) : '', $controlPlaneIps));
}
$controlPlaneIpsValue = array_key_exists('control_plane_ips', $values)
    ? (string) $values['control_plane_ips']
    : (string) $controlPlaneIps;

$manageFirewall = !in_array(strtolower(trim($field('manage_firewall', '1'))), ['', '0', 'false', 'no', 'off'], true);

$sslChallenges = [
    '' => 'Ikuti default role',
    'http' => 'HTTP-01 (port 80)',
    'dns-cloudflare' => 'DNS-01 Cloudflare',
];
$sslCaServers = [
    '' => 'Ikuti default role',
    'production' => 'Production (sertifikat nyata)',
    'staging' => 'Staging (uji, tidak dipercaya browser)',
];

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Servers', 'href' => '/servers'],
    ['label' => 'Provision', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Provision Server</h1>
    <p class="text-muted mb-0">
      Pasang <strong>rames-agent</strong> di server tujuan lewat Ansible yang dijalankan control plane
      (helper container). Server baru mendaftar sendiri setelah playbook selesai.
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="/servers">Kembali ke Servers</a>
</div>

<div class="alert alert-warning" role="alert">
  <p class="mb-2"><strong>Sebelum mengirim:</strong></p>
  <ul class="mb-0 small">
    <li>
      <strong>Kunci privat SSH</strong> disimpan di control plane dengan mode <span class="mono">0600</span>
      (di luar basis data) dan hanya dipasang <em>read-only</em> ke helper Ansible untuk job ini.
      Jangan pernah mengirim kunci lewat chat, tiket, atau commit — tempel hanya di form ini.
    </li>
    <li>
      <strong>Host key server dipin TOFU</strong> saat worker mulai: kunci SSH host dibaca sekali lalu
      disimpan. Fingerprint <span class="mono">SHA256:…</span> muncul di log status — bandingkan manual
      dengan server tujuan. Bila kunci berubah, job digagalkan (tidak ada penimpaan otomatis).
    </li>
    <li>
      Control plane butuh <strong>egress internet</strong> untuk menarik image helper Ansible
      (<span class="mono">docker pull</span>) dan akses SSH ke server tujuan (port SSH harus terbuka).
    </li>
    <li>
      Proses berjalan di latar belakang; halaman ini memantau status tiap 2 detik. Menutup tab
      <strong>tidak</strong> menghentikan job — buka halaman ini lagi untuk memantau job berikutnya.
    </li>
  </ul>
</div>

<?php if ($errors !== []): ?>
<section class="alert alert-danger" role="alert">
  <p class="mb-2"><strong>Provisioning belum bisa dimulai:</strong></p>
  <ul class="mb-0 small">
    <?php foreach ($errors as $__error): ?>
      <li><?= e($__error) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card mb-4">
  <div class="card-header">
    <h2 class="h6 mb-0">Detail server tujuan &amp; agent</h2>
  </div>
  <div class="card-body">
    <form id="provision-form" method="post" action="/servers/provision" autocomplete="off">
      <?= csrf_field() ?>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="provision-name">Nama server</label>
          <input class="form-control mono" id="provision-name" name="name" required maxlength="64"
                 pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" value="<?= e($field('name')) ?>"
                 placeholder="vps-jakarta" autocomplete="off">
          <div class="form-text">Huruf kecil, angka, dan strip. Menjadi nama host inventori &amp; slot kunci SSH.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="provision-host">Host / IP server</label>
          <input class="form-control mono" id="provision-host" name="host" required maxlength="253"
                 value="<?= e($field('host')) ?>" placeholder="203.0.113.10 atau vps.contoh.id" autocomplete="off">
          <div class="form-text">IPv4 atau FQDN yang bisa dijangkau control plane.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="provision-ssh-user">User SSH</label>
          <input class="form-control mono" id="provision-ssh-user" name="ssh_user" required maxlength="32"
                 value="<?= e($field('ssh_user', 'root')) ?>" placeholder="root" autocomplete="off">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="provision-ssh-port">Port SSH</label>
          <input class="form-control mono" id="provision-ssh-port" name="ssh_port" type="number" min="1" max="65535"
                 required value="<?= e($field('ssh_port', '22')) ?>" placeholder="22">
        </div>

        <div class="col-12">
          <label class="form-label" for="provision-private-key">Kunci privat SSH</label>
          <textarea class="form-control mono" id="provision-private-key" name="private_key" rows="6" required
                    autocomplete="off" autocapitalize="off" spellcheck="false"
                    placeholder="Tempel isi kunci privat (OpenSSH/PEM) di sini — tidak ditampilkan lagi setelah dikirim."></textarea>
          <div class="form-text">
            Otomatis dihapus dari form setelah dikirim. Tidak pernah dicatat di log atau ditampilkan ulang.
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="provision-domain">Domain server</label>
          <input class="form-control mono" id="provision-domain" name="domain" required maxlength="253"
                 value="<?= e($field('domain')) ?>" placeholder="vps.contoh.id" autocomplete="off">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="provision-email">Email (Let's Encrypt)</label>
          <input class="form-control mono" id="provision-email" name="email" type="email" required maxlength="254"
                 value="<?= e($field('email')) ?>" placeholder="ops@contoh.id" autocomplete="off">
        </div>

        <div class="col-md-3">
          <label class="form-label" for="provision-range-start">Rentang port — mulai</label>
          <input class="form-control mono" id="provision-range-start" name="port_range_start" type="number"
                 min="1" max="65535" required value="<?= e($field('port_range_start', '30000')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="provision-range-end">Rentang port — selesai</label>
          <input class="form-control mono" id="provision-range-end" name="port_range_end" type="number"
                 min="1" max="65535" required value="<?= e($field('port_range_end', '30999')) ?>">
          <div class="form-text">Harus lebih besar dari port mulai.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="provision-agent-port">Port agent</label>
          <input class="form-control mono" id="provision-agent-port" name="agent_port" type="number"
                 min="1" max="65535" required value="<?= e($field('agent_port', '8443')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="provision-bind">Bind agent</label>
          <input class="form-control mono" id="provision-bind" name="bind" maxlength="64"
                 value="<?= e($field('bind', '0.0.0.0:8443')) ?>" placeholder="0.0.0.0:8443" autocomplete="off">
        </div>

        <div class="col-md-6">
          <label class="form-label" for="provision-ip">IP agent (opsional)</label>
          <input class="form-control mono" id="provision-ip" name="ip" maxlength="15"
                 value="<?= e($field('ip')) ?>" placeholder="biarkan kosong bila sama dengan host" autocomplete="off">
          <div class="form-text">Dipakai untuk SAN sertifikat &amp; URL onboarding.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="provision-control-plane-ips">IP control plane (opsional)</label>
          <input class="form-control mono" id="provision-control-plane-ips" name="control_plane_ips"
                 value="<?= e($controlPlaneIpsValue) ?>" placeholder="10.0.0.1, 10.0.0.2" autocomplete="off">
          <div class="form-text">Pisahkan dengan koma — alamat yang boleh memanggil API agent.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="provision-playbook">Playbook</label>
          <select class="form-select" id="provision-playbook" name="playbook" required>
            <?php foreach ($playbooks as $__playbook): ?>
              <?php $__playbook = (string) $__playbook; ?>
              <option value="<?= e($__playbook) ?>" <?= $field('playbook', 'provision-agent') === $__playbook ? 'selected' : '' ?>>
                <?= e($playbookLabels[$__playbook] ?? $__playbook) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <div class="form-check mb-2">
            <input type="hidden" name="manage_firewall" value="0">
            <input class="form-check-input" type="checkbox" id="provision-manage-firewall"
                   name="manage_firewall" value="1" <?= $manageFirewall ? 'checked' : '' ?>>
            <label class="form-check-label" for="provision-manage-firewall">
              Kelola firewall di server tujuan (UFW) agar port agent terbuka
            </label>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="provision-ssl-challenge">Tantangan SSL (opsional)</label>
          <select class="form-select" id="provision-ssl-challenge" name="ssl_challenge">
            <?php foreach ($sslChallenges as $__value => $__label): ?>
              <option value="<?= e($__value) ?>" <?= $field('ssl_challenge') === (string) $__value ? 'selected' : '' ?>><?= e($__label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="provision-ssl-ca">CA Let's Encrypt (opsional)</label>
          <select class="form-select" id="provision-ssl-ca" name="ssl_ca_server">
            <?php foreach ($sslCaServers as $__value => $__label): ?>
              <option value="<?= e($__value) ?>" <?= $field('ssl_ca_server') === (string) $__value ? 'selected' : '' ?>><?= e($__label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mt-4">
        <button class="btn btn-primary" type="submit" data-provision-submit>
          <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-provision-spinner></span>
          Jalankan provisioning
        </button>
        <a class="btn btn-outline-secondary" href="/servers">Batal &amp; kembali</a>
      </div>
    </form>
  </div>
</section>

<section class="card mb-4" data-provision-panel hidden>
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h6 mb-0">Status provisioning</h2>
    <div class="d-flex align-items-center gap-2">
      <span class="small text-muted" data-provision-job></span>
      <button type="button" class="btn btn-outline-danger btn-sm" data-provision-cancel>Batal</button>
    </div>
  </div>
  <div class="card-body">
    <p class="mb-2" data-provision-status aria-live="polite">Menunggu job …</p>
    <div class="small text-muted mb-1">Log terbaru (8–200 baris terakhir):</div>
    <pre class="small bg-body-tertiary border rounded p-2 mb-0" data-provision-log
         style="max-height: 22rem; overflow: auto; white-space: pre-wrap;"></pre>
  </div>
</section>

<script src="/js/provision.js?v=1"></script>

<?php include app_path() . '/view/partials/footer.php'; ?>
