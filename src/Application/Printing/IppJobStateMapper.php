<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Model\PrintJobStatus;

final class IppJobStateMapper
{
    public function toPrintJobStatus(PrintJobStatusSnapshot $snapshot): PrintJobStatus
    {
        return match ($snapshot->state) {
            PrintJobState::COMPLETED => PrintJobStatus::COMPLETED,
            PrintJobState::CANCELLED => PrintJobStatus::CANCELLED,
            PrintJobState::ABORTED => PrintJobStatus::FAILED,
            PrintJobState::PENDING, PrintJobState::PROCESSING, PrintJobState::UNKNOWN => PrintJobStatus::SUBMITTED,
        };
    }
}
