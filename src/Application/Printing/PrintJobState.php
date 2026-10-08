<?php

declare(strict_types=1);

namespace App\Application\Printing;

enum PrintJobState: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case CANCELLED = 'cancelled';
    case ABORTED = 'aborted';
    case COMPLETED = 'completed';
    case UNKNOWN = 'unknown';
}
