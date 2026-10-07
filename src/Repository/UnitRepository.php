<?php

namespace App\Repository;

use App\Entity\Unit;
use App\Repository\AbstractServiceEntityRepository;

/** @extends AbstractServiceEntityRepository<Unit> */
class UnitRepository extends AbstractServiceEntityRepository
{
    /**
     * @inheritDoc
     */
    public static function getEntityClass(): string
    {
        return Unit::class;
    }

    /**
     * @return list<Unit>
     */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['active' => true], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }
}
