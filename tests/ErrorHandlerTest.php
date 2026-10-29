<?php
declare(strict_types=1);

namespace Tests;

use app\exception\Handler;
use app\library\Auth\AppAccessDenied;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test hook exception server: {@see Handler::report()} mencatat error 5xx lewat
 * `ErrorReporter`, tetapi **tidak** mencatat `BusinessException` (404 disengaja)
 * dan tidak fatal saat store bermasalah.
 *
 * Handler diinstansiasi dengan logger null (konstruktor induk webman) —
 * tanpa HTTP, tanpa logger nyata, DB temp.
 */
class ErrorHandlerTest extends TestCase
{
    private string $tmp;

    private string $file;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/errhandler_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';

        $this->useConfig($this->file);
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    public function testReportRecordsRuntimeException(): void
    {
        $handler = new Handler(new NullLogger(), false);

        $handler->report(new RuntimeException('Boom dari handler'));

        self::assertSame(1, $this->eventCount());
        $message = (string) (new SqliteDatabase($this->file))->pdo()
            ->query('SELECT "message" FROM "error_groups" LIMIT 1')
            ->fetchColumn();
        self::assertStringContainsString('RuntimeException: Boom dari handler', $message);
    }

    public function testReportSkipsBusinessException(): void
    {
        $handler = new Handler(new NullLogger(), false);

        $handler->report(new AppAccessDenied('view', 'app-1'));

        self::assertSame(0, $this->eventCount());
    }

    public function testReportNeverFatalWhenStoreIsBroken(): void
    {
        // DB tidak bisa dibuka (path = direktori) → ErrorReporter harus menelan.
        mkdir($this->tmp . '/rusak', 0777, true);
        $this->useConfig($this->tmp . '/rusak');

        $handler = new Handler(new NullLogger(), false);
        $handler->report(new RuntimeException('Store rusak'));

        self::assertTrue(true, 'report tidak boleh melempar');
    }

    public function testConfigPointsToAppHandler(): void
    {
        $config = require dirname(__DIR__) . '/config/exception.php';

        self::assertSame(Handler::class, $config[''] ?? null);

        $reflection = new ReflectionClass(Handler::class);
        self::assertTrue($reflection->isSubclassOf(\support\exception\Handler::class));
        self::assertTrue($reflection->hasMethod('report'));
        // `render()` tidak ditimpa (perilaku framework dipertahankan).
        self::assertSame(
            \support\exception\Handler::class,
            $reflection->getMethod('render')->getDeclaringClass()->getName()
        );
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function eventCount(): int
    {
        return (int) (new SqliteDatabase($this->file))->pdo()
            ->query('SELECT COUNT(*) FROM "error_events"')
            ->fetchColumn();
    }

    private function useConfig(string $sqliteFile): void
    {
        $configDir = $this->tmp . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }

        file_put_contents($configDir . '/app.php', "<?php return [];\n");
        file_put_contents(
            $configDir . '/deploy.php',
            '<?php return ' . var_export([
                'mode' => 'dashboard',
                'sqlite_file' => $sqliteFile,
                'sqlite_dir' => $this->tmp,
                'apps_path' => $this->tmp . '/apps',
                'app_domain' => 'example.org',
            ], true) . ';' . PHP_EOL
        );
        file_put_contents(
            $configDir . '/errors.php',
            '<?php return ' . var_export([
                'retention_days' => 30,
                'max_events' => 5000,
                'max_samples_per_group' => 20,
                'max_payload_bytes' => 16384,
                'rate_per_minute' => 20,
                'max_message_chars' => 2000,
                'max_stack_bytes' => 8192,
                'per_page' => 50,
            ], true) . ';' . PHP_EOL
        );

        Config::clear();
        Config::load($configDir);
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
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
