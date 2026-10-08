<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\OrderItem;
use App\Entity\Unit;

final readonly class PackageAllocation
{
    public function __construct(
        public OrderItem $orderItem,
        public int $packageNumber,
        public int $packageCount,
        public string $quantity,
        public Unit $unit,
    ) {
        if ($packageNumber < 1 || $packageCount < 1 || $packageNumber > $packageCount) {
            throw new \InvalidArgumentException('Package numbers must identify a valid package.');
        }
    }
}
