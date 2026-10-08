<?php
declare(strict_types=1);

namespace app\library\Adminer;

use GuzzleHttp\Psr7\Stream;

/**
 * Sink Guzzle yang menulis ke berkas nyata di disk dan **menolak** byte ke-`$maxBytes+1`.
 *
 * Mengapa subclass `GuzzleHttp\Psr7\Stream` dan bukan `php://temp`:
 * Workerman mengirim berkas respons lewat `Response::withFile($path)` (butuh PATH
 * berkas nyata) SETELAH handler controller kembali, sedangkan `php://temp`
 * hanya menyimpan nama berkas setelah spill dan segera hilang saat handle
 * ditutup. Berkas nyata di `runtime/adminer-proxy/` memenuhi tujuan kontrak
 * ("byte tidak ditahan di memori PHP") sekaligus bisa di-stream Workerman.
 *
 * Batas ukuran ditegakkan **saat menulis** (bukan hanya dari `Content-Length`)
 * sehingga respons chunked raksasa pun berhenti sebelum menghabiskan disk:
 * Guzzle menangkap exception ini di `CURLOPT_WRITEFUNCTION`, membatalkan
 * transfer, dan meneruskannya sebagai rejection (previous dari RequestException).
 */
final class LimitedTempSink extends Stream
{
    private int $written = 0;

    private int $maxBytes;

    /**
     * @param resource $handle handle berkas `w+b` milik pemanggil (ditutup oleh
     *                         `close()`/destructor stream, berkas tetap ada di disk)
     */
    public function __construct($handle, int $maxBytes)
    {
        parent::__construct($handle);
        $this->maxBytes = max(0, $maxBytes);
    }

    public function write(string $string): int
    {
        if ($this->maxBytes > 0 && $this->written + strlen($string) > $this->maxBytes) {
            throw new AdminerProxyLimitExceeded(AdminerProxy::LIMIT_MESSAGE);
        }

        $written = parent::write($string);
        $this->written += $written;

        return $written;
    }

    /**
     * Jumlah byte yang sudah ditulis ke sink.
     */
    public function bytesWritten(): int
    {
        return $this->written;
    }
}
