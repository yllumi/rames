<?php
declare(strict_types=1);

namespace app\library\Provision;

use app\library\Support\ProcessRunner;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runner Ansible untuk provisioning server agent (opsi B: Ansible → control plane).
 *
 * Ansible **tidak** dipasang di host dashboard: satu helper container
 * (`docker run --rm`) menjalankan `ansible-playbook` dari image yang bisa
 * ditarik/di-update terpisah. Pola ini sama dengan {@see \app\library\Nginx\NginxReloader}
 * — argv **array** + `bypass_shell`, tanpa string shell, tanpa escaping manual.
 *
 * Mount (semuanya absolut, ditolak bila relatif atau memuat `:`):
 *   - `<playbooksRoot>:/ansible:ro`        playbook, role, `ansible.cfg`, `group_vars`
 *   - `<jobDir>:/job:rw`                   `inventory.yml`, `extra_vars.yml`, keluaran onboarding
 *   - `<ProvisionAgentKey::privatePath()>:/ssh/id:ro`  kunci privat provisioning bersama
 *     (satu pasangan untuk semua server; public key-nya dipasang operator di tiap VM)
 *   - `<knownHosts>:/ssh/known_hosts:ro`   host key yang sudah diverifikasi
 *
 * `--network host` diperlukan karena playbook menghubungi server target lewat
 * jaringan host (VPN/lan/loopback operator), sama seperti helper Nginx.
 *
 * Imutabel per pemanggilan: tidak ada state lintas-request selain path
 * konfigurasi (yang dibaca dari `config('deploy.*')` pada konstruksi) — aman di
 * worker Webman persistent.
 */
final class AnsibleRunner
{
    /** Label Docker job — satu-satunya cara membatalkan container yang sedang jalan. */
    public const LABEL_JOB = 'rames.provision.job';

    /** Bentuk id job/server yang aman (cegah path traversal & opsi argv palsu). */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Bentuk nama host inventori (`--limit`). Titik diizinkan (FQDN/target
     * SSH), tetapi karakter pertama WAJIB alfanumerik supaya nilainya tidak
     * pernah dibaca `ansible-playbook` sebagai opsi (`--limit -x`).
     */
    private const HOST_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    /** Batas keluaran yang disimpan pemanggil (sama dengan batas pesan job). */
    public const MAX_OUTPUT = 2000;

    private const CONTAINER_KEY_PATH = '/ssh/id';
    private const CONTAINER_KNOWN_HOSTS = '/ssh/known_hosts';

    /**
     * Titik mount playbook di dalam helper container. Dipakai juga sebagai
     * prefiks path playbook (image `alpine/ansible` ber-WorkingDir `/`, jadi
     * path relatif tidak bisa diandalkan).
     */
    private const CONTAINER_PLAYBOOKS = '/ansible';

    private readonly ProcessRunner $runner;
    private readonly string $playbooksRoot;
    private readonly string $workRoot;
    private readonly ?string $keysDir;
    private readonly string $knownHosts;

    /**
     * @param ProcessRunner|null $runner        injeksi untuk uji (tanpa docker)
     * @param string|null        $playbooksRoot direktori `ops/ansible` (host path)
     * @param string|null        $workRoot      akar direktori kerja per job
     * @param string|null        $keysDir       direktori kunci SSH provisioning
     * @param string|null        $knownHosts    berkas known_hosts yang dipasang ke helper
     */
    public function __construct(
        ?ProcessRunner $runner = null,
        ?string $playbooksRoot = null,
        ?string $workRoot = null,
        ?string $keysDir = null,
        ?string $knownHosts = null
    ) {
        $playbooksRoot = self::text($playbooksRoot ?? base_path() . '/ops/ansible');
        $workRoot = self::text($workRoot ?? config('deploy.provision_work_dir', runtime_path('provision')));
        $keysDir = self::text($keysDir ?? config('deploy.ssh_keys_path', base_path() . '/database/keys'));
        $knownHosts = self::text($knownHosts ?? config('deploy.git_known_hosts', base_path() . '/database/keys/known_hosts'));

        $this->runner = $runner ?? new ProcessRunner();
        $this->playbooksRoot = self::assertAbsolute($playbooksRoot, 'playbooks_root');
        $this->workRoot = self::assertAbsolute($workRoot, 'provision_work_dir');
        $this->keysDir = $keysDir === '' ? null : self::assertAbsolute($keysDir, 'ssh_keys_path');
        $this->knownHosts = self::assertAbsolute($knownHosts, 'known_hosts');
    }

