<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regresi **kepemilikan repo** pada self-update (docs/SPECS.md §7.8 / docs/ARCHITECTURE.md §5.14).
 *
 * Bug yang direproduksi: helper update dijalankan `--user <uid>:<gid>` = pemilik
 * direktori repo, tetapi tidak ada satu pun pemeriksaan yang menjawab "bolehkah
 * uid:gid ITU menulis `.git`?". Preflight di container dashboard memakai
 * `is_writable()` — dan container dashboard berjalan sebagai **root**, sehingga
 * `is_writable()` hampir selalu `true`. Akibatnya helper menyentuh repo lalu
 * gagal di tengah `git fetch`:
 *
 *   error: insufficient permission for adding an object to repository database .git/objects
 *   fatal: unpack-objects failed
 *
 * Dua pertahanan yang diuji di sini:
 *
 *  A. `UpdateService::canUidWritePath()` / `unwritableHelperPaths()` — murni dari
 *     stat (owner/group/other + bit mode), bukan `is_writable()`, dan dipakai
 *     `preflight()` untuk menolak lebih awal dengan perintah `chown` siap-salin.
 *  B. Gate `[ -w ]` di `cli/self-update.sh` — **authoritative** (dijalankan
 *     sebagai uid helper) dan berjalan SEBELUM `git fetch` mana pun, plus
 *     klasifikasi galat git: jejak izin → pesan kepemilikan, bukan jaringan/DNS.
 *
 * Semua fixture memakai direktori temp. Uji skrip memakai `docker`/`git` palsu di
 * PATH (tidak menyentuh Docker, jaringan, atau repo nyata), dan salinan skrip
 * helper ditulis ulang agar `REPORT` menunjuk berkas temp (bukan /tmp bersama).
 */
class SelfUpdateOwnershipTest extends TestCase
{
    /**
     * uid/gid "pemilik repo" pada fixture preflight. Sengaja **bukan** 0: root
     * menembus bit mode, jadi fixture milik root tidak membuktikan apa pun.
     */
    private const FIXTURE_UID = 33;
    private const FIXTURE_GID = 33;

    private string $root;
    private string $tmp;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->tmp = sys_get_temp_dir() . '/rames-owner-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================
    // (A) canUidWritePath() — murni, dihitung dari stat
    // ==================================================================

    public function testOwnerUidMayWriteItsOwnDirectory(): void
    {
        foreach ([0700, 0750, 0755] as $mode) {
            $dir = $this->tmp . '/owner-' . decoct($mode);
            mkdir($dir, 0700, true);
            [$owner, $group] = $this->ownedByFixture($dir);
            chmod($dir, $mode);

            $this->assertTrue(
                UpdateService::canUidWritePath($dir, $owner, $group),
                'pemilik direktori harus bisa menulis mode ' . sprintf('%04o', $mode)
            );
        }
    }

    public function testMatchingGroupAppliesGroupBitsOnly(): void
    {
        $dir = $this->tmp . '/group-770';
        mkdir($dir, 0700, true);
        [$owner, $group] = $this->ownedByFixture($dir);
        chmod($dir, 0770);

        $stranger = $owner + 1;

        // uid lain, tapi gid cocok dan grup punya rwx → boleh.
        $this->assertTrue(UpdateService::canUidWritePath($dir, $stranger, $group));

        // Grup tanpa bit tulis (0750 = r-x) → tidak boleh, walau gid cocok.
        chmod($dir, 0750);
        $this->assertFalse(UpdateService::canUidWritePath($dir, $stranger, $group));

        // Pemilik tetap boleh (bit owner rwx).
        $this->assertTrue(UpdateService::canUidWritePath($dir, $owner, $group));
    }

    public function testOtherWriteBitAppliesToUnknownUidAndGid(): void
    {
        $dir = $this->tmp . '/other-707';
        mkdir($dir, 0700, true);
        [$owner] = $this->ownedByFixture($dir);
        chmod($dir, 0707);

        // uid/gid sama-sama bukan pemilik/grup → bit `other` (rwx) yang dipakai.
        $this->assertTrue(UpdateService::canUidWritePath($dir, $owner + 1, $owner + 2));

        // `w` tanpa `x` pada direktori tetap tidak berguna (`git fetch` harus
        // membuat entri baru di dalamnya).
        chmod($dir, 0702);
        $this->assertFalse(UpdateService::canUidWritePath($dir, $owner + 1, $owner + 2));
    }

