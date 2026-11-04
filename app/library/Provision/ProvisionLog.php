<?php
declare(strict_types=1);

namespace app\library\Provision;

use app\library\Support\LogChunkReader;
use InvalidArgumentException;

/**
 * Log worker provisioning (`runtime/logs/provision/{jobId}.log`).
 *
 * Satu berkas per job, ditulis worker {@see \cli\provision} (di luar
 * `register_shutdown_function` apapun) dan dibaca UI lewat offset byte —
 * pola yang sama dengan {@see \app\library\Deploy\DeployLog}, termasuk
 * {@see LogChunkReader} sebagai satu-satunya pembaca potongan.
 *
 * Log **tidak pernah** memuat isi berkas onboarding (token/PEM): worker hanya
 * mencatat tahap dan output `ansible-playbook`, dan baris yang terlalu panjang
 * dipotong supaya satu `debug:` berisi variabel rahasia tidak pernah utuh.
 *
 * Tanpa state lintas-request (hanya path direktori) ⇒ aman di worker Webman.
 */
final class ProvisionLog
{
    /** Bentuk id job yang aman dijadikan nama berkas (cegah path traversal). */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Plafon panjang satu baris log (karakter) — menahan output `-vvv`/`debug`. */
    public const MAX_LINE = 2000;

    /** Penanda baris dipotong; ASCII agar tidak merusak encoding berkas. */
    private const TRUNCATED = ' ...[dipotong]';

    private readonly string $dir;

    /**
     * @param string|null $dir direktori log; null = `runtime/logs/provision`
     */
    public function __construct(?string $dir = null)
    {
        $dir = trim((string) $dir);
        $this->dir = rtrim($dir !== '' ? $dir : runtime_path('logs/provision'), '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * @throws InvalidArgumentException id tidak sah
     */
    public function path(string $jobId): string
    {
        $jobId = trim($jobId);
        if (preg_match(self::ID_PATTERN, $jobId) !== 1) {
            throw new InvalidArgumentException('id job provisioning tidak valid.');
        }

        return $this->dir . '/' . $jobId . '.log';
    }

    /**
     * Tambahkan potongan output ke log job.
     *
     * Potongan dari `ProcessRunner::runStreaming()` bisa memuat beberapa baris
     * sekaligus dan tidak selalu berakhir di newline; setiap baris diberi
     * timestamp ISO-8601 sendiri agar urutan tahap terbaca, dan baris kosong
     * (mis. spasi keluaran Ansible) tidak ditulis.
     */
    public function append(string $jobId, string $line): void
    {
        $path = $this->path($jobId);

        $stamp = '[' . gmdate('c') . '] ';
        $rows = '';
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $line)) as $row) {
            if (trim($row) === '') {
                continue;
            }
            $rows .= $stamp . self::truncate($row) . "\n";
        }
        if ($rows === '') {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($path, $rows, FILE_APPEND | LOCK_EX);
    }

    /**
     * Baca potongan log ≤256 KiB mulai `offset` byte.
     *
     * @return array{content:string, offset:int} `offset` = offset lanjutan
     */
    public function read(string $jobId, int $offset = 0): array
    {
        $path = $this->path($jobId);
        $offset = max(0, $offset);
        $content = LogChunkReader::read($path, $offset);

        return ['content' => $content, 'offset' => $offset + strlen($content)];
    }

    /** Hapus log satu job (idempoten). */
    public function delete(string $jobId): void
    {
        $path = $this->path($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Potong baris ke {@see self::MAX_LINE} karakter.
     *
     * `mb_strcut` dipakai bila tersedia supaya potongan tidak berhenti di tengah
     * karakter UTF-8 (berkas log dibaca API lalu di-`json_encode`).
     */
    private static function truncate(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE) {
            return $line;
        }

        $cut = self::MAX_LINE - strlen(self::TRUNCATED);
        $kept = function_exists('mb_strcut')
            ? mb_strcut($line, 0, $cut, 'UTF-8')
            : substr($line, 0, $cut);

        return $kept . self::TRUNCATED;
    }
}
