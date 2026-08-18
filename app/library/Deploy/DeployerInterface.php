<?php
declare(strict_types=1);

namespace app\library\Deploy;

/**
 * Abstraksi eksekusi deployment (SPECS.md §5).
 *
 * Implementasi lokal (LocalDeployer) mengeksekusi docker compose di mesin yang
 * sama; di fase berikutnya bisa diganti implementasi yang memanggil agent HTTP
 * (multi-server) tanpa mengubah controller/business logic dashboard.
 */
interface DeployerInterface
{
    /**
     * Build & jalankan container, collect info container, tulis config Nginx.
     * Mengembalikan site yang sudah diperbarui (containers + status).
     *
     * @param array        $site
     * @param callable     $logger callable(string $stage, string $message): void
     * @return array
     */
    public function deploy(array $site, callable $logger): array;

    /**
     * Rebuild: pull ulang repo, lalu up -d --build.
     *
     * @param array    $site
     * @param callable $logger callable(string $stage, string $message): void
     * @return array
     */
    public function rebuild(array $site, callable $logger): array;

    /**
     * Rollback site ke versi (ref git) yang pernah sukses.
     *
     * - Checkout source ke ref lama, lalu up -d --build, collect container,
     *   tulis ulang config Nginx.
     * - Ref diambil dari remote (repo shallow) sebelum checkout.
     * - Bila build versi lama GAGAL, otomatis kembali ke versi yang tadinya
     *   aktif (prevRef) — restore best-effort. Jika restore juga gagal, lempar
     *   exception (worker akan menandai status error).
     * - Mengembalikan site yang sudah diperbarui (containers + status +
     *   deploy_history).
     *
     * @param array    $site
     * @param string   $ref  SHA commit target rollback (full SHA)
     * @param callable $logger callable(string $stage, string $message): void
     * @return array
     */
    public function rollback(array $site, string $ref, callable $logger): array;

    public function stop(array $site): void;

    public function start(array $site): void;

    /**
     * Teardown: down container + hapus config Nginx.
     *
     * Bila $preserveVolumes === null → `down -v` (hapus SEMUA volume termasuk
     * anonymous — jalur "hapus total"). Bila array → `down` tanpa -v lalu hapus
     * hanya volume project yang TIDAK ada di $preserveVolumes. Named volume yang
     * dipertahankan akan dipakai ulang otomatis saat site dibuat ulang dengan
     * nama yang sama (project name compose = nama site).
     *
     * @param array       $site
     * @param array|null  $preserveVolumes daftar nama volume yang dipertahankan, atau null = hapus semua
     */
    public function teardown(array $site, ?array $preserveVolumes = null): void;

    /**
     * Nama-nama named volume milik project compose (untuk UI konfirmasi delete).
     *
     * @return array<int,string>
     */
    public function getProjectVolumes(string $project): array;

    /**
     * Info container project (nama, image, status, port).
     *
     * @return array<int,array{service_name:string,container_name:string,image:string,internal_port:?int,host_port:?int,status:string}>
     */
    public function getContainers(string $project): array;

    /**
     * Fail-fast: pastikan prasyarat tulis config Nginx terpenuhi.
     * Melempar RuntimeException dengan pesan jelas jika tidak.
     */
    public function ensureWritable(): void;

    /**
     * Konten config Nginx untuk site.
     */
    public function renderNginxConfig(array $site): string;

    public function writeNginxConfig(array $site): void;

    public function removeNginxConfig(array $site): void;
}
