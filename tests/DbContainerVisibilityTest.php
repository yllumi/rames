<?php
declare(strict_types=1);

namespace Tests;

use app\library\Db\DbContainerDetector;
use app\library\Docker\DockerClient;
use PHPUnit\Framework\TestCase;

/**
 * Test visibilitas daftar container DB per kepemilikan app (halaman /database).
 *
 * Non-admin hanya boleh melihat container DB milik app yang bisa diaksesnya;
 * container app user lain & container eksternal tidak boleh muncul.
 */
class DbContainerVisibilityTest extends TestCase
{
    /**
     * Engine tiruan: 3 container DB (milik app saya, milik app orang lain,
     * dan eksternal tanpa app) + 1 container non-DB.
     *
     * @return array{0:DockerClient,1:array<int,string>} mock + daftar id yang di-inspect
     */
    private function fakeDocker(array &$inspected): DockerClient
    {
        $containers = [
            ['Id' => 'c-mine', 'Names' => ['/myapp-db-1'], 'Image' => 'mysql:8', 'State' => 'running', 'Status' => 'Up'],
            ['Id' => 'c-other', 'Names' => ['/otherapp-db-1'], 'Image' => 'mariadb:11', 'State' => 'running', 'Status' => 'Up'],
            ['Id' => 'c-external', 'Names' => ['/external-db'], 'Image' => 'mysql:5.7', 'State' => 'running', 'Status' => 'Up'],
            ['Id' => 'c-web', 'Names' => ['/myapp-web-1'], 'Image' => 'nginx:alpine', 'State' => 'running', 'Status' => 'Up'],
        ];

        $docker = $this->createMock(DockerClient::class);
        $docker->method('listContainers')->willReturn($containers);
        $docker->method('inspectContainer')->willReturnCallback(
            static function (string $id) use (&$inspected): array {
                $inspected[] = $id;
                return match ($id) {
                    'c-other' => ['Id' => $id, 'Config' => ['Image' => 'mariadb:11'], 'State' => ['Status' => 'running']],
                    'c-web' => ['Id' => $id, 'Config' => ['Image' => 'nginx:alpine'], 'State' => ['Status' => 'running']],
                    default => ['Id' => $id, 'Config' => ['Image' => 'mysql:8'], 'State' => ['Status' => 'running']],
                };
            }
        );

        return $docker;
    }

    /**
     * @return array<int,array>
     */
    private function myApps(): array
    {
        return [[
            'id' => 'app1',
            'name' => 'myapp',
            'owner_id' => 'u1',
            'containers' => [
                ['container_name' => 'myapp-db-1', 'image' => 'mysql:8'],
                ['container_name' => 'myapp-web-1', 'image' => 'nginx:alpine'],
            ],
        ]];
    }

    public function testAdminSeesAllDbContainersIncludingExternal(): void
    {
        $inspected = [];
        $detector = new DbContainerDetector($this->fakeDocker($inspected));

        $rows = $detector->detectAll($this->myApps(), true);
        $names = array_column($rows, 'container_name');

        // container non-DB (nginx) tetap dikecualikan; urut alfabetis
        $this->assertSame(['external-db', 'myapp-db-1', 'otherapp-db-1'], $names);
        $this->assertNotContains('myapp-web-1', $names);

        $byName = array_column($rows, null, 'container_name');
        $this->assertTrue($byName['myapp-db-1']['owned']);
        $this->assertSame('myapp', $byName['myapp-db-1']['app_name']);
        $this->assertFalse($byName['external-db']['owned']);
        $this->assertFalse($byName['otherapp-db-1']['owned'], 'app orang lain = bukan milik app yang diizinkan');
    }

    public function testMemberOnlySeesOwnAppsDbContainers(): void
    {
        $inspected = [];
        $detector = new DbContainerDetector($this->fakeDocker($inspected));

        $rows = $detector->detectAll($this->myApps(), false);

        $this->assertSame(['myapp-db-1'], array_column($rows, 'container_name'));
        $this->assertSame('app1', $rows[0]['app_id']);
        $this->assertTrue($rows[0]['owned']);
    }

    public function testSkippedContainersAreNotInspected(): void
    {
        $inspected = [];
        $detector = new DbContainerDetector($this->fakeDocker($inspected));

        $detector->detectAll($this->myApps(), false);

        // Hanya container milik app yang diizinkan yang di-inspect (efisien &
        // tidak menyentuh container app user lain).
        sort($inspected);
        $this->assertSame(['c-mine', 'c-web'], $inspected);
    }

    public function testNoAllowedAppsYieldsNoRows(): void
    {
        $inspected = [];
        $detector = new DbContainerDetector($this->fakeDocker($inspected));

        $this->assertSame([], $detector->detectAll([], false));
    }
}
