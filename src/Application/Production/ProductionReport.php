<?php

declare(strict_types=1);

namespace App\Application\Production;

final readonly class ProductionReport
{
    /**
     * @param list<ProductionReportSection> $sections
     */
    public function __construct(
        public \DateTimeImmutable $pickupDate,
        public array $sections,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->sections;
    }
}
