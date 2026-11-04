<?php
declare(strict_types=1);

namespace app\library\Provision;

use RuntimeException;

/**
 * Penyimpanan kunci SSH server untuk provisioning Ansible (Opsi B).
 *
 * Kunci privat per server disimpan sebagai berkas di
 * `{deploy.ssh_keys_path}/servers/{serverId}` dengan mode 0600; direktori
 * `servers/` dibuat mode 0700. Private key **tidak pernah** masuk basis data,
 * log, atau pesan galat — kelas ini hanya menyebut path/nama kunci.
 *
 * Pin host key (TOFU) disimpan di `<keysDir>/known_hosts` dengan format
 * `[host]:port <baris host key>`. Baris untuk host:port yang sama diganti
 * (idempoten), entri host lain tidak pernah ditimpa. `saveKnownHost()` juga
 * menulis penanda per server (`servers/{serverId}.pin`) sehingga
 * {@see self::hasPin()} bisa menjawab "host key server ini sudah dipin?" tanpa
 * mem-parsing known_hosts — dipakai pemanggil untuk memutuskan
 * `StrictHostKeyChecking=yes`.
 *
 * Path dapat di-override di konstruktor (pola `EnvManager`/`TemplateCatalog`)
 * supaya tes memakai direktori temp tanpa menyentuh `database/keys` nyata.
 */
final class ServerSshKeyStore
{
    /** Id server: alfanumerik/underscore/strip (aman untuk nama berkas). */
    private const SERVER_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Batas isi private key (64 KiB, cukup untuk key ED25519/RSA 8192). */
    private const MAX_KEY_BYTES = 65536;

    /** Batas satu baris host key (mencegah berkas known_hosts membengkak). */
    private const MAX_HOST_KEY_BYTES = 8192;

    private const DIR_MODE = 0700;
    private const FILE_MODE = 0600;

    private readonly string $keysDir;

    /**
     * @param string|null $keysDir direktori kunci; null = `config('deploy.ssh_keys_path')`
     */
    public function __construct(?string $keysDir = null)
    {
        $dir = $keysDir !== null && trim($keysDir) !== ''
            ? trim($keysDir)
            : (string) config('deploy.ssh_keys_path', base_path() . '/database/keys');

        $this->keysDir = rtrim($dir, '/');
    }

    /**
     * Direktori akar kunci (mis. `database/keys`).
     */
    public function keysDir(): string
    {
        return $this->keysDir;
    }

    /**
     * Path private key sebuah server. Seperti `SshKeyManager::privateKeyPath()`,
     * direktori `servers/` dipastikan ada (mode 0700) — direktori kosong, bukan
     * rahasia — agar path siap dipakai sebagai bind mount / target tulis.
     */
    public function path(string $serverId): string
    {
        return $this->ensureServersDir() . '/' . $this->assertServerId($serverId);
    }

    /**
     * Apakah private key server sudah tersimpan.
     */
    public function exists(string $serverId): bool
    {
        return is_file($this->path($serverId));
    }

    /**
     * Apakah host key server sudah dipin (pernah `saveKnownHost()`).
     */
    public function hasPin(string $serverId): bool
    {
        return is_file($this->pinPath($serverId));
    }

    /**
     * Path berkas known_hosts bersama (format OpenSSH).
     */
    public function knownHostsPath(): string
    {
        return $this->keysDir . '/known_hosts';
    }

    /**
     * Simpan private key server (atomik, 0600). Isi harus PEM yang memuat
     * header `BEGIN ... PRIVATE KEY`; isi > 64 KiB ditolak.
     *
     * Pesan galat hanya menyebut path — TIDAK pernah potongan isi kunci.
     *
     * @throws RuntimeException bila id tidak aman atau isi bukan private key
     */
    public function save(string $serverId, string $privateKey): void
    {
        $path = $this->path($serverId);

        if ($privateKey === '' || strlen($privateKey) > self::MAX_KEY_BYTES
            || !str_contains($privateKey, '-----BEGIN ')
            || !str_contains($privateKey, 'PRIVATE KEY-----')
            || !str_contains($privateKey, '-----END ')
        ) {
            throw new RuntimeException(
                'Isi kunci privat SSH tidak valid: harus PEM utuh berisi "BEGIN ... PRIVATE KEY" dan "-----END"'
                . ', maksimal 64 KiB (berkas: ' . $path . ').'
            );
        }

        $this->ensureServersDir();
        $this->writeAtomic($path, $privateKey, self::FILE_MODE);
    }

    /**
     * Hapus private key + pin server (idempoten) beserta entri known_hosts
     * miliknya. Entri host lain tidak disentuh.
     */
    public function delete(string $serverId): void
    {
        $pinPath = $this->pinPath($serverId);
        $pinnedHost = null;
        if (is_file($pinPath)) {
            $content = file_get_contents($pinPath);
            if (is_string($content) && $content !== '') {
                $parts = preg_split('/\s+/', trim($content)) ?: [];
                $pinnedHost = $parts[0] ?? null;
            }
        }

        foreach ([$this->path($serverId), $this->path($serverId) . '.pub', $pinPath] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Tidak bisa menghapus berkas kunci SSH: ' . $file);
            }
        }

