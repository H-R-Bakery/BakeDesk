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

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('unit')
            ->select('COUNT(unit.id)')
            ->andWhere('unit.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveWithNonPositiveEachEquivalent(): int
    {
        return (int) $this->createQueryBuilder('unit')
            ->select('COUNT(unit.id)')
            ->andWhere('unit.active = :active')
            ->andWhere('unit.eachEquivalent <= :minimum')
            ->setParameter('active', true)
            ->setParameter('minimum', 0)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
