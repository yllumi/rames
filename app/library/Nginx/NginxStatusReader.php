<?php
declare(strict_types=1);

namespace app\library\Nginx;

/**
 * Membaca status reload Nginx terakhir yang ditulis watcher di host
 * (SPECS.md §8.3 butir 3) — untuk feedback di UI.
 */
class NginxStatusReader
{
    public function __construct(
        private readonly string $statusFile,
    ) {
    }

    /**
     * @return array{ok:bool, error?:string, updated_at?:string}|null
     */
    public function lastReload(): ?array
    {
        if (!is_file($this->statusFile)) {
            return null;
        }
        $raw = file_get_contents($this->statusFile);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
