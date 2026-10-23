<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $dir = $this->tmp . '/app';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.env.example', "# contoh\nAPP_KEY=base64:abc\nDB_HOST='localhost'\n\nKOSONG=\n# komentar\ndb_port=\"5432\"\n");

        $this->assertSame([
            'APP_KEY' => 'base64:abc',
            'DB_HOST' => 'localhost',
            'KOSONG' => '',
            'db_port' => '5432',
        ], EnvManager::parseEnvExample($dir));
    }

    public function testParseEnvExampleMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], EnvManager::parseEnvExample($this->tmp . '/tidak-ada'));
    }

    public function testWriteOverrideInjectsToAllServices(): void
    {
        $dir = $this->tmp . '/app';
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
        $dir = $this->tmp . '/app';
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
        $dir = $this->tmp . '/app';
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
        $dir = $this->tmp . '/app';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx\n");

        $m = new EnvManager($this->envDir);
        $active = $m->sync(['name' => 'myapp', 'env' => ['BAD-KEY' => 'x', 'GOOD_KEY' => 'y']], $dir, ['docker-compose.yml']);

        $this->assertTrue($active);
        $content = (string) file_get_contents($this->envDir . '/myapp.env');
        $this->assertStringContainsString('GOOD_KEY=y', $content);
        $this->assertStringNotContainsString('BAD-KEY', $content);
    }

    // ==================================================================
    // parseEnvContent() — parser strict untuk file .env hasil upload
    // ==================================================================

    public function testParseEnvContentHandlesFullDotenvSyntax(): void
    {
        $m = new EnvManager($this->envDir);
        $content = <<<'ENV'
# komentar

  # komentar setelah spasi
export DB_HOST='localhost'
APP_KEY=base64:abc
EMPTY=
SPACED=   nilai dengan spasi
HASH=a#b
COMMENT=abc # komentar inline
QUOTED="a \"b\" c"
TABBED="kolom\tsatu"
SINGLE='literal $HOME dan # hash'
COMMENTED='x' # komentar setelah quote
KEEP="a\qb"
LAST=1
ENV;

        $this->assertSame([
            'DB_HOST' => 'localhost',
            'APP_KEY' => 'base64:abc',
            'EMPTY' => '',
            'SPACED' => 'nilai dengan spasi',
            'HASH' => 'a#b',
            'COMMENT' => 'abc',
            'QUOTED' => 'a "b" c',
            'TABBED' => "kolom\tsatu",
            'SINGLE' => 'literal $HOME dan # hash',
            'COMMENTED' => 'x',
            'KEEP' => 'a\\qb',
            'LAST' => '1',
        ], $m->parseEnvContent($content));
    }

    public function testParseEnvContentAcceptsBomAndCrlf(): void
    {
        $m = new EnvManager($this->envDir);
        $content = "\xEF\xBB\xBFA=1\r\nB=dua\r\n\r\n# komentar\r\nC=\r\n";

        $this->assertSame(['A' => '1', 'B' => 'dua', 'C' => ''], $m->parseEnvContent($content));
    }

    public function testParseEnvContentAcceptsEmptyContent(): void
    {
        $m = new EnvManager($this->envDir);

        $this->assertSame([], $m->parseEnvContent(''));
        $this->assertSame([], $m->parseEnvContent("# hanya komentar\n\n"));
    }

    public function testParseEnvContentRejectsLineWithoutEqualsWithLineNumber(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baris 2:');
        $m->parseEnvContent("A=1\nTANPA_SAMA_DENGAN\n");
    }

    public function testParseEnvContentRejectsInvalidKeyWithLineNumber(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baris 1:');
        $m->parseEnvContent("1BAD=x\n");
    }

    public function testParseEnvContentRejectsDuplicateKeyAndNamesBothLines(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('baris 1 dan 3');
        $m->parseEnvContent("A=satu\nB=dua\nA=tiga\n");
    }

    public function testParseEnvContentRejectsOversizedContent(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maks 256 KiB');
        $m->parseEnvContent(str_repeat('A', EnvManager::MAX_UPLOAD_BYTES + 1));
    }

    public function testParseEnvContentRejectsBinaryAndNulBytes(): void
    {
        $m = new EnvManager($this->envDir);

        try {
            $m->parseEnvContent("A=1\0B=2");
            $this->fail('NUL byte harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('biner', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('biner');
        $m->parseEnvContent("\xFF\xFE\x00\x01");
    }

    public function testParseEnvContentRejectsNewlineEscapeInDoubleQuotes(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('baris baru');
        $m->parseEnvContent("MULTI=\"satu\\ndua\"\n");
    }

    public function testParseEnvContentRejectsCarriageReturnEscapeInDoubleQuotes(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('baris baru');
        $m->parseEnvContent("MULTI=\"satu\\rdua\"\n");
    }

    public function testParseEnvContentRejectsC0ControlCharactersExceptTab(): void
    {
        $m = new EnvManager($this->envDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('karakter kontrol');
        $m->parseEnvContent("A=v\x01w\n");
    }

    public function testParseEnvContentKeepsTabCharacter(): void
    {
        $m = new EnvManager($this->envDir);

        $this->assertSame(['A' => "v\tw"], $m->parseEnvContent("A=\"v\\tw\"\n"));
    }

    public function testParseEnvContentErrorMessagesNeverLeakValues(): void
    {
        $m = new EnvManager($this->envDir);

        try {
            $m->parseEnvContent("SECRET=\"rahasia\\nsekali\"\n");
            $this->fail('escape \\n harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('rahasia', $e->getMessage());
        }
    }

    // ==================================================================
    // mergeUploaded() — penggabungan murni (merge vs replace)
    // ==================================================================

    public function testMergeUploadedOverlaysExistingAndAppendsNewKeys(): void
    {
        $existing = ['A' => '1', 'B' => '2', 'BAD-KEY' => 'x', 'ANGKA' => 5];
        $uploaded = ['B' => '9', 'C' => '3'];

        $merged = EnvManager::mergeUploaded($existing, $uploaded, false);

        // Urutan lama dipertahankan, kunci baru di akhir; kunci tidak valid &
        // value non-string dari apps.json dibuang.
        $this->assertSame(['A' => '1', 'B' => '9', 'C' => '3'], $merged);
        $this->assertSame(['A', 'B', 'C'], array_keys($merged));
    }

    public function testMergeUploadedReplaceDropsOldKeys(): void
    {
        $existing = ['A' => '1', 'B' => '2'];

        $this->assertSame(['B' => '9', 'C' => '3'], EnvManager::mergeUploaded($existing, ['B' => '9', 'C' => '3'], true));
        $this->assertSame([], EnvManager::mergeUploaded($existing, [], true));
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
