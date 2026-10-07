<?php

declare(strict_types=1);

namespace App\Application\Order;

use App\Entity\ProductType;
use App\Entity\Unit;

final readonly class OrderItemInput
{
    public function __construct(
        public ProductType $productType,
        public string $quantity,
        public Unit $unit,
        public string $description,
        public int $sortOrder = 0,
    ) {
    }
}
