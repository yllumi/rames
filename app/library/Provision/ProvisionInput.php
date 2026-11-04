<?php
declare(strict_types=1);

namespace app\library\Provision;

use RuntimeException;

/**
 * Normalisasi + validasi payload provisioning server agent (Opsi B: Ansible).
 *
 * Dua lapis, keduanya **statik murni tanpa I/O** (mudah diuji & tidak menyentuh
 * basis data/berkas):
 *  1. {@see self::validate()} — membersihkan payload form/CLI menjadi array
 *     ternormalisasi yang hanya berisi field yang dikenal;
 *  2. {@see self::extraVars()} — memetakan hasil itu ke extra vars Ansible
 *     dengan **allowlist** ketat (prefix `rames_agent_`), satu sumber nama
 *     variabel untuk role `rames_agent`.
 *
 * Yang sengaja TIDAK pernah ada di sini:
 *  - token agent / private key SSH / passphrase — rahasia hidup di
 *    {@see ServerSshKeyStore} (berkas 0600), tidak pernah di extra vars, log,
 *    atau pesan galat;
 *  - `--force`/`force` — provisioning tidak boleh meregenerasi token/TLS atau
 *    memaksa ulang langkah yang sudah selesai (idempoten).
 *
 * Aturan nama & rentang port sengaja disamakan semantiknya dengan
 * {@see \app\library\Deploy\ServerResolver} (slug `[a-z0-9-]`, port 1–65535,
 * `start < end`) supaya nama server provisioning dan nama server registry
 * tidak pernah berbeda arti.
 */
final class ProvisionInput
{
    /** Playbook yang boleh dipilih (kunci → berkas di `ops/ansible/playbooks/`). */
    public const PLAYBOOKS = [
        'provision-agent' => 'provision-agent.yml',
        'verify-agent' => 'verify-agent.yml',
    ];

    /** Playbook default: pasang agent (idempoten, aman diulang). */
    public const DEFAULT_PLAYBOOK = 'provision-agent';

    /** Port API agent (HTTP/TLS). `bind` wajib memakai port ini. */
    public const AGENT_PORT = 8443;

    /** Batas panjang nama server (sama seperti slug prefix container). */
    public const MAX_NAME_LENGTH = 64;

    /** Batas panjang hostname FQDN. */
    private const MAX_HOST_LENGTH = 253;

    /** Batas panjang email (RFC 5321). */
    private const MAX_EMAIL_LENGTH = 254;

    /** Slug huruf kecil/angka, `-` hanya di tengah (semantik ServerResolver). */
    private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** User SSH: huruf kecil/underscore di awal, maks 32 karakter. */
    private const SSH_USER_PATTERN = '/^[a-z_][a-z0-9_-]{0,31}$/';

    /** Satu label hostname: huruf/angka, `-` di tengah, maks 63 karakter. */
    private const HOST_LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** Karakter yang tidak boleh muncul di nama host/domain (selain spasi & newline). */
    private const FORBIDDEN_HOST_CHARS = [';', '&', '|', '$', '`', '"', "'", '\\', '<', '>', '(', ')', '{', '}', '[', ']', '*', '?', '!', '#', '%', '^', '=', '+', ',', ':', '/', '@'];

    /** Nilai `ssl_challenge` yang dikenal role Ansible. */
    private const SSL_CHALLENGES = ['', 'http', 'dns-cloudflare'];

    /** Nilai `ssl_ca_server` yang dikenal role Ansible. */
    private const SSL_CA_SERVERS = ['', 'production', 'staging'];

