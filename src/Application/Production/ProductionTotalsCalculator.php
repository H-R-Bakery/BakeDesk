<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\OrderItem;

final readonly class ProductionTotalsCalculator
{
    public function __construct(
        private ProductionQuantityCalculator $productionQuantityCalculator,
        private DecimalArithmetic $decimalArithmetic,
    ) {
    }

    /**
     * @param iterable<OrderItem> $orderItems
     *
     * @return array<string, string> totals keyed by ProductType name
     */
    public function calculate(iterable $orderItems): array
    {
        $totals = [];
        foreach ($orderItems as $orderItem) {
            $productType = $orderItem->getProductType();
            if (null === $productType) {
                throw new \InvalidArgumentException('An order item must have a product type before production totals can be calculated.');
            }

            $name = $productType->getName();
            $quantity = $this->productionQuantityCalculator->calculate($orderItem);
            $totals[$name] = isset($totals[$name])
                ? $this->decimalArithmetic->add($totals[$name], $quantity)
                : $quantity;
        }

        return $totals;
    }
}
