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

    public function countDefaultLabelPrinters(): int
    {
        return (int) $this->createQueryBuilder('printer')
            ->select('COUNT(printer.id)')
            ->andWhere('printer.defaultForLabels = :defaultForLabels')
            ->setParameter('defaultForLabels', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUsableDefaultLabelPrinters(): int
    {
        return (int) $this->createQueryBuilder('printer')
            ->select('COUNT(printer.id)')
            ->andWhere('printer.defaultForLabels = :defaultForLabels')
            ->andWhere('printer.active = :active')
            ->andWhere('printer.forLabels = :forLabels')
            ->setParameter('defaultForLabels', true)
            ->setParameter('active', true)
            ->setParameter('forLabels', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAvailableForReports(): int
    {
        return (int) $this->createQueryBuilder('printer')
            ->select('COUNT(printer.id)')
            ->andWhere('printer.active = :active')
            ->andWhere('printer.forReports = :forReports')
            ->setParameter('active', true)
            ->setParameter('forReports', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Printer>
     */
    public function findAvailableForReports(): array
    {
        return $this->findBy(
            ['active' => true, 'forReports' => true],
            ['name' => 'ASC', 'id' => 'ASC'],
        );
    }
}
