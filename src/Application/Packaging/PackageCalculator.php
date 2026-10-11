<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\OrderItem;
use App\Repository\PackagingRuleRepository;

final class PackageCalculator
{
    public function __construct(
        private readonly PackagingRuleRepository $packagingRuleRepository,
    ) {
    }

    /**
     * @return list<PackageAllocation>
     */
    public function calculate(OrderItem $orderItem): array
    {
        $productType = $orderItem->getProductType();
        $unit = $orderItem->getUnit();
        if (null === $productType || null === $unit) {
            throw new InvalidPackagingQuantity('An order item must have a product type and unit before it can be packaged.');
        }

        $quantity = $orderItem->getQuantity();
        if ($quantity < 1) {
            throw new InvalidPackagingQuantity('An order item quantity must be greater than zero.');
        }

        if ($unit->isPackageUnit()) {
            $packageCount = $quantity;

            return array_map(
                fn (int $packageNumber): PackageAllocation => new PackageAllocation(
                    orderItem: $orderItem,
                    packageNumber: $packageNumber,
                    packageCount: $packageCount,
                    quantity: 1,
                    unit: $unit,
                ),
                range(1, $packageCount),
            );
        }

        $rule = $this->packagingRuleRepository->findActiveFor($productType, $unit);
        if (null === $rule) {
            return [new PackageAllocation(
                orderItem: $orderItem,
                packageNumber: 1,
                packageCount: 1,
                quantity: $quantity,
                unit: $unit,
            )];
        }
        if (null === $rule->getUnit() || $rule->getUnit()->isPackageUnit()) {
            throw new InvalidPackagingQuantity(sprintf('The packaging rule for %s / %s must use a non-package unit.', $productType->getName(), $unit->getName()));
        }

        $capacity = $rule->getQuantityPerPackage();
        if ($capacity < 1) {
            throw new InvalidPackagingQuantity(sprintf('The packaging rule for %s / %s must have a positive capacity.', $productType->getName(), $unit->getName()));
        }

        $packageCount = intdiv($quantity + $capacity - 1, $capacity);
        $allocations = [];
        $remaining = $quantity;
        for ($packageNumber = 1; $packageNumber <= $packageCount; ++$packageNumber) {
            $packageQuantity = min($remaining, $capacity);
            $allocations[] = new PackageAllocation(
                orderItem: $orderItem,
                packageNumber: $packageNumber,
                packageCount: $packageCount,
                quantity: $packageQuantity,
                unit: $unit,
            );
            $remaining -= $packageQuantity;
        }

        return $allocations;
    }
}
