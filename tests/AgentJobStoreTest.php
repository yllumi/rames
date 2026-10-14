<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentError;
use app\library\Agent\AgentJobStore;
use PHPUnit\Framework\TestCase;

/**
 * Test AgentJobStore (registry job **di sisi agent**, fondasi `POST /jobs` F1).
 *
 * Yang dikunci: satu berkas JSON per job lewat `JsonStore` (atomik + flock),
 * transisi `pending → running → done/error`, log berbasis baris dengan offset +
 * plafon baca 256 KiB, `prune` yang hanya membuang job **selesai** terlama,
 * penolakan id tak sah (anti path traversal), dan higienitas: rekaman job tidak
 * pernah memuat token/kredensial.
 *
 * Seluruh uji memakai direktori `/tmp` — tidak pernah menyentuh `runtime/` nyata.
 */
class AgentJobStoreTest extends TestCase
{
    private string $tmp;
    private AgentJobStore $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/agent_jobs_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
        $this->store = new AgentJobStore($this->tmp . '/jobs');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testCreateWritesRecordThroughJsonStore(): void
    {
        $job = $this->store->create('deploy', 'app1', ['app' => 'app1', 'ref' => 'main']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $job['id']);
        $this->assertSame('deploy', $job['op']);
        $this->assertSame('app1', $job['app_id']);
        $this->assertSame('pending', $job['status']);
        $this->assertSame('', $job['stage']);
        $this->assertSame('', $job['error']);
        $this->assertNotSame('', $job['created_at']);
        $this->assertSame('', $job['started_at']);
        $this->assertSame('', $job['finished_at']);
        $this->assertSame(['app' => 'app1', 'ref' => 'main'], $job['payload']);
        $this->assertContains($job['status'], AgentJobStore::STATUSES);

        $path = $this->tmp . '/jobs/' . $job['id'] . '.json';
        $this->assertFileExists($path);
        $this->assertSame($job, json_decode((string) file_get_contents($path), true));
        $this->assertSame($job, $this->store->find((string) $job['id']));
    }

    public function testCreateRejectsEmptyOp(): void
    {
        try {
            $this->store->create('  ', 'app1');
            $this->fail('op kosong harus ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::INVALID_CONFIG, $e->reason);
        }
    }

    public function testUpdateTransitionsStatusAndKeepsId(): void
    {
        $job = $this->store->create('deploy', 'app1');
        $id = (string) $job['id'];

        $running = $this->store->update($id, static function (array &$record): void {
            $record['status'] = 'running';
            $record['stage'] = 'build';
            $record['started_at'] = date('c');
            $record['id'] = 'id-palsu'; // mutator tidak boleh mengubah id
        });

        $this->assertSame('running', $running['status']);
        $this->assertSame('build', $running['stage']);
        $this->assertSame($id, $running['id']);
        $this->assertSame('running', $this->store->find($id)['status']);

        $done = $this->store->update($id, static function (array &$record): void {
            $record['status'] = 'done';
            $record['message'] = 'selesai';
            $record['finished_at'] = date('c');
        });

        $this->assertSame('done', $done['status']);
        $this->assertSame('selesai', $done['message']);
        $this->assertFileExists($this->tmp . '/jobs/' . $id . '.json.bak', 'JsonStore menyimpan backup');
    }

    public function testFindAndUpdateRejectUnknownOrUnsafeId(): void
    {
        $job = $this->store->create('deploy', 'app1');

        $this->assertNull($this->store->find('tidakada'));
        $this->assertNull($this->store->find('../../database/auth.json'));
        $this->assertNull($this->store->find((string) $job['id'] . '/../x'));

        foreach (['tidakada', '../auth', ''] as $id) {
            try {
                $this->store->update($id, static function (array &$record): void {
                    $record['status'] = 'done';
                });
                $this->fail("update(\"{$id}\") harus ditolak");
            } catch (AgentError $e) {
                $this->assertSame(AgentError::NOT_FOUND, $e->reason);
            }
        }

        // Tidak ada berkas asing yang tercipta di luar direktori job.
        $this->assertFileDoesNotExist($this->tmp . '/auth.json');
    }

    public function testAppendAndReadLogFromOffset(): void
    {
        $job = $this->store->create('deploy', 'app1');
        $id = (string) $job['id'];

        $this->assertSame('', $this->store->readLog($id), 'job tanpa log = string kosong');

        $this->store->appendLog($id, 'baris satu');
        $this->store->appendLog($id, "baris dua\n");
        $this->store->appendLog($id, "baris tiga\r\n");
        $this->store->appendLog($id, '');

        $log = $this->store->readLog($id);
        $this->assertSame("baris satu\nbaris dua\nbaris tiga\n", $log);
        $this->assertSame($log, (string) file_get_contents($this->store->logPath($id)));

        $this->assertSame("baris dua\nbaris tiga\n", $this->store->readLog($id, 11));
        $this->assertSame('', $this->store->readLog($id, strlen($log)));
        $this->assertSame('', $this->store->readLog($id, strlen($log) + 500));
        $this->assertSame($log, $this->store->readLog($id, -10), 'offset negatif dijepit ke 0');
    }

    public function testAppendLogRequiresExistingJob(): void
    {
        try {
            $this->store->appendLog('tidakada', 'baris');
            $this->fail('appendLog untuk job tidak ada harus ditolak');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::NOT_FOUND, $e->reason);
        }

        foreach (['../x', 'a/b', ''] as $id) {
            try {
                $this->store->logPath($id);
                $this->fail("logPath(\"{$id}\") harus ditolak");
            } catch (AgentError $e) {
                $this->assertSame(AgentError::NOT_FOUND, $e->reason);
            }
        }
    }

