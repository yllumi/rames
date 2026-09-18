<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ComposeSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test ComposeSource — mode create app "compose" (paste/upload
 * docker-compose.yml tanpa repo Git): validasi compose (image prebuilt, tolak
 * `build:`), penyimpanan file unggahan (nama aman, batas ukuran), deteksi file
 * utama, daftar file sumber, dan perencanaan host port.
 */
class ComposeSourceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/composesrc_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    // ==================================================================
    // Deteksi mode
    // ==================================================================

    public function testSourceDefaultsToGitWhenFieldAbsent(): void
    {
        $this->assertSame(ComposeSource::SOURCE_GIT, ComposeSource::source([]));
        $this->assertFalse(ComposeSource::isCompose(['name' => 'myapp']));
        $this->assertTrue(ComposeSource::isGit(['name' => 'myapp']));
    }

    public function testSourceDetectsComposeMode(): void
    {
        $app = ['name' => 'myapp', 'source' => ComposeSource::SOURCE_COMPOSE];
        $this->assertTrue(ComposeSource::isCompose($app));
        $this->assertFalse(ComposeSource::isGit($app));
    }

    public function testMainFileSkipsGeneratedOverrides(): void
    {
        $files = [
            'docker-compose.override.yml',
            'docker-compose.yml',
            'docker-compose.override.ports.yml',
        ];

        $this->assertSame('docker-compose.yml', ComposeSource::mainFileFrom($files));
        $this->assertSame(
            'docker-compose.yml',
            ComposeSource::mainFile(['name' => 'myapp', 'compose_files' => $files])
        );
        $this->assertSame('', ComposeSource::mainFileFrom(['docker-compose.override.env.yml']));
    }

    public function testDetectMainFilePrefersYmlAndReturnsEmptyWhenNone(): void
    {
        file_put_contents($this->tmp . '/compose.yaml', "services:\n  web:\n    image: nginx:alpine\n");
        $this->assertSame('compose.yaml', ComposeSource::detectMainFile($this->tmp));

        file_put_contents($this->tmp . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        $this->assertSame('docker-compose.yml', ComposeSource::detectMainFile($this->tmp));

        $empty = $this->tmp . '/kosong';
        mkdir($empty);
        $this->assertSame('', ComposeSource::detectMainFile($empty));
    }

    // ==================================================================
    // Validasi compose
    // ==================================================================

    public function testAssertDeployableAcceptsImageOnlyCompose(): void
    {
        ComposeSource::assertDeployable("services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"8080:80\"\n");
        $this->addToAssertionCount(1);
    }

    public function testAssertDeployableRejectsBuildService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/build:/');
        ComposeSource::assertDeployable("services:\n  web:\n    build: .\n");
    }

    public function testAssertDeployableRejectsServiceWithoutImage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/image:/');
        ComposeSource::assertDeployable("services:\n  web:\n    restart: always\n");
    }

    public function testAssertDeployableRejectsInvalidYaml(): void
    {
        $this->expectException(RuntimeException::class);
        ComposeSource::assertDeployable("services: [\n  - rusak\n");
    }

    public function testAssertDeployableRejectsMissingServices(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/services/');
        ComposeSource::assertDeployable("version: '3'\n");
    }

    // ==================================================================
    // Penyimpanan file
    // ==================================================================

    public function testStorePastedComposeWritesMainFile(): void
    {
        $content = "services:\n  web:\n    image: nginx:alpine\n";

        $written = ComposeSource::store($this->tmp, [], $content);

        $this->assertSame(['docker-compose.yml'], $written);
        $this->assertSame($content, (string) file_get_contents($this->tmp . '/docker-compose.yml'));
    }

    public function testStoreWritesSupportingFileInSubdirectory(): void
    {
        $upload = $this->upload('conf/nginx.conf', "server {\n}\n");

        $written = ComposeSource::store(
            $this->tmp,
            [$upload],
            "services:\n  web:\n    image: nginx:alpine\n"
        );

        $this->assertSame(['conf/nginx.conf', 'docker-compose.yml'], $written);
        $this->assertFileExists($this->tmp . '/conf/nginx.conf');
    }

    public function testStoreAcceptsUploadedMainCompose(): void
    {
        $upload = $this->upload('docker-compose.yml', "services:\n  db:\n    image: mariadb:11\n");

        $written = ComposeSource::store($this->tmp, [$upload], '');

        $this->assertSame(['docker-compose.yml'], $written);
        $this->assertStringContainsString('mariadb:11', (string) file_get_contents($this->tmp . '/docker-compose.yml'));
    }

    public function testStoreRespectsPreferredMainFileWhenPasting(): void
    {
        // app dibuat dari compose.yaml → edit dari editor tetap menulis ke file itu
        $written = ComposeSource::store(
            $this->tmp,
            [],
            "services:\n  web:\n    image: nginx:alpine\n",
            ComposeSource::MAX_FILE_BYTES,
            ComposeSource::MAX_TOTAL_BYTES,
            'compose.yaml'
        );

        $this->assertSame(['compose.yaml'], $written);
        $this->assertFileDoesNotExist($this->tmp . '/docker-compose.yml');
    }

    /**
     * Regresi: input file yang tidak diisi tetap dikirim browser sebagai satu
     * entri bernama kosong (Workerman menandainya error=0, bukan
     * UPLOAD_ERR_NO_FILE) → harus diabaikan, bukan dianggap nama file invalid.
     */
    public function testStoreSkipsEmptyFileInputEntry(): void
    {
        $emptyInput = $this->upload('', '');   // name kosong, tmp_name ada, error 0

        $written = ComposeSource::store(
            $this->tmp,
            [$emptyInput],
            "services:\n  web:\n    image: nginx:alpine\n"
        );

        $this->assertSame(['docker-compose.yml'], $written);
    }

    public function testStoreSkipsEmptyNameFromFilesArray(): void
    {
        // $_FILES gaya flat untuk <input type="file" name="files[]"> tanpa file
        $normalized = ComposeSource::normalizeUploads([
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => 0,
            'size' => 0,
        ]);

        $written = ComposeSource::store(
            $this->tmp,
            $normalized,
            "services:\n  web:\n    image: nginx:alpine\n"
        );

        $this->assertSame(['docker-compose.yml'], $written);
    }

    public function testStoreSkipsEmptyEntryButKeepsRealFile(): void
    {
        $written = ComposeSource::store(
            $this->tmp,
            [
                $this->upload('', ''),
                $this->upload('conf/nginx.conf', "server {}\n"),
            ],
            "services:\n  web:\n    image: nginx:alpine\n"
        );

        $this->assertSame(['conf/nginx.conf', 'docker-compose.yml'], $written);
    }

    public function testStoreRejectsPasteAndUploadedMainTogether(): void
    {        $upload = $this->upload('docker-compose.yml', "services:\n  db:\n    image: mariadb:11\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pilih salah satu/');
        ComposeSource::store($this->tmp, [$upload], "services:\n  web:\n    image: nginx:alpine\n");
    }

    public function testStoreRequiresMainCompose(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/docker-compose.yml wajib/');
        ComposeSource::store($this->tmp, [$this->upload('catatan.txt', 'halo')], '');
    }

    public function testStoreRejectsPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak valid/');
        ComposeSource::store(
            $this->tmp,
            [$this->upload('../jahat.yml', "services:\n  web:\n    image: nginx:alpine\n")],
            ''
        );
    }

    public function testStoreRejectsAbsolutePath(): void
    {
        $this->expectException(RuntimeException::class);
        ComposeSource::store(
            $this->tmp,
            [$this->upload('/etc/passwd', 'x')],
            "services:\n  web:\n    image: nginx:alpine\n"
        );
    }

    public function testStoreRejectsGeneratedOverrideUpload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dikelola dashboard/');
        ComposeSource::store(
            $this->tmp,
            [$this->upload('docker-compose.override.ports.yml', "services: {}\n")],
            "services:\n  web:\n    image: nginx:alpine\n"
        );
    }

    public function testStoreRejectsOversizedFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/terlalu besar/');
        ComposeSource::store(
            $this->tmp,
            [$this->upload('besar.txt', str_repeat('a', 4096), 4096)],
            "services:\n  web:\n    image: nginx:alpine\n",
            1024,
            1048576
        );
    }

    public function testStoreDoesNotWriteAnythingWhenComposeInvalid(): void
    {
        try {
            ComposeSource::store(
                $this->tmp,
                [$this->upload('conf/nginx.conf', "server {}\n")],
                "services:\n  web:\n    build: .\n"
            );
            $this->fail('Seharusnya melempar exception untuk service ber-build.');
        } catch (RuntimeException $e) {
            // validasi gagal sebelum menulis → tidak ada file (termasuk compose)
            $this->assertFileDoesNotExist($this->tmp . '/docker-compose.yml');
            $this->assertFileDoesNotExist($this->tmp . '/conf/nginx.conf');
        }
    }

    // ==================================================================
    // Baca/tulis & daftar file
    // ==================================================================

    public function testWriteAndReadMainCompose(): void
    {
        $app = ['name' => 'myapp', 'source' => ComposeSource::SOURCE_COMPOSE, 'compose_files' => ['docker-compose.yml']];

        $name = ComposeSource::writeMain($this->tmp, $app, "services:\n  web:\n    image: nginx:alpine\n");
        $this->assertSame('docker-compose.yml', $name);
        $this->assertStringContainsString('nginx:alpine', ComposeSource::readMain($this->tmp, $app));
    }

    public function testWriteMainRejectsBuild(): void
    {
        $app = ['name' => 'myapp', 'source' => ComposeSource::SOURCE_COMPOSE];

        $this->expectException(RuntimeException::class);
        ComposeSource::writeMain($this->tmp, $app, "services:\n  web:\n    build: .\n");
    }

    public function testRequireMainFileThrowsWhenMissing(): void
    {
        $this->expectException(RuntimeException::class);
        ComposeSource::requireMainFile($this->tmp, ['name' => 'myapp', 'source' => ComposeSource::SOURCE_COMPOSE]);
    }

    public function testListSourceFilesExcludesGeneratedOverrides(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        file_put_contents($this->tmp . '/docker-compose.override.yml', "services: {}\n");
        file_put_contents($this->tmp . '/docker-compose.override.ports.yml', "services: {}\n");
        file_put_contents($this->tmp . '/docker-compose.override.env.yml', "services: {}\n");
        mkdir($this->tmp . '/conf');
        file_put_contents($this->tmp . '/conf/nginx.conf', "server {}\n");

        $this->assertSame(
            ['conf/nginx.conf', 'docker-compose.yml'],
            ComposeSource::listSourceFiles($this->tmp)
        );
    }

    public function testRemoveFilesRejectsMainAndGenerated(): void
    {
        file_put_contents($this->tmp . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        file_put_contents($this->tmp . '/extra.txt', 'x');

        // file pendukung terhapus
        $this->assertSame(['extra.txt'], ComposeSource::removeFiles($this->tmp, ['extra.txt'], 'docker-compose.yml'));
        $this->assertFileDoesNotExist($this->tmp . '/extra.txt');

        // compose utama & override generated tidak boleh dihapus
        try {
            ComposeSource::removeFiles($this->tmp, ['docker-compose.yml'], 'docker-compose.yml');
            $this->fail('Compose utama seharusnya tidak bisa dihapus.');
        } catch (RuntimeException $e) {
            $this->assertFileExists($this->tmp . '/docker-compose.yml');
        }

        $this->expectException(RuntimeException::class);
        ComposeSource::removeFiles($this->tmp, ['docker-compose.override.yml'], 'docker-compose.yml');
    }

    // ==================================================================
    // Perencanaan host port
    // ==================================================================

    public function testPlanHostPortsKeepsExistingAndResolvesRemaining(): void
    {
        $services = [
            'web' => ['internal_port' => 80, 'host_port' => 8080, 'ports' => [['host' => 8080, 'container' => 80, 'protocol' => 'tcp']]],
            'api' => ['internal_port' => 3000, 'host_port' => null, 'ports' => [['host' => null, 'container' => 3000, 'protocol' => 'tcp']]],
            'worker' => ['internal_port' => null, 'host_port' => null, 'ports' => []],
        ];

        $planned = ComposeSource::planHostPorts($services, ['web' => 30005], [30000], 30000, 30999);

        $this->assertSame(30005, $planned['web']['host_port']);
        $this->assertSame(30005, $planned['web']['ports'][0]['host']);
        $this->assertSame(30001, $planned['api']['host_port']);
        $this->assertSame(30001, $planned['api']['ports'][0]['host']);
        $this->assertNull($planned['worker']['host_port']);
    }

    public function testPlanHostPortsMovesPortConflictingWithOtherApp(): void
    {
        $services = [
            'web' => ['internal_port' => 80, 'host_port' => 30000, 'ports' => [['host' => 30000, 'container' => 80, 'protocol' => 'tcp']]],
        ];

        // 30000 dipakai app lain → port wajib digeser
        $planned = ComposeSource::planHostPorts($services, ['web' => 30000], [30000], 30000, 30999);

        $this->assertSame(30001, $planned['web']['host_port']);
    }

    public function testExistingHostPortsMapsServiceToPort(): void
    {
        $app = [
            'containers' => [
                ['service_name' => 'web', 'host_port' => 30010],
                ['service_name' => 'worker', 'host_port' => null],
                ['service_name' => '', 'host_port' => 30011],
            ],
        ];

        $this->assertSame(['web' => 30010], ComposeSource::existingHostPorts($app));
    }

    /**
     * Buat entri unggahan palsu (file temporer berisi $content).
     *
     * @return array{name:string,tmp_name:string,size:int,error:int}
     */
    private function upload(string $name, string $content, ?int $size = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rames-up-');
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => $size ?? strlen($content),
            'error' => UPLOAD_ERR_OK,
        ];
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
