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

    public function capacityFor(OrderItem $orderItem): ?int
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
            return null;
        }

        $rule = $this->packagingRuleRepository->findActiveFor($productType, $unit);
        if (null === $rule) {
            return null;
        }
        if (null === $rule->getUnit() || $rule->getUnit()->isPackageUnit()) {
            throw new InvalidPackagingQuantity(sprintf('The packaging rule for %s / %s must use a non-package unit.', $productType->getName(), $unit->getName()));
        }

        $capacity = $rule->getQuantityPerPackage();
        if ($capacity < 1) {
            throw new InvalidPackagingQuantity(sprintf('The packaging rule for %s / %s must have a positive capacity.', $productType->getName(), $unit->getName()));
        }

        return $capacity;
    }
}
