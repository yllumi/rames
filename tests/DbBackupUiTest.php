<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Storage\DbBackup;
use app\library\Storage\SqliteDatabase;
use app\middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

/**
 * Test mediator kartu "Database dashboard (SQLite)" di `BackupController`:
 *  - admin-only 404 untuk non-admin (tanpa mutasi berkas);
 *  - POST tanpa token CSRF ditolak `CsrfMiddleware` (419);
 *  - restore tanpa `confirm=RESTORE` ditolak TANPA menyentuh berkas DB;
 *  - unduh dengan path traversal ditolak (404);
 *  - run sukses membuat snapshot + menulis flash; restore sukses + peringatan reload.
 *
 * Tanpa HTTP/Docker. Semua path diarahkan ke temp (`deploy.sqlite_file`/
 * `deploy.db_backup_dir` + env `RAMES_DB_BACKUP_STATE`) sehingga `runtime/` dan
 * `database/` nyata tidak tersentuh.
 */
class DbBackupUiTest extends TestCase
{
    private string $tmp;
    private string $dbFile;
    private string $backupDir;
    private string $stateFile;

    /** @var string|false */
    private string|false $savedStateEnv;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        parent::setUp();
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/rames-dbbackup-ui-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/db', 0777, true);
        mkdir($this->tmp . '/backup', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/runtime', 0777, true);

        $this->dbFile = $this->tmp . '/db/rames.sqlite';
        $this->backupDir = $this->tmp . '/backup';
        $this->stateFile = $this->tmp . '/runtime/db-backup/state.json';

        $this->configState = $this->snapshotConfigState();
        $this->savedStateEnv = getenv('RAMES_DB_BACKUP_STATE');
        putenv('RAMES_DB_BACKUP_STATE=' . $this->stateFile);

        Config::clear();
        file_put_contents(
            $this->tmp . '/config/app.php',
            '<?php return ' . var_export(['runtime_path' => $this->tmp . '/runtime'], true) . ';' . PHP_EOL
        );
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->dbFile,
                'db_backup_dir' => $this->backupDir,
                'database_path' => $this->tmp . '/db',
                'db_backup_enabled' => true,
                'db_backup_hour' => 3,
                'db_backup_keep_daily' => 7,
                'db_backup_keep_weekly' => 4,
                'db_backup_keep_monthly' => 3,
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        // Migrasi DB temp (membuat tabel inti) - bukan data runtime nyata.
        (new SqliteDatabase($this->dbFile))->pdo();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        if ($this->savedStateEnv === false) {
            putenv('RAMES_DB_BACKUP_STATE');
        } else {
            putenv('RAMES_DB_BACKUP_STATE=' . $this->savedStateEnv);
        }

        SqliteDatabase::reset();
        self::removeTree($this->tmp);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Admin-only -> 404 (tanpa mutasi)
    // ------------------------------------------------------------------

    public function testMemberGets404OnEveryEndpointWithoutMutation(): void
    {
        $controller = $this->controller(false);

        $responses = [
            $controller->dbRun($this->post('/backups/db/run')),
            $controller->dbPrune($this->post('/backups/db/prune')),
            $controller->dbRestore($this->post('/backups/db/restore', ['file' => 'x.sqlite', 'confirm' => 'RESTORE'])),
            $controller->dbDownload($this->get('/backups/db/download?file=rames-20260101-030000.sqlite')),
        ];

        foreach ($responses as $response) {
            $this->assertSame(404, $response->getStatusCode());
        }

        $this->assertSame([], glob($this->backupDir . '/rames-*.sqlite') ?: [], 'non-admin tidak memicu snapshot');
        $this->assertSame([], $controller->flashes, 'non-admin tidak diberi flash');
        $this->assertFileDoesNotExist($this->stateFile, 'state tidak ditulis');
    }

    // ------------------------------------------------------------------
    // CSRF (middleware)
    // ------------------------------------------------------------------

    public function testDbRunPostWithoutCsrfTokenIsRejectedWith419(): void
    {
        $handled = false;

        $response = (new CsrfMiddleware())->process(
            $this->post('/backups/db/run', [], true),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        $this->assertFalse($handled, 'POST tanpa token tidak boleh diteruskan ke controller');
        $this->assertSame(419, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Restore: konfirmasi wajib & efek samping
    // ------------------------------------------------------------------

    public function testRestoreWithoutConfirmIsRejectedAndLeavesDbUntouched(): void
    {
        $controller = $this->controller(true);
        $before = (string) file_get_contents($this->dbFile);

        $response = $controller->dbRestore($this->post('/backups/db/restore', [
            'file' => 'rames-20260101-030000.sqlite',
            'confirm' => 'bukan-restore',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/backups#db-backup', $response->getHeader('Location'));
        $this->assertSame('error', $controller->flashes[0]['type'] ?? '');
        $this->assertSame($before, (string) file_get_contents($this->dbFile), 'berkas DB tidak berubah');
        $this->assertSame([], glob($this->backupDir . '/pre-restore-*.sqlite') ?: [], 'tanpa safety snapshot');
    }

    public function testRestoreWithConfirmSucceedsAndWarnsToReload(): void
    {
        $entry = (new DbBackup($this->dbFile, $this->backupDir))->run('manual');

        $controller = $this->controller(true);
        $response = $controller->dbRestore($this->post('/backups/db/restore', [
            'file' => $entry['file'],
            'confirm' => 'RESTORE',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('success', $controller->flashes[0]['type'] ?? '');
        $this->assertStringContainsString('start.php reload', (string) ($controller->flashes[0]['message'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Unduh
    // ------------------------------------------------------------------

    public function testDownloadRejectsPathTraversal(): void
    {
        $controller = $this->controller(true);

        $response = $controller->dbDownload(
            $this->get('/backups/db/download?' . http_build_query(['file' => '../../etc/passwd']))
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDownloadStreamsExistingSnapshot(): void
    {
        $entry = (new DbBackup($this->dbFile, $this->backupDir))->run('manual');

        $controller = $this->controller(true);
        $response = $controller->dbDownload($this->get('/backups/db/download?' . http_build_query(['file' => $entry['file']])));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment', (string) $response->getHeader('Content-Disposition'));
    }

    // ------------------------------------------------------------------
    // Run / prune sukses -> flash
    // ------------------------------------------------------------------

    public function testAdminRunCreatesSnapshotAndWritesFlash(): void
    {
        $controller = $this->controller(true);

        $response = $controller->dbRun($this->post('/backups/db/run'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/backups#db-backup', $response->getHeader('Location'));
        $this->assertSame('success', $controller->flashes[0]['type'] ?? '');
        $this->assertNotSame([], glob($this->backupDir . '/rames-*.sqlite') ?: [], 'snapshot dibuat');
        $this->assertFileExists($this->stateFile, 'state ditulis ke path temp');
    }

    public function testAdminPruneReportsCountsInFlash(): void
    {
        $controller = $this->controller(true);

        $response = $controller->dbPrune($this->post('/backups/db/prune'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('success', $controller->flashes[0]['type'] ?? '');
        $this->assertStringContainsString('Prune selesai', (string) ($controller->flashes[0]['message'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(bool $admin): FakeBackupDbController
    {
        $controller = new FakeBackupDbController();
        $controller->admin = $admin;

        return $controller;
    }

    /**
     * @param array<string,string> $fields
     */
    private function post(string $path, array $fields = [], bool $json = false): Request
    {
        $body = http_build_query($fields);
        $accept = $json ? "Accept: application/json\r\n" : '';

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n{$accept}"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

/**
 * Subclass uji: seam `isAdmin()`/`flash()` menggantikan session Webman
 * (butuh konteks HTTP) - pola `FakeCreditController`.
 */
class FakeBackupDbController extends BackupController
{
    public bool $admin = false;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    protected function isAdmin(): bool
    {
        return $this->admin;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }
}
