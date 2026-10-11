<?php

namespace App\Repository;

use App\Application\Order\OrderListRow;
use App\Application\Order\OrderSearchCriteria;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\PrintJob;
use App\Model\OrderStatus;
use App\Model\PrintJobStatus;
use Doctrine\DBAL\Types\Types;
use SortDirection;

/** @extends AbstractServiceEntityRepository<Order> */
class OrderRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return Order::class;
    }

    /**
     * @return list<OrderListRow>
     */
    public function searchForList(OrderSearchCriteria $criteria, int $page = 1, int $pageSize = 25): array
    {
        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), 100);

        $queryBuilder = $this->createFilteredQueryBuilder($criteria)
            ->leftJoin('o.user', 'user')
            ->addSelect('user')
            ->leftJoin('o.items', 'item')
            ->addSelect('COUNT(item.id) AS itemCount')
            ->groupBy('o.id')
            ->addGroupBy('user.id')
            ->orderBy('o.pickupAt', SortDirection::Ascending)
            ->addOrderBy('o.orderNumber', SortDirection::Ascending)
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        $rows = $queryBuilder->getQuery()->getResult();

        return array_map(
            static fn (array $row): OrderListRow => new OrderListRow($row[0], (int) $row['itemCount']),
            $rows,
        );
    }

    public function countForList(OrderSearchCriteria $criteria): int
    {
        return (int) $this->createFilteredQueryBuilder($criteria)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findForDetail(int $id): ?Order
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'user')
            ->addSelect('user')
            ->leftJoin('o.items', 'item')
            ->addSelect('item')
            ->leftJoin('item.productType', 'productType')
            ->addSelect('productType')
            ->leftJoin('item.unit', 'unit')
            ->addSelect('unit')
            ->andWhere('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Order>
     */
    public function findForCustomerHistory(
        Customer $customer,
        int $page = 1,
        int $pageSize = 25,
    ): array {
        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), 100);

        $orderIds = $this->createQueryBuilder('o')
            ->select('o.id')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('o.pickupAt', SortDirection::Descending)
            ->addOrderBy('o.id', SortDirection::Descending)
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $orderIds) {
            return [];
        }

        return $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'user')
            ->addSelect('user')
            ->leftJoin('o.items', 'item')
            ->addSelect('item')
            ->leftJoin('item.productType', 'productType')
            ->addSelect('productType')
            ->leftJoin('item.unit', 'unit')
            ->addSelect('unit')
            ->andWhere('o.id IN (:orderIds)')
            ->setParameter('orderIds', $orderIds)
            ->orderBy('o.pickupAt', SortDirection::Descending)
            ->addOrderBy('o.id', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    public function countForCustomerHistory(Customer $customer): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPickupBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.pickupAt >= :start')
            ->andWhere('o.pickupAt < :end')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('start', $start, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $end, Types::DATETIME_IMMUTABLE)
            ->setParameter('statuses', [OrderStatus::OPEN->value, OrderStatus::COMPLETED->value])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUpcomingOpen(\DateTimeImmutable $from): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :status')
            ->andWhere('o.pickupAt >= :from')
            ->setParameter('status', OrderStatus::OPEN->value)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUpcomingUnpaid(\DateTimeImmutable $from): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :status')
            ->andWhere('o.paid = :paid')
            ->andWhere('o.pickupAt >= :from')
            ->setParameter('status', OrderStatus::OPEN->value)
            ->setParameter('paid', false)
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countEligibleForCleanup(\DateTimeImmutable $cutoff, ?\DateTimeImmutable $printJobCutoff = null): int
    {
        return (int) $this->createCleanupQueryBuilder($cutoff, $printJobCutoff)
            ->select('COUNT(DISTINCT bakeryOrder.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Order>
     */
    public function findEligibleForCleanup(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->createCleanupQueryBuilder($cutoff)
            ->select('DISTINCT bakeryOrder')
            ->orderBy('bakeryOrder.id', SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Order>
     */
    public function findForProductionReport(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.items', 'item')
            ->addSelect('item')
            ->innerJoin('item.productType', 'productType')
            ->addSelect('productType')
            ->innerJoin('item.unit', 'unit')
            ->addSelect('unit')
            ->andWhere('o.pickupAt >= :start')
            ->andWhere('o.pickupAt < :end')
            ->andWhere('o.status IN (:includedStatuses)')
            ->setParameter('start', $start, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $end, Types::DATETIME_IMMUTABLE)
            ->setParameter('includedStatuses', [OrderStatus::OPEN->value, OrderStatus::COMPLETED->value])
            ->orderBy('productType.sortOrder', SortDirection::Ascending)
            ->addOrderBy('productType.name', SortDirection::Ascending)
            ->addOrderBy('o.pickupAt', SortDirection::Ascending)
            ->addOrderBy('o.orderNumber', SortDirection::Ascending)
            ->addOrderBy('item.sortOrder', SortDirection::Ascending)
            ->addOrderBy('item.id', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    private function createFilteredQueryBuilder(OrderSearchCriteria $criteria): \Doctrine\ORM\QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('o');

        if ($criteria->isDefaultUpcoming()) {
            $queryBuilder
                ->andWhere('o.status = :upcomingStatus')
                ->setParameter('upcomingStatus', OrderStatus::OPEN)
                ->andWhere('o.pickupAt >= :upcomingFrom')
                ->setParameter('upcomingFrom', $criteria->upcomingFrom, Types::DATETIME_IMMUTABLE);
        } elseif (null !== $criteria->status) {
            $queryBuilder
                ->andWhere('o.status = :status')
                ->setParameter('status', $criteria->status);
        }

        if ('' !== $criteria->query) {
            $phoneDigits = preg_replace('/\D+/', '', $criteria->query) ?? '';
            $searchExpressions = [
                'LOWER(o.orderNumber) LIKE :query',
                'LOWER(o.customerName) LIKE :query',
            ];
            if ('' !== $phoneDigits) {
                $searchExpressions[] = 'o.customerPhone LIKE :phoneQuery';
            }

            $queryBuilder
                ->andWhere('('.implode(' OR ', $searchExpressions).')')
                ->setParameter('query', '%'.mb_strtolower($criteria->query).'%');
            if ('' !== $phoneDigits) {
                $queryBuilder->setParameter('phoneQuery', '%'.$phoneDigits.'%');
            }
        }

        if (null !== $criteria->pickupDate) {
            $queryBuilder
                ->andWhere('o.pickupAt >= :pickupDateStart')
                ->andWhere('o.pickupAt < :pickupDateEnd')
                ->setParameter('pickupDateStart', $criteria->pickupDate, Types::DATETIME_IMMUTABLE)
                ->setParameter('pickupDateEnd', $criteria->pickupDate->modify('+1 day'), Types::DATETIME_IMMUTABLE);
        }

        if (null !== $criteria->userId) {
            $queryBuilder
                ->andWhere('IDENTITY(o.user) = :userId')
                ->setParameter('userId', $criteria->userId);
        }

        return $queryBuilder;
    }

    private function createCleanupQueryBuilder(\DateTimeImmutable $cutoff, ?\DateTimeImmutable $printJobCutoff = null): \Doctrine\ORM\QueryBuilder
    {
        $printJobReferenceQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('referencedPrintJob.id')
            ->from(PrintJob::class, 'referencedPrintJob')
            ->leftJoin('referencedPrintJob.orderItem', 'referencedOrderItem')
            ->where('(referencedPrintJob.order = bakeryOrder OR referencedOrderItem.order = bakeryOrder)');

        if (null !== $printJobCutoff) {
            $printJobReferenceQuery
                ->andWhere('(referencedPrintJob.status NOT IN (:printJobTerminalStatuses) OR referencedPrintJob.createdAt >= :printJobCutoff)');
        }

        $queryBuilder = $this->createQueryBuilder('bakeryOrder')
            ->andWhere('bakeryOrder.status IN (:terminalStatuses)')
            ->andWhere('bakeryOrder.pickupAt < :cutoff')
            ->andWhere($this->getEntityManager()->getExpressionBuilder()->not($this->getEntityManager()->getExpressionBuilder()->exists($printJobReferenceQuery->getDQL())))
            ->setParameter('terminalStatuses', [OrderStatus::COMPLETED->value, OrderStatus::CANCELLED->value])
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE);

        if (null !== $printJobCutoff) {
            $queryBuilder
                ->setParameter('printJobTerminalStatuses', [
                    PrintJobStatus::COMPLETED->value,
                    PrintJobStatus::FAILED->value,
                    PrintJobStatus::CANCELLED->value,
                ])
                ->setParameter('printJobCutoff', $printJobCutoff, Types::DATETIME_IMMUTABLE);
        }

        return $queryBuilder;
    }
}
