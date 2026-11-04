<?php
declare(strict_types=1);

namespace app\library\Provision;

use app\library\Support\ProcessRunner;
use RuntimeException;
use Throwable;

/**
 * Pin host key SSH server target (TOFU — trust on first use) sebelum Ansible
 * menghubunginya dengan `StrictHostKeyChecking=yes`.
 *
 * Latar belakang: {@see AnsibleRunner} memasang `known_hosts` read-only ke
 * helper container dan **selalu** memakai `StrictHostKeyChecking=yes`. Tanpa
 * entri pin, playbook gagal di koneksi pertama dengan pesan OpenSSH yang
 * membingungkan. Karena itu worker provisioning memanggil kelas ini dulu:
 *
 *  1. `ssh-keyscan -T 10 -p <port> -t ed25519,ecdsa,rsa <host>` (argv array,
 *     `bypass_shell`, timeout 30 dtk — tidak pernah string shell);
 *  2. baris pertama yang bukan komentar diambil sebagai `<keytype> <base64>`;
 *  3. bila `known_hosts` **sudah** punya entri untuk `[host]:port` dengan isi
 *     berbeda ⇒ `host_key_changed=true` dan berkas **tidak** ditulis (pemanggil
 *     wajib menggagalkan job: kemungkinan MITM atau server dipasang ulang);
 *  4. bila belum ada ⇒ {@see ServerSshKeyStore::saveKnownHost()} menulis pin.
 *
 * Fingerprint `SHA256:...` dihitung `ssh-keygen -lf -` (stdin = baris kunci)
 * supaya operator bisa membandingkannya **manual** dengan keluaran di server
 * tujuan. Kegagalan menghitung fingerprint bukan alasan menggagalkan job ⇒
 * string kosong.
 *
 * Kelas ini **tidak pernah** menyentuh kunci privat: hanya host key publik.
 * Status (statik per panggilan) disimpan di berkas known_hosts; instance hanya
 * memegang seam (`ProcessRunner`/`ServerSshKeyStore`) — aman di worker Webman
 * persistent, tanpa cache lintas-request.
 */
final class HostKeyPinner
{
    /** Slot kunci = nama server (slug `[a-z0-9-]`), pola disamakan dengan store. */
    private const KEY_SLOT_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

    /** FQDN: label huruf/angka/strip, titik sebagai pemisah. */
    private const HOST_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/';

    /** Tipe kunci OpenSSH (`ssh-ed25519`, `ecdsa-sha2-nistp256`, `ssh-rsa`, …). */
    private const KEY_TYPE_PATTERN = '/^[a-z][a-z0-9@._-]{0,63}$/';

    /** Badan kunci: base64 standar satu baris. */
    private const BASE64_PATTERN = '/^[A-Za-z0-9+\/]+={0,2}$/';

    /** Timeout `ssh-keyscan` (detik) — `-T 10` adalah timeout koneksi per host. */
    private const SCAN_TIMEOUT = 30;

    /** Timeout `ssh-keygen -lf -` (detik): lokal, tanpa jaringan. */
    private const FINGERPRINT_TIMEOUT = 15;

    private const MAX_KEY_BODY_BYTES = 8192;

    private readonly ProcessRunner $runner;
    private readonly ServerSshKeyStore $store;

    public function __construct(?ProcessRunner $runner = null, ?ServerSshKeyStore $store = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->store = $store ?? new ServerSshKeyStore();
    }

