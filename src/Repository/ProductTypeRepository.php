<?php

namespace App\Repository;

use App\Entity\ProductType;

/** @extends AbstractServiceEntityRepository<ProductType> */
class ProductTypeRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return ProductType::class;
    }

    /**
     * @return list<ProductType>
     */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['active' => true], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('productType')
            ->select('COUNT(productType.id)')
            ->andWhere('productType.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
