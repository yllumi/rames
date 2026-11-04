<?php
declare(strict_types=1);

namespace Tests;

use app\library\Provision\AnsibleRunner;
use app\library\Provision\ProvisionInput;
use app\library\Provision\ProvisionAgentKey;
use app\library\Support\ProcessRunner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bentuk argv helper Ansible ({@see AnsibleRunner::command()}), pembatalan job,
 * dan pembersihan direktori kerja.
 *
 * Yang dikunci:
 *  - argv **array** untuk `docker run` (tanpa shell, tanpa string gabungan) —
 *    satu-satunya jalan agar input job tidak bisa menjadi command injection;
 *  - mount playbook/kunci/known_hosts `:ro` dan direktori job `:rw`, label job
 *    `rames.provision.job=<id>` (dasar `cancel()`), serta `--limit <name>`;
 *  - playbook di luar daftar putih {@see ProvisionInput::PLAYBOOKS} dan id
 *    berbahaya ditolak sebelum docker dipanggil;
 *  - `cleanup()` hanya menghapus isi direktori kerja **di bawah** work root.
 *
 * Semua path di direktori temp — `ops/ansible`, `database/keys`, dan
 * `runtime/provision` nyata tidak pernah disentuh dan docker tidak dijalankan.
 */
class AnsibleRunnerCommandTest extends TestCase
{
    private string $tmp;

    /** Token contoh yang tidak boleh muncul di argv/pesan galat. */
    private const SECRET_TOKEN = 'token-rahasia-jangan-bocor';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-ansible-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/playbooks', 0775, true);
        mkdir($this->tmp . '/work', 0775, true);
        mkdir($this->tmp . '/keys', 0700, true);
        file_put_contents($this->tmp . '/known_hosts', '');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testCommandIsArrayArgvWithMountsLabelsAndLimit(): void
    {
        $jobDir = $this->tmp . '/work/job-1';
        $argv = $this->runner()->command($this->job(), $jobDir);

        $this->assertSame('docker', $argv[0], 'argv wajib dimulai program (bukan string shell)');
        $this->assertSame('run', $argv[1]);
        $this->assertContains('--rm', $argv);
        $this->assertContains('--network', $argv);
        $this->assertContains('host', $argv);
        $this->assertContains('rames.role=ansible-runner', $argv);
        $this->assertContains('rames.provision.job=job-1', $argv);

        // Mount: playbook & kunci read-only, direktori job write.
        // Kunci = SATU pasangan bersama untuk semua server (`rames-agent-key`).
        $keyPath = (new ProvisionAgentKey(null, $this->tmp . '/keys'))->privatePath();
        $this->assertStringContainsString('/provision/rames-agent-key', $keyPath);
        $this->assertContains($this->tmp . '/playbooks:/ansible:ro', $argv);
        $this->assertContains($jobDir . ':/job:rw', $argv);
        $this->assertContains($keyPath . ':/ssh/id:ro', $argv);
        $this->assertContains($this->tmp . '/known_hosts:/ssh/known_hosts:ro', $argv);

        // Environment Ansible = argumen `-e`, bukan env proses worker.
        $this->assertContains('ANSIBLE_CONFIG=/ansible/ansible.cfg', $argv);
        $this->assertContains('ANSIBLE_HOST_KEY_CHECKING=True', $argv);
        $this->assertContains('ANSIBLE_PRIVATE_KEY_FILE=/ssh/id', $argv);
        $this->assertContains(
            'ANSIBLE_SSH_ARGS=-o IdentitiesOnly=yes -o UserKnownHostsFile=/ssh/known_hosts -o StrictHostKeyChecking=yes',
            $argv
        );
        $this->assertContains('ANSIBLE_LOCAL_TEMP=/tmp/ansible-local', $argv);
        $this->assertContains('ANSIBLE_REMOTE_TEMP=/tmp/ansible-remote', $argv);

        // Perintah playbook + inventori job + batas host inventori.
        $imageIndex = (int) array_search('ansible-playbook', $argv, true);
        $this->assertGreaterThan(0, $imageIndex);
        $this->assertSame($this->runner()->image(), $argv[$imageIndex - 1], 'image tepat sebelum perintah');
        $this->assertSame(config('deploy.ansible_image', 'alpine/ansible:latest'), $this->runner()->image());
        $this->assertContains('-i', $argv);
        $this->assertContains('/job/inventory.yml', $argv);
        $this->assertContains('/ansible/playbooks/provision-agent.yml', $argv,
            'path playbook WAJIB absolut di dalam helper (image ber-WorkingDir /)');
        $this->assertContains('-e', $argv);
        $this->assertContains('@/job/extra_vars.yml', $argv);
        $this->assertContains('--limit', $argv);
        $this->assertContains('agent-01', $argv);

        // Tidak ada token/PEM yang ikut ke argv (rahasia hidup di berkas 0600).
        $this->assertStringNotContainsString(self::SECRET_TOKEN, implode("\n", $argv));

        foreach ($argv as $arg) {
            $this->assertIsString($arg);
            $this->assertDoesNotMatchRegularExpression(
                '/[\x00-\x1f;&|`$<>]/',
                $arg,
                'argv tidak boleh memuat metakarakter shell'
            );
        }
        $this->assertNotContains('docker run', $argv, 'tidak boleh ada string perintah gabungan');
    }

