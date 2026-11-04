<?php
declare(strict_types=1);

namespace Tests\Support;

use app\controller\app\AccessController;
use app\controller\app\AppArtifactControllerBase;
use app\controller\app\AppControllerBase;
use app\controller\app\ComposeController;
use app\controller\app\ContainerController;
use app\controller\app\CreateController;
use app\controller\app\CreateDeployController;
use app\controller\app\DeploymentController;
use app\controller\app\DomainSettingsController;
use app\controller\app\EnvironmentController;
use app\controller\app\InfoController;
use app\controller\app\ListController;
use app\controller\app\TemplateController;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Penakar controller fitur app (`app\controller\app\*`).
 *
 * Sejak `AppController` dipecah per fitur, helper/privat yang dulu satu kelas
 * kini tersebar: satu method bisa dimiliki `AppControllerBase`,
 * `AppArtifactControllerBase`, atau controller fitur tertentu. Locator ini
 * memetakan **nama method → kelas pemiliknya** lewat refleksi sehingga test
 * tetap menguji perilaku yang sama tanpa menyalin peta kelas.
 *
 * Tanpa I/O dan tanpa state: kelas hanya diintrospeksi, bukan dijalankan.
 */
final class AppControllerLocator
{
    /** @var list<class-string> */
    private const CONTROLLERS = [
        AccessController::class,
        ComposeController::class,
        ContainerController::class,
        CreateController::class,
        CreateDeployController::class,
        DeploymentController::class,
        DomainSettingsController::class,
        EnvironmentController::class,
        InfoController::class,
        ListController::class,
        TemplateController::class,
        AppArtifactControllerBase::class,
        AppControllerBase::class,
    ];

    /**
     * Kelas yang **mendeklarasikan** `$method` (bukan mewarisinya).
     */
    public static function classFor(string $method): string
    {
        foreach (self::CONTROLLERS as $class) {
            if (!method_exists($class, $method)) {
                continue;
            }
            if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName() === $class) {
                return $class;
            }
        }

        foreach (self::CONTROLLERS as $class) {
            if (method_exists($class, $method)) {
                return $class;
            }
        }

        throw new RuntimeException('Method controller app tidak dikenal: ' . $method);
    }

    /**
     * Instance konkret pemilik method. Kelas basis abstract dipanggil lewat
     * subclass konkret (tidak ada state di controller, jadi aman).
     */
    public static function make(string $method): object
    {
        $class = self::classFor($method);

        if ((new ReflectionClass($class))->isAbstract()) {
            return new ContainerController();
        }

        return new $class();
    }

    /**
     * Panggil method (termasuk privat/protected) pada instance baru.
     *
     * @param array<int,mixed> $args
     */
    public static function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(self::classFor($method), $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(self::make($method), ...$args);
    }

    /**
     * Daftar berkas controller fitur app (semua kelas, termasuk abstract).
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/app/controller/app/*.php') ?: [];
        sort($files);

        return array_values($files);
    }
}