        if ($pinnedHost !== null && $pinnedHost !== '') {
            $this->removeKnownHostEntry($pinnedHost);
        }
    }

    /**
     * Simpan/pin host key server: tulis `[host]:port <hostKeyLine>` ke
     * `<keysDir>/known_hosts` (ganti entri host:port yang sama, entri host lain
     * dipertahankan) dan penanda `servers/{serverId}.pin`.
     *
     * @throws RuntimeException bila host/port/baris host key tidak sah
     */
    public function saveKnownHost(string $serverId, string $host, int $port, string $hostKeyLine): void
    {
        $this->assertServerId($serverId);

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Port host key SSH harus 1–65535.');
        }

        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost === '' || strlen($normalizedHost) > 253
            || preg_match('/[\s#,\[\]]/', $normalizedHost) === 1
        ) {
            throw new RuntimeException('Host untuk known_hosts tidak valid (tanpa spasi, koma, #, atau kurung siku).');
        }

        $keyLine = trim($hostKeyLine);
        if ($keyLine === '' || strlen($keyLine) > self::MAX_HOST_KEY_BYTES
            || str_contains($keyLine, "\n") || str_contains($keyLine, "\r")
        ) {
            throw new RuntimeException('Baris host key SSH tidak valid (satu baris, maksimal 8 KiB).');
        }

        $entry = '[' . $normalizedHost . ']:' . $port . ' ' . $keyLine;

        $knownHosts = $this->knownHostsPath();
        $lines = $this->knownHostLines();
        $result = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (($parts[0] ?? '') === '[' . $normalizedHost . ']:' . $port) {
                continue; // entri lama host:port ini diganti
            }
            $result[] = $line;
        }
        $result[] = $entry;

        $this->writeAtomic($knownHosts, implode("\n", $result) . "\n", self::FILE_MODE);
        $this->ensureServersDir();
        $this->writeAtomic($this->pinPath($serverId), $entry . "\n", self::FILE_MODE);
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Hapus satu entri host:port dari known_hosts (idempoten).
     *
     * Baris dibaca sebelum berkas penanda dihapus oleh {@see self::delete()}.
     */
    private function removeKnownHostEntry(string $hostSpec): void
    {
        $knownHosts = $this->knownHostsPath();
        if (!is_file($knownHosts)) {
            return;
        }

        $lines = $this->knownHostLines();
        $result = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (($parts[0] ?? '') === $hostSpec) {
                continue;
            }
            $result[] = $line;
        }

        if (count($result) === count($lines)) {
            return; // tidak ada yang cocok — biarkan berkas apa adanya
        }

        $this->writeAtomic($knownHosts, $result === [] ? '' : implode("\n", $result) . "\n", self::FILE_MODE);
    }

    /**
     * Baris known_hosts (tanpa baris kosong komentar tidak diubah).
     *
     * @return array<int,string>
     */
    private function knownHostLines(): array
    {
        $path = $this->knownHostsPath();
        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function ensureServersDir(): string
    {
        $dir = $this->keysDir . '/servers';
        if (!is_dir($dir) && !mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new RuntimeException('Tidak bisa membuat direktori kunci SSH server: ' . $dir);
        }
        chmod($dir, self::DIR_MODE);

        return $dir;
    }

    private function pinPath(string $serverId): string
    {
        return $this->ensureServersDir() . '/' . $this->assertServerId($serverId) . '.pin';
    }

    /**
     * Tulis atomik: berkas temp di direktori tujuan → chmod → rename.
     * Kegagalan tidak meninggalkan berkas temp.
     */
    private function writeAtomic(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
                throw new RuntimeException('Tidak bisa membuat direktori kunci SSH: ' . $dir);
            }
            chmod($dir, self::DIR_MODE);
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Tidak bisa menulis berkas kunci SSH: ' . $path);
        }

        try {
            $written = fwrite($handle, $contents);
            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException('Gagal menulis berkas kunci SSH: ' . $path);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            unlink($tmp);
            throw $e;
        }
        fclose($handle);

        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            unlink($tmp);
            throw new RuntimeException('Gagal menyimpan berkas kunci SSH: ' . $path);
        }
        chmod($path, $mode);
    }

    /**
     * @throws RuntimeException bila id mengandung karakter di luar pola aman
     */
    private function assertServerId(string $serverId): string
    {
        if (preg_match(self::SERVER_ID_PATTERN, $serverId) !== 1) {
            throw new RuntimeException(
                'Id server tidak valid untuk nama berkas kunci (huruf, angka, underscore, strip; maksimal 64 karakter).'
            );
        }

        return $serverId;
    }
}
