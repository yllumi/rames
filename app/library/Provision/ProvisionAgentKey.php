<?php
declare(strict_types=1);

namespace app\library\Provision;

use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Kunci SSH **bersama** untuk provisioning server agent (opsi B).
 *
 * Bedanya dengan {@see ServerSshKeyStore}: kunci ini tidak per-server, ia satu
 * pasangan `ed25519` yang dipakai ulang untuk **semua** VM agent. Alurnya:
 *
 *   1. dashboard membuat pasangan sekali (`ensure()`, idempoten);
 *   2. operator menyalin **public key** ke VM baru (`~/.ssh/authorized_keys`
 *      lewat console provider/cloud-init, atau panel SSH key provider);
 *   3. job provisioning memakai **private key** yang tidak pernah keluar dari
 *      control plane — di-mount read-only ke helper container Ansible.
 *
 * Kenapa satu kunci bersama (bukan per-server): private key tidak lagi perlu
 * diketik/ditempel di form, sehingga (a) tidak ada rahasia yang melewati browser,
 * dan (b) menambah server baru cukup menyalin public key yang sama.
 *
 * Lokasi: `{deploy.ssh_keys_path}/provision/rames-agent-key` (+ `.pub`), direktori
 * 0700, private 0600, public 0644. Kelas ini **tidak pernah** mengembalikan isi
 * private key — hanya path, public key, dan fingerprint.
 */
final class ProvisionAgentKey
{
    /** Nama berkas kunci (juga komentar kunci, terlihat di `authorized_keys` VM). */
    public const SLOT = 'rames-agent-key';

    /** Prefix komentar kunci — memudahkan operator mengenali asalnya di VM. */
    public const COMMENT = 'rames-agent-key';

    private const DIR_MODE = 0700;
    private const PRIVATE_MODE = 0600;
    private const PUBLIC_MODE = 0644;

    /** Timeout `ssh-keygen` (detik) — generasi ed25519 praktis instan. */
    private const TIMEOUT = 30;

    private readonly ProcessRunner $runner;
    private readonly string $dir;

    /**
     * @param ProcessRunner|null $runner  injeksi untuk uji (tanpa `ssh-keygen`)
     * @param string|null        $keysDir direktori kunci (default `deploy.ssh_keys_path`)
     */
    public function __construct(?ProcessRunner $runner = null, ?string $keysDir = null)
    {
        $this->runner = $runner ?? new ProcessRunner();

        $base = trim((string) ($keysDir ?? config('deploy.ssh_keys_path', base_path() . '/database/keys')));
        if ($base === '') {
            $base = base_path() . '/database/keys';
        }

        $this->dir = rtrim($base, '/') . '/provision';
    }

    /** Direktori kunci bersama (`{keysDir}/provision`). */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Path private key (RAHASIA — dipasang read-only ke helper container). */
    public function privatePath(): string
    {
        return $this->dir . '/' . self::SLOT;
    }

    /** Path public key (aman ditampilkan ke browser). */
    public function publicPath(): string
    {
        return $this->privatePath() . '.pub';
    }

    /** Apakah pasangan kunci sudah lengkap (private + public). */
    public function exists(): bool
    {
        return is_file($this->privatePath()) && is_file($this->publicPath());
    }

    /** Isi public key (`''` bila belum ada). Tidak pernah melempar. */
    public function publicKey(): string
    {
        if (!is_file($this->publicPath())) {
            return '';
        }

        $content = @file_get_contents($this->publicPath());

        return $content === false ? '' : trim($content);
    }

