<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ComposeBinds;
use PHPUnit\Framework\TestCase;

/**
 * Unit test ComposeBinds — penyiapan source bind mount app sebelum
 * `docker compose up`: kumpulkan bind dari volume bernama (driver_opts/o: bind)
 * & bind mount service, buat direktori yang aman dibuat, dan laporkan yang tidak
 * bisa dibuat (file / di luar direktori app).
 */
class ComposeBindsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/composebinds_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    // ==================================================================
    // collect()
    // ==================================================================

    public function testCollectsVolumeDeviceWithBindOption(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n"
            . "volumes:\n  data:\n    driver: local\n    driver_opts:\n      type: none\n"
            . "      device: \${PWD}/.hermes\n      o: bind\n");

        $binds = ComposeBinds::collect($this->tmp, ['docker-compose.yml'], ['PWD' => $this->tmp]);

        $this->assertCount(1, $binds);
        $this->assertSame('volume', $binds[0]['type']);
        $this->assertSame($this->tmp . '/.hermes', $binds[0]['path']);
        $this->assertTrue($binds[0]['dir']);
        // ${PWD} tak ter-substitusi bila env tidak diberikan
        $bindsNoEnv = ComposeBinds::collect($this->tmp, ['docker-compose.yml'], []);
        $this->assertSame([], $bindsNoEnv);
    }

    public function testIgnoresVolumeWithoutBindOption(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n"
            . "volumes:\n  data:\n    driver: local\n    driver_opts:\n"
            . "      type: nfs\n      device: \":/export/data\"\n      o: \"addr=10.0.0.1,rw\"\n");

        $this->assertSame([], ComposeBinds::collect($this->tmp, ['docker-compose.yml'], ['PWD' => $this->tmp]));
    }

    public function testCollectsServiceShortSyntaxBindsAndSkipsNamedVolumes(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n"
            . "      - ./data:/var/lib/data\n"
            . "      - named-vol:/opt/named\n"
            . "      - /opt/anonymous\n"
            . "      - ./conf:/etc/conf:ro\n");

        $binds = ComposeBinds::collect($this->tmp, ['docker-compose.yml'], []);

        $paths = array_column($binds, 'path');
        $this->assertSame([$this->tmp . '/data', $this->tmp . '/conf'], $paths);
        $this->assertTrue($binds[0]['dir']);
        $this->assertSame('bind', $binds[0]['type']);
    }

    public function testTreatsFileLikeSourceAsFile(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n"
            . "      - ./nginx.conf:/etc/nginx/nginx.conf\n"
            . "      - ./.env:/app/.env\n"
            . "      - ./app.json:/app/config/app.json\n"
            . "      - ./.hermes:/opt/data\n");

        $binds = ComposeBinds::collect($this->tmp, ['docker-compose.yml'], []);

        $byPath = [];
        foreach ($binds as $bind) {
            $byPath[basename($bind['path'])] = $bind['dir'];
        }

        $this->assertFalse($byPath['nginx.conf'], 'nama ber-ekstensi harus dianggap file');
        $this->assertFalse($byPath['.env'], 'dotfile harus dianggap file');
        $this->assertFalse($byPath['app.json'], 'nama ber-ekstensi harus dianggap file');
        // kasus ambigu (dotdir sebagai bind service) diperlakukan konservatif → file
        $this->assertFalse($byPath['.hermes']);
    }

    public function testTreatsPlainNameSourceAsDirectory(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n"
            . "      - ./data:/data\n"
            . "      - ./conf:/etc/conf\n"
            . "      - \${PWD}/store:/store\n"
            . "      - ./uploads/:/var/uploads\n");

        $binds = ComposeBinds::collect($this->tmp, ['docker-compose.yml'], ['PWD' => $this->tmp]);

        foreach ($binds as $bind) {
            $this->assertTrue($bind['dir'], $bind['raw'] . ' harus dianggap direktori');
        }
        $this->assertCount(4, $binds);
    }

    public function testCollectsLongSyntaxBindOnly(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n"
            . "      - type: bind\n        source: ./conf\n        target: /etc/conf\n"
            . "      - type: volume\n        source: named\n        target: /var/lib/named\n");

        $binds = ComposeBinds::collect($this->tmp, ['docker-compose.yml'], []);

        $this->assertCount(1, $binds);
        $this->assertSame($this->tmp . '/conf', $binds[0]['path']);
        $this->assertTrue($binds[0]['dir']);
    }

    public function testSkipsEntriesWithUnknownVariables(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n"
            . "      - \${TIDAK_DIKENAL}/data:/data\n");

        $this->assertSame([], ComposeBinds::collect($this->tmp, ['docker-compose.yml'], []));
    }

    public function testCollectsFromMultipleComposeFilesAndSkipsMissing(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - ./a:/a\n");
        file_put_contents($this->tmp . '/extra.yml', "services:\n  db:\n    image: mariadb:11\n    volumes:\n      - ./b:/b\n");

        $paths = array_column(
            ComposeBinds::collect($this->tmp, ['docker-compose.yml', 'extra.yml', 'tidak-ada.yml'], []),
            'path'
        );

        $this->assertSame([$this->tmp . '/a', $this->tmp . '/b'], $paths);
    }

    // ==================================================================
    // ensure()
    // ==================================================================

    public function testEnsureCreatesMissingDirectories(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n"
            . "      - ./data:/var/lib/data\n"
            . "volumes:\n  hermes-data:\n    driver: local\n    driver_opts:\n"
            . "      type: none\n      device: \${PWD}/.hermes\n      o: bind\n");

        $result = ComposeBinds::ensure($this->tmp, ['docker-compose.yml'], ['PWD' => $this->tmp]);

        $this->assertDirectoryExists($this->tmp . '/data');
        $this->assertDirectoryExists($this->tmp . '/.hermes');
        // urutan: device volume dikumpulkan sebelum bind service
        $this->assertEqualsCanonicalizing([$this->tmp . '/data', $this->tmp . '/.hermes'], $result['created']);
        $this->assertSame([], $result['missing']);
    }

    public function testEnsureSubstitutesEnvVariables(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - \${DATA_DIR}/store:/store\n");

        $result = ComposeBinds::ensure($this->tmp, ['docker-compose.yml'], ['DATA_DIR' => './mnt']);

        $this->assertDirectoryExists($this->tmp . '/mnt/store');
        $this->assertSame([$this->tmp . '/mnt/store'], $result['created']);
    }

    public function testEnsureDoesNotRecreateExistingDirectory(): void
    {
        mkdir($this->tmp . '/data', 0777, true);
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - ./data:/data\n");

        $result = ComposeBinds::ensure($this->tmp, ['docker-compose.yml'], []);

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['missing']);
    }

    public function testEnsureReportsFileLikeSourceWithoutCreatingDirectory(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - ./.env:/app/.env\n");

        $result = ComposeBinds::ensure($this->tmp, ['docker-compose.yml'], []);

        $this->assertSame([], $result['created']);
        $this->assertCount(1, $result['missing']);
        $this->assertStringContainsString('.env', $result['missing'][0]);
        $this->assertStringContainsString('kemungkinan file', $result['missing'][0]);
        $this->assertDirectoryDoesNotExist($this->tmp . '/.env');
    }

    public function testEnsureReportsPathOutsideAppDirectory(): void
    {
        $outside = sys_get_temp_dir() . '/rames-binds-outside-' . bin2hex(random_bytes(4));
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - {$outside}:/data\n");

        $result = ComposeBinds::ensure($this->tmp, ['docker-compose.yml'], []);

        $this->assertSame([], $result['created']);
        $this->assertCount(1, $result['missing']);
        $this->assertStringContainsString('di luar direktori app', $result['missing'][0]);
        $this->assertDirectoryDoesNotExist($outside);
    }

    public function testEnsureRejectsParentTraversal(): void
    {
        $this->writeCompose("services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - ../shared/data:/data\n");

        $result = ComposeBinds::ensure($this->tmp, ['docker-compose.yml'], []);

        $this->assertSame([], $result['created']);
        $this->assertStringContainsString('di luar direktori app', $result['missing'][0]);
        $this->assertDirectoryDoesNotExist(dirname($this->tmp) . '/shared/data');
    }

    // ==================================================================
    // missingHint()
    // ==================================================================

    public function testMissingHintEmptyWhenNothingMissing(): void
    {
        $this->assertSame('', ComposeBinds::missingHint([]));
    }

    public function testMissingHintListsPathsAndSuggestions(): void
    {
        $hint = ComposeBinds::missingHint(['/x/.env — kemungkinan file']);

        $this->assertStringContainsString('Bind mount di compose belum siap', $hint);
        $this->assertStringContainsString('/x/.env', $hint);
        $this->assertStringContainsString('unggah filenya lewat tab Compose', $hint);
    }

    private function writeCompose(string $yaml): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', $yaml);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
