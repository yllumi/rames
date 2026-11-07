<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppDeletionConfirmation;
use PHPUnit\Framework\TestCase;

final class AppDeletionConfirmationTest extends TestCase
{
    public function testRequiresExactStringAppName(): void
    {
        self::assertTrue(AppDeletionConfirmation::matches('demo-app', 'demo-app'));
        self::assertFalse(AppDeletionConfirmation::matches(null, 'demo-app'));
        self::assertFalse(AppDeletionConfirmation::matches('', 'demo-app'));
        self::assertFalse(AppDeletionConfirmation::matches('demo-app ', 'demo-app'));
        self::assertFalse(AppDeletionConfirmation::matches('Demo-app', 'demo-app'));
        self::assertFalse(AppDeletionConfirmation::matches(['demo-app'], 'demo-app'));
        self::assertFalse(AppDeletionConfirmation::matches(123, '123'));
    }
}
