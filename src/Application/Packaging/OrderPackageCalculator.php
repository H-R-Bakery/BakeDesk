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

        /** @var list<list<PackageAllocationLine>> $packages */
        $packages = [];
        /** @var array<string, list<OrderItem>> $groups */
        $groups = [];
        /** @var array<string, int> $groupOrder */
        $groupOrder = [];
        /** @var array<string, int|null> $capacities */
        $capacities = [];
        /** @var array<string, bool> $packageUnitGroups */
        $packageUnitGroups = [];

        foreach ($items as $item) {
            $this->validateItem($item);
            $unit = $item->getUnit();
            if (null === $unit) {
                throw new InvalidPackagingQuantity('An order item must have a unit before it can be packaged.');
            }

            if ($unit->isPackageUnit()) {
                $groupKey = 'package|'.($item->getId() ?? spl_object_id($item));
                $groups[$groupKey] = [$item];
                $groupOrder[$groupKey] = count($groupOrder);
                $capacities[$groupKey] = null;
                $packageUnitGroups[$groupKey] = true;
                continue;
            }

            $capacity = $this->packageCalculator->capacityFor($item);
            $productType = $item->getProductType();
            if (null === $productType) {
                throw new InvalidPackagingQuantity('An order item must have a product type before it can be packaged.');
            }
            $groupKey = $this->groupKey($productType, $unit, $capacity);
            $groups[$groupKey] ??= [];
            $groups[$groupKey][] = $item;
            $groupOrder[$groupKey] ??= count($groupOrder);
            $capacities[$groupKey] = $capacity;
            $packageUnitGroups[$groupKey] = false;
        }

        uksort($groups, static fn (string $left, string $right): int => $groupOrder[$left] <=> $groupOrder[$right]);
        foreach ($groups as $groupKey => $groupItems) {
            if ($packageUnitGroups[$groupKey]) {
                $item = $groupItems[0];
                $unit = $item->getUnit();
                if (null === $unit) {
                    throw new InvalidPackagingQuantity('An order item must have a unit before it can be packaged.');
                }
                for ($packageNumber = 0; $packageNumber < $item->getQuantity(); ++$packageNumber) {
                    $packages[] = [new PackageAllocationLine($item, 1, $unit)];
                }
                continue;
            }
            $capacity = $capacities[$groupKey];
            if (null === $capacity) {
                $lines = [];
                foreach ($groupItems as $item) {
                    $unit = $item->getUnit();
                    if (null === $unit) {
                        throw new InvalidPackagingQuantity('An order item must have a unit before it can be packaged.');
                    }
                    $lines[] = new PackageAllocationLine($item, $item->getQuantity(), $unit);
                }
                $packages[] = array_map(
                    static fn (PackageAllocationLine $line): PackageAllocationLine => $line,
                    $lines,
                );
                continue;
            }

            $currentLines = [];
            $remainingCapacity = $capacity;
            foreach ($groupItems as $item) {
                $remainingQuantity = $item->getQuantity();
                while ($remainingQuantity > 0) {
                    $lineQuantity = min($remainingQuantity, $remainingCapacity);
                    $currentLines[] = new PackageAllocationLine($item, $lineQuantity, $item->getUnit());
                    $remainingQuantity -= $lineQuantity;
                    $remainingCapacity -= $lineQuantity;

                    if (0 === $remainingCapacity) {
                        $packages[] = $currentLines;
                        $currentLines = [];
                        $remainingCapacity = $capacity;
                    }
                }
            }
            if ([] !== $currentLines) {
                $packages[] = $currentLines;
            }
        }

        $orderPackageCount = \count($packages);

        return array_map(
            static fn (array $lines, int $index): PackageAllocation => new PackageAllocation($index + 1, $orderPackageCount, $lines, $order),
            $packages,
            array_keys($packages),
        );
    }

    private function validateItem(OrderItem $item): void
    {
        if (null === $item->getProductType() || null === $item->getUnit()) {
            throw new InvalidPackagingQuantity('An order item must have a product type and unit before it can be packaged.');
        }
        if ($item->getQuantity() < 1) {
            throw new InvalidPackagingQuantity('An order item quantity must be greater than zero.');
        }
    }

    private function groupKey(\App\Entity\ProductType $productType, \App\Entity\Unit $unit, ?int $capacity): string
    {
        $productKey = null !== $productType->getId() ? 'id:'.$productType->getId() : 'object:'.spl_object_id($productType);
        $unitKey = null !== $unit->getId() ? 'id:'.$unit->getId() : 'object:'.spl_object_id($unit);

        return $productKey.'|'.$unitKey.'|'.($capacity ?? 'unlimited');
    }
}
