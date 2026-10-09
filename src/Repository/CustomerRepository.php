<?php

namespace App\Repository;

use App\Entity\Customer;
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

        $phoneDigits = preg_replace('/\\D+/', '', $query) ?? '';
        $match = $this->getEntityManager()->getExpressionBuilder()->orX(
            'LOWER(customer.name) LIKE :nameQuery',
        );

        if ('' !== $phoneDigits) {
            $match->add('customer.phone LIKE :phoneQuery');
        }

        $builder = $this->createQueryBuilder('customer')
            ->andWhere('customer.active = :active')
            ->setParameter('active', true)
            ->andWhere($match)
            ->setParameter('nameQuery', '%'.mb_strtolower($query).'%')
            ->orderBy('customer.name', 'ASC')
            ->addOrderBy('customer.id', 'ASC')
            ->setMaxResults(min(max($limit, 1), 20));

        if ('' !== $phoneDigits) {
            $builder
                ->setParameter('phoneQuery', '%'.$phoneDigits.'%');
        }

        return $builder->getQuery()->getResult();
    }
}
