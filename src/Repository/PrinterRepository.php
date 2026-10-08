<?php

namespace App\Repository;

use App\Entity\Printer;

/** @extends AbstractServiceEntityRepository<Printer> */
class PrinterRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return Printer::class;
    }

    /**
     * @return list<Printer>
     */
    public function findConfiguredLabelDefaults(): array
    {
        return $this->createQueryBuilder('printer')
            ->andWhere('printer.defaultForLabels = :defaultForLabels')
            ->setParameter('defaultForLabels', true)
            ->getQuery()
            ->getResult();
    }
}
