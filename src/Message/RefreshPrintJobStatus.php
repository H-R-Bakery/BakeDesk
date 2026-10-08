<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RefreshPrintJobStatus
{
    public function __construct(
        public int $printJobId,
        public int $checkNumber = 0,
    ) {
    }
}
