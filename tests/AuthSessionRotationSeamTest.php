<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AuthController;
use PHPUnit\Framework\TestCase;
use support\Request;
use Workerman\Protocols\Http\Session;

/**
 * Kontrak rotasi id session di {@see AuthController::rotateSession()}.
 *
 * Semua test alur login memakai seam `rotateSession()`, sehingga pemanggilan
 * nyata ke `Request::sessionRegenerateId(true)` tidak tercakup. Test ini
 * mengunci kontraknya: id **wajib** dirotasi lewat Request (bukan
 * `Session::refresh()` yang hanya memperbarui timestamp di Workerman), sesi
 * lama dihapus, dan objek sesi yang dikembalikan adalah sesi request terbaru.
 */
class AuthSessionRotationSeamTest extends TestCase
{
    public function testRotateSessionRegeneratesIdAndReturnsFreshRequestSession(): void
    {
        $request = new RotationProbeRequest();
        $controller = new RotationSeamController();

        $session = $controller->rotate($request);

        self::assertSame(1, $request->regenerations, 'rotateSession wajib memanggil sessionRegenerateId()');
        self::assertTrue($request->deletedOldSession, 'sesi lama wajib dihapus (anti session fixation)');
        self::assertSame($request->session(), $session, 'harus mengembalikan sesi request terbaru');
    }
}

/**
 * Seam publik kecil agar `rotateSession()` (protected) bisa diuji.
 */
class RotationSeamController extends AuthController
{
    public function rotate(Request $request): Session
    {
        return $this->rotateSession($request);
    }
}

/**
 * Request palsu: mencatat panggilan `sessionRegenerateId()` tanpa koneksi TCP.
 */
class RotationProbeRequest extends Request
{
    public int $regenerations = 0;

    public bool $deletedOldSession = false;

    private ?Session $session = null;

    public function __construct()
    {
        parent::__construct("GET /profile HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    public function session(): Session
    {
        return $this->session ??= new Session('rotationprobe' . bin2hex(random_bytes(8)));
    }

    public function sessionRegenerateId(bool $deleteOldSession = false): string
    {
        $this->regenerations++;
        $this->deletedOldSession = $deleteOldSession;
        $this->session = new Session('rotationnew' . bin2hex(random_bytes(8)));

        return $this->session->getId();
    }
}
