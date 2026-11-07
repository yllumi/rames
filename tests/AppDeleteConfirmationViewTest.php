<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class AppDeleteConfirmationViewTest extends TestCase
{
    public function testDeleteModalRequiresExactNameForEveryDeleteMode(): void
    {
        $file = dirname(__DIR__) . '/app/view/app/partials/detail-modal-delete.php';
        self::assertFileExists($file);
        $source = (string) file_get_contents($file);

        self::assertStringContainsString('name="confirm_name"', $source);
        self::assertStringContainsString('required autocomplete="off"', $source);
        self::assertStringContainsString('Hapus &amp; pertahankan volume', $source);
        self::assertStringContainsString('Hapus total (semua volume)', $source);
        self::assertStringContainsString('Lepas dari dashboard', $source);
    }
}
