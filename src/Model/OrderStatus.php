<?php

namespace App\Model;

enum OrderStatus: string
{
    case OPEN = 'open';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
