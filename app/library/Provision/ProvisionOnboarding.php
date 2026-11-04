<?php
declare(strict_types=1);

namespace app\library\Provision;

use app\library\Agent\AgentClient;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pendaftaran server agent hasil provisioning Ansible ke control plane.
 *
 * Ansible menyalin berkas onboarding dari server agent
 * (`agent/install.sh --onboarding-file <path>` — format `KUNCI=nilai` + blok
 * `-----BEGIN/END ONBOARDING PEM-----`, 0600, RAHASIA) ke direktori kerja job.
 * Kelas ini membaca berkas itu, memvalidasinya lewat {@see ServerResolver}
 * (aturan yang persis sama dengan form "Add Server"), menyimpannya, lalu
 * **memverifikasi koneksi** `GET /version` sebelum job dianggap sukses.
 *
 * Invarian:
 *  - pesan galat **hanya** menyebut nama kunci/field, tidak pernah nilai
 *    (token/PEM tidak boleh masuk log maupun UI);
 *  - registrasi yang gagal verifikasi **menghapus lagi** baris server yang baru
 *    dibuat, sehingga registry tidak menyimpan server yang tidak bisa dihubungi;
 *  - nama server yang sudah ada ditolak **sebelum** apa pun ditulis.
 *
 * Logika parse murni = statik; registrasi menyentuh store (instance store
 * disuntikkan dari pemanggil) — tidak ada state lintas-request.
 */
final class ProvisionOnboarding
{
    /** Penanda blok PEM di berkas onboarding (kontrak dengan agent/install.sh). */
    public const PEM_BEGIN = '-----BEGIN ONBOARDING PEM-----';
    public const PEM_END = '-----END ONBOARDING PEM-----';

    /** Versi format berkas onboarding yang dipahami batch ini. */
    public const FORMAT_VERSION = '1';

    /**
     * Kunci wajib, berurutan sesuai berkas. `PEM` tidak ada di berkas sebagai
     * `KUNCI=nilai` — ia blok di akhir berkas, tetapi tetap diperlakukan sama
     * (pesan galat menyebut `PEM`).
     *
     * @var array<int,string>
     */
    public const REQUIRED_KEYS = [
        'RAMES_ONBOARDING_VERSION',
        'URL',
        'TOKEN',
        'DOMAIN',
        'PORT_RANGE_START',
        'PORT_RANGE_END',
        'PEM',
    ];

    /** Batas panjang pesan galat yang diteruskan ke pemanggil/job. */
    private const MAX_MESSAGE = 500;