    /**
     * Fingerprint SHA256 public key (`''` bila belum ada / `ssh-keygen` gagal).
     *
     * Dipakai UI agar operator bisa membandingkan dengan server tujuan
     * (`ssh-keygen -lf ~/.ssh/authorized_keys`) tanpa menyalin kunci panjang.
     */
    public function fingerprint(): string
    {
        if (!is_file($this->publicPath())) {
            return '';
        }

        $result = $this->runner->run(['ssh-keygen', '-lf', $this->publicPath()], null, self::TIMEOUT);
        if ((int) ($result['code'] ?? 1) !== 0) {
            return '';
        }

        $parts = preg_split('/\s+/', trim((string) ($result['stdout'] ?? ''))) ?: [];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'SHA256:')) {
                return $part;
            }
        }

        return '';
    }

    /**
     * Pastikan pasangan kunci ada; buat bila belum (idempoten).
     *
     * Idempoten: berkas yang sudah ada **tidak** disentuh, sehingga public key
     * yang sudah dipasang di VM-VM lain tetap berlaku. Kasus rusak sebagian
     * (private ada, public hilang) ditangani dengan menurunkan public key dari
     * private (`ssh-keygen -y`), bukan membuat ulang pasangan.
     *
     * @return array{public_key:string, fingerprint:string, created:bool}
     * @throws RuntimeException bila `ssh-keygen` tidak tersedia/gagal
     */
    public function ensure(): array
    {
        $created = false;

        if (!is_file($this->privatePath())) {
            $this->generate();
            $created = true;
        } elseif (!is_file($this->publicPath())) {
            $this->derivePublicKey();
        }

        $public = $this->publicKey();
        if ($public === '') {
            throw new RuntimeException(
                'Kunci provisioning tidak terbaca setelah dibuat: ' . $this->publicPath()
                . ' — periksa izin direktori kunci.'
            );
        }

        return [
            'public_key' => $public,
            'fingerprint' => $this->fingerprint(),
            'created' => $created,
        ];
    }

    /**
     * Buat pasangan kunci di direktori sementara lalu pindahkan atomik — pembaca
     * (job provisioning) tidak boleh melihat private 0 byte / public setengah.
     */
    private function generate(): void
    {
        $this->ensureDir();

        $tmp = $this->dir . '/.gen-' . bin2hex(random_bytes(6));
        $result = $this->runner->run(
            ['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', self::COMMENT, '-f', $tmp],
            null,
            self::TIMEOUT
        );

        $ok = (int) ($result['code'] ?? 1) === 0 && is_file($tmp) && is_file($tmp . '.pub');
        if (!$ok) {
            @unlink($tmp);
            @unlink($tmp . '.pub');
            $detail = trim((string) ($result['stderr'] ?? ''));
            throw new RuntimeException(
                'Gagal membuat kunci provisioning dengan ssh-keygen' . ($detail !== '' ? ': ' . $detail : '.')
            );
        }

        @chmod($tmp, self::PRIVATE_MODE);
        @chmod($tmp . '.pub', self::PUBLIC_MODE);

        if (!@rename($tmp, $this->privatePath())) {
            @unlink($tmp);
            @unlink($tmp . '.pub');
            throw new RuntimeException('Gagal menyimpan kunci privat provisioning (periksa izin direktori).');
        }
        @chmod($this->privatePath(), self::PRIVATE_MODE);

        if (!@rename($tmp . '.pub', $this->publicPath())) {
            @unlink($tmp . '.pub');
            throw new RuntimeException('Gagal menyimpan public key provisioning (periksa izin direktori).');
        }
        @chmod($this->publicPath(), self::PUBLIC_MODE);
    }

    /** Turunkan public key dari private yang sudah ada (berkas public hilang). */
    private function derivePublicKey(): void
    {
        $result = $this->runner->run(
            ['ssh-keygen', '-y', '-f', $this->privatePath()],
            null,
            self::TIMEOUT
        );

        $public = trim((string) ($result['stdout'] ?? ''));
        if ((int) ($result['code'] ?? 1) !== 0 || $public === '') {
            throw new RuntimeException(
                'Public key provisioning tidak bisa diturunkan dari private key di ' . $this->privatePath()
                . ' — periksa berkasnya atau hapus pasangan itu lalu buat ulang.'
            );
        }

        // `ssh-keygen -y` versi baru sudah menyertakan komentar kunci; hanya
        // tambahkan bila keluarannya cuma "<tipe> <base64>".
        $parts = preg_split('/\s+/', $public) ?: [];
        $line = count($parts) >= 3 ? $public : $public . ' ' . self::COMMENT;

        if (@file_put_contents($this->publicPath(), $line . "\n") === false) {
            throw new RuntimeException('Gagal menulis public key provisioning (periksa izin direktori).');
        }
        @chmod($this->publicPath(), self::PUBLIC_MODE);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, self::DIR_MODE, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Tidak bisa membuat direktori kunci provisioning: ' . $this->dir);
        }
        @chmod($this->dir, self::DIR_MODE);
    }
}
