<?php

namespace App\Repository;

use App\Entity\Unit;
use App\Repository\AbstractServiceEntityRepository;

class UnitRepository extends AbstractServiceEntityRepository
{
    /**
     * @inheritDoc
     */
    public static function getEntityClass(): string
    {
        return Unit::class;
    }
}