    /**
     * Parse isi berkas onboarding.
     *
     * Formatnya **bukan** JSON dan bukan INI penuh: baris `KUNCI=nilai`
     * dipisah pada `=` **pertama** (nilai boleh memuat `=`), komentar `#`/`;`
     * dilewati, lalu blok PEM diambil apa adanya mulai sentinel BEGIN sampai
     * END (PEM memuat baris baru sehingga tidak bisa ditulis sebagai `KUNCI=`).
     *
     * @return array{version:string, url:string, token:string, domain:string,
     *               port_range_start:int, port_range_end:int, pem:string,
     *               fingerprint_sha256:string, agent_version:string, generated_at:string}
     * @throws InvalidArgumentException pesan hanya menyebut nama kunci
     */
    public static function parse(string $content): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $content);

        $values = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line === self::PEM_BEGIN) {
                break; // sisa berkas = blok PEM
            }
            if ($line[0] === '#' || $line[0] === ';') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if ($key === '') {
                continue;
            }
            $values[$key] = trim(substr($line, $eq + 1)); // baris terakhir menang
        }

        $missing = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if ($key === 'PEM') {
                continue; // diambil di bawah
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        [$pem, $pemMissing, $pemError] = self::extractPem($text);
        if ($pemMissing) {
            $missing[] = 'PEM';
        }
        if ($pemError !== null) {
            throw new InvalidArgumentException($pemError);
        }
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Berkas onboarding server agent tidak lengkap: kunci ' . implode(', ', $missing)
                . ' tidak ditemukan/kosong.'
            );
        }

        if (trim((string) $values['RAMES_ONBOARDING_VERSION']) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException(
                'RAMES_ONBOARDING_VERSION tidak didukung: hanya versi ' . self::FORMAT_VERSION . ' yang bisa dibaca.'
            );
        }

        $start = filter_var(trim((string) $values['PORT_RANGE_START']), FILTER_VALIDATE_INT);
        if ($start === false) {
            throw new InvalidArgumentException('Kunci PORT_RANGE_START bukan bilangan bulat.');
        }
        $end = filter_var(trim((string) $values['PORT_RANGE_END']), FILTER_VALIDATE_INT);
        if ($end === false) {
            throw new InvalidArgumentException('Kunci PORT_RANGE_END bukan bilangan bulat.');
        }

        return [
            'version' => self::FORMAT_VERSION,
            'url' => trim((string) $values['URL']),
            'token' => trim((string) $values['TOKEN']),
            'domain' => trim((string) $values['DOMAIN']),
            'port_range_start' => (int) $start,
            'port_range_end' => (int) $end,
            'pem' => $pem,
            // Kunci pelengkap (tidak wajib): fingerprint/versi/waktu hanya untuk
            // ditampilkan & verifikasi manual, tidak dipakai untuk identitas.
            'fingerprint_sha256' => trim((string) ($values['FINGERPRINT_SHA256'] ?? '')),
            'agent_version' => trim((string) ($values['AGENT_VERSION'] ?? '')),
            'generated_at' => trim((string) ($values['GENERATED_AT'] ?? '')),
        ];
    }

    /**
     * Daftarkan server agent hasil provisioning.
     *
     * @param array<string,mixed> $job            baris job provisioning (`name` = slug server)
     * @param string              $onboardingFile berkas onboarding hasil salinan Ansible
     * @param ServerStore|null    $stores         store temp pada pengujian
     * @param ServerResolver|null $resolver       resolver temp pada pengujian
     * @param callable|null       $clientFactory  factory `fn(array $server): object{version(): array}`
     * @return array<string,mixed> dokumen server tersimpan + `agent_version`
     * @throws InvalidArgumentException berkas onboarding tidak valid
     * @throws RuntimeException nama sudah dipakai / verifikasi koneksi gagal
     */
    public static function register(
        array $job,
        string $onboardingFile,
        ?ServerStore $stores = null,
        ?ServerResolver $resolver = null,
        ?callable $clientFactory = null
    ): array {
        $file = trim($onboardingFile);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Berkas onboarding server agent tidak ditemukan atau tidak terbaca.');
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('Berkas onboarding server agent tidak bisa dibaca.');
        }

        $data = self::parse($content);

        $stores ??= new ServerStore();
        $resolver ??= new ServerResolver($stores);

        // Validasi lewat jalur yang SAMA dengan form Add Server: nama, base URL
        // https, token, PEM X.509, domain, rentang port (tanpa tumpang tindih
        // dengan server lokal). Nilai rahasia tidak pernah muncul di pesan galat.
        $validated = $resolver->validate([
            'name' => (string) ($job['name'] ?? ''),
            'base_url' => $data['url'],
            'token' => $data['token'],
            'tls_cert_pem' => $data['pem'],
            'domain' => $data['domain'],
            'port_range' => ['start' => $data['port_range_start'], 'end' => $data['port_range_end']],
        ]);

        $serverName = (string) ($validated['name'] ?? '');
        if ($stores->nameExists($serverName)) {
            throw new RuntimeException(
                "Server dengan nama \"{$serverName}\" sudah terdaftar: perbarui server yang ada, atau pakai nama lain untuk job provisioning."
            );
        }

        $created = $stores->create($validated);
        $serverId = (string) ($created['id'] ?? '');

        try {
            $client = $clientFactory !== null ? $clientFactory($created) : new AgentClient($created);
            $version = $client->version();
        } catch (Throwable $e) {
            // Server yang tidak bisa dihubungi tidak boleh tertinggal di registry.
            try {
                if ($serverId !== '') {
                    $stores->delete($serverId);
                }
            } catch (Throwable) {
                // Pembersihan best-effort: galat asli (verifikasi) tetap dilaporkan.
            }

            throw new RuntimeException(
                'Verifikasi koneksi ke agent gagal: '
                . self::redact($e->getMessage(), [$data['token'], $data['pem']])
            );
        }

        $agentVersion = '';
        if (is_array($version)) {
            $agentVersion = trim((string) ($version['agent_version'] ?? ($version['version'] ?? '')));
        }

        $document = $created;
        $document['agent_version'] = $agentVersion;

        return $document;
    }

    /**
     * Ambil blok PEM dari isi berkas onboarding.
     *
     * @return array{0:string,1:bool,2:?string} [pem, tidakAda, pesanGalat]
     */
    private static function extractPem(string $text): array
    {
        $begin = strpos($text, self::PEM_BEGIN);
        if ($begin === false) {
            return ['', true, null];
        }

        $rest = substr($text, $begin + strlen(self::PEM_BEGIN));
        $end = strpos($rest, self::PEM_END);
        if ($end === false) {
            return ['', false, 'Blok PEM berkas onboarding tidak ditutup sentinel ' . self::PEM_END . '.'];
        }

        $pem = trim(substr($rest, 0, $end));
        if ($pem === '') {
            return ['', true, null];
        }

        return [$pem, false, null];
    }

    /**
     * Buang kemungkinan nilai rahasia dari pesan galat pihak ketiga lalu
     * batasi panjangnya (pesan ini diteruskan ke `provision_jobs.error`).
     *
     * @param array<int,string> $secrets
     */
    private static function redact(string $message, array $secrets): string
    {
        $message = trim(str_replace(["\r\n", "\r", "\n"], ' ', $message));
        foreach ($secrets as $secret) {
            $secret = trim($secret);
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }
        if ($message === '') {
            $message = 'agent tidak merespons / menolak koneksi.';
        }
        if (strlen($message) > self::MAX_MESSAGE) {
            $message = substr($message, 0, self::MAX_MESSAGE) . '...';
        }

        return $message;
    }
}