    /**
     * Validasi + normalisasi payload provisioning.
     *
     * Field asing diabaikan (bukan ditolak) — tetapi `force`/`--force` yang
     * truthy DITOLAK karena fitur itu tidak boleh ada.
     *
     * @param array<string,mixed> $payload
     * @return array{
     *   name:string,host:string,ssh_user:string,ssh_port:int,
     *   domain:string,email:string,
     *   port_range_start:int,port_range_end:int,agent_port:int,
     *   bind:string,ip:string,
     *   ssl_challenge:string,ssl_ca_server:string,
     *   control_plane_ips:array<int,string>,manage_firewall:bool,playbook:string
     * }
     * @throws RuntimeException pesan bahasa Indonesia yang menyebut nama field
     */
    public static function validate(array $payload): array
    {
        self::rejectForce($payload);

        $name = strtolower(trim(self::text($payload['name'] ?? '')));
        if ($name === '') {
            throw new RuntimeException('Field "name" wajib diisi.');
        }
        if (strlen($name) > self::MAX_NAME_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException(
                'Field "name" hanya boleh huruf kecil a-z, angka, dan strip (-), maksimal '
                . self::MAX_NAME_LENGTH . ' karakter, serta diawali & diakhiri huruf/angka.'
            );
        }

        $host = self::normalizeHost(self::text($payload['host'] ?? ''), 'host', true, false);

        $sshUser = strtolower(trim(self::text($payload['ssh_user'] ?? '')));
        if ($sshUser === '') {
            throw new RuntimeException('Field "ssh_user" wajib diisi (mis. root atau deploy).');
        }
        if (preg_match(self::SSH_USER_PATTERN, $sshUser) !== 1) {
            throw new RuntimeException(
                'Field "ssh_user" tidak valid: huruf kecil/angka/underscore/strip, diawali huruf atau underscore, maksimal 32 karakter.'
            );
        }

        $sshPort = self::port($payload['ssh_port'] ?? null, 'ssh_port', 22);

        $domain = self::normalizeHost(self::text($payload['domain'] ?? ''), 'domain', true, true);

        $email = trim(self::text($payload['email'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Field "email" wajib diisi (dipakai Let\'s Encrypt/agent).');
        }
        if (strlen($email) > self::MAX_EMAIL_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException('Field "email" tidak valid: gunakan alamat email polos, mis. ops@example.com.');
        }

        $rangeStart = self::port($payload['port_range_start'] ?? null, 'port_range_start', 30000);
        $rangeEnd = self::port($payload['port_range_end'] ?? null, 'port_range_end', 30999);
        if ($rangeStart >= $rangeEnd) {
            throw new RuntimeException(
                'Field "port_range_start"/"port_range_end" harus 1–65535 dengan start < end (mis. 30000–30999).'
            );
        }

        $agentPort = self::port($payload['agent_port'] ?? null, 'agent_port', self::AGENT_PORT);

        $bind = self::normalizeBind($payload['bind'] ?? null);

        $ip = trim(self::text($payload['ip'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Field "ip" harus alamat IPv4 yang valid (atau dikosongkan).');
        }

        $sslChallenge = self::enum(
            $payload['ssl_challenge'] ?? null,
            'ssl_challenge',
            self::SSL_CHALLENGES,
            'Field "ssl_challenge" hanya boleh "", "http", atau "dns-cloudflare".'
        );
        $sslCaServer = self::enum(
            $payload['ssl_ca_server'] ?? null,
            'ssl_ca_server',
            self::SSL_CA_SERVERS,
            'Field "ssl_ca_server" hanya boleh "", "production", atau "staging".'
        );

        $playbook = strtolower(trim(self::text($payload['playbook'] ?? '')));
        if ($playbook === '') {
            $playbook = self::DEFAULT_PLAYBOOK;
        }
        if (!array_key_exists($playbook, self::PLAYBOOKS)) {
            throw new RuntimeException(
                'Field "playbook" tidak dikenal; nilai yang sah: ' . implode(', ', array_keys(self::PLAYBOOKS)) . '.'
            );
        }

        return [
            'name' => $name,
            'host' => $host,
            'ssh_user' => $sshUser,
            'ssh_port' => $sshPort,
            'domain' => $domain,
            'email' => $email,
            'port_range_start' => $rangeStart,
            'port_range_end' => $rangeEnd,
            'agent_port' => $agentPort,
            'bind' => $bind,
            'ip' => $ip,
            'ssl_challenge' => $sslChallenge,
            'ssl_ca_server' => $sslCaServer,
            'control_plane_ips' => self::normalizeControlPlaneIps($payload['control_plane_ips'] ?? null),
            'manage_firewall' => self::boolValue($payload['manage_firewall'] ?? null, 'manage_firewall', true),
            'playbook' => $playbook,
        ];
    }

    /**
     * Extra vars Ansible dari input ternormalisasi ({@see self::validate()}).
     *
     * Hanya kunci ber-prefix `rames_agent_` yang pernah dikembalikan. String
     * kosong tidak dikirim (biar default role yang berlaku); boolean/int/array
     * selalu dikirim. `rames_agent_run_installer` = true hanya untuk playbook
     * `provision-agent` (verify tidak boleh memasang ulang).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function extraVars(array $input): array
    {
        $playbook = (string) ($input['playbook'] ?? self::DEFAULT_PLAYBOOK);

        $vars = [
            'rames_agent_port_range_start' => (int) ($input['port_range_start'] ?? 30000),
            'rames_agent_port_range_end' => (int) ($input['port_range_end'] ?? 30999),
            'rames_agent_port' => (int) ($input['agent_port'] ?? self::AGENT_PORT),
            'rames_agent_manage_firewall' => self::boolValue($input['manage_firewall'] ?? null, 'manage_firewall', true),
            'rames_agent_run_installer' => $playbook === self::DEFAULT_PLAYBOOK,
            'rames_agent_assert_identity' => true,
        ];

        foreach ([
            'rames_agent_domain' => 'domain',
            'rames_agent_email' => 'email',
            'rames_agent_bind' => 'bind',
            'rames_agent_ip' => 'ip',
            'rames_agent_ssl_challenge' => 'ssl_challenge',
            'rames_agent_ssl_ca_server' => 'ssl_ca_server',
        ] as $var => $field) {
            $value = trim(self::text($input[$field] ?? ''));
            if ($value !== '') {
                $vars[$var] = $value;
            }
        }

        $ips = self::controlPlaneIpList($input['control_plane_ips'] ?? null);
        if ($ips !== []) {
            $vars['rames_agent_control_plane_ips'] = $ips;
        }

        return $vars;
    }

    // ==================================================================
    // Internal — input primitif
    // ==================================================================

    /**
     * Tolak `force`/`--force` truthy: provisioning tidak boleh memaksa ulang.
     *
     * @param array<string,mixed> $payload
     */
    private static function rejectForce(array $payload): void
    {
        foreach (['force', '--force'] as $key) {
            if (array_key_exists($key, $payload) && self::truthy($payload[$key])) {
                throw new RuntimeException(
                    'Field "force" tidak didukung: provisioning tidak boleh meregenerasi token/TLS atau memaksa ulang langkah yang sudah selesai.'
                );
            }
        }
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Nilai jadi string; hanya skalar yang diterima (array/objek = kosong).
     */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    /**
     * Port tunggal 1–65535; kosong ⇒ default.
     *
     * @throws RuntimeException untuk nilai non-angka/di luar rentang
     */
    private static function port(mixed $raw, string $field, int $default): int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $default;
        }

        $port = null;
        if (is_int($raw)) {
            $port = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,5}$/', trim($raw)) === 1) {
            $port = (int) trim($raw);
        }

        if ($port === null || $port < 1 || $port > 65535) {
            throw new RuntimeException('Field "' . $field . '" harus angka port 1–65535.');
        }

        return $port;
    }

    /**
     * Boolean dari form/CLI; kosong ⇒ default.
     *
     * @throws RuntimeException untuk nilai yang tidak dikenal
     */
    private static function boolValue(mixed $raw, string $field, bool $default): bool
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }
        if (is_string($raw)) {
            $value = strtolower(trim($raw));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        throw new RuntimeException('Field "' . $field . '" harus boolean (true/false).');
    }

    /**
     * Nilai enum (lowercase, trim); kosong ⇒ ''.
     *
     * @param array<int,string> $allowed
     * @throws RuntimeException bila nilai di luar daftar
     */
    private static function enum(mixed $raw, string $field, array $allowed, string $message): string
    {
        $value = strtolower(trim(self::text($raw ?? '')));
        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    // ==================================================================
    // Internal — host & domain
    // ==================================================================

    /**
     * Validasi host: IPv4 valid ATAU hostname FQDN (label huruf/angka/strip).
     *
     * Semua input dinormalkan lowercase. Karakter khusus (`;`, `&`, `|`, `$`,
     * backtick, kutip, spasi, newline, …) ditolak lebih dulu dengan pesan yang
     * menyebut nama field — bukan diteruskan ke shell/Ansible.
     *
     * @param bool $requireDot true untuk `domain` (harus FQDN, minimal satu titik)
     */
    private static function normalizeHost(string $raw, string $field, bool $required, bool $requireDot): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            if ($required) {
                throw new RuntimeException('Field "' . $field . '" wajib diisi.');
            }

            return '';
        }

        if (strlen($value) > self::MAX_HOST_LENGTH) {
            throw new RuntimeException('Field "' . $field . '" terlalu panjang (maksimal 253 karakter).');
        }
        if (preg_match('/^[a-z0-9.-]+$/', $value) !== 1
            || strpbrk($value, implode('', self::FORBIDDEN_HOST_CHARS)) !== false
        ) {
            throw new RuntimeException(
                'Field "' . $field . '" tidak valid: hanya huruf, angka, titik, dan strip (tanpa spasi/karakter khusus).'
            );
        }
        // String yang seluruh labelnya angka dianggap percobaan IPv4 — harus valid.
        if (preg_match('/^\d+(?:\.\d+)+$/', $value) === 1) {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException('Field "' . $field . '" bukan alamat IPv4 yang valid.');
            }

            return $value;
        }

