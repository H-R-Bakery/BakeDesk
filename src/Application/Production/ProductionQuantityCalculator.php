<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\OrderItem;

final readonly class ProductionQuantityCalculator
{
    public function calculate(OrderItem $orderItem): int
    {
        $unit = $orderItem->getUnit();
        if (null === $unit) {
            throw new \InvalidArgumentException('An order item must have a unit before its production quantity can be calculated.');
        }
        if ($orderItem->getQuantity() < 1) {
            throw new \InvalidArgumentException('An order item quantity must be greater than zero.');
        }
        if ($unit->getEachEquivalent() < 1) {
            throw new \InvalidArgumentException('A unit each equivalent must be greater than zero.');
        }

        return $orderItem->getQuantity() * $unit->getEachEquivalent();
    }
}