    /** Image helper Ansible (dibaca per panggilan, tanpa cache lintas-request). */
    public function image(): string
    {
        $image = self::text(config('deploy.ansible_image', 'alpine/ansible:latest'));

        return $image !== '' ? $image : 'alpine/ansible:latest';
    }

    /** Timeout satu run `ansible-playbook` (detik); nilai tak masuk akal ⇒ 1800. */
    public function timeout(): int
    {
        $timeout = (int) config('deploy.ansible_timeout', 1800);

        return $timeout > 0 ? $timeout : 1800;
    }

    /** Akar direktori kerja (dipakai pemanggil untuk membuat `<workRoot>/<jobId>`). */
    public function workRoot(): string
    {
        return $this->workRoot;
    }

    /** Direktori playbook (host path) — dipakai untuk pemeriksaan prasyarat. */
    public function playbooksRoot(): string
    {
        return $this->playbooksRoot;
    }

    /**
     * Susun argv helper container untuk satu job.
     *
     * **Hanya** menyusun argv, tanpa menjalankan docker. Pemeriksaan keberadaan
     * kunci/known_hosts sengaja ada di {@see self::run()}.
     *
     * Catatan I/O: `ProvisionAgentKey::privatePath()` hanya menghitung path (tidak
     * menulis apa pun); keberadaan kunci diperiksa {@see self::run()}.
     *
     * @param array<string,mixed> $job    baris job (`id`, `name`, `playbook`, `server_id`)
     * @param string              $jobDir direktori kerja job (absolut, dipasang rw di `/job`)
     * @return array<int,string> argv tanpa shell (dipakai `bypass_shell`)
     * @throws InvalidArgumentException id/playbook/path tidak sah
     */
    public function command(array $job, string $jobDir): array
    {
        $jobId = self::assertId((string) ($job['id'] ?? ''), 'id job');
        $playbookFile = self::playbookFile((string) ($job['playbook'] ?? ''));
        $hostName = self::assertHostName((string) ($job['name'] ?? ''));

        $jobDir = self::assertAbsolute($jobDir, 'job_dir');
        $keyPath = self::assertAbsolute($this->keyPath(), 'ssh_key');

        return [
            'docker',
            'run',
            '--rm',
            '--label',
            'rames.role=ansible-runner',
            '--label',
            self::LABEL_JOB . '=' . $jobId,
            // Jaringan host: target SSH bisa berada di LAN/VPN operator, dan
            // DNS/loopback harus identik dengan host (pola NginxReloader).
            '--network',
            'host',
            '-v',
            $this->playbooksRoot . ':/ansible:ro',
            '-v',
            $jobDir . ':/job:rw',
            '-v',
            $keyPath . ':' . self::CONTAINER_KEY_PATH . ':ro',
            '-v',
            $this->knownHosts . ':' . self::CONTAINER_KNOWN_HOSTS . ':ro',
            '-e',
            'ANSIBLE_CONFIG=/ansible/ansible.cfg',
            '-e',
            'ANSIBLE_HOST_KEY_CHECKING=True',
            '-e',
            'ANSIBLE_PRIVATE_KEY_FILE=' . self::CONTAINER_KEY_PATH,
            // Nilai ber-spasi aman: argv langsung (tanpa shell) → satu argumen.
            '-e',
            'ANSIBLE_SSH_ARGS=-o IdentitiesOnly=yes -o UserKnownHostsFile='
                . self::CONTAINER_KNOWN_HOSTS . ' -o StrictHostKeyChecking=yes',
            '-e',
            'ANSIBLE_LOCAL_TEMP=/tmp/ansible-local',
            '-e',
            'ANSIBLE_REMOTE_TEMP=/tmp/ansible-remote',
            $this->image(),
            'ansible-playbook',
            '-i',
            '/job/inventory.yml',
            // Path ABSOLUT di dalam helper: image `alpine/ansible` (diperiksa pada
            // tag 2.21.0) punya `WorkingDir = /`, BUKAN `/ansible` — argumen
            // relatif `playbooks/…` akan dicari di `/playbooks/…` dan gagal
            // `file not found`. Mount playbook selalu di `/ansible`.
            self::CONTAINER_PLAYBOOKS . '/playbooks/' . $playbookFile,
            '-e',
            '@/job/extra_vars.yml',
            '--limit',
            $hostName,
        ];
    }

