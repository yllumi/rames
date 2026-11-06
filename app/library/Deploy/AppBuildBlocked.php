<?php
declare(strict_types=1);

namespace app\library\Deploy;

use RuntimeException;

/**
 * Penolakan pipeline build ulang app ({@see AppBuildService}) — pesan sudah
 * siap tampil ke user (flash dashboard maupun body JSON API).
 *
 * Diturunkan dari `RuntimeException` supaya pemanggil lama yang hanya menangkap
 * `RuntimeException` tetap aman; `busy()` membedakan "app sedang diproses"
 * (HTTP 409 di API) dari penolakan lain (422).
 */
final class AppBuildBlocked extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $busy = false,
    ) {
        parent::__construct($message);
    }

    /** `true` bila penyebabnya deploy/build lain yang masih berjalan. */
    public function busy(): bool
    {
        return $this->busy;
    }
}
