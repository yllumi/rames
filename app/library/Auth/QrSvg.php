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
 *
 * **Degradasi aman:** bila paket Composer belum terpasang (mis. update lama
 * tanpa `composer install`), `render()` mengembalikan string kosong dan view
 * menampilkan kunci manual — 2FA tetap bisa diaktifkan, bukan HTTP 500.
 */
final class QrSvg
{
    /**
     * Apakah pustaka QR tersedia (paket Composer terpasang).
     */
    public static function isAvailable(): bool
    {
        return class_exists(Writer::class);
    }

    /**
     * Render teks menjadi dokumen SVG (string kosong bila pustaka tidak ada).
     */
    public static function render(string $text, int $size = 320): string
    {
        if (!self::isAvailable()) {
            return '';
        }

        if ($size < 64) {
            $size = 320;
        }

        $renderer = new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($text);
    }
}
