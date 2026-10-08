<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessPrintJob
{
    public function __construct(
        public int $printJobId,
    ) {
    }
}
