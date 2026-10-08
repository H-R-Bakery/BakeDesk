<?php

declare(strict_types=1);

namespace App\Application\Packaging;

use App\Entity\OrderItem;

final class FractionalPackageUnit extends PackagingException
{
    public static function forItem(OrderItem $orderItem): self
    {
        return new self(sprintf(
            '%s / %s must be a whole number of packages; received %s.',
            $orderItem->getProductType()?->getName() ?? 'Unknown product',
            $orderItem->getUnit()?->getName() ?? 'Unknown unit',
            $orderItem->getQuantity(),
        ));
    }
}
