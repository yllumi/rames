<?php
declare(strict_types=1);

namespace Tests;

use app\controller\app\AppArtifactControllerBase;
use app\controller\app\ComposeController;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\PortOverrides;
use app\library\Docker\ComposeParser;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\StoreDbFiles;
use Webman\Config;

/**
 * Unit test `PortOverrides` — satu-satunya pemilik render & tulis dua lapis
 * override port (`docker-compose.override.yml` + `…override.ports.yml`).
 *
 * Yang dikunci:
 *  1. `rows()` membaca sumber berurutan: metadata `host_ports` → berkas override
 *     port di disk (**digabung** service baru dari base compose `git pull`) →
 *     base compose; entri non-sederhana (port range, IP bind) tetap tampil
 *     tetapi `editable=false` + `raw` aslinya;
 *  2. protokol port tidak hilang: `ComposeParser` mempertahankan `/udp`,
 *     `rowsFromMetadata()` membaca `protocol`, `persistMetadata()` menulisnya
 *     hanya untuk non-tcp;
 *  3. `render()` menghasilkan YAML **identik** dengan jalur lama
 *     `AppArtifactControllerBase::writeOverride()` (termasuk `services: {  }` saat kosong);
 *  4. `plan()` fail-fast untuk port container di luar rentang, pemetaan
 *     (host, container) duplikat, host/service asing, baris tanpa host port,
 *     dan baris non-editable yang dipaksa berubah — **host port tidak pernah
 *     diubah** (identitas form = host port);
 *  5. `write()` menulis/menghapus kedua berkas; `persist()`/
 *     `persistMetadata()` menyusun `compose_files` kanonik (lokal vs agent) dan
 *     memperbarui `primary_port` bila mapping yang di-proxy berganti port
 *     container.
 *
 * Semua uji memakai direktori & basis data temp — **tidak** menyentuh
 * `database/`, `apps/`, atau `nginx-status/` nyata dan tidak menjalankan docker.
 */
class PortOverridesTest extends TestCase
{
    private string $tmp = '';

