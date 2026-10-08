<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\ProductType;
use App\Entity\Unit;

final class MissingPackagingRule extends PackagingException
{
    public static function forProductAndUnit(ProductType $productType, Unit $unit): self
    {
        return new self(sprintf(
            'No active packaging rule exists for %s / %s.',
            $productType->getName(),
            $unit->getName(),
        ));
    }
}
