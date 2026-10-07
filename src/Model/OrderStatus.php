<?php

namespace App;

enum OrderStatus: string
{
    case OPEN = 'open';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
