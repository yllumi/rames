<?php
declare(strict_types=1);

namespace Tests;

use app\controller\NginxController;
use app\library\Auth\AppAccessDenied;
use PHPUnit\Framework\TestCase;
use support\Request;

/**
 * Halaman operasional host `/nginx` (menu "Config") = **admin-only**.
 *
 * Menu disembunyikan dari member di `app/view/partials/header.php`, tetapi
 * penyembunyian UI bukan pengaman: pemeriksaan di controller yang menolak,
 * dengan respons **404** (bukan 403) agar tidak membocorkan keberadaan halaman
 * operasional host — pola sama dengan `AppAccessDenied` di tempat lain.
 */
class NginxControllerAdminOnlyTest extends TestCase
{
    public function testMemberIsDeniedWithNotFound(): void
    {
        $controller = $this->controller(false);

        $this->expectException(AppAccessDenied::class);
        $controller->index($this->request('/nginx'));
    }

    public function testDenialCarriesNotFoundStatus(): void
    {
        $controller = $this->controller(false);

        try {
            $controller->index($this->request('/nginx'));
            self::fail('Member seharusnya ditolak.');
        } catch (AppAccessDenied $e) {
            self::assertSame(404, $e->getCode());
            self::assertSame('nginx', $e->ability);
        }
    }

    private function controller(bool $admin): NginxController
    {
        return new class ($admin) extends NginxController {
            public function __construct(private readonly bool $admin)
            {
            }

            protected function isAdmin(): bool
            {
                return $this->admin;
            }
        };
    }

    private function request(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }
}
