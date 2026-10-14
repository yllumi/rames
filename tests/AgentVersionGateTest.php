<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentController;
use app\controller\AgentJobController;
use app\library\Agent\AgentError;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Update\AgentVersionGate;
use PHPUnit\Framework\TestCase;

/**
 * Gerbang versi & kapabilitas control plane ↔ agen (`AgentVersionGate`,
 * multi-server F4 lanjutan).
 *
 * Kontrak (kontrak misi butir 3):
 *  - **capabilities diutamakan**, bukan nomor versi: op yang tidak ada di
 *    `capabilities` yang dilaporkan agen ditolak `unsupported` dengan pesan
 *    jelas — dan agen yang **belum pernah** melaporkan apa pun tidak ditolak
 *    (keputusan akhir di agen), hanya diberi peringatan;
 *  - agen di bawah `MIN_AGENT_VERSION` ⇒ ditolak (tidak bisa digerakkan);
 *  - agen yang menuntut control plane lebih baru dari dashboard ini
 *    (`min_control_plane_version`) ⇒ ditolak;
 *  - sisi agen: perintah dari control plane yang terlalu tua ditolak, termasuk
 *    (atau justru khusus) permintaan self-update;
 *  - pesan galat tidak pernah memuat token/`base_url`.
 *
 * Kelas ini statis murni: tanpa I/O, tanpa jaringan, tanpa store.
 */
class AgentVersionGateTest extends TestCase
{
    private const TOKEN = 'agt_rahasia_jangan_bocor_1a2b';

    // ------------------------------------------------------------------
    // (iv) kapabilitas diutamakan
    // ------------------------------------------------------------------

    public function testOpOutsideCapabilitiesIsRejectedWithClearMessage(): void
    {
        $server = $this->agent(['capabilities' => ['deploy', 'stop']]);

        $this->assertFalse(AgentVersionGate::supports($server, 'template_pull'));

        $report = AgentVersionGate::check($server, 'template_pull');
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('template_pull', $report['errors'][0]);
        $this->assertStringContainsString('capabilities', $report['errors'][0]);

        try {
            AgentVersionGate::assertCompatible($server, 'template_pull');
            $this->fail('op di luar capabilities wajib ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNSUPPORTED, $e->reason);
            $this->assertStringContainsString('template_pull', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString('internal.test', $e->getMessage(), 'base_url tidak boleh ikut');
        }
    }

    public function testOpInsideCapabilitiesIsAccepted(): void
    {
        $server = $this->agent(['capabilities' => ['deploy', 'template_pull', 'self_update']]);

        $this->assertTrue(AgentVersionGate::supports($server, 'template_pull'));
        $this->assertTrue(AgentVersionGate::check($server, 'self_update')['ok']);
        AgentVersionGate::assertCompatible($server, 'template_pull');
    }

    /** Agen yang belum pernah melaporkan capabilities: peringatan, bukan tolak. */
    public function testUnknownCapabilitiesIsAWarningNotARejection(): void
    {
        $server = $this->agent(['capabilities' => [], 'agent_version' => '', 'min_control_plane_version' => '']);

        $this->assertTrue(AgentVersionGate::supports($server, 'template_pull'));

        $report = AgentVersionGate::check($server, 'template_pull');
        $this->assertTrue($report['ok'], 'belum pernah diuji bukan alasan menolak');
        $this->assertCount(3, $report['warnings']);
        $this->assertStringContainsString('Test connection', $report['warnings'][0]);

        AgentVersionGate::assertCompatible($server, 'template_pull');
    }

    // ------------------------------------------------------------------
    // (v) versi minimum
    // ------------------------------------------------------------------

    public function testAgentBelowMinimumVersionIsRejected(): void
    {
        $server = $this->agent([
            'agent_version' => '0.0.9',
            'min_control_plane_version' => AgentVersionGate::MIN_CONTROL_PLANE_VERSION,
        ]);

        $report = AgentVersionGate::check($server);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString(AgentVersionGate::MIN_AGENT_VERSION, $report['errors'][0]);
        $this->assertStringContainsString('0.0.9', $report['errors'][0]);

        $this->expectException(AgentError::class);
        AgentVersionGate::assertCompatible($server);
    }

    public function testAgentRequiringNewerControlPlaneIsRejected(): void
    {
        $server = $this->agent(['min_control_plane_version' => '9.9.9']);

        $report = AgentVersionGate::check($server);
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('9.9.9', $report['errors'][0]);
        $this->assertStringContainsString('Config', $report['errors'][0], 'pesan harus menunjuk cara memperbarui dashboard');
    }

    public function testServerLocalAlwaysPasses(): void
    {
        $server = ['id' => 'local', 'name' => 'lokal', 'kind' => 'local', 'capabilities' => [], 'agent_version' => ''];

        $report = AgentVersionGate::check($server, 'template_pull');
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['warnings'], 'server lokal tidak punya versi agen untuk diperingatkan');
        AgentVersionGate::assertCompatible($server, 'template_pull');
    }

    public function testVersionComparison(): void
    {
        $this->assertTrue(AgentVersionGate::atLeast('0.1.0', '0.1.0'));
        $this->assertTrue(AgentVersionGate::atLeast('0.2', '0.1.99'));
        $this->assertFalse(AgentVersionGate::atLeast('0.0.9', '0.1.0'));
        $this->assertTrue(AgentVersionGate::atLeast('', '0.1.0'), 'versi kosong = tidak diketahui ⇒ jangan menolak');
        $this->assertTrue(AgentVersionGate::atLeast('1.0.0', ''));
    }

    // ------------------------------------------------------------------
    // Sisi agen: tolak control plane yang terlalu tua (termasuk self-update)
    // ------------------------------------------------------------------

    public function testAgentSideRefusesOlderControlPlane(): void
    {
        AgentVersionGate::assertControlPlaneCompatibleWithAgent(AgentVersionGate::CODEBASE_VERSION);
        AgentVersionGate::assertControlPlaneCompatibleWithAgent('99.0.0');

        try {
            AgentVersionGate::assertControlPlaneCompatibleWithAgent('0.0.1');
            $this->fail('control plane terlalu tua wajib ditolak agen');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNSUPPORTED, $e->reason);
            $this->assertStringContainsString('0.0.1', $e->getMessage());
            $this->assertStringContainsString(AgentVersionGate::MIN_CONTROL_PLANE_VERSION, $e->getMessage());
        }
    }

