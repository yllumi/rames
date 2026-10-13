<?php
declare(strict_types=1);

namespace Tests;

use app\controller\NginxController;
use PHPUnit\Framework\TestCase;
use support\Request;

/**
 * Halaman Config `/nginx` sekarang bertab (Update / Nginx / Template).
 *
 * Yang dikunci di sini:
 *  - aksi reload Nginx mempertahankan tab aktif (`/nginx?tab=template`) — tanpa
 *    ini setiap klik "Reload Nginx" dari tab Template melompat ke tab Update;
 *  - whitelist tab ketat: nilai `tab` sembarang TIDAK boleh masuk ke URL;
 *  - perilaku anti-open-redirect yang lama **tidak berubah** (URL absolut /
 *    protokol-relatif tetap jatuh ke `/apps`);
 *  - endpoint AJAX baru (pull + status pull) menolak non-admin dengan `code` 403
 *    dan tidak pernah menyentuh katalog/worker.
 */
class NginxConfigTabRedirectTest extends TestCase
{
    public function testKeepsRequestedTabWhenReturningToConfigPage(): void
    {
        $exposed = $this->exposedController(false);

        $this->assertSame('/nginx?tab=template', $exposed->back('/nginx', 'template'));
        $this->assertSame('/nginx?tab=nginx', $exposed->back('/nginx?tab=update', 'nginx'));
        $this->assertSame('/nginx?tab=update', $exposed->back('/nginx/', 'update'));
    }

    public function testRejectsUnknownTabValue(): void
    {
        $exposed = $this->exposedController(false);

        $this->assertSame('/nginx', $exposed->back('/nginx', 'jahat'));
        $this->assertSame('/nginx', $exposed->back('/nginx', '../template'));
        $this->assertSame('/nginx', $exposed->back('/nginx', ''));
    }

    public function testTabIsOnlyAppliedForConfigPage(): void
    {
        $exposed = $this->exposedController(false);

        $this->assertSame('/apps', $exposed->back('/apps', 'template'));
        $this->assertSame('/nginx-x', $exposed->back('/nginx-x', 'template'));
    }

    public function testOpenRedirectAttemptsStillFallBackToApps(): void
    {
        $exposed = $this->exposedController(false);

        $this->assertSame('/apps', $exposed->back('', 'template'));
        $this->assertSame('/apps', $exposed->back('https://jahat.example/nginx', 'template'));
        $this->assertSame('/apps', $exposed->back('//jahat.example/nginx', 'template'));
        $this->assertSame('/apps', $exposed->back('/\\jahat.example/nginx', 'template'));
    }

    public function testPullEndpointsRejectNonAdminWithCode403(): void
    {
        $controller = $this->exposedController(false);

        foreach ([$controller->pullTemplates($this->request('POST', '/nginx/templates/pull')), $controller->pullStatus($this->request('GET', '/nginx/templates/pull/status'))] as $response) {
            $this->assertSame(200, $response->getStatusCode());
            $payload = json_decode((string) $response->rawBody(), true);
            $this->assertIsArray($payload);
            $this->assertSame(403, $payload['code']);
            $this->assertNotEmpty($payload['msg']);
            $this->assertArrayNotHasKey('data', $payload);
        }
    }

    /**
     * Controller dengan `configBackUrl()` & `isAdmin()` yang bisa dipanggil test
     * (seam sama dengan `NginxControllerAdminOnlyTest`).
     *
     * @return NginxController&object{back:string}
     */
    private function exposedController(bool $admin): NginxController
    {
        return new class ($admin) extends NginxController {
            public function __construct(private readonly bool $admin)
            {
            }

            protected function isAdmin(): bool
            {
                return $this->admin;
            }

            public function back(string $referer, string $tab): string
            {
                return $this->configBackUrl($referer, $tab);
            }
        };
    }

    private function request(string $method, string $path): Request
    {
        return new Request("{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }
}
