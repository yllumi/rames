<?php
declare(strict_types=1);

namespace app\exception;

use Throwable;

/**
 * Handler exception aplikasi — perluasan handler webman untuk fitur
 * "Rekam Error".
 *
 * `report()` menjalankan perilaku bawaan (`$dontReport`, logger) **lalu**
 * meneruskan exception ke {@see \app\library\Errors\ErrorReporter::reportServer()}.
 * Reporter itu sendiri yang memfilter:
 *  - mode `agent` (control plane saja), dan
 *  - `BusinessException` (termasuk `AppAccessDenied` → 404 yang disengaja).
 *
 * `render()` **tidak** diubah: bentuk respons 404/500 tetap milik framework.
 */
final class Handler extends \support\exception\Handler
{
    public function report(Throwable $exception): void
    {
        parent::report($exception);
        (new \app\library\Errors\ErrorReporter())->reportServer($exception);
    }
}
