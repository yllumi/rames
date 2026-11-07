<?php
declare(strict_types=1);

namespace app\library\Branch;

use RuntimeException;

/**
 * Penolakan pipeline ganti branch sebelum worker dijalankan.
 */
final class BranchChangeBlocked extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $busy = false,
    ) {
        parent::__construct($message);
    }

    public function busy(): bool
    {
        return $this->busy;
    }
}
