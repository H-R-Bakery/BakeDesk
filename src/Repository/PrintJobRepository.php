<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\PrintJob;
use App\Model\PrintDocumentType;
use App\Model\PrintJobStatus;
use Doctrine\DBAL\Types\Types;

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
     * @param list<PrintJobStatus> $statuses
     */
    public function countByStatuses(array $statuses): int
    {
        if ([] === $statuses) {
            return 0;
        }

        return (int) $this->createQueryBuilder('printJob')
            ->select('COUNT(printJob.id)')
            ->andWhere('printJob.status IN (:statuses)')
            ->setParameter('statuses', array_map(static fn (PrintJobStatus $status): string => $status->value, $statuses))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countEligibleForCleanup(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('printJob')
            ->select('COUNT(printJob.id)')
            ->andWhere('printJob.status IN (:terminalStatuses)')
            ->andWhere('printJob.createdAt < :cutoff')
            ->setParameter('terminalStatuses', $this->terminalStatusValues())
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<PrintJob>
     */
    public function findEligibleForCleanup(\DateTimeImmutable $cutoff, int $limit, ?string $documentPath = null): array
    {
        $queryBuilder = $this->createQueryBuilder('printJob')
            ->andWhere('printJob.status IN (:terminalStatuses)')
            ->andWhere('printJob.createdAt < :cutoff')
            ->setParameter('terminalStatuses', $this->terminalStatusValues())
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->orderBy('printJob.id', 'ASC')
            ->setMaxResults($limit);

        if (null === $documentPath) {
            $queryBuilder->andWhere('printJob.documentPath IS NULL');
        } else {
            $queryBuilder
                ->andWhere('printJob.documentPath = :documentPath')
                ->setParameter('documentPath', $documentPath);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return list<string>
     */
    public function findEligibleDocumentPaths(\DateTimeImmutable $cutoff, int $limit, int $offset = 0): array
    {
        $paths = $this->createQueryBuilder('printJob')
            ->select('DISTINCT printJob.documentPath')
            ->andWhere('printJob.documentPath IS NOT NULL')
            ->andWhere('printJob.status IN (:terminalStatuses)')
            ->andWhere('printJob.createdAt < :cutoff')
            ->setParameter('terminalStatuses', $this->terminalStatusValues())
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->orderBy('printJob.documentPath', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path)));
    }

    public function hasDocumentReference(string $documentPath): bool
    {
        return 0 < (int) $this->createQueryBuilder('printJob')
            ->select('COUNT(printJob.id)')
            ->andWhere('printJob.documentPath = :documentPath')
            ->setParameter('documentPath', $documentPath)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasRemainingDocumentReferenceAfterCleanup(string $documentPath, \DateTimeImmutable $cutoff): bool
    {
        return 0 < (int) $this->createQueryBuilder('printJob')
            ->select('COUNT(printJob.id)')
            ->andWhere('printJob.documentPath = :documentPath')
            ->andWhere('(printJob.status NOT IN (:terminalStatuses) OR printJob.createdAt >= :cutoff)')
            ->setParameter('documentPath', $documentPath)
            ->setParameter('terminalStatuses', $this->terminalStatusValues())
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    private function terminalStatusValues(): array
    {
        return [
            PrintJobStatus::COMPLETED->value,
            PrintJobStatus::FAILED->value,
            PrintJobStatus::CANCELLED->value,
        ];
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
