<?php
declare(strict_types=1);

namespace app\library\Storage;

use RuntimeException;

/**
 * Penyimpanan JSON sederhana dengan file locking (flock) dan backup sebelum
 * overwrite — sesuai Security Considerations SPECS.md §11.
 *
 * Semua mutasi dilakukan lewat update() agar baca-tulis atomik (anti race
 * condition saat dua request bersamaan).
 */
class JsonStore
{
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function path(): string
    {
        return $this->filePath;
    }

    /**
     * Baca seluruh data. File tidak ada / kosong => [].
     *
     * @return array
     */
    public function read(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }
        $raw = file_get_contents($this->filePath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Korup JSON: {$this->filePath}");
        }
        return $data;
    }

    /**
     * Tulis seluruh data (lock + backup).
     */
    public function write(array $data): void
    {
        $this->ensureDir();
        if (is_file($this->filePath)) {
            @copy($this->filePath, $this->filePath . '.bak');
        }
        $encoded = $this->encode($data);
        $tmp = $this->filePath . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException("Gagal menulis file sementara {$tmp}");
        }
        if (!@rename($tmp, $this->filePath)) {
            @unlink($tmp);
            throw new RuntimeException("Gagal mengganti {$tmp} -> {$this->filePath}");
        }
    }

    /**
     * Update atomik: kunci file (LOCK_EX), baca data, panggil mutator(array $data),
     * tulis ulang, buka kunci.
     *
     * Mutator menerima data by reference; jika mutator melempar exception, tidak
     * ada penulisan parsial (data tetap utuh).
     */
    public function update(callable $mutator): void
    {
        $this->ensureDir();
        $fp = fopen($this->filePath, 'c+');
        if ($fp === false) {
            throw new RuntimeException("Tidak bisa membuka {$this->filePath}");
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException("Gagal mengunci {$this->filePath}");
            }
            $size = filesize($this->filePath);
            $raw = $size === false || $size === 0 ? '[]' : (string) stream_get_contents($fp);
            $data = json_decode($raw ?: '[]', true);
            if (!is_array($data)) {
                $data = [];
            }
            $mutator($data);
            // backup hanya saat benar-benar menimpa data yang sudah ada
            if ($size !== false && $size > 0) {
                @copy($this->filePath, $this->filePath . '.bak');
            }
            $encoded = $this->encode($data);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori {$dir}");
        }
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal encode JSON: ' . json_last_error_msg());
        }
        return $json . PHP_EOL;
    }
}
