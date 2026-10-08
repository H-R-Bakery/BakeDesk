<?php

declare(strict_types=1);

namespace App\Application\Production;

final readonly class ProductionReportSection
{
    /**
     * @param list<ProductionReportLine> $lines
     */
    public function __construct(
        public string $productTypeName,
        public string $totalEachQuantity,
        public array $lines,
    ) {
    }
}