    public function testReadLogIsBoundedTo256KiBAndLineAligned(): void
    {
        $job = $this->store->create('deploy', 'app1');
        $id = (string) $job['id'];

        $line = str_repeat('x', 1023) . "\n"; // 1 KiB per baris
        for ($i = 0; $i < 400; $i++) {        // 400 KiB total
            $this->store->appendLog($id, rtrim($line, "\n"));
        }

        $full = (string) file_get_contents($this->store->logPath($id));
        $this->assertSame(400 * 1024, strlen($full));

        $offset = 0;
        $rounds = 0;
        $collected = '';
        while (true) {
            $chunk = $this->store->readLog($id, $offset);
            if ($chunk === '') {
                break;
            }
            $this->assertLessThanOrEqual(AgentJobStore::MAX_LOG_READ, strlen($chunk));
            $this->assertStringEndsWith("\n", $chunk, 'potongan selalu berakhir di batas baris');
            $collected .= $chunk;
            $offset += strlen($chunk);
            $this->assertLessThan(10, ++$rounds, 'pembacaan harus maju');
        }

        $this->assertGreaterThan(1, $rounds, '400 KiB tidak boleh terbaca dalam satu panggilan');
        $this->assertSame($full, $collected);
    }

    public function testAllReturnsNewestFirst(): void
    {
        $first = $this->store->create('deploy', 'app1');
        $second = $this->store->create('rebuild', 'app2');

        // created_at punya granularitas detik ⇒ ditetapkan eksplisit agar urutan deterministik.
        $this->setCreatedAt((string) $first['id'], '2026-10-14T11:00:00+07:00');
        $this->setCreatedAt((string) $second['id'], '2026-10-14T12:00:00+07:00');

        $all = $this->store->all();
        $this->assertCount(2, $all);
        $this->assertSame($second['id'], $all[0]['id']);
        $this->assertSame($first['id'], $all[1]['id']);
    }

    public function testPruneRemovesOldestFinishedJobsWithTheirLogs(): void
    {
        $jobs = [];
        foreach (['app1', 'app2', 'app3', 'app4'] as $index => $appId) {
            $job = $this->store->create('deploy', $appId);
            $id = (string) $job['id'];
            $this->store->appendLog($id, 'log ' . $appId);
            $finishedAt = sprintf('2026-10-14T10:%02d:00+07:00', $index);
            $this->store->update($id, static function (array &$record) use ($finishedAt): void {
                $record['status'] = 'done';
                $record['finished_at'] = $finishedAt;
            });
            $jobs[$appId] = $id;
        }

        $running = $this->store->create('deploy', 'app5'); // pending: tidak boleh dipangkas

        $this->assertSame(2, $this->store->prune(2));

        $this->assertNull($this->store->find($jobs['app1']));
        $this->assertNull($this->store->find($jobs['app2']));
        $this->assertFileDoesNotExist($this->store->logPath($jobs['app1']));
        $this->assertFileDoesNotExist($this->store->logPath($jobs['app2']));
        $this->assertFileDoesNotExist($this->tmp . '/jobs/' . $jobs['app1'] . '.json.bak');
        $this->assertNotNull($this->store->find($jobs['app3']));
        $this->assertNotNull($this->store->find($jobs['app4']));
        $this->assertNotNull($this->store->find((string) $running['id']));

        $this->assertSame(0, $this->store->prune(2), 'idempoten: tidak ada lagi yang dipangkas');
        $this->assertSame(2, $this->store->prune(0), 'keep=0 membuang semua job selesai');
        $this->assertNotNull($this->store->find((string) $running['id']));
    }

    public function testRecordsNeverContainCredentials(): void
    {
        $secret = 'tok-rahasia-deploy-1a2b3c';
        $job = $this->store->create('deploy', 'app1', [
            'app' => 'app1',
            'ref' => 'main',
            'token' => $secret,
            'token_ref' => $secret,
            'deploy_key' => $secret,
            'authorization' => 'Bearer ' . $secret,
            'env' => ['APP_KEY' => 'base64:rahasia', 'DB_PASSWORD' => $secret, 'APP_URL' => 'https://x.test'],
            'nested' => [['api_key' => $secret]],
        ]);

        $raw = (string) file_get_contents($this->tmp . '/jobs/' . $job['id'] . '.json');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString('base64:rahasia', $raw);

        $stored = $this->store->find((string) $job['id']);
        $this->assertSame('[redacted]', $stored['payload']['token']);
        $this->assertSame('[redacted]', $stored['payload']['env']['DB_PASSWORD']);
        $this->assertSame('[redacted]', $stored['payload']['nested'][0]['api_key']);
        $this->assertSame('app1', $stored['payload']['app']);
        $this->assertSame('https://x.test', $stored['payload']['env']['APP_URL']);
    }

    public function testDirIsOverridableAndDefaultPointsToRuntime(): void
    {
        $this->assertSame($this->tmp . '/jobs', $this->store->dir());
        $this->assertStringEndsWith('/agent-jobs', (new AgentJobStore())->dir());
        $this->assertSame('', (new AgentJobStore())->readLog('tidakada'));
    }

    /** Set `created_at` eksplisit (urutan `all()` deterministik di uji). */
    private function setCreatedAt(string $id, string $createdAt): void
    {
        $this->store->update($id, static function (array &$record) use ($createdAt): void {
            $record['created_at'] = $createdAt;
        });
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
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
