<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Kontrak role Ansible `rames_agent` yang **tidak** tereksekusi di suite PHP
 * (playbook dijalankan ke host nyata), tetapi mudah rusak tanpa disadari:
 *
 *  - `verify.yml` memanggil `/version` agent yang dijaga
 *    `AgentAuthMiddleware`: WAJIB `Authorization: Bearer` + `X-Rames-Timestamp`
 *    + `X-Rames-Nonce`. Tanpa dua header terakhir respons SELALU 401 walau token
 *    benar (pernah terjadi di produksi).
 *  - `install.yml` DILARANG meneruskan `--force` (merotasi token/TLS agent).
 *  - `firewall.yml` DILARANG `ufw enable` atau mengubah default policy.
 *  - `ansible.cfg` memakai inventory nyata yang di-gitignore; `hosts.example.yml`
 *    harus tetap ada agar operator baru punya titik mulai.
 *
 * Tes ini adalah jaring pengaman berbasis teks: cepat, tanpa host.
 */
class ProvisionAnsibleRoleContractTest extends TestCase
{
    private const ROLE = '/ops/ansible/roles/rames_agent/tasks';

    public function testVerifySendsTimestampAndNonceHeaders(): void
    {
        $task = $this->read(self::ROLE . '/verify.yml');

        $this->assertStringContainsString('Authorization: "Bearer {{ rames_agent_token }}"', $task);
        $this->assertStringContainsString('X-Rames-Timestamp:', $task, 'agent menolak (401) tanpa timestamp');
        $this->assertStringContainsString('X-Rames-Nonce:', $task, 'agent menolak (401) tanpa nonce');
        $this->assertStringContainsString('rames_agent_verify_nonce', $task);
        $this->assertStringContainsString('date', $task, 'timestamp harus dari jam target, bukan control node');
    }

    public function testInstallNeverForcesRecreation(): void
    {
        // Komentar role menyebut larangan `--force`; yang diperiksa hanya isi task.
        $task = $this->code(self::ROLE . '/install.yml');

        // Nama task menyebut "tanpa --force"; yang dilarang adalah ITEM argv-nya.
        $this->assertStringNotContainsString("'--force'", $task, 'argv installer tidak boleh memuat --force');
        $this->assertStringNotContainsString('"--force"', $task);
        $this->assertStringNotContainsString('- --force', $task);
        $this->assertStringContainsString('agent/install.sh', $task);
    }

    public function testFirewallNeverEnablesOrChangesDefaultPolicy(): void
    {
        $task = $this->code(self::ROLE . '/firewall.yml');

        // argv berbentuk daftar (`- ufw`, `- enable`) — periksa token, bukan frasa.
        $this->assertDoesNotMatchRegularExpression('/^\s*-\s*enable\s*$/m', $task, 'ufw enable DILARANG');
        $this->assertStringNotContainsString('ufw --force', $task);
        $this->assertDoesNotMatchRegularExpression('/^\s*-\s*default\s*$/m', $task, 'ubah default policy DILARANG');
        $this->assertStringContainsString('to any port', $task);
        $this->assertStringContainsString('80,443/tcp', $task, 'GOTCHA 52: 80/443 wajib publik');
    }

    public function testExampleInventoryExists(): void
    {
        $root = dirname(__DIR__) . '/ops/ansible/inventories/agents';

        $this->assertFileExists($root . '/hosts.example.yml');
        $this->assertStringContainsString('agents:', (string) file_get_contents($root . '/hosts.example.yml'));
    }

    /** Isi task tanpa baris komentar (negatif-check tidak boleh kena dokumentasi). */
    private function code(string $relative): string
    {
        $lines = preg_split('/\R/', $this->read($relative)) ?: [];

        return implode("\n", array_filter(
            $lines,
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#')
        ));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__) . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
