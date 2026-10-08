<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\OrderItem;

final readonly class ProductionQuantityCalculator
{
    public function __construct(
        private DecimalArithmetic $decimalArithmetic,
    ) {
    }

    public function calculate(OrderItem $orderItem): string
    {
        $unit = $orderItem->getUnit();
        if (null === $unit) {
            throw new \InvalidArgumentException('An order item must have a unit before its production quantity can be calculated.');
        }
        if (!$this->decimalArithmetic->isPositive($orderItem->getQuantity())) {
            throw new \InvalidArgumentException('An order item quantity must be greater than zero.');
        }
        if (!$this->decimalArithmetic->isPositive($unit->getEachEquivalent())) {
            throw new \InvalidArgumentException('A unit each equivalent must be greater than zero.');
        }

        return $this->decimalArithmetic->multiply($orderItem->getQuantity(), $unit->getEachEquivalent());
    }
}
