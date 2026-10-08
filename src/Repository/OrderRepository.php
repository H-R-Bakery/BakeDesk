<?php

namespace App\Repository;

use App\Application\Order\OrderListRow;
use App\Application\Order\OrderSearchCriteria;
use App\Entity\Order;
use App\Model\OrderStatus;
use Doctrine\DBAL\Types\Types;

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
            ->orderBy('o.pickupAt', 'ASC')
            ->addOrderBy('o.orderNumber', 'ASC')
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
            ->orderBy('productType.sortOrder', 'ASC')
            ->addOrderBy('productType.name', 'ASC')
            ->addOrderBy('o.pickupAt', 'ASC')
            ->addOrderBy('o.orderNumber', 'ASC')
            ->addOrderBy('item.sortOrder', 'ASC')
            ->addOrderBy('item.id', 'ASC')
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
}
