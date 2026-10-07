<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Repository\AbstractServiceEntityRepository;

class CustomerRepository extends AbstractServiceEntityRepository
{
    /**
     * @inheritDoc
     */
    public static function getEntityClass(): string
    {
        return Customer::class;
    }
}
