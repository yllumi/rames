<?php
declare(strict_types=1);

namespace app\library\Api;

use RuntimeException;

/**
 * Nama (slug) app dipakai lebih dari satu app **yang terlihat** oleh aktor —
 * keunikan nama di `AppStore` hanya per server (`findByNameOnServer`), jadi
 * instalasi multi-server bisa punya nama yang sama.
 *
 * Endpoint API berbasis slug menolak keadaan ini (409) alih-alih menebak app
 * mana yang harus di-build; pemanggil dapat memakai id app (`/apps/{id}`) di
 * dashboard sampai endpoint API berbasis server tersedia.
 */
final class AmbiguousAppSlug extends RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct(
            'Nama app "' . $slug . '" dipakai lebih dari satu app (server berbeda). '
            . 'Gunakan id app di dashboard untuk membedakannya.'
        );
    }
}
