<?php
declare(strict_types=1);

namespace app\library\Update;

use app\library\Agent\AgentError;

/**
 * Gerbang **versi & kapabilitas** antara control plane (dashboard) dan server
 * `kind=agent` (multi-server F4 lanjutan).
 *
 * Latar: satu kode, satu image, dua mode runtime (plan §10.1) — tetapi sebuah
 * agen bisa tertinggal beberapa rilis. Dua arah ketidakcocokan yang harus
 * ditolak **sebelum efek samping apa pun**:
 *
 *  1. **control plane terlalu tua untuk agen** — agen melaporkan
 *     `min_control_plane_version` di `GET /version`
 *     ({@see \app\controller\AgentController::version()}); bila dashboard ini
 *     lebih tua dari nilai itu, agen tidak boleh digerakkan (dan tidak boleh
 *     di-self-update: memperbaruinya hanya memperparah ketidakcocokan);
 *  2. **agen terlalu tua untuk control plane** — di bawah
 *     {@see self::MIN_AGENT_VERSION}; agen setua itu belum tentu punya op yang
 *     dibutuhkan, jadi seluruh op ditolak dengan pesan yang menyuruh memperbarui
 *     agen.
 *
 * Kapabilitas lebih diutamakan daripada string versi (kontrak misi): bila agen
 * sudah pernah melaporkan `capabilities` (hasil Test connection) dan op yang
 * diminta **tidak ada** di daftar itu, op ditolak `unsupported` tanpa menebak
 * dari nomor versi. Sebaliknya, agen yang **belum pernah** melaporkan apa pun
 * (capabilities/versi kosong) tidak ditolak di sini — itu kondisi "belum diuji",
 * dilaporkan sebagai *peringatan*, dan keputusan akhirnya ada di agen
 * (allowlist `AgentJobController::OPS`), bukan di tebakan control plane.
 *
 * Kelas ini statis murni (tanpa I/O, tanpa jaringan) ⇒ aman dipanggil di worker
 * Webman persistent dan dipakai bersama oleh `AgentDeployer` (op app),
 * `ServerController` (status + aksi update agen), dan `cli/deploy.php`
 * (pemeriksaan sisi agen).
 */
final class AgentVersionGate
{
    /**
     * Versi kode ini (dilaporkan agen sebagai `agent_version`; juga versi
     * control plane). **Satu-satunya** tempat angka versi ditulis untuk kedua
     * peran — `AgentController` merujuk konstanta ini, jangan menyalinnya.
     */
    public const CODEBASE_VERSION = '0.1.0';

    /**
     * Versi agen **tertua** yang masih boleh digerakkan control plane ini.
     * Di bawah nilai ini ⇒ semua op ditolak dengan pesan "perbarui agen".
     */
    public const MIN_AGENT_VERSION = '0.1.0';

    /**
     * Versi control plane **tertua** yang diterima agen ini (= nilai
     * `min_control_plane_version` yang dilaporkan `GET /version`). Dipakai sisi
     * agen untuk menolak perintah dari dashboard yang terlalu tua — termasuk
     * permintaan self-update.
     */
    public const MIN_CONTROL_PLANE_VERSION = '0.1.0';

    /**
     * Capabilities yang dilaporkan agen (dedupe, urutan apa adanya). Daftar
     * kosong = agen belum pernah melaporkan (Test connection belum dijalankan
     * atau gagal).
     *
     * @param array<string,mixed> $server
     * @return array<int,string>
     */
    public static function capabilities(array $server): array
    {
        $out = [];
        foreach ((array) ($server['capabilities'] ?? []) as $capability) {
            if (!is_scalar($capability)) {
                continue;
            }
            $capability = trim((string) $capability);
            if ($capability !== '') {
                $out[$capability] = $capability;
            }
        }

        return array_values($out);
    }

    /**
     * Apakah agen (menurut capabilitas yang dilaporkannya) mendukung op ini?
     *
     * `true` bila op kosong, bukan server `kind=agent`, atau capabilities belum
     * diketahui — keputusan akhir di agen. `false` hanya bila capabilities
     * **sudah** dilaporkan dan op tidak ada di dalamnya.
     *
     * @param array<string,mixed> $server
     */
    public static function supports(array $server, string $op): bool
    {
        $op = trim($op);
        if ($op === '' || !self::isAgent($server)) {
            return true;
        }

        $capabilities = self::capabilities($server);
        if ($capabilities === []) {
            return true;
        }

        return in_array($op, $capabilities, true);
    }

    /**
     * Versi (string) memenuhi minimum? Versi kosong = tidak diketahui ⇒
     * `true` (jangan menolak berdasarkan data yang tidak ada).
     */
    public static function atLeast(string $version, string $minimum): bool
    {
        $version = trim($version);
        $minimum = trim($minimum);
        if ($version === '' || $minimum === '') {
            return true;
        }

        return version_compare($version, $minimum, '>=');
    }

