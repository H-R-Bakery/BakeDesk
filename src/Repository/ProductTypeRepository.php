<?php

namespace App\Repository;

use App\Entity\ProductType;
use App\Repository\AbstractServiceEntityRepository;

class ProductTypeRepository extends AbstractServiceEntityRepository
{
    /**
     * @inheritDoc
     */
    public static function getEntityClass(): string
    {
        return ProductType::class;
    }
}
