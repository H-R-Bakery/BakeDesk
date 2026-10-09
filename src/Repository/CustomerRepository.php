<?php

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\ORM\QueryBuilder;
use libphonenumber\PhoneNumber;

/** @extends AbstractServiceEntityRepository<Customer> */
class CustomerRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return Customer::class;
    }

    public function findOneByPhone(PhoneNumber $phone): ?Customer
    {
        return $this->createQueryBuilder('customer')
            ->andWhere('customer.phone = :phone')
            ->andWhere('customer.active = :active')
            ->setParameter('phone', $phone, 'phone_number')
            ->setParameter('active', true)
            ->orderBy('customer.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Customer>
     */
    public function searchActive(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $builder = $this->createQueryBuilder('customer')
            ->andWhere('customer.active = :active')
            ->setParameter('active', true)
            ->orderBy('customer.name', 'ASC')
            ->addOrderBy('customer.id', 'ASC')
            ->setMaxResults(min(max($limit, 1), 20));

        $this->applySearchCriteria($builder, $query, 'autocomplete');

        return $builder->getQuery()->getResult();
    }

    /**
     * @return list<Customer>
     */
    public function searchForDirectory(string $query, int $page = 1, int $pageSize = 25): array
    {
        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), 100);
        $query = trim($query);

        $builder = $this->createQueryBuilder('customer')
            ->orderBy('customer.name', 'ASC')
            ->addOrderBy('customer.id', 'ASC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        if ('' !== $query) {
            $this->applySearchCriteria($builder, $query, 'directory');
        }

        return $builder->getQuery()->getResult();
    }

    public function countForDirectory(string $query): int
    {
        $query = trim($query);
        $builder = $this->createQueryBuilder('customer')
            ->select('COUNT(customer.id)');

        if ('' !== $query) {
            $this->applySearchCriteria($builder, $query, 'directory');
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    private function applySearchCriteria(QueryBuilder $builder, string $query, string $parameterPrefix): void
    {
        $phoneDigits = preg_replace('/\D+/', '', $query) ?? '';
        $nameParameter = $parameterPrefix.'NameQuery';
        $match = $this->getEntityManager()->getExpressionBuilder()->orX(
            'LOWER(customer.name) LIKE :'.$nameParameter,
        );

        $builder->setParameter($nameParameter, '%'.mb_strtolower($query).'%');

        if ('' !== $phoneDigits) {
            $phoneParameter = $parameterPrefix.'PhoneQuery';
            $match->add('customer.phone LIKE :'.$phoneParameter);
            $builder->setParameter($phoneParameter, '%'.$phoneDigits.'%');
        }

        $builder->andWhere($match);
    }
}
