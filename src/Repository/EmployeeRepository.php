<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Repository\AbstractServiceEntityRepository;

class EmployeeRepository extends AbstractServiceEntityRepository
{
    /**
     * @inheritDoc
     */
    public static function getEntityClass(): string
    {
        return Employee::class;
    }
}
