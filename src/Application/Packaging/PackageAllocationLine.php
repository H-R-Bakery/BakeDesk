<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\OrderItem;
use App\Entity\ProductType;
use App\Entity\Unit;

final readonly class PackageAllocationLine
{
    public function __construct(
        public OrderItem $orderItem,
        public int $quantity,
        public Unit $unit,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('A package allocation line quantity must be greater than zero.');
        }
    }

    /** @return array{orderItemId: int|null, quantity: int, unitName: string, productTypeName: string, description: string} */
    public function toSnapshot(): array
    {
        return [
            'orderItemId' => $this->orderItem->getId(),
            'quantity' => $this->quantity,
            'unitName' => $this->unit->getName(),
            'productTypeName' => $this->orderItem->getProductType()?->getName() ?? 'Product',
            'description' => $this->orderItem->getDescription(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $quantity = $snapshot['quantity'] ?? null;
        $unitName = $snapshot['unitName'] ?? null;
        $productTypeName = $snapshot['productTypeName'] ?? null;
        $description = $snapshot['description'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || !is_string($unitName) || '' === $unitName || !is_string($productTypeName) || '' === $productTypeName || !is_string($description)) {
            throw new \InvalidArgumentException('A package snapshot contains an invalid line.');
        }

        $unit = (new Unit())->setName($unitName);
        $productType = (new ProductType())->setName($productTypeName);
        $orderItem = (new OrderItem())
            ->setProductType($productType)
            ->setUnit($unit)
            ->setQuantity($quantity)
            ->setDescription($description);

        return new self($orderItem, $quantity, $unit);
    }
}
