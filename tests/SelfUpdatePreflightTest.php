<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Preflight self-update untuk **pemilik direktori repo yang tidak bisa dipakai**
 * helper (docs/SPECS.md §7.8 / docs/ARCHITECTURE.md §5.14).
 *
 * Bug produksi yang direproduksi (server agent "do-cdpl"): job `self_update`
 * berhenti dengan pesan
 *
 *   "Tidak bisa menentukan pemilik direktori repo (uid) — update dibatalkan …"
 *
 * Pesan itu dilempar `start()` ketika `repoUid() <= 0`, dan `repoUid()` memetakan
 * `fileowner()` yang gagal ke 0 — sehingga **dua** sebab berbeda tampil identik:
 *
 *  a. direktori repo tidak terbaca dari dalam container (stat gagal),
 *  b. direktori repo benar-benar milik root (uid 0) — kasus paling nyata: checkout
 *     di host hasil `sudo git clone` / `sudo ./agent/install.sh`. Helper SENGAJA
 *     tidak berjalan sebagai root, jadi uid 0 memang tidak didukung.
 *
 * Sekarang keduanya ditolak **preflight** dengan pesan berbeda + jalan keluar
 * (`chown` konkret / bind-mount), dan `start()` memakai pesan yang sama sebagai
 * sabuk pengaman. Uji di sini tidak bergantung uid proses uji: stat pemilik repo
 * disuntik lewat seam `$ownerStat`.
 */
class SelfUpdatePreflightTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-preflight-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================
    // Pesan: pemilik root (uid 0)
    // ==================================================================

    public function testPreflightRejectsRootOwnedRepoWithConcreteChownFix(): void
    {
        $root = $this->fixtureRepo();
        // Seam: pemilik direktori repo = root, tanpa perlu chown (uji bebas uid).
        $service = $this->service($root, static fn (string $path): array => [0, 0]);

        $pf = $service->preflight();

        $this->assertFalse($pf['ok'], 'repo milik root harus ditolak preflight');
        $message = $this->ownerError($pf['errors'], 'dimiliki root');
        $this->assertStringContainsString($root, $message, 'path repo wajib disebut');
        $this->assertStringContainsString('0:0', $message, 'pemilik yang ditemukan');
        $this->assertStringContainsString('sudo chown -R ', $message);
        $this->assertStringContainsString('agent/install.sh', $message, 'alternatif aman disebut');
        $this->assertStringContainsString('sudo -u ', $message);
        $this->assertStringContainsString('git pull', $message, 'sebab: berkas hasil pull akan jadi milik root');
        $this->assertStringNotContainsString("\n", $message, 'pesan harus satu baris (job self_update)');
        $this->assertStringNotContainsString('token', strtolower($message));
        // Aturan keamanan tidak dilonggarkan: uid 0 tetap ditolak.
        $this->assertStringNotContainsString('diizinkan', strtolower($message));
    }

    public function testRootOwnedRepoFixUsesParentOwnerWhenReadable(): void
    {
        // Direktori repo bersarang: induknya milik proses uji (bukan root), jadi
        // `chown` konkret harus ditawarkan — atau placeholder bila induknya juga
        // milik root (CI sebagai root). Uji tidak bergantung pada uid nyata.
        $parent = $this->tmp . '/srv';
        mkdir($parent, 0755, true);
        $root = $this->fixtureRepo($parent . '/rames');

        $service = $this->service($root, static fn (string $path): array => [0, 0]);
        $message = $this->ownerError($service->preflight()['errors'], 'dimiliki root');

        $parentUid = @fileowner($parent);
        $parentGid = @filegroup($parent);
        if ($parentUid !== false && $parentGid !== false && (int) $parentUid > 0) {
            $this->assertStringContainsString(
                'sudo chown -R ' . (int) $parentUid . ':' . (int) $parentGid . ' ' . $root,
                $message,
                'perintah chown memakai pemilik direktori INDUK repo'
            );
        } else {
            $this->assertStringContainsString('sudo chown -R <uid>:<gid> ' . $root, $message);
            $this->assertStringContainsString('pemilik direktori induk repo', $message);
        }
    }

    public function testRootOwnedMessageIsStableForNonZeroGid(): void
    {
        // root bisa saja memiliki direktori dengan gid lain (mis. root:staff).
        $root = $this->fixtureRepo();
        $service = $this->service($root, static fn (string $path): array => [0, 50]);

        $message = $this->ownerError($service->preflight()['errors'], 'dimiliki root');

        $this->assertStringContainsString('0:50', $message);
        $this->assertStringContainsString($root, $message);
    }

    // ==================================================================
    // Pesan: path tidak terbaca (stat gagal) — sebab BERBEDA
    // ==================================================================

    public function testPreflightReportsUnreadableRepoPathSeparately(): void
    {
        $root = $this->fixtureRepo();
        $service = $this->service($root, static fn (string $path): array => [false, false]);

        $pf = $service->preflight();

        $this->assertFalse($pf['ok']);
        $unreadable = $this->ownerError($pf['errors'], 'tidak bisa dibaca');
        $this->assertStringContainsString($root, $unreadable);
        $this->assertStringContainsString('${PWD}:${PWD}', $unreadable, 'saran bind-mount');
        $this->assertStringContainsString('fileowner()', $unreadable, 'sebab teknis disebut');
        $this->assertStringNotContainsString('sudo chown', $unreadable, 'stat gagal bukan urusan chown');
        $this->assertStringNotContainsString("\n", $unreadable);

        // Dua sebab tidak boleh memakai pesan yang sama.
        $rootOwnedRoot = $this->fixtureRepo($this->tmp . '/root-owned');
        $rootOwned = $this->service($rootOwnedRoot, static fn (string $path): array => [0, 0])->preflight()['errors'];
        $this->assertNotSame($unreadable, $this->ownerError($rootOwned, 'dimiliki root'));
    }

    public function testRepoOwnerUnusableErrorIsPure(): void
    {
        $this->assertNull(UpdateService::repoOwnerUnusableError('/srv/rames', 1000, 1000));
        $this->assertNull(UpdateService::repoOwnerUnusableError('/srv/rames', 1, 1));

        $statFail = UpdateService::repoOwnerUnusableError('/srv/rames', false, false);
        $this->assertNotNull($statFail);
        $this->assertStringContainsString('/srv/rames', (string) $statFail);
        $this->assertStringContainsString('${PWD}:${PWD}', (string) $statFail);

        $rootOwned = UpdateService::repoOwnerUnusableError('/srv/rames', 0, 0);
        $this->assertNotNull($rootOwned);
        $this->assertStringContainsString('sudo chown -R ', (string) $rootOwned);
        $this->assertStringContainsString('/srv/rames', (string) $rootOwned);
        $this->assertNotSame($statFail, $rootOwned);
    }

    // ==================================================================
    // Regresi: repo normal tetap lolos, cek kepemilikan lain tetap jalan
    // ==================================================================

    public function testPreflightAcceptsRepoOwnedByNormalUser(): void
    {
        $root = $this->fixtureRepo();
        // Tanpa seam: stat sungguhan (uid proses uji). Bila uji dijalankan sebagai
        // root, repo fixture memang milik root → tidak ada yang bisa dibuktikan
        // soal "uid normal"; uji di-skip supaya tidak bergantung uid mesin.
        if ((int) fileowner($root) === 0) {
            self::markTestSkipped('fixture dimiliki root — butuh repo milik uid non-root');
        }

        $pf = $this->service($root)->preflight();

        foreach ($pf['errors'] as $error) {
            $this->assertStringNotContainsString('dimiliki root', $error, $error);
            $this->assertStringNotContainsString('tidak bisa dibaca dari dalam container', $error, $error);
        }

        // Fixture ini sehat: tidak ada galat kepemilikan sama sekali.
        $this->assertTrue($pf['ok'], 'repo normal harus lolos preflight: ' . implode(' | ', $pf['errors']));
    }

    public function testGitDirOwnershipCheckStillRunsWhenRepoOwnerIsUsable(): void
    {
        // `gitDirOwnershipError()` tidak boleh ikut terlewati oleh pemeriksaan baru.
        $root = $this->tmp . '/worktree-repo';
        mkdir($root . '/cli', 0755, true);
        file_put_contents($root . '/.git', "gitdir: /srv/git/worktrees/x\n");
        file_put_contents($root . '/cli/self-update.sh', "# helper\n");
        file_put_contents($root . '/cli/update-report.php', "<?php\n");

        $pf = $this->service($root, static fn (string $path): array => [33, 33])->preflight();

        $this->assertFalse($pf['ok']);
        $layout = array_values(array_filter(
            $pf['errors'],
            static fn (string $e): bool => str_contains($e, 'BUKAN direktori')
        ));
        $this->assertCount(1, $layout, 'cek gitdir tetap berjalan: ' . implode(' | ', $pf['errors']));
        foreach ($pf['errors'] as $error) {
            $this->assertStringNotContainsString('dimiliki root', $error, 'uid 33 = pemilik yang sah');
        }
    }

    // ==================================================================
    // start(): sabuk pengaman, fail-closed tanpa efek samping
    // ==================================================================

    public function testStartThrowsInformativeMessageWhenOwnerIsRoot(): void
    {
        $root = $this->fixtureRepo();
        $state = new UpdateState($this->tmp . '/check.json', $this->tmp . '/runs');
        // Seam berurutan: preflight melihat uid sah (33:33) supaya lolos, lalu
        // `start()` membaca stat lagi dan melihat root — persis skenario balapan
        // yang dijaga sabuk pengaman.
        $calls = 0;
        $ownerStat = static function (string $path) use (&$calls): array {
            $calls++;

            return $calls === 1 ? [33, 33] : [0, 0];
        };

        $service = $this->service($root, $ownerStat, $state);
        // Direktori fixture dibuka lebar supaya cek "bisa ditulis uid:gid helper"
        // lolos untuk uid 33 (uji tidak bergantung uid pemilik nyata).
        foreach ([$root, $root . '/.git', $root . '/.git/objects', $root . '/.git/refs'] as $dir) {
            chmod($dir, 0777);
        }
        $pre = $service->preflight();
        $this->assertTrue($pre['ok'], 'prasyarat uji: preflight harus lolos pada stat pertama: ' . implode(' | ', $pre['errors']));

        try {
            $service->start('update', 'tester');
            $this->fail('start() harus menolak repo milik root');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('dimiliki root', $message);
        $this->assertStringContainsString($root, $message);
        $this->assertStringContainsString('sudo chown -R ', $message);
        $this->assertStringNotContainsString('Tidak bisa menentukan pemilik direktori repo (uid)', $message);
        // Fail-closed: belum ada run/plan yang ditulis.
        $this->assertFileDoesNotExist($this->tmp . '/runs/run.json');
        $this->assertSame([], glob($this->tmp . '/runs/*.plan.json') ?: []);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Fixture repo minimal (`.git` direktori + berkas helper + HEAD).
     */
    private function fixtureRepo(?string $root = null): string
    {
        $root ??= $this->tmp . '/repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        mkdir($root . '/cli', 0755, true);
        file_put_contents($root . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($root . '/cli/self-update.sh', "# helper\n");
        file_put_contents($root . '/cli/update-report.php', "<?php\n");
        chmod($root . '/.git', 0755);

        return $root;
    }

    /**
     * @param Closure(string):array{0:int|false,1:int|false}|null $ownerStat
     */
    private function service(string $root, ?Closure $ownerStat = null, ?UpdateState $state = null): UpdateService
    {
        return new UpdateService(
            new RepoInfo($root),
            $state ?? new UpdateState($this->tmp . '/check.json', $this->tmp . '/runs'),
            self::fakeDocker(),
            null,
            '',
            $ownerStat,
        );
    }

    /**
     * Engine palsu: `selfContext()` hanya butuh container dashboard + network
     * project compose, tanpa Docker/network nyata.
     */
    private static function fakeDocker(): DockerClient
    {
        return new class extends DockerClient {
            public function __construct()
            {
            }

            public function inspectContainer(string $id): array
            {
                return [
                    'Name' => '/rames-webman',
                    'Config' => [
                        'Image' => 'rames-webman',
                        'Labels' => [
                            'com.docker.compose.project' => 'rames',
                            'com.docker.compose.service' => 'webman',
                        ],
                    ],
                    'HostConfig' => ['Dns' => []],
                    'NetworkSettings' => ['Networks' => ['rames_default' => ['IPAddress' => '172.18.0.2']]],
                ];
            }

            public function inspectNetwork(string $id): array
            {
                if ($id !== 'rames_default') {
                    throw new RuntimeException('network tidak ditemukan: ' . $id);
                }

                return ['Name' => $id, 'Internal' => false];
            }

            public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
            {
                throw new RuntimeException('listContainers tidak dipakai di fixture preflight');
            }
        };
    }

    /**
     * @param array<int,string> $errors
     */
    private function ownerError(array $errors, string $needle): string
    {
        $found = array_values(array_filter(
            $errors,
            static fn (string $e): bool => str_contains($e, $needle)
        ));
        $this->assertCount(1, $found, 'tepat satu galat "' . $needle . '": ' . implode(' | ', $errors));

        return $found[0];
    }
}
