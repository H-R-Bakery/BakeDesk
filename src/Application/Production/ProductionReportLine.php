<?php

declare(strict_types=1);

namespace App\Application\Production;

final readonly class ProductionReportLine
{
    public function __construct(
        public string $orderNumber,
        public \DateTimeImmutable $pickupAt,
        public string $customerName,
        public int $quantity,
        public string $unitName,
        public int $eachQuantity,
        public string $description,
    ) {
    }
}
