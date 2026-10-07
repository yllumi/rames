<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    'event_loop' => '',
    'stop_timeout' => 2,
    'pid_file' => runtime_path() . '/webman.pid',
    'status_file' => runtime_path() . '/webman.status',
    'stdout_file' => runtime_path() . '/logs/stdout.log',
    'log_file' => runtime_path() . '/logs/workerman.log',
    // Batas paket HTTP Webman — dinaikkan ke 68 MiB agar MENAMPUNG OVERHEAD
    // multipart (boundary + field) untuk unggahan berkas 64 MiB dari klien
    // (batas klien = 64 MiB, batas nyata per berkas `upload_max_filesize` = 64M).
    'max_package_size' => 68 * 1024 * 1024
];