    /** Versi yang tidak dilaporkan (klien lama) juga ditolak — bukan dilewatkan. */
    public function testAgentSideRefusesMissingControlPlaneVersion(): void
    {
        try {
            AgentVersionGate::assertControlPlaneCompatibleWithAgent('');
            $this->fail('versi control plane yang tidak dilaporkan wajib ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::UNSUPPORTED, $e->reason);
            $this->assertStringContainsString('tidak dilaporkan', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // (vii) tidak ada op "senyap": MODES ≡ OPS ≡ cli/deploy.php ≡ capabilities
    // ------------------------------------------------------------------

    public function testEveryModeIsARealOpWithReachableBranch(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/cli/deploy.php');

        // capabilities yang dilaporkan agen = allowlist op job = MODES.
        $this->assertSame(DeployWorkerLauncher::MODES, AgentJobController::OPS);
        $version = json_decode((new AgentController())->version()->rawBody(), true);
        $this->assertSame(DeployWorkerLauncher::MODES, $version['capabilities'] ?? null);

        foreach (DeployWorkerLauncher::MODES as $mode) {
            // Setiap mode wajib muncul ≥2 kali di `cli/deploy.php`: sekali di daftar
            // mode, sekali lagi di cabang eksekusinya (match/daftar op per grup).
            $occurrences = substr_count($source, "'" . $mode . "'");
            $this->assertGreaterThanOrEqual(
                2,
                $occurrences,
                "mode `{$mode}` dideklarasikan tetapi cabang eksekusinya tidak ditemukan di cli/deploy.php"
            );
        }

        // Server op (F4 lanjutan) wajib merupakan bagian dari daftar mode.
        foreach (DeployWorkerLauncher::SERVER_OPS as $op) {
            $this->assertContains($op, DeployWorkerLauncher::MODES, 'op tingkat server wajib jadi mode nyata');
        }
        $this->assertContains(DeployWorkerLauncher::AGENT_UPDATE_OP, DeployWorkerLauncher::SERVER_OPS);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function agent(array $overrides = []): array
    {
        return array_merge([
            'id' => 'vps-1',
            'name' => 'vps-1',
            'kind' => 'agent',
            'base_url' => 'https://vps-1.internal.test:8443',
            'token' => self::TOKEN,
            'capabilities' => DeployWorkerLauncher::MODES,
            'agent_version' => AgentVersionGate::CODEBASE_VERSION,
            'min_control_plane_version' => AgentVersionGate::MIN_CONTROL_PLANE_VERSION,
        ], $overrides);
    }
}
