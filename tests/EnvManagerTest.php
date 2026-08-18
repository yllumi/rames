<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit test EnvManager — menulis managed env file (dotenv), override YAML
 * (inject ke semua service), parse .env.example, dan sync/remove.
 */
class EnvManagerTest extends TestCase
{
    private string $tmp;
    private string $envDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/envmgr_' . bin2hex(random_bytes(4));
        $this->envDir = $this->tmp . '/env';
        mkdir($this->tmp, 0777, true);
        mkdir($this->envDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testWriteManagedFileWithDotenvFormat(): void
    {
        $m = new EnvManager($this->envDir);
        $m->write('myapp', [
            'DB_HOST' => '127.0.0.1',
            'DB_PASS' => 'pa$$ word',
            'EMPTY' => '',
            'PLAIN' => 'just-a-value_123',
        ]);

        $path = $this->envDir . '/myapp.env';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $content);
        $this->assertStringContainsString("DB_PASS='pa\$\$ word'", $content);
        $this->assertStringContainsString("EMPTY=''", $content);
        $this->assertStringContainsString('PLAIN=just-a-value_123', $content);
    }

    public function testRemoveManagedFile(): void
    {
        $m = new EnvManager($this->envDir);
        $m->write('myapp', ['A' => '1']);
        $this->assertFileExists($this->envDir . '/myapp.env');

        $m->remove('myapp');
        $this->assertFileDoesNotExist($this->envDir . '/myapp.env');
    }

    public function testParseEnvExample(): void
    {
        $dir = $this->tmp . '/site';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.env.example', "# contoh\nAPP_KEY=base64:abc\nDB_HOST='localhost'\n\nKOSONG=\n# komentar\ndb_port=\"5432\"\n");

        $m = new EnvManager($this->envDir);
        $this->assertSame([
            'APP_KEY' => 'base64:abc',
            'DB_HOST' => 'localhost',
            'KOSONG' => '',
            'db_port' => '5432',
        ], $m->parseEnvExample($dir));
    }

    public function testParseEnvExampleMissingFileReturnsEmpty(): void
    {
        $m = new EnvManager($this->envDir);
        $this->assertSame([], $m->parseEnvExample($this->tmp . '/tidak-ada'));
    }

    public function testWriteOverrideInjectsToAllServices(): void
    {
        $dir = $this->tmp . '/site';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx\n  worker:\n    image: busybox\n");

        $m = new EnvManager($this->envDir);
        $m->writeOverride($dir, ['docker-compose.yml'], ['DB_HOST' => '127.0.0.1', 'SECRET' => 'a b']);

        $this->assertFileExists($dir . '/' . EnvManager::OVERRIDE_FILE);
        $content = (string) file_get_contents($dir . '/' . EnvManager::OVERRIDE_FILE);
        $this->assertStringContainsString('web:', $content);
        $this->assertStringContainsString('worker:', $content);
        // 127.0.0.1 aman tanpa quote (YAML: string valid); nilai berspasi di-quote
        $this->assertStringContainsString('DB_HOST: 127.0.0.1', $content);
        $this->assertStringContainsString("SECRET: 'a b'", $content);
    }

    public function testSyncActiveWritesFiles(): void
    {
        $dir = $this->tmp . '/site';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx\n");

        $m = new EnvManager($this->envDir);
        $active = $m->sync(['name' => 'myapp', 'env' => ['A' => '1']], $dir, ['docker-compose.yml']);

        $this->assertTrue($active);
        $this->assertFileExists($this->envDir . '/myapp.env');
        $this->assertFileExists($dir . '/' . EnvManager::OVERRIDE_FILE);
    }

    public function testSyncEmptyRemovesFiles(): void
    {
        $dir = $this->tmp . '/site';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx\n");

        $m = new EnvManager($this->envDir);
        $m->sync(['name' => 'myapp', 'env' => ['A' => '1']], $dir, ['docker-compose.yml']);
        $this->assertFileExists($this->envDir . '/myapp.env');

        $active = $m->sync(['name' => 'myapp', 'env' => []], $dir, ['docker-compose.yml']);
        $this->assertFalse($active);
        $this->assertFileDoesNotExist($this->envDir . '/myapp.env');
        $this->assertFileDoesNotExist($dir . '/' . EnvManager::OVERRIDE_FILE);
    }

    public function testSyncIgnoresInvalidKeys(): void
    {
        $dir = $this->tmp . '/site';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx\n");

        $m = new EnvManager($this->envDir);
        $active = $m->sync(['name' => 'myapp', 'env' => ['BAD-KEY' => 'x', 'GOOD_KEY' => 'y']], $dir, ['docker-compose.yml']);

        $this->assertTrue($active);
        $content = (string) file_get_contents($this->envDir . '/myapp.env');
        $this->assertStringContainsString('GOOD_KEY=y', $content);
        $this->assertStringNotContainsString('BAD-KEY', $content);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
