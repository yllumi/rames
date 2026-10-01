<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\ResticRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test ResticRunner — pembentukan argv helper container (ARRAY, tanpa string
 * shell) + penegakan "tidak ada secret di argv" (passphrase lewat
 * `--password-file`, kredensial lewat `--env-file`).
 */
class ResticArgvTest extends TestCase
{
    private string $tmp;
    private string $passwordFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/restic_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->passwordFile = $this->tmp . '/password';
        file_put_contents($this->passwordFile, 'super-secret-passphrase');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function spec(array $overrides = []): array
    {
        return array_replace([
            'docker' => 'docker',
            'image' => 'rames:test',
            'repository' => 's3:https://s3.example.com/bucket/rames',
            'password_file' => $this->passwordFile,
            'env_file' => $this->tmp . '/run.env',
            'network' => '',
            'dns' => [],
            'binds' => [ResticRunner::volumeBind('tonidata_data')],
            'timeout' => 60,
        ], $overrides);
    }

    // ==================================================================
    // Bentuk argv
    // ==================================================================

    public function testBackupArgvIsListOfShellSafeTokens(): void
    {
        $argv = ResticRunner::buildBackupArgv(
            $this->spec(),
            ['/data'],
            ['project:tonidata', 'volume:tonidata_data', 'strategy:snapshot'],
            'rames-host'
        );

        $this->assertTrue(array_is_list($argv), 'argv harus list array (bukan string command)');
        foreach ($argv as $arg) {
            $this->assertIsString($arg);
            // satu token = satu argumen: tidak ada spasi yang menandakan string shell gabungan
            $this->assertDoesNotMatchRegularExpression('/\s/', $arg);
        }

        $this->assertSame('docker', $argv[0]);
        $this->assertSame(['run', '--rm'], array_slice($argv, 1, 2));
        $this->assertContains('--password-file', $argv);
        $this->assertContains('--repo', $argv);
        $this->assertContains('backup', $argv);
        $this->assertContains('/data', $argv);
        $this->assertContains('--host', $argv);
        $this->assertContains('rames-host', $argv);
        $this->assertContains('--tag', $argv);
        $this->assertContains('volume:tonidata_data', $argv);
        $this->assertContains('rames:test', $argv);
        $this->assertContains('restic', $argv);
    }

    public function testPassphraseGoesThroughMountedPasswordFileNotArgv(): void
    {
        $spec = $this->spec();
        $argv = ResticRunner::buildBackupArgv($spec, ['/data'], [], '');

        $this->assertContains($this->passwordFile . ':' . ResticRunner::PASSWORD_MOUNT . ':ro', $argv);
        $this->assertContains(ResticRunner::PASSWORD_MOUNT, $argv);
        // tidak ada satu pun token yang membawa isi passphrase / assignment env
        foreach ($argv as $arg) {
            $this->assertStringNotContainsString('super-secret-passphrase', $arg);
            $this->assertStringNotContainsString('RESTIC_PASSWORD=', $arg);
        }
        $this->assertNotContains('-e', $argv, 'kredensial tidak boleh lewat -e KEY=VALUE (bocor ke ps)');
    }

    public function testAwsCredentialsOnlyViaEnvFile(): void
    {
        $argv = ResticRunner::buildBackupArgv($this->spec(), ['/data'], [], '');

        $this->assertContains('--env-file', $argv);
        $this->assertContains($this->tmp . '/run.env', $argv);
        foreach ($argv as $arg) {
            $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY=', $arg);
            $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID=', $arg);
        }
    }

