<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\OrderItem;
use App\Repository\PackagingRuleRepository;

final class PackageCalculator
{
    private const MINOR_UNITS_PER_WHOLE = 100;

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

        $quantity = $this->toMinorUnits($orderItem->getQuantity());
        if ($quantity < 1) {
            throw new InvalidPackagingQuantity('An order item quantity must be greater than zero.');
        }

        if ($unit->isPackageUnit()) {
            if (0 !== $quantity % self::MINOR_UNITS_PER_WHOLE) {
                throw FractionalPackageUnit::forItem($orderItem);
            }

            $packageCount = intdiv($quantity, self::MINOR_UNITS_PER_WHOLE);

            return array_map(
                fn (int $packageNumber): PackageAllocation => new PackageAllocation(
                    orderItem: $orderItem,
                    packageNumber: $packageNumber,
                    packageCount: $packageCount,
                    quantity: '1',
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
                quantity: $this->fromMinorUnits($quantity),
                unit: $unit,
            )];
        }
        if (null === $rule->getUnit() || $rule->getUnit()->isPackageUnit()) {
            throw new InvalidPackagingQuantity(sprintf('The packaging rule for %s / %s must use a non-package unit.', $productType->getName(), $unit->getName()));
        }

        $capacity = $this->toMinorUnits($rule->getQuantityPerPackage());
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
                quantity: $this->fromMinorUnits($packageQuantity),
                unit: $unit,
            );
            $remaining -= $packageQuantity;
        }

        return $allocations;
    }

    private function toMinorUnits(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidPackagingQuantity(sprintf('The quantity "%s" is not a valid positive decimal.', $value));
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $minorUnits = ((int) $whole * self::MINOR_UNITS_PER_WHOLE) + (int) $fraction;
        if ($minorUnits < 1) {
            throw new InvalidPackagingQuantity('Packaging quantities must be greater than zero.');
        }

        return $minorUnits;
    }

    private function fromMinorUnits(int $value): string
    {
        $whole = intdiv($value, self::MINOR_UNITS_PER_WHOLE);
        $fraction = $value % self::MINOR_UNITS_PER_WHOLE;
        if (0 === $fraction) {
            return (string) $whole;
        }

        return sprintf('%d.%02d', $whole, $fraction);
    }
}