    public function testUidAndGidMismatchWithMode755IsNotWritable(): void
    {
        // REPRODUKSI BUG: direktori 0755 milik uid lain — persis `.git`/`.git/objects`
        // yang ditinggalkan `sudo git …` di host. Helper (uid pemilik repo) tidak
        // bisa menulis object apa pun di sana.
        $dir = $this->tmp . '/root-owned-755';
        mkdir($dir, 0700, true);
        [$owner, $group] = $this->ownedByFixture($dir);
        chmod($dir, 0755);

        $this->assertFalse(UpdateService::canUidWritePath($dir, $owner + 1, $owner + 1));
        $this->assertFalse(UpdateService::canUidWritePath($dir, $owner + 1, $group + 1));
        $this->assertTrue(UpdateService::canUidWritePath($dir, $owner, $group));
    }

    public function testMissingPathIsNeverWritableForNonRoot(): void
    {
        $this->assertFalse(UpdateService::canUidWritePath($this->tmp . '/tidak-ada', 1000, 1000));
    }

    public function testRootMayWriteRegardlessOfMode(): void
    {
        // Repo milik root + helper root: jangan sampai update yang sah diblokir
        // oleh bit mode (root menembus DAC).
        $dir = $this->tmp . '/root-repo';
        mkdir($dir, 0700, true);
        chmod($dir, 0500);

        $owner = (int) fileowner($dir);
        $this->assertFalse(UpdateService::canUidWritePath($dir, $owner + 1, $owner + 1));
        $this->assertTrue(UpdateService::canUidWritePath($dir, 0, 0));
    }

    public function testRegularFileNeedsOnlyWriteBit(): void
    {
        $file = $this->tmp . '/HEAD';
        file_put_contents($file, "ref: refs/heads/main\n");
        [$owner, $group] = $this->ownedByFixture($file);

        chmod($file, 0644);
        $this->assertTrue(UpdateService::canUidWritePath($file, $owner, $group));

        chmod($file, 0444);
        $this->assertFalse(UpdateService::canUidWritePath($file, $owner, $group));

        // 0646: bit tulis untuk other → uid/gid asing boleh menulis.
        chmod($file, 0646);
        $this->assertTrue(UpdateService::canUidWritePath($file, $owner + 1, $group + 1));
    }

    public function testHelperWritablePathsCoverWorktreeAndGitInternals(): void
    {
        $this->assertSame(
            ['/srv/rames', '/srv/rames/.git', '/srv/rames/.git/objects', '/srv/rames/.git/refs'],
            UpdateService::helperWritablePaths('/srv/rames')
        );
        // Trailing slash tidak boleh menghasilkan path ganda (//.git).
        $this->assertSame(
            UpdateService::helperWritablePaths('/srv/rames'),
            UpdateService::helperWritablePaths('/srv/rames/')
        );
    }

    public function testUnwritableHelperPathsReportsModeForDiagnosis(): void
    {
        $root = $this->tmp . '/repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        chmod($root, 0775);
        $this->chownTree($root, self::FIXTURE_UID, self::FIXTURE_GID);

        // Semua milik uid pemilik repo → tidak ada offender.
        $this->assertSame([], UpdateService::unwritableHelperPaths($root, self::FIXTURE_UID, self::FIXTURE_GID));

        // `.git` kembali ke uid lain (0755, sisa `sudo git …`) → dilaporkan + mode
        // oktalnya supaya operator tahu apa yang harus di-`chown`.
        $this->chownPath($root . '/.git', 0, 0);
        $this->assertSame(
            [$root . '/.git (mode 0755)'],
            UpdateService::unwritableHelperPaths($root, self::FIXTURE_UID, self::FIXTURE_GID)
        );
    }

    // ==================================================================
    // (B) preflight() menolak lebih awal (dengan perintah chown)
    // ==================================================================

