<?php
declare(strict_types=1);

namespace app\library\Update;

/**
 * Kontrak minimal **self-update** yang dipakai jalur op tingkat server
 * `self_update` di `cli/deploy.php` (multi-server F4 lanjutan).
 *
 * Implementasi produksi: {@see UpdateService} (helper container di luar compose
 * project — lihat catatan kelasnya). Kontrak ini ada supaya:
 *
 *  - worker di agen bergantung pada bentuk yang sempit (preflight + start),
 *    bukan pada seluruh permukaan `UpdateService`;
 *  - uji dapat menyuntikkan implementasi palsu lewat env
 *    `RAMES_SELF_UPDATE_CLASS` (pola yang sama dengan `DEPLOYER_CLASS` /
 *    `RAMES_BACKUP_RUNNER_CLASS`) sehingga **tidak pernah** ada helper container
 *    atau `docker` nyata yang dijalankan saat test.
 *
 * Tidak ada kredensial di kontrak ini: `start()` hanya menerima mode
 * (`update`/`rollback`) dan nama aktor untuk jejak audit.
 */
interface UpdateRunner
{
    /**
     * Pemeriksaan prasyarat (tanpa efek samping) — bentuknya sama dengan
     * {@see UpdateService::preflight()}.
     *
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function preflight(): array;

    /**
     * Jalankan update (detached di sisi implementasi produksi) dan kembalikan
     * ringkasan run (`id`, `container`, `mode`, `old_sha`, `target_sha`, `log`).
     *
     * @return array<string,mixed>
     */
    public function start(string $mode, string $actor): array;
}
