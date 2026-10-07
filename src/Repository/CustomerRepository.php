<?php

namespace App\Repository;

use App\Entity\Customer;
use libphonenumber\PhoneNumber;

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
            ->setParameter('phone', $phone, 'phone_number')
            ->orderBy('customer.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