    public function testPreflightFailsWhenGitInternalIsNotWritableByRepoOwner(): void
    {
        $root = $this->fixtureRepo();
        // Pemilik direktori repo = uid fixture; `.git` sengaja TETAP milik root
        // 0755 → kepemilikan campuran, persis kasus `sudo git …` di host.
        $this->chownPath($root, self::FIXTURE_UID, self::FIXTURE_GID);
        $this->assertSame(self::FIXTURE_UID, fileowner($root), 'fixture harus dimiliki uid yang diuji');

        $pf = $this->service($root)->preflight();

        $this->assertFalse($pf['ok'], 'preflight harus menolak: ' . implode(' | ', $pf['errors']));
        // Fixture ini juga memicu galat "dubious ownership" (gitdir milik root),
        // jadi galat yang diuji di sini disaring dari pesannya.
        $ownership = array_values(array_filter(
            $pf['errors'],
            static fn (string $e): bool => str_contains($e, 'tidak bisa ditulis')
        ));
        $this->assertCount(1, $ownership, 'tepat satu galat "tidak bisa ditulis": ' . implode(' | ', $pf['errors']));

        $message = $ownership[0];
        $this->assertStringContainsString(self::FIXTURE_UID . ':' . self::FIXTURE_GID, $message);
        $this->assertStringContainsString($root . '/.git', $message);
        $this->assertStringContainsString('mode 0755', $message);
        $this->assertStringContainsString(
            'sudo chown -R ' . self::FIXTURE_UID . ':' . self::FIXTURE_GID . ' ' . $root,
            $message
        );
        // Pesan tanpa rahasia apa pun.
        $this->assertStringNotContainsString('token', strtolower($message));
    }

    public function testPreflightPassesOwnershipCheckWhenRepoOwnerCanWriteEverything(): void
    {
        $root = $this->fixtureRepo();
        $this->chownTree($root, self::FIXTURE_UID, self::FIXTURE_GID);
        $this->assertSame(self::FIXTURE_UID, fileowner($root));

        $pf = $this->service($root)->preflight();

        foreach ($pf['errors'] as $error) {
            $this->assertStringNotContainsString('chown', $error, 'tidak boleh ada galat kepemilikan: ' . $error);
            $this->assertStringNotContainsString('dubious ownership', $error, $error);
            $this->assertStringNotContainsString(self::FIXTURE_UID . ':' . self::FIXTURE_GID, $error);
        }
    }

    public function testPreflightFailsWhenGitDirIsOwnedByAnotherUser(): void
    {
        // Kasus kanonik laporan verifikator: repo milik uid 33, `.git` milik root
        // dengan grup repo (0775) → izin tulis CUKUP (gate `[ -w ]` maupun
        // `unwritableHelperPaths()` lolos), tetapi git sendiri menolak dengan
        // `fatal: detected dubious ownership in repository at '<root>'`.
        $root = $this->fixtureRepo();
        $this->chownTree($root, self::FIXTURE_UID, self::FIXTURE_GID);
        $this->chownTree($root . '/.git', 0, self::FIXTURE_GID);
        foreach (['', '/objects', '/refs'] as $sub) {
            chmod($root . '/.git' . $sub, 0775);
        }

        $this->assertSame(self::FIXTURE_UID, fileowner($root));
        $this->assertSame(0, fileowner($root . '/.git'), 'fixture: gitdir milik uid lain');

        $pf = $this->service($root)->preflight();

        $this->assertFalse($pf['ok']);
        $dubious = array_values(array_filter(
            $pf['errors'],
            static fn (string $e): bool => str_contains($e, 'dubious ownership')
        ));
        $this->assertCount(1, $dubious, 'tepat satu galat dubious ownership: ' . implode(' | ', $pf['errors']));

        $message = $dubious[0];
        $this->assertStringContainsString($root . '/.git', $message);
        $this->assertStringContainsString('0:' . self::FIXTURE_GID, $message, 'owner gitdir yang ditemukan');
        $this->assertStringContainsString(self::FIXTURE_UID . ':' . self::FIXTURE_GID, $message, 'owner repo');
        $this->assertStringContainsString(
            'sudo chown -R ' . self::FIXTURE_UID . ':' . self::FIXTURE_GID . ' ' . $root,
            $message
        );

        // Fixture ini presisi: galat "tidak bisa ditulis" TIDAK ikut muncul (grup
        // repo punya rwx) — yang menolak memang kepemilikan gitdir-nya.
        foreach ($pf['errors'] as $error) {
            $this->assertStringNotContainsString('tidak bisa ditulis', $error, $error);
        }
    }

