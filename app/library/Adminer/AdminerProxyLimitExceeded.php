<?php
declare(strict_types=1);

namespace app\library\Adminer;

/**
 * Respons helper melewati `deploy.adminer_proxy_max_bytes`.
 *
 * Dilempar baik saat stream (lewat {@see LimitedTempSink} maupun `on_headers`)
 * maupun setelah transfer selesai (ukuran akhir). Dump/import besar harus lewat
 * fitur Volume/backup atau Terminal — bukan diakali lewat proxy ini.
 */
class AdminerProxyLimitExceeded extends AdminerProxyError
{
}
