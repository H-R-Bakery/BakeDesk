<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\PrintJob;
use App\Model\PrintDocumentType;

/** @extends AbstractServiceEntityRepository<PrintJob> */
class PrintJobRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return PrintJob::class;
    }

    public function findLatestLabelForOrder(Order $order): ?PrintJob
    {
        return $this->createQueryBuilder('printJob')
            ->andWhere('printJob.order = :order')
            ->andWhere('printJob.documentType = :documentType')
            ->setParameter('order', $order)
            ->setParameter('documentType', PrintDocumentType::LABEL)
            ->orderBy('printJob.createdAt', 'DESC')
            ->addOrderBy('printJob.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
