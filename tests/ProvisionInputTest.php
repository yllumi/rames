<?php
declare(strict_types=1);

namespace Tests;

use app\library\Provision\ProvisionInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test {@see ProvisionInput} (lapisan data & validasi provisioning Ansible).
 *
 * Kelas ini statik murni tanpa I/O — tidak ada basis data/berkas yang disentuh,
 * jadi tidak perlu temp dir. Yang dikunci:
 *  - default terisi (port, bind, playbook, manage_firewall);
 *  - host/domain/email/port/name invalid ditolak dengan pesan menyebut field;
 *  - `force`/`--force` truthy ditolak (tidak boleh regenerasi token/TLS);
 *  - `extraVars()` hanya mengeluarkan allowlist `rames_agent_*`, dan
 *    `rames_agent_run_installer` benar per playbook;
 *  - `control_plane_ips` diparse dari string koma/spasi/newline.
 */
class ProvisionInputTest extends TestCase
{
    /**
     * Payload minimal yang sah (field wajib saja + rahasia yang harus diabaikan).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'agent-01',
            'host' => '10.0.0.5',
            'ssh_user' => 'root',
            'domain' => 'agent-01.example.com',
            'email' => 'ops@example.com',
            // Wajib saat `manage_firewall` aktif (bawaan) — port agent hanya
            // boleh terbuka untuk IP control plane.
            'control_plane_ips' => '203.0.113.7',
            // Rahasia yang TIDAK boleh ikut ke hasil normalisasi/extra vars.
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----",
            'token' => 'rahasia-jangan-bocor',
        ], $overrides);
    }

    public function testValidateMinimalFillsDefaults(): void
    {
        $input = ProvisionInput::validate($this->payload());

        $this->assertSame('agent-01', $input['name']);
        $this->assertSame('10.0.0.5', $input['host']);
        $this->assertSame('root', $input['ssh_user']);
        $this->assertSame(22, $input['ssh_port']);
        $this->assertSame('agent-01.example.com', $input['domain']);
        $this->assertSame('ops@example.com', $input['email']);
        $this->assertSame(30000, $input['port_range_start']);
        $this->assertSame(30999, $input['port_range_end']);
        $this->assertSame(8443, $input['agent_port']);
        $this->assertSame('0.0.0.0:8443', $input['bind']);
        $this->assertSame('', $input['ip']);
        $this->assertSame('', $input['ssl_challenge']);
        $this->assertSame('', $input['ssl_ca_server']);
        $this->assertSame(['203.0.113.7'], $input['control_plane_ips']);
        $this->assertTrue($input['manage_firewall']);
        $this->assertFalse($input['allow_identity_change'], 'bawaan: identitas tidak boleh berubah');
        $this->assertSame('provision-agent', $input['playbook']);

        // Hanya field yang dikenal; rahasia tidak pernah ikut.
        $this->assertSame([
            'name', 'host', 'ssh_user', 'ssh_port', 'domain', 'email',
            'port_range_start', 'port_range_end', 'agent_port', 'bind', 'ip',
            'ssl_challenge', 'ssl_ca_server', 'control_plane_ips',
            'manage_firewall', 'allow_identity_change', 'playbook',
        ], array_keys($input));
        $this->assertArrayNotHasKey('ssh_private_key', $input);
        $this->assertArrayNotHasKey('token', $input);
    }

    public function testValidateNormalizesCaseAndExplicitValues(): void
    {
        $input = ProvisionInput::validate($this->payload([
            'name' => '  Agent-01  ',
            'host' => 'Agent-01.Example.COM',
            'ssh_user' => 'DEPLOY',
            'ssh_port' => '2222',
            'domain' => 'Agent-01.Example.com',
            'port_range_start' => '31000',
            'port_range_end' => '31999',
            'agent_port' => 8443,
            'bind' => '10.0.0.5:8443',
            'ip' => '10.0.0.5',
            'ssl_challenge' => 'DNS-Cloudflare',
            'ssl_ca_server' => 'staging',
            'manage_firewall' => 'off',
            'playbook' => 'verify-agent',
        ]));

        $this->assertSame('agent-01', $input['name']);
        $this->assertSame('agent-01.example.com', $input['host']);
        $this->assertSame('deploy', $input['ssh_user']);
        $this->assertSame(2222, $input['ssh_port']);
        $this->assertSame(31000, $input['port_range_start']);
        $this->assertSame(31999, $input['port_range_end']);
        $this->assertSame('10.0.0.5:8443', $input['bind']);
        $this->assertSame('10.0.0.5', $input['ip']);
        $this->assertSame('dns-cloudflare', $input['ssl_challenge']);
        $this->assertSame('staging', $input['ssl_ca_server']);
        $this->assertFalse($input['manage_firewall']);
        $this->assertSame('verify-agent', $input['playbook']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function invalidHosts(): array
    {
        return [
            'spasi' => ['10.0.0.5 ekstra'],
            'titik koma' => ['10.0.0.5; rm -rf /'],
            'ampersand' => ['10.0.0.5 && id'],
            'pipa' => ['10.0.0.5 | cat /etc/passwd'],
            'dollar' => ['$(id).example.com'],
            'backtick' => ['`id`.example.com'],
            'kutip' => ["10.0.0.5'"],
            'newline' => ["10.0.0.5\n10.0.0.6"],
            'ipv4 tidak valid' => ['999.1.1.1'],
            'label kosong' => ['agent..example.com'],
            'strip di awal' => ['-agent.example.com'],
            'kosong' => [''],
        ];
    }

    #[DataProvider('invalidHosts')]
    public function testInvalidHostRejected(string $host): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"host"');

        ProvisionInput::validate($this->payload(['host' => $host]));
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function invalidNames(): array
    {
        return [
            'kosong' => [''],
            'underscore' => ['agent_01'],
            'strip di awal' => ['-agent'],
            'huruf besar + spasi' => ['Agent 01'],
            'terlalu panjang' => [str_repeat('a', 65)],
            'titik' => ['agent.01'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameRejected(mixed $name): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"name"');

        ProvisionInput::validate($this->payload(['name' => $name]));
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function invalidPorts(): array
    {
        return [
            'ssh port 0' => [['ssh_port' => 0]],
            'ssh port terlalu besar' => [['ssh_port' => 70000]],
            'ssh port bukan angka' => [['ssh_port' => 'dua']],
            'agent port 0' => [['agent_port' => 0]],
            'range start = end' => [['port_range_start' => 30000, 'port_range_end' => 30000]],
            'range start > end' => [['port_range_start' => 31000, 'port_range_end' => 30999]],
            'range di luar batas' => [['port_range_start' => 0, 'port_range_end' => 30999]],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[DataProvider('invalidPorts')]
    public function testInvalidPortsRejected(array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('port');

        ProvisionInput::validate($this->payload($overrides));
    }

    public function testDomainMustBeFqdn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"domain"');

        ProvisionInput::validate($this->payload(['domain' => 'localhost']));
    }

    public function testInvalidEmailRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"email"');

        ProvisionInput::validate($this->payload(['email' => 'bukan-email']));
    }

    public function testInvalidSshUserRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"ssh_user"');

        ProvisionInput::validate($this->payload(['ssh_user' => '1root; id']));
    }

    public function testBindMustUseAgentPort(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"bind"');

        ProvisionInput::validate($this->payload(['bind' => '0.0.0.0:9000']));
    }

    public function testBindRejectsMalformedValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"bind"');

        ProvisionInput::validate($this->payload(['bind' => 'tanpa-port']));
    }

    public function testUnknownSslValuesRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"ssl_challenge"');

        ProvisionInput::validate($this->payload(['ssl_challenge' => 'tls-alpn']));
    }

    public function testUnknownPlaybookRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"playbook"');

        ProvisionInput::validate($this->payload(['playbook' => 'hapus-server.yml']));
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function forcePayloads(): array
    {
        return [
            'force true' => [['force' => true]],
            'force string' => [['force' => 'yes']],
            'force angka' => [['force' => 1]],
            'flag --force' => [['--force' => 'on']],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    #[DataProvider('forcePayloads')]
    public function testForceIsRejected(array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('force');

        ProvisionInput::validate($this->payload($overrides));
    }

    public function testForceFalseIsIgnored(): void
    {
        $input = ProvisionInput::validate($this->payload(['force' => false, '--force' => '0']));

        $this->assertSame('agent-01', $input['name']);
    }

    public function testExtraVarsUsesAllowlistOnly(): void
    {
        $input = ProvisionInput::validate($this->payload([
            'control_plane_ips' => '10.0.0.1,10.0.0.2',
            'ip' => '10.0.0.5',
            'ssl_challenge' => 'http',
            'manage_firewall' => true,
        ]));

        $vars = ProvisionInput::extraVars($input);

        foreach (array_keys($vars) as $key) {
            $this->assertStringStartsWith('rames_agent_', $key, 'extra vars harus ber-prefix rames_agent_: ' . $key);
        }

        $expected = [
            'rames_agent_port_range_start',
            'rames_agent_port_range_end',
            'rames_agent_port',
            'rames_agent_manage_firewall',
            'rames_agent_run_installer',
            'rames_agent_assert_identity',
            'rames_agent_domain',
            'rames_agent_email',
            'rames_agent_bind',
            'rames_agent_ip',
            'rames_agent_ssl_challenge',
            'rames_agent_control_plane_ips',
        ];
        $this->assertEqualsCanonicalizing($expected, array_keys($vars));

        $this->assertSame('agent-01.example.com', $vars['rames_agent_domain']);
        $this->assertSame('ops@example.com', $vars['rames_agent_email']);
        $this->assertSame(30000, $vars['rames_agent_port_range_start']);
        $this->assertSame(30999, $vars['rames_agent_port_range_end']);
        $this->assertSame(8443, $vars['rames_agent_port']);
        $this->assertSame('0.0.0.0:8443', $vars['rames_agent_bind']);
        $this->assertSame('10.0.0.5', $vars['rames_agent_ip']);
        $this->assertSame('http', $vars['rames_agent_ssl_challenge']);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $vars['rames_agent_control_plane_ips']);
        $this->assertTrue($vars['rames_agent_manage_firewall']);
        $this->assertTrue($vars['rames_agent_assert_identity']);
        $this->assertTrue($vars['rames_agent_run_installer'], 'provision-agent menjalankan installer');
    }

    public function testExtraVarsVerifyPlaybookSkipsInstallerAndEmptyStrings(): void
    {
        $input = ProvisionInput::validate($this->payload([
            'playbook' => 'verify-agent',
            'manage_firewall' => 'off',
            'control_plane_ips' => '',
        ]));

        $vars = ProvisionInput::extraVars($input);

        $this->assertFalse($vars['rames_agent_run_installer'], 'verify-agent tidak memasang ulang');
        $this->assertArrayNotHasKey('rames_agent_ip', $vars, 'string kosong tidak dikirim');
        $this->assertArrayNotHasKey('rames_agent_ssl_challenge', $vars);
        $this->assertArrayNotHasKey('rames_agent_ssl_ca_server', $vars);
        $this->assertArrayNotHasKey('rames_agent_control_plane_ips', $vars, 'array kosong tidak dikirim');
    }

    /**
     * Firewall dikelola tanpa daftar IP control plane = kegagalan PASTI di
     * preflight role (setelah antre). Validasi harus menolaknya lebih awal.
     */
    public function testAllowIdentityChangeDisablesAssertIdentity(): void
    {
        $default = ProvisionInput::extraVars(ProvisionInput::validate($this->payload()));
        $this->assertTrue($default['rames_agent_assert_identity'], 'bawaan: role memagari identitas');

        $input = ProvisionInput::validate($this->payload(['allow_identity_change' => '1']));
        $this->assertTrue($input['allow_identity_change']);
        $this->assertFalse(
            ProvisionInput::extraVars($input)['rames_agent_assert_identity'],
            'opt-in eksplisit: role boleh mengubah identitas'
        );
    }