    public function testPreflightRejectsGitDirThatIsNotADirectory(): void
    {
        // Layout berkas worktree (`gitdir:`): bukan urusan izin, tetapi helper
        // membaca `.git/HEAD` langsung dan menjalankan `git fetch` di dalam gitdir.
        $root = $this->tmp . '/worktree-repo';
        mkdir($root, 0755, true);
        file_put_contents($root . '/.git', "gitdir: /srv/git/worktrees/x\n");

        $pf = $this->service($root)->preflight();

        $this->assertFalse($pf['ok']);
        $layout = $this->layoutError($pf['errors']);
        $this->assertStringContainsString($root . '/.git', $layout);
        $this->assertStringContainsString('gitdir:', $layout, 'sebab layout (worktree `gitdir:`) harus disebut');

        // N1: owner `.git` (berkas) SAMA dengan owner direktori repo → `chown`
        // tidak mengubah apa pun, jadi tidak boleh dicetak seolah perintah wajib.
        $this->assertSame(
            (int) fileowner($root),
            (int) fileowner($root . '/.git'),
            'fixture: owner sama'
        );
        $this->assertStringNotContainsString('sudo chown', $layout);
        if ((int) fileowner($root) > 0) {
            // Bila uji dijalankan sebagai root, repo fixture memang milik uid 0 →
            // galat "dimiliki root" (dengan `chown`) memang WAJIB muncul; asersi
            // "tanpa chown" hanya berlaku untuk repo milik uid non-root.
            $this->assertStringNotContainsString('sudo chown', implode(' | ', $pf['errors']));
        }
    }

    public function testLayoutErrorStillOffersChownWhenGitFileOwnerDiffers(): void
    {
        // Anti-regresi N1: pada layout yang SAMA tetapi kepemilikannya benar-benar
        // campur, jalan keluar `chown` tetap harus ditawarkan (owner ditemukan +
        // owner repo + perintahnya).
        $root = $this->tmp . '/worktree-repo-foreign';
        mkdir($root, 0755, true);
        file_put_contents($root . '/.git', "gitdir: /srv/git/worktrees/x\n");
        $this->chownPath($root, self::FIXTURE_UID, self::FIXTURE_GID); // skip bila bukan root

        $pf = $this->service($root)->preflight();

        $this->assertFalse($pf['ok']);
        $layout = $this->layoutError($pf['errors']);
        $this->assertStringContainsString($root . '/.git', $layout);
        $this->assertStringContainsString('BUKAN direktori', $layout);
        $this->assertStringContainsString('gitdir:', $layout);

        $found = (int) fileowner($root . '/.git') . ':' . (int) filegroup($root . '/.git');
        $this->assertNotSame($found, self::FIXTURE_UID . ':' . self::FIXTURE_GID, 'fixture: owner berbeda');
        $this->assertStringContainsString($found, $layout, 'owner yang ditemukan');
        $this->assertStringContainsString(self::FIXTURE_UID . ':' . self::FIXTURE_GID, $layout, 'owner repo');
        $this->assertStringContainsString(
            'sudo chown -R ' . self::FIXTURE_UID . ':' . self::FIXTURE_GID . ' ' . $root,
            $layout
        );
    }

    // ==================================================================
    // (C) cli/self-update.sh — gate sebelum git + klasifikasi galat
    // ==================================================================

    public function testHelperScriptPassesPosixShellSyntaxCheck(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-n', $this->root . '/cli/self-update.sh'], $this->root, 15);

        $this->assertSame(0, $result['code'], $result['stderr']);
    }