    /**
     * Jalankan playbook satu job dengan log streaming.
     *
     * @param array<string,mixed>    $job     baris job (lihat {@see self::command()})
     * @param string                 $jobDir  direktori kerja job
     * @param callable(string):void  $onChunk penerima potongan output mentah
     * @return array{code:int, output:string} `output` = stdout+stderr (dipotong {@see self::MAX_OUTPUT})
     * @throws InvalidArgumentException argv tidak sah
     * @throws RuntimeException kunci SSH/known_hosts tidak siap
     */
    public function run(array $job, string $jobDir, callable $onChunk): array
    {
        $command = $this->command($job, $jobDir);
        $this->ensureKeyMaterial($job);

        $result = $this->runner->runStreaming($command, null, $this->timeout(), [], null, $onChunk);
        $output = (string) ($result['stdout'] ?? '') . (string) ($result['stderr'] ?? '');
        if (strlen($output) > self::MAX_OUTPUT) {
            $output = substr($output, -self::MAX_OUTPUT);
        }

        return ['code' => (int) ($result['code'] ?? -1), 'output' => $output];
    }

    /**
     * Hentikan helper container milik satu job (`docker rm -f` atas id hasil
     * `docker ps --filter label=rames.provision.job=<id>`).
     *
     * @return bool true bila ada container yang dihentikan
     */
    public function cancel(string $jobId): bool
    {
        $jobId = self::assertId($jobId, 'id job');

        $listed = $this->runner->run(
            ['docker', 'ps', '-q', '--filter', self::LABEL_JOB . '=' . $jobId],
            null,
            30
        );
        if ((int) ($listed['code'] ?? -1) !== 0) {
            return false;
        }

        $ids = [];
        foreach (preg_split('/\R/', (string) ($listed['stdout'] ?? '')) ?: [] as $line) {
            $id = trim($line);
            // Id container Docker = heksadesimal; apa pun selain itu bukan id.
            if ($id !== '' && preg_match('/^[a-f0-9]{1,64}$/i', $id) === 1) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return false;
        }

        $removed = $this->runner->run(array_merge(['docker', 'rm', '-f'], $ids), null, 60);

        return (int) ($removed['code'] ?? -1) === 0;
    }

    /**
     * Hapus **isi** direktori kerja job (berkas inventori, extra vars, dan
     * berkas onboarding yang berisi token/PEM).
     *
     * Hanya menerima direktori di bawah {@see self::workRoot()} — path di luar
     * akar kerja (atau akar kerja itu sendiri) ditolak. Direktori job yang sudah
     * hilang = no-op.
     *
     * @throws InvalidArgumentException path di luar work root
     */
    public function cleanup(string $jobDir): void
    {
        $jobDir = self::assertAbsolute($jobDir, 'job_dir');

        $root = realpath($this->workRoot);
        $dir = realpath($jobDir);
        if ($root === false || $dir === false) {
            return; // akar kerja belum ada / job dir sudah bersih
        }

        $root = rtrim($root, '/');
        if ($dir === $root || !str_starts_with($dir . '/', $root . '/')) {
            throw new InvalidArgumentException('Direktori kerja job berada di luar provision_work_dir; pembersihan ditolak.');
        }

        self::removeContents($dir);
    }

