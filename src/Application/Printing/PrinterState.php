<?php

declare(strict_types=1);

namespace App\Application\Printing;

enum PrinterState: string
{
    case IDLE = 'idle';
    case PROCESSING = 'processing';
    case STOPPED = 'stopped';
    case UNKNOWN = 'unknown';
}
