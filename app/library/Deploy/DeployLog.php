<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Support\LogChunkReader;
use InvalidArgumentException;

/**
 * Pembaca log deploy per app (`runtime/logs/deploy/{appId}.log`).
 *
 * Berkas itu ditulis **worker** (`cli/deploy.php`) dan berisi baris tahap
 * worker (`[waktu] [stage] pesan`) serta output mentah `docker compose`
 * (streaming build, tanpa prefix). UI hanya membacanya lewat endpoint
 * `GET /api/apps/{id}/deploy-log` — tidak ada salinan log kedua.
 *
 * Pembacaan memakai offset byte ({@see LogChunkReader}) supaya polling UI tidak
 * mengulang baris lama dan tidak pernah memuat berkas raksasa sekaligus.
 *
 * Kelas tanpa state lintas-request (hanya path direktori) ⇒ aman di worker
 * Webman persistent.
 */
final class DeployLog
{
    /** Bentuk id app/target yang aman dijadikan nama berkas (cegah path traversal). */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    private readonly string $dir;

    /**
     * @param string|null $dir direktori log deploy; null = `runtime/logs/deploy`
     */
    public function __construct(?string $dir = null)
    {
        $dir = trim((string) $dir);
        $this->dir = rtrim($dir !== '' ? $dir : runtime_path('logs/deploy'), '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * @throws InvalidArgumentException id tidak sah
     */
    public function path(string $id): string
    {
        $id = trim($id);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('id log tidak valid.');
        }

        return $this->dir . '/' . $id . '.log';
    }

    /**
     * Baca potongan log ≤256 KiB mulai `offset` byte.
     *
     * @param bool $tail `true` = abaikan `$offset` dan mulai dari ekor log
     *                   (offset diselaraskan ke awal baris) — dipakai UI saat
     *                   modal dibuka agar yang tampil adalah deploy terakhir,
     *                   bukan awal berkas yang menumpuk sejak deploy pertama.
     * @return array{text:string, offset:int} `offset` = offset lanjutan
     */
    public function read(string $id, int $offset = 0, bool $tail = false): array
    {
        $path = $this->path($id);
        $offset = $tail ? $this->tailOffset($path) : max(0, $offset);
        $text = LogChunkReader::read($path, $offset);

        return ['text' => $text, 'offset' => $offset + strlen($text)];
    }

    /**
     * Tambahkan satu baris ke log deploy (append atomic + `LOCK_EX`).
     *
     * Dipakai pembatalan deploy (`DeployCanceller`) agar jejak cancel ikut
     * terlihat di modal log. Baris dinormalkan dan selalu diakhiri newline.
     */
    public function append(string $id, string $line): void
    {
        $line = trim(str_replace(["\r\n", "\r"], "\n", $line));
        if ($line === '') {
            return;
        }

        $path = $this->path($id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Id job agent terakhir yang tercatat di log (`Job ... (job <id>)`) — jalur
     * cadangan untuk membatalkan job di server agent bila field
     * `apps.agent_job_id` tidak tersedia (worker lama).
     */
    public function lastAgentJobId(string $id): string
    {
        $text = $this->read($id, 0, true)['text'];
        if ($text === '' || preg_match_all('/\(job ([A-Za-z0-9_-]{1,128})\)/', $text, $matches) === 0) {
            return '';
        }

        return (string) ($matches[1][count($matches[1]) - 1] ?? '');
    }

    /**
     * Offset awal untuk membaca ekor berkas (≤ {@see LogChunkReader::MAX_READ}
     * byte terakhir) lalu maju ke awal baris berikutnya agar potongan pertama
     * tidak dimulai di tengah baris.
     */
    private function tailOffset(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $size = @filesize($path);
        if ($size === false || $size <= LogChunkReader::MAX_READ) {
            return 0;
        }

        $start = $size - LogChunkReader::MAX_READ;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $start;
        }

        try {
            if (fseek($handle, $start) !== 0) {
                return $start;
            }
            $probe = fread($handle, 8192);
        } finally {
            fclose($handle);
        }

        if ($probe === false || $probe === '') {
            return $start;
        }

        $newline = strpos($probe, "\n");

        return $newline === false ? $start : $start + $newline + 1;
    }
}
