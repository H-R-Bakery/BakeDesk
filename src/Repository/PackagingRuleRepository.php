<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PackagingRule;
use App\Entity\ProductType;
use App\Entity\Unit;

/** @extends AbstractServiceEntityRepository<PackagingRule> */
final class PackagingRuleRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return PackagingRule::class;
    }

    public function findActiveFor(ProductType $productType, Unit $unit): ?PackagingRule
    {
        return $this->createQueryBuilder('packagingRule')
            ->andWhere('packagingRule.productType = :productType')
            ->andWhere('packagingRule.unit = :unit')
            ->andWhere('packagingRule.active = :active')
            ->setParameter('productType', $productType)
            ->setParameter('unit', $unit)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