    /**
     * Ringkasan kompatibilitas satu server — dipakai UI (halaman Servers) dan
     * sebagai sumber tunggal pesan galat `assertCompatible()`.
     *
     * - `errors` = penghalang nyata (versi agen terlalu tua, control plane
     *   terlalu tua, atau op yang diminta tidak ada di capabilities);
     * - `warnings` = data belum lengkap (versi/capabilities belum pernah
     *   dilaporkan ⇒ jalankan Test connection).
     *
     * @param array<string,mixed> $server
     * @param string              $op     op yang akan dijalankan ('' = hanya cek versi)
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,agent_version:string,min_control_plane_version:string,min_agent_version:string,control_plane_version:string,capabilities:array<int,string>}
     */
    public static function check(array $server, string $op = ''): array
    {
        $errors = [];
        $warnings = [];

        $agentVersion = trim((string) ($server['agent_version'] ?? ''));
        $minControlPlane = trim((string) ($server['min_control_plane_version'] ?? ''));
        $capabilities = self::capabilities($server);

        if (self::isAgent($server)) {
            $label = self::label($server);

            if ($agentVersion === '') {
                $warnings[] = $label . ': versi agen belum diketahui — jalankan Test connection di halaman Servers.';
            } elseif (!self::atLeast($agentVersion, self::MIN_AGENT_VERSION)) {
                $errors[] = $label . ': versi agen "' . $agentVersion . '" lebih tua dari minimum yang didukung dashboard ini ('
                    . self::MIN_AGENT_VERSION . ') — perbarui agen di server itu (halaman Servers → Perbarui agen) sebelum '
                    . 'menjalankan operasi apa pun.';
            }

            if ($minControlPlane === '') {
                $warnings[] = $label . ': versi control plane minimum yang diminta agen belum diketahui — jalankan Test connection.';
            } elseif (!self::atLeast(self::CODEBASE_VERSION, $minControlPlane)) {
                $errors[] = $label . ': agen ini butuh control plane ≥ "' . $minControlPlane . '" sedangkan dashboard ini "'
                    . self::CODEBASE_VERSION . '" — perbarui dashboard lebih dulu (halaman Config → tab Update).';
            }

            if ($capabilities === []) {
                $warnings[] = $label . ': capabilities agen belum dilaporkan — jalankan Test connection agar dukungan op bisa dipastikan.';
            }

            $op = trim($op);
            if ($op !== '' && !self::supports($server, $op)) {
                $errors[] = $label . ': op "' . $op . '" tidak ada di capabilities yang dilaporkan agen — perbarui agen di '
                    . 'server itu, lalu jalankan Test connection. Operasi dibatalkan (tidak pernah dijalankan di server lokal).';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'agent_version' => $agentVersion,
            'min_control_plane_version' => $minControlPlane,
            'min_agent_version' => self::MIN_AGENT_VERSION,
            'control_plane_version' => self::CODEBASE_VERSION,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Fail-fast: server agent tidak kompatibel untuk op tersebut ⇒
     * {@see AgentError} `unsupported` dengan pesan yang bisa langsung ditampilkan
     * (tanpa token/`base_url`). Server lokal selalu lolos (tidak ada versi agen).
     *
     * @param array<string,mixed> $server
     * @throws AgentError kompatibilitas/dukungan op tidak terpenuhi
     */
    public static function assertCompatible(array $server, string $op = ''): void
    {
        $report = self::check($server, $op);
        if ($report['ok']) {
            return;
        }

        throw AgentError::unsupported(implode(' ', $report['errors']));
    }

    /**
     * Sisi **agen**: tolak perintah dari control plane yang terlalu tua
     * (dipakai jalur self-update agen — jangan sampai agen diperbarui oleh
     * dashboard yang tidak lagi kompatibel dengannya).
     *
     * Versi kosong (klien lama yang tidak mengirimkannya) ⇒ **ditolak** dengan
     * pesan jelas, bukan dilewatkan: tanpa itu kontrol kompatibilitas hilang
     * begitu saja.
     *
     * @throws AgentError `unsupported` bila versi control plane tidak memenuhi
     */
    public static function assertControlPlaneCompatibleWithAgent(string $controlPlaneVersion): void
    {
        $controlPlaneVersion = trim($controlPlaneVersion);
        // Versi kosong = klien lama yang tidak melaporkan versi ⇒ tetap ditolak
        // (lihat catatan method) — bukan dilewatkan.
        if ($controlPlaneVersion !== '' && self::atLeast($controlPlaneVersion, self::MIN_CONTROL_PLANE_VERSION)) {
            return;
        }

        $shown = $controlPlaneVersion === '' ? '(tidak dilaporkan)' : $controlPlaneVersion;
        throw AgentError::unsupported(
            'Control plane versi ' . $shown . ' lebih tua dari minimum yang didukung agen ini ('
            . self::MIN_CONTROL_PLANE_VERSION . ') — operasi dibatalkan di agen. Perbarui dashboard lebih dulu '
            . '(halaman Config → tab Update).'
        );
    }

    /**
     * Label server untuk pesan galat (nama + id, **tanpa** `base_url`/token) —
     * pola yang sama dengan `DeployerFactory::label()`.
     *
     * @param array<string,mixed> $server
     */
    public static function label(array $server): string
    {
        $name = trim((string) ($server['name'] ?? ''));
        $id = trim((string) ($server['id'] ?? ''));
        if ($name !== '' && $id !== '') {
            return "Server \"{$name}\" ({$id})";
        }
        if ($name !== '') {
            return "Server \"{$name}\"";
        }

        return $id !== '' ? "Server ({$id})" : 'Server tanpa id';
    }

    /**
     * Server ini `kind=agent`? Server lokal tidak punya versi/capabilitas agen,
     * jadi selalu lolos gerbang.
     *
     * @param array<string,mixed> $server
     */
    private static function isAgent(array $server): bool
    {
        return strtolower(trim((string) ($server['kind'] ?? ''))) === 'agent';
    }
}
