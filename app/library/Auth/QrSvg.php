<?php
declare(strict_types=1);

namespace app\library\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Pembungkus tipis `bacon/bacon-qr-code` v3 untuk QR SVG inline.
 *
 * Dipakai halaman setup 2FA: SVG dikirim sebagai bagian payload view (bukan
 * data URI/base64) sehingga tidak butuh berkas sementara maupun GD/Imagick.
 * Statik murni tanpa state; kegagalan library dibiarkan melempar exception
 * (pemanggil memperlakukannya sebagai kegagalan setup, bukan halaman rusak).
 */
final class QrSvg
{
    /**
     * Render teks menjadi dokumen SVG.
     */
    public static function render(string $text, int $size = 320): string
    {
        if ($size < 64) {
            $size = 320;
        }

        $renderer = new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($text);
    }
}
