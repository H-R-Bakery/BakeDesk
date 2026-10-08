<?php

declare(strict_types=1);

namespace App\Application\Printing;

final readonly class PrintSubmission
{
    public function __construct(
        public string $externalJobId,
        public ?PrintJobStatusSnapshot $initialStatus = null,
    ) {
    }
}
