<?php
declare(strict_types=1);

namespace app\library\System;

/**
 * Metrik host (CPU, memori, load, uptime) dari pseudo-filesystem `/proc` dan
 * **pemakaian storage** (filesystem) — "total VM" pada halaman `/monitor`
 * (SPECS §8d).
 *
 * Catatan penting: `/proc` di dalam container dashboard SUDAH menampilkan nilai
 * host (Docker tidak men-*namespace*-kan `stat`/`meminfo`/`loadavg`; hanya cgroup
 * dan PID yang di-namespace), sehingga tidak perlu mount tambahan dari host.
 * Bila host memakai lxcfs (mis. lingkungan tertentu), nilai yang terbaca akan
 * ter-scope container — `host_proc_path` bisa diarahkan ke mount host eksplisit.
 *
 * Storage diukur `statvfs` pada satu path (`disk()`); path itu **harus** berupa
 * bind mount host (mis. `${PWD}:${PWD}` milik container dashboard) agar angka
 * yang dilaporkan milik filesystem host, bukan overlay container.
 *
 * Kelas ini murni (tanpa state, tanpa I/O Engine) supaya mudah diuji: seluruh
 * penafsiran file `/proc` ada di sini, pemanggil hanya menyediakan path.
 */
final class HostUsage
{
    /**
     * Durasi sampling CPU (mikrodetik). Persentase CPU hanya bisa dihitung dari
     * **delta** dua sampel `/proc/stat`; dibaca dari file lokal sehingga murah
     * (berbeda dari `GET /containers/{id}/stats` yang menunggu daemon).
     */
    public const SAMPLE_MICROS = 250000;

    /**
     * Ringkasan pemakaian host. Field yang tidak terbaca bernilai `null`
     * (UI menampilkan N/A) — bukan exception, agar halaman tetap tampil.
     *
     * @param string      $procPath     direktori `/proc` host (bisa di-override config)
     * @param int         $sampleMicros durasi sampling CPU; 0 = lewati (cpu_percent null)
     * @param string|null $diskPath     path di filesystem host yang diukur; null = tanpa metrik disk
     * @return array{
     *   available:bool, proc_path:string, cpu_percent:?float, cpu_count:?int,
     *   mem_total:?int, mem_used:?int, mem_available:?int, mem_percent:?float,
     *   load_1:?float, load_5:?float, load_15:?float, uptime_seconds:?int,
     *   disk_path:?string, disk_total:?int, disk_used:?int, disk_available:?int, disk_percent:?float
     * }
     */
    public static function snapshot(
        string $procPath = '/proc',
        int $sampleMicros = self::SAMPLE_MICROS,
        ?string $diskPath = null
    ): array {
        $before = $sampleMicros > 0 ? self::cpuSample($procPath) : null;
        if ($before !== null && $sampleMicros > 0) {
            usleep($sampleMicros);
        }
        $after = self::cpuSample($procPath);

        $memory = self::memory($procPath);
        $load = self::load($procPath);
        [$load1, $load5, $load15] = $load ?? [null, null, null];
        $disk = $diskPath !== null && $diskPath !== '' ? self::disk($diskPath) : null;

        return [
            'available' => $after !== null || $memory !== null,
            'proc_path' => $procPath,
            // hanya terisi bila ada dua sampel (sampling dilewati → null)
            'cpu_percent' => self::percentBetween($before, $after),
            'cpu_count' => self::cpuCount($procPath),
            'mem_total' => $memory['total'] ?? null,
            'mem_used' => $memory['used'] ?? null,
            'mem_available' => $memory['available'] ?? null,
            'mem_percent' => $memory['percent'] ?? null,
            'load_1' => $load1,
            'load_5' => $load5,
            'load_15' => $load15,
            'uptime_seconds' => self::uptimeSeconds($procPath),
            'disk_path' => $diskPath !== null && $diskPath !== '' ? $diskPath : null,
            'disk_total' => $disk['total'] ?? null,
            'disk_used' => $disk['used'] ?? null,
            'disk_available' => $disk['available'] ?? null,
            'disk_percent' => $disk['percent'] ?? null,
        ];
    }

    /**
     * Pemakaian **storage** (filesystem) tempat `$path` berada.
     *
     * Path harus berupa mount yang sama dengan host (mis. bind mount `${PWD}` di
     * container dashboard) — `disk_total_space()` membaca filesystem di bawah
     * path itu, bukan overlay container-nya. `total`/`available` memakai
     * `statvfs` (`disk_total_space`/`disk_free_space`); `used` = total −
     * available, `percent` mengikuti cara `df` (dibulatkan satu desimal).
     *
     * @return array{path:string,total:int,used:int,available:int,percent:float}|null
     *         null bila path tidak ada/tidak terbaca atau fungsi disk dinonaktifkan
     */
    public static function disk(string $path): ?array
    {
        $path = trim($path);
        if ($path === '' || !file_exists($path) || !function_exists('disk_total_space') || !function_exists('disk_free_space')) {
            return null;
        }

        $total = @disk_total_space($path);
        $available = @disk_free_space($path);
        if ($total === false || $available === false) {
            return null;
        }

        $total = (int) $total;
        $available = max(0, min((int) $available, $total));
        $used = $total - $available;
        if ($total <= 0) {
            return null;
        }

        return [
            'path' => $path,
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percent' => self::percent($used, $total),
        ];
    }

