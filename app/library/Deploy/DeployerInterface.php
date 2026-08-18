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
     * Mengembalikan app yang sudah diperbarui (containers + status).
     *
     * @param array        $app
     * @param callable     $logger callable(string $stage, string $message): void
     * @return array
     */
    public function deploy(array $app, callable $logger): array;

    /**
     * Rebuild: pull ulang repo, lalu up -d --build.
     *
     * @param array    $app
     * @param callable $logger callable(string $stage, string $message): void
     * @return array
     */
    public function rebuild(array $app, callable $logger): array;

    /**
     * Rollback app ke versi (ref git) yang pernah sukses.
     *
     * - Checkout source ke ref lama, lalu up -d --build, collect container,
     *   tulis ulang config Nginx.
     * - Ref diambil dari remote (repo shallow) sebelum checkout.
     * - Bila build versi lama GAGAL, otomatis kembali ke versi yang tadinya
     *   aktif (prevRef) — restore best-effort. Jika restore juga gagal, lempar
     *   exception (worker akan menandai status error).
     * - Mengembalikan app yang sudah diperbarui (containers + status +
     *   deploy_history).
     *
     * @param array    $app
     * @param string   $ref  SHA commit target rollback (full SHA)
     * @param callable $logger callable(string $stage, string $message): void
     * @return array
     */
    public function rollback(array $app, string $ref, callable $logger): array;

    public function stop(array $app): void;

    public function start(array $app): void;

    /**
     * Terapkan perubahan environment variable app TANPA rebuild source:
     * tulis ulang managed env file + override env, lalu `docker compose up -d`
     * (tanpa --build) — hanya container yang environment-nya berubah yang
     * diciptakan ulang. Mengembalikan app yang diperbarui (containers).
     *
     * @param array    $app
     * @param callable $logger callable(string $stage, string $message): void
     * @return array
     */
    public function applyEnv(array $app, callable $logger): array;

    /**
     * Teardown: down container + hapus config Nginx.
     *
     * Bila $preserveVolumes === null → `down -v` (hapus SEMUA volume termasuk
     * anonymous — jalur "hapus total"). Bila array → `down` tanpa -v lalu hapus
     * hanya volume project yang TIDAK ada di $preserveVolumes. Named volume yang
     * dipertahankan akan dipakai ulang otomatis saat app dibuat ulang dengan
     * nama yang sama (project name compose = nama app).
     *
     * @param array       $app
     * @param array|null  $preserveVolumes daftar nama volume yang dipertahankan, atau null = hapus semua
     */
    public function teardown(array $app, ?array $preserveVolumes = null): void;

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
     * Konten config Nginx untuk app.
     */
    public function renderNginxConfig(array $app): string;

    public function writeNginxConfig(array $app): void;

    public function removeNginxConfig(array $app): void;
}