        $labels = explode('.', $value);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match(self::HOST_LABEL_PATTERN, $label) !== 1) {
                throw new RuntimeException(
                    'Field "' . $field . '" bukan hostname yang valid (tiap label huruf/angka/strip, diawali & diakhiri huruf/angka).'
                );
            }
        }
        if ($requireDot && count($labels) < 2) {
            throw new RuntimeException('Field "' . $field . '" harus hostname FQDN (mis. agent-01.example.com).');
        }

        return $value;
    }

    /**
     * `bind` = `<ip|host>:<port>`; port WAJIB port agent (8443).
     *
     * @throws RuntimeException untuk bentuk/port/host yang tidak sah
     */
    private static function normalizeBind(mixed $raw): string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return '0.0.0.0:' . self::AGENT_PORT;
        }
        if (!is_string($raw)) {
            throw new RuntimeException('Field "bind" harus berbentuk <ip|host>:<port> (mis. 0.0.0.0:8443).');
        }

        $value = trim($raw);
        $separator = strrpos($value, ':');
        if ($separator === false) {
            throw new RuntimeException('Field "bind" harus berbentuk <ip|host>:<port> (mis. 0.0.0.0:8443).');
        }

        $host = substr($value, 0, $separator);
        $portPart = substr($value, $separator + 1);
        if ($host === '' || preg_match('/^\d{1,5}$/', $portPart) !== 1) {
            throw new RuntimeException('Field "bind" harus berbentuk <ip|host>:<port> (mis. 0.0.0.0:8443).');
        }
        if ((int) $portPart !== self::AGENT_PORT) {
            throw new RuntimeException(
                'Field "bind" harus memakai port ' . self::AGENT_PORT . ' (port API agent), mis. 0.0.0.0:' . self::AGENT_PORT . '.'
            );
        }

        return self::normalizeHost($host, 'bind', true, false) . ':' . self::AGENT_PORT;
    }

    // ==================================================================
    // Internal — control plane IP
    // ==================================================================

    /**
     * Daftar IP control plane (IPv4) dari array ATAU string dipisah
     * koma/spasi/newline. Duplikat dibuang, urutan dipertahankan.
     *
     * @return array<int,string>
     * @throws RuntimeException bila ada entri yang bukan IPv4
     */
    private static function normalizeControlPlaneIps(mixed $raw): array
    {
        $ips = [];
        foreach (self::controlPlaneCandidates($raw) as $candidate) {
            $ip = trim($candidate);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException(
                    'Field "control_plane_ips" hanya boleh berisi alamat IPv4 (dipisah koma/spasi/baris baru).'
                );
            }
            $ips[$ip] = true;
        }

        return array_keys($ips);
    }

    /**
     * Versi longgar untuk {@see self::extraVars()}: entri yang bukan IPv4
     * dibuang, bukan melempar (payload di jalur ini sudah divalidasi).
     *
     * @return array<int,string>
     */
    private static function controlPlaneIpList(mixed $raw): array
    {
        $ips = [];
        foreach (self::controlPlaneCandidates($raw) as $candidate) {
            $ip = trim($candidate);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ips[$ip] = true;
            }
        }

        return array_keys($ips);
    }

    /**
     * @return array<int,string>
     */
    private static function controlPlaneCandidates(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            return preg_split('/[\s,]+/', trim($raw)) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) || is_int($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
