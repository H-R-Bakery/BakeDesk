<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\ProductType;
use App\Entity\Unit;
use Symfony\Component\Validator\Constraints as Assert;

final class NewOrderItemData
{
    public ?int $id = null;

    #[Assert\NotNull(message: 'Choose a product type.')]
    public ?ProductType $productType = null;

    #[Assert\NotBlank(message: 'Enter a quantity.')]
    #[Assert\Positive(message: 'Quantity must be greater than zero.')]
    public ?string $quantity = null;

    #[Assert\NotNull(message: 'Choose a unit.')]
    public ?Unit $unit = null;

    #[Assert\NotBlank(message: 'Enter a description.')]
    public string $description = '';
}
