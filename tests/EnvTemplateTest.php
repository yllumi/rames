<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvTemplate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test EnvTemplate — deteksi variabel environment untuk wizard create:
 * prioritas `.env.example`, fallback ke referensi ${VAR} docker compose, dan
 * validasi isian form (`resolveValues`).
 */
class EnvTemplateTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/envtpl_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmp);
    }

    public function testEnvExampleMenjadiSumberUtama(): void
    {
        file_put_contents($this->tmp . '/.env.example', "# komentar\nAPP_NAME=Demo\nDB_HOST=\nDB_PASS='rahasia'\n");
        file_put_contents($this->tmp . '/docker-compose.yml', "services:\n  web:\n    image: nginx:\${TAG:-alpine}\n");

        $tpl = EnvTemplate::detect($this->tmp);

        $this->assertSame(EnvTemplate::SOURCE_ENV_EXAMPLE, $tpl['source']);
        $this->assertSame(
            [
                ['key' => 'APP_NAME', 'value' => 'Demo', 'default' => null, 'required' => false],
                ['key' => 'DB_HOST', 'value' => '', 'default' => null, 'required' => false],
                ['key' => 'DB_PASS', 'value' => 'rahasia', 'default' => null, 'required' => false],
            ],
            $tpl['vars']
        );
    }

    public function testEnvExampleDiperkayaSyaratDariCompose(): void
    {
        file_put_contents($this->tmp . '/.env.example', "DB_HOST=\nDB_PASS=contoh\nPWD=/tmp\n");
        file_put_contents($this->tmp . '/docker-compose.yml', implode("\n", [
            'services:',
            '  db:',
            '    image: postgres:${PG_VERSION:-16}',
            '    environment:',
            '      DB_HOST: ${DB_HOST}',
            '      DB_PASS: ${DB_PASS}',
            '      APP_KEY: ${APP_KEY:?wajib}',
            '      OPTIONAL: ${OPTIONAL:-x}',
        ]));

        $tpl = EnvTemplate::detect($this->tmp);
        $byKey = [];
        foreach ($tpl['vars'] as $var) {
            $byKey[$var['key']] = $var;
        }

        $this->assertSame(EnvTemplate::SOURCE_ENV_EXAMPLE, $tpl['source']);
        $this->assertTrue($byKey['DB_HOST']['required'], 'contoh kosong + compose wajib ⇒ wajib');
        $this->assertFalse($byKey['DB_PASS']['required'], 'contoh terisi ⇒ tidak dipaksa wajib');
        $this->assertTrue($byKey['APP_KEY']['required'], 'kunci wajib yang tidak ada di contoh ikut ditambahkan');
        $this->assertSame([], array_diff(array_keys($byKey), ['DB_HOST', 'DB_PASS', 'APP_KEY']), 'kunci opsional compose tidak ikut ditambahkan');
        $this->assertArrayNotHasKey('PWD', $byKey, 'variabel reserved tidak dirender');
    }

    public function testFallbackKeComposeDenganDefaultDanWajib(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', implode("\n", [
            'services:',
            '  web:',
            '    image: "app:${TAG:-latest}"',
            '    environment:',
            '      DB_HOST: ${DB_HOST:-localhost}',
            '      DB_PASSWORD: ${DB_PASSWORD}',
            '      API_KEY: ${API_KEY:?API_KEY wajib diisi}',
            '      OPTIONAL: ${OPTIONAL:+ada}',
            '      LEGACY: $LEGACY_VAR',
            '      LITERAL: "$$NOT_A_VAR"',
        ]));

        $tpl = EnvTemplate::detect($this->tmp);

        $this->assertSame(EnvTemplate::SOURCE_COMPOSE, $tpl['source']);
        $byKey = [];
        foreach ($tpl['vars'] as $var) {
            $byKey[$var['key']] = $var;
        }

        $this->assertSame(['TAG', 'DB_HOST', 'DB_PASSWORD', 'API_KEY', 'OPTIONAL', 'LEGACY_VAR'], array_keys($byKey));
        $this->assertSame('latest', $byKey['TAG']['default']);
        $this->assertFalse($byKey['TAG']['required']);
        $this->assertSame('localhost', $byKey['DB_HOST']['default']);
        $this->assertFalse($byKey['DB_HOST']['required']);
        $this->assertNull($byKey['DB_PASSWORD']['default']);
        $this->assertTrue($byKey['DB_PASSWORD']['required']);
        $this->assertNull($byKey['API_KEY']['default']);
        $this->assertTrue($byKey['API_KEY']['required']);
        $this->assertNull($byKey['OPTIONAL']['default']);
        $this->assertFalse($byKey['OPTIONAL']['required']);
        $this->assertTrue($byKey['LEGACY_VAR']['required']);
        $this->assertArrayNotHasKey('NOT_A_VAR', $byKey);
    }

    public function testKemunculanKeduaDenganDefaultMenurunkanStatusWajib(): void
    {
        $vars = EnvTemplate::fromCompose("a: \${DB_HOST}\nb: \${DB_HOST:-127.0.0.1}\n");

        $this->assertCount(1, $vars);
        $this->assertSame('DB_HOST', $vars[0]['key']);
        $this->assertSame('127.0.0.1', $vars[0]['default']);
        $this->assertFalse($vars[0]['required']);
    }

    public function testKomentarComposeTidakDianggapVariabel(): void
    {
        $vars = EnvTemplate::fromCompose(implode("\n", [
            '# dokumentasi: $_ENV kosong di worker',
            'services:',
            '  web:',
            '    image: nginx  # catatan $CATATAN',
            '    environment:',
            '      DB_HOST: ${DB_HOST:-db}',
        ]));

        $this->assertSame(['DB_HOST'], array_column($vars, 'key'));
    }

    public function testYamlRusakTetapMemakaiFallbackTanpaKomentar(): void
    {
        $vars = EnvTemplate::fromCompose("# \$FAKE jangan terdeteksi\nservices: [\n");

        $this->assertSame([], $vars);
    }

    public function testTanpaEnvExampleDanTanpaVariabelCompose(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");

        $tpl = EnvTemplate::detect($this->tmp);

        $this->assertSame(EnvTemplate::SOURCE_NONE, $tpl['source']);
        $this->assertSame([], $tpl['vars']);
    }

    public function testComposeFileYangTidakAdaDiabaikan(): void
    {
        $this->assertSame([], EnvTemplate::fromComposeFiles($this->tmp, ['tidak-ada.yml', '../keluar.yml']));
    }

    public function testVariabelYangDisediakanDashboardDilewati(): void
    {
        $vars = EnvTemplate::fromCompose(implode("\n", [
            'services:',
            '  web:',
            '    image: nginx',
            '    volumes:',
            '      - ${PWD}/data:/data',
            '    environment:',
            '      NAME: ${COMPOSE_PROJECT_NAME:-app}',
            '      DB_HOST: ${DB_HOST}',
        ]));

        $this->assertSame(['DB_HOST'], array_column($vars, 'key'));
    }

    public function testResolveValuesMenolakVariabelReserved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dikelola dashboard');
        EnvTemplate::resolveValues(['PWD' => '/tmp'], []);
    }

    public function testResolveValuesMengembalikanMapBersih(): void
    {
        $template = EnvTemplate::detect($this->tmp);

        $this->assertSame(
            ['DB_HOST' => 'db', 'DB_PASSWORD' => 's3cret', 'EMPTY' => ''],
            EnvTemplate::resolveValues([
                'DB_HOST' => 'db',
                'DB_PASSWORD' => 's3cret',
                'EMPTY' => '',
                '  ' => 'diabaikan',
            ], $template)
        );
    }

    public function testResolveValuesMenolakNamaTidakValid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama variabel environment tidak valid');
        EnvTemplate::resolveValues(['DB-HOST' => 'x'], []);
    }

    public function testResolveValuesMenolakNilaiMultiBaris(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak boleh mengandung baris baru');
        EnvTemplate::resolveValues(['DB_PASSWORD' => "a\nb"], []);
    }

    public function testResolveValuesMenolakNilaiTerlaluPanjang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terlalu panjang');
        EnvTemplate::resolveValues(['LONG' => str_repeat('x', EnvTemplate::MAX_VALUE_LENGTH + 1)], []);
    }

    public function testResolveValuesMewajibkanVariabelTanpaDefault(): void
    {
        $template = ['source' => EnvTemplate::SOURCE_COMPOSE, 'vars' => [
            ['key' => 'DB_PASSWORD', 'value' => '', 'default' => null, 'required' => true],
        ]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nilai wajib diisi: DB_PASSWORD');
        EnvTemplate::resolveValues(['DB_PASSWORD' => '  '], $template);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
