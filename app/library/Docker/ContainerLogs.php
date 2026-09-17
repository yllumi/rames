<?php
declare(strict_types=1);

namespace app\library\Docker;

/**
 * Log container: pembersihan stream multiplexed & normalisasi parameter.
 *
 * Docker Engine mengembalikan log container **tanpa TTY** sebagai stream
 * multiplexed: tiap frame = header 8 byte (`stream` 1 byte, 3 byte padding,
 * panjang payload 4 byte big-endian) + payload. Header itu harus dibuang supaya
 * teks log terbaca; container ber-TTY mengembalikan teks polos.
 *
 * Fungsi statik tanpa I/O supaya mudah diuji.
 */
final class ContainerLogs
{
    public const DEFAULT_TAIL = 200;
    public const MAX_TAIL = 2000;
    public const MIN_TAIL = 50;

    /**
     * Pilihan jumlah baris untuk dropdown modal log.
     *
     * @return array<int,string> nilai → label
     */
    public static function tailOptions(): array
    {
        $options = [];
        foreach ([self::MIN_TAIL, self::DEFAULT_TAIL, 500, 1000, self::MAX_TAIL] as $n) {
            $options[$n] = $n . ' baris terakhir';
        }
        return $options;
    }

    /**
     * Batasi jumlah baris yang diminta user ke rentang aman.
     */
    public static function normalizeTail(mixed $tail): int
    {
        $value = (int) $tail;
        if ($value < 1) {
            return self::DEFAULT_TAIL;
        }
        return min($value, self::MAX_TAIL);
    }

    /**
     * Ubah respons `/containers/{id}/logs` jadi teks log polos.
     *
     * Bila respons bukan stream multiplexed (container TTY, atau bukan format
     * frame sama sekali), teks dikembalikan apa adanya.
     */
    public static function demultiplex(string $raw): string
    {
        $length = strlen($raw);
        if ($length < 8) {
            return $raw;
        }

        $offset = 0;
        $payload = '';
        while ($offset + 8 <= $length) {
            $header = unpack('Cstream/Cpad1/Cpad2/Cpad3/Nsize', substr($raw, $offset, 8));
            if ($header === false
                || $header['stream'] > 2
                || $header['pad1'] !== 0 || $header['pad2'] !== 0 || $header['pad3'] !== 0
            ) {
                return $raw; // header tidak valid → bukan multiplexed
            }
            $size = (int) $header['size'];
            if ($offset + 8 + $size > $length) {
                return $raw; // frame terpotong → bukan multiplexed
            }
            $payload .= substr($raw, $offset + 8, $size);
            $offset += 8 + $size;
        }

        // frame harus mengisi buffer tepat habis; kalau tidak, data bukan multiplexed
        return $offset === $length ? $payload : $raw;
    }
}