    /**
     * Pastikan kunci privat & known_hosts siap dipasang sebelum container jalan.
     *
     * Tanpa ini Docker diam-diam membuat **direktori** kosong pada mount source
     * yang tidak ada, dan kegagalannya muncul jauh kemudian sebagai error SSH
     * yang membingungkan. known_hosts boleh belum ada ⇒ dibuat kosong 0600
     * (verifikasi host key tetap aktif lewat `StrictHostKeyChecking=yes`).
     */
    private function ensureKeyMaterial(array $job): void
    {
        $keyPath = $this->keyPath();
        if (!is_file($keyPath) || !is_readable($keyPath)) {
            throw new RuntimeException(
                'Kunci provisioning bersama belum ada (' . $keyPath . '). Buka /servers/provision lalu klik '
                . '“Buat kunci”, pasang public key-nya di server tujuan, baru jalankan provisioning lagi.'
            );
        }

        if (is_file($this->knownHosts)) {
            return;
        }
        $dir = dirname($this->knownHosts);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_file($this->knownHosts)) {
            @touch($this->knownHosts);
            @chmod($this->knownHosts, 0600);
        }
    }

    /**
     * Path private key provisioning bersama (satu pasangan untuk semua server).
     *
     * Tidak lagi per-server: form tidak meminta kunci privat sama sekali, dan
     * public key pasangan ini dipasang operator di setiap VM baru.
     */
    private function keyPath(): string
    {
        return (new ProvisionAgentKey(null, $this->keysDir))->privatePath();
    }

    /**
     * Berkas playbook dari daftar putih {@see ProvisionInput::PLAYBOOKS}.
     *
     * Menerima kunci (`provision-agent`) maupun nama berkasnya
     * (`provision-agent.yml`); nilai di luar daftar putih selalu ditolak.
     */
    private static function playbookFile(string $playbook): string
    {
        $playbook = trim($playbook);
        if ($playbook !== '' && isset(ProvisionInput::PLAYBOOKS[$playbook])) {
            return ProvisionInput::PLAYBOOKS[$playbook];
        }
        if ($playbook !== '' && in_array($playbook, ProvisionInput::PLAYBOOKS, true)) {
            return $playbook;
        }

        throw new InvalidArgumentException(
            'Playbook provisioning tidak dikenal; hanya playbook bawaan yang diizinkan ('
            . implode(', ', array_keys(ProvisionInput::PLAYBOOKS)) . ').'
        );
    }

    private static function assertId(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match(self::ID_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "{$label} tidak valid (hanya A-Z a-z 0-9 _ -, 1-64 karakter)."
            );
        }

        return $value;
    }

    private static function assertHostName(string $value): string
    {
        $value = trim($value);
        if (preg_match(self::HOST_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'Nama host inventori tidak valid (mulai huruf/angka; hanya A-Z a-z 0-9 _ . -, maksimal 64 karakter).'
            );
        }

        return $value;
    }

    /**
     * Path yang dipasang ke docker **wajib** absolut, tanpa newline/NUL, dan
     * tanpa `:` (pemisah mount docker) — kesalahan di sini tidak boleh berubah
     * jadi mount yang salah tanpa pesan.
     */
    private static function assertAbsolute(string $path, string $label): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_contains($path, ':') || preg_match('/[\r\n\0]/', $path) === 1) {
            throw new InvalidArgumentException("{$label} wajib path absolut (tanpa ':' dan karakter kontrol).");
        }

        return rtrim($path, '/') !== '' ? rtrim($path, '/') : '/';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /** Hapus isi direktori (bukan direktorinya sendiri), tanpa mengikuti symlink. */
    private static function removeContents(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            try {
                if (is_link($path) || is_file($path)) {
                    @unlink($path);
                    continue;
                }
                if (is_dir($path)) {
                    self::removeTree($path);
                }
            } catch (Throwable) {
                // Pembersihan bersifat best-effort: kegagalan satu entri tidak
                // boleh menutupi hasil job yang sudah tercatat.
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        self::removeContents($dir);
        @rmdir($dir);
    }
}