    private string $dbFile = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-portoverrides-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException('gagal membuat direktori temp: ' . $this->tmp);
        }
        $this->dbFile = $this->tmp . '/apps.sqlite';
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->dbFile);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // rows()
    // ==================================================================

    public function testRowsReadsBaseComposeAndFlagsNonSimpleEntriesAsLocked(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
            ports:
              - "8080:80"
              - "443:443"
              - "53/udp"
          db:
            image: postgres
            ports:
              - "5432"
              - "8000-8010:8000-8010"
              - "127.0.0.1:9000:90"
        YAML);

        $rows = PortOverrides::rows(['compose_files' => ['docker-compose.yml']], $this->tmp);

        $this->assertSame(
            [
                ['service' => 'web', 'container' => 80, 'host' => 8080, 'protocol' => 'tcp', 'raw' => '8080:80', 'editable' => true],
                ['service' => 'web', 'container' => 443, 'host' => 443, 'protocol' => 'tcp', 'raw' => '443:443', 'editable' => true],
                ['service' => 'web', 'container' => 53, 'host' => null, 'protocol' => 'udp', 'raw' => '53/udp', 'editable' => true],
                ['service' => 'db', 'container' => 5432, 'host' => null, 'protocol' => 'tcp', 'raw' => '5432', 'editable' => true],
                ['service' => 'db', 'container' => 0, 'host' => null, 'protocol' => 'tcp', 'raw' => '8000-8010:8000-8010', 'editable' => false],
                ['service' => 'db', 'container' => 90, 'host' => 9000, 'protocol' => 'tcp', 'raw' => '127.0.0.1:9000:90', 'editable' => false],
            ],
            $rows
        );
    }

    public function testRowsPrefersOverridePortsFileOverBaseCompose(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
            ports:
              - "8080:80"
        YAML);
        file_put_contents($this->tmp . '/' . ComposeSource::PORTS_OVERRIDE_FILE, <<<'YAML'
        services:
          web:
            ports:
              - "9090:80"
        YAML);

        $rows = PortOverrides::rows(['compose_files' => ['docker-compose.yml']], $this->tmp);

        $this->assertCount(1, $rows);
        $this->assertSame(9090, $rows[0]['host'], 'berkas override port yang berlaku menang atas base');
        $this->assertSame(80, $rows[0]['container']);
    }

    public function testRowsMergesNewBaseComposeServicesIntoOverrideRows(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
            ports:
              - "8080:80"
          api:
            image: api
            ports:
              - "9000:9000"
        YAML);
        file_put_contents($this->tmp . '/' . ComposeSource::PORTS_OVERRIDE_FILE, <<<'YAML'
        services:
          web:
            ports:
              - "9090:80"
        YAML);

        $rows = PortOverrides::rows(['compose_files' => ['docker-compose.yml']], $this->tmp);

        $this->assertSame(['web', 'api'], array_column($rows, 'service'), 'override dulu, lalu service baru dari base');
        $this->assertSame(9090, $rows[0]['host'], 'service yang ada di override memakai port override');
        $this->assertSame('web', $rows[0]['service'], 'service web tidak terduplikasi dari base');
        $this->assertSame(9000, $rows[1]['container']);
        $this->assertSame(9000, $rows[1]['host'], 'service baru dari base tetap tampil (mis. setelah git pull)');
    }

    /**
     * `3000/tcp` dan `3000/udp` adalah dua port berbeda; baris tidak boleh
     * saling menghapus saat dedupe.
     */
    public function testRowsKeepsSameContainerPortWithDifferentProtocols(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', <<<'YAML'
        services:
          dns:
            image: coredns
            ports:
              - "53"
              - "53/udp"
        YAML);

        $rows = PortOverrides::rows(['compose_files' => ['docker-compose.yml']], $this->tmp);

        $this->assertSame(['tcp', 'udp'], array_column($rows, 'protocol'));
    }

    public function testRowsPrefersHostPortsMetadataEvenWithoutDirectory(): void
    {
        $app = [
            'compose_files' => ['docker-compose.yml'],
            'host_ports' => [
                ['service' => 'web', 'container' => 80, 'host' => 31000],
                ['service' => 'api', 'container' => 8642, 'host' => null],
            ],
        ];

        $rows = PortOverrides::rows($app);

        $this->assertSame(
            [
                ['service' => 'web', 'container' => 80, 'host' => 31000, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
                ['service' => 'api', 'container' => 8642, 'host' => null, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ],
            $rows
        );
    }

    /**
     * Metadata `host_ports` boleh memuat `protocol` (port UDP app agent). Entri
     * lama tanpa `protocol` = `tcp`, dan nilai tak dikenal ditoleransi sebagai
     * `tcp` (metadata lokal; validasi ketat ada di packager).
     */
    public function testRowsFromMetadataReadsProtocolTolerantly(): void
    {
        $rows = PortOverrides::rows([
            'host_ports' => [
                ['service' => 'dns', 'container' => 53, 'host' => 3053, 'protocol' => 'udp'],
                ['service' => 'web', 'container' => 80, 'host' => 3080],
                ['service' => 'ftp', 'container' => 21, 'host' => 3021, 'protocol' => 'icmp'],
                ['service' => 'tls', 'container' => 853, 'host' => null, 'protocol' => 'SCTP'],
            ],
        ]);

        $this->assertSame(['udp', 'tcp', 'tcp', 'sctp'], array_column($rows, 'protocol'));
        $this->assertSame(3053, $rows[0]['host']);
        $this->assertNull($rows[3]['host'], 'port exposed-only tetap tanpa host');
    }

    public function testRowsWithoutAnySourceIsEmpty(): void
    {
        $this->assertSame([], PortOverrides::rows(['compose_files' => ['docker-compose.yml']], $this->tmp));
        $this->assertSame([], PortOverrides::rows([]));
    }

    // ==================================================================
    // render()
    // ==================================================================

    public function testRenderWritesResetLayerAndPortsLayer(): void
    {
        $rendered = PortOverrides::render([
            ['service' => 'web', 'container' => 80, 'host' => 8080, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ['service' => 'web', 'container' => 443, 'host' => null, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ['service' => 'api', 'container' => 53, 'host' => 5353, 'protocol' => 'udp', 'raw' => null, 'editable' => true],
        ]);

        $reset = Yaml::parse($rendered['reset'], Yaml::PARSE_CUSTOM_TAGS);
        $this->assertInstanceOf(TaggedValue::class, $reset['services']['web']['ports']);
        $this->assertSame('reset', $reset['services']['web']['ports']->getTag());
        $this->assertArrayHasKey('api', $reset['services'], 'setiap service ber-port ikut di-reset');

        $ports = Yaml::parse($rendered['ports']);
        $this->assertSame(['8080:80', '443'], $ports['services']['web']['ports']);
        $this->assertSame(['5353:53/udp'], $ports['services']['api']['ports'], 'sufiks /proto hanya untuk non-tcp');
    }

    public function testRenderKeepsRawEntryOfNonEditableRow(): void
    {
        $rendered = PortOverrides::render([
            ['service' => 'web', 'container' => 0, 'host' => null, 'protocol' => 'tcp', 'raw' => '8000-8010:8000-8010', 'editable' => false],
            ['service' => 'web', 'container' => 90, 'host' => 9000, 'protocol' => 'tcp', 'raw' => '127.0.0.1:9000:90', 'editable' => false],
        ]);

        $ports = Yaml::parse($rendered['ports']);
        $this->assertSame(
            ['8000-8010:8000-8010', '127.0.0.1:9000:90'],
            $ports['services']['web']['ports'],
            'entri non-sederhana ditulis apa adanya (jangan dinormalkan)'
        );
    }

    public function testRenderOfEmptyPlanMatchesLegacyShape(): void
    {
        $rendered = PortOverrides::render([]);

        $this->assertSame("services: {  }\n", $rendered['reset']);
        $this->assertSame("services: {  }\n", $rendered['ports']);
    }

    // ==================================================================
    // plan()
    // ==================================================================

    /**
     * Baris uji baku: identitas form = **host port**.
     *
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    private function rows(): array
    {
        return [
            ['service' => 'web', 'container' => 80, 'host' => 31500, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ['service' => 'db', 'container' => 5432, 'host' => 5432, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ['service' => 'ssr', 'container' => 90, 'host' => 9000, 'protocol' => 'tcp', 'raw' => '127.0.0.1:9000:90', 'editable' => false],
            ['service' => 'metrics', 'container' => 8642, 'host' => null, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
        ];
    }

    /**
     * Edit valid: `ports['web'][31500] = '8080'` ⇒ port container 8080, host
     * port tetap 31500 (target `proxy_pass` tidak boleh bergeser).
     */
    public function testPlanEditsContainerPortAndKeepsHost(): void
    {
        $plan = PortOverrides::plan($this->rows(), ['web' => [31500 => '8080']], []);

        $this->assertSame(
            ['service' => 'web', 'container' => 8080, 'host' => 31500, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            $plan[0],
            'yang berubah hanya port container; host tetap'
        );
        $this->assertSame(5432, $plan[1]['container'], 'baris lain tidak tersentuh');
        $this->assertSame(5432, $plan[1]['host'], 'host port lain tidak berubah');
        $this->assertSame(90, $plan[2]['container'], 'baris non-editable tidak tersentuh');
        $this->assertSame('127.0.0.1:9000:90', $plan[2]['raw']);
    }

    public function testPlanKeepsContainerPortWhenValueIsEmpty(): void
    {
        $plan = PortOverrides::plan($this->rows(), ['web' => [31500 => ''], 'db' => [5432 => null]], []);

        $this->assertSame(80, $plan[0]['container'], 'nilai kosong = pertahankan port container lama');
        $this->assertSame(31500, $plan[0]['host']);
        $this->assertSame(5432, $plan[1]['container'], 'nilai null juga dipertahankan');
    }

    public function testPlanRejectsNonNumericContainerPort(): void
    {
        try {
            PortOverrides::plan($this->rows(), ['web' => [31500 => 'abc']], []);
            $this->fail('port container non-numerik harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('abc', $e->getMessage());
            $this->assertStringContainsString('web', $e->getMessage());
            $this->assertStringContainsString('31500', $e->getMessage());
        }
    }

    /**
     * @dataProvider outOfRangeContainers
     */
    public function testPlanRejectsContainerPortOutOfRange(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/di luar rentang|tidak sah/');

        PortOverrides::plan($this->rows(), ['web' => [31500 => $value]], []);
    }

    /** @return array<string,array{0:string}> */
    public static function outOfRangeContainers(): array
    {
        return ['nol' => ['0'], 'terlalu besar' => ['70000']];
    }

    public function testPlanRejectsUnknownServiceOrHost(): void
    {
        try {
            PortOverrides::plan($this->rows(), ['web' => [9999 => '8080']], []);
            $this->fail('host asing harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('9999', $e->getMessage());
            $this->assertStringContainsString('web', $e->getMessage());
            $this->assertStringContainsString('tidak dikenal', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hantu');

        PortOverrides::plan($this->rows(), ['hantu' => [31500 => '8080']], []);
    }

    /**
     * Dua host port berbeda boleh memakai port container yang sama
     * (`8080:80` + `8081:80`) — Docker mengizinkannya.
     */
    public function testPlanAllowsSameContainerPortOnDifferentHosts(): void
    {
        $rows = [
            ['service' => 'web', 'container' => 80, 'host' => 31500, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ['service' => 'api', 'container' => 8080, 'host' => 31501, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
        ];

        $plan = PortOverrides::plan($rows, ['api' => [31501 => '80']], []);

        $this->assertSame([80, 80], array_column($plan, 'container'));
        $this->assertSame([31500, 31501], array_column($plan, 'host'));
    }

    /** Pemetaan (host, port container) identik dua kali ⇒ ditolak. */
    public function testPlanRejectsDuplicateMapping(): void
    {
        $rows = [
            ['service' => 'web', 'container' => 80, 'host' => 31500, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
            ['service' => 'worker', 'container' => 9090, 'host' => 31500, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
        ];

        try {
            PortOverrides::plan($rows, ['worker' => [31500 => '80']], []);
            $this->fail('pemetaan (host, container) duplikat harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('31500', $e->getMessage());
            $this->assertStringContainsString('lebih dari sekali', $e->getMessage());
        }
    }

    /**
     * Baris `host === null` hanya diekspos di jaringan container ⇒ tidak punya
     * identitas host, jadi tidak bisa dialamatkan input.
     */
    public function testPlanRejectsEditOfExposedOnlyRow(): void
    {
        try {
            PortOverrides::plan($this->rows(), ['metrics' => [8642 => '8080']], []);
            $this->fail('baris tanpa host port tidak boleh diubah');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('metrics', $e->getMessage());
            $this->assertStringContainsString('8642', $e->getMessage());
            $this->assertStringContainsString('tidak bisa diubah', $e->getMessage());
        }
    }

    public function testPlanRejectsChangeToNonEditableRow(): void
    {
        try {
            PortOverrides::plan($this->rows(), ['ssr' => [9000 => '91']], []);
            $this->fail('baris non-editable tidak boleh diubah');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('127.0.0.1:9000:90', $e->getMessage());
            $this->assertStringContainsString('9000', $e->getMessage());
            $this->assertStringContainsString('tidak bisa diubah', $e->getMessage());
        }
    }

    /** Nilai sama dengan port container baris non-editable = no-op, bukan error. */
    public function testPlanAcceptsUnchangedNonEditableRow(): void
    {
        $plan = PortOverrides::plan($this->rows(), ['ssr' => [9000 => '90']], []);

        $this->assertSame(90, $plan[2]['container']);
        $this->assertFalse($plan[2]['editable']);
    }

    /**
     * `$usedPorts` (host port app lain) **tidak** lagi menolak apa pun: host
     * port tidak pernah berubah oleh operasi ini.
     */
    public function testPlanIgnoresUsedHostPorts(): void
    {
        $plan = PortOverrides::plan($this->rows(), ['web' => [31500 => '8080']], [31500, 5432]);

        $this->assertSame(31500, $plan[0]['host']);
        $this->assertSame(8080, $plan[0]['container']);
    }

    public function testPlanRejectsScalarServicePayload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Format port tidak valid');

        PortOverrides::plan($this->rows(), ['web' => '8080'], []);
    }

    // ==================================================================
    // write()
    // ==================================================================

    public function testWriteCreatesBothLayersAndEmptyPlanRemovesThem(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir, 0777, true);

        $this->assertTrue(PortOverrides::write($dir, $this->rows()));

        $reset = $dir . '/' . ComposeSource::RESET_OVERRIDE_FILE;
        $ports = $dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE;
        $this->assertFileExists($reset);
        $this->assertFileExists($ports);
        $this->assertStringContainsString('!reset', (string) file_get_contents($reset));
        $this->assertStringContainsString("'31500:80'", (string) file_get_contents($ports));

        $this->assertFalse(PortOverrides::write($dir, []), 'rencana kosong ⇒ kedua berkas dihapus');
        $this->assertFileDoesNotExist($reset);
        $this->assertFileDoesNotExist($ports);
    }

    /**
     * Regresi bentuk entri: host tetap di kiri (`[host:]container[/proto]`),
     * sufiks `/proto` hanya untuk non-tcp — port container hasil edit yang
     * berada di kanan.
     */
    public function testWriteKeepsHostOnTheLeftAfterContainerEdit(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir, 0777, true);

        $plan = PortOverrides::plan(
            [
                ['service' => 'web', 'container' => 80, 'host' => 31500, 'protocol' => 'tcp', 'raw' => null, 'editable' => true],
                ['service' => 'dns', 'container' => 53, 'host' => 31501, 'protocol' => 'udp', 'raw' => null, 'editable' => true],
            ],
            ['web' => [31500 => '8080'], 'dns' => [31501 => '5353']],
            []
        );
        $this->assertTrue(PortOverrides::write($dir, $plan));

        $parsed = Yaml::parse((string) file_get_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE));
        $this->assertSame(['31500:8080'], $parsed['services']['web']['ports']);
        $this->assertSame(['31501:5353/udp'], $parsed['services']['dns']['ports'], 'sufiks /proto dipertahankan');
    }

    // ==================================================================
    // persist() / persistMetadata()
    // ==================================================================

    public function testPersistWritesFilesAndOrdersComposeFiles(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/docker-compose.yml', <<<'YAML'
        services:
          web:
            image: nginx
            ports:
              - "31000:80"
        YAML);

        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'demo',
            'compose_files' => ['docker-compose.yml', 'docker-compose.override.networks.yml'],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $result = PortOverrides::persist($store, $app, $dir, ['web' => [31000 => '8080']], []);

        $this->assertSame(31000, $result['rows'][0]['host'], 'host port tetap');
        $this->assertSame(8080, $result['rows'][0]['container'], 'port container hasil edit');
        $this->assertSame(
            ['docker-compose.yml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE, 'docker-compose.override.networks.yml'],
            $result['app']['compose_files'],
            'base → reset → ports → sisa override generated (urutan kanonik)'
        );
        $this->assertFileExists($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE);
        $this->assertStringContainsString('31000:8080', (string) file_get_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE));

        // Idempoten: simpan ulang tidak menduplikasi entri compose_files.
        // Baris kini dibaca dari berkas override (host 31000, container 8080).
        $again = PortOverrides::persist($store, $result['app'], $dir, ['web' => [31000 => '8081']], []);
        $files = (array) $again['app']['compose_files'];
        $this->assertSame(1, count(array_keys($files, ComposeSource::RESET_OVERRIDE_FILE, true)));
        $this->assertSame(1, count(array_keys($files, ComposeSource::PORTS_OVERRIDE_FILE, true)));
        $this->assertStringContainsString('31000:8081', (string) file_get_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE));
    }

    /**
     * `primary_port` (= port container yang di-proxy Nginx) ikut bergerak bila
     * port container mapping yang di-proxy berubah — host port-nya tetap.
     */
    public function testPersistUpdatesPrimaryPortWhenProxiedMappingChanges(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir, 0777, true);

        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'demo',
            'compose_files' => ['docker-compose.yml'],
            'primary_service' => 'web',
            'primary_port' => 80,
            'containers' => [
                ['service_name' => 'web', 'ports' => [['host' => 31500, 'container' => 80]]],
            ],
            'host_ports' => [['service' => 'web', 'container' => 80, 'host' => 31500]],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $result = PortOverrides::persist($store, $app, $dir, ['web' => [31500 => '8080']], []);

        $this->assertSame(8080, $result['app']['primary_port'], 'port container yang di-proxy ikut berubah');
        $this->assertSame(8080, $result['rows'][0]['container']);
        $this->assertSame(31500, $result['rows'][0]['host']);
    }

    /**
     * Edit pada mapping **lain** (host port berbeda) tidak menyentuh
     * `primary_port`; filter service `primary_service` juga dihormati.
     */
    public function testPersistKeepsPrimaryPortWhenOtherMappingChanges(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir, 0777, true);

        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'demo',
            'compose_files' => ['docker-compose.yml'],
            'primary_service' => 'web',
            'primary_port' => 80,
            'containers' => [
                [
                    'service_name' => 'web',
                    'ports' => [
                        ['host' => 31500, 'container' => 80],
                        ['host' => 31501, 'container' => 9090],
                    ],
                ],
            ],
            // Urutan metadata sengaja menaruh mapping non-proxy lebih dulu.
            'host_ports' => [
                ['service' => 'web', 'container' => 9090, 'host' => 31501],
                ['service' => 'web', 'container' => 80, 'host' => 31500],
            ],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $result = PortOverrides::persist($store, $app, $dir, ['web' => [31501 => '9091']], []);

        $this->assertSame(9091, $result['rows'][0]['container'], 'mapping non-proxy berubah');
        $this->assertSame(80, $result['rows'][1]['container'], 'mapping yang di-proxy tidak berubah');
        $this->assertSame(80, $result['app']['primary_port'], 'primary_port tidak boleh ikut bergeser');
    }

    public function testPersistMetadataStoresHostPortsWithoutTouchingFilesystem(): void
    {
        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'agent-app',
            'server_id' => 'srv-2',
            'compose_files' => ['compose.yaml', 'docker-compose.override.networks.yml'],
            'host_ports' => [['service' => 'web', 'container' => 80, 'host' => 31000]],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $rows = PortOverrides::rows($app);
        $result = PortOverrides::persistMetadata($store, $app, ['web' => [31000 => '8080']], $rows, []);

        $this->assertSame(
            [['service' => 'web', 'container' => 8080, 'host' => 31000]],
            $result['app']['host_ports'],
            'metadata host_ports bentuk kanonik: host tetap, port container hasil edit'
        );
        $this->assertSame(
            ['compose.yaml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE, 'docker-compose.override.networks.yml'],
            $result['app']['compose_files']
        );
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/agent-app', 'tanpa sentuhan filesystem');
    }

    /**
     * Protokol non-tcp **wajib** ikut metadata (tanpa itu `apply_ports` menulis
     * ulang port UDP sebagai TCP); entri tcp tetap ringkas `{service,container,host}`.
     */
    public function testPersistMetadataStoresProtocolOnlyForNonTcpPorts(): void
    {
        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'agent-dns',
            'server_id' => 'srv-2',
            'compose_files' => ['compose.yaml'],
            'host_ports' => [
                ['service' => 'dns', 'container' => 53, 'host' => 3053, 'protocol' => 'udp'],
                ['service' => 'web', 'container' => 80, 'host' => 3080],
            ],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $rows = PortOverrides::rows($app);
        $result = PortOverrides::persistMetadata(
            $store,
            $app,
            ['dns' => [3053 => '1053'], 'web' => [3080 => '']],
            $rows,
            []
        );

        $this->assertSame(
            [
                ['service' => 'dns', 'container' => 1053, 'host' => 3053, 'protocol' => 'udp'],
                ['service' => 'web', 'container' => 80, 'host' => 3080],
            ],
            $result['app']['host_ports'],
            'protocol hanya untuk non-tcp; urutan kunci tcp tetap {service,container,host}'
        );
    }

    /**
     * `persistMetadata()` juga memperbarui `primary_port` bila mapping yang
     * di-proxy berganti port container (agent memakai `host_ports` sebagai
     * sumber kebenaran mapping port).
     */
    public function testPersistMetadataUpdatesPrimaryPortOfProxiedMapping(): void
    {
        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'agent-web',
            'server_id' => 'srv-2',
            'compose_files' => ['compose.yaml'],
            'primary_service' => 'web',
            'primary_port' => 80,
            'containers' => [
                ['service_name' => 'web', 'ports' => [['host' => 31500, 'container' => 80]]],
            ],
            'host_ports' => [['service' => 'web', 'container' => 80, 'host' => 31500]],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $rows = PortOverrides::rows($app);
        $result = PortOverrides::persistMetadata($store, $app, ['web' => [31500 => '8080']], $rows, []);

        $this->assertSame(
            [['service' => 'web', 'container' => 8080, 'host' => 31500]],
            $result['app']['host_ports'],
            'port container hasil edit ikut tersimpan di host_ports'
        );
        $this->assertSame(8080, $result['app']['primary_port'], 'primary_port mengikuti port container baru');
    }

    public function testPersistMetadataDropsHostPortsWhenPlanIsEmpty(): void
    {
        $store = new AppStore($this->dbFile);
        $app = $store->create([
            'name' => 'agent-app',
            'server_id' => 'srv-2',
            'compose_files' => ['compose.yaml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            'host_ports' => [['service' => 'web', 'container' => 80, 'host' => 31000]],
        ]);
        $app = $store->find((string) $app['id']) ?? $app;

        $result = PortOverrides::persistMetadata($store, $app, [], [], []);

        $this->assertArrayNotHasKey('host_ports', $result['app']);
        $this->assertSame(['compose.yaml'], $result['app']['compose_files'], 'entri override port ikut dibuang');
    }

    // ==================================================================
    // ComposeParser: protokol pada sintaks pendek
    // ==================================================================

    /**
     * `ComposeParser` harus mempertahankan sufiks `/proto` sintaks pendek —
     * tanpanya port UDP (`53/udp`) tersimpan sebagai TCP di metadata/override.
     */
    public function testComposeParserKeepsProtocolOfShortSyntax(): void
    {
        $file = $this->tmp . '/docker-compose.yml';
        file_put_contents($file, <<<'YAML'
        services:
          dns:
            image: coredns
            ports:
              - "53:53/udp"
              - "53/udp"
              - "80"
              - "853/sctp"
              - "8080:80/tidak-dikenal"
        YAML);

        $services = (new ComposeParser())->parse($file)['services'];

        $this->assertSame(
            [
                ['host' => 53, 'container' => 53, 'protocol' => 'udp'],
                ['host' => null, 'container' => 53, 'protocol' => 'udp'],
                ['host' => null, 'container' => 80, 'protocol' => 'tcp'],
                ['host' => null, 'container' => 853, 'protocol' => 'sctp'],
                ['host' => 8080, 'container' => 80, 'protocol' => 'tcp'],
            ],
            $services['dns']['ports'],
            'sufiks /tcp|/udp|/sctp dipertahankan; sufiks lain tetap tcp'
        );
    }

    // ==================================================================
    // Regresi: AppArtifactControllerBase::writeOverride() tetap sama
    // ==================================================================

    /**
     * Refactor `writeOverride()` (memakai `PortOverrides::render()` +
     * `rowsFromServices()`) tidak boleh mengubah isi berkas maupun daftar
     * `compose_files` untuk input service ber-port.
     */
    public function testWriteOverrideKeepsLegacyOutput(): void
    {
        $appsPath = $this->tmp . '/apps';
        mkdir($appsPath . '/demo', 0777, true);

        $services = [
            'web' => [
                'internal_port' => 80,
                'host_port' => 31000,
                'ports' => [['host' => 31000, 'container' => 80, 'protocol' => 'tcp']],
            ],
            'worker' => ['internal_port' => null, 'host_port' => null, 'ports' => []],
        ];

        $this->withAppsPath($appsPath, function () use ($services): void {
            $method = new ReflectionMethod(AppArtifactControllerBase::class, 'writeOverride');
            $method->setAccessible(true);

            $composeFiles = ['docker-compose.yml'];
            $args = [['name' => 'demo'], $services, &$composeFiles, null];
            $method->invokeArgs(new ComposeController(), $args);

            $this->assertSame(
                ['docker-compose.yml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
                $composeFiles,
                'kedua berkas override tetap ditambahkan'
            );

            $dir = (string) config('deploy.apps_path') . '/demo';
            $this->assertSame(
                "services:\n  web:\n    ports: !reset\n      {  }",
                (string) file_get_contents($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE)
            );
            $this->assertSame(
                "services:\n  web:\n    ports:\n      - '31000:80'\n",
                (string) file_get_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE)
            );
            $this->assertSame(
                PortOverrides::render(PortOverrides::rowsFromServices($services)),
                [
                    'reset' => (string) file_get_contents($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE),
                    'ports' => (string) file_get_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE),
                ],
                'berkas ditulis dari render PortOverrides'
            );
        });
    }

    /** Tanpa service ber-port pun kedua berkas tetap ditulis (perilaku lama). */
    public function testWriteOverrideStillWritesBothFilesWithoutPorts(): void
    {
        $appsPath = $this->tmp . '/apps';
        mkdir($appsPath . '/demo', 0777, true);

        $this->withAppsPath($appsPath, function (): void {
            $method = new ReflectionMethod(AppArtifactControllerBase::class, 'writeOverride');
            $method->setAccessible(true);

            $composeFiles = ['docker-compose.yml'];
            $args = [
                ['name' => 'demo'],
                ['worker' => ['internal_port' => null, 'host_port' => null, 'ports' => []]],
                &$composeFiles,
                null,
            ];
            $method->invokeArgs(new ComposeController(), $args);

            $this->assertSame(
                ['docker-compose.yml', ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
                $composeFiles
            );

            $dir = (string) config('deploy.apps_path') . '/demo';
            $this->assertSame("services: {  }\n", (string) file_get_contents($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE));
            $this->assertSame("services: {  }\n", (string) file_get_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE));
        });
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Jalankan `$fn` dengan `config('deploy.apps_path')` menunjuk direktori temp,
     * lalu pulihkan state config global (uji lain tidak boleh terpengaruh).
     */
    private function withAppsPath(string $appsPath, callable $fn): void
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        $configDir = $this->tmp . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }
        file_put_contents($configDir . '/deploy.php', '<?php return ' . var_export(['apps_path' => $appsPath], true) . ';' . PHP_EOL);
        // Config::loadFromDir() hanya membaca berkas yang direktori-nya punya
        // `app.php` (penanda konfigurasi aplikasi webman).
        file_put_contents($configDir . '/app.php', "<?php return [];\n");

        try {
            Config::load($configDir);
            $fn();
        } finally {
            foreach ($state as $name => $value) {
                $prop = new ReflectionProperty(Config::class, $name);
                $prop->setAccessible(true);
                $prop->setValue(null, $value);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
