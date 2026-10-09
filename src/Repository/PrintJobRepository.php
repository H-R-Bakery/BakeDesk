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

    /**
     * @return list<PrintJob>
     */
    public function findLabelJobsForOrder(Order $order): array
    {
        return $this->createQueryBuilder('printJob')
            ->andWhere('printJob.order = :order')
            ->andWhere('printJob.documentType = :documentType')
            ->setParameter('order', $order)
            ->setParameter('documentType', PrintDocumentType::LABEL)
            ->orderBy('printJob.createdAt', 'DESC')
            ->addOrderBy('printJob.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, PrintJob>
     */
    public function findLatestLabelJobsByPackageForOrder(Order $order): array
    {
        $latestJob = $this->createQueryBuilder('newer')
            ->select('1')
            ->andWhere('newer.order = :order')
            ->andWhere('newer.documentType = :documentType')
            ->andWhere('newer.orderItem = printJob.orderItem')
            ->andWhere('newer.packageNumber = printJob.packageNumber')
            ->andWhere('(newer.createdAt > printJob.createdAt OR (newer.createdAt = printJob.createdAt AND newer.id > printJob.id))');

        $jobs = $this->createQueryBuilder('printJob')
            ->andWhere('printJob.order = :order')
            ->andWhere('printJob.documentType = :documentType')
            ->andWhere('printJob.orderItem IS NOT NULL')
            ->andWhere('printJob.packageNumber IS NOT NULL')
            ->andWhere($this->getEntityManager()->getExpressionBuilder()->not($this->getEntityManager()->getExpressionBuilder()->exists($latestJob->getDQL())))
            ->setParameter('order', $order)
            ->setParameter('documentType', PrintDocumentType::LABEL)
            ->orderBy('printJob.createdAt', 'DESC')
            ->addOrderBy('printJob.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latestByPackage = [];
        foreach ($jobs as $job) {
            \assert($job instanceof PrintJob);
            $orderItemId = $job->getOrderItem()?->getId();
            $packageNumber = $job->getPackageNumber();
            if (null !== $orderItemId && null !== $packageNumber) {
                $latestByPackage[$orderItemId.':'.$packageNumber] = $job;
            }
        }

        return $latestByPackage;
    }
}