    public function testAllWhitelistedPlaybooksMapToTheirFile(): void
    {
        foreach (ProvisionInput::PLAYBOOKS as $key => $file) {
            $job = $this->job(['playbook' => $key]);
            $this->assertContains(
                '/ansible/playbooks/' . $file,
                $this->runner()->command($job, $this->tmp . '/work/job-1'),
                "playbook {$key} harus dipetakan ke {$file}"
            );

            // Nama berkasnya sendiri juga diterima (idempoten dari kolom job).
            $job = $this->job(['playbook' => $file]);
            $this->assertContains(
                '/ansible/playbooks/' . $file,
                $this->runner()->command($job, $this->tmp . '/work/job-1')
            );
        }
    }

    public function testUnknownPlaybookIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Playbook provisioning tidak dikenal');

        $this->runner()->command($this->job(['playbook' => 'playbooks/../../etc/passwd']), $this->tmp . '/work/job-1');
    }

    /**
     * @return array<string,array{0:array<string,string>}>
     */
    public static function dangerousIds(): array
    {
        return [
            'traversal' => [['name' => '../etc']],
            'spasi' => [['name' => 'job 1']],
            'shell' => [['name' => 'x;rm -rf /']],
            'terlalu panjang' => [['name' => str_repeat('a', 65)]],
        ];
    }

    /**
     * @param array<string,string> $overrides
     */
    #[DataProvider('dangerousIds')]
    public function testDangerousServerIdsAreRejected(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->command(array_merge($this->job(), $overrides), $this->tmp . '/work/job-1');
    }

    public function testMissingServerIdentityIsRejected(): void
    {
        $job = $this->job();
        unset($job['server_id'], $job['name']);

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->command($job, $this->tmp . '/work/job-1');
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function dangerousJobIds(): array
    {
        return [
            'kosong' => [''],
            'traversal' => ['../../etc/passwd'],
            'shell' => ['job;id'],
            'terlalu panjang' => [str_repeat('b', 65)],
        ];
    }

    #[DataProvider('dangerousJobIds')]
    public function testDangerousJobIdsAreRejected(string $jobId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->command($this->job(['id' => $jobId]), $this->tmp . '/work/job-1');
    }

    public function testRelativePathsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnsibleRunner(null, 'ops/ansible', $this->tmp . '/work', $this->tmp . '/keys', $this->tmp . '/known_hosts');
    }

    public function testLimitMustNotLookLikeAnOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->command($this->job(['name' => '--check']), $this->tmp . '/work/job-1');
    }

    public function testTimeoutFallsBackTo1800Seconds(): void
    {
        $configured = (int) config('deploy.ansible_timeout', 1800);

        $this->assertSame($configured > 0 ? $configured : 1800, $this->runner()->timeout());
    }

    public function testCancelRemovesContainersByJobLabel(): void
    {
        $runner = new class extends ProcessRunner {
            /** @var array<int,array<int,string>> */
            public array $calls = [];

            public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
            {
                $this->calls[] = $command;

                return ['code' => 0, 'stdout' => "abc123\n", 'stderr' => '', 'timedOut' => false];
            }
        };

        $ansible = new AnsibleRunner($runner, $this->tmp . '/playbooks', $this->tmp . '/work', $this->tmp . '/keys', $this->tmp . '/known_hosts');
        $this->assertTrue($ansible->cancel('job-1'));

        $this->assertSame(['docker', 'ps', '-q', '--filter', 'rames.provision.job=job-1'], $runner->calls[0]);
        $this->assertSame(['docker', 'rm', '-f', 'abc123'], $runner->calls[1]);
    }

    public function testCancelReturnsFalseWhenNoContainerMatches(): void
    {
        $runner = new class extends ProcessRunner {
            public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
            {
                return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
            }
        };

        $ansible = new AnsibleRunner($runner, $this->tmp . '/playbooks', $this->tmp . '/work', $this->tmp . '/keys', $this->tmp . '/known_hosts');
        $this->assertFalse($ansible->cancel('job-1'));
    }

    public function testCleanupRemovesJobDirContentsOnly(): void
    {
        $jobDir = $this->tmp . '/work/job-1';
        mkdir($jobDir . '/nested', 0700, true);
        file_put_contents($jobDir . '/onboarding.txt', "TOKEN=rahasia\n");
        file_put_contents($jobDir . '/nested/inventory.yml', "agents: {}\n");

        $this->runner()->cleanup($jobDir);

        $this->assertDirectoryExists($jobDir, 'direktori job dipertahankan');
        $this->assertSame([], array_values(array_diff(scandir($jobDir) ?: [], ['.', '..'])));
    }

    public function testCleanupRefusesPathOutsideWorkRoot(): void
    {
        $outside = $this->tmp . '/outside';
        mkdir($outside, 0700, true);
        file_put_contents($outside . '/penting.txt', 'jangan dihapus');

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->runner()->cleanup($outside);
        } finally {
            $this->assertFileExists($outside . '/penting.txt', 'berkas di luar work root tidak boleh tersentuh');
        }
    }

    private function runner(): AnsibleRunner
    {
        return new AnsibleRunner(
            null,
            $this->tmp . '/playbooks',
            $this->tmp . '/work',
            $this->tmp . '/keys',
            $this->tmp . '/known_hosts'
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function job(array $overrides = []): array
    {
        return array_merge([
            'id' => 'job-1',
            'name' => 'agent-01',
            'playbook' => 'provision-agent',
            'server_id' => 'srv-1',
            'host' => '10.0.0.5',
            'ssh_user' => 'root',
            'ssh_port' => 22,
        ], $overrides);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                @unlink($child);
                continue;
            }
            self::removeTree($child);
        }
        @rmdir($path);
    }
}
