<?php
declare(strict_types=1);

namespace app\library\Files;

use RuntimeException;

/**
 * Error operasi file manager yang membawa status HTTP yang diinginkan.
 *
 * Controller memetakan exception ini langsung ke envelope `{code:<status>,msg}`.
 * Dipakai agar controller tetap tipis (tanpa logika bisnis / pemetaan kode
 * tersebar), sekaligus menjaga respons akses tidak sah (404) tetap di
 * `AppAccessDenied`.
 */
final class FileError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