    public function testVolumeBindModesAndNameValidation(): void
    {
        $this->assertSame('tonidata_data:/data:ro', ResticRunner::volumeBind('tonidata_data'));
        $this->assertSame('tonidata_data:/data:rw', ResticRunner::volumeBind('tonidata_data', 'rw'));

        foreach (['', 'bad name', 'x;rm -rf /', '--repo', 'vol/../etc', 'a$(id)'] as $bad) {
            try {
                ResticRunner::volumeBind($bad);
                $this->fail("nama volume \"{$bad}\" seharusnya ditolak");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testSnapshotsArgvUsesJsonAndTags(): void
    {
        $argv = ResticRunner::buildSnapshotsArgv($this->spec(), ['project:tonidata']);

        $this->assertContains('snapshots', $argv);
        $this->assertContains('--json', $argv);
        $this->assertContains('project:tonidata', $argv);
    }

    public function testRestoreArgvUsesSnapshotAndTargetWithReadWriteBind(): void
    {
        $argv = ResticRunner::buildRestoreArgv(
            $this->spec(['binds' => [ResticRunner::volumeBind('tonidata_data', 'rw')]]),
            '1a2b3c4d',
            '/data'
        );

        $this->assertContains('restore', $argv);
        $this->assertContains('1a2b3c4d', $argv);
        $this->assertContains('--target', $argv);
        $this->assertContains('/data', $argv);
        $this->assertContains('tonidata_data:/data:rw', $argv);
    }

    public function testRestoreArgvWithoutDeleteIsNonDestructive(): void
    {
        // Jalur restore dump (target `/restore`) tidak boleh menghapus apa pun.
        $argv = ResticRunner::buildRestoreArgv(
            $this->spec(['binds' => ['/tmp/restore:/restore:rw']]),
            '1a2b3c4d',
            '/restore'
        );

        $this->assertContains('restore', $argv);
        $this->assertContains('--target', $argv);
        $this->assertContains('/restore', $argv);
        $this->assertNotContains('--delete', $argv, 'restore dump TIDAK boleh menghapus');
        $this->assertNotContains('--include', $argv);
    }

    public function testSnapshotRestoreArgvDeletesStaleFilesWithinVolumeOnly(): void
    {
        // argv produksi jalur snapshot: `restore <snap> --target / --delete --include /data`.
        $argv = ResticRunner::buildRestoreArgv(
            $this->spec(['binds' => [ResticRunner::volumeBind('tonidata_data', 'rw')]]),
            '1a2b3c4d',
            '/',
            true,
            ResticRunner::DATA_PATH
        );

        $this->assertTrue(array_is_list($argv), 'argv harus list array (bukan string command)');
        foreach ($argv as $arg) {
            $this->assertIsString($arg);
            $this->assertDoesNotMatchRegularExpression('/\s/', $arg);
        }

        $this->assertContains('--delete', $argv, 'berkas basi wajib dihapus saat restore snapshot');
        $this->assertContains('--include', $argv, '--delete tanpa filter ditolak restic → --include wajib');
        $this->assertContains('tonidata_data:/data:rw', $argv, 'volume harus di-mount rw pada helper');

        // urutan: --target <t> → --delete → --include <filter>, filter = mount volume.
        $targetIndex = array_search('--target', $argv, true);
        $deleteIndex = array_search('--delete', $argv, true);
        $includeIndex = array_search('--include', $argv, true);
        $this->assertIsInt($targetIndex);
        $this->assertIsInt($deleteIndex);
        $this->assertIsInt($includeIndex);
        $this->assertSame('/', $argv[$targetIndex + 1], 'target wajib akar volume (`/`) di helper');
        $this->assertGreaterThan($targetIndex, $deleteIndex);
        $this->assertGreaterThan($deleteIndex, $includeIndex);
        $this->assertSame(ResticRunner::DATA_PATH, $argv[$includeIndex + 1], 'filter hapus harus path mount volume');
        $this->assertSame('/data', $argv[$includeIndex + 1]);
    }

    public function testSnapshotRestoreArgvTargetsVolumeRootExactlyOnce(): void
    {
        $argv = ResticRunner::buildRestoreArgv(
            $this->spec(['binds' => [ResticRunner::volumeBind('tonidata_data', 'rw')]]),
            '1a2b3c4d',
            '/',
            true,
            ResticRunner::DATA_PATH
        );

        // `--target` muncul sekali dengan nilai `/`; filter hapus = `/data` (bukan `/`).
        $targets = array_keys($argv, '--target', true);
        $this->assertCount(1, $targets);
        $this->assertSame('/', $argv[$targets[0] + 1]);

        $includes = array_keys($argv, '--include', true);
        $this->assertCount(1, $includes);
        $this->assertSame('/data', $argv[$includes[0] + 1]);
        $this->assertNotSame($argv[$targets[0] + 1], $argv[$includes[0] + 1]);
    }

    public function testRestoreDeleteWithoutIncludeFilterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResticRunner::buildRestoreArgv($this->spec(), '1a2b3c4d', '/', true, '');
    }

    public function testDeleteOnlyAppearsOnSnapshotRestoreAndNeverOnOtherCommands(): void
    {
        $spec = $this->spec();

        foreach ([
            ResticRunner::buildBackupArgv($spec, ['/data'], [], ''),
            ResticRunner::buildSnapshotsArgv($spec, []),
            ResticRunner::buildForgetArgv($spec, ['daily' => 7]),
            ResticRunner::buildCheckArgv($spec),
            ResticRunner::buildRestoreArgv($spec, '1a2b3c4d', '/restore'),
        ] as $argv) {
            $this->assertNotContains('--delete', $argv);
            $this->assertNotContains('--include', $argv);
        }
    }

    public function testForgetArgvMapsKeepFlagsAndDropsNonPositive(): void
    {
        $argv = ResticRunner::buildForgetArgv(
            $this->spec(),
            ['daily' => 7, 'weekly' => 4, 'monthly' => 0, 'yearly' => -1],
            ['project:tonidata']
        );

        $this->assertContains('forget', $argv);
        $this->assertContains('--prune', $argv);
        $this->assertContains('--keep-daily', $argv);
        $this->assertContains('7', $argv);
        $this->assertContains('--keep-weekly', $argv);
        $this->assertContains('4', $argv);
        $this->assertNotContains('--keep-monthly', $argv);
        $this->assertNotContains('--keep-yearly', $argv);
    }

    public function testForgetRejectsUnknownRetentionKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResticRunner::buildForgetArgv($this->spec(), ['forever' => 3]);
    }

    public function testSnapshotIdAndContainerPathValidation(): void
    {
        $this->assertSame('1a2b3c4d', ResticRunner::assertSnapshotId('1a2b3c4d'));
        foreach (['abc', 'latest', 'ZZZZ', '1a2b3c4d; rm -rf /', ''] as $bad) {
            try {
                ResticRunner::assertSnapshotId($bad);
                $this->fail("id snapshot \"{$bad}\" seharusnya ditolak");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame('/data', ResticRunner::assertContainerPath('/data'));
        $this->assertSame('/data/sub', ResticRunner::assertContainerPath('/data/sub/'));
        foreach (['/', 'data', '/data/../etc', '/data;rm -rf /'] as $bad) {
            try {
                ResticRunner::assertContainerPath($bad);
                $this->fail("path \"{$bad}\" seharusnya ditolak");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRestoreTargetAllowsContainerRootButRejectsSloppyPaths(): void
    {
        // `/` sah HANYA sebagai target restore snapshot (akar volume di helper).
        $this->assertSame('/', ResticRunner::assertRestoreTarget('/'));
        $this->assertSame('/restore', ResticRunner::assertRestoreTarget('/restore'));
        $this->assertSame('/data/sub', ResticRunner::assertRestoreTarget('/data/sub/'));

        foreach (['', 'data', '//', '/data/../etc', '/data;rm -rf /', '/data extra'] as $bad) {
            try {
                ResticRunner::assertRestoreTarget($bad);
                $this->fail("target restore \"{$bad}\" seharusnya ditolak");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testHelperArgvRequiresImage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResticRunner::buildBackupArgv($this->spec(['image' => '']), ['/data'], [], '');
    }

    public function testEntrypointAndResticBinaryCanBeOverridden(): void
    {
        $argv = ResticRunner::buildCheckArgv($this->spec([
            'entrypoint' => 'restic',
            'restic_binary' => '',
            'network' => 'rames_default',
            'dns' => ['1.1.1.1'],
        ]));

        $this->assertContains('--entrypoint', $argv);
        $this->assertContains('restic', $argv);
        $this->assertContains('--network', $argv);
        $this->assertContains('rames_default', $argv);
        $this->assertContains('--dns', $argv);
        $this->assertContains('1.1.1.1', $argv);
        $this->assertContains('check', $argv);
        // entrypoint=restic → binary tidak diulang di command container
        $this->assertSame('check', $argv[array_key_last($argv)]);
    }

    public function testParseSnapshotIdFromTextJsonAndGarbage(): void
    {
        $this->assertSame('1a2b3c4d', ResticRunner::parseSnapshotId("snapshot 1a2b3c4d saved\n"));
        $this->assertSame(
            'deadbeef',
            ResticRunner::parseSnapshotId("{\"message_type\":\"summary\",\"snapshot_id\":\"deadbeef\"}\n")
        );
        $this->assertNull(ResticRunner::parseSnapshotId('no snapshot here'));
    }

    // ==================================================================
    // Eksekusi lewat ProcessRunner (tanpa proses nyata)
    // ==================================================================

    public function testInstanceBackupPassesArgvArrayToRunnerAndThrowsOnFailure(): void
    {
        $fake = new FakeCommandRunner();
        $fake->reply('restic', 'boom', 3, 'repo tidak bisa dibuka');

        $runner = new ResticRunner($fake, $this->spec());

        try {
            $runner->backup(['/data'], ['volume:tonidata_data'], '');
            $this->fail('backup gagal seharusnya melempar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('restic gagal', $e->getMessage());
            $this->assertStringContainsString('repo tidak bisa dibuka', $e->getMessage());
        }

        $this->assertCount(1, $fake->calls);
        $call = $fake->calls[0];
        $this->assertStringContainsString('docker run --rm', $call);
        $this->assertStringContainsString('--password-file', $call);
        $this->assertStringContainsString('backup /data', $call);
        $this->assertStringNotContainsString('super-secret-passphrase', $call);
        $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY=', $call);
    }

    public function testInstanceBackupReturnsResultAndSnapshotIdCanBeParsed(): void
    {
        $fake = new FakeCommandRunner();
        $fake->reply('backup', "snapshot 1a2b3c4d saved\n", 0);

        $runner = new ResticRunner($fake, $this->spec());
        $result = $runner->backup(['/data'], ['strategy:snapshot'], '');

        $this->assertSame(0, $result['code']);
        $this->assertSame('1a2b3c4d', ResticRunner::parseSnapshotId($result['stdout']));
    }

    public function testInstanceFailsFastWhenRepositoryMissing(): void
    {
        $runner = new ResticRunner(new FakeCommandRunner(), $this->spec(['repository' => '']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RESTIC_REPOSITORY');
        $runner->backup(['/data'], [], '');
    }

    public function testInstanceFailsFastWhenPasswordFileMissing(): void
    {
        $runner = new ResticRunner(new FakeCommandRunner(), $this->spec([
            'password_file' => $this->tmp . '/tidak-ada',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('passphrase');
        $runner->backup(['/data'], [], '');
    }

    public function testSnapshotsParsesJsonOutput(): void
    {
        $fake = new FakeCommandRunner();
        $fake->reply('snapshots', '[{"short_id":"1a2b3c4d","tags":["volume:x"]}]', 0);

        $runner = new ResticRunner($fake, $this->spec());
        $this->assertSame([['short_id' => '1a2b3c4d', 'tags' => ['volume:x']]], $runner->snapshots([]));
    }

    public function testSnapshotsRejectsNonJsonOutput(): void
    {
        $fake = new FakeCommandRunner();
        $fake->reply('snapshots', 'bukan json', 0);

        $runner = new ResticRunner($fake, $this->spec());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('snapshots --json');
        $runner->snapshots([]);
    }

    public function testCheckNeverThrows(): void
    {
        $fake = new FakeCommandRunner();
        $fake->reply('check', 'Fatal: unable to open repository', 1);

        $runner = new ResticRunner($fake, $this->spec());
        $result = $runner->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unable to open repository', $result['output']);
    }
}