    public function testOwnershipGateIsDeclaredBeforeAnyRepoMutatingGitCall(): void
    {
        $src = (string) file_get_contents($this->root . '/cli/self-update.sh');

        $gate = strpos($src, 'for gate_path in');
        $fetch = strpos($src, 'run_git fetch');
        $this->assertNotFalse($gate, 'gate kepemilikan harus ada di skrip helper');
        $this->assertNotFalse($fetch);
        $this->assertLessThan($fetch, $gate, 'gate kepemilikan harus mendahului git fetch di dalam skrip');

        // Tidak ada `git fetch|checkout|merge|reset|pull` yang dipanggil langsung di
        // posisi command (semuanya lewat `run_git()`) supaya jejaknya bisa
        // diklasifikasi izin-vs-jaringan.
        $this->assertSame(
            0,
            preg_match_all('/^[ \t]*(?:if ! )?git\s+(?:fetch|checkout|merge|reset|pull)\b/m', $src),
            'git yang menyentuh repo harus lewat run_git()'
        );
        foreach ([
            'run_git fetch --prune origin "$BRANCH"',
            'run_git checkout --force',
            'run_git merge --ff-only "origin/$BRANCH"',
            'run_git reset --hard "$OLD_SHA"',
        ] as $call) {
            $this->assertStringContainsString($call, $src);
        }

        // Daftar path gate shell = daftar preflight PHP (`helperWritablePaths()`),
        // termasuk `.git/refs` (R3: dulu gate hanya memeriksa root/.git/objects).
        $gateLine = '';
        foreach (explode("\n", $src) as $line) {
            if (str_contains($line, 'for gate_path in')) {
                $gateLine = trim($line);
                break;
            }
        }
        $this->assertSame(
            'for gate_path in "$ROOT" "$ROOT/.git" "$ROOT/.git/objects" "$ROOT/.git/refs"; do',
            $gateLine
        );
        foreach (UpdateService::helperWritablePaths('/srv/rames') as $path) {
            $suffix = substr($path, strlen('/srv/rames'));
            $this->assertStringContainsString(
                '"$ROOT' . $suffix . '"',
                $src,
                'gate shell harus memeriksa path yang sama dengan preflight: ' . $path
            );
        }
    }

