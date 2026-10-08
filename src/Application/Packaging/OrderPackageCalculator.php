<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\Order;
use App\Entity\OrderItem;

final class OrderPackageCalculator
{
    public function __construct(
        private readonly PackageCalculator $packageCalculator,
    ) {
    }

    /**
     * @return list<PackageAllocation>
     */
    public function calculate(Order $order): array
    {
        $items = $order->getItems()->toArray();
        usort($items, static fn (OrderItem $left, OrderItem $right): int => ($left->getSortOrder() <=> $right->getSortOrder()) ?: ((int) $left->getId() <=> (int) $right->getId()));

        $allocations = [];
        foreach ($items as $item) {
            foreach ($this->packageCalculator->calculate($item) as $allocation) {
                $allocations[] = $allocation;
            }
        }

        return $allocations;
    }
}