    /**
     * Baca host key server lalu pin bila belum ada.
     *
     * @param string $keySlot slot kunci (nama server, dipakai juga oleh AnsibleRunner)
     * @param string $host    IPv4 atau FQDN target SSH
     * @param int    $port    port SSH 1–65535
     * @return array{fingerprint:string,host_key_changed:bool,pinned:bool}
     *         `host_key_changed=true` ⇒ kunci di server BERBEDA dari pin lama;
     *         berkas known_hosts tidak diubah dan job harus digagalkan pemanggil.
     * @throws RuntimeException host/port/slot tidak sah, atau host key tidak terbaca
     */
    public function pin(string $keySlot, string $host, int $port): array
    {
        $keySlot = self::assertKeySlot($keySlot);
        $host = self::assertHost($host);
        $port = self::assertPort($port);

        $result = $this->runner->run(
            ['ssh-keyscan', '-T', '10', '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $host],
            null,
            self::SCAN_TIMEOUT
        );

        $key = self::firstKeyLine((string) ($result['stdout'] ?? ''));
        if ($key === null) {
            throw new RuntimeException(
                'Tidak bisa membaca host key ' . $host . ':' . $port
                . ' (port/SSH/firewall?) — pastikan sshd berjalan, port terbuka dari control plane,'
                . ' lalu ulangi provisioning.'
            );
        }

        [$keyType, $keyBody] = $key;
        $fingerprint = $this->fingerprint($keyType . ' ' . $keyBody);

        $existing = $this->existingKeys($host, $port);
        if ($existing !== []) {
            foreach ($existing as $entry) {
                if ($entry[0] === $keyType && hash_equals($entry[1], $keyBody)) {
                    // Idempoten: pin lama sama dengan kunci yang dibaca sekarang.
                    // Tetap tulis ulang supaya bentuk kolom host dinormalkan:
                    // versi lama menulis `[host]:22`, padahal OpenSSH mencari
                    // `host` untuk port default (kalau tidak, koneksi ditolak
                    // "No ED25519 host key is known").
                    $this->store->saveKnownHost($keySlot, $host, $port, $keyType . ' ' . $keyBody);

                    return ['fingerprint' => $fingerprint, 'host_key_changed' => false, 'pinned' => false];
                }
            }

            // Ada entri untuk host:port ini tetapi isinya berbeda ⇒ JANGAN menulis.
            return ['fingerprint' => $fingerprint, 'host_key_changed' => true, 'pinned' => false];
        }

        $this->store->saveKnownHost($keySlot, $host, $port, $keyType . ' ' . $keyBody);

        return ['fingerprint' => $fingerprint, 'host_key_changed' => false, 'pinned' => true];
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Baris kunci pertama yang sah dari keluaran `ssh-keyscan`.
     *
     * Baris komentar (`# ...`), baris kosong, dan baris cacat dilewati; format
     * yang diterima `<hostspec> <keytype> <base64>`. Hostspec tidak dipakai —
     * pemanggil menulis entri `[host]:port` sendiri supaya konsisten dengan
     * {@see ServerSshKeyStore::saveKnownHost()}.
     *
     * @return array{0:string,1:string}|null [keytype, base64] atau null bila tidak ada
     */
    private static function firstKeyLine(string $output): ?array
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 3) {
                continue;
            }

            $keyType = $parts[1];
            $keyBody = $parts[2];
            if (preg_match(self::KEY_TYPE_PATTERN, $keyType) !== 1
                || preg_match(self::BASE64_PATTERN, $keyBody) !== 1
                || strlen($keyBody) > self::MAX_KEY_BODY_BYTES
            ) {
                continue;
            }

            return [$keyType, $keyBody];
        }

        return null;
    }

    /**
     * Entri known_hosts yang sudah ada untuk host:port ini.
     *
     * Bentuk yang dicocokkan: `[host]:port`, dan untuk port 22 juga `host` /
     * `[host]:22` (nilai default OpenSSH). Baris ber-hash (`|1|...`) dan
     * penanda (`@cert-authority`) dilewati karena tidak bisa dicocokkan tanpa
     * salt — pin lama yang tidak terbaca diperlakukan sebagai "belum ada".
     *
     * @return array<int,array{0:string,1:string}> daftar [keytype, base64]
     */
    private function existingKeys(string $host, int $port): array
    {
        $specs = ['[' . $host . ']:' . $port];
        if ($port === 22) {
            $specs[] = $host;
            $specs[] = '[' . $host . ']:22';
        }

        $path = $this->store->knownHostsPath();
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '|' || $line[0] === '@') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 3) {
                continue;
            }

            $hostSpecs = explode(',', $parts[0]);
            $matched = false;
            foreach ($hostSpecs as $spec) {
                if (in_array(strtolower($spec), $specs, true)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            $entries[] = [$parts[1], $parts[2]];
        }

        return $entries;
    }

    /**
     * `SHA256:...` dari `ssh-keygen -lf -` (stdin = `<keytype> <base64>`).
     *
     * Kegagalan apa pun ⇒ string kosong: fingerprint hanya informasi bantu,
     * bukan syarat pin.
     */
    private function fingerprint(string $keyLine): string
    {
        try {
            $result = $this->runner->run(
                ['ssh-keygen', '-lf', '-'],
                null,
                self::FINGERPRINT_TIMEOUT,
                [],
                $keyLine . "\n"
            );
        } catch (Throwable) {
            return '';
        }

        if ((int) ($result['code'] ?? -1) !== 0) {
            return '';
        }

        if (preg_match('/\b(SHA256:[A-Za-z0-9+\/=]{8,})/', (string) ($result['stdout'] ?? ''), $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    /**
     * @throws RuntimeException slot tidak sah untuk nama berkas kunci
     */
    private static function assertKeySlot(string $keySlot): string
    {
        $keySlot = trim($keySlot);
        if (preg_match(self::KEY_SLOT_PATTERN, $keySlot) !== 1) {
            throw new RuntimeException(
                'Slot kunci SSH tidak valid (hanya A-Z a-z 0-9 _ . -, maksimal 64 karakter).'
            );
        }

        return $keySlot;
    }

    /**
     * IPv4 atau FQDN huruf kecil. Karakter shell/opsi argv tidak mungkin lolos
     * karena pola hanya mengizinkan huruf, angka, titik, dan strip.
     *
     * @throws RuntimeException host tidak sah
     */
    private static function assertHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            throw new RuntimeException('Host SSH wajib diisi (IPv4 atau FQDN).');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (strlen($host) > 253 || preg_match(self::HOST_PATTERN, $host) !== 1) {
            throw new RuntimeException('Host SSH harus IPv4 atau FQDN valid (huruf, angka, titik, strip).');
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
            ) {
                throw new RuntimeException('Host SSH harus IPv4 atau FQDN valid (label 1–63 karakter).');
            }
        }

        return $host;
    }

    /**
     * @throws RuntimeException port di luar 1–65535
     */
    private static function assertPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Port SSH harus 1–65535.');
        }

        return $port;
    }
}
