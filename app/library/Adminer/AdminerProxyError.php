<?php
declare(strict_types=1);

namespace app\library\Adminer;

use RuntimeException;

/**
 * Kegagalan proxy Adminer (helper tidak terjangkau, respons tak terduga, dll.).
 *
 * Pesannya sengaja tidak pernah memuat kredensial DB.
 */
class AdminerProxyError extends RuntimeException
{
}
