<?php
declare(strict_types=1);

namespace app\library\Billing;

use RuntimeException;

/**
 * Kegagalan komunikasi/response Duitku.
 *
 * `httpStatus` = status HTTP response (0 bila bukan error HTTP: masalah jaringan,
 * timeout, konfigurasi belum lengkap). `duitkuMessage` = pesan asli Duitku bila ada
 * (mis. `statusMessage`), untuk logging internal.
 *
 * **Higienitas kredensial**: pesan exception **tidak boleh** memuat
 * `billing_duitku_api_key`. `DuitkuClient` menyanitasi pesan sebelum melempar
 * (mengganti kemunculan API key dengan `[redacted]`).
 */
class DuitkuError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $duitkuMessage = '',
    ) {
        parent::__construct($message);
    }
}
