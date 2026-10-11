<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\OrderItem;
use App\Entity\Unit;

final readonly class PackageAllocation
{
    /**
     * Order-wide numbering is intentionally absent only while this is the
     * item-level result produced by PackageCalculator. OrderPackageCalculator
     * assigns it before an allocation is used for a current label operation.
     */
    public function __construct(
        public OrderItem $orderItem,
        public int $itemPackageNumber,
        public int $itemPackageCount,
        public int $quantity,
        public Unit $unit,
        public ?int $orderPackageNumber = null,
        public ?int $orderPackageCount = null,
    ) {
        self::assertValidPosition($itemPackageNumber, $itemPackageCount, 'Item package');

        if ((null === $orderPackageNumber) !== (null === $orderPackageCount)) {
            throw new \InvalidArgumentException('Order package number and count must be provided together.');
        }

        if (null !== $orderPackageNumber && null !== $orderPackageCount) {
            self::assertValidPosition($orderPackageNumber, $orderPackageCount, 'Order package');
        }
    }

    public function withOrderPackagePosition(int $number, int $count): self
    {
        return new self(
            orderItem: $this->orderItem,
            itemPackageNumber: $this->itemPackageNumber,
            itemPackageCount: $this->itemPackageCount,
            quantity: $this->quantity,
            unit: $this->unit,
            orderPackageNumber: $number,
            orderPackageCount: $count,
        );
    }

    private static function assertValidPosition(int $number, int $count, string $label): void
    {
        if ($number < 1 || $count < 1 || $number > $count) {
            throw new \InvalidArgumentException(sprintf('%s numbers must identify a valid package.', $label));
        }
    }
}
