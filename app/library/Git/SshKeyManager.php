<?php
declare(strict_types=1);

namespace app\library\Git;

use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Kelola pasangan kunci SSH (deploy key) per app untuk clone/pull repo
 * private via SSH.
 *
 * Alur: sistem membangkitkan keypair ed25519 per app, private key disimpan
 * lokal (tidak pernah keluar server), public key ditampilkan ke user untuk
 * ditambahkan sebagai Deploy Key di repo (GitHub/GitLab Settings → Deploy keys).
 *
 * Lokasi: {deploy.ssh_keys_path}/{name} (+ .pub), chmod 0600. Direktori storage
 * dikelola sistem dan di-gitignore.
 */
class SshKeyManager
{
    private ProcessRunner $runner;

    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
    }

    /**
     * Direktori penyimpanan kunci (default: database/keys).
     */
    public function keysDir(): string
    {
        return (string) config('deploy.ssh_keys_path', base_path() . '/database/keys');
    }

    /**
     * Pastikan direktori kunci ada (mode 0700) lalu kembalikan path-nya.
     */
    public function ensureDir(): string
    {
        $dir = $this->keysDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori kunci SSH: {$dir}");
        }
        return $dir;
    }

    /**
     * Path private key sebuah app.
     */
    public function privateKeyPath(string $name): string
    {
        return $this->ensureDir() . '/' . $name;
    }

    /**
     * Path public key sebuah app.
     */
    public function publicKeyPath(string $name): string
    {
        return $this->privateKeyPath($name) . '.pub';
    }

    /**
     * Apakah pasangan kunci app sudah ada.
     */
    public function exists(string $name): bool
    {
        return is_file($this->privateKeyPath($name));
    }

    /**
     * Generate pasangan kunci ed25519 (tanpa passphrase) untuk sebuah app.
     * Dipanggil sebelum clone saat wizard create; jika sudah ada, dipakai ulang
     * (mis. user mencoba Analisis Repo ulang setelah menambah deploy key).
     *
     * @return array{public:string} isi public key
     */
    public function generate(string $name): array
    {
        $path = $this->privateKeyPath($name);

        if (!$this->exists($name)) {
            $result = $this->runner->run(
                ['ssh-keygen', '-t', 'ed25519', '-N', '', '-C', 'rames-' . $name, '-f', $path],
                null,
                60
            );
            if ($result['code'] !== 0) {
                throw new RuntimeException('Gagal generate SSH deploy key: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
            }
            @chmod($path, 0600);
        }

        $pub = @file_get_contents($path . '.pub');
        if ($pub === false) {
            throw new RuntimeException("Gagal membaca public key: {$path}.pub");
        }
        return ['public' => trim($pub)];
    }

    /**
     * Isi public key sebuah app, atau null bila belum ada.
     */
    public function publicKey(string $name): ?string
    {
        $path = $this->publicKeyPath($name);
        if (!is_file($path)) {
            return null;
        }
        $pub = @file_get_contents($path);
        return $pub === false ? null : trim($pub);
    }

    /**
     * Hapus pasangan kunci app (private + public). Dipakai saat delete app.
     */
    public function remove(string $name): void
    {
        foreach ([$this->privateKeyPath($name), $this->publicKeyPath($name)] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