    /**
     * Persentase `$used` terhadap `$total` (0–100, satu desimal). `null` bila
     * `$total` tidak positif.
     */
    public static function percent(int $used, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round(min(100.0, max(0.0, $used / $total * 100)), 1);
    }

    /**
     * Sampel baris `cpu` dari `/proc/stat`: total jiffies & jiffies idle.
     *
     * Field 0..7 = user nice system idle iowait irq softirq steal; `guest` dan
     * `guest_nice` diabaikan karena sudah termasuk di `user`/`nice`.
     *
     * @return array{total:int,idle:int}|null null bila file tidak terbaca
     */
    public static function cpuSample(string $procPath = '/proc'): ?array
    {
        $raw = self::read(self::path($procPath, 'stat'));
        if ($raw === null) {
            return null;
        }

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (!str_starts_with($line, 'cpu ')) {
                continue;
            }
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            array_shift($fields); // buang label "cpu"
            if (count($fields) < 4) {
                return null;
            }

            $total = 0;
            $idle = 0;
            foreach ($fields as $i => $value) {
                if ($i > 7) {
                    break;
                }
                $total += (int) $value;
                // idle + iowait dianggap menganggur (konvensi top/htop)
                if ($i === 3 || $i === 4) {
                    $idle += (int) $value;
                }
            }

            return ['total' => $total, 'idle' => $idle];
        }

        return null;
    }

    /**
     * Persentase pemakaian CPU antara dua sampel (0–100, satu desimal).
     * `null` bila salah satu sampel tidak ada atau jendela waktunya kosong.
     *
     * @param array{total:int,idle:int}|null $before
     * @param array{total:int,idle:int}|null $after
     */
    public static function percentBetween(?array $before, ?array $after): ?float
    {
        if ($before === null || $after === null) {
            return null;
        }
        $total = $after['total'] - $before['total'];
        $idle = $after['idle'] - $before['idle'];
        if ($total <= 0) {
            return null;
        }

        $busy = max(0, $total - $idle);
        return round(min(100.0, $busy / $total * 100), 1);
    }

    /**
     * Pemakaian memori host dari `/proc/meminfo`.
     *
     * `MemAvailable` (bukan `MemFree`) dipakai untuk menghitung terpakai karena
     * sudah memperhitungkan page cache yang bisa dibebaskan — sama seperti `free`.
     *
     * @return array{total:int,used:int,available:int,percent:float}|null
     */
    public static function memory(string $procPath = '/proc'): ?array
    {
        $raw = self::read(self::path($procPath, 'meminfo'));
        if ($raw === null) {
            return null;
        }

        $values = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^(MemTotal|MemFree|MemAvailable):\s+(\d+)\s*kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2] * 1024;
            }
        }

        $total = $values['MemTotal'] ?? 0;
        if ($total <= 0) {
            return null;
        }
        $available = $values['MemAvailable'] ?? $values['MemFree'] ?? 0;
        $available = min($available, $total);
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percent' => round($used / $total * 100, 1),
        ];
    }

    /**
     * Rata-rata beban sistem 1/5/15 menit dari `/proc/loadavg`.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public static function load(string $procPath = '/proc'): ?array
    {
        $raw = self::read(self::path($procPath, 'loadavg'));
        if ($raw === null) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($raw)) ?: [];
        if (count($fields) < 3) {
            return null;
        }

        return [(float) $fields[0], (float) $fields[1], (float) $fields[2]];
    }

    /**
     * Uptime host (detik) dari `/proc/uptime` (field pertama, pecahan detik).
     */
    public static function uptimeSeconds(string $procPath = '/proc'): ?int
    {
        $raw = self::read(self::path($procPath, 'uptime'));
        if ($raw === null) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($raw)) ?: [];
        if ($fields === [] || !is_numeric($fields[0])) {
            return null;
        }

        return (int) floor((float) $fields[0]);
    }

    /**
     * Jumlah CPU yang terlihat host (`/proc/cpuinfo`), atau null bila tidak ada.
     */
    public static function cpuCount(string $procPath = '/proc'): ?int
    {
        $raw = self::read(self::path($procPath, 'cpuinfo'));
        if ($raw === null) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/mi', $raw);

        return $count > 0 ? $count : null;
    }

    private static function path(string $procPath, string $file): string
    {
        return rtrim($procPath, '/') . '/' . $file;
    }

    /**
     * Baca file `/proc`; null bila tidak ada/tidak bisa dibaca — bukan error,
     * karena metrik host bersifat opsional (UI menampilkan N/A).
     */
    private static function read(string $file): ?string
    {
        if (!is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file);

        return is_string($raw) ? $raw : null;
    }
}