    public function testManagedFirewallRequiresControlPlaneIps(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('control_plane_ips');

        ProvisionInput::validate($this->payload(['manage_firewall' => '1', 'control_plane_ips' => '']));
    }

    public function testFirewallDisabledAllowsEmptyControlPlaneIps(): void
    {
        $input = ProvisionInput::validate($this->payload([
            'manage_firewall' => '0',
            'control_plane_ips' => '',
        ]));

        $this->assertFalse($input['manage_firewall']);
        $this->assertSame([], $input['control_plane_ips']);
    }

    public function testControlPlaneIpsAreParsedFromMixedSeparators(): void
    {
        $input = ProvisionInput::validate($this->payload([
            'control_plane_ips' => "10.0.0.1, 10.0.0.2\n192.168.1.9 10.0.0.1",
        ]));

        $this->assertSame(['10.0.0.1', '10.0.0.2', '192.168.1.9'], $input['control_plane_ips']);
    }

    public function testControlPlaneIpsArrayIsAccepted(): void
    {
        $input = ProvisionInput::validate($this->payload([
            'control_plane_ips' => ['203.0.113.5', '', '203.0.113.5'],
        ]));

        $this->assertSame(['203.0.113.5'], $input['control_plane_ips']);
    }

    public function testControlPlaneIpsRejectNonIpv4(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"control_plane_ips"');

        ProvisionInput::validate($this->payload(['control_plane_ips' => '10.0.0.1, agent.example.com']));
    }

    public function testPlaybookMapExposesYamlFiles(): void
    {
        $this->assertSame('provision-agent.yml', ProvisionInput::PLAYBOOKS['provision-agent']);
        $this->assertSame('verify-agent.yml', ProvisionInput::PLAYBOOKS['verify-agent']);
        $this->assertSame('provision-agent', ProvisionInput::DEFAULT_PLAYBOOK);
    }
}