    public function testHelperRefusesToTouchGitWhenRepoPathIsNotWritableByHelper(): void
    {
        $bin = $this->fakeBin();
        $trace = $this->traceFile('fatal: unable to access repository: Could not resolve host: github.com');
        $marker = $this->tmp . '/git-invoked';

        // `.git` ada (lolos pemeriksaan direktori) tetapi `.git/objects` tidak ada
        // → `[ -w ]` gagal bahkan saat uji ini dijalankan sebagai root (root pun
        // tidak bisa `[ -w ]` pada path yang tidak ada), sehingga cabang gate bisa
        // diuji tanpa mengubah kepemilikan fixture.
        $root = $this->tmp . '/gate-repo';
        mkdir($root . '/.git', 0755, true);
        $plan = $this->writePlan($root);

        $result = $this->runHelper($this->scriptUnderTest(), $plan, [
            'PATH' => $bin . ':' . (string) getenv('PATH'),
            'FAKE_GIT_MARKER' => $marker,
            'FAKE_GIT_TRACE' => $trace,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertFileDoesNotExist($marker, 'gate harus menolak SEBELUM git fetch dijalankan');
        $this->assertStringContainsString('gate kepemilikan gagal', (string) file_get_contents($this->tmp . '/runs/run.log'));

        $error = (string) $this->runFile()['error'];
        $this->assertStringContainsString('chown', $error);
        $this->assertStringContainsString('uid=', $error);
        $this->assertStringContainsString('.git/objects', $error);
        $this->assertStringContainsString('sudo chown -R', $error);
        $this->assertStringNotContainsString('jaringan', $error);
        $this->assertStringNotContainsString('DNS', $error);
    }

    public function testHelperGateIsAuthoritativeWhenRunAsAForeignUid(): void
    {
        if (posix_geteuid() !== 0) {
            self::markTestSkipped('butuh root untuk menjatuhkan uid helper (setpriv)');
        }
        $setpriv = trim((string) shell_exec('command -v setpriv 2>/dev/null'));
        if ($setpriv === '') {
            self::markTestSkipped('setpriv tidak tersedia');
        }

        $bin = $this->fakeBin();
        $trace = $this->traceFile('fatal: unable to access repository: Could not resolve host: github.com');
        $marker = $this->tmp . '/git-invoked';

        // Repo milik root mode 0755 → helper sebagai uid 33 (kasus laporan user:
        // helper uid=33:33 di repo milik uid lain) tidak bisa menulis apa pun.
        $root = $this->tmp . '/foreign-repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        chmod($this->tmp, 0777);
        chmod($root, 0755);
        $plan = $this->writePlan($root);

        $result = (new ProcessRunner())->run(
            [$setpriv, '--reuid', '33', '--regid', '33', '--clear-groups', 'sh', $this->scriptUnderTest(), $plan],
            $this->tmp,
            60,
            [
                'PATH' => $bin . ':' . (string) getenv('PATH'),
                'FAKE_GIT_MARKER' => $marker,
                'FAKE_GIT_TRACE' => $trace,
                'HOME' => '/tmp',
            ]
        );

        $this->assertSame(1, $result['code'], $result['stderr']);
        $this->assertFileDoesNotExist($marker, 'git tidak boleh dijalankan saat gate gagal');
        $this->assertStringContainsString(
            'gate kepemilikan gagal',
            (string) file_get_contents($this->tmp . '/runs/run.log')
        );

        $error = (string) $this->runFile()['error'];
        $this->assertStringContainsString('uid=33:33', $error);
        $this->assertStringContainsString('sudo chown -R 33:33 ' . $root, $error);
        $this->assertStringNotContainsString('jaringan', $error);
    }

    public function testHelperClassifiesDubiousOwnershipAsOwnershipNotNetwork(): void
    {
        $bin = $this->fakeBin();
        // Jejak nyata git 2.49 saat gitdir milik uid lain (`safe.directory` muncul
        // di saran perbaikannya — pemicunya tetap kepemilikan, bukan jaringan).
        $trace = $this->traceFile(implode("\n", [
            "fatal: detected dubious ownership in repository at '/srv/rames'",
            "'/srv/rames/.git' is owned by:",
            "\troot (uid = 0)",
            "but the current user is:",
            "\tw3 (uid = 33)",
            "To add an exception for this directory, call:",
            '',
            "\tgit config --global --add safe.directory /srv/rames",
        ]));
        $marker = $this->tmp . '/git-invoked';

        // Gate `[ -w ]` lolos (izin tulis cukup) → yang menolak adalah git sendiri.
        $root = $this->tmp . '/dubious-repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        $plan = $this->writePlan($root);

        $result = $this->runHelper($this->scriptUnderTest(), $plan, [
            'PATH' => $bin . ':' . (string) getenv('PATH'),
            'FAKE_GIT_MARKER' => $marker,
            'FAKE_GIT_TRACE' => $trace,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertFileExists($marker, 'gate lolos → git benar-benar dijalankan');

        $run = $this->runFile();
        $this->assertSame('error', $run['result']);
        $error = (string) $run['error'];
        $this->assertStringContainsString('kepemilikan/izin', $error);
        $this->assertStringContainsString('chown', $error);
        $this->assertStringNotContainsString('jaringan', $error);
        $this->assertStringNotContainsString('DNS', $error);

        $log = (string) file_get_contents($this->tmp . '/runs/run.log');
        $this->assertStringContainsString('dubious ownership', $log, 'jejak mentah git tetap di log');
    }

    public function testHelperClassifiesPermissionFailureAsOwnershipNotNetwork(): void
    {
        $bin = $this->fakeBin();
        $trace = $this->traceFile('error: insufficient permission for adding an object to repository database .git/objects');
        $marker = $this->tmp . '/git-invoked';

        // Gate lolos: repo bisa ditulis helper → kegagalan di bawah datang dari git.
        $root = $this->tmp . '/perm-repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        $plan = $this->writePlan($root);

        $result = $this->runHelper($this->scriptUnderTest(), $plan, [
            'PATH' => $bin . ':' . (string) getenv('PATH'),
            'FAKE_GIT_MARKER' => $marker,
            'FAKE_GIT_TRACE' => $trace,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertFileExists($marker, 'gate lolos → git fetch benar-benar dijalankan');

        $run = $this->runFile();
        $this->assertSame('error', $run['result']);
        $this->assertSame('fetch', $run['stage']);
        $error = (string) $run['error'];
        $this->assertStringContainsString('chown', $error);
        $this->assertStringContainsString('kepemilikan/izin', $error);
        $this->assertStringNotContainsString('jaringan', $error);

        // Log helper tetap memuat jejak mentah git + info network/dns (berguna
        // untuk kasus jaringan yang asli) — hanya kesimpulan di UI yang dipisah.
        $log = (string) file_get_contents($this->tmp . '/runs/run.log');
        $this->assertStringContainsString('insufficient permission', $log);
        $this->assertStringContainsString('network=rames_default dns=', $log);
        $this->assertStringContainsString('8.8.8.8', $log);
    }

    public function testHelperReportsOwnershipWhenCheckoutHitsWorktreeFilePermissions(): void
    {
        // Batas R2 (pra-cek direktori/berkas worktree sebelum merge) sengaja TIDAK
        // diimplementasikan sekarang: yang dipastikan di sini adalah **pesannya
        // benar** — kegagalan `git checkout` karena izin berkas worktree juga
        // dilaporkan sebagai kepemilikan/izin, bukan jaringan/DNS.
        $bin = $this->fakeBin();
        $trace = $this->traceFile('error: unable to create file src/app.php: Permission denied');
        $marker = $this->tmp . '/git-invoked';

        $root = $this->tmp . '/checkout-repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        $plan = $this->writePlan($root);

        $result = $this->runHelper($this->scriptUnderTest(), $plan, [
            'PATH' => $bin . ':' . (string) getenv('PATH'),
            'FAKE_GIT_MARKER' => $marker,
            'FAKE_GIT_TRACE' => $trace,
            'FAKE_GIT_FETCH_OK' => '1',
        ]);

        $this->assertSame(1, $result['code']);
        $run = $this->runFile();
        $this->assertSame('error', $run['result']);
        $error = (string) $run['error'];
        $this->assertStringContainsString('chown', $error);
        $this->assertStringContainsString('kepemilikan/izin', $error);
        $this->assertStringNotContainsString('jaringan', $error);
        $this->assertStringContainsString(
            'git checkout main gagal',
            (string) file_get_contents($this->tmp . '/runs/run.log')
        );
    }

    public function testHelperKeepsNetworkConclusionForGenuineNetworkFailure(): void
    {
        $bin = $this->fakeBin();
        $trace = $this->traceFile('fatal: unable to access https://github.com/x/y: Could not resolve host: github.com');
        $marker = $this->tmp . '/git-invoked';

        $root = $this->tmp . '/net-repo';
        mkdir($root . '/.git/objects', 0755, true);
        mkdir($root . '/.git/refs', 0755, true);
        $plan = $this->writePlan($root);

        $result = $this->runHelper($this->scriptUnderTest(), $plan, [
            'PATH' => $bin . ':' . (string) getenv('PATH'),
            'FAKE_GIT_MARKER' => $marker,
            'FAKE_GIT_TRACE' => $trace,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertFileExists($marker);

        $error = (string) $this->runFile()['error'];
        $this->assertStringContainsString('jaringan/DNS', $error);
        $this->assertStringContainsString('network rames_default', $error);
        $this->assertStringNotContainsString('chown', $error);

        $log = (string) file_get_contents($this->tmp . '/runs/run.log');
        $this->assertStringContainsString('Could not resolve host', $log);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Fixture repo minimal: `.git` (milik proses uji), berkas helper, dan HEAD.
     */
    private function fixtureRepo(): string
    {
        $root = $this->tmp . '/repo';
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
     * `UpdateService` dengan fixture temp + Engine palsu (selfContext tidak
     * menyentuh Docker/network nyata).
     */
    private function service(string $root): UpdateService
    {
        return new UpdateService(
            new RepoInfo($root),
            new UpdateState($this->tmp . '/check.json', $this->tmp . '/runs'),
            new class extends DockerClient {
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

                /**
                 * Tidak boleh tersentuh: fixture preflight tidak sedang menjalankan
                 * helper (run.json belum ada) — kalau tersentuh, uji ini salah.
                 */
                public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
                {
                    throw new RuntimeException('listContainers tidak dipakai di fixture preflight');
                }
            }
        );
    }

    /**
     * Salinan `cli/self-update.sh` dengan `REPORT` diarahkan ke berkas temp supaya
     * uji tidak menulis ke `/tmp/rames-update-report.php` bersama.
     */
    private function scriptUnderTest(): string
    {
        $report = $this->tmp . '/update-report.php';
        copy($this->root . '/cli/update-report.php', $report);

        $src = (string) file_get_contents($this->root . '/cli/self-update.sh');
        $patched = str_replace('REPORT=/tmp/rames-update-report.php', 'REPORT=' . $report, $src);
        $this->assertStringContainsString('REPORT=' . $report, $patched, 'konstanta REPORT skrip helper harus ada');

        $path = $this->tmp . '/self-update.sh';
        file_put_contents($path, $patched);

        return $path;
    }

    /**
     * `docker`/`git` palsu di PATH. `git` mencatat pemanggilannya ke
     * $FAKE_GIT_MARKER dan mencetak jejak dari $FAKE_GIT_TRACE.
     */
    private function fakeBin(): string
    {
        $bin = $this->tmp . '/bin';
        mkdir($bin, 0755, true);

        file_put_contents($bin . '/docker', <<<'SH'
#!/bin/sh
case "$1" in
  version) printf '29.9.0\n'; exit 0 ;;
esac
printf 'fake docker: %s\n' "$*" >&2
exit 1
SH);

        file_put_contents($bin . '/git', <<<'SH'
#!/bin/sh
: > "$FAKE_GIT_MARKER"
if [ "${FAKE_GIT_FETCH_OK:-0}" = "1" ] && [ "$1" = "fetch" ]; then
  exit 0
fi
cat "$FAKE_GIT_TRACE" >&2
exit 1
SH);

        chmod($bin . '/docker', 0755);
        chmod($bin . '/git', 0755);

        return $bin;
    }

    private function traceFile(string $text): string
    {
        $path = $this->tmp . '/git-trace.txt';
        file_put_contents($path, $text . "\n");

        return $path;
    }

    /**
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function runHelper(string $script, string $plan, array $env): array
    {
        return (new ProcessRunner())->run(['sh', $script, $plan], $this->tmp, 60, $env);
    }

    /**
     * Plan minimal seperti yang ditulis `UpdateService::start()`.
     *
     * @param array<string,mixed> $overrides
     */
    private function writePlan(string $root, array $overrides = []): string
    {
        $runDir = $this->tmp . '/runs';
        $plan = array_merge([
            'id' => '20261016-070000-abc123',
            'mode' => 'update',
            'root' => $root,
            'branch' => 'main',
            'project' => 'rames',
            'service' => 'webman',
            'old_sha' => str_repeat('a', 40),
            'target_sha' => '',
            'health_url' => 'http://rames-webman:8787/healthz',
            'health_timeout' => 5,
            'rollback_timeout' => 5,
            'run_dir' => $runDir,
            'log_file' => $runDir . '/run.log',
            'network' => 'rames_default',
            'dns' => ['8.8.8.8', '1.1.1.1'],
            'actor' => 'admin',
        ], $overrides);

        $path = $this->tmp . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function runFile(): array
    {
        $raw = (string) file_get_contents($this->tmp . '/runs/run.json');
        $data = json_decode($raw, true);
        $this->assertIsArray($data);

        return $data;
    }

    private function chownPath(string $path, int $uid, int $gid): void
    {
        if (!@chown($path, $uid) || !@chgrp($path, $gid)) {
            self::markTestSkipped('fixture kepemilikan campuran butuh root (chown/chgrp)');
        }
    }

    /**
     * Galat layout `.git` bukan direktori dari daftar galat preflight (tepat satu).
     *
     * @param array<int,string> $errors
     */
    private function layoutError(array $errors): string
    {
        $found = array_values(array_filter(
            $errors,
            static fn (string $e): bool => str_contains($e, 'BUKAN direktori')
        ));
        $this->assertCount(1, $found, implode(' | ', $errors));

        return $found[0];
    }

    /**
     * uid/gid fixture yang bisa diuji. Saat uji dijalankan sebagai **root**, berkas
     * fixture otomatis milik uid 0 — dan uid 0 menembus bit mode (`canUidWritePath`
     * sengaja memperlakukannya begitu), sehingga uji bit owner/group/other tidak
     * akan membuktikan apa pun. Fixture di-`chown` ke uid non-root; uji di-skip
     * bila proses tidak berhak `chown`.
     *
     * @return array{0:int,1:int}
     */
    private function ownedByFixture(string $path): array
    {
        $owner = (int) fileowner($path);
        $group = (int) filegroup($path);
        if ($owner !== 0) {
            return [$owner, $group];
        }

        $this->chownPath($path, self::FIXTURE_UID, self::FIXTURE_GID);

        return [self::FIXTURE_UID, self::FIXTURE_GID];
    }

    private function chownTree(string $dir, int $uid, int $gid): void
    {
        $this->chownPath($dir, $uid, $gid);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            $this->chownPath($entry->getPathname(), $uid, $gid);
        }
    }
}
