<?php

namespace App\Model;

enum PrintJobStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SUBMITTED = 'submitted';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
