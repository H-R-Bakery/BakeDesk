<?php

namespace App\Repository;

use App\Entity\Unit;

/** @extends AbstractServiceEntityRepository<Unit> */
class UnitRepository extends AbstractServiceEntityRepository
{
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
