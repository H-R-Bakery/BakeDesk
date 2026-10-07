<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Entity\Order;

final readonly class OrderListRow
{
    public function __construct(
        public Order $order,
        public int $itemCount,
    ) {
    }
}
