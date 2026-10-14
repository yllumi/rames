<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Update\AgentVersionGate;
use Webman\Http\Response;

/**
 * Route diagnostik server agent (mode runtime `RAMES_MODE=agent`, plan §4.1).
 *
 * Peran: **mediator tipis**. Tidak ada logika bisnis, tidak ada state properti,
 * tidak menyentuh session/request, dan seluruh respons lewat helper `json()`.
 *
 * Route ini hanya terdaftar di `config/route.php` bila:
 *   1. `config('deploy.mode') === 'agent'`, dan
 *   2. `config('deploy.agent_token')` terisi (fail-closed; tanpa token semua 404).
 *
 * Keamanan yang dijaga di sini: respons **tidak boleh** memuat apa pun yang
 * sensitif — token, path, hostname, daftar app, atau isi store. Yang dipulangkan
 * hanya identitas versi + kapabilitas. Penegakan token/nonce (dan TLS pinning di
 * sisi pemanggil) menyusul di misi *Auth & Security*; di misi ini route agent
 * sengaja tidak memasang middleware sesi/CSRF apa pun.
 *
 * `capabilities` diisi op yang benar-benar didukung agent (F1: job deploy async —
 * satu sumber dengan allowlist `AgentJobController::OPS` / mode `cli/deploy.php`),
 * sehingga control plane bisa menolak op di luar daftar itu alih-alih diam-diam
 * menganggapnya tidak ada.
 */
class AgentController
{
    /**
     * Versi agent — **satu-satunya sumber** untuk `health()` dan `version()`
     * (dipakai control plane sebagai `servers.agent_version`).
     *
     * Nilai & aturan kompatibilitasnya ada di {@see AgentVersionGate} (dipakai
     * bersama control plane: `AgentDeployer`, `ServerController`, `cli/deploy.php`)
     * supaya angka versi tidak pernah ditulis di dua tempat.
     */
    public const VERSION = AgentVersionGate::CODEBASE_VERSION;

    /**
     * Versi control plane minimum yang boleh mengendalikan agent ini
     * (`servers.min_control_plane_version` di sisi dashboard) — dilaporkan
     * `GET /version` dan **disimpan per server** oleh Test connection
     * (`ServerController`). Control plane yang lebih tua akan ditolak gerbang
     * versi sebelum menjalankan op apa pun.
     */
    public const MIN_CONTROL_PLANE_VERSION = AgentVersionGate::MIN_CONTROL_PLANE_VERSION;

    /** Nama layanan pada laporan kesehatan (pembeda dari dashboard `rames`). */
    public const SERVICE = 'rames-agent';

    /**
     * `GET /healthz` — laporan kesehatan agent (dipakai "Test connection" di
     * dashboard + monitoring eksternal). HTTP 200, tanpa data sensitif.
     */
    public function health(): Response
    {
        return json([
            'ok' => true,
            'service' => self::SERVICE,
            // Route ini hanya ada di mode agent, jadi mode-nya faktual.
            'mode' => 'agent',
            'version' => self::VERSION,
        ]);
    }

    /**
     * `GET /version` — negosiasi versi & kapabilitas untuk control plane.
     *
     * `capabilities` = op yang **benar-benar** didukung agent ini
     * ({@see AgentJobController::OPS} → {@see \app\library\Deploy\DeployWorkerLauncher::MODES},
     * yaitu mode `cli/deploy.php` yang ada). Satu sumber dengan validasi
     * `POST /jobs`: daftar yang dilaporkan tidak bisa menyimpang dari yang
     * diterima. Control plane menolak (bukan mendiamkan) op di luar daftar ini
     * lewat {@see \app\library\Update\AgentVersionGate::assertCompatible()} —
     * daftar ini **lebih diutamakan** daripada nomor versi saat memutuskan
     * dukungan sebuah op.
     */
    public function version(): Response
    {
        return json([
            'agent_version' => self::VERSION,
            'capabilities' => AgentJobController::OPS,
            'min_control_plane_version' => self::MIN_CONTROL_PLANE_VERSION,
        ]);
    }
}
