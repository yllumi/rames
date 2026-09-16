<?php
declare(strict_types=1);

namespace Tests;

use app\library\Db\DbCredentialResolver;
use PHPUnit\Framework\TestCase;

/**
 * Test resolusi kredensial MySQL/MariaDB dari env app & container.
 */
class DbCredentialResolverTest extends TestCase
{
    private DbCredentialResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DbCredentialResolver();
    }

    public function testDetectsAppUserFromAppEnv(): void
    {
        $app = ['env' => ['MYSQL_USER' => 'app', 'MYSQL_PASSWORD' => 'secret', 'MYSQL_DATABASE' => 'appdb']];
        $inspect = ['Config' => ['Env' => []]];

        $result = $this->resolver->resolve($app, $inspect);

        $this->assertNotNull($result);
        $this->assertSame('app', $result['username']);
        $this->assertSame('secret', $result['password']);
        $this->assertSame('appdb', $result['database']);
        $this->assertTrue($result['detected']);
    }

    public function testDetectsRootPasswordFromContainerEnv(): void
    {
        $app = ['env' => []];
        $inspect = ['Config' => ['Env' => ['MYSQL_ROOT_PASSWORD=rootsecret']]];

        $result = $this->resolver->resolve($app, $inspect);

        $this->assertNotNull($result);
        $this->assertSame('root', $result['username']);
        $this->assertSame('rootsecret', $result['password']);
    }

    public function testDetectsFrameworkStyleCredentials(): void
    {
        $app = ['env' => ['DB_USERNAME' => 'sail', 'DB_PASSWORD' => 'sailpass']];
        $inspect = ['Config' => ['Env' => []]];

        $result = $this->resolver->resolve($app, $inspect);

        $this->assertNotNull($result);
        $this->assertSame('sail', $result['username']);
        $this->assertSame('sailpass', $result['password']);
    }

    public function testPrefersAppEnvOverContainerEnv(): void
    {
        $app = ['env' => ['MYSQL_ROOT_PASSWORD' => 'from-app']];
        $inspect = ['Config' => ['Env' => ['MYSQL_ROOT_PASSWORD=from-container']]];

        $result = $this->resolver->resolve($app, $inspect);

        $this->assertNotNull($result);
        $this->assertSame('from-app', $result['password']);
    }

    public function testReturnsNullWithoutCredentials(): void
    {
        $result = $this->resolver->resolve(['env' => []], ['Config' => ['Env' => []]]);

        $this->assertNull($result);
    }
}
